<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\InvoiceMailer;
use App\Services\Billing\InvoiceNumberGenerator;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\Billing\WorklogAggregator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Command to generate monthly invoices for all projects with billable work.
 */
class GenerateMonthlyInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:generate-monthly
                            {--month= : Month to generate invoices for (YYYY-MM format)}
                            {--dry-run : Run without creating invoices}
                            {--no-email : Generate invoices but do not send emails}
                            {--project= : Generate invoice for specific project ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate monthly invoices for all projects with billable work';

    public function __construct(
        private WorklogAggregator $aggregator,
        private InvoiceNumberGenerator $numberGenerator,
        private InvoicePdfGenerator $pdfGenerator,
        private InvoiceMailer $mailer
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧾 Starting monthly invoice generation');
        $this->newLine();

        // Determine the period
        $period = $this->getPeriod();
        $this->info("Period: {$period['start']->format('Y-m-d')} to {$period['end']->format('Y-m-d')}");

        // Check for dry run
        $isDryRun = $this->option('dry-run');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No invoices will be created');
        }

        // Get projects with billable work
        $projects = $this->getProjects($period['start'], $period['end']);

        if ($projects->isEmpty()) {
            $this->warn('No projects with billable work found for this period');

            return Command::SUCCESS;
        }

        $this->info("Found {$projects->count()} projects with billable work");
        $this->newLine();

        // Process each project
        $results = [
            'created' => [],
            'failed' => [],
            'skipped' => [],
        ];

        foreach ($projects as $project) {
            try {
                $result = $this->processProject($project, $period, $isDryRun);

                if ($result['status'] === 'created') {
                    $results['created'][] = $result;
                } elseif ($result['status'] === 'skipped') {
                    $results['skipped'][] = $result;
                } else {
                    $results['failed'][] = $result;
                }
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'project' => $project->name,
                    'error' => $e->getMessage(),
                ];

                $this->error("Failed to process {$project->name}: {$e->getMessage()}");
            }
        }

        // Display summary
        $this->displaySummary($results);

        return Command::SUCCESS;
    }

    /**
     * Get the period for invoice generation.
     */
    private function getPeriod(): array
    {
        $monthOption = $this->option('month');

        if ($monthOption) {
            // Parse provided month
            try {
                $date = Carbon::createFromFormat('Y-m', $monthOption, 'Europe/Zurich');
            } catch (\Exception $e) {
                $this->error('Invalid month format. Use YYYY-MM (e.g., 2025-01)');
                exit(1);
            }
        } else {
            // Use previous month
            $date = Carbon::now('Europe/Zurich')->subMonth();
        }

        return [
            'start' => $date->copy()->startOfMonth()->startOfDay(),
            'end' => $date->copy()->endOfMonth()->endOfDay(),
            'month' => $date->format('Y-m'),
        ];
    }

    /**
     * Get projects to process.
     */
    private function getProjects(Carbon $start, Carbon $end)
    {
        $projectId = $this->option('project');

        if ($projectId) {
            return \App\Models\Project::where('id', $projectId)
                ->whereHas('tickets.worklogs', function ($query) use ($start, $end) {
                    $query->where('status', 'completed')
                        ->whereBetween('started_at', [$start, $end]);
                })->get();
        }

        return $this->aggregator->getProjectsWithBillableWork($start, $end);
    }

    /**
     * Process a single project.
     */
    private function processProject($project, array $period, bool $isDryRun): array
    {
        $this->info("Processing: {$project->name}");

        // Check if invoice already exists for this period
        $existingInvoice = Invoice::where('project_id', $project->id)
            ->whereBetween('period_start', [$period['start'], $period['end']])
            ->first();

        if ($existingInvoice) {
            $this->warn("  → Invoice already exists: {$existingInvoice->invoice_number}");

            return [
                'status' => 'skipped',
                'project' => $project->name,
                'reason' => 'Invoice already exists',
                'invoice_number' => $existingInvoice->invoice_number,
            ];
        }

        // Aggregate worklogs
        $aggregated = $this->aggregator->aggregateForProject(
            $project,
            $period['start'],
            $period['end']
        );

        if ($aggregated['billable_hours'] <= 0) {
            $this->warn('  → No billable hours');

            return [
                'status' => 'skipped',
                'project' => $project->name,
                'reason' => 'No billable hours',
            ];
        }

        $this->info("  → Billable hours: {$aggregated['billable_hours']}");
        $this->info("  → Line items: {$aggregated['items']->count()}");

        if ($isDryRun) {
            $this->info('  → [DRY RUN] Would create invoice');

            return [
                'status' => 'created',
                'project' => $project->name,
                'billable_hours' => $aggregated['billable_hours'],
                'amount' => $aggregated['billable_hours'] * config('billing.unit_price_per_hour'),
                'dry_run' => true,
            ];
        }

        // Create invoice
        $invoice = $this->createInvoice($project, $period, $aggregated);
        $this->info("  → Created invoice: {$invoice->invoice_number}");

        // Generate PDF
        try {
            $pdfPath = $this->pdfGenerator->generate($invoice);
            $this->info("  → PDF generated: {$pdfPath}");
        } catch (\Exception $e) {
            $this->error("  → PDF generation failed: {$e->getMessage()}");
            Log::error('PDF generation failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Send email
        if (! $this->option('no-email')) {
            try {
                if ($this->mailer->send($invoice)) {
                    $this->info('  → Email sent');
                } else {
                    $this->warn('  → Email sending failed');
                }
            } catch (\Exception $e) {
                $this->error("  → Email error: {$e->getMessage()}");
                Log::error('Email sending failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'status' => 'created',
            'project' => $project->name,
            'invoice_number' => $invoice->invoice_number,
            'billable_hours' => $aggregated['billable_hours'],
            'amount' => $invoice->total,
        ];
    }

    /**
     * Create invoice and items.
     */
    private function createInvoice($project, array $period, array $aggregated): Invoice
    {
        return DB::transaction(function () use ($project, $period, $aggregated) {
            // Calculate amounts
            $subtotal = 0;
            foreach ($aggregated['items'] as $item) {
                $subtotal += $item['amount'];
            }

            $taxRate = config('billing.tax_rate', 0.077);
            $taxAmount = round($subtotal * $taxRate, 2);
            $total = $subtotal + $taxAmount;

            // Calculate due date
            $dueDate = Carbon::now()->addDays(config('billing.payment_terms_days', 30));

            // Generate invoice number
            $invoiceNumber = $this->numberGenerator->generate($project, $period['start']);

            // Create invoice
            $invoice = Invoice::create([
                'project_id' => $project->id,
                'invoice_number' => $invoiceNumber,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'due_date' => $dueDate,
                'currency' => config('billing.default_currency', 'CHF'),
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'status' => 'draft',
                'meta' => [
                    'worklog_ids' => $aggregated['worklog_ids'],
                    'total_seconds' => $aggregated['total_seconds'],
                    'total_hours' => $aggregated['total_hours'],
                    'billable_hours' => $aggregated['billable_hours'],
                    'by_phase' => $aggregated['by_phase'],
                    'generated_at' => Carbon::now()->toIso8601String(),
                    'generated_by' => 'monthly_cron',
                ],
            ]);

            // Create invoice items
            foreach ($aggregated['items'] as $itemData) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit' => $itemData['unit'],
                    'unit_price' => $itemData['unit_price'],
                    'amount' => $itemData['amount'],
                    'meta' => $itemData['meta'] ?? [],
                ]);
            }

            Log::info('Invoice created', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'project' => $project->name,
                'total' => $total,
                'items_count' => $aggregated['items']->count(),
            ]);

            return $invoice;
        });
    }

    /**
     * Display summary of results.
     */
    private function displaySummary(array $results): void
    {
        $this->newLine();
        $this->info('========== SUMMARY ==========');

        // Created invoices
        if (! empty($results['created'])) {
            $this->info('✅ Created: '.count($results['created']).' invoices');

            $totalAmount = 0;
            $totalHours = 0;

            foreach ($results['created'] as $invoice) {
                if (isset($invoice['dry_run']) && $invoice['dry_run']) {
                    $this->line("   - {$invoice['project']} [DRY RUN]");
                } else {
                    $this->line("   - {$invoice['project']}: {$invoice['invoice_number']}");
                    $this->line("     Hours: {$invoice['billable_hours']} | Amount: ".
                        config('billing.currency_symbol').' '.
                        number_format((float) $invoice['amount'], 2));
                }

                $totalAmount += $invoice['amount'] ?? 0;
                $totalHours += $invoice['billable_hours'] ?? 0;
            }

            if ($totalAmount > 0) {
                $this->newLine();
                $this->info('Total Hours: '.number_format($totalHours, 2));
                $this->info('Total Amount: '.config('billing.currency_symbol').' '.
                    number_format($totalAmount, 2));
            }
        }

        // Skipped projects
        if (! empty($results['skipped'])) {
            $this->newLine();
            $this->warn('⚠️ Skipped: '.count($results['skipped']).' projects');
            foreach ($results['skipped'] as $skip) {
                $this->line("   - {$skip['project']}: {$skip['reason']}");
            }
        }

        // Failed projects
        if (! empty($results['failed'])) {
            $this->newLine();
            $this->error('❌ Failed: '.count($results['failed']).' projects');
            foreach ($results['failed'] as $fail) {
                $this->line("   - {$fail['project']}: {$fail['error']}");
            }
        }

        $this->newLine();
        $this->info('=============================');
    }
}
