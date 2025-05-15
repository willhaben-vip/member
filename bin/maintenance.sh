#!/bin/bash
#
# Maintenance script for willhaben.vip redirect service
# 
# This script handles routine maintenance tasks including:
# - Cache warm-up procedure
# - Log rotation and cleanup
# - Metrics database pruning
# - Health check execution
#
# Usage: ./maintenance.sh [--skip-cache] [--skip-logs] [--skip-metrics] [--skip-healthcheck]
#
# Author: DevOps Team
# Date: 2025-05-15

set -eo pipefail

# Configuration
APP_ROOT="/Users/rene/k42/instantpay/code/INSTANTpay/willhaben.vip/member"
LOG_DIR="/var/log/willhaben"
CACHE_DIR="/var/cache/willhaben"
MAX_LOG_AGE=30  # days
MAX_METRICS_AGE=90  # days
REDIS_HOST="127.0.0.1"
REDIS_PORT=6379
REDIS_DB=3
HEALTH_CHECK_URL="https://willhaben.vip/health"
HEALTH_CHECK_TIMEOUT=10
INSTANCE_ID=$(hostname)
LOCKFILE="/tmp/willhaben_maintenance.lock"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
LOGFILE="${LOG_DIR}/maintenance_${TIMESTAMP}.log"

# Command line arguments
SKIP_CACHE=0
SKIP_LOGS=0
SKIP_METRICS=0
SKIP_HEALTHCHECK=0

# Process command line arguments
for arg in "$@"; do
  case $arg in
    --skip-cache)
      SKIP_CACHE=1
      shift
      ;;
    --skip-logs)
      SKIP_LOGS=1
      shift
      ;;
    --skip-metrics)
      SKIP_METRICS=1
      shift
      ;;
    --skip-healthcheck)
      SKIP_HEALTHCHECK=1
      shift
      ;;
    --help)
      echo "Usage: $0 [--skip-cache] [--skip-logs] [--skip-metrics] [--skip-healthcheck]"
      exit 0
      ;;
    *)
      # Unknown option
      echo "Unknown option: $arg"
      echo "Usage: $0 [--skip-cache] [--skip-logs] [--skip-metrics] [--skip-healthcheck]"
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
  exit 1
}

# Verify prerequisites
check_prerequisites() {
  log "Checking prerequisites..."
  
  # Check for required commands
  for cmd in php redis-cli curl find gzip; do
    if ! command -v $cmd &> /dev/null; then
      error_exit "$cmd is required but not installed"
    fi
  done
  
  # Check app root exists
  if [ ! -d "$APP_ROOT" ]; then
    error_exit "Application root not found: $APP_ROOT"
  fi
  
  # Check Redis connection
  if ! redis-cli -h $REDIS_HOST -p $REDIS_PORT ping > /dev/null; then
    error_exit "Cannot connect to Redis at $REDIS_HOST:$REDIS_PORT"
  fi
  
  log "Prerequisites check passed"
}

# Lock to prevent concurrent runs
acquire_lock() {
  log "Acquiring lock..."
  if [ -f "$LOCKFILE" ]; then
    PID=$(cat "$LOCKFILE")
    if ps -p $PID > /dev/null; then
      error_exit "Another instance is already running with PID $PID"
    else
      log "Stale lock file found. Previous run may have crashed."
      rm -f "$LOCKFILE"
    fi
  fi
  echo $$ > "$LOCKFILE"
  log "Lock acquired"
}

# Release lock
release_lock() {
  log "Releasing lock..."
  rm -f "$LOCKFILE"
  log "Lock released"
}

# Cache warm-up procedure
warm_cache() {
  if [ $SKIP_CACHE -eq 1 ]; then
    log "Skipping cache warm-up"
    return
  fi
  
  log "Starting cache warm-up procedure..."
  START_TIME=$(date +%s)
  
  # Flush expired cache entries
  log "Flushing expired cache entries..."
  redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB --scan --pattern "cache:*" | while read key; do
    redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB ttl "$key" | grep -q -E '^-1$|^-2$' && redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB del "$key"
  done
  
  # Warm up seller cache - run the PHP script
  log "Warming up seller cache..."
  php -f "$APP_ROOT/bin/warm-cache.php" >> "$LOGFILE" 2>&1 || error_exit "Cache warm-up script failed"
  
  # Verify cache state
  CACHE_KEYS=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB --scan --pattern "cache:seller:*" | wc -l)
  log "Warmed up $CACHE_KEYS seller cache entries"
  
  END_TIME=$(date +%s)
  DURATION=$((END_TIME - START_TIME))
  log "Cache warm-up completed in $DURATION seconds"
}

# Log rotation and cleanup
rotate_logs() {
  if [ $SKIP_LOGS -eq 1 ]; then
    log "Skipping log rotation and cleanup"
    return
  fi
  
  log "Starting log rotation and cleanup..."
  START_TIME=$(date +%s)
  
  # Create archive directory if it doesn't exist
  ARCHIVE_DIR="${LOG_DIR}/archive"
  mkdir -p "$ARCHIVE_DIR"
  
  # Find logs older than MAX_LOG_AGE days
  log "Finding logs older than $MAX_LOG_AGE days..."
  find "$LOG_DIR" -name "*.log" -type f -mtime +$MAX_LOG_AGE | while read logfile; do
    if [ -f "$logfile" ]; then
      BASENAME=$(basename "$logfile")
      ARCHIVE_NAME="${ARCHIVE_DIR}/${BASENAME%.log}_${TIMESTAMP}.log.gz"
      
      log "Archiving $logfile to $ARCHIVE_NAME"
      gzip -c "$logfile" > "$ARCHIVE_NAME" && rm -f "$logfile"
    fi
  done
  
  # Clean up old archived logs (older than 1 year)
  log "Cleaning up archived logs older than 1 year..."
  find "$ARCHIVE_DIR" -name "*.log.gz" -type f -mtime +365 -delete
  
  # Clean up empty log files
  find "$LOG_DIR" -name "*.log" -type f -size 0 -delete
  
  END_TIME=$(date +%s)
  DURATION=$((END_TIME - START_TIME))
  log "Log rotation and cleanup completed in $DURATION seconds"
}

# Metrics database pruning
prune_metrics() {
  if [ $SKIP_METRICS -eq 1 ]; then
    log "Skipping metrics database pruning"
    return
  fi
  
  log "Starting metrics database pruning..."
  START_TIME=$(date +%s)
  
  # Clean up old metrics from Redis
  log "Pruning metrics older than $MAX_METRICS_AGE days..."
  CUTOFF_TIME=$(date -d "-$MAX_METRICS_AGE days" +%s)
  
  # Delete old metrics by pattern
  redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB --scan --pattern "metrics:*" | while read key; do
    # Fetch the timestamp from the key or metadata if available
    TIMESTAMP=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB hget "$key" "timestamp" 2>/dev/null || echo "0")
    
    # If the timestamp is older than the cutoff or not available, delete the key
    if [ "$TIMESTAMP" = "0" ] || [ "$TIMESTAMP" -lt "$CUTOFF_TIME" ]; then
      redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB del "$key"
    fi
  done
  
  # Compact metrics for efficiency
  log "Compacting metrics data..."
  php -f "$APP_ROOT/bin/compact-metrics.php" >> "$LOGFILE" 2>&1 || log "Warning: Metrics compaction script failed"
  
  END_TIME=$(date +%s)
  DURATION=$((END_TIME - START_TIME))
  log "Metrics pruning completed in $DURATION seconds"
}

# Health check execution
run_health_check() {
  if [ $SKIP_HEALTHCHECK -eq 1 ]; then
    log "Skipping health check"
    return
  fi
  
  log "Running health checks..."
  START_TIME=$(date +%s)
  
  # Overall system health check
  log "Checking system health..."
  HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -m $HEALTH_CHECK_TIMEOUT $HEALTH_CHECK_URL)
  
  if [ "$HTTP_STATUS" -ne 200 ]; then
    log "Warning: Health check returned HTTP status $HTTP_STATUS"
  else
    log "Health check passed with HTTP status 200"
  fi
  
  # Check Redis health
  REDIS_INFO=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT info)
  REDIS_MEMORY=$(echo "$REDIS_INFO" | grep used_memory_human | cut -d: -f2 | tr -d '[:space:]')
  REDIS_CLIENTS=$(echo "$REDIS_INFO" | grep connected_clients | cut -d: -f2 | tr -d '[:space:]')
  
  log "Redis memory usage: $REDIS_MEMORY with $REDIS_CLIENTS connected clients"
  
  # Check disk space
  DISK_USAGE=$(df -h "$APP_ROOT" | grep -v "Filesystem" | awk '{print $5}')
  log "Disk usage: $DISK_USAGE"
  
  if [ "${DISK_USAGE%\%}" -gt 90 ]; then
    log "Warning: Disk usage above 90%"
  fi
  
  END_TIME=$(date +%s)
  DURATION=$((END_TIME - START_TIME))
  log "Health checks completed in $DURATION seconds"
}

# Script execution
log "Starting maintenance tasks on ${INSTANCE_ID}"
check_prerequisites
acquire_lock

# Try to run all tasks, capturing errors
ERROR_COUNT=0

# Run cache warm-up
(warm_cache) || ((ERROR_COUNT++))

# Run log rotation
(rotate_logs) || ((ERROR_COUNT++))

# Run metrics pruning
(prune_metrics) || ((ERROR_COUNT++))

# Run health checks
(run_health_check) || ((ERROR_COUNT++))

# Release lock
release_lock

# Provide summary
log "Maintenance tasks completed with $ERROR_COUNT errors"
if [ $ERROR_COUNT -gt 0 ]; then
  log "Check the log file for details: $LOGFILE"
  exit 1
else
  exit 0
fi

