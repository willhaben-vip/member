# Monitoring Setup Guide for willhaben.vip Redirect Service

This guide provides comprehensive instructions for setting up and maintaining the monitoring infrastructure for the willhaben.vip redirect service.

## Table of Contents

1. [Overview](#overview)
2. [Monitoring Setup Instructions](#monitoring-setup-instructions)
3. [Environment-Specific Settings](#environment-specific-settings)
4. [Troubleshooting](#troubleshooting)
5. [Alert Response Procedures](#alert-response-procedures)

## Overview

The willhaben.vip redirect service uses the following components for monitoring:

- **Prometheus**: For metrics collection and alerting
- **Grafana**: For visualization and dashboards
- **AlertManager**: For alert routing and notification

Key metrics monitored include:
- Request rate and error rate
- Response time percentiles
- Cache hit ratio
- Memory usage
- Rate limiting statistics
- Validation failures

## Monitoring Setup Instructions

### Prometheus Configuration

1. **Install Prometheus** (if not already available):
   ```bash
   # Example for Ubuntu/Debian
   sudo apt-get update
   sudo apt-get install prometheus
   ```

2. **Configure Prometheus**:
   - Copy the provided `prometheus.yml` file to your Prometheus configuration directory:
   ```bash
   sudo cp config/prometheus/prometheus.yml /etc/prometheus/prometheus.yml
   ```
   
   - Copy the alerts configuration:
   ```bash
   sudo cp config/prometheus/willhaben_alerts.yml /etc/prometheus/
   ```

3. **Configure scraping endpoints**:
   - Ensure the targets in `prometheus.yml` point to the correct service URLs:
   ```yaml
   scrape_configs:
     - job_name: 'willhaben-redirect'
       static_configs:
         - targets: ['localhost:8080']  # Update with your service URL
   ```

4. **Restart Prometheus**:
   ```bash
   sudo systemctl restart prometheus
   ```

### Grafana Dashboard Setup

1. **Install Grafana** (if not already available):
   ```bash
   # Example for Ubuntu/Debian
   sudo apt-get install -y software-properties-common
   sudo add-apt-repository "deb https://packages.grafana.com/oss/deb stable main"
   wget -q -O - https://packages.grafana.com/gpg.key | sudo apt-key add -
   sudo apt-get update
   sudo apt-get install grafana
   ```

2. **Start Grafana**:
   ```bash
   sudo systemctl start grafana-server
   sudo systemctl enable grafana-server
   ```

3. **Configure Prometheus as a data source**:
   - Access Grafana at `http://your-server:3000`
   - Default login: admin/admin
   - Go to Configuration → Data Sources → Add data source
   - Select Prometheus
   - Set URL to `http://localhost:9090` (adjust as needed)
   - Click "Save & Test"

4. **Import Dashboards**:
   - In Grafana, go to Dashboard → Import
   - Either upload the JSON file from `config/grafana/dashboards/willhaben.json`
   - Or import using dashboard ID if you've published it to grafana.com

### Alert Rules Explanation

The provided alert rules in `willhaben_alerts.yml` include:

| Alert Name | Description | Threshold | Severity |
|------------|-------------|-----------|----------|
| HighErrorRate | Triggers when error rate exceeds threshold | >1% for 5m | Warning |
| HighResponseTime | Triggers when response time is too high | 95th percentile >1s for 5m | Warning |
| HighRateLimiting | Triggers when many requests are rate-limited | >10 req/s for 5m | Warning |
| LowCacheHitRate | Triggers when cache efficiency drops | <90% for 15m | Warning |
| HighTraffic | Informational alert for traffic spikes | >100 req/s for 10m | Info |
| MemoryUsageHigh | Triggers when memory consumption is high | >100MB for 15m | Warning |
| ValidationFailuresHigh | Triggers on input validation problems | >5 failures/s for 5m | Warning |

### Required Environment Variables

Set these environment variables to customize monitoring behavior:

```bash
# Prometheus settings
PROMETHEUS_RETENTION_DAYS=15           # How long to store metrics
PROMETHEUS_SCRAPE_INTERVAL=15s         # How often to collect metrics
PROMETHEUS_EVALUATION_INTERVAL=15s     # How often to evaluate rules

# AlertManager settings
ALERTMANAGER_RESOLVE_TIMEOUT=5m        # How long until alert auto-resolves

# Grafana settings
GRAFANA_ADMIN_PASSWORD=securepassword  # Grafana admin password
```

## Environment-Specific Settings

### Development Environment

For development, use these settings:

```bash
# prometheus.yml settings
scrape_interval: 30s  # Less frequent collection
evaluation_interval: 30s
alertmanager_timeout: 10s

# Alert thresholds (higher to reduce noise during development)
- alert: HighErrorRate
  expr: sum(rate(errors_total{type="server"}[5m])) / sum(rate(requests_total[5m])) > 0.05  # 5% instead of 1%
```

### Production Environment

For production, use these stricter settings:

```bash
# prometheus.yml settings
scrape_interval: 15s  # More frequent collection
evaluation_interval: 15s
alertmanager_timeout: 30s

# Alert thresholds (stricter for production)
- alert: HighErrorRate
  expr: sum(rate(errors_total{type="server"}[5m])) / sum(rate(requests_total[5m])) > 0.01  # 1%
```

### Authentication Details

**Prometheus:**
- Basic auth username: `prometheus_user`
- Set password via environment variable: `PROMETHEUS_PASSWORD`
- TLS certificate path: `/etc/prometheus/ssl/prometheus.crt`

**Grafana:**
- Admin username: `admin`
- Set admin password via environment variable: `GRAFANA_ADMIN_PASSWORD`
- LDAP integration available, see `config/grafana/ldap.toml`

### Dashboard URLs

- **Production**: https://grafana.willhaben.vip/d/willhaben/willhaben-vip-redirect-service
- **Staging**: https://grafana-staging.willhaben.vip/d/willhaben/willhaben-vip-redirect-service
- **Development**: http://localhost:3000/d/willhaben/willhaben-vip-redirect-service

## Troubleshooting

### Common Issues

1. **No metrics appearing in Prometheus**
   - Check if the service endpoint is exposing metrics at `/metrics`
   - Verify Prometheus is scraping the correct endpoint
   - Check firewall rules allowing Prometheus to reach the service
   - Solution: `curl http://service-host:port/metrics` should return metrics

2. **Dashboard shows "No data" panels**
   - Verify Prometheus data source is correctly configured in Grafana
   - Check that metrics names in dashboard queries match those exposed by service
   - Solution: Test queries directly in Prometheus UI before using in Grafana

3. **Alerts not firing**
   - Check AlertManager configuration
   - Verify alert rules syntax
   - Check Prometheus logs for evaluation errors
   - Solution: `amtool check-config /etc/alertmanager/config.yml` validates AlertManager config

4. **Too many notifications**
   - Adjust alert thresholds to reduce noise
   - Implement alert grouping in AlertManager
   - Add inhibition rules to prevent alert storms
   - Solution: Edit `willhaben_alerts.yml` to adjust sensitivity

### How to Verify Metrics Collection

1. **Check endpoint availability**:
   ```bash
   curl http://service-host:port/metrics
   ```
   Should return a text dump of metrics in Prometheus format.

2. **Verify scraping in Prometheus**:
   - Go to Prometheus UI: `http://prometheus-host:9090/targets`
   - Check that your targets show "UP" status
   - Check "Last Scrape" time is recent

3. **Test specific metrics**:
   - Go to Prometheus UI: `http://prometheus-host:9090/graph`
   - Enter query: `rate(requests_total[5m])` (or any other metric)
   - Should display a graph if data is being collected

4. **Check Prometheus logs**:
   ```bash
   sudo journalctl -u prometheus | grep -i error
   ```

## Alert Response Procedures

### For HighErrorRate Alert

1. Check application logs for errors:
   ```bash
   tail -n 1000 /var/log/willhaben/error.log | grep -i error
   ```

2. Check recent deployments:
   ```bash
   grep "deploy" /var/log/willhaben/deploy.log | tail -n 20
   ```

3. If recently deployed, consider rollback:
   ```bash
   bin/deploy.sh --rollback
   ```

4. Restart the service if needed:
   ```bash
   bin/maintenance.sh --restart-service
   ```

5. Escalate to development team if unresolved.

### For HighResponseTime Alert

1. Check system resources:
   ```bash
   top -b -n 1
   free -m
   df -h
   ```

2. Check Redis performance:
   ```bash
   redis-cli --stat
   redis-cli info | grep hit_rate
   ```

3. Run cache warming to improve performance:
   ```bash
   bin/maintenance.sh --warm-cache
   ```

4. If caused by high traffic, consider temporary rate limit adjustment:
   ```bash
   bin/maintenance.sh --adjust-rate-limit 20
   ```

5. Monitor response time improvement:
   ```bash
   watch -n 5 'curl -o /dev/null -s -w "%{time_total}\n" http://localhost/health'
   ```

---

For additional support, contact the on-call team:
- Email: oncall@willhaben.vip
- Phone: +1-555-123-4567
- Slack: #willhaben-oncall

