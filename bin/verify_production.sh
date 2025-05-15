#!/bin/bash
#
# Production Verification Script for willhaben.vip redirect service
# 
# This script performs comprehensive checks to ensure all components
# are ready for production deployment.
#
# Author: DevOps Team
# Date: 2025-05-15

set -eo pipefail

# Configuration
APP_ROOT="/Users/rene/k42/instantpay/code/INSTANTpay/willhaben.vip/member"
BACKUP_DIR="/var/backups/willhaben"
LOG_DIR="/var/log/willhaben"
CONFIG_DIR="${APP_ROOT}/config"
REDIS_HOST="127.0.0.1"
REDIS_PORT=6379
REDIS_DB=3
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
LOGFILE="${LOG_DIR}/verify_production_${TIMESTAMP}.log"
ENVIRONMENT=${ENVIRONMENT:-"production"}
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
      echo "Usage: $0 [--verbose] [--env=production|staging|development]"
      echo "  --verbose       Display detailed output"
      echo "  --env=ENV       Environment to check (default: production)"
      exit 0
      ;;
    *)
      # Unknown option
      echo "Unknown option: $arg"
      echo "Usage: $0 [--verbose] [--env=production|staging|development]"
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

# Test Redis connectivity
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
    local test_key="willhaben_verify_production_test"
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
    failure "Redis is not responsive"
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
  
  # Check for log files
  local log_count=$(find "$LOG_DIR" -name "*.log" | wc -l)
  info "Found $log_count log files"
  
  if [ "$log_count" -eq 0 ]; then
    warning "No log files found. This may be normal for a new installation."
  else
    success "Log files exist"
    
    # Check log file permissions
    local incorrect_perms=$(find "$LOG_DIR" -name "*.log" ! -perm 0640 | wc -l)
    
    if [ "$incorrect_perms" -gt 0 ]; then
      warning "$incorrect_perms log files have incorrect permissions"
    else
      success "All log files have correct permissions"
    fi
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

# Check monitoring endpoints
check_monitoring() {
  section "Checking Monitoring Endpoints"
  
  # Check for health endpoint
  local health_endpoint="http://localhost/health"
  
  if command -v curl &> /dev/null; then
    info "Testing health endpoint: $health_endpoint"
    
    if curl -s -f "$health_endpoint" > /dev/null 2>&1; then
      success "Health endpoint is accessible"
    else
      warning "Health endpoint is not accessible (this may be expected if service is not running)"
    fi
  else
    warning "curl not installed, skipping health endpoint check"
  fi
  
  # Check for monitoring config
  if [ -f "${CONFIG_DIR}/monitoring.json" ]; then
    success "Monitoring configuration exists"
    
    if command -v jq &> /dev/null; then
      if jq -e . "${CONFIG_DIR}/monitoring.json" > /dev/null 2>&1; then
        success "Monitoring config is valid JSON"
      else
        failure "Monitoring config is not valid JSON"
      fi
    fi
  else
    warning "Monitoring configuration not found"
  fi
  
  # Check for prometheus exporters if applicable
  if [ -f "${APP_ROOT}/bin/prometheus_exporter.sh" ]; then
    if [ -x "${APP_ROOT}/bin/prometheus_exporter.sh" ]; then
      success "Prometheus exporter script exists and is executable"
    else
      failure "Prometheus exporter script is not executable"
    fi
  else
    info "No Prometheus exporter script found (may not be required)"
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
  
  # Check file permissions
  info "Checking sensitive file permissions"
  
  # Critical files that should have restricted permissions
  local secure_files=(
    "${CONFIG_DIR}/security.json"
    "${CONFIG_DIR}/credentials.json"
    "${CONFIG_DIR}/ssl"
  )
  
  for file in "${secure_files[@]}"; do
    if [ -e "$file" ]; then
      # Check if the file/directory has appropriate permissions
      # For files: only owner should have write permission
      # For directories: no world-writable
      if [ -d "$file" ] && [ "$(stat -c '%a' "$file" 2>/dev/null || stat -f '%A' "$file" 2>/dev/null)" = "755" ]; then
        success "Directory permissions correct: $file"
      elif [ -f "$file" ] && [ "$(stat -c '%a' "$file" 2>/dev/null || stat -f '%A' "$file" 2>/dev/null)" = "600" ]; then
        success "File permissions correct: $file"
      else
        failure "Incorrect permissions on sensitive file/directory: $file"
      fi
    fi
  done
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
    if touch "${BACKUP_DIR}/test_perm_file" 2>/dev/null; then
      rm -f "${BACKUP_DIR}/test_perm_file"
      success "Backup directory has correct write permissions"
    else
      failure "Cannot write to backup directory"
    fi
    
    # Validate backup retention
    # Check existing backups to see if retention seems to be working
    local daily_backups=$(find "${BACKUP_DIR}/daily" -type f -name "willhaben-backup-*" 2>/dev/null | wc -l)
    local weekly_backups=$(find "${BACKUP_DIR}/weekly" -type f -name "willhaben-backup-*" 2>/dev/null | wc -l)
    local monthly_backups=$(find "${BACKUP_DIR}/monthly" -type f -name "willhaben-backup-*" 2>/dev/null | wc -l)
    
    info "Found $daily_backups daily backups, $weekly_backups weekly backups, $monthly_backups monthly backups"
    
    if [ "$daily_backups" -eq 0 ] && [ "$weekly_backups" -eq 0 ] && [ "$monthly_backups" -eq 0 ]; then
      warning "No backups found. This may be normal for a new installation."
    else
      success "Existing backups found"
      
      # Check for very old backups that should have been rotated out
      local old_backups=$(find "${BACKUP_DIR}" -type f -name "willhaben-backup-*" -mtime +90 2>/dev/null | wc -l)
      if [ "$old_backups" -gt 0 ]; then
        warning "Found $old_backups backups older than 90 days. Retention policy may not be working."
      else
        success "No excessively old backups found. Retention policy appears functional."
      fi
    fi
  fi
}

# Check maintenance tasks
check_maintenance_tasks() {
  section "Checking Maintenance Tasks"
  
  # Verify cron jobs
  info "Checking for maintenance cron jobs"
  
  local cron_files=("/etc/crontab" "/etc/cron.d/willhaben")
  local cron_found=0
  
  for cron_file in "${cron_files[@]}"; do
    if [ -f "$cron_file" ]; then
      # Check if maintenance scripts are mentioned in cron file
      if grep -q "backup.sh\|maintenance.sh" "$cron_file" 2>/dev/null; then
        success "Maintenance tasks found in cron file: $cron_file"
        cron_found=1
      else
        info "No maintenance tasks found in cron file: $cron_file"
      fi
    fi
  done
  
  if [ "$cron_found" -eq 0 ]; then
    warning "No maintenance cron jobs found. You should configure scheduled maintenance tasks."
  fi
  
  # Check maintenance scripts
  if [ -f "${APP_ROOT}/bin/maintenance.sh" ]; then
    if [ -x "${APP_ROOT}/bin/maintenance.sh" ]; then
      success "Maintenance script exists and is executable"
      
      # Try to run maintenance script with --help or --check to see if it works
      if "${APP_ROOT}/bin/maintenance.sh" --help >/dev/null 2>&1 || "${APP_ROOT}/bin/maintenance.sh" --check >/dev/null 2>&1; then
        success "Maintenance script runs successfully"
      else
        warning "Maintenance script exists but may have issues when executed"
      fi
    else
      failure "Maintenance script exists but is not executable"
    fi
  else
    warning "Maintenance script not found: ${APP_ROOT}/bin/maintenance.sh"
  fi
  
  # Validate log rotation
  if [ -f "/etc/logrotate.d/willhaben" ]; then
    success "Log rotation configuration exists"
  elif [ -f "${CONFIG_DIR}/logrotate.conf" ]; then
    success "Log rotation configuration exists in app config directory"
  else
    warning "No log rotation configuration found. Logs may grow indefinitely."
  fi
  
  # Check log files for rotation evidence
  local rotated_logs=$(find "$LOG_DIR" -name "*.log.*" -o -name "*.gz" 2>/dev/null | wc -l)
  if [ "$rotated_logs" -gt 0 ]; then
    success "Evidence of log rotation found: $rotated_logs rotated log files"
  else
    info "No rotated log files found. This may be normal for a new installation."
  fi
  
  # Test cleanup procedures
  local temp_files=$(find "${APP_ROOT}" -name "*.tmp" -o -name "*.temp" -o -name "tmp_*" 2>/dev/null | wc -l)
  if [ "$temp_files" -gt 10 ]; then
    warning "Found $temp_files temporary files. Cleanup procedures may not be working effectively."
  else
    success "No excessive temporary files found. Cleanup procedures seem effective."
  fi
  
  # Check disk usage trends
  if [ -f "${LOG_DIR}/disk_usage.log" ]; then
    success "Disk usage tracking is in place"
  else
    info "No disk usage tracking found. Consider adding this to maintenance tasks."
  fi
}

# Generate final report
final_report() {
  section "PRODUCTION READINESS REPORT"
  
  echo -e "\n${BLUE}==================================================${NC}"
  echo -e "${BLUE}          PRODUCTION VERIFICATION RESULTS          ${NC}"
  echo -e "${BLUE}==================================================${NC}\n"
  
  echo "Verification performed on: $(date)"
  echo "Environment: $ENVIRONMENT"
  echo ""
  
  # Summary counts
  echo -e "Total checks performed: $((CHECK_PASSED + CHECK_FAILED + CHECK_SKIPPED))"
  echo -e "${GREEN}Passed: $CHECK_PASSED${NC}"
  echo -e "${RED}Failed: $CHECK_FAILED${NC}"
  echo -e "${YELLOW}Warnings/Skipped: $CHECK_SKIPPED${NC}"
  echo ""
  
  # Recommendations for failures
  if [ "$CHECK_FAILED" -gt 0 ]; then
    echo -e "${RED}CRITICAL ISSUES REQUIRING ATTENTION:${NC}"
    echo "The following critical issues must be resolved before deploying to production:"
    echo ""
    echo "1. Review the log file for details on all failed checks: $LOGFILE"
    echo "2. Ensure all required scripts are in place and executable"
    echo "3. Verify all configuration files are valid and properly formatted"
    echo "4. Ensure all services are running and responsive"
    echo "5. Fix any file permission issues on sensitive files"
    echo "6. Resolve any backup directory access issues"
    echo ""
  fi
  
  # Required actions for warnings
  if [ "$CHECK_SKIPPED" -gt 0 ]; then
    echo -e "${YELLOW}WARNINGS REQUIRING REVIEW:${NC}"
    echo "The following warnings should be reviewed before production deployment:"
    echo ""
    echo "1. Ensure optional components are installed if needed"
    echo "2. Verify monitoring is properly configured"
    echo "3. Check disk space availability and planning"
    echo "4. Review SSL certificate expiration dates"
    echo "5. Ensure maintenance tasks are scheduled properly"
    echo ""
  fi
  
  # Production readiness status
  echo -e "${BLUE}PRODUCTION READINESS STATUS:${NC}"
  if [ "$CHECK_FAILED" -eq 0 ]; then
    if [ "$CHECK_SKIPPED" -eq 0 ]; then
      echo -e "${GREEN}FULLY READY FOR PRODUCTION${NC}"
      echo "All checks have passed. The system is ready for production deployment."
    else
      echo -e "${YELLOW}READY WITH WARNINGS${NC}"
      echo "No critical issues found, but there are warnings to review before proceeding."
    fi
  else
    echo -e "${RED}NOT READY FOR PRODUCTION${NC}"
    echo "Critical issues must be resolved before deploying to production."
  fi
  
  echo -e "\n${BLUE}==================================================${NC}"
  echo -e "Detailed log available at: $LOGFILE"
  echo -e "${BLUE}==================================================${NC}\n"
  
  # Return overall result
  return $OVERALL_RESULT
}

# Main function
main() {
  log "Starting production verification"
  log "Environment: $ENVIRONMENT"
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
  
  log "Verification complete with status code: $OVERALL_RESULT"
  
  return $OVERALL_RESULT
}

# Run the main function
main "$@"

exit $OVERALL_RESULT
