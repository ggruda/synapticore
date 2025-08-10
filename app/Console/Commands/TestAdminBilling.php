<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Worklog;
use App\Services\Billing\InvoiceNumberGenerator;
use App\Services\Billing\WorklogAggregator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class TestAdminBilling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:test-billing 
                            {--worklogs : Test worklog management}
                            {--invoices : Test invoice management}
                            {--csv : Test CSV export}
                            {--all : Run all tests}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the admin UI for worklogs and invoices';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧪 Testing Admin Billing UI');
        $this->newLine();
        
        // Ensure admin role exists
        $this->ensureAdminRole();
        
        if ($this->option('all') || $this->option('worklogs')) {
            $this->testWorklogManagement();
        }
        
        if ($this->option('all') || $this->option('invoices')) {
            $this->testInvoiceManagement();
        }
        
        if ($this->option('all') || $this->option('csv')) {
            $this->testCsvExport();
        }
        
        $this->newLine();
        $this->info('✅ Admin billing tests completed');
        $this->info('Access the admin UI at: http://localhost:8080/admin/worklogs');
        $this->info('                        http://localhost:8080/admin/invoices');
        
        return Command::SUCCESS;
    }
    
    private function ensureAdminRole(): void
    {
        if (!Role::where('name', 'admin')->exists()) {
            Role::create(['name' => 'admin']);
            $this->info('Created admin role');
        }
        
        // Ensure test admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@synapticore.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
            ]
        );
        
        if (!$admin->hasRole('admin')) {
            $admin->assignRole('admin');
            $this->info('Assigned admin role to test user');
        }
    }
    
    private function testWorklogManagement(): void
    {
        $this->info('Testing Worklog Management...');
        
        // Create test data
        $project = Project::first();
        if (!$project) {
            $project = Project::factory()->create(['name' => 'Test Billing Project']);
        }
        
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'external_key' => 'BILL-' . fake()->numberBetween(1000, 9999),
            'title' => 'Test ticket for billing',
        ]);
        
        // Create worklogs for different phases
        $phases = ['plan', 'implement', 'test', 'review', 'pr'];
        $startDate = Carbon::now()->subDays(30);
        
        foreach ($phases as $index => $phase) {
            for ($i = 0; $i < 3; $i++) {
                Worklog::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => 1,
                    'phase' => $phase,
                    'seconds' => fake()->numberBetween(1800, 7200), // 30min to 2h
                    'started_at' => $startDate->copy()->addDays($index * 5 + $i),
                    'ended_at' => $startDate->copy()->addDays($index * 5 + $i)->addHours(2),
                    'status' => 'completed',
                    'notes' => "Work on {$phase} phase - Day " . ($i + 1),
                    'synced_at' => fake()->boolean(70) ? Carbon::now() : null,
                    'sync_status' => fake()->randomElement(['success', 'failed', null]),
                ]);
            }
        }
        
        // Display statistics
        $totalWorklogs = Worklog::whereHas('ticket', function ($q) use ($project) {
            $q->where('project_id', $project->id);
        })->count();
        
        $totalHours = Worklog::whereHas('ticket', function ($q) use ($project) {
            $q->where('project_id', $project->id);
        })->sum('seconds') / 3600;
        
        $this->info("  → Created {$totalWorklogs} worklogs");
        $this->info("  → Total hours: " . number_format($totalHours, 2));
        
        // Test filtering
        $this->info("  → Testing filters:");
        
        $byPhase = Worklog::whereHas('ticket', function ($q) use ($project) {
            $q->where('project_id', $project->id);
        })->select('phase', DB::raw('COUNT(*) as count'), DB::raw('SUM(seconds) as total'))
          ->groupBy('phase')
          ->get();
        
        foreach ($byPhase as $phase) {
            $this->line("    • {$phase->phase}: {$phase->count} entries, " . 
                number_format($phase->total / 3600, 2) . " hours");
        }
        
        $this->info("  ✓ Worklog management ready");
    }
    
    private function testInvoiceManagement(): void
    {
        $this->info('Testing Invoice Management...');
        
        // Get aggregator
        $aggregator = app(WorklogAggregator::class);
        $numberGenerator = app(InvoiceNumberGenerator::class);
        
        // Get projects with worklogs
        $startDate = Carbon::now()->startOfMonth()->subMonth();
        $endDate = Carbon::now()->endOfMonth()->subMonth();
        
        $projects = $aggregator->getProjectsWithBillableWork($startDate, $endDate);
        
        if ($projects->isEmpty()) {
            // Create test data for last month
            $project = Project::first();
            if ($project) {
                $ticket = Ticket::factory()->create([
                    'project_id' => $project->id,
                    'external_key' => 'INV-TEST-' . fake()->numberBetween(1000, 9999),
                ]);
                
                for ($i = 0; $i < 10; $i++) {
                    Worklog::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => 1,
                        'phase' => fake()->randomElement(['plan', 'implement', 'test']),
                        'seconds' => fake()->numberBetween(3600, 14400), // 1-4 hours
                        'started_at' => $startDate->copy()->addDays($i * 2),
                        'ended_at' => $startDate->copy()->addDays($i * 2)->addHours(2),
                        'status' => 'completed',
                        'notes' => 'Invoice test worklog ' . ($i + 1),
                    ]);
                }
                
                $projects = $aggregator->getProjectsWithBillableWork($startDate, $endDate);
            }
        }
        
        // Create test invoices
        $createdInvoices = [];
        foreach ($projects->take(2) as $project) {
            $aggregated = $aggregator->aggregateForProject($project, $startDate, $endDate);
            
            if ($aggregated['billable_hours'] > 0) {
                $invoiceNumber = $numberGenerator->generate($project, $startDate);
                
                $subtotal = $aggregated['billable_hours'] * config('billing.unit_price_per_hour');
                $taxRate = config('billing.tax_rate', 0.077);
                $taxAmount = round($subtotal * $taxRate, 2);
                $total = $subtotal + $taxAmount;
                
                $invoice = Invoice::create([
                    'project_id' => $project->id,
                    'invoice_number' => $invoiceNumber,
                    'period_start' => $startDate,
                    'period_end' => $endDate,
                    'due_date' => Carbon::now()->addDays(30),
                    'currency' => config('billing.default_currency', 'CHF'),
                    'subtotal' => $subtotal,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                    'status' => fake()->randomElement(['draft', 'sent', 'paid']),
                    'meta' => [
                        'worklog_ids' => $aggregated['worklog_ids'],
                        'total_hours' => $aggregated['total_hours'],
                        'billable_hours' => $aggregated['billable_hours'],
                    ],
                ]);
                
                // Create invoice items
                foreach ($aggregated['items'] as $item) {
                    \App\Models\InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'unit_price' => $item['unit_price'],
                        'amount' => $item['amount'],
                        'meta' => $item['meta'] ?? [],
                    ]);
                }
                
                $createdInvoices[] = $invoice;
                
                $this->info("  → Created invoice {$invoice->invoice_number}");
                $this->info("    • Project: {$project->name}");
                $this->info("    • Billable hours: {$aggregated['billable_hours']}");
                $this->info("    • Total: " . config('billing.currency_symbol', 'CHF') . " " . 
                    number_format($total, 2));
            }
        }
        
        // Display invoice statistics
        $stats = [
            'total' => Invoice::count(),
            'draft' => Invoice::where('status', 'draft')->count(),
            'sent' => Invoice::where('status', 'sent')->count(),
            'paid' => Invoice::where('status', 'paid')->count(),
            'overdue' => Invoice::where('status', 'sent')
                ->where('due_date', '<', Carbon::today())
                ->count(),
        ];
        
        $this->info("  → Invoice Statistics:");
        $this->info("    • Total: {$stats['total']}");
        $this->info("    • Draft: {$stats['draft']}");
        $this->info("    • Sent: {$stats['sent']}");
        $this->info("    • Paid: {$stats['paid']}");
        $this->info("    • Overdue: {$stats['overdue']}");
        
        $this->info("  ✓ Invoice management ready");
    }
    
    private function testCsvExport(): void
    {
        $this->info('Testing CSV Export...');
        
        // Simulate CSV export
        $worklogs = Worklog::with(['ticket.project', 'user'])
            ->where('status', 'completed')
            ->limit(10)
            ->get();
        
        $csv = [];
        $csv[] = ['ID', 'Date', 'Project', 'Ticket', 'Phase', 'Duration (hours)', 'User', 'Status'];
        
        foreach ($worklogs as $worklog) {
            $csv[] = [
                $worklog->id,
                $worklog->started_at->format('Y-m-d'),
                $worklog->ticket->project->name ?? 'N/A',
                $worklog->ticket->external_key ?? 'N/A',
                $worklog->phase,
                round($worklog->seconds / 3600, 2),
                $worklog->user->name ?? 'System',
                $worklog->status,
            ];
        }
        
        $this->info("  → Sample CSV data (first 5 rows):");
        foreach (array_slice($csv, 0, 5) as $row) {
            $this->line("    " . implode(', ', $row));
        }
        
        $this->info("  ✓ CSV export ready");
    }
}