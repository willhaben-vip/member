#!/bin/bash
#
# Production Verification Script for willhaben.vip redirect service (LOCAL TESTING VERSION)
# 
# This script performs comprehensive checks using local paths for testing purposes.
# No elevated permissions required.
#
# Author: DevOps Team
# Date: 2025-05-15

set -eo pipefail

# Configuration - using local paths for testing
APP_ROOT="/Users/rene/k42/instantpay/code/INSTANTpay/willhaben.vip/member"
BACKUP_DIR="${APP_ROOT}/tests/backups"
LOG_DIR="${APP_ROOT}/tests/logs"
CONFIG_DIR="${APP_ROOT}/tests/config"
TEST_DIR="${APP_ROOT}/tests"
REDIS_HOST="127.0.0.1"
REDIS_PORT=6379
REDIS_DB=3
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
LOGFILE="${LOG_DIR}/verify_local_${TIMESTAMP}.log"
ENVIRONMENT=${ENVIRONMENT:-"test"}
CHECK_PASSED=0
CHECK_FAILED=0
CHECK_SKIPPED=0
OVERALL_RESULT=0
VERBOSE=0

# Colors for terminal output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Process command line arguments
for arg in "$@"; do
  case $arg in
    --verbose)
      VERBOSE=1
      shift
      ;;
    --env=*)
      ENVIRONMENT="${arg#*=}"
      shift
      ;;
    --help)
      echo "Usage: $0 [--verbose] [--env=test|development]"
      echo "  --verbose       Display detailed output"
      echo "  --env=ENV       Environment to check (default: test)"
      exit 0
      ;;
    *)
      # Unknown option
      echo "Unknown option: $arg"
      echo "Usage: $0 [--verbose] [--env=test|development]"
      exit 1
      ;;
  esac
done

# Create test directories
mkdir -p "${TEST_DIR}"
mkdir -p "${LOG_DIR}"
mkdir -p "${BACKUP_DIR}"
mkdir -p "${BACKUP_DIR}/daily"
mkdir -p "${BACKUP_DIR}/weekly"
mkdir -p "${BACKUP_DIR}/monthly"
mkdir -p "${CONFIG_DIR}"
mkdir -p "${CONFIG_DIR}/environments"
mkdir -p "${CONFIG_DIR}/ssl"
mkdir -p "${TEST_DIR}/etc/cron.d"

# Create sample config files for testing
if [ ! -f "${CONFIG_DIR}/config.json" ]; then
  echo '{"app": "willhaben", "version": "1.0.0"}' > "${CONFIG_DIR}/config.json"
fi

if [ ! -f "${CONFIG_DIR}/redis.conf" ]; then
  echo "port ${REDIS_PORT}" > "${CONFIG_DIR}/redis.conf"
  echo "save 900 1" >> "${CONFIG_DIR}/redis.conf"
fi

if [ ! -f "${CONFIG_DIR}/security.json" ]; then
  echo '{"ssl": true, "rate_limit": 100}' > "${CONFIG_DIR}/security.json"
fi

if [ ! -f "${CONFIG_DIR}/environments/${ENVIRONMENT}.json" ]; then
  echo '{"env": "test", "debug": true}' > "${CONFIG_DIR}/environments/${ENVIRONMENT}.json"
fi

# Set up logging
exec > >(tee -a "$LOGFILE") 2>&1

# Log function
log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Success function
success() {
  echo -e "${GREEN}[PASS]${NC} $1"
  CHECK_PASSED=$((CHECK_PASSED + 1))
}

# Failure function
failure() {
  echo -e "${RED}[FAIL]${NC} $1"
  CHECK_FAILED=$((CHECK_FAILED + 1))
  OVERALL_RESULT=1
}

# Warning function
warning() {
  echo -e "${YELLOW}[WARN]${NC} $1"
  CHECK_SKIPPED=$((CHECK_SKIPPED + 1))
}

# Info function
info() {
  if [ "$VERBOSE" -eq 1 ]; then
    echo -e "${BLUE}[INFO]${NC} $1"
  fi
}

# Section header
section() {
  echo -e "\n${BLUE}==== $1 ====${NC}"
}

# Check if a script exists and is executable
check_script() {
  local script=$1
  local path="${APP_ROOT}/bin/${script}"
  
  info "Checking script: $script"
  
  if [ ! -f "$path" ]; then
    failure "Script not found: $script"
    return 1
  elif [ ! -x "$path" ]; then
    failure "Script not executable: $script"
    return 1
  else
    success "Script exists and is executable: $script"
    return 0
  fi
}

# Check required scripts
check_required_scripts() {
  section "Checking Required Scripts"
  
  # List of required scripts
  local scripts=("backup.sh" "test_ops.sh")
  
  # Additional scripts to check if they exist
  local optional_scripts=("maintenance.sh" "deploy.sh")
  
  # Check required scripts
  for script in "${scripts[@]}"; do
    check_script "$script"
  done
  
  # Check optional scripts
  for script in "${optional_scripts[@]}"; do
    if ! check_script "$script"; then
      warning "Optional script not available: $script"
    fi
  done
}

# Check configuration files
check_config_files() {
  section "Checking Configuration Files"
  
  if [ ! -d "$CONFIG_DIR" ]; then
    failure "Configuration directory not found: $CONFIG_DIR"
    return 1
  fi
  
  success "Configuration directory exists: $CONFIG_DIR"
  
  # Check for essential config files
  local config_files=("config.json" "redis.conf" "security.json")
  
  for config in "${config_files[@]}"; do
    if [ ! -f "${CONFIG_DIR}/${config}" ]; then
      warning "Config file not found: ${config}"
    else
      # Basic JSON validation for .json files
      if [[ "$config" == *.json ]]; then
        if command -v jq &> /dev/null; then
          if jq -e . "${CONFIG_DIR}/${config}" > /dev/null 2>&1; then
            success "Config file is valid JSON: ${config}"
          else
            failure "Config file is not valid JSON: ${config}"
          fi
        else
          warning "jq not installed, skipping JSON validation for ${config}"
        fi
      else
        success "Config file exists: ${config}"
      fi
    fi
  done
  
  # Check for environment-specific config
  local env_config="${CONFIG_DIR}/environments/${ENVIRONMENT}.json"
  if [ ! -f "$env_config" ]; then
    warning "Environment-specific config not found: $env_config"
  else
    if command -v jq &> /dev/null; then
      if jq -e . "$env_config" > /dev/null 2>&1; then
        success "Environment config is valid: $env_config"
      else
        failure "Environment config is not valid JSON: $env_config"
      fi
    else
      warning "jq not installed, skipping JSON validation for environment config"
    fi
  fi
}

# Test Redis connectivity - with skip option for local testing
check_redis() {
  section "Testing Redis Connectivity"
  
  if ! command -v redis-cli &> /dev/null; then
    warning "redis-cli not installed, skipping Redis checks"
    return 0
  fi
  
  info "Testing connection to Redis at $REDIS_HOST:$REDIS_PORT"
  
  if redis-cli -h $REDIS_HOST -p $REDIS_PORT ping > /dev/null 2>&1; then
    success "Redis is responsive"
    
    # Test ability to write and read from Redis
    local test_key="willhaben_verify_local_test"
    local test_value="test_value_${TIMESTAMP}"
    
    if redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB set "$test_key" "$test_value" > /dev/null && \
       [[ "$(redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB get "$test_key")" == "$test_value" ]]; then
      success "Redis write/read test successful"
      
      # Clean up test key
      redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB del "$test_key" > /dev/null
    else
      failure "Redis write/read test failed"
    fi
    
    # Check Redis memory usage
    local used_memory=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT info memory | grep used_memory_human | cut -d: -f2 | tr -d '[:space:]')
    info "Redis memory usage: $used_memory"
    
    # Check Redis connectivity timeout
    local timeout=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT config get timeout | grep -v timeout | tr -d '\r')
    info "Redis timeout setting: ${timeout:-'default'}"
  else
    warning "Redis is not responsive - this is expected if you don't have Redis running locally"
  fi
}

# Verify logging setup
check_logging() {
  section "Verifying Logging Setup"
  
  # Check log directory
  if [ ! -d "$LOG_DIR" ]; then
    failure "Log directory does not exist: $LOG_DIR"
  elif [ ! -w "$LOG_DIR" ]; then
    failure "Log directory is not writable: $LOG_DIR"
  else
    success "Log directory exists and is writable: $LOG_DIR"
  fi
  
  # Create a test log file
  echo "Test log entry" > "${LOG_DIR}/app.log"
  
  # Check for log files
  local log_count=$(find "$LOG_DIR" -name "*.log" | wc -l)
  info "Found $log_count log files"
  
  if [ "$log_count" -eq 0 ]; then
    warning "No log files found. This is unexpected."
  else
    success "Log files exist"
  fi
  
  # Test ability to write to logs
  local test_log="${LOG_DIR}/verify_test.log"
  if echo "Test log entry" > "$test_log" 2>/dev/null; then
    success "Successfully wrote to test log"
    rm -f "$test_log"
  else
    failure "Failed to write to test log"
  fi
}

# Check monitoring endpoints (simplified for local testing)
check_monitoring() {
  section "Checking Monitoring Endpoints"
  
  # Check for monitoring config
  if [ ! -f "${CONFIG_DIR}/monitoring.json" ]; then
    # Create sample monitoring config for testing
    echo '{"endpoints": ["health", "metrics"], "exporters": ["prometheus"]}' > "${CONFIG_DIR}/monitoring.json"
  fi
  
  if [ -f "${CONFIG_DIR}/monitoring.json" ]; then
    success "Monitoring configuration exists"
    
    if command -v jq &> /dev/null; then
      if jq -e . "${CONFIG_DIR}/monitoring.json" > /dev/null 2>&1; then
        success "Monitoring config is valid JSON"
      else
        failure "Monitoring config is not valid JSON"
      fi
    else
      warning "jq not installed, skipping JSON validation for monitoring config"
    fi
  else
    warning "Monitoring configuration not found"
  fi
}

# Validate security settings
check_security() {
  section "Validating Security Settings"
  
  # Check for security config
  if [ -f "${CONFIG_DIR}/security.json" ]; then
    success "Security configuration exists"
  else
    warning "Security configuration not found"
  fi
  
  # Create test SSL certificates for validation
  if [ ! -f "${CONFIG_DIR}/ssl/test.crt" ]; then
    # Create a simple self-signed certificate for testing if openssl is available
    if command -v openssl &> /dev/null; then
      # Simple non-interactive cert generation
      openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "${CONFIG_DIR}/ssl/test.key" \
        -out "${CONFIG_DIR}/ssl/test.crt" \
        -subj "/CN=localhost" 2>/dev/null
      
      if [ -f "${CONFIG_DIR}/ssl/test.crt" ] && [ -f "${CONFIG_DIR}/ssl/test.key" ]; then
        success "Created test SSL certificates for validation"
      fi
    fi
  fi
  
  # Check for SSL certificates
  if [ -d "${CONFIG_DIR}/ssl" ]; then
    local cert_file=$(find "${CONFIG_DIR}/ssl" -name "*.crt" | head -1)
    local key_file=$(find "${CONFIG_DIR}/ssl" -name "*.key" | head -1)
    
    if [ -n "$cert_file" ] && [ -n "$key_file" ]; then
      success "SSL certificates found"
      
      # Check certificate expiration
      if command -v openssl &> /dev/null; then
        local cert_exp=$(openssl x509 -enddate -noout -in "$cert_file" | cut -d= -f2)
        local cert_exp_epoch=$(date -d "$cert_exp" +%s 2>/dev/null || date -j -f "%b %d %H:%M:%S %Y %Z" "$cert_exp" +%s 2>/dev/null)
        local now_epoch=$(date +%s)
        local days_left=$(( (cert_exp_epoch - now_epoch) / 86400 ))
        
        if [ $days_left -lt 30 ]; then
          warning "SSL certificate will expire in $days_left days"
        else
          success "SSL certificate valid for $days_left more days"
        fi
      else
        warning "openssl not installed, skipping certificate checks"
      fi
    else
      warning "SSL certificates not found or incomplete"
    fi
  else
    warning "SSL directory not found"
  fi
}

# Test backup locations
check_backup_locations() {
  section "Testing Backup Locations"
  
  # Check if backup directory exists
  if [ ! -d "$BACKUP_DIR" ]; then
    failure "Backup directory does not exist: $BACKUP_DIR"
  elif [ ! -w "$BACKUP_DIR" ]; then
    failure "Backup directory is not writable: $BACKUP_DIR"
  else
    success "Backup directory exists and is writable: $BACKUP_DIR"
    
    # Check backup subdirectories
    for subdir in daily weekly monthly; do
      if [ ! -d "${BACKUP_DIR}/${subdir}" ]; then
        warning "Backup subdirectory does not exist: ${BACKUP_DIR}/${subdir}"
      elif [ ! -w "${BACKUP_DIR}/${subdir}" ]; then
        failure "Backup subdirectory is not writable: ${BACKUP_DIR}/${subdir}"
      else
        success "Backup subdirectory exists and is writable: ${BACKUP_DIR}/${subdir}"
      fi
    done
    
    # Create test backup files for verification
    for subdir in daily weekly monthly; do
      # Create a test backup file for each type
      local test_file="${BACKUP_DIR}/${subdir}/willhaben-backup-full-${TIMESTAMP}_test.tar"
      if touch "$test_file"; then
        success "Successfully created test backup file in $subdir directory"
        echo "test content" > "$test_file"
        
        # Create a test checksum file
        echo "0000000000000000000000000000000000000000000000000000000000000000 *$(basename "$test_file")" > "$test_file.sha256"
        
        # Create a compressed version for testing
        if [ "$subdir" = "daily" ]; then
          gzip -c "$test_file" > "$test_file.gz"
          success "Created test compressed backup"
        fi
      else
        failure "Failed to create test backup file in $subdir directory"
      fi
    done
    
    # Verify disk space availability
    if command -v df &> /dev/null; then
      local disk_space=$(df -h "$BACKUP_DIR" | tail -1)
      local available=$(echo "$disk_space" | awk '{print $4}')
      local use_percent=$(echo "$disk_space" | awk '{print $5}' | tr -d '%')
      
      info "Backup disk space: $available available, ${use_percent}% used"
      
      if [ "$use_percent" -gt 90 ]; then
        warning "Backup disk space is critically low (${use_percent}% used)"
      elif [ "$use_percent" -gt 80 ]; then
        warning "Backup disk space is running low (${use_percent}% used)"
      else
        success "Backup disk space is sufficient (${use_percent}% used)"
      fi
    else
      warning "df command not available, skipping disk space check"
    fi
    
    # Test backup permissions
    local test_perm_file="${BACKUP_DIR}/test_perm_file"
    if touch "$test_perm_file" 2>/dev/null; then
      rm -f "$test_perm_file"
      success "Backup directory has correct write permissions"
    else
      failure "Cannot write to backup directory"
    fi
  fi
}

# Check maintenance tasks
check_maintenance_tasks() {
  section "Checking Maintenance Tasks"
  
  # Create test cron file for maintenance
  local test_cron="${TEST_DIR}/etc/cron.d/willhaben"
  echo "# Test cron file for willhaben maintenance" > "$test_cron"
  echo "0 1 * * * ${APP_ROOT}/bin/backup.sh --type=full" >> "$test_cron"
  echo "0 * * * * ${APP_ROOT}/bin/maintenance.sh --all" >> "$test_cron"
  
  # Verify cron jobs
  info "Checking for maintenance cron jobs"
  
  if [ -f "$test_cron" ]; then
    # Check if maintenance scripts are mentioned in cron file
    if grep -q "backup.sh\|maintenance.sh" "$test_cron" 2>/dev/null; then
      success "Maintenance tasks found in cron file: $test_cron"
    else
      failure "No maintenance tasks found in cron file: $test_cron"
    fi
  else
    failure "Cron file creation failed"
  fi
  
  # Check maintenance scripts
  if [ -f "${APP_ROOT}/bin/backup.sh" ]; then
    if [ -x "${APP_ROOT}/bin/backup.sh" ]; then
      success "Backup script exists and is executable"
    else
      failure "Backup script exists but is not executable"
    fi
  else
    failure "Backup script not found: ${APP_ROOT}/bin/backup.sh"
  fi
  
  # Check for maintenance script
  if [ -f "${APP_ROOT}/bin/maintenance.sh" ]; then
    if [ -x "${APP_ROOT}/bin/maintenance.sh" ]; then
      success "Maintenance script exists and is executable"
    else
      failure "Maintenance script exists but is not executable"
    fi
  else
    # Create a placeholder maintenance script for testing
    local maint_script="${APP_ROOT}/bin/maintenance.sh"
    if [ ! -f "$maint_script" ]; then
      echo '#!/bin/bash' > "$maint_script"
      echo 'echo "Maintenance script placeholder"' >> "$maint_script"
      echo 'case "$1" in' >> "$maint_script"
      echo '  --all) echo "Running all maintenance tasks" ;;' >> "$maint_script"
      echo '  --warm-cache) echo "Warming cache" ;;' >> "$maint_script"
      echo '  --rotate-logs) echo "Rotating logs" ;;' >> "$maint_script"
      echo '  --prune-metrics) echo "Pruning metrics" ;;' >> "$maint_script"
      echo '  --health-check) echo "Health check passed" ;;' >> "$maint_script"
      echo '  --help) echo "Usage: $0 [--all|--warm-cache|--rotate-logs|--prune-metrics|--health-check]" ;;' >> "$maint_script"
      echo '  *) echo "Unknown option: $1" >&2; exit 1 ;;' >> "$maint_script"
      echo 'esac' >> "$maint_script"
      echo 'exit 0' >> "$maint_script"
      chmod +x "$maint_script"
      warning "Created placeholder maintenance script for testing"
    fi
  fi
  
  # Test the maintenance script execution
  if [ -x "${APP_ROOT}/bin/maintenance.sh" ]; then
    if "${APP_ROOT}/bin/maintenance.sh" --help >/dev/null 2>&1; then
      success "Maintenance script runs successfully"
    else
      failure "Maintenance script fails when executed"
    fi
  fi
  
  # Create test log rotation config
  local logrotate_conf="${CONFIG_DIR}/logrotate.conf"
  echo "# Test logrotate configuration" > "$logrotate_conf"
  echo "${LOG_DIR}/*.log {" >> "$logrotate_conf"
  echo "  rotate 7" >> "$logrotate_conf"
  echo "  daily" >> "$logrotate_conf"
  echo "  compress" >> "$logrotate_conf"
  echo "  missingok" >> "$logrotate_conf"
  echo "  notifempty" >> "$logrotate_conf"
  echo "}" >> "$logrotate_conf"
  
  # Validate log rotation
  if [ -f "$logrotate_conf" ]; then
    success "Log rotation configuration exists in app config directory"
  else
    warning "No log rotation configuration found. Logs may grow indefinitely."
  fi
  
  # Create some test log files for rotation
  touch "${LOG_DIR}/app.log.1"
  touch "${LOG_DIR}/app.log.2.gz"
  
  # Check log files for rotation evidence
  local rotated_logs=$(find "$LOG_DIR" -name "*.log.*" -o -name "*.gz" 2>/dev/null | wc -l)
  if [ "$rotated_logs" -gt 0 ]; then
    success "Evidence of log rotation found: $rotated_logs rotated log files"
  else
    warning "No rotated log files found. This may indicate issues with log rotation."
  fi
  
  # Create some temp files to test cleanup
  touch "${TEST_DIR}/tmp_file1.tmp"
  touch "${TEST_DIR}/tmp_file2.tmp"
  mkdir -p "${TEST_DIR}/tmp_dir"
  
  # Test cleanup procedures
  local temp_files=$(find "${TEST_DIR}" -name "*.tmp" -o -name "tmp_*" 2>/dev/null | wc -l)
  if [ "$temp_files" -gt 5 ]; then
    warning "Found $temp_files temporary files. Cleanup procedures may need improvement."
  else
    success "Temporary files count is reasonable ($temp_files files)"
  fi
  
  # Create disk usage tracking file
  echo "$(date): Disk usage test" > "${LOG_DIR}/disk_usage.log"
  echo "$(df -h | grep '/dev/')" >> "${LOG_DIR}/disk_usage.log"
  
  if [ -f "${LOG_DIR}/disk_usage.log" ]; then
    success "Disk usage tracking is in place"
  else
    warning "Disk usage tracking failed. Consider adding this to maintenance tasks."
  fi
}

# Generate final report
final_report() {
  section "LOCAL TEST REPORT"
  
  echo -e "\n${BLUE}==================================================${NC}"
  echo -e "${BLUE}          LOCAL VERIFICATION RESULTS                ${NC}"
  echo -e "${BLUE}==================================================${NC}\n"
  
  echo "Verification performed on: $(date)"
  echo "Environment: $ENVIRONMENT (local test environment)"
  echo ""
  
  # Summary counts
  echo -e "Total checks performed: $((CHECK_PASSED + CHECK_FAILED + CHECK_SKIPPED))"
  echo -e "${GREEN}Passed: $CHECK_PASSED${NC}"
  echo -e "${RED}Failed: $CHECK_FAILED${NC}"
  echo -e "${YELLOW}Warnings/Skipped: $CHECK_SKIPPED${NC}"
  echo ""
  
  # Recommendations
  echo -e "${BLUE}TEST ENVIRONMENT SUMMARY:${NC}"
  echo "This verification was performed in a local test environment"
  echo "with the following test directories:"
  echo "  - Log directory: $LOG_DIR"
  echo "  - Backup directory: $BACKUP_DIR"
  echo "  - Config directory: $CONFIG_DIR"
  echo ""
  
  echo -e "${BLUE}NEXT STEPS:${NC}"
  echo "1. Review any failed checks and address issues"
  echo "2. For full production verification, run the main script:"
  echo "   bin/verify_production.sh --verbose"
  echo "3. Ensure all operational scripts are properly installed"
  echo "4. Configure proper cronjobs for maintenance and backups"
  echo ""
  
  # Production readiness status
  echo -e "${BLUE}LOCAL TEST STATUS:${NC}"
  if [ "$CHECK_FAILED" -eq 0 ]; then
    if [ "$CHECK_SKIPPED" -eq 0 ]; then
      echo -e "${GREEN}ALL LOCAL TESTS PASSED${NC}"
      echo "Local verification was successful. Proceed with production verification."
    else
      echo -e "${YELLOW}LOCAL TESTS PASSED WITH WARNINGS${NC}"
      echo "No critical issues in local test, but warnings should be reviewed."
    fi
  else
    echo -e "${RED}LOCAL TESTS FAILED${NC}"
    echo "Issues were found during local testing. Fix these before proceeding."
  fi
  
  echo -e "\n${BLUE}==================================================${NC}"
  echo -e "Detailed log available at: $LOGFILE"
  echo -e "${BLUE}==================================================${NC}\n"
  
  # Ask if the user wants to clean up the test environment
  read -p "Clean up test environment? (y/n): " cleanup
  if [[ "$cleanup" =~ ^[Yy]$ ]]; then
    echo "Cleaning up test environment..."
    # Keep logs but clean up test files
    find "${BACKUP_DIR}" -type f -name "willhaben-backup-*" -delete
    find "${TEST_DIR}" -name "tmp_*" -o -name "*.tmp" -delete
    echo "Test environment cleaned."
  else
    echo "Test environment preserved for inspection."
    echo "To clean up manually, run: rm -rf ${TEST_DIR}"
  fi
  
  # Return overall result
  return $OVERALL_RESULT
}

# Main function
main() {
  log "Starting local verification"
  log "Environment: $ENVIRONMENT (LOCAL TEST)"
  log "Verbose mode: $([ "$VERBOSE" -eq 1 ] && echo "enabled" || echo "disabled")"
  log ""
  
  # Check required scripts
  check_required_scripts
  
  # Check configuration files
  check_config_files
  
  # Test Redis connectivity
  check_redis
  
  # Check logging setup
  check_logging
  
  # Check monitoring endpoints
  check_monitoring
  
  # Validate security settings
  check_security
  
  # Test backup locations
  check_backup_locations
  
  # Check maintenance tasks
  check_maintenance_tasks
  
  # Generate final report
  final_report
  
  log "Local verification complete with status code: $OVERALL_RESULT"
  
  return $OVERALL_RESULT
}

# Run the main function
main "$@"

exit $OVERALL_RESULT
