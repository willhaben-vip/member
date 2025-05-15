#!/bin/bash
#
# Deployment script for willhaben.vip redirect service
# 
# This script handles deployment with:
# - Zero downtime deployment
# - Configuration validation
# - Cache warming
# - Security checks
#
# Usage: ./deploy.sh [--env=production|staging|development] [--skip-validation] [--skip-cache] [--skip-security]
#
# Author: DevOps Team
# Date: 2025-05-15

set -eo pipefail

# Default configuration
APP_ROOT="/Users/rene/k42/instantpay/code/INSTANTpay/willhaben.vip/member"
DEPLOY_ENV="production"
LOG_DIR="/var/log/willhaben"
BACKUP_DIR="/var/backups/willhaben"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
RELEASE_DIR="${APP_ROOT}/releases/${TIMESTAMP}"
CURRENT_SYMLINK="${APP_ROOT}/current"
SHARED_DIR="${APP_ROOT}/shared"
REPO_URL="git@github.com:willhaben/redirect-service.git"
BRANCH="main"
LOCKFILE="/tmp/willhaben_deploy.lock"
MAX_RELEASES=5
LOGFILE="${LOG_DIR}/deploy_${TIMESTAMP}.log"
CONFIG_FILES=("cache.php" "logging.php" "metrics.php" "rate_limiting.php" "security.php" "static_assets.php" "validation.php")
SKIP_VALIDATION=0
SKIP_CACHE=0
SKIP_SECURITY=0

# Process command line arguments
for arg in "$@"; do
  case $arg in
    --env=*)
      DEPLOY_ENV="${arg#*=}"
      if [[ ! "$DEPLOY_ENV" =~ ^(production|staging|development)$ ]]; then
        echo "Invalid environment: $DEPLOY_ENV. Must be one of: production, staging, development"
        exit 1
      fi
      shift
      ;;
    --skip-validation)
      SKIP_VALIDATION=1
      shift
      ;;
    --skip-cache)
      SKIP_CACHE=1
      shift
      ;;
    --skip-security)
      SKIP_SECURITY=1
      shift
      ;;
    --help)
      echo "Usage: $0 [--env=production|staging|development] [--skip-validation] [--skip-cache] [--skip-security]"
      exit 0
      ;;
    *)
      # Unknown option
      echo "Unknown option: $arg"
      echo "Usage: $0 [--env=production|staging|development] [--skip-validation] [--skip-cache] [--skip-security]"
      exit 1
      ;;
  esac
done

# Create log directory if it doesn't exist
mkdir -p "${LOG_DIR}"

# Set up logging
exec > >(tee -a "$LOGFILE") 2>&1

# Log function
log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Error handling function
error_exit() {
  log "ERROR: $1"
  # Release lock if we have it
  if [ -f "$LOCKFILE" ]; then
    rm -f "$LOCKFILE"
  fi
  
  # If we were in the middle of a deployment and we've created the release directory,
  # clean it up to avoid partial deployments
  if [ -d "$RELEASE_DIR" ]; then
    log "Cleaning up failed deployment at $RELEASE_DIR"
    rm -rf "$RELEASE_DIR"
  fi
  
  exit 1
}

# Function to notify about deployment
notify() {
  local status=$1
  local message=$2
  
  log "Deployment $status: $message"
  
  # Here you would implement actual notification mechanism (email, Slack, etc.)
  # Example: curl -X POST -H 'Content-type: application/json' --data "{\"text\":\"Deployment $status: $message\"}" $SLACK_WEBHOOK_URL
}

# Verify prerequisites
check_prerequisites() {
  log "Checking prerequisites..."
  
  # Check for required commands
  for cmd in git php composer curl find rsync; do
    if ! command -v $cmd &> /dev/null; then
      error_exit "$cmd is required but not installed"
    fi
  done
  
  # Check app root is writable
  if [ ! -w "$APP_ROOT" ]; then
    error_exit "Application root not writable: $APP_ROOT"
  fi
  
  # Check if we can connect to the repo
  if ! git ls-remote --exit-code "$REPO_URL" "$BRANCH" &> /dev/null; then
    error_exit "Cannot access repository branch: $REPO_URL $BRANCH"
  fi
  
  log "Prerequisites check passed"
}

# Lock to prevent concurrent deployments
acquire_lock() {
  log "Acquiring deployment lock..."
  if [ -f "$LOCKFILE" ]; then
    PID=$(cat "$LOCKFILE")
    if ps -p $PID > /dev/null; then
      error_exit "Another deployment is already running with PID $PID"
    else
      log "Stale lock file found. Previous deployment may have crashed."
      rm -f "$LOCKFILE"
    fi
  fi
  echo $$ > "$LOCKFILE"
  log "Lock acquired"
}

# Release lock
release_lock() {
  log "Releasing deployment lock..."
  rm -f "$LOCKFILE"
  log "Lock released"
}

# Create directories
create_directories() {
  log "Creating directory structure..."
  
  mkdir -p "$RELEASE_DIR"
  mkdir -p "$SHARED_DIR/config"
  mkdir -p "$SHARED_DIR/logs"
  mkdir -p "$SHARED_DIR/cache"
  
  log "Directory structure created"
}

# Checkout code
checkout_code() {
  log "Checking out code from $REPO_URL branch $BRANCH..."
  
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$RELEASE_DIR"
  
  if [ $? -ne 0 ]; then
    error_exit "Git checkout failed"
  fi
  
  log "Code checkout complete"
}

# Validate configuration
validate_configuration() {
  if [ $SKIP_VALIDATION -eq 1 ]; then
    log "Skipping configuration validation"
    return
  fi
  
  log "Validating configuration..."
  
  # Set APP_ENV for validation
  export APP_ENV="$DEPLOY_ENV"
  
  # Validate PHP syntax in all PHP files
  log "Checking PHP syntax..."
  find "$RELEASE_DIR" -name "*.php" -type f -exec php -l {} \; | grep -v "No syntax errors" && error_exit "PHP syntax errors found"
  
  # Validate configuration files
  log "Validating configuration files..."
  for config_file in "${CONFIG_FILES[@]}"; do
    if [ -f "$RELEASE_DIR/config/$config_file" ]; then
      log "Validating $config_file..."
      php -r "try { \$config = require('$RELEASE_DIR/config/$config_file'); echo 'Valid: ' . count(\$config) . \" configuration items\\n\"; } catch (\Throwable \$e) { echo \"Invalid: {\$e->getMessage()}\\n\"; exit(1); }" || error_exit "Invalid configuration in $config_file"
    else
      log "Warning: Configuration file $config_file not found"
    fi
  done
  
  log "Configuration validation passed"
}

# Security checks
security_checks() {
  if [ $SKIP_SECURITY -eq 1 ]; then
    log "Skipping security checks"
    return
  fi
  
  log "Running security checks..."
  
  # Check for hardcoded secrets
  log "Checking for hardcoded secrets..."
  SECRETS_FOUND=$(grep -r -E '(password|secret|key|token|auth)["\']?\s*[:=]\s*["\'][a-zA-Z0-9_]+["\']' --include="*.php" --include="*.json" "$RELEASE_DIR" | grep -v -E '(password|secret|key|token|auth)["\']?\s*[:=]\s*["\']["\']' | wc -l)
  
  if [ "$SECRETS_FOUND" -gt 0 ]; then
    log "Warning: Potential hardcoded secrets found in the codebase"
    grep -r -E '(password|secret|key|token|auth)["\']?\s*[:=]\s*["\'][a-zA-Z0-9_]+["\']' --include="*.php" --include="*.json" "$RELEASE_DIR" | grep -v -E '(password|secret|key|token|auth)["\']?\s*[:=]\s*["\']["\']'
  fi
  
  # Check file permissions
  log "Checking file permissions..."
  find "$RELEASE_DIR" -type f -perm -+x -not -path "*/bin/*" | grep -q . && log "Warning: Executable files found outside bin directory"
  
  # If in production, check for development configs
  if [ "$DEPLOY_ENV" = "production" ]; then
    log "Checking for development configurations in production..."
    grep -r -E 'debug|development|test' --include="*.php" "$RELEASE_DIR/config" | grep -E '(true|1)' | grep -v -E '(false|0)' && log "Warning: Development settings found in production configs"
  fi
  
  log "Security checks completed"
}

# Link shared resources
link_shared_resources() {
  log "Linking shared resources..."
  
  # Link shared config files
  if [ -d "$SHARED_DIR/config" ]; then
    for config_file in "$SHARED_DIR/config"/*; do
      if [ -f "$config_file" ]; then
        BASENAME=$(basename "$config_file")
        log "Linking shared config file: $BASENAME"
        ln -sf "$config_file" "$RELEASE_DIR/config/$BASENAME"
      fi
    done
  fi
  
  # Link other shared resources
  ln -sf "$SHARED_DIR/logs" "$RELEASE_DIR/logs"
  ln -sf "$SHARED_DIR/cache" "$RELEASE_DIR/var/cache"
  
  # Ensure correct permissions
  find "$RELEASE_DIR" -type d -exec chmod 755 {} \;
  find "$RELEASE_DIR" -type f -exec chmod 644 {} \;
  find "$RELEASE_DIR/bin" -type f -name "*.sh" -exec chmod 755 {} \;
  
  log "Shared resources linked"
}

# Warm cache
warm_cache() {
  if [ $SKIP_CACHE -eq 1 ]; then
    log "Skipping cache warming"
    return
  fi
  
  log "Warming cache..."
  
  # Ensure cache directory exists
  mkdir -p "$RELEASE_DIR/var/cache"
  chmod -R 775 "$RELEASE_DIR/var/cache"
  
  # Execute cache warm-up script
  cd "$RELEASE_DIR"
  if [ -f "$RELEASE_DIR/bin/warm-cache.php" ]; then
    log "Running cache warm-up script..."
    php -f "$RELEASE_DIR/bin/warm-cache.php" >> "$LOGFILE" 2>&1 || log "Warning: Cache warm-up script failed"
  else
    log "Warning: Cache warm-up script not found"
  fi
  
  log "Cache warming completed"
}

# Switch to new release
switch_release() {
  log "Switching to new release..."
  
  # Create a temp symlink and atomically move it
  ln -sfn "$RELEASE_DIR" "${CURRENT_SYMLINK}.new"
  mv -Tf "${CURRENT_SYMLINK}.new" "$CURRENT_SYMLINK"
  
  log "Switched to new release: $TIMESTAMP"
}

# Cleanup old releases
cleanup_old_releases() {
  log "Cleaning up old releases..."
  
  # Count releases
  RELEASES_COUNT=$(find "$APP_ROOT/releases" -maxdepth 1 -type d | wc -l)
  
  # Keep only the latest MAX_RELEASES releases
  if [ "$RELEASES_COUNT" -gt "$MAX_RELEASES" ]; then
    # Get list of releases sorted by creation time
    OLD_RELEASES=$(find "$APP_ROOT/releases" -maxdepth 1 -type d -printf "%T@ %p\n" | sort -n | head -n $((RELEASES_COUNT - MAX_RELEASES)) | cut -d' ' -f2-)
    
    # Remove old releases
    for old_release in $OLD_RELEASES; do
      log "Removing old release: $(basename "$old_release")"
      rm -rf "$old_release"
    done
  fi
  
  log "Cleanup completed"
}

# Verify deployment
verify_deployment() {
  log "Verifying deployment..."
  
  # Check if the current symlink is valid
  if [ ! -d "$CURRENT_SYMLINK" ]; then
    error_exit "Current symlink is not valid"
  fi
  
  # Check if the application is accessible
  HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -m 10 "https://willhaben.vip/health")
  
  if [ "$HTTP_STATUS" -ne 200 ]; then
    log "Warning: Health check returned HTTP status $HTTP_STATUS"
  else
    log "Health check passed with HTTP status 200"
  fi
  
  log "Deployment verification completed"
}

# Main deployment process
log "Starting deployment to $DEPLOY_ENV environment"
check_prerequisites
acquire_lock

# Try to run all deployment steps, capturing errors
ERROR=0

# Create new release directory
create_directories || ERROR=1

if [ $ERROR -eq 0 ]; then
  # Checkout code
  checkout_code || ERROR=1
fi

if [ $ERROR -eq 0 ]; then
  # Validate configuration
  validate_configuration || ERROR=1
fi

if [ $ERROR -eq 0 ]; then
  # Run security checks
  security_checks || ERROR=1
fi

if [ $ERROR -eq 0 ]; then
  # Link shared resources
  link_shared_resources || ERROR=1
fi

if [ $ERROR -eq 0 ]; then
  # Warm cache
  warm_cache || ERROR=1
fi

if [ $ERROR -eq 0 ]; then
  # Switch to new release
  switch_release || ERROR=1
fi

if [ $ERROR -eq 0 ]; then
  # Cleanup old releases
  cleanup_old_releases || ERROR=1
fi

if [ $ERROR -eq 0 ]; then
  # Verify deployment
  verify_deployment || ERROR=1
fi

# Release lock
release_lock

# Provide summary
if [ $ERROR -eq 0 ]; then
  log "Deployment to $DEPLOY_ENV completed successfully"
  notify "SUCCESS" "Deployment to $DEPLOY_ENV completed successfully"
  exit 0
else
  log "Deployment to $DEPLOY_ENV failed"
  notify "FAILED" "Deployment to $DEPLOY_ENV failed. Check the log file for details: $LOGFILE"
  exit 1
fi
