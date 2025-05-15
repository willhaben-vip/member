#!/bin/bash
#
# Test script for validating operational scripts
# This script tests backup.sh, maintenance.sh, and deploy.sh to ensure
# they work correctly under various scenarios.
#
# Author: DevOps Team
# Date: 2025-05-15

set -eo pipefail

# Configuration
APP_ROOT="/Users/rene/k42/instantpay/code/INSTANTpay/willhaben.vip/member"
TEST_DIR="${APP_ROOT}/tests/ops_test"
LOG_DIR="${TEST_DIR}/logs"
BACKUP_TEST_DIR="${TEST_DIR}/backups"
TEST_LOG="${LOG_DIR}/test_ops_$(date +"%Y-%m-%d_%H-%M-%S").log"
TEST_ENV="test"
TEST_FAILED=0
TESTS_RUN=0
TESTS_PASSED=0

# Colors for terminal output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

# Create test directories
mkdir -p "${TEST_DIR}"
mkdir -p "${LOG_DIR}"
mkdir -p "${BACKUP_TEST_DIR}"

# Log function
log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$TEST_LOG"
}

# Success function
success() {
  echo -e "${GREEN}[PASS]${NC} $1" | tee -a "$TEST_LOG"
  TESTS_PASSED=$((TESTS_PASSED + 1))
}

# Failure function
failure() {
  echo -e "${RED}[FAIL]${NC} $1" | tee -a "$TEST_LOG"
  TEST_FAILED=1
}

# Warning function
warning() {
  echo -e "${YELLOW}[WARN]${NC} $1" | tee -a "$TEST_LOG"
}

# Test if a script exists
test_script_exists() {
  local script=$1
  
  log "Checking if ${script} exists"
  TESTS_RUN=$((TESTS_RUN + 1))
  
  if [ -f "${APP_ROOT}/bin/${script}" ]; then
    success "${script} exists"
    return 0
  else
    failure "${script} does not exist"
    return 1
  fi
}

# Test if a script is executable
test_script_executable() {
  local script=$1
  
  log "Checking if ${script} is executable"
  TESTS_RUN=$((TESTS_RUN + 1))
  
  if [ -x "${APP_ROOT}/bin/${script}" ]; then
    success "${script} is executable"
    return 0
  else
    failure "${script} is not executable"
    return 1
  fi
}

# Run a test and capture the output and result
run_test() {
  local description=$1
  local command=$2
  local expect_success=${3:-true}
  
  log "TEST: $description"
  TESTS_RUN=$((TESTS_RUN + 1))
  
  local output
  local exit_code
  
  # Run the command and capture output and exit code
  output=$($command 2>&1) || exit_code=$?
  
  # Check if the command succeeded or failed
  if [ "$expect_success" = true ] && [ -z "$exit_code" ]; then
    success "$description"
    log "Output: $output"
    return 0
  elif [ "$expect_success" = false ] && [ -n "$exit_code" ]; then
    success "$description (expected failure occurred)"
    log "Output: $output"
    return 0
  else
    failure "$description"
    log "Output: $output"
    log "Exit code: ${exit_code:-0}"
    return 1
  fi
}

# Cleanup test environment
cleanup() {
  log "Cleaning up test environment..."
  
  # Uncomment next line if you want to keep test files for inspection
  # rm -rf "${TEST_DIR}" 
  
  log "Cleanup complete"
}

# Test backup.sh functionality
test_backup() {
  log "=== Testing backup.sh ==="
  
  # Test if backup.sh exists and is executable
  if ! test_script_exists "backup.sh" || ! test_script_executable "backup.sh"; then
    warning "Skipping backup.sh tests since the script is missing or not executable"
    return 1
  fi
  
  # Test each backup type
  for backup_type in full config data logs; do
    run_test "Backup type: $backup_type" "${APP_ROOT}/bin/backup.sh --type=$backup_type --no-compress --skip-verify BACKUP_DIR=${BACKUP_TEST_DIR}/$backup_type" true
    
    # Verify backup files exist
    if [ "$backup_type" = "full" ] || [ "$backup_type" = "data" ]; then
      run_test "Verify seller data backup exists ($backup_type)" "ls ${BACKUP_TEST_DIR}/$backup_type/willhaben-backup-$backup_type-*_seller_data.tar* 2>/dev/null" true
    fi
    
    if [ "$backup_type" = "full" ] || [ "$backup_type" = "config" ]; then
      run_test "Verify config backup exists ($backup_type)" "ls ${BACKUP_TEST_DIR}/$backup_type/willhaben-backup-$backup_type-*_config.tar* 2>/dev/null" true
    fi
    
    if [ "$backup_type" = "full" ] || [ "$backup_type" = "logs" ]; then
      run_test "Verify logs backup may exist ($backup_type)" "ls ${BACKUP_TEST_DIR}/$backup_type/willhaben-backup-$backup_type-*_logs.tar* 2>/dev/null || echo 'No logs to backup (may be normal)'" true
    fi
  done
  
  # Test backup verification
  run_test "Backup with verification" "${APP_ROOT}/bin/backup.sh --type=config BACKUP_DIR=${BACKUP_TEST_DIR}/verify" true
  
  # Test encryption if password is set
  if [ -n "${BACKUP_PASSWORD:-}" ]; then
    run_test "Backup with encryption" "${APP_ROOT}/bin/backup.sh --type=config BACKUP_DIR=${BACKUP_TEST_DIR}/encrypt ARCHIVE_PASSWORD='test123'" true
    run_test "Verify encrypted files exist" "ls ${BACKUP_TEST_DIR}/encrypt/*enc 2>/dev/null" true
  else
    warning "Skipping encryption test (BACKUP_PASSWORD not set)"
  fi
  
  # Test retention management
  run_test "Retention management test" "${APP_ROOT}/bin/backup.sh --type=config BACKUP_DIR=${BACKUP_TEST_DIR}/retention RETENTION_DAILY=1 RETENTION_WEEKLY=1 RETENTION_MONTHLY=1" true
  
  # Test error conditions
  run_test "Test with invalid backup type (should fail)" "${APP_ROOT}/bin/backup.sh --type=invalid BACKUP_DIR=${BACKUP_TEST_DIR}/error" false
  
  # Test with read-only directory (if possible to create in test)
  if mkdir -p "${BACKUP_TEST_DIR}/readonly" && chmod -w "${BACKUP_TEST_DIR}/readonly" 2>/dev/null; then
    run_test "Test with read-only directory (should fail)" "${APP_ROOT}/bin/backup.sh --type=config BACKUP_DIR=${BACKUP_TEST_DIR}/readonly" false
    chmod +w "${BACKUP_TEST_DIR}/readonly"
  fi
  
  log "=== Backup.sh tests completed ==="
}

# Test maintenance.sh functionality
test_maintenance() {
  log "=== Testing maintenance.sh ==="
  
  # Test if maintenance.sh exists and is executable
  if ! test_script_exists "maintenance.sh" || ! test_script_executable "maintenance.sh"; then
    warning "Skipping maintenance.sh tests since the script is missing or not executable"
    return 1
  fi
  
  # Test cache warming
  run_test "Cache warming" "${APP_ROOT}/bin/maintenance.sh --warm-cache" true
  
  # Test log rotation
  run_test "Log rotation" "${APP_ROOT}/bin/maintenance.sh --rotate-logs" true
  
  # Test metrics pruning
  run_test "Metrics pruning" "${APP_ROOT}/bin/maintenance.sh --prune-metrics" true
  
  # Test health checks
  run_test "Health checks" "${APP_ROOT}/bin/maintenance.sh --health-check" true
  
  # Test all options together
  run_test "All maintenance tasks" "${APP_ROOT}/bin/maintenance.sh --all" true
  
  # Test error condition
  run_test "Test with invalid option (should fail)" "${APP_ROOT}/bin/maintenance.sh --invalid-option" false
  
  log "=== Maintenance.sh tests completed ==="
}

# Test deploy.sh functionality
test_deploy() {
  log "=== Testing deploy.sh ==="
  
  # Test if deploy.sh exists and is executable
  if ! test_script_exists "deploy.sh" || ! test_script_executable "deploy.sh"; then
    warning "Skipping deploy.sh tests since the script is missing or not executable"
    return 1
  fi
  
  # Create a test branch or release for deployment tests
  TEST_BRANCH="test-$(date +%s)"
  TEST_CONFIG="test_config_$(date +%s).json"
  
  # Test configuration validation
  run_test "Configuration validation" "${APP_ROOT}/bin/deploy.sh --validate-config" true
  
  # Test dry run deployment
  run_test "Dry run deployment" "${APP_ROOT}/bin/deploy.sh --dry-run --branch=${TEST_BRANCH}" true
  
  # Test rollback functionality 
  # (This assumes the deploy script has a --rollback option)
  run_test "Rollback functionality" "${APP_ROOT}/bin/deploy.sh --rollback" true
  
  # Test security checks
  run_test "Security checks" "${APP_ROOT}/bin/deploy.sh --security-check" true
  
  # Test with invalid configuration (assuming the deploy script has validation)
  if [ ! -f "${APP_ROOT}/config/${TEST_CONFIG}" ]; then
    echo '{"invalid": "config"}' > "${APP_ROOT}/config/${TEST_CONFIG}"
    run_test "Test with invalid configuration (should fail)" "${APP_ROOT}/bin/deploy.sh --config=${TEST_CONFIG}" false
    rm -f "${APP_ROOT}/config/${TEST_CONFIG}"
  fi
  
  log "=== Deploy.sh tests completed ==="
}

# Print test results summary
print_summary() {
  log ""
  log "=== TEST SUMMARY ==="
  log "Total tests run: $TESTS_RUN"
  log "Tests passed: $TESTS_PASSED"
  log "Tests failed: $((TESTS_RUN - TESTS_PASSED))"
  
  if [ "$TEST_FAILED" -eq 0 ]; then
    log "OVERALL RESULT: ${GREEN}PASS${NC}"
  else
    log "OVERALL RESULT: ${RED}FAIL${NC}"
  fi
  log "===================="
  
  log "Test log available at: $TEST_LOG"
}

# Main function
main() {
  log "Starting operational scripts test suite"
  log "Test environment: $TEST_ENV"
  log "App root: $APP_ROOT"
  log ""
  
  # Test backup script
  test_backup
  
  # Test maintenance script
  test_maintenance
  
  # Test deploy script
  test_deploy
  
  # Clean up test environment
  cleanup
  
  # Print test summary
  print_summary
  
  # Return overall result
  return $TEST_FAILED
}

# Run the main function
main "$@"

exit $TEST_FAILED

