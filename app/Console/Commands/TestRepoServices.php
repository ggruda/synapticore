<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Ticket;
use App\Services\RepoManager;
use App\Services\RepoProfiler;
use App\Services\WorkspaceRunner;
use Illuminate\Console\Command;

/**
 * Test command for repository services.
 */
class TestRepoServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'repo:test 
                            {--repo=https://github.com/laravel/laravel : Repository URL to test}
                            {--branch=main : Branch to clone}
                            {--cleanup : Clean up workspace after test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test repository services (clone, profile, run)';

    /**
     * Execute the console command.
     */
    public function handle(
        RepoManager $repoManager,
        RepoProfiler $repoProfiler,
        WorkspaceRunner $workspaceRunner,
    ): int {
        $repoUrl = $this->option('repo');
        $branch = $this->option('branch');

        $this->info('🧪 Testing Repository Services');
        $this->info("Repository: {$repoUrl}");
        $this->info("Branch: {$branch}");
        $this->newLine();

        // Create test project and ticket
        $project = $this->createTestProject($repoUrl, $branch);
        $ticket = $this->createTestTicket($project);

        try {
            // Test 1: Clone and setup workspace
            $this->info('📦 Step 1: Setting up workspace...');
            $workspacePath = $repoManager->setupWorkspace($ticket);
            $this->info("✅ Workspace created at: {$workspacePath}");

            // List workspace contents
            $files = scandir($workspacePath);
            $this->info('Files in workspace: '.implode(', ', array_slice($files, 2, 10)));
            $this->newLine();

            // Test 2: Profile repository
            $this->info('🔍 Step 2: Profiling repository...');
            $profile = $repoProfiler->profileRepository($workspacePath);

            $this->info('✅ Repository profiled successfully!');
            $this->table(
                ['Property', 'Value'],
                [
                    ['Languages', implode(', ', $profile->languages)],
                    ['Frameworks', implode(', ', array_keys($profile->frameworks))],
                    ['Tools', implode(', ', $profile->tools)],
                    ['Manifests', implode(', ', $profile->manifests)],
                ]
            );

            // Show available commands
            if (! empty($profile->commands)) {
                $this->newLine();
                $this->info('Available commands:');
                foreach ($profile->commands as $name => $command) {
                    $this->info("  {$name}: {$command}");
                }
            }
            $this->newLine();

            // Test 3: Run a command
            $this->info('⚡ Step 3: Running test command...');

            // Determine language and command
            $language = $profile->primaryLanguage() ?? 'bash';
            $testCommand = $this->selectTestCommand($profile);

            if ($testCommand) {
                $this->info("Running: {$testCommand} (language: {$language})");

                // Run command (use direct mode in local environment)
                if (app()->environment('local')) {
                    $result = $workspaceRunner->runDirect($workspacePath, $testCommand);
                } else {
                    $result = $workspaceRunner->run($workspacePath, $testCommand, $language);
                }

                $this->info("✅ Command completed with exit code: {$result->exitCode}");

                if (! empty($result->stdout)) {
                    $this->info('Output (first 500 chars):');
                    $this->line(substr($result->stdout, 0, 500));
                }

                if (! empty($result->stderr) && $result->exitCode !== 0) {
                    $this->error('Error output:');
                    $this->line($result->stderr);
                }
            } else {
                $this->warn('No suitable test command found');
            }

            // Test 4: Check modified files
            $this->newLine();
            $this->info('📝 Step 4: Checking for modifications...');
            $modifiedFiles = $repoManager->getModifiedFiles($workspacePath);
            if (! empty($modifiedFiles)) {
                $this->info('Modified files: '.implode(', ', $modifiedFiles));
            } else {
                $this->info('No files modified');
            }

            // Test 5: Path restrictions
            $this->newLine();
            $this->info('🔒 Step 5: Testing path restrictions...');
            $testPaths = ['src/index.php', 'vendor/autoload.php', '.env', 'app/Models/User.php'];
            foreach ($testPaths as $path) {
                $allowed = $repoManager->isPathAllowed($path, $project);
                $status = $allowed ? '✅ Allowed' : '❌ Blocked';
                $this->info("  {$path}: {$status}");
            }

            $this->newLine();
            $this->info('🎉 All tests completed successfully!');

        } catch (\Exception $e) {
            $this->error('❌ Test failed: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        } finally {
            // Cleanup
            if ($this->option('cleanup')) {
                $this->newLine();
                $this->info('🧹 Cleaning up...');
                $repoManager->cleanWorkspace($ticket);
                $ticket->delete();
                $project->delete();
                $this->info('✅ Cleanup complete');
            } else {
                $workspaceMsg = isset($workspacePath) ? $workspacePath : 'N/A';
                $this->warn("Workspace preserved at: {$workspaceMsg}");
                $this->warn('Run with --cleanup to remove');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Create test project.
     */
    private function createTestProject(string $repoUrl, string $branch): Project
    {
        return Project::create([
            'name' => 'Test Project - '.now()->format('Y-m-d H:i:s'),
            'repo_urls' => [$repoUrl],
            'default_branch' => $branch,
            'allowed_paths' => ['src/', 'app/', 'tests/', '*.md'],
            'language_profile' => [],
        ]);
    }

    /**
     * Create test ticket.
     */
    private function createTestTicket(Project $project): Ticket
    {
        return Ticket::create([
            'project_id' => $project->id,
            'external_key' => 'TEST-'.rand(1000, 9999),
            'source' => 'jira',
            'title' => 'Test ticket for repository services',
            'body' => 'This is a test ticket',
            'status' => 'open',
            'priority' => 'medium',
            'labels' => ['test'],
            'acceptance_criteria' => [],
            'meta' => [],
        ]);
    }

    /**
     * Select appropriate test command based on profile.
     */
    private function selectTestCommand($profile): ?string
    {
        // Try to find a simple command that won't fail
        $preferences = [
            'version' => null,  // Check for version commands
            'help' => null,     // Check for help commands
            'list' => null,     // Check for list commands
        ];

        // Language-specific version commands
        $versionCommands = [
            'php' => 'php --version',
            'javascript' => 'node --version',
            'typescript' => 'node --version',
            'python' => 'python --version',
            'go' => 'go version',
            'java' => 'java --version',
            'ruby' => 'ruby --version',
        ];

        $language = $profile->primaryLanguage();
        if ($language && isset($versionCommands[$language])) {
            return $versionCommands[$language];
        }

        // Try profile commands
        foreach (['lint', 'format', 'test'] as $cmd) {
            if ($profile->hasCommand($cmd)) {
                // For test command, check if it's safe
                $command = $profile->getCommand($cmd);
                if (str_contains($command, '--help') || str_contains($command, '--version')) {
                    return $command;
                }
            }
        }

        // Default to listing files
        return 'ls -la';
    }
}
