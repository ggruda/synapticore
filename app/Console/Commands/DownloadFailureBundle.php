<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\SelfHealing\FailureCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Command to download the latest failure bundle for a ticket.
 */
class DownloadFailureBundle extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synaptic:failure:last 
                            {ticket : Ticket ID or external key}
                            {--output= : Output file path (default: failure_bundle_TICKET.json)}
                            {--with-files : Also download associated workspace files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download the latest failure bundle for a ticket';

    /**
     * Execute the console command.
     */
    public function handle(FailureCollector $failureCollector): int
    {
        $ticketIdentifier = $this->argument('ticket');

        // Find ticket by ID or external key
        $ticket = is_numeric($ticketIdentifier)
            ? Ticket::find($ticketIdentifier)
            : Ticket::where('external_key', $ticketIdentifier)->first();

        if (! $ticket) {
            $this->error("Ticket not found: {$ticketIdentifier}");

            return Command::FAILURE;
        }

        $this->info("📋 Ticket: {$ticket->external_key} - {$ticket->title}");
        $this->newLine();

        // Get latest failure bundle
        $bundle = $failureCollector->getLatestBundle($ticket);

        if (! $bundle) {
            $this->warn('No failure bundles found for this ticket');

            // Check if workflow has failures
            if ($ticket->workflow && isset($ticket->workflow->meta['failures'])) {
                $failures = $ticket->workflow->meta['failures'];
                $this->info('Found '.count($failures).' recorded failures:');

                foreach ($failures as $failure) {
                    $this->line('  - '.$failure['timestamp'].': '.$failure['message']);
                }
            }

            return Command::FAILURE;
        }

        // Determine output path
        $outputPath = $this->option('output')
            ?? "failure_bundle_{$ticket->external_key}_".date('Y-m-d_H-i-s').'.json';

        // Save bundle to local file
        File::put($outputPath, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("✅ Failure bundle saved to: {$outputPath}");

        // Display bundle summary
        $this->displayBundleSummary($bundle);

        // Download associated files if requested
        if ($this->option('with-files')) {
            $this->downloadAssociatedFiles($ticket, $bundle, dirname($outputPath));
        }

        return Command::SUCCESS;
    }

    /**
     * Display bundle summary.
     */
    private function displayBundleSummary(array $bundle): void
    {
        $this->newLine();
        $this->info('📊 Bundle Summary');
        $this->table(
            ['Field', 'Value'],
            [
                ['Version', $bundle['version'] ?? 'unknown'],
                ['Timestamp', $bundle['timestamp'] ?? 'unknown'],
                ['Job', $bundle['failure']['job'] ?? 'unknown'],
                ['Exception', $bundle['failure']['exception']['class'] ?? 'unknown'],
                ['Message', substr($bundle['failure']['exception']['message'] ?? '', 0, 60).'...'],
            ]
        );

        // Show suggestions
        if (! empty($bundle['suggestions'])) {
            $this->newLine();
            $this->info('💡 Repair Suggestions:');

            foreach ($bundle['suggestions'] as $suggestion) {
                $this->line("  [{$suggestion['priority']}] {$suggestion['type']}: {$suggestion['action']}");

                if (isset($suggestion['commands'])) {
                    foreach ($suggestion['commands'] as $command) {
                        $this->line("    → {$command}");
                    }
                }
            }
        }

        // Show artifacts
        if (! empty($bundle['artifacts'])) {
            $this->newLine();
            $this->info('📁 Artifacts ('.count($bundle['artifacts']).'):');

            foreach ($bundle['artifacts'] as $artifact) {
                $this->line("  - [{$artifact['type']}] {$artifact['path']}");
            }
        }

        // Show code context
        if (! empty($bundle['code_context'])) {
            $this->newLine();
            $this->info('🔍 Code Context ('.count($bundle['code_context']).' items)');

            foreach (array_slice($bundle['code_context'], 0, 3) as $context) {
                $this->line("  - Relevance: {$context['relevance']} | ".
                    ($context['metadata']['file_path'] ?? 'unknown'));
            }
        }
    }

    /**
     * Download associated files from the bundle.
     */
    private function downloadAssociatedFiles(Ticket $ticket, array $bundle, string $outputDir): void
    {
        $this->newLine();
        $this->info('📥 Downloading associated files...');

        // Create output directory
        $filesDir = $outputDir.'/bundle_files';
        if (! File::exists($filesDir)) {
            File::makeDirectory($filesDir, 0755, true);
        }

        $disk = Storage::disk(config('filesystems.default') === 'spaces' ? 'spaces' : 'local');
        $downloadedCount = 0;

        // Download artifacts
        foreach ($bundle['artifacts'] ?? [] as $artifact) {
            if (isset($artifact['path']) && $disk->exists($artifact['path'])) {
                try {
                    $content = $disk->get($artifact['path']);
                    $localPath = $filesDir.'/'.basename($artifact['path']);
                    File::put($localPath, $content);
                    $this->line('  ✓ Downloaded: '.basename($artifact['path']));
                    $downloadedCount++;
                } catch (\Exception $e) {
                    $this->warn("  ✗ Failed to download: {$artifact['path']}");
                }
            }
        }

        // Download workspace files if they exist in storage
        $bundlePath = $bundle['bundle_path'] ?? null;
        if ($bundlePath) {
            $bundleDir = dirname($bundlePath);
            $workspaceFilesPath = $bundleDir.'/workspace_files';

            if ($disk->exists($workspaceFilesPath)) {
                $files = $disk->files($workspaceFilesPath);

                foreach ($files as $file) {
                    try {
                        $content = $disk->get($file);
                        $localPath = $filesDir.'/workspace/'.basename($file);

                        if (! File::exists(dirname($localPath))) {
                            File::makeDirectory(dirname($localPath), 0755, true);
                        }

                        File::put($localPath, $content);
                        $this->line('  ✓ Downloaded workspace file: '.basename($file));
                        $downloadedCount++;
                    } catch (\Exception $e) {
                        $this->warn("  ✗ Failed to download: {$file}");
                    }
                }
            }
        }

        $this->info("Downloaded {$downloadedCount} files to: {$filesDir}");
    }
}
