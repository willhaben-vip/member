#!/bin/bash
#
# Backup script for willhaben.vip redirect service
# 
# This script handles:
# - Seller data backups
# - Configuration backups
# - Log archival
# - Redis data backup
# - Database dumps
# - Backup verification
# - Retention management
#
# Usage: ./backup.sh [--type=full|config|data|logs] [--skip-verify] [--no-compress]
#
# Author: DevOps Team
# Date: 2025-05-15

set -eo pipefail

# Default configuration
APP_ROOT="/Users/rene/k42/instantpay/code/INSTANTpay/willhaben.vip/member"
BACKUP_TYPE="full"
SKIP_VERIFY=0
NO_COMPRESS=0
BACKUP_DIR="/var/backups/willhaben"
LOG_DIR="/var/log/willhaben"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
BACKUP_PREFIX="willhaben-backup"
BACKUP_FILE="${BACKUP_DIR}/${BACKUP_PREFIX}-${BACKUP_TYPE}-${TIMESTAMP}"
LOGFILE="${LOG_DIR}/backup_${TIMESTAMP}.log"
LOCKFILE="/tmp/willhaben_backup.lock"
REDIS_HOST="127.0.0.1"
REDIS_PORT=6379
REDIS_DB=3
REDIS_DUMP_FILE="${BACKUP_DIR}/redis-dump-${TIMESTAMP}.rdb"
RETENTION_DAILY=7    # Days to keep daily backups
RETENTION_WEEKLY=4   # Weeks to keep weekly backups
RETENTION_MONTHLY=3  # Months to keep monthly backups
MIN_BACKUP_SIZE=1024 # Minimum expected backup size in bytes
CURRENT_DAY=$(date +"%u") # 1-7, Monday is 1
CURRENT_DATE=$(date +"%d") # 01-31
ARCHIVE_PASSWORD=${BACKUP_PASSWORD:-""} # Use env var or empty if not set

# Process command line arguments
for arg in "$@"; do
  case $arg in
    --type=*)
      BACKUP_TYPE="${arg#*=}"
      if [[ ! "$BACKUP_TYPE" =~ ^(full|config|data|logs)$ ]]; then
        echo "Invalid backup type: $BACKUP_TYPE. Must be one of: full, config, data, logs"
        exit 1
      fi
      BACKUP_FILE="${BACKUP_DIR}/${BACKUP_PREFIX}-${BACKUP_TYPE}-${TIMESTAMP}"
      shift
      ;;
    --skip-verify)
      SKIP_VERIFY=1
      shift
      ;;
    --no-compress)
      NO_COMPRESS=1
      shift
      ;;
    --help)
      echo "Usage: $0 [--type=full|config|data|logs] [--skip-verify] [--no-compress]"
      echo "  --type=TYPE       Backup type (full, config, data, logs)"
      echo "  --skip-verify     Skip backup verification"
      echo "  --no-compress     Don't compress the backup"
      exit 0
      ;;
    *)
      # Unknown option
      echo "Unknown option: $arg"
      echo "Usage: $0 [--type=full|config|data|logs] [--skip-verify] [--no-compress]"
      exit 1
      ;;
  esac
done

# Create log and backup directories if they don't exist
mkdir -p "${LOG_DIR}"
mkdir -p "${BACKUP_DIR}"
mkdir -p "${BACKUP_DIR}/daily"
mkdir -p "${BACKUP_DIR}/weekly"
mkdir -p "${BACKUP_DIR}/monthly"

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
  
  # Clean up temp files
  cleanup_temp_files
  
  # Send notification of failure
  notify "FAILED" "Backup failed: $1"
  
  exit 1
}

# Notification function
notify() {
  local status=$1
  local message=$2
  
  log "Backup $status: $message"
  
  # Here you would implement actual notification mechanism (email, Slack, etc.)
  # Example: curl -X POST -H 'Content-type: application/json' --data "{\"text\":\"Backup $status: $message\"}" $SLACK_WEBHOOK_URL
}

# Temp file cleanup
cleanup_temp_files() {
  log "Cleaning up temporary files..."
  find "${BACKUP_DIR}" -name "tmp_*" -type f -mmin +60 -delete
  find "${BACKUP_DIR}" -name "*.tmp" -type f -mmin +60 -delete
}

# Lock to prevent concurrent backups
acquire_lock() {
  log "Acquiring backup lock..."
  if [ -f "$LOCKFILE" ]; then
    PID=$(cat "$LOCKFILE")
    if ps -p $PID > /dev/null; then
      error_exit "Another backup is already running with PID $PID"
    else
      log "Stale lock file found. Previous backup may have crashed."
      rm -f "$LOCKFILE"
    fi
  fi
  echo $$ > "$LOCKFILE"
  log "Lock acquired"
}

# Release lock
release_lock() {
  log "Releasing backup lock..."
  rm -f "$LOCKFILE"
  log "Lock released"
}

# Verify prerequisites
check_prerequisites() {
  log "Checking prerequisites..."
  
  # Check for required commands
  for cmd in tar gzip redis-cli sha256sum find rsync; do
    if ! command -v $cmd &> /dev/null; then
      error_exit "$cmd is required but not installed"
    fi
  done
  
  # Check backup directory is writable
  if [ ! -w "$BACKUP_DIR" ]; then
    error_exit "Backup directory not writable: $BACKUP_DIR"
  fi
  
  # Check app root exists
  if [ ! -d "$APP_ROOT" ]; then
    error_exit "Application root not found: $APP_ROOT"
  fi
  
  log "Prerequisites check passed"
}

# Backup seller data
backup_seller_data() {
  log "Backing up seller data..."
  
  SELLER_TEMP="${BACKUP_DIR}/tmp_seller_data_${TIMESTAMP}"
  mkdir -p "$SELLER_TEMP"
  
  # Find seller directories (all directories with json files in them)
  SELLER_DIRS=$(find "$APP_ROOT" -name "*.json" -type f -exec dirname {} \; | sort | uniq)
  
  if [ -z "$SELLER_DIRS" ]; then
    log "Warning: No seller directories found"
  else
    # Copy seller data to temp directory
    for dir in $SELLER_DIRS; do
      if [ -d "$dir" ]; then
        DIRNAME=$(basename "$dir")
        log "Backing up seller directory: $DIRNAME"
        mkdir -p "${SELLER_TEMP}/${DIRNAME}"
        rsync -a "$dir/" "${SELLER_TEMP}/${DIRNAME}/"
      fi
    done
  fi
  
  # Create tarball of seller data
  if [ -d "$SELLER_TEMP" ]; then
    tar -cf "${BACKUP_FILE}_seller_data.tar" -C "$SELLER_TEMP" .
    log "Seller data backup complete: ${BACKUP_FILE}_seller_data.tar"
    
    # Calculate and store checksum
    sha256sum "${BACKUP_FILE}_seller_data.tar" > "${BACKUP_FILE}_seller_data.tar.sha256"
    
    # Clean up temp directory
    rm -rf "$SELLER_TEMP"
  fi
}

# Backup configuration
backup_config() {
  log "Backing up configuration files..."
  
  CONFIG_TEMP="${BACKUP_DIR}/tmp_config_${TIMESTAMP}"
  mkdir -p "$CONFIG_TEMP/config"
  
  # Copy all configuration files
  if [ -d "${APP_ROOT}/config" ]; then
    rsync -a "${APP_ROOT}/config/" "${CONFIG_TEMP}/config/"
    log "Config files copied to temp directory"
  else
    log "Warning: Config directory not found: ${APP_ROOT}/config"
  fi
  
  # Also backup .htaccess and nginx configs if they exist
  for file in "${APP_ROOT}/.htaccess" "${APP_ROOT}/nginx.conf.example"; do
    if [ -f "$file" ]; then
      cp "$file" "$CONFIG_TEMP/"
      log "Copied $(basename "$file")"
    fi
  done
  
  # Create tarball of configuration
  if [ -d "$CONFIG_TEMP" ]; then
    tar -cf "${BACKUP_FILE}_config.tar" -C "$CONFIG_TEMP" .
    log "Configuration backup complete: ${BACKUP_FILE}_config.tar"
    
    # Calculate and store checksum
    sha256sum "${BACKUP_FILE}_config.tar" > "${BACKUP_FILE}_config.tar.sha256"
    
    # Clean up temp directory
    rm -rf "$CONFIG_TEMP"
  fi
}

# Backup logs
backup_logs() {
  log "Backing up logs..."
  
  LOGS_TEMP="${BACKUP_DIR}/tmp_logs_${TIMESTAMP}"
  mkdir -p "$LOGS_TEMP"
  
  # Find log files newer than 1 day
  LOG_FILES=$(find "${LOG_DIR}" -name "*.log" -type f -mtime -1)
  
  if [ -z "$LOG_FILES" ]; then
    log "Warning: No recent log files found"
  else
    # Copy recent logs to temp directory
    for logfile in $LOG_FILES; do
      if [ -f "$logfile" ]; then
        LOGNAME=$(basename "$logfile")
        log "Backing up log file: $LOGNAME"
        cp "$logfile" "${LOGS_TEMP}/${LOGNAME}"
      fi
    done
  fi
  
  # Create tarball of logs
  if [ "$(ls -A "$LOGS_TEMP")" ]; then
    tar -cf "${BACKUP_FILE}_logs.tar" -C "$LOGS_TEMP" .
    log "Logs backup complete: ${BACKUP_FILE}_logs.tar"
    
    # Calculate and store checksum
    sha256sum "${BACKUP_FILE}_logs.tar" > "${BACKUP_FILE}_logs.tar.sha256"
    
    # Clean up temp directory
    rm -rf "$LOGS_TEMP"
  else
    log "No logs to backup"
    rm -rf "$LOGS_TEMP"
  fi
}

# Backup Redis data
backup_redis() {
  log "Backing up Redis data..."
  
  # Check if Redis is running
  if ! redis-cli -h $REDIS_HOST -p $REDIS_PORT ping > /dev/null; then
    log "Warning: Cannot connect to Redis at $REDIS_HOST:$REDIS_PORT"
    return
  fi
  
  # Create Redis backup - first try SAVE
  if redis-cli -h $REDIS_HOST -p $REDIS_PORT SAVE > /dev/null; then
    log "Redis SAVE command successful"
  else
    log "Warning: Redis SAVE command failed, trying BGSAVE"
    
    # Try BGSAVE as alternative
    redis-cli -h $REDIS_HOST -p $REDIS_PORT BGSAVE > /dev/null
    
    # Wait for BGSAVE to complete
    while true; do
      SAVE_IN_PROGRESS=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT INFO persistence | grep rdb_bgsave_in_progress:1)
      if [ -z "$SAVE_IN_PROGRESS" ]; then
        break
      fi
      log "Waiting for Redis BGSAVE to complete..."
      sleep 2
    done
  fi
  
  # Get Redis data directory
  REDIS_DIR=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT CONFIG GET dir | grep -v dir | tr -d '\r')
  REDIS_RDB=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT CONFIG GET dbfilename | grep -v dbfilename | tr -d '\r')
  
  if [ -z "$REDIS_DIR" ] || [ -z "$REDIS_RDB" ]; then
    log "Warning: Could not determine Redis data file location"
    return
  fi
  
  # Copy Redis dump file
  REDIS_DATA_FILE="$REDIS_DIR/$REDIS_RDB"
  
  if [ -f "$REDIS_DATA_FILE" ]; then
    cp "$REDIS_DATA_FILE" "$REDIS_DUMP_FILE"
    log "Redis data backup complete: $REDIS_DUMP_FILE"
    
    # Calculate and store checksum
    sha256sum "$REDIS_DUMP_FILE" > "$REDIS_DUMP_FILE.sha256"
  else
    log "Warning: Redis data file not found: $REDIS_DATA_FILE"
  fi
}

# Compress all backups
compress_backups() {
  if [ "$NO_COMPRESS" -eq 1 ]; then
    log "Skipping compression as requested"
    return
  fi
  
  log "Compressing backup files..."
  
  # Compress backup files
  for file in "${BACKUP_FILE}"_*.tar "$REDIS_DUMP_FILE"; do
    if [ -f "$file" ]; then
      log "Compressing $file..."
      
      # Use encryption if password is set
      if [ -n "$ARCHIVE_PASSWORD" ]; then
        gzip -c "$file" | openssl enc -aes-256-cbc -salt -pbkdf2 -pass pass:"$ARCHIVE_PASSWORD" -out "$file.gz.enc"
        rm -f "$file"
        log "Encrypted and compressed: $file.gz.enc"
      else
        gzip -9 "$file"
        log "Compressed: $file.gz"
      fi
    fi
  done
  
  log "Compression complete"
}

# Verify backup integrity
verify_backups() {
  if [ "$SKIP_VERIFY" -eq 1 ]; then
    log "Skipping verification as requested"
    return
  fi
  
  log "Verifying backup integrity..."
  VERIFY_FAILED=0
  
  # Verify checksums
  for checksum_file in "${BACKUP_FILE}"_*.sha256 "$REDIS_DUMP_FILE.sha256"; do
    if [ -f "$checksum_file" ]; then
      backup_file="${checksum_file%.sha256}"
      
      # If backup is compressed, we need to decompress for verification
      if [ ! -f "$backup_file" ] && [ -f "$backup_file.gz" ]; then
        log "Decompressing $backup_file.gz for verification"
        gzip -d -c "$backup_file.gz" > "$backup_file.tmp"
        VERIFY_RESULT=$(cd "$(dirname "$checksum_file")" && sha256sum -c "$checksum_file" 2>&1)
        rm -f "$backup_file.tmp"
      # If backup is encrypted, we need to decrypt for verification
      elif [ ! -f "$backup_file" ] && [ -f "$backup_file.gz.enc" ]; then
        if [ -n "$ARCHIVE_PASSWORD" ]; then
          log "Decrypting and decompressing $backup_file.gz.enc for verification"
          openssl enc -aes-256-cbc -d -salt -pbkdf2 -pass pass:"$ARCHIVE_PASSWORD" -in "$backup_file.gz.enc" | gunzip > "$backup_file.tmp"
          VERIFY_RESULT=$(cd "$(dirname "$checksum_file")" && sha256sum -c "$checksum_file" 2>&1)
          rm -f "$backup_file.tmp"
        else
          log "Warning: Cannot verify encrypted backup without password: $backup_file.gz.enc"
          VERIFY_RESULT="SKIPPED"
        fi
      else
        # Verify the original file directly
        VERIFY_RESULT=$(cd "$(dirname "$checksum_file")" && sha256sum -c "$checksum_file" 2>&1)
      fi

      if [[ "$VERIFY_RESULT" == *"OK"* ]]; then
        log "Verification successful for $(basename "$backup_file")"
      elif [[ "$VERIFY_RESULT" == "SKIPPED" ]]; then
        log "Verification skipped for encrypted backup: $(basename "$backup_file")"
      else
        log "Verification FAILED for $(basename "$backup_file"): $VERIFY_RESULT"
        VERIFY_FAILED=1
      fi
    fi
  done

  # Check backup file sizes
  for backup_file in "${BACKUP_FILE}"_*.tar.gz "${BACKUP_FILE}"_*.tar.gz.enc "$REDIS_DUMP_FILE.gz" "$REDIS_DUMP_FILE.gz.enc"; do
    if [ -f "$backup_file" ]; then
      file_size=$(stat -c%s "$backup_file" 2>/dev/null || stat -f%z "$backup_file" 2>/dev/null)
      if [ -z "$file_size" ] || [ "$file_size" -lt "$MIN_BACKUP_SIZE" ]; then
        log "Warning: Backup file is suspiciously small (${file_size} bytes): $(basename "$backup_file")"
        VERIFY_FAILED=1
      else
        log "Size check passed for $(basename "$backup_file"): ${file_size} bytes"
      fi
    fi
  done

  # Content validation - spot check a sample of files in the archives
  for tar_file in "${BACKUP_FILE}"_*.tar "${BACKUP_FILE}"_*.tar.tmp; do
    if [ -f "$tar_file" ]; then
      # Extract file listing from tar
      FILES_IN_ARCHIVE=$(tar -tf "$tar_file" 2>/dev/null | wc -l)
      if [ "$FILES_IN_ARCHIVE" -lt 1 ]; then
        log "Warning: No files found in archive: $(basename "$tar_file")"
        VERIFY_FAILED=1
      else
        log "Content validation passed for $(basename "$tar_file"): $FILES_IN_ARCHIVE files found"
      fi
      
      # For selected types, do deeper validation
      if [[ "$tar_file" == *"_config.tar"* ]]; then
        # Config backups should contain configuration files
        if ! tar -tf "$tar_file" | grep -q "config/"; then
          log "Warning: No config directory found in configuration backup"
          VERIFY_FAILED=1
        fi
      fi
    fi
  done

  if [ "$VERIFY_FAILED" -eq 1 ]; then
    log "Backup verification found issues - check logs for details"
  else
    log "All backup verifications passed"
  fi
}

# Manage backup retention
manage_retention() {
  log "Managing backup retention..."

  # Determine backup category (daily, weekly, monthly)
  local BACKUP_CATEGORY="daily"
  
  # Sunday backups are weekly (day 7)
  if [ "$CURRENT_DAY" -eq 7 ]; then
    BACKUP_CATEGORY="weekly"
  fi
  
  # First day of month backups are monthly
  if [ "$CURRENT_DATE" -eq 1 ]; then
    BACKUP_CATEGORY="monthly"
  fi
  
  log "Categorizing current backup as: $BACKUP_CATEGORY"
  
  # Copy backup files to the appropriate directory
  for backup_file in "${BACKUP_FILE}"_* "$REDIS_DUMP_FILE"*; do
    if [ -f "$backup_file" ]; then
      BACKUP_NAME=$(basename "$backup_file")
      cp "$backup_file" "${BACKUP_DIR}/${BACKUP_CATEGORY}/"
      log "Backup copied to ${BACKUP_CATEGORY} directory: $BACKUP_NAME"
    fi
  done

  # Clean up old backups
  clean_old_backups "daily" $RETENTION_DAILY
  clean_old_backups "weekly" $RETENTION_WEEKLY
  clean_old_backups "monthly" $RETENTION_MONTHLY
  
  # Verify backup sets are complete
  verify_backup_sets
}

# Clean up old backups based on retention policy
clean_old_backups() {
  local category=$1
  local retention_days=$2
  
  log "Cleaning old $category backups, keeping $retention_days days/periods..."
  
  # Find and remove old backups
  find "${BACKUP_DIR}/${category}" -type f -mtime +${retention_days} -name "${BACKUP_PREFIX}-*" -print | while read old_backup; do
    log "Removing old $category backup: $(basename "$old_backup")"
    rm -f "$old_backup"
  done
}

# Verify backup sets are complete for each timestamp
verify_backup_sets() {
  log "Verifying backup sets completeness..."
  
  # For full backups, check if we have all components
  for base_dir in "${BACKUP_DIR}/daily" "${BACKUP_DIR}/weekly" "${BACKUP_DIR}/monthly"; do
    if [ ! -d "$base_dir" ]; then
      continue
    fi
    
    # Get unique backup timestamps
    find "$base_dir" -name "${BACKUP_PREFIX}-full-*" | sed -E 's/.*full-([0-9-_]+).*/\1/' | sort | uniq | while read backup_ts; do
      # Check if all components exist for this timestamp
      seller_file=$(find "$base_dir" -name "${BACKUP_PREFIX}-full-${backup_ts}_seller_data.*" | head -1)
      config_file=$(find "$base_dir" -name "${BACKUP_PREFIX}-full-${backup_ts}_config.*" | head -1)
      redis_file=$(find "$base_dir" -name "redis-dump-${backup_ts}.*" | head -1)
      
      if [ -z "$seller_file" ] || [ -z "$config_file" ] || [ -z "$redis_file" ]; then
        log "Warning: Incomplete backup set for timestamp ${backup_ts} in ${base_dir}"
        # List missing components
        [ -z "$seller_file" ] && log "  Missing seller data component"
        [ -z "$config_file" ] && log "  Missing configuration component"
        [ -z "$redis_file" ] && log "  Missing Redis data component"
      else
        log "Complete backup set found for timestamp ${backup_ts} in ${base_dir}"
      fi
    done
  done
}

# Generate final report
generate_report() {
  log "=== BACKUP SUMMARY REPORT ==="
  log "Backup type: $BACKUP_TYPE"
  log "Timestamp: $TIMESTAMP"
  
  # Summary of backed up files
  log "Backed up files:"
  for backup_file in "${BACKUP_FILE}"_* "$REDIS_DUMP_FILE"*; do
    if [ -f "$backup_file" ]; then
      file_size=$(stat -c%s "$backup_file" 2>/dev/null || stat -f%z "$backup_file" 2>/dev/null)
      file_size_human=$(awk "BEGIN {printf \"%.2f MB\", ${file_size}/1024/1024}")
      log "  - $(basename "$backup_file") ($file_size_human)"
    fi
  done
  
  # Space usage reporting
  log "Backup space usage:"
  daily_size=$(du -sh "${BACKUP_DIR}/daily" 2>/dev/null | cut -f1)
  weekly_size=$(du -sh "${BACKUP_DIR}/weekly" 2>/dev/null | cut -f1)
  monthly_size=$(du -sh "${BACKUP_DIR}/monthly" 2>/dev/null | cut -f1)
  total_size=$(du -sh "${BACKUP_DIR}" 2>/dev/null | cut -f1)
  
  log "  - Daily backups: ${daily_size:-N/A}"
  log "  - Weekly backups: ${weekly_size:-N/A}"
  log "  - Monthly backups: ${monthly_size:-N/A}"
  log "  - Total backup size: ${total_size:-N/A}"
  
  # Disk space availability
  avail_space=$(df -h "${BACKUP_DIR}" | tail -1 | awk '{print $4}')
  log "  - Available space: ${avail_space}"
  
  # Check if low on space (below 10%)
  disk_percent=$(df "${BACKUP_DIR}" | tail -1 | awk '{print $5}' | tr -d '%')
  if [ "$disk_percent" -gt 90 ]; then
    log "WARNING: Low disk space on backup volume (${disk_percent}% used)"
  fi
  
  # Retention status
  log "Retention status:"
  daily_count=$(find "${BACKUP_DIR}/daily" -type f -name "${BACKUP_PREFIX}-*" | wc -l)
  weekly_count=$(find "${BACKUP_DIR}/weekly" -type f -name "${BACKUP_PREFIX}-*" | wc -l)
  monthly_count=$(find "${BACKUP_DIR}/monthly" -type f -name "${BACKUP_PREFIX}-*" | wc -l)
  
  log "  - Daily backups: ${daily_count} files (keeping ${RETENTION_DAILY} days)"
  log "  - Weekly backups: ${weekly_count} files (keeping ${RETENTION_WEEKLY} weeks)"
  log "  - Monthly backups: ${monthly_count} files (keeping ${RETENTION_MONTHLY} months)"
  
  log "=== END OF BACKUP REPORT ==="
}

# Main backup function
run_backup() {
  # Check if we need to backup seller data
  if [[ "$BACKUP_TYPE" == "full" || "$BACKUP_TYPE" == "data" ]]; then
    backup_seller_data
  fi
  
  # Check if we need to backup configuration
  if [[ "$BACKUP_TYPE" == "full" || "$BACKUP_TYPE" == "config" ]]; then
    backup_config
  fi
  
  # Check if we need to backup logs
  if [[ "$BACKUP_TYPE" == "full" || "$BACKUP_TYPE" == "logs" ]]; then
    backup_logs
  fi
  
  # Backup Redis data for full and data backups
  if [[ "$BACKUP_TYPE" == "full" || "$BACKUP_TYPE" == "data" ]]; then
    backup_redis
  fi
  
  # Compress all backup files
  compress_backups
  
  # Verify backup integrity
  verify_backups
  
  # Manage backup retention
  manage_retention
  
  # Generate final report
  generate_report
}

# Main script execution
log "Beginning backup process (type: $BACKUP_TYPE)"

# Acquire lock to prevent concurrent executions
acquire_lock

# Ensure we release lock when script exits
trap 'release_lock' EXIT

# Check prerequisites
check_prerequisites

# Clean up any temp files from previous runs
cleanup_temp_files

# Run backup
run_backup

# Success!
log "Backup completed successfully"
notify "SUCCESS" "Backup completed successfully"

exit 0
