# Synapticore Runners - Security & Guardrails

## Overview

Synapticore uses secure, isolated Docker containers to execute code during the automation workflow. Each language has its own optimized runner image with built-in security features and guardrails.

## Available Runners

| Language   | Image                       | Base Image               | Key Tools                    |
|------------|---------------------------|-------------------------|------------------------------|
| PHP        | `synapticore/runner:php`   | `php:8.3-cli-alpine`    | Composer, PHPUnit, Pint      |
| Node.js    | `synapticore/runner:node`  | `node:20-alpine`        | NPM, TypeScript, Jest        |
| Python     | `synapticore/runner:python`| `python:3.11-alpine`    | Pip, Poetry, Pytest, Black   |
| Go         | `synapticore/runner:go`    | `golang:1.21-alpine`    | Go modules, golangci-lint    |
| Java       | `synapticore/runner:java`  | `temurin:17-jdk-alpine` | Maven, Gradle, SpotBugs      |

## Security Features

### 1. Container-Level Security

- **Non-root User**: All commands run as user `runner` (UID 1000)
- **Read-only Root Filesystem**: System directories are read-only
- **Resource Limits**:
  - Memory: 512MB max
  - CPU: 1 core max
  - PIDs: 100 max
  - File size: 10MB max
- **Capability Restrictions**: All capabilities dropped except CHOWN, SETUID, SETGID
- **No New Privileges**: Prevents privilege escalation

### 2. Command Guardrails

The `CommandGuard` service validates all commands before execution:

#### Blocked Commands
- System modification: `rm -rf /`, `mkfs`, `fdisk`, `mount`
- User management: `sudo`, `useradd`, `passwd`
- Network tools: `nc -l`, `nmap`, `tcpdump`
- Process control: `killall`, `reboot`, `shutdown`
- Dangerous patterns: Fork bombs, command injection

#### Path Restrictions
- Allowed paths: `/workspace`, `/tmp`, `/home/runner`
- Blocked paths: `/etc`, `/sys`, `/proc`, `/dev`, `/root`
- Directory traversal prevention

#### Command Validation
- Commands must match allowed list from `repo_profile.json`
- Rate limiting: 10 executions per minute per command
- Output size limit: 1MB max
- Timeout: 5 minutes default, 10 minutes max

### 3. Network Security

- **Egress Allowlist**: Only package registries allowed
  - npmjs.org, pypi.org, packagist.org
  - maven.apache.org, proxy.golang.org
  - rubygems.org, crates.io, nuget.org
- **No Ingress**: Containers cannot listen on ports
- **Isolated Network**: Containers run in isolated networks

## Usage

### Basic Command Execution

```php
use App\Services\WorkspaceRunner;

$runner = app(WorkspaceRunner::class);

$result = $runner->run(
    workspacePath: '/path/to/workspace',
    command: 'npm test',
    language: 'node',
    timeout: 300,
    repoProfile: $repoProfile,
    allowedPaths: ['/additional/path'],
    ticketId: 'TICKET-123'
);
```

### With Repo Profile

```php
use App\DTO\RepoProfileJson;

$repoProfile = new RepoProfileJson(
    commands: [
        'lint' => 'npm run lint',
        'test' => 'npm test',
        'build' => 'npm run build',
    ],
    languages: ['javascript', 'typescript'],
    frameworks: ['react'],
    // ...
);

// Only commands in the profile will be allowed
$result = $runner->run(
    workspacePath: $workspace,
    command: 'npm test', // ✅ Allowed
    language: 'node',
    repoProfile: $repoProfile
);

$result = $runner->run(
    workspacePath: $workspace,
    command: 'rm -rf node_modules', // ❌ Blocked
    language: 'node',
    repoProfile: $repoProfile
);
```

## Building Runner Images

```bash
# Build all runner images
./scripts/build-runners

# Initialize runner environment
./scripts/init-runners

# Test runner security
php artisan runner:test-security

# Test with Docker Compose
docker compose -f runners/docker-compose.yml up
```

## Security Configuration

The security configuration is stored in `runners/base/security.conf`:

```conf
# Network Egress Allowlist
ALLOWED_HOSTS="
registry.npmjs.org
pypi.org
proxy.golang.org
repo.maven.apache.org
packagist.org
"

# Resource Limits
MAX_MEMORY="512M"
MAX_CPU="1"
MAX_PIDS="100"
MAX_FILE_SIZE="10M"

# Timeout Settings
DEFAULT_TIMEOUT="300"
MAX_TIMEOUT="600"

# Output Limits
MAX_OUTPUT_SIZE="1048576"
```

## Testing Security Features

### Test Command Guard

```bash
php artisan runner:test-security --test-guard
```

This tests:
- Safe command validation
- Dangerous command blocking
- Path restriction enforcement
- Command allowlisting

### Test Dangerous Commands

```bash
php artisan runner:test-security --test-dangerous
```

This verifies blocking of:
- System destruction commands
- Privilege escalation attempts
- Network backdoors
- Fork bombs

### Test Runner Execution

```bash
php artisan runner:test-security --test-runner --language=php
```

This tests actual command execution in containers with:
- Resource limits enforced
- Output size limits
- Timeout enforcement
- Non-root execution

## Error Handling

### CommandBlockedException

Thrown when a command is blocked for security reasons:

```php
try {
    $runner->run($workspace, 'sudo apt-get install', 'node');
} catch (CommandBlockedException $e) {
    // Command was blocked: "Command contains blocked operation: sudo"
}
```

### PathViolationException

Thrown when a command tries to access restricted paths:

```php
try {
    $runner->run($workspace, 'cat /etc/shadow', 'node');
} catch (PathViolationException $e) {
    // Path violation: "Path '/etc/shadow' is not in allowed paths"
}
```

### Rate Limiting

Commands are rate-limited per ticket:

```php
// After 10 executions within 60 seconds
try {
    $runner->run($workspace, 'npm test', 'node', ticketId: 'TICKET-123');
} catch (CommandBlockedException $e) {
    // "Command execution rate limited"
}
```

## Monitoring & Logs

All runner executions are logged with:
- Command executed
- Validation results
- Exit codes
- Execution duration
- Output (sanitized, truncated)

Logs are stored in:
- Local: `storage/app/logs/runs/{run-id}/`
- S3/Spaces: `logs/runs/{run-id}/`

## Security Best Practices

1. **Always use repo profiles**: Define allowed commands in `repo_profile.json`
2. **Minimize allowed paths**: Only allow necessary directories
3. **Set appropriate timeouts**: Use shortest reasonable timeout
4. **Monitor output size**: Large outputs may indicate issues
5. **Review logs regularly**: Check for security violations
6. **Update runner images**: Keep base images and tools updated
7. **Test security features**: Run security tests after changes

## Troubleshooting

### Command Blocked Unexpectedly

Check:
1. Is the command in the repo profile's allowed list?
2. Does the command contain blocked keywords?
3. Are all file paths within allowed directories?

### Container Fails to Start

Check:
1. Is Docker running?
2. Are runner images built? (`./scripts/build-runners`)
3. Is the security config present? (`/etc/runner/security.conf`)

### Output Truncated

The output limit is 1MB. For larger outputs:
1. Write to files instead of stdout
2. Upload files to storage
3. Stream output in chunks

## Future Enhancements

- [ ] Network policy enforcement with Cilium/Calico
- [ ] Seccomp profiles for syscall filtering
- [ ] SELinux/AppArmor mandatory access control
- [ ] Runtime vulnerability scanning
- [ ] Automated security updates
- [ ] Audit logging to SIEM
- [ ] Anomaly detection with ML