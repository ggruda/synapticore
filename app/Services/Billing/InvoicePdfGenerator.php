<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service for generating invoice PDFs.
 */
class InvoicePdfGenerator
{
    /**
     * Generate PDF for an invoice.
     *
     * @return string The path to the generated PDF
     */
    public function generate(Invoice $invoice): string
    {
        try {
            // Prepare data for the view
            $data = $this->prepareInvoiceData($invoice);

            // Generate PDF
            $pdf = Pdf::loadView('invoices.pdf', $data);

            // Configure PDF settings
            $pdf->setPaper(
                config('billing.pdf.paper_size', 'A4'),
                config('billing.pdf.orientation', 'portrait')
            );

            // Generate filename
            $filename = $this->generateFilename($invoice);

            // Save to local storage first
            $localPath = "temp/invoices/{$filename}";
            Storage::disk('local')->put($localPath, $pdf->output());

            // Upload to Spaces/S3
            $remotePath = $this->uploadToSpaces($invoice, $localPath, $filename);

            // Clean up local file
            Storage::disk('local')->delete($localPath);

            // Update invoice with PDF path
            $invoice->update([
                'pdf_path' => $remotePath,
                'pdf_generated_at' => Carbon::now(),
            ]);

            Log::info('Invoice PDF generated', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'path' => $remotePath,
            ]);

            return $remotePath;

        } catch (\Exception $e) {
            Log::error('Failed to generate invoice PDF', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Prepare data for the invoice view.
     */
    private function prepareInvoiceData(Invoice $invoice): array
    {
        // Load relationships
        $invoice->load(['project', 'items']);

        // Get period dates
        $period = [
            'start' => Carbon::parse($invoice->period_start),
            'end' => Carbon::parse($invoice->period_end),
        ];

        // Get addresses
        $from = config('billing.invoice_address_from');
        $to = $this->getClientAddress($invoice);

        // Get bank details
        $bank = config('billing.bank_details');

        // Currency
        $currency = config('billing.currency_symbol', 'CHF');

        return [
            'invoice' => $invoice,
            'project' => $invoice->project,
            'items' => $invoice->items,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'bank' => $bank,
            'currency' => $currency,
        ];
    }

    /**
     * Get client address for invoice.
     */
    private function getClientAddress(Invoice $invoice): array
    {
        // Check if project has custom billing address
        $projectMeta = $invoice->project->meta ?? [];

        if (! empty($projectMeta['billing_address'])) {
            return $projectMeta['billing_address'];
        }

        // Use default from config
        return config('billing.invoice_address_to');
    }

    /**
     * Generate filename for the PDF.
     */
    private function generateFilename(Invoice $invoice): string
    {
        $number = Str::slug($invoice->invoice_number);
        $project = Str::slug($invoice->project->name);
        $date = Carbon::now()->format('Y-m-d');

        return "{$number}_{$project}_{$date}.pdf";
    }

    /**
     * Upload PDF to Spaces/S3.
     */
    private function uploadToSpaces(Invoice $invoice, string $localPath, string $filename): string
    {
        $disk = config('billing.storage.disk', 'spaces');
        $pathTemplate = config('billing.storage.path', 'invoices/{YEAR}/{MONTH}');

        // Build remote path
        $remotePath = str_replace(
            ['{YEAR}', '{MONTH}', '{DAY}'],
            [
                Carbon::now()->format('Y'),
                Carbon::now()->format('m'),
                Carbon::now()->format('d'),
            ],
            $pathTemplate
        );

        $remotePath = trim($remotePath, '/').'/'.$filename;

        // Upload file
        $contents = Storage::disk('local')->get($localPath);

        $uploaded = Storage::disk($disk)->put(
            $remotePath,
            $contents,
            config('billing.storage.public', false) ? 'public' : 'private'
        );

        if (! $uploaded) {
            throw new \Exception("Failed to upload PDF to {$disk}");
        }

        Log::info('Invoice PDF uploaded to storage', [
            'invoice_id' => $invoice->id,
            'disk' => $disk,
            'path' => $remotePath,
        ]);

        return $remotePath;
    }

    /**
     * Generate a temporary signed URL for the PDF.
     */
    public function getSignedUrl(Invoice $invoice, int $expiryMinutes = 60): ?string
    {
        if (empty($invoice->pdf_path)) {
            return null;
        }

        $disk = config('billing.storage.disk', 'spaces');

        try {
            if (method_exists(Storage::disk($disk), 'temporaryUrl')) {
                return Storage::disk($disk)->temporaryUrl(
                    $invoice->pdf_path,
                    now()->addMinutes($expiryMinutes)
                );
            }

            // Fallback to regular URL if temporary URLs not supported
            return Storage::disk($disk)->url($invoice->pdf_path);

        } catch (\Exception $e) {
            Log::error('Failed to generate signed URL for invoice PDF', [
                'invoice_id' => $invoice->id,
                'path' => $invoice->pdf_path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Regenerate PDF for an existing invoice.
     */
    public function regenerate(Invoice $invoice): string
    {
        // Delete old PDF if exists
        if (! empty($invoice->pdf_path)) {
            $this->deletePdf($invoice);
        }

        // Generate new PDF
        return $this->generate($invoice);
    }

    /**
     * Delete PDF file for an invoice.
     */
    public function deletePdf(Invoice $invoice): void
    {
        if (empty($invoice->pdf_path)) {
            return;
        }

        $disk = config('billing.storage.disk', 'spaces');

        try {
            Storage::disk($disk)->delete($invoice->pdf_path);

            $invoice->update([
                'pdf_path' => null,
                'pdf_generated_at' => null,
            ]);

            Log::info('Invoice PDF deleted', [
                'invoice_id' => $invoice->id,
                'path' => $invoice->pdf_path,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete invoice PDF', [
                'invoice_id' => $invoice->id,
                'path' => $invoice->pdf_path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get PDF content as string.
     */
    public function getPdfContent(Invoice $invoice): ?string
    {
        if (empty($invoice->pdf_path)) {
            return null;
        }

        $disk = config('billing.storage.disk', 'spaces');

        try {
            return Storage::disk($disk)->get($invoice->pdf_path);
        } catch (\Exception $e) {
            Log::error('Failed to get invoice PDF content', [
                'invoice_id' => $invoice->id,
                'path' => $invoice->pdf_path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
