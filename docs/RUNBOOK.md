# Synapticore Runbook

## Quick Start

### Prerequisites
- Docker & Docker Compose installed
- PHP 8.3+ (for local development)
- Composer 2.x
- Node.js 20.x & npm

### Initial Setup

1. **Clone the repository**
```bash
git clone <repository-url>
cd synapticore-bot
```

2. **Start the development environment**
```bash
./scripts/dev-up
```

This script will:
- Create .env file from .env.example
- Start all Docker containers
- Run database migrations
- Install dependencies
- Build frontend assets

3. **Access the application**
- Application: http://localhost
- Mailpit: http://localhost:8030
- Grafana: http://localhost:3000 (admin/admin)
- MinIO: http://localhost:9001 (minioadmin/minioadmin123)

## Daily Operations

### Starting Services
```bash
./scripts/dev-up
```

### Stopping Services
```bash
./scripts/dev-down
```

### Viewing Logs
```bash
# All services
./scripts/dev-logs

# Specific service
./scripts/dev-logs app
./scripts/dev-logs nginx
./scripts/dev-logs postgres
```

### Running Artisan Commands
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan <command>

# Examples:
docker compose -f infra/compose/docker-compose.yml exec app php artisan migrate
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:work
docker compose -f infra/compose/docker-compose.yml exec app php artisan cache:clear
```

## Database Management

### Running Migrations
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan migrate
```

### Rolling Back Migrations
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan migrate:rollback
```

### Seeding Database
```bash
./scripts/seed
```

### Database Backup
```bash
docker compose -f infra/compose/docker-compose.yml exec postgres pg_dump -U synapticore synapticore > backup.sql
```

### Database Restore
```bash
docker compose -f infra/compose/docker-compose.yml exec -T postgres psql -U synapticore synapticore < backup.sql
```

## Queue Management

### Starting Queue Workers
```bash
# Single worker
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:work

# Multiple workers
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:work --daemon --tries=3 --timeout=90

# Specific queue
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:work redis --queue=high,default
```

### Monitoring Queue
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:monitor
```

### Failed Jobs
```bash
# List failed jobs
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:failed

# Retry failed job
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:retry <job-id>

# Retry all failed jobs
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:retry all

# Clear failed jobs
docker compose -f infra/compose/docker-compose.yml exec app php artisan queue:flush
```

## Testing

### Running Tests
```bash
# All tests
./scripts/test

# Specific test file
./scripts/test tests/Feature/ExampleTest.php

# With coverage
docker compose -f infra/compose/docker-compose.yml exec app php artisan test --coverage
```

### Code Quality

#### Linting
```bash
./scripts/lint
```

#### Fixing Code Style
```bash
./scripts/fix
```

#### Formatting Code
```bash
./scripts/format
```

## Cache Management

### Clear All Caches
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan optimize:clear
```

### Clear Specific Caches
```bash
# Configuration cache
docker compose -f infra/compose/docker-compose.yml exec app php artisan config:clear

# Route cache
docker compose -f infra/compose/docker-compose.yml exec app php artisan route:clear

# View cache
docker compose -f infra/compose/docker-compose.yml exec app php artisan view:clear

# Application cache
docker compose -f infra/compose/docker-compose.yml exec app php artisan cache:clear
```

### Rebuild Caches
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan optimize
```

## Storage Management

### Create Storage Link
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan storage:link
```

### MinIO/Spaces Operations
Access MinIO console at http://localhost:9001
- Username: minioadmin
- Password: minioadmin123

## Troubleshooting

### Container Issues

#### Check container status
```bash
docker compose -f infra/compose/docker-compose.yml ps
```

#### Restart specific service
```bash
docker compose -f infra/compose/docker-compose.yml restart app
```

#### Rebuild containers
```bash
docker compose -f infra/compose/docker-compose.yml build --no-cache
docker compose -f infra/compose/docker-compose.yml up -d
```

### Database Connection Issues

1. Check if PostgreSQL is running:
```bash
docker compose -f infra/compose/docker-compose.yml ps postgres
```

2. Test connection:
```bash
docker compose -f infra/compose/docker-compose.yml exec postgres psql -U synapticore -d synapticore -c "SELECT 1"
```

3. Check logs:
```bash
docker compose -f infra/compose/docker-compose.yml logs postgres
```

### Redis Connection Issues

1. Check if Redis is running:
```bash
docker compose -f infra/compose/docker-compose.yml ps redis
```

2. Test connection:
```bash
docker compose -f infra/compose/docker-compose.yml exec redis redis-cli ping
```

### Permission Issues

Fix storage permissions:
```bash
docker compose -f infra/compose/docker-compose.yml exec app chmod -R 775 storage bootstrap/cache
docker compose -f infra/compose/docker-compose.yml exec app chown -R www-data:www-data storage bootstrap/cache
```

### Memory Issues

Check memory usage:
```bash
docker stats
```

Increase PHP memory limit:
Edit `infra/compose/php.ini` and adjust `memory_limit`

## Deployment

### Production Deployment Checklist

1. **Environment Configuration**
   - [ ] Update .env for production
   - [ ] Set APP_ENV=production
   - [ ] Set APP_DEBUG=false
   - [ ] Configure real DigitalOcean credentials

2. **Database**
   - [ ] Run migrations
   - [ ] Verify pgvector extension
   - [ ] Set up backups

3. **Cache & Optimization**
   - [ ] Run `php artisan optimize`
   - [ ] Enable OPcache
   - [ ] Configure Redis persistence

4. **Security**
   - [ ] Generate new APP_KEY
   - [ ] Configure HTTPS
   - [ ] Set up firewall rules
   - [ ] Enable rate limiting

5. **Monitoring**
   - [ ] Configure external monitoring
   - [ ] Set up alerts
   - [ ] Enable error tracking

### Rollback Procedure

1. **Application Rollback**
```bash
git checkout <previous-version>
composer install --no-dev
php artisan migrate:rollback
php artisan optimize
```

2. **Database Rollback**
- Restore from backup
- Run specific rollback migrations

## Maintenance Mode

### Enable Maintenance Mode
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan down
```

### Disable Maintenance Mode
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan up
```

### Allow Specific IPs During Maintenance
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan down --allow=127.0.0.1 --allow=192.168.1.0/24
```

## Performance Optimization

### Enable Query Caching
```bash
docker compose -f infra/compose/docker-compose.yml exec app php artisan config:cache
docker compose -f infra/compose/docker-compose.yml exec app php artisan route:cache
```

### Optimize Autoloader
```bash
docker compose -f infra/compose/docker-compose.yml exec app composer dump-autoload -o
```

### Monitor Slow Queries
Check PostgreSQL slow query log in Grafana dashboards

## Backup Procedures

### Full Backup
```bash
# Database
docker compose -f infra/compose/docker-compose.yml exec postgres pg_dump -U synapticore synapticore > backup_$(date +%Y%m%d).sql

# Redis
docker compose -f infra/compose/docker-compose.yml exec redis redis-cli BGSAVE

# Application files
tar -czf app_backup_$(date +%Y%m%d).tar.gz --exclude=node_modules --exclude=vendor .
```

### Restore from Backup
```bash
# Database
docker compose -f infra/compose/docker-compose.yml exec -T postgres psql -U synapticore synapticore < backup_20240101.sql

# Application files
tar -xzf app_backup_20240101.tar.gz
```