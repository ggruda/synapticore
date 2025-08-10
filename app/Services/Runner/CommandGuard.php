<?php

declare(strict_types=1);

namespace App\Services\Runner;

use App\DTO\RepoProfileJson;
use App\Exceptions\CommandBlockedException;
use App\Exceptions\PathViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Command guard service for enforcing security policies on runner commands.
 */
class CommandGuard
{
    /**
     * Dangerous commands that are always blocked.
     */
    private const BLOCKED_COMMANDS = [
        'rm -rf /',
        'rm -rf /*',
        'dd if=/dev/zero',
        'dd if=/dev/random',
        'mkfs',
        'fdisk',
        'mount',
        'umount',
        'chroot',
        'sudo',
        'su -',
        'passwd',
        'useradd',
        'userdel',
        'groupadd',
        'groupdel',
        'systemctl',
        'service',
        'killall',
        'pkill',
        'reboot',
        'shutdown',
        'poweroff',
        'halt',
        'iptables',
        'nc -l',
        'netcat -l',
        'nmap',
        'tcpdump',
        'wget --post-file',
        'curl -d @',
        'curl --upload-file',
        'ssh',
        'scp',
        'rsync --daemon',
        'chmod 777',
        'chmod -R 777',
        'chown -R',
        ':(){:|:&};:',  // Fork bomb
        'eval',
        'exec',
        '$()',
        '`',
        '>',
        '>>',
        '|',
        '&',
        '&&',
        ';',
        '||',
    ];

    /**
     * Dangerous patterns to check.
     */
    private const DANGEROUS_PATTERNS = [
        '/\.\.\//',           // Directory traversal
        '/\/etc\/shadow/',    // Password file access
        '/\/etc\/passwd/',    // User file access
        '/\/proc\//',         // Process information
        '/\/sys\//',          // System information
        '/\/dev\//',          // Device access
        '/\$\(.*\)/',        // Command substitution
        '/`.*`/',            // Backtick execution
        '/;\s*rm/',          // Chained rm command
        '/\|\s*sh/',         // Pipe to shell
        '/\|\s*bash/',       // Pipe to bash
        '/>\s*\/dev\//',     // Redirect to device
        '/2>&1/',            // Stderr redirect (can hide errors)
    ];

    /**
     * Allowed paths for file operations.
     */
    private const ALLOWED_PATHS = [
        '/workspace',
        '/tmp',
        '/home/runner',
    ];

    /**
     * Maximum command length.
     */
    private const MAX_COMMAND_LENGTH = 4096;

    /**
     * Maximum number of arguments.
     */
    private const MAX_ARGUMENTS = 100;

    /**
     * Check if a command is safe to execute.
     *
     * @throws CommandBlockedException
     * @throws PathViolationException
     */
    public function validateCommand(
        string $command,
        ?RepoProfileJson $repoProfile = null,
        array $allowedPaths = []
    ): array {
        $violations = [];

        // Check command length
        if (strlen($command) > self::MAX_COMMAND_LENGTH) {
            throw new CommandBlockedException(
                'Command exceeds maximum length of '.self::MAX_COMMAND_LENGTH.' characters'
            );
        }

        // Normalize command for checking
        $normalizedCommand = $this->normalizeCommand($command);

        // Check against blocked commands
        foreach (self::BLOCKED_COMMANDS as $blocked) {
            if (str_contains($normalizedCommand, $blocked)) {
                throw new CommandBlockedException(
                    "Command contains blocked operation: {$blocked}"
                );
            }
        }

        // Check against dangerous patterns
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalizedCommand)) {
                $violations[] = "Command matches dangerous pattern: {$pattern}";
            }
        }

        // If repo profile exists, check against allowed commands
        if ($repoProfile && ! empty($repoProfile->commands)) {
            $isAllowedCommand = false;
            $baseCommand = $this->extractBaseCommand($command);

            foreach ($repoProfile->commands as $allowedCommand) {
                if ($this->commandMatches($baseCommand, $allowedCommand)) {
                    $isAllowedCommand = true;
                    break;
                }
            }

            if (! $isAllowedCommand) {
                $violations[] = "Command '{$baseCommand}' not in allowed list from repo profile";
            }
        }

        // Check file paths in command
        $paths = $this->extractPaths($command);
        $effectiveAllowedPaths = array_merge(self::ALLOWED_PATHS, $allowedPaths);

        foreach ($paths as $path) {
            if (! $this->isPathAllowed($path, $effectiveAllowedPaths)) {
                throw new PathViolationException(
                    "Path '{$path}' is not in allowed paths: ".implode(', ', $effectiveAllowedPaths)
                );
            }
        }

        // Check argument count
        $argCount = count(explode(' ', $command));
        if ($argCount > self::MAX_ARGUMENTS) {
            $violations[] = "Command has too many arguments ({$argCount} > ".self::MAX_ARGUMENTS.')';
        }

        // Log violations but allow execution if not critical
        if (! empty($violations)) {
            Log::warning('Command guard violations detected', [
                'command' => $command,
                'violations' => $violations,
            ]);
        }

        return [
            'command' => $command,
            'normalized' => $normalizedCommand,
            'violations' => $violations,
            'safe' => empty($violations),
        ];
    }

    /**
     * Normalize command for checking.
     */
    private function normalizeCommand(string $command): string
    {
        // Remove extra whitespace
        $command = preg_replace('/\s+/', ' ', trim($command));

        // Convert to lowercase for checking
        $command = strtolower($command);

        return $command;
    }

    /**
     * Extract base command from full command.
     */
    private function extractBaseCommand(string $command): string
    {
        // Get first word of command
        $parts = explode(' ', trim($command));

        // Handle npm/yarn/composer scripts
        if (count($parts) >= 2) {
            $tool = $parts[0];
            $subcommand = $parts[1];

            if (in_array($tool, ['npm', 'yarn', 'pnpm']) && in_array($subcommand, ['run', 'test'])) {
                return count($parts) >= 3 ? "{$tool} {$subcommand} {$parts[2]}" : "{$tool} {$subcommand}";
            }

            if ($tool === 'composer' && in_array($subcommand, ['run-script', 'test'])) {
                return count($parts) >= 3 ? "{$tool} {$subcommand} {$parts[2]}" : "{$tool} {$subcommand}";
            }
        }

        return $parts[0];
    }

    /**
     * Check if a command matches an allowed command pattern.
     */
    private function commandMatches(string $command, string $pattern): bool
    {
        // Direct match
        if ($command === $pattern) {
            return true;
        }

        // Check if pattern is a prefix
        if (str_starts_with($command, $pattern.' ')) {
            return true;
        }

        // Check wildcard patterns
        if (str_contains($pattern, '*')) {
            $regex = '/^'.str_replace('*', '.*', preg_quote($pattern, '/')).'$/';

            return preg_match($regex, $command) === 1;
        }

        return false;
    }

    /**
     * Extract file paths from command.
     */
    private function extractPaths(string $command): array
    {
        $paths = [];

        // Look for absolute paths (starting with /)
        preg_match_all('/\/[^\s]+/', $command, $matches);
        if (! empty($matches[0])) {
            // Filter out command flags that start with /
            foreach ($matches[0] as $match) {
                // Skip if it's likely a regex or option
                if (! preg_match('/^\/[a-z]$/', $match)) {
                    $paths[] = $match;
                }
            }
        }

        // Look for relative paths with ../
        preg_match_all('/\.\.\/[^\s]*/', $command, $matches);
        if (! empty($matches[0])) {
            $paths = array_merge($paths, $matches[0]);
        }

        // Look for vendor/bin paths
        preg_match_all('/(?:\.\/)?vendor\/[^\s]+/', $command, $matches);
        if (! empty($matches[0])) {
            $paths = array_merge($paths, $matches[0]);
        }

        return array_unique($paths);
    }

    /**
     * Check if a path is allowed.
     */
    private function isPathAllowed(string $path, array $allowedPaths): bool
    {
        // Skip validation for command names without slashes (e.g., 'ls', 'composer')
        if (! str_contains($path, '/')) {
            return true;
        }

        // Check for dangerous path traversal
        if (str_contains($path, '..')) {
            return false;
        }

        // Check if it's a relative path in vendor/bin (common for PHP tools)
        if (str_starts_with($path, 'vendor/') || str_starts_with($path, './vendor/')) {
            return true;
        }

        // Normalize path if it exists
        $realPath = @realpath($path);
        if ($realPath !== false) {
            $path = $realPath;
        }

        // Check if path is within allowed paths
        foreach ($allowedPaths as $allowedPath) {
            if (str_starts_with($path, $allowedPath)) {
                return true;
            }
        }

        // Check against restricted system paths
        $restrictedPaths = ['/etc', '/sys', '/proc', '/dev', '/boot', '/root'];
        foreach ($restrictedPaths as $restricted) {
            if (str_starts_with($path, $restricted)) {
                return false;
            }
        }

        // Allow relative paths that don't escape workspace
        if (! str_starts_with($path, '/')) {
            return true;
        }

        return false;
    }

    /**
     * Sanitize command output.
     */
    public function sanitizeOutput(string $output, int $maxSize = 1048576): array
    {
        $originalSize = strlen($output);
        $truncated = false;

        // Truncate if too large
        if ($originalSize > $maxSize) {
            $output = substr($output, 0, $maxSize);
            $truncated = true;
        }

        // Remove potential secrets/tokens (basic patterns)
        $patterns = [
            '/api[_-]?key\s*[:=]\s*["\']?[\w\-]+["\']?/i',
            '/token\s*[:=]\s*["\']?[\w\-]+["\']?/i',
            '/password\s*[:=]\s*["\']?[^"\'\s]+["\']?/i',
            '/secret\s*[:=]\s*["\']?[\w\-]+["\']?/i',
            '/bearer\s+[\w\-\.]+/i',
        ];

        foreach ($patterns as $pattern) {
            $output = preg_replace($pattern, '[REDACTED]', $output);
        }

        return [
            'output' => $output,
            'original_size' => $originalSize,
            'truncated' => $truncated,
            'sanitized' => true,
        ];
    }

    /**
     * Create a rate limit key for command execution.
     */
    public function getRateLimitKey(string $ticketId, string $command): string
    {
        $commandHash = substr(md5($command), 0, 8);

        return "runner:rate_limit:{$ticketId}:{$commandHash}";
    }

    /**
     * Check if command execution is rate limited.
     */
    public function isRateLimited(string $key, int $maxAttempts = 10, int $decaySeconds = 60): bool
    {
        $attempts = \Illuminate\Support\Facades\Cache::get($key, 0);

        if ($attempts >= $maxAttempts) {
            Log::warning('Command execution rate limited', [
                'key' => $key,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
            ]);

            return true;
        }

        \Illuminate\Support\Facades\Cache::put($key, $attempts + 1, $decaySeconds);

        return false;
    }
}
