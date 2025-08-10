# Synapticore Architecture

## Overview

Synapticore is a dockerized Laravel 12 application designed for deployment on DigitalOcean infrastructure. The architecture follows strict MVC patterns with a focus on maintainability, observability, and scalability.

## Technology Stack

### Core Application
- **Framework**: Laravel 12
- **PHP Version**: 8.3+
- **Web Server**: Nginx (reverse proxy)
- **Application Server**: PHP-FPM

### Data Layer
- **Primary Database**: PostgreSQL 16 with pgvector extension
- **Cache & Queue**: Redis 7
- **Object Storage**: DigitalOcean Spaces (S3-compatible, MinIO for local development)

### Observability Stack
- **Metrics**: Prometheus
- **Logs**: Loki + Promtail
- **Tracing**: OpenTelemetry Collector
- **Visualization**: Grafana
- **Email Testing**: Mailpit (development only)

## Architecture Principles

### 1. Strict MVC Separation
- **Controllers**: Handle HTTP requests/responses only
- **Services/Jobs**: Contain all business logic
- **Models**: Data access and relationships
- **Form Requests**: Validate all external payloads

### 2. Service-Oriented Design
```
HTTP Request → Controller → Form Request → Service/Job → Model → Response
```

### 3. Queue-Based Processing
- Background jobs via Redis queues
- Optional Laravel Horizon for queue management
- Async processing for heavy operations

### 4. Storage Strategy
- **Local Files**: Temporary processing only
- **Persistent Storage**: DigitalOcean Spaces
- **Artifacts**: Logs, reports, PDFs stored in Spaces

## Directory Structure

```
synapticore-bot/
├── app/
│   ├── Http/
│   │   ├── Controllers/      # HTTP request handlers
│   │   ├── Requests/          # Form request validators
│   │   └── Middleware/        # HTTP middleware
│   ├── Services/              # Business logic
│   ├── Jobs/                  # Queue jobs
│   └── Models/                # Eloquent models
├── infra/
│   ├── compose/               # Docker configurations
│   ├── nginx/                 # Web server config
│   ├── prometheus/            # Metrics config
│   ├── grafana/               # Dashboard provisioning
│   └── loki/                  # Log aggregation config
├── scripts/                   # Development utilities
├── docs/                      # Documentation
└── tests/
    ├── Feature/               # Integration tests
    └── Unit/                  # Unit tests
```

## Service Communication

### Internal Services
- Services communicate via Docker network `synapticore`
- Service discovery through Docker DNS
- No hardcoded IPs or ports

### External Integration
- Webhooks validated through Form Requests
- API responses cached in Redis
- Rate limiting via Laravel middleware

## Security Considerations

1. **Secrets Management**
   - Environment variables for sensitive data
   - Never commit .env files
   - Rotate keys regularly

2. **Network Security**
   - Internal services not exposed to host
   - Only Nginx, Grafana, and dev tools exposed
   - HTTPS termination at load balancer (production)

3. **Data Protection**
   - Encrypted connections to databases
   - S3 bucket policies for access control
   - Audit logging for sensitive operations

## Deployment Architecture

### Development
- Docker Compose orchestration
- All services running locally
- MinIO simulates DigitalOcean Spaces

### Production (DigitalOcean)
- App Platform or Kubernetes
- Managed PostgreSQL
- Managed Redis
- Spaces for object storage
- Load Balancer with SSL termination

## Scaling Strategy

### Horizontal Scaling
- Stateless application containers
- Redis for session storage
- Shared storage via Spaces

### Vertical Scaling
- Adjust container resources
- Database connection pooling
- Redis memory optimization

## Monitoring & Alerting

### Metrics Collection
- Application metrics via Prometheus
- Custom business metrics
- Infrastructure metrics

### Log Aggregation
- Structured JSON logging
- Centralized in Loki
- Searchable via Grafana

### Alerting Rules
- Response time thresholds
- Error rate monitoring
- Queue depth alerts
- Storage usage warnings

## Backup & Recovery

### Data Backup
- PostgreSQL: Daily automated backups
- Redis: Periodic snapshots
- Spaces: Versioning enabled

### Disaster Recovery
- Infrastructure as Code
- Database point-in-time recovery
- Application rollback strategy