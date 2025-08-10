# Synapticore Security Guide

## Security Principles

1. **Defense in Depth**: Multiple layers of security controls
2. **Least Privilege**: Minimal access rights for users and services
3. **Zero Trust**: Verify everything, trust nothing
4. **Separation of Concerns**: Isolate different components and environments

## Secret Management

### Environment Variables

#### Best Practices
- **Never commit .env files** to version control
- Use `.env.example` as template only
- Rotate secrets regularly
- Use strong, unique passwords

#### Required Secrets
```env
APP_KEY=            # Generate with: php artisan key:generate
DB_PASSWORD=        # Strong password for PostgreSQL
REDIS_PASSWORD=     # Optional but recommended for production
DO_SPACES_KEY=      # DigitalOcean Spaces access key
DO_SPACES_SECRET=   # DigitalOcean Spaces secret key
```

#### Generating Secure Secrets
```bash
# Generate random password (32 characters)
openssl rand -base64 32

# Generate Laravel application key
docker compose -f infra/compose/docker-compose.yml exec app php artisan key:generate

# Generate random token
php -r "echo bin2hex(random_bytes(32));"
```

### Production Secret Management

#### DigitalOcean App Platform
1. Use App-level environment variables
2. Enable encryption at rest
3. Restrict access via IAM roles
4. Audit access logs regularly

#### Kubernetes Secrets
```yaml
apiVersion: v1
kind: Secret
metadata:
  name: synapticore-secrets
type: Opaque
data:
  app-key: <base64-encoded-key>
  db-password: <base64-encoded-password>
```

#### HashiCorp Vault Integration
```php
// config/vault.php
use Vault\Client;

$client = new Client([
    'base_uri' => env('VAULT_ADDR'),
    'token' => env('VAULT_TOKEN'),
]);

$secret = $client->get('/secret/data/synapticore');
```

## Authentication & Authorization

### User Authentication

#### Password Requirements
```php
// app/Http/Requests/RegisterRequest.php
'password' => [
    'required',
    'string',
    'min:12',
    'regex:/[a-z]/',      // lowercase
    'regex:/[A-Z]/',      // uppercase
    'regex:/[0-9]/',      // number
    'regex:/[@$!%*#?&]/', // special character
    'confirmed',
],
```

#### Session Security
```php
// config/session.php
'secure' => env('SESSION_SECURE_COOKIE', true),  // HTTPS only
'http_only' => true,                             // No JS access
'same_site' => 'strict',                         // CSRF protection
'encrypt' => true,                               // Encrypt session data
```

#### Two-Factor Authentication
```bash
composer require laravel/fortify
php artisan fortify:install
```

### API Authentication

#### API Token Security
```php
// Using Laravel Sanctum
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
}

// Token creation with abilities
$token = $user->createToken('api-token', ['read', 'write']);
```

#### Rate Limiting
```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'api' => [
        'throttle:60,1',  // 60 requests per minute
    ],
];

// Custom rate limits
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```

## Data Protection

### Encryption at Rest

#### Database Encryption
```sql
-- Enable PostgreSQL encryption
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Encrypt sensitive columns
UPDATE users SET 
    ssn = pgp_sym_encrypt(ssn, 'encryption_key'),
    credit_card = pgp_sym_encrypt(credit_card, 'encryption_key');
```

#### File Encryption
```php
use Illuminate\Support\Facades\Crypt;

// Encrypt file before storage
$encrypted = Crypt::encrypt(file_get_contents($file));
Storage::disk('spaces')->put('encrypted/'.$filename, $encrypted);

// Decrypt on retrieval
$decrypted = Crypt::decrypt(Storage::disk('spaces')->get('encrypted/'.$filename));
```

### Encryption in Transit

#### HTTPS Configuration
```nginx
# infra/nginx/sites/default.conf
server {
    listen 443 ssl http2;
    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    
    # HSTS
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
}
```

#### Database SSL Connection
```env
DB_SSLMODE=require
DB_SSLCERT=/path/to/client-cert.pem
DB_SSLKEY=/path/to/client-key.pem
DB_SSLROOTCERT=/path/to/ca-cert.pem
```

## Input Validation & Sanitization

### Form Request Validation
```php
// app/Http/Requests/WebhookRequest.php
public function rules()
{
    return [
        'event' => 'required|string|in:created,updated,deleted',
        'data' => 'required|array',
        'data.*.id' => 'required|uuid',
        'data.*.email' => 'required|email:rfc,dns',
        'signature' => 'required|string',
    ];
}

public function authorize()
{
    return $this->hasValidSignature();
}

protected function hasValidSignature()
{
    $payload = $this->getContent();
    $signature = hash_hmac('sha256', $payload, config('services.webhook.secret'));
    return hash_equals($signature, $this->header('X-Signature'));
}
```

### SQL Injection Prevention
```php
// Always use parameter binding
$users = DB::select('SELECT * FROM users WHERE email = ?', [$email]);

// Use Eloquent ORM
$user = User::where('email', $email)->first();

// Avoid raw queries
// BAD: DB::statement("SELECT * FROM users WHERE email = '$email'");
```

### XSS Prevention
```blade
{{-- Blade automatically escapes output --}}
{{ $user->name }}

{{-- For raw HTML (use cautiously) --}}
{!! $sanitized_html !!}

{{-- JavaScript context --}}
<script>
    var userName = @json($user->name);
</script>
```

## Security Headers

### Application Headers
```php
// app/Http/Middleware/SecurityHeaders.php
public function handle($request, Closure $next)
{
    $response = $next($request);
    
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $response->headers->set('X-XSS-Protection', '1; mode=block');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    
    return $response;
}
```

### Content Security Policy
```php
// config/csp.php
return [
    'default-src' => ["'self'"],
    'script-src' => ["'self'", "'unsafe-inline'", 'cdn.jsdelivr.net'],
    'style-src' => ["'self'", "'unsafe-inline'", 'fonts.googleapis.com'],
    'img-src' => ["'self'", 'data:', 'https:'],
    'font-src' => ["'self'", 'fonts.gstatic.com'],
    'connect-src' => ["'self'"],
    'frame-ancestors' => ["'none'"],
];
```

## File Upload Security

### Validation Rules
```php
// app/Http/Requests/FileUploadRequest.php
public function rules()
{
    return [
        'file' => [
            'required',
            'file',
            'mimes:pdf,doc,docx,jpg,png',
            'max:10240', // 10MB
            'mimetypes:application/pdf,application/msword,image/jpeg,image/png',
        ],
    ];
}
```

### Secure File Handling
```php
// app/Services/FileUploadService.php
public function store(UploadedFile $file)
{
    // Generate unique filename
    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
    
    // Scan for viruses (using ClamAV)
    if (!$this->scanForVirus($file)) {
        throw new SecurityException('File contains malware');
    }
    
    // Store in non-public directory
    $path = Storage::disk('spaces')->putFileAs(
        'uploads/' . date('Y/m/d'),
        $file,
        $filename,
        'private'
    );
    
    return $path;
}
```

## CORS Configuration

```php
// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

## Audit Logging

### Activity Logging
```php
// app/Models/AuditLog.php
class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];
}

// app/Traits/Auditable.php
trait Auditable
{
    public static function bootAuditable()
    {
        static::created(function ($model) {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'created',
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'new_values' => $model->toJson(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });
    }
}
```

### Security Event Monitoring
```php
// app/Listeners/LogSecurityEvent.php
public function handle($event)
{
    Log::channel('security')->warning('Security event', [
        'event' => get_class($event),
        'user' => auth()->id(),
        'ip' => request()->ip(),
        'data' => $event->toArray(),
    ]);
    
    // Alert on critical events
    if ($event instanceof CriticalSecurityEvent) {
        Mail::to(config('security.alert_email'))
            ->send(new SecurityAlert($event));
    }
}
```

## Container Security

### Docker Security Best Practices

#### Dockerfile Security
```dockerfile
# Run as non-root user
RUN groupadd -g 1000 www && useradd -u 1000 -ms /bin/bash -g www www
USER www

# Don't expose unnecessary ports
EXPOSE 9000

# Use specific versions
FROM php:8.3-fpm-alpine

# Remove unnecessary packages
RUN apk del build-dependencies
```

#### Docker Compose Security
```yaml
services:
  app:
    security_opt:
      - no-new-privileges:true
    read_only: true
    tmpfs:
      - /tmp
    cap_drop:
      - ALL
    cap_add:
      - CHOWN
      - SETUID
      - SETGID
```

### Container Scanning
```bash
# Scan for vulnerabilities
docker scan synapticore_app

# Use Trivy for comprehensive scanning
trivy image synapticore_app
```

## Security Testing

### Automated Security Testing
```bash
# OWASP ZAP scan
docker run -t owasp/zap2docker-stable zap-baseline.py -t http://localhost

# PHP Security Checker
composer require --dev enlightn/security-checker
vendor/bin/security-checker security:check composer.lock
```

### Manual Security Checklist

#### Pre-Deployment
- [ ] All secrets rotated
- [ ] HTTPS enabled
- [ ] Security headers configured
- [ ] Rate limiting enabled
- [ ] Input validation comprehensive
- [ ] File uploads restricted
- [ ] CORS properly configured
- [ ] Audit logging enabled

#### Post-Deployment
- [ ] SSL certificate valid
- [ ] No exposed debug information
- [ ] Error pages don't leak information
- [ ] Admin interfaces protected
- [ ] Monitoring alerts configured
- [ ] Backup encryption verified
- [ ] Access logs reviewed
- [ ] Security scan completed

## Incident Response

### Response Plan

1. **Detection**
   - Monitor security logs
   - Set up alerts for anomalies
   - Regular security scans

2. **Containment**
   - Isolate affected systems
   - Block malicious IPs
   - Disable compromised accounts

3. **Eradication**
   - Remove malware/backdoors
   - Patch vulnerabilities
   - Update security rules

4. **Recovery**
   - Restore from clean backups
   - Verify system integrity
   - Monitor for reinfection

5. **Lessons Learned**
   - Document incident
   - Update security procedures
   - Train team on findings

### Emergency Contacts

```yaml
Security Team:
  - Email: security@synapticore.com
  - Phone: +41-XX-XXX-XXXX
  - On-call: PagerDuty

External:
  - DigitalOcean Support: support@digitalocean.com
  - Law Enforcement: Local cybercrime unit
```

## Compliance

### GDPR Compliance
- User consent mechanisms
- Data portability features
- Right to erasure implementation
- Privacy policy compliance
- Data processing agreements

### PCI DSS (if handling payments)
- Network segmentation
- Encryption requirements
- Access control measures
- Regular security testing
- Incident response procedures

### Security Certifications
- ISO 27001 alignment
- SOC 2 compliance
- Regular penetration testing
- Security awareness training