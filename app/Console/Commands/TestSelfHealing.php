<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RepairAttemptJob;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workflow;
use App\Services\SelfHealing\FailureCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Command to test the self-healing system.
 */
class TestSelfHealing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'self-healing:test
                            {--scenario=lint : Test scenario (lint|test|type|import)}
                            {--create : Create a new test ticket with intentional errors}
                            {--ticket= : Use existing ticket ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test self-healing system with various failure scenarios';

    /**
     * Execute the console command.
     */
    public function handle(FailureCollector $failureCollector): int
    {
        $this->info('🧪 Testing Self-Healing System');
        $this->newLine();

        $scenario = $this->option('scenario');

        // Get or create ticket
        $ticket = $this->getOrCreateTicket();

        if (! $ticket) {
            $this->error('Failed to get or create ticket');

            return Command::FAILURE;
        }

        $this->info("📋 Using ticket: {$ticket->external_key}");
        $this->newLine();

        // Create intentional failure based on scenario
        $this->info("🔥 Creating {$scenario} failure scenario...");

        switch ($scenario) {
            case 'lint':
                $this->createLintFailure($ticket);
                break;
            case 'test':
                $this->createTestFailure($ticket);
                break;
            case 'type':
                $this->createTypeFailure($ticket);
                break;
            case 'import':
                $this->createImportFailure($ticket);
                break;
            default:
                $this->error("Unknown scenario: {$scenario}");

                return Command::FAILURE;
        }

        // Capture failure
        $this->info('📦 Capturing failure bundle...');

        $exception = $this->createException($scenario);
        $bundlePath = $failureCollector->captureFailure(
            $exception,
            $ticket,
            'TestSelfHealing',
            [
                'scenario' => $scenario,
                'test_mode' => true,
            ]
        );

        $this->info("✅ Bundle created: {$bundlePath}");
        $this->newLine();

        // Display bundle summary
        $bundle = $failureCollector->loadBundle($bundlePath);
        if ($bundle) {
            $this->displayBundleSummary($bundle);
        }

        // Ask if user wants to attempt repair
        if ($this->confirm('Attempt automatic repair?')) {
            $this->info('🔧 Dispatching repair job...');

            RepairAttemptJob::dispatch($ticket, $bundlePath, 1)
                ->delay(now()->addSeconds(5));

            $this->info('✅ Repair job queued');
            $this->warn('Run queue worker to process: php artisan queue:work --stop-when-empty');
        }

        return Command::SUCCESS;
    }

    /**
     * Get or create test ticket.
     */
    private function getOrCreateTicket(): ?Ticket
    {
        if ($ticketId = $this->option('ticket')) {
            return Ticket::find($ticketId);
        }

        if ($this->option('create')) {
            return $this->createTestTicket();
        }

        // Find recent test ticket
        $ticket = Ticket::where('external_key', 'like', 'HEAL-%')
            ->latest()
            ->first();

        if (! $ticket) {
            $this->info('Creating new test ticket...');

            return $this->createTestTicket();
        }

        return $ticket;
    }

    /**
     * Create test ticket for self-healing.
     */
    private function createTestTicket(): Ticket
    {
        $project = Project::firstOrCreate(
            ['name' => 'Self-Healing Test Project'],
            [
                'repo_urls' => ['https://github.com/laravel/laravel'],
                'default_branch' => '11.x',
                'allowed_paths' => ['app/', 'tests/'],
                'language_profile' => [
                    'languages' => ['php'],
                    'commands' => [
                        'lint' => 'vendor/bin/pint --test',
                        'test' => 'vendor/bin/phpunit',
                        'format' => 'vendor/bin/pint',
                    ],
                ],
            ]
        );

        $ticket = Ticket::create([
            'project_id' => $project->id,
            'external_key' => 'HEAL-'.rand(1000, 9999),
            'source' => 'jira',  // Use valid source
            'title' => 'Self-healing test ticket',
            'body' => 'Testing automatic failure recovery',
            'acceptance_criteria' => [
                'Lint errors are automatically fixed',
                'Test failures are resolved',
                'Type errors are corrected',
            ],
            'labels' => ['test', 'self-healing'],
            'status' => 'in_progress',
            'priority' => 'low',
            'meta' => [
                'test_mode' => true,
                'scenario' => 'self-healing',
            ],
        ]);

        // Create workflow
        Workflow::create([
            'ticket_id' => $ticket->id,
            'state' => 'TESTING',
            'retries' => 0,
        ]);

        return $ticket;
    }

    /**
     * Create lint failure scenario.
     */
    private function createLintFailure(Ticket $ticket): void
    {
        $workspacePath = storage_path('app/workspaces/'.$ticket->id.'/repo');

        if (! File::exists($workspacePath)) {
            File::makeDirectory($workspacePath, 0755, true);
        }

        // Create PHP file with lint errors
        $badCode = <<<'PHP'
<?php

namespace App\Test;

class TestClass {
    public function badMethod() 
    {
        $variable = "missing semicolon"
        $anotherVar = 'mixed quotes";
        
        if($condition){
            echo"no space";
        }
        
        return$result;
    }
    
    private function  tooManySpaces  (  )  {
        return    true   ;
    }
}
PHP;

        File::put($workspacePath.'/TestClass.php', $badCode);

        $this->info('Created file with lint errors: TestClass.php');
    }

    /**
     * Create test failure scenario.
     */
    private function createTestFailure(Ticket $ticket): void
    {
        $workspacePath = storage_path('app/workspaces/'.$ticket->id.'/repo');

        if (! File::exists($workspacePath)) {
            File::makeDirectory($workspacePath, 0755, true);
        }

        // Create test file that will fail
        $testCode = <<<'PHP'
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FailingTest extends TestCase
{
    public function testThatFails()
    {
        $this->assertEquals(1, 2); // This will fail
    }
    
    public function testAnotherFailure()
    {
        $this->assertTrue(false); // This will also fail
    }
}
PHP;

        File::put($workspacePath.'/tests/Unit/FailingTest.php', $testCode);

        $this->info('Created failing test: FailingTest.php');
    }

    /**
     * Create type error scenario.
     */
    private function createTypeFailure(Ticket $ticket): void
    {
        $workspacePath = storage_path('app/workspaces/'.$ticket->id.'/repo');

        if (! File::exists($workspacePath)) {
            File::makeDirectory($workspacePath, 0755, true);
        }

        // Create file with type errors
        $typeErrorCode = <<<'PHP'
<?php

namespace App\Services;

class TypeErrorService
{
    public function process(string $input): int
    {
        return $input; // Type error: returning string instead of int
    }
    
    public function calculate($number): float
    {
        return $number + "string"; // Type error: adding string to number
    }
    
    private function helper(array $data)
    {
        return $data->property; // Type error: treating array as object
    }
}
PHP;

        File::put($workspacePath.'/TypeErrorService.php', $typeErrorCode);

        $this->info('Created file with type errors: TypeErrorService.php');
    }

    /**
     * Create import/namespace error scenario.
     */
    private function createImportFailure(Ticket $ticket): void
    {
        $workspacePath = storage_path('app/workspaces/'.$ticket->id.'/repo');

        if (! File::exists($workspacePath)) {
            File::makeDirectory($workspacePath, 0755, true);
        }

        // Create file with missing imports
        $importErrorCode = <<<'PHP'
<?php

namespace App\Controllers;

class ImportErrorController
{
    public function index()
    {
        $user = User::find(1); // Missing use statement
        $request = Request::all(); // Missing use statement
        
        return new JsonResponse(['data' => $user]); // Missing use statement
    }
    
    public function store()
    {
        $validator = Validator::make([], []); // Missing use statement
        
        throw new ValidationException('Error'); // Missing use statement
    }
}
PHP;

        File::put($workspacePath.'/ImportErrorController.php', $importErrorCode);

        $this->info('Created file with import errors: ImportErrorController.php');
    }

    /**
     * Create exception for scenario.
     */
    private function createException(string $scenario): \Exception
    {
        return match ($scenario) {
            'lint' => new \Exception('Lint check failed: Found 5 style violations'),
            'test' => new \Exception('Tests failed: 2 failures in FailingTest'),
            'type' => new \Exception('Type error: Return value must be of type int, string returned'),
            'import' => new \Exception('Class "User" not found'),
            default => new \Exception('Unknown failure'),
        };
    }

    /**
     * Display bundle summary.
     */
    private function displayBundleSummary(array $bundle): void
    {
        $this->info('📊 Failure Bundle Summary');
        $this->table(
            ['Field', 'Value'],
            [
                ['Timestamp', $bundle['timestamp'] ?? 'N/A'],
                ['Exception', $bundle['failure']['exception']['class'] ?? 'N/A'],
                ['Message', substr($bundle['failure']['exception']['message'] ?? '', 0, 50).'...'],
            ]
        );

        if (! empty($bundle['suggestions'])) {
            $this->newLine();
            $this->info('💡 Repair Suggestions:');

            foreach ($bundle['suggestions'] as $suggestion) {
                $priority = $suggestion['priority'] ?? 'medium';
                $type = $suggestion['type'] ?? 'unknown';
                $action = $suggestion['action'] ?? '';

                $this->line("  [{$priority}] {$type}: {$action}");

                if (isset($suggestion['commands'])) {
                    foreach ($suggestion['commands'] as $command) {
                        $this->line("    → {$command}");
                    }
                }
            }
        }

        $this->newLine();
    }
}
