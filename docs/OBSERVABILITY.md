# Synapticore Observability Guide

## Overview

The observability stack provides comprehensive monitoring, logging, and tracing capabilities for the Synapticore application.

## Access Points

| Service | URL | Credentials | Purpose |
|---------|-----|-------------|---------|
| Grafana | http://localhost:3000 | admin/admin | Dashboards & Visualization |
| Prometheus | http://localhost:9090 | - | Metrics Storage |
| Loki | http://localhost:3100 | - | Log Aggregation |
| MinIO | http://localhost:9001 | minioadmin/minioadmin123 | Object Storage UI |
| Mailpit | http://localhost:8030 | - | Email Testing |
| OTEL Collector | http://localhost:8888 | - | Telemetry Collection |

## Grafana Dashboards

### Default Dashboards

1. **Application Overview**
   - Request rate and latency
   - Error rates
   - Active users
   - Database performance

2. **Infrastructure Metrics**
   - Container resource usage
   - Network I/O
   - Disk usage
   - Memory consumption

3. **Business Metrics**
   - User registrations
   - API usage
   - Queue depth
   - Job processing rates

### Creating Custom Dashboards

1. Access Grafana at http://localhost:3000
2. Navigate to Dashboards → New Dashboard
3. Add panels with queries from Prometheus or Loki
4. Save dashboard for persistence

### Useful Prometheus Queries

#### Application Metrics
```promql
# Request rate (per second)
rate(http_requests_total[5m])

# Average response time
histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m]))

# Error rate
rate(http_requests_total{status=~"5.."}[5m])

# Active database connections
mysql_global_status_threads_connected
```

#### Queue Metrics
```promql
# Jobs processed per minute
rate(laravel_queue_jobs_processed_total[1m]) * 60

# Failed jobs
increase(laravel_queue_jobs_failed_total[1h])

# Queue depth
laravel_queue_size{queue="default"}
```

#### Resource Metrics
```promql
# CPU usage per container
rate(container_cpu_usage_seconds_total[5m]) * 100

# Memory usage
container_memory_usage_bytes / container_spec_memory_limit_bytes * 100

# Disk I/O
rate(container_fs_writes_bytes_total[5m])
```

## Logging

### Log Sources

1. **Application Logs**
   - Location: `/var/www/html/storage/logs/`
   - Format: JSON structured logs
   - Channels: single, daily, stack

2. **Container Logs**
   - Collected via Docker API
   - Tagged with container names
   - Includes stdout/stderr

### Viewing Logs in Grafana

1. Navigate to Explore → Loki
2. Use LogQL queries:

```logql
# All Laravel logs
{job="laravel"}

# Error logs only
{job="laravel"} |= "ERROR"

# Specific container logs
{container_name="synapticore_app"}

# Filter by log level
{job="laravel"} | json | level="error"

# Search for specific text
{job="laravel"} |~ "database.*connection"

# Last 1 hour of exceptions
{job="laravel"} |= "Exception" | json | level="error"
```

### Log Levels

Configure in `.env`:
```env
LOG_LEVEL=debug  # debug, info, notice, warning, error, critical, alert, emergency
```

### Structured Logging

Use Laravel's logging with context:
```php
Log::info('User action', [
    'user_id' => $user->id,
    'action' => 'login',
    'ip' => request()->ip(),
    'user_agent' => request()->userAgent(),
]);
```

## Metrics Collection

### Application Metrics

#### Custom Metrics in Laravel
```php
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;

// Register custom metric
$registry = new CollectorRegistry(new Redis());
$counter = $registry->getOrRegisterCounter(
    'app',
    'user_registrations_total',
    'Total user registrations'
);
$counter->inc();
```

### Exposed Metrics Endpoints

- Application: http://localhost:9000/metrics
- OTEL Collector: http://localhost:8888/metrics
- Prometheus: http://localhost:9090/metrics

## Tracing

### OpenTelemetry Configuration

Configure in `.env`:
```env
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
OTEL_SERVICE_NAME=synapticore
OTEL_TRACES_EXPORTER=otlp
```

### Adding Custom Spans
```php
use OpenTelemetry\API\Globals;

$tracer = Globals::tracerProvider()->getTracer('synapticore');
$span = $tracer->spanBuilder('process-payment')->startSpan();

try {
    // Your code here
    $span->setAttribute('payment.amount', $amount);
    $span->setAttribute('payment.currency', 'USD');
} finally {
    $span->end();
}
```

## Alerting

### Setting Up Alerts in Grafana

1. Navigate to Alerting → Alert Rules
2. Create new alert rule
3. Define query and conditions
4. Set notification channels

### Example Alert Rules

#### High Error Rate
```yaml
Alert: High Error Rate
Expression: rate(http_requests_total{status=~"5.."}[5m]) > 0.05
Duration: 5m
Annotations:
  summary: "High error rate detected"
  description: "Error rate is above 5% for the last 5 minutes"
```

#### Database Connection Issues
```yaml
Alert: Database Connection Failed
Expression: mysql_up == 0
Duration: 1m
Annotations:
  summary: "Database connection lost"
  description: "Cannot connect to PostgreSQL database"
```

#### Queue Backlog
```yaml
Alert: Queue Backlog
Expression: laravel_queue_size{queue="default"} > 1000
Duration: 10m
Annotations:
  summary: "Large queue backlog"
  description: "Default queue has more than 1000 pending jobs"
```

### Notification Channels

Configure in Grafana UI:
1. Alerting → Contact Points
2. Add contact point
3. Choose type: Email, Slack, PagerDuty, etc.

## Performance Monitoring

### Key Performance Indicators (KPIs)

1. **Response Time**
   - Target: < 200ms for 95th percentile
   - Alert: > 500ms for 5 minutes

2. **Error Rate**
   - Target: < 1%
   - Alert: > 5% for 5 minutes

3. **Throughput**
   - Monitor requests per second
   - Track API endpoint usage

4. **Database Performance**
   - Query execution time
   - Connection pool usage
   - Slow query log

### Performance Dashboards

#### Application Performance
- Request latency histogram
- Throughput graphs
- Error rate trends
- Cache hit rates

#### Database Performance
- Query execution times
- Connection pool status
- Table sizes and growth
- Index usage statistics

#### Queue Performance
- Job processing rate
- Queue depth over time
- Failed job trends
- Worker utilization

## Debugging Issues

### Using Logs for Debugging

1. **Correlate logs with metrics**
   - Find spike in error metrics
   - Query logs for that time period
   - Identify root cause

2. **Trace requests**
   - Use request ID for correlation
   - Follow request through services
   - Identify bottlenecks

### Common Issues and Solutions

#### High Memory Usage
```logql
{container_name="synapticore_app"} |= "memory"
```
Check for memory leaks, optimize queries, increase limits

#### Slow Queries
```logql
{job="laravel"} |= "slow query"
```
Review query optimization, add indexes

#### Queue Processing Issues
```logql
{job="laravel"} |= "job failed"
```
Check job logs, review error handling

## Best Practices

### Logging Best Practices

1. **Use structured logging**
   - Include context in log messages
   - Use consistent field names
   - Log at appropriate levels

2. **Avoid sensitive data**
   - Never log passwords or tokens
   - Mask credit card numbers
   - Redact personal information

3. **Log actionable information**
   - Include request IDs
   - Add user context
   - Record timing information

### Metrics Best Practices

1. **Use meaningful names**
   - Follow naming conventions
   - Include units in names
   - Use labels for dimensions

2. **Avoid high cardinality**
   - Limit label values
   - Aggregate where possible
   - Use histograms for distributions

3. **Set up SLIs/SLOs**
   - Define service level indicators
   - Monitor against objectives
   - Alert on SLO violations

### Dashboard Best Practices

1. **Organize logically**
   - Group related metrics
   - Use consistent time ranges
   - Include descriptions

2. **Make actionable**
   - Include thresholds
   - Link to runbooks
   - Provide context

3. **Keep updated**
   - Review regularly
   - Remove unused panels
   - Update as system evolves

## Troubleshooting Observability Stack

### Grafana Not Loading
```bash
docker compose -f infra/compose/docker-compose.yml restart grafana
docker compose -f infra/compose/docker-compose.yml logs grafana
```

### Prometheus Not Collecting Metrics
```bash
# Check targets
curl http://localhost:9090/api/v1/targets

# Verify scrape config
docker compose -f infra/compose/docker-compose.yml exec prometheus cat /etc/prometheus/prometheus.yml
```

### Loki Not Receiving Logs
```bash
# Check Promtail status
docker compose -f infra/compose/docker-compose.yml logs promtail

# Verify log paths
docker compose -f infra/compose/docker-compose.yml exec promtail ls -la /var/www/html/storage/logs/
```

### OTEL Collector Issues
```bash
# Check collector status
curl http://localhost:8888/metrics

# Review configuration
docker compose -f infra/compose/docker-compose.yml exec otel-collector cat /etc/otel-collector-config.yaml
```