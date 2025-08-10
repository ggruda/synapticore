<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildContextJob;
use App\Models\Project;
use App\Models\Ticket;
use App\Services\Context\AstTools\JsAstTool;
use App\Services\Context\AstTools\PhpAstTool;
use App\Services\Context\EmbeddingIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Test command for context building tools.
 */
class TestContextTools extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'context:test 
                            {--ast : Test AST manipulation}
                            {--embeddings : Test embedding indexing}
                            {--job : Test BuildContextJob}
                            {--search= : Search query for embeddings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test context building tools (embeddings and AST)';

    /**
     * Execute the console command.
     */
    public function handle(
        PhpAstTool $phpAst,
        JsAstTool $jsAst,
        EmbeddingIndexer $embeddingIndexer,
    ): int {
        $this->info('🧪 Testing Context Building Tools');
        $this->newLine();

        if ($this->option('ast')) {
            $this->testAstManipulation($phpAst, $jsAst);
        }

        if ($this->option('embeddings')) {
            $this->testEmbeddingIndexing($embeddingIndexer);
        }

        if ($this->option('job')) {
            $this->testBuildContextJob();
        }

        if ($searchQuery = $this->option('search')) {
            $this->testEmbeddingSearch($embeddingIndexer, $searchQuery);
        }

        return Command::SUCCESS;
    }

    /**
     * Test AST manipulation.
     */
    private function testAstManipulation(PhpAstTool $phpAst, JsAstTool $jsAst): void
    {
        $this->info('📝 Testing AST Manipulation...');

        // Create test PHP file
        $phpFile = storage_path('app/test_ast.php');
        $phpContent = <<<'PHP'
<?php

namespace App\Test;

class TestClass
{
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
PHP;

        File::put($phpFile, $phpContent);

        try {
            // Test PHP AST: Add a new method
            $this->info('Adding new method to PHP class...');

            $methodCode = <<<'PHP'
public function setName(string $name): void
{
    $this->name = $name;
}
PHP;

            $phpAst->addMethod($phpFile, 'TestClass', 'setName', $methodCode);

            $this->info('✅ Method added successfully!');

            // Show methods in the class
            $methods = $phpAst->findClassMethods($phpFile, 'TestClass');
            $this->table(
                ['Method', 'Visibility', 'Static', 'Parameters'],
                array_map(fn ($name, $info) => [
                    $name,
                    $info['visibility'],
                    $info['isStatic'] ? 'Yes' : 'No',
                    count($info['parameters']),
                ], array_keys($methods), $methods)
            );

            // Test adding a property
            $this->info('Adding new property to PHP class...');
            $phpAst->addProperty($phpFile, 'TestClass', 'age', 'int', false, false, 0);
            $this->info('✅ Property added successfully!');

            // Show the modified file
            $this->info('Modified PHP file:');
            $this->line(substr(File::get($phpFile), 0, 500).'...');
        } catch (\Exception $e) {
            $this->error('PHP AST test failed: '.$e->getMessage());
        } finally {
            // Clean up
            @unlink($phpFile);
        }

        $this->newLine();

        // Create test TypeScript file
        $tsFile = storage_path('app/test_ast.ts');
        $tsContent = <<<'TS'
export class User {
    private name: string;

    constructor(name: string) {
        this.name = name;
    }

    getName(): string {
        return this.name;
    }
}

export function greet(name: string): string {
    return `Hello, ${name}!`;
}
TS;

        File::put($tsFile, $tsContent);

        try {
            $this->info('Testing TypeScript AST manipulation...');

            // Note: This would require the Node.js helper to be set up
            // For now, we'll just demonstrate the API
            $this->warn('TypeScript AST manipulation requires Node.js with ts-morph');
            $this->info('Would add method: setName(name: string): void');
            $this->info('Would add function: farewell(name: string): string');
        } catch (\Exception $e) {
            $this->error('TypeScript AST test failed: '.$e->getMessage());
        } finally {
            // Clean up
            @unlink($tsFile);
        }
    }

    /**
     * Test embedding indexing.
     */
    private function testEmbeddingIndexing(EmbeddingIndexer $embeddingIndexer): void
    {
        $this->info('🔍 Testing Embedding Indexing...');

        // Create test repository structure
        $testRepo = storage_path('app/test_repo');
        if (! is_dir($testRepo)) {
            mkdir($testRepo, 0755, true);
        }

        // Create test files
        $files = [
            'UserController.php' => <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        return User::all();
    }

    public function show(User $user)
    {
        return $user;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
        ]);

        return User::create($validated);
    }
}
PHP,
            'user.service.ts' => <<<'TS'
export class UserService {
    private apiUrl = '/api/users';

    async getUsers(): Promise<User[]> {
        const response = await fetch(this.apiUrl);
        return response.json();
    }

    async getUser(id: number): Promise<User> {
        const response = await fetch(`${this.apiUrl}/${id}`);
        return response.json();
    }

    async createUser(user: Partial<User>): Promise<User> {
        const response = await fetch(this.apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(user),
        });
        return response.json();
    }
}
TS,
            'README.md' => <<<'MD'
# Test Repository

This is a test repository for embedding indexing.

## Features

- User management
- API endpoints
- TypeScript services

## Usage

1. Install dependencies
2. Run migrations
3. Start the server
MD,
        ];

        foreach ($files as $filename => $content) {
            File::put($testRepo.'/'.$filename, $content);
        }

        try {
            // Create a test project
            $project = Project::create([
                'name' => 'Test Embedding Project',
                'repo_urls' => ['https://github.com/test/repo'],
                'default_branch' => 'main',
                'allowed_paths' => ['*.php', '*.ts', '*.md'],
                'language_profile' => [],
            ]);

            // Index the repository
            $this->info('Indexing test repository...');
            $chunksIndexed = $embeddingIndexer->indexRepository(
                repoPath: $testRepo,
                projectId: $project->id,
                allowedPaths: ['*.php', '*.ts', '*.md'],
            );

            $this->info("✅ Indexed {$chunksIndexed} chunks");

            // Clean up
            $embeddingIndexer->clearProjectEmbeddings($project->id);
            $project->delete();
        } catch (\Exception $e) {
            $this->error('Embedding indexing test failed: '.$e->getMessage());
        } finally {
            // Clean up test repository
            File::deleteDirectory($testRepo);
        }
    }

    /**
     * Test embedding search.
     */
    private function testEmbeddingSearch(EmbeddingIndexer $embeddingIndexer, string $query): void
    {
        $this->info("🔎 Searching for: {$query}");

        try {
            $results = $embeddingIndexer->search($query, 5);

            if (empty($results)) {
                $this->warn('No results found');

                return;
            }

            $this->info('Search results:');
            foreach ($results as $index => $hit) {
                $this->line(($index + 1).'. Score: '.round($hit->score, 4));
                $this->line('   File: '.($hit->metadata['file_path'] ?? 'unknown'));
                $this->line('   Content: '.substr($hit->content, 0, 100).'...');
                $this->newLine();
            }
        } catch (\Exception $e) {
            $this->error('Search failed: '.$e->getMessage());
        }
    }

    /**
     * Test BuildContextJob.
     */
    private function testBuildContextJob(): void
    {
        $this->info('🚀 Testing BuildContextJob...');

        try {
            // Create test project and ticket
            $project = Project::create([
                'name' => 'Test Context Project',
                'repo_urls' => ['https://github.com/laravel/laravel'],
                'default_branch' => '11.x',
                'allowed_paths' => ['app/', 'tests/'],
                'language_profile' => [],
            ]);

            $ticket = Ticket::create([
                'project_id' => $project->id,
                'external_key' => 'CTX-'.rand(1000, 9999),
                'source' => 'jira',
                'title' => 'Test context building',
                'body' => 'Testing the BuildContextJob',
                'status' => 'open',
                'priority' => 'medium',
                'labels' => ['test'],
                'acceptance_criteria' => [],
                'meta' => [],
            ]);

            $this->info("Created test ticket: {$ticket->external_key}");

            // Dispatch the job
            BuildContextJob::dispatch($ticket);
            $this->info('✅ BuildContextJob dispatched');

            $this->info('Run queue worker to process the job:');
            $this->line('php artisan queue:work --stop-when-empty');

            // Clean up
            $this->warn('Remember to clean up test data after job completes');
        } catch (\Exception $e) {
            $this->error('BuildContextJob test failed: '.$e->getMessage());
        }
    }
}
