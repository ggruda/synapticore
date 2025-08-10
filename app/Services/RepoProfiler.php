<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\RepoProfileJson;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Analyzes repository to detect languages, frameworks, and available commands.
 * Creates repo_profile.json with discovered information.
 */
class RepoProfiler
{
    /**
     * Known manifest files and their parsers.
     */
    private const MANIFESTS = [
        'composer.json' => 'parseComposerJson',
        'package.json' => 'parsePackageJson',
        'pyproject.toml' => 'parsePyprojectToml',
        'requirements.txt' => 'parseRequirementsTxt',
        'go.mod' => 'parseGoMod',
        'pom.xml' => 'parsePomXml',
        'build.gradle' => 'parseBuildGradle',
        'Gemfile' => 'parseGemfile',
        'Cargo.toml' => 'parseCargoToml',
        '.csproj' => 'parseCsproj',
    ];

    /**
     * Profile a repository and create repo_profile.json.
     */
    public function profileRepository(string $repoPath): RepoProfileJson
    {
        Log::info('Starting repository profiling', ['path' => $repoPath]);

        $manifests = $this->findManifests($repoPath);
        $languages = [];
        $frameworks = [];
        $dependencies = [];
        $tools = [];
        $commands = [];

        // Analyze each manifest
        foreach ($manifests as $manifest) {
            $relativePath = str_replace($repoPath.'/', '', $manifest);
            $filename = basename($manifest);

            // Find parser method
            foreach (self::MANIFESTS as $pattern => $method) {
                if (str_contains($filename, $pattern) || str_ends_with($filename, $pattern)) {
                    if (method_exists($this, $method)) {
                        $result = $this->$method($manifest);

                        // Merge results
                        $languages = array_unique(array_merge($languages, $result['languages'] ?? []));
                        $frameworks = array_merge($frameworks, $result['frameworks'] ?? []);
                        $dependencies = array_merge($dependencies, $result['dependencies'] ?? []);
                        $tools = array_unique(array_merge($tools, $result['tools'] ?? []));
                        $commands = array_merge($commands, $result['commands'] ?? []);
                    }
                    break;
                }
            }
        }

        // Detect additional tools
        $tools = array_merge($tools, $this->detectTools($repoPath));

        // Generate default commands if not found
        $commands = $this->normalizeCommands($commands, $tools, $languages);

        $profile = new RepoProfileJson(
            commands: $commands,
            dependencies: $dependencies,
            languages: array_values(array_unique($languages)),
            frameworks: $frameworks,
            tools: array_values(array_unique($tools)),
            manifests: array_map(
                fn ($path) => str_replace($repoPath.'/', '', $path),
                $manifests
            ),
            metadata: [
                'profiled_at' => now()->toIso8601String(),
                'repo_path' => $repoPath,
            ],
        );

        // Save profile to file
        $this->saveProfile($repoPath, $profile);

        Log::info('Repository profiling completed', [
            'languages' => $profile->languages,
            'frameworks' => array_keys($profile->frameworks),
            'tools' => $profile->tools,
        ]);

        return $profile;
    }

    /**
     * Find all manifest files in repository.
     *
     * @return array<string>
     */
    private function findManifests(string $repoPath): array
    {
        $manifests = [];

        foreach (self::MANIFESTS as $pattern => $method) {
            // Find files matching pattern
            $found = $this->findFiles($repoPath, $pattern);
            $manifests = array_merge($manifests, $found);
        }

        return array_unique($manifests);
    }

    /**
     * Find files matching pattern in repository.
     *
     * @return array<string>
     */
    private function findFiles(string $path, string $pattern): array
    {
        $files = [];

        if (str_contains($pattern, '*')) {
            // Use find command for wildcards
            $result = Process::path($path)
                ->run("find . -name '{$pattern}' -type f");

            if ($result->successful()) {
                $found = array_filter(
                    array_map('trim', explode("\n", $result->output())),
                    fn ($file) => ! empty($file)
                );

                foreach ($found as $file) {
                    $files[] = $path.'/'.ltrim($file, './');
                }
            }
        } else {
            // Direct file check
            $filePath = $path.'/'.$pattern;
            if (File::exists($filePath)) {
                $files[] = $filePath;
            }

            // Also check in common locations
            $commonPaths = ['src/', 'app/', 'lib/', 'backend/', 'frontend/'];
            foreach ($commonPaths as $commonPath) {
                $filePath = $path.'/'.$commonPath.$pattern;
                if (File::exists($filePath)) {
                    $files[] = $filePath;
                }
            }
        }

        return $files;
    }

    /**
     * Parse composer.json for PHP projects.
     */
    private function parseComposerJson(string $path): array
    {
        $content = json_decode(File::get($path), true);

        $frameworks = [];
        $dependencies = $content['require'] ?? [];

        // Detect Laravel
        if (isset($dependencies['laravel/framework'])) {
            $frameworks['laravel'] = $dependencies['laravel/framework'];
        }

        // Detect Symfony
        if (isset($dependencies['symfony/framework-bundle'])) {
            $frameworks['symfony'] = $dependencies['symfony/framework-bundle'];
        }

        // Get scripts as potential commands
        $scripts = $content['scripts'] ?? [];
        $commands = [];

        foreach (['test', 'lint', 'format', 'analyze'] as $cmd) {
            if (isset($scripts[$cmd])) {
                $commands[$cmd] = 'composer '.$cmd;
            }
        }

        // Check for Laravel Pint
        if (isset($dependencies['laravel/pint']) || isset($content['require-dev']['laravel/pint'])) {
            $commands['format'] = './vendor/bin/pint';
            $commands['lint'] = './vendor/bin/pint --test';
        }

        // Check for PHPUnit
        if (isset($content['require-dev']['phpunit/phpunit'])) {
            $commands['test'] = $commands['test'] ?? './vendor/bin/phpunit';
        }

        // Check for PHPStan
        if (isset($content['require-dev']['phpstan/phpstan'])) {
            $commands['typecheck'] = './vendor/bin/phpstan analyse';
        }

        return [
            'languages' => ['php'],
            'frameworks' => $frameworks,
            'dependencies' => $dependencies,
            'tools' => ['composer'],
            'commands' => $commands,
        ];
    }

    /**
     * Parse package.json for Node.js projects.
     */
    private function parsePackageJson(string $path): array
    {
        $content = json_decode(File::get($path), true);

        $frameworks = [];
        $dependencies = array_merge(
            $content['dependencies'] ?? [],
            $content['devDependencies'] ?? []
        );

        // Detect frameworks
        if (isset($dependencies['react'])) {
            $frameworks['react'] = $dependencies['react'];
        }
        if (isset($dependencies['vue'])) {
            $frameworks['vue'] = $dependencies['vue'];
        }
        if (isset($dependencies['@angular/core'])) {
            $frameworks['angular'] = $dependencies['@angular/core'];
        }
        if (isset($dependencies['next'])) {
            $frameworks['nextjs'] = $dependencies['next'];
        }
        if (isset($dependencies['express'])) {
            $frameworks['express'] = $dependencies['express'];
        }

        // Get scripts as commands
        $scripts = $content['scripts'] ?? [];
        $commands = [];

        $commandMap = [
            'test' => 'test',
            'lint' => 'lint',
            'format' => 'format',
            'build' => 'build',
            'dev' => 'dev',
            'start' => 'start',
            'typecheck' => 'typecheck',
        ];

        foreach ($commandMap as $key => $scriptName) {
            if (isset($scripts[$scriptName])) {
                $commands[$key] = 'npm run '.$scriptName;
            }
        }

        // Detect TypeScript
        $languages = ['javascript'];
        if (isset($dependencies['typescript'])) {
            $languages[] = 'typescript';
            $commands['typecheck'] = $commands['typecheck'] ?? 'npx tsc --noEmit';
        }

        // Detect package manager
        $tools = ['npm'];
        if (File::exists(dirname($path).'/yarn.lock')) {
            $tools[] = 'yarn';
        }
        if (File::exists(dirname($path).'/pnpm-lock.yaml')) {
            $tools[] = 'pnpm';
        }

        return [
            'languages' => $languages,
            'frameworks' => $frameworks,
            'dependencies' => $dependencies,
            'tools' => $tools,
            'commands' => $commands,
        ];
    }

    /**
     * Parse pyproject.toml for Python projects.
     */
    private function parsePyprojectToml(string $path): array
    {
        $content = File::get($path);
        $frameworks = [];
        $dependencies = [];
        $commands = [];

        // Simple TOML parsing for common patterns
        if (preg_match('/\[tool\.poetry\.dependencies\](.*?)\[/s', $content, $matches)) {
            $depSection = $matches[1];

            // Detect frameworks
            if (str_contains($depSection, 'django')) {
                $frameworks['django'] = 'detected';
            }
            if (str_contains($depSection, 'flask')) {
                $frameworks['flask'] = 'detected';
            }
            if (str_contains($depSection, 'fastapi')) {
                $frameworks['fastapi'] = 'detected';
            }
        }

        // Detect tools
        $tools = [];
        if (str_contains($content, '[tool.poetry]')) {
            $tools[] = 'poetry';
            $commands['install'] = 'poetry install';
            $commands['test'] = 'poetry run pytest';
        }

        if (str_contains($content, '[tool.black]')) {
            $commands['format'] = 'black .';
        }

        if (str_contains($content, '[tool.mypy]')) {
            $commands['typecheck'] = 'mypy .';
        }

        if (str_contains($content, '[tool.pytest]')) {
            $commands['test'] = $commands['test'] ?? 'pytest';
        }

        return [
            'languages' => ['python'],
            'frameworks' => $frameworks,
            'dependencies' => $dependencies,
            'tools' => $tools,
            'commands' => $commands,
        ];
    }

    /**
     * Parse requirements.txt for Python projects.
     */
    private function parseRequirementsTxt(string $path): array
    {
        $content = File::get($path);
        $lines = array_filter(
            array_map('trim', explode("\n", $content)),
            fn ($line) => ! empty($line) && ! str_starts_with($line, '#')
        );

        $frameworks = [];
        $dependencies = [];

        foreach ($lines as $line) {
            // Extract package name
            $package = preg_split('/[<>=!]/', $line)[0];
            $dependencies[$package] = $line;

            // Detect frameworks
            if (str_contains($package, 'django')) {
                $frameworks['django'] = $line;
            }
            if (str_contains($package, 'flask')) {
                $frameworks['flask'] = $line;
            }
            if (str_contains($package, 'fastapi')) {
                $frameworks['fastapi'] = $line;
            }
        }

        return [
            'languages' => ['python'],
            'frameworks' => $frameworks,
            'dependencies' => $dependencies,
            'tools' => ['pip'],
            'commands' => [
                'install' => 'pip install -r requirements.txt',
                'test' => 'python -m pytest',
            ],
        ];
    }

    /**
     * Parse go.mod for Go projects.
     */
    private function parseGoMod(string $path): array
    {
        $content = File::get($path);
        $frameworks = [];

        // Detect common Go frameworks
        if (str_contains($content, 'github.com/gin-gonic/gin')) {
            $frameworks['gin'] = 'detected';
        }
        if (str_contains($content, 'github.com/labstack/echo')) {
            $frameworks['echo'] = 'detected';
        }
        if (str_contains($content, 'github.com/gofiber/fiber')) {
            $frameworks['fiber'] = 'detected';
        }

        return [
            'languages' => ['go'],
            'frameworks' => $frameworks,
            'dependencies' => [],
            'tools' => ['go'],
            'commands' => [
                'build' => 'go build',
                'test' => 'go test ./...',
                'format' => 'go fmt ./...',
                'lint' => 'golangci-lint run',
            ],
        ];
    }

    /**
     * Parse pom.xml for Java projects.
     */
    private function parsePomXml(string $path): array
    {
        $content = File::get($path);
        $frameworks = [];

        // Detect Spring Boot
        if (str_contains($content, 'spring-boot')) {
            $frameworks['spring-boot'] = 'detected';
        }

        return [
            'languages' => ['java'],
            'frameworks' => $frameworks,
            'dependencies' => [],
            'tools' => ['maven'],
            'commands' => [
                'build' => 'mvn clean compile',
                'test' => 'mvn test',
                'package' => 'mvn package',
            ],
        ];
    }

    /**
     * Parse build.gradle for Java/Kotlin projects.
     */
    private function parseBuildGradle(string $path): array
    {
        $content = File::get($path);
        $frameworks = [];
        $languages = ['java'];

        // Detect Kotlin
        if (str_contains($content, 'kotlin')) {
            $languages[] = 'kotlin';
        }

        // Detect Spring Boot
        if (str_contains($content, 'spring-boot')) {
            $frameworks['spring-boot'] = 'detected';
        }

        return [
            'languages' => $languages,
            'frameworks' => $frameworks,
            'dependencies' => [],
            'tools' => ['gradle'],
            'commands' => [
                'build' => 'gradle build',
                'test' => 'gradle test',
                'clean' => 'gradle clean',
            ],
        ];
    }

    /**
     * Parse Gemfile for Ruby projects.
     */
    private function parseGemfile(string $path): array
    {
        $content = File::get($path);
        $frameworks = [];

        if (str_contains($content, 'rails')) {
            $frameworks['rails'] = 'detected';
        }

        if (str_contains($content, 'sinatra')) {
            $frameworks['sinatra'] = 'detected';
        }

        return [
            'languages' => ['ruby'],
            'frameworks' => $frameworks,
            'dependencies' => [],
            'tools' => ['bundler'],
            'commands' => [
                'install' => 'bundle install',
                'test' => 'bundle exec rspec',
            ],
        ];
    }

    /**
     * Parse Cargo.toml for Rust projects.
     */
    private function parseCargoToml(string $path): array
    {
        return [
            'languages' => ['rust'],
            'frameworks' => [],
            'dependencies' => [],
            'tools' => ['cargo'],
            'commands' => [
                'build' => 'cargo build',
                'test' => 'cargo test',
                'format' => 'cargo fmt',
                'lint' => 'cargo clippy',
            ],
        ];
    }

    /**
     * Parse .csproj for C# projects.
     */
    private function parseCsproj(string $path): array
    {
        $content = File::get($path);
        $frameworks = [];

        if (str_contains($content, 'Microsoft.AspNetCore')) {
            $frameworks['aspnetcore'] = 'detected';
        }

        return [
            'languages' => ['csharp'],
            'frameworks' => $frameworks,
            'dependencies' => [],
            'tools' => ['dotnet'],
            'commands' => [
                'build' => 'dotnet build',
                'test' => 'dotnet test',
                'run' => 'dotnet run',
            ],
        ];
    }

    /**
     * Detect additional tools in repository.
     *
     * @return array<string>
     */
    private function detectTools(string $repoPath): array
    {
        $tools = [];

        // Check for Docker
        if (File::exists($repoPath.'/Dockerfile') || File::exists($repoPath.'/docker-compose.yml')) {
            $tools[] = 'docker';
        }

        // Check for Make
        if (File::exists($repoPath.'/Makefile')) {
            $tools[] = 'make';
        }

        // Check for Git
        if (File::exists($repoPath.'/.git')) {
            $tools[] = 'git';
        }

        return $tools;
    }

    /**
     * Normalize and generate default commands.
     */
    private function normalizeCommands(array $commands, array $tools, array $languages): array
    {
        // Ensure essential commands exist
        $essentials = ['lint', 'format', 'test', 'build'];

        foreach ($essentials as $cmd) {
            if (! isset($commands[$cmd])) {
                // Try to generate default command
                $commands[$cmd] = $this->generateDefaultCommand($cmd, $tools, $languages);
            }
        }

        // Remove null values
        return array_filter($commands, fn ($cmd) => $cmd !== null);
    }

    /**
     * Generate default command based on detected tools and languages.
     */
    private function generateDefaultCommand(string $command, array $tools, array $languages): ?string
    {
        // Language-specific defaults
        $defaults = [
            'php' => [
                'test' => './vendor/bin/phpunit',
                'lint' => './vendor/bin/pint --test',
                'format' => './vendor/bin/pint',
            ],
            'javascript' => [
                'test' => 'npm test',
                'lint' => 'npm run lint',
                'build' => 'npm run build',
            ],
            'python' => [
                'test' => 'pytest',
                'format' => 'black .',
                'lint' => 'flake8',
            ],
            'go' => [
                'test' => 'go test ./...',
                'format' => 'go fmt ./...',
                'build' => 'go build',
            ],
        ];

        foreach ($languages as $lang) {
            if (isset($defaults[$lang][$command])) {
                return $defaults[$lang][$command];
            }
        }

        return null;
    }

    /**
     * Save profile to file.
     */
    private function saveProfile(string $repoPath, RepoProfileJson $profile): void
    {
        $profilePath = $repoPath.'/repo_profile.json';

        File::put(
            $profilePath,
            json_encode($profile->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        Log::info('Saved repository profile', ['path' => $profilePath]);
    }
}
