# Operator Guide - willhaben.vip Redirect Service

This guide provides essential information for operators managing the willhaben.vip redirect service in production. It covers basic setup, common operational tasks, troubleshooting, and monitoring procedures.

## 1. Quick Start

### First-Time Setup

1. **Verify System Requirements**
   ```bash
   # Run the production verification script to check system readiness
   bin/verify_production.sh --verbose
   ```
   Address any failures or warnings before proceeding.

2. **Set Up Environment Variables**
   Create a `.env` file in the application root with the following settings:
   ```
   REDIS_HOST=127.0.0.1
   REDIS_PORT=6379
   REDIS_DB=3
   BACKUP_PASSWORD=your_secure_backup_password
   LOG_LEVEL=info
   ENVIRONMENT=production
   ```

3. **Initialize Directories**
   Ensure the following directories are properly set up:
   ```bash
   sudo mkdir -p /var/backups/willhaben/{daily,weekly,monthly}
   sudo mkdir -p /var/log/willhaben
   sudo chown -R your_service_user:your_service_group /var/backups/willhaben /var/log/willhaben
   sudo chmod 755 /var/backups/willhaben /var/log/willhaben
   ```

4. **Schedule Maintenance Tasks**
   Add the following to your crontab:
   ```
   # Daily backup at 1 AM
   0 1 * * * /path/to/willhaben.vip/member/bin/backup.sh --type=full >> /var/log/willhaben/backup.log 2>&1

   # Hourly maintenance
   0 * * * * /path/to/willhaben.vip/member/bin/maintenance.sh --all >> /var/log/willhaben/maintenance.log 2>&1
   ```

### Basic Operational Commands

#### Backup Operations
```bash
# Full backup
bin/backup.sh --type=full

# Configuration only backup
bin/backup.sh --type=config

# Seller data backup
bin/backup.sh --type=data

# Skip verification (faster but less safe)
bin/backup.sh --type=full --skip-verify

# Backup without compression
bin/backup.sh --type=full --no-compress
```

#### Deployment Operations
```bash
# Deploy new version with validation
bin/deploy.sh --branch=master

# Dry run deployment (validation only)
bin/deploy.sh --dry-run --branch=master

# Rollback to previous version
bin/deploy.sh --rollback

# Deploy with security check
bin/deploy.sh --branch=master --security-check
```

#### Maintenance Operations
```bash
# Run all maintenance tasks
bin/maintenance.sh --all

# Warm cache only
bin/maintenance.sh --warm-cache

# Rotate logs only
bin/maintenance.sh --rotate-logs

# Prune metrics data
bin/maintenance.sh --prune-metrics

# Run health checks
bin/maintenance.sh --health-check
```

#### Testing Operations
```bash
# Run all operational tests
bin/test_ops.sh

# Set test environment
ENVIRONMENT=test bin/test_ops.sh
```

### Common Maintenance Tasks

1. **Database Maintenance**
   - Redis persistence check: `redis-cli BGSAVE`
   - Check Redis memory: `redis-cli INFO memory`

2. **Log Management**
   - Manual log rotation: `bin/maintenance.sh --rotate-logs`
   - Archive old logs: `tar -czf logs-archive-$(date +%F).tar.gz /var/log/willhaben/archive/`

3. **Backup Verification**
   ```bash
   # List available backups
   ls -la /var/backups/willhaben/{daily,weekly,monthly}
   
   # Test restore (to temporary location)
   bin/backup.sh --restore=/tmp/restore_test --backup-file=/var/backups/willhaben/daily/willhaben-backup-full-YYYY-MM-DD_HH-MM-SS_seller_data.tar.gz
   ```

4. **Configuration Updates**
   ```bash
   # Validate config change
   bin/deploy.sh --validate-config --config=new_config.json
   
   # Apply configuration change
   bin/deploy.sh --update-config --config=new_config.json
   ```

## 2. Troubleshooting

### Common Issues and Solutions

#### Service Not Responding
1. Check if Redis is running: `redis-cli PING`
2. Verify log files for errors: `tail -n 100 /var/log/willhaben/error.log`
3. Check system resources: `top`, `free -m`, `df -h`
4. Restart the service: `bin/maintenance.sh --restart-service`

#### Backup Failures
1. Check disk space: `df -h /var/backups/willhaben`
2. Verify backup log: `tail -n 100 /var/log/willhaben/backup_*.log`
3. Test directory permissions: `touch /var/backups/willhaben/test_file && rm /var/backups/willhaben/test_file`
4. Run manual backup with verbose output: `bin/backup.sh --type=full --verbose`

#### High Response Time
1. Check Redis performance: `redis-cli --stat`
2. Verify cache hit ratio: `redis-cli INFO stats | grep hit_rate`
3. Check system load: `uptime`
4. Run cache warming: `bin/maintenance.sh --warm-cache`
5. Check for high traffic patterns in logs: `grep -i "request" /var/log/willhaben/access.log | wc -l`

#### Deployment Issues
1. Check deployment logs: `tail -n 100 /var/log/willhaben/deploy.log`
2. Verify config validation: `bin/deploy.sh --validate-config`
3. Run security check: `bin/deploy.sh --security-check`
4. If needed, rollback: `bin/deploy.sh --rollback`

### Log File Locations

| Log Type | Location | Description |
|----------|----------|-------------|
| Application Logs | `/var/log/willhaben/app.log` | Main application logs |
| Error Logs | `/var/log/willhaben/error.log` | Error-level application messages |
| Access Logs | `/var/log/willhaben/access.log` | HTTP access logs |
| Backup Logs | `/var/log/willhaben/backup_*.log` | Logs from backup operations |
| Maintenance Logs | `/var/log/willhaben/maintenance.log` | Maintenance operation logs |
| Deployment Logs | `/var/log/willhaben/deploy.log` | Deployment operation logs |

**Log Format:**
```
[YYYY-MM-DD HH:MM:SS] [LEVEL] [COMPONENT] Message
```

**Common Log Search Patterns:**
```bash
# Find error messages
grep -i "error\|exception\|fail" /var/log/willhaben/app.log

# Find slow requests (taking > 500ms)
grep "request_time=[5-9][0-9][0-9]\|request_time=[0-9]\{4,\}" /var/log/willhaben/access.log

# Check rate limiting
grep "rate_limit" /var/log/willhaben/access.log
```

### Health Check Procedures

1. **Basic Health Check**
   ```bash
   # Check the service health endpoint
   curl -s http://localhost/health
   
   # Run internal health check
   bin/maintenance.sh --health-check
   ```

2. **Component-Level Checks**
   ```bash
   # Redis connection
   redis-cli PING
   
   # Disk space
   df -h
   
   # System load
   uptime
   
   # Service process
   ps aux | grep willhaben
   ```

3. **Comprehensive Health Verification**
   ```bash
   bin/verify_production.sh
   ```

4. **Recovering from Unhealthy State**
   - Redis issues: `service redis restart`
   - Application issues: `bin/maintenance.sh --restart-service`
   - Disk space issues: `bin/maintenance.sh --clean-temp-files`
   - If all else fails: `bin/deploy.sh --rollback`

## 3. Monitoring

### Metrics Overview

The following key metrics should be monitored for the willhaben.vip redirect service:

| Metric | Description | Normal Range | Alert Threshold |
|--------|-------------|--------------|----------------|
| Response Time | Average response time in ms | 50-150ms | >500ms |
| Error Rate | Percentage of 5xx responses | <0.1% | >1% |
| Cache Hit Ratio | Redis cache hit percentage | >80% | <50% |
| Disk Usage | Percentage of disk space used | <70% | >85% |
| CPU Usage | System CPU utilization | <40% | >80% |
| Memory Usage | System memory utilization | <60% | >85% |
| Active Connections | Number of concurrent connections | <1000 | >5000 |
| Seller Data Size | Size of seller data in Redis | <500MB | >1GB |
| Redis Memory | Redis memory utilization | <70% | >85% |

#### Accessing Metrics

**Prometheus Endpoint:**
```
http://localhost:9090/metrics
```

**Redis Metrics:**
```bash
redis-cli INFO all
```

**System Metrics Dashboard:**
If Grafana is configured, access the dashboard at:
```
http://monitoring-server:3000/d/willhaben/
```

### Alert Descriptions

| Alert | Description | Severity | Response Procedure |
|-------|-------------|----------|-------------------|
| HighErrorRate | Error rate exceeds 1% for 5 minutes | Critical | See Error Rate Response below |
| SlowResponses | Response time >500ms for 5 minutes | Warning | See Slow Response Procedure below |
| DiskSpaceLow | Disk space usage >85% | Warning | Clean logs and temp files |
| DiskSpaceCritical | Disk space usage >95% | Critical | Emergency cleanup required |
| BackupFailure | Backup job failed | Critical | Check backup logs, verify disk space |
| RedisMemoryHigh | Redis memory usage >85% | Warning | Consider clearing less critical cached data |
| ServiceDown | Health check failing for >1 minute | Critical | Immediate investigation required |

### Response Procedures

#### Error Rate Response
1. Check error logs: `tail -n 100 /var/log/willhaben/error.log`
2. Verify Redis status: `redis-cli PING`
3. Check for recent deployments: `grep "deploy" /var/log/willhaben/deploy.log | tail -n 10`
4. If recent deployment is the cause: `bin/deploy.sh --rollback`
5. Otherwise restart service: `bin/maintenance.sh --restart-service`
6. If issues persist, escalate to development team

#### Slow Response Procedure
1. Check system load: `uptime`
2. Verify Redis performance: `redis-cli --stat`
3. Look for high traffic patterns: `grep -i "request" /var/log/willhaben/access.log | wc -l`
4. Run cache warming: `bin/maintenance.sh --warm-cache`
5. If caused by Redis, consider restarting: `service redis restart`
6. Monitor improvement: `watch -n 1 'curl -s -o /dev/null -w "%{time_total}" http://localhost/health'`

#### Backup Failure Response
1. Check backup logs: `tail -n 100 /var/log/willhaben/backup_*.log`
2. Verify disk space: `df -h /var/backups/willhaben`
3. Test backup directory access: `touch /var/backups/willhaben/test_file && rm /var/backups/willhaben/test_file`
4. Run manual backup with verbose output: `bin/backup.sh --type=full --verbose`
5. If issue persists, check Redis dump file: `ls -la $(redis-cli CONFIG GET dir | grep -v dir | tr -d '\r')/$(redis-cli CONFIG GET dbfilename | grep -v dbfilename | tr -d '\r')`

#### Service Down Response
1. Check if process is running: `ps aux | grep willhaben`
2. Check logs for fatal errors: `tail -n 100 /var/log/willhaben/error.log`
3. Verify Redis connection: `redis-cli PING`
4. Attempt restart: `bin/maintenance.sh --restart-service`
5. If restart fails, check disk space and system resources
6. Consider rollback if recent deployment: `bin/deploy.sh --rollback`
7. If all else fails, escalate to on-call developer

## Additional Resources

- [Production Readiness Checklist](./PRODUCTION_CHECKLIST.md)
- [Backup Script Documentation](./docs/backup.md)
- [Maintenance Procedures](./docs/maintenance.md)
- [Deployment Guide](./docs/deployment.md)
- [Redis Documentation](https://redis.io/documentation)

---

For emergency support, contact the on-call team:
- Email: oncall@willhaben.vip
- Phone: +1-555-123-4567
- Slack: #willhaben-oncall

