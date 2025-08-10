<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for sending invoice emails.
 */
class InvoiceMailer
{
    public function __construct(
        private InvoicePdfGenerator $pdfGenerator
    ) {}

    /**
     * Send invoice email to admin and configured recipients.
     */
    public function send(Invoice $invoice): bool
    {
        try {
            // Ensure PDF is generated
            if (empty($invoice->pdf_path)) {
                $this->pdfGenerator->generate($invoice);
                $invoice->refresh();
            }

            // Get recipients
            $recipients = $this->getRecipients();

            if (empty($recipients['to'])) {
                Log::warning('No recipients configured for invoice email', [
                    'invoice_id' => $invoice->id,
                ]);

                return false;
            }

            // Prepare email data
            $data = $this->prepareEmailData($invoice);

            // Create mail message
            $mail = Mail::html($this->renderEmailHtml($data));

            // Set recipients
            $mail->to($recipients['to']);
            if (! empty($recipients['cc'])) {
                $mail->cc($recipients['cc']);
            }

            // Set from address
            $mail->from(
                config('billing.email.from_address', 'billing@synapticore.com'),
                config('billing.email.from_name', 'Synapticore Billing')
            );

            // Set subject
            $subject = $this->formatSubject($invoice);
            $mail->subject($subject);

            // Attach PDF if configured
            if (config('billing.email.attach_pdf', true)) {
                $this->attachPdf($mail, $invoice);
            }

            // Send email
            $mail->send();

            // Update invoice status
            $invoice->update([
                'status' => 'sent',
                'sent_at' => Carbon::now(),
                'meta' => array_merge($invoice->meta ?? [], [
                    'email_sent_to' => $recipients['to'],
                    'email_sent_at' => Carbon::now()->toIso8601String(),
                ]),
            ]);

            Log::info('Invoice email sent', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'recipients' => $recipients,
                'subject' => $subject,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send invoice email', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update invoice with error
            $invoice->update([
                'meta' => array_merge($invoice->meta ?? [], [
                    'email_error' => $e->getMessage(),
                    'email_error_at' => Carbon::now()->toIso8601String(),
                ]),
            ]);

            return false;
        }
    }

    /**
     * Get email recipients.
     */
    private function getRecipients(): array
    {
        $to = [];
        $cc = [];

        // Primary admin email
        $adminEmail = config('billing.admin_email');
        if (! empty($adminEmail)) {
            $to[] = $adminEmail;
        }

        // CC emails
        $ccEmails = config('billing.cc_emails');
        if (! empty($ccEmails)) {
            $cc = array_map('trim', explode(',', $ccEmails));
        }

        return [
            'to' => $to,
            'cc' => $cc,
        ];
    }

    /**
     * Prepare data for email template.
     */
    private function prepareEmailData(Invoice $invoice): array
    {
        // Load relationships
        $invoice->load(['project', 'items']);

        // Calculate statistics
        $worklogIds = $invoice->meta['worklog_ids'] ?? [];
        $worklogCount = count($worklogIds);
        $ticketCount = $invoice->items()->distinct('meta->ticket_id')->count();

        // Get period
        $periodStart = Carbon::parse($invoice->period_start);
        $periodEnd = Carbon::parse($invoice->period_end);
        $period = $periodStart->format('d.m.Y').' - '.$periodEnd->format('d.m.Y');

        // Get PDF URL if configured
        $pdfUrl = null;
        if (config('billing.email.include_link', true)) {
            $expiryDays = config('billing.email.link_expiry_days', 90);
            $pdfUrl = $this->pdfGenerator->getSignedUrl($invoice, $expiryDays * 24 * 60);
        }

        return [
            'invoice' => $invoice,
            'project' => $invoice->project,
            'period' => $period,
            'currency' => config('billing.currency_symbol', 'CHF'),
            'total_hours' => $invoice->meta['total_hours'] ?? 0,
            'ticket_count' => $ticketCount,
            'worklog_count' => $worklogCount,
            'pdf_url' => $pdfUrl,
        ];
    }

    /**
     * Render email HTML from template.
     */
    private function renderEmailHtml(array $data): string
    {
        return view('mail.invoice', $data)->render();
    }

    /**
     * Format email subject.
     */
    private function formatSubject(Invoice $invoice): string
    {
        $template = config('billing.email.subject_template', 'Rechnung {INVOICE_NUMBER} - {MONTH} {YEAR}');

        $periodStart = Carbon::parse($invoice->period_start);

        $replacements = [
            '{INVOICE_NUMBER}' => $invoice->invoice_number,
            '{MONTH}' => $periodStart->locale('de')->monthName,
            '{YEAR}' => $periodStart->format('Y'),
            '{PROJECT}' => $invoice->project->name,
            '{AMOUNT}' => number_format($invoice->total, 2).' '.config('billing.currency_symbol', 'CHF'),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );
    }

    /**
     * Attach PDF to mail message.
     */
    private function attachPdf($mail, Invoice $invoice): void
    {
        $pdfContent = $this->pdfGenerator->getPdfContent($invoice);

        if ($pdfContent) {
            $filename = "Rechnung_{$invoice->invoice_number}.pdf";
            $mail->attachData($pdfContent, $filename, [
                'mime' => 'application/pdf',
            ]);
        }
    }

    /**
     * Send reminder email for unpaid invoice.
     */
    public function sendReminder(Invoice $invoice): bool
    {
        try {
            // Check if invoice is overdue
            if ($invoice->status !== 'sent' || ! $invoice->due_date->isPast()) {
                return false;
            }

            // Calculate days overdue
            $daysOverdue = $invoice->due_date->diffInDays(Carbon::now());

            // Prepare custom subject
            $subject = "Zahlungserinnerung: Rechnung {$invoice->invoice_number} - {$daysOverdue} Tage überfällig";

            // Get recipients
            $recipients = $this->getRecipients();

            // Prepare email data with reminder flag
            $data = $this->prepareEmailData($invoice);
            $data['is_reminder'] = true;
            $data['days_overdue'] = $daysOverdue;

            // Create and send mail
            $mail = Mail::html($this->renderEmailHtml($data));
            $mail->to($recipients['to']);
            if (! empty($recipients['cc'])) {
                $mail->cc($recipients['cc']);
            }
            $mail->from(
                config('billing.email.from_address', 'billing@synapticore.com'),
                config('billing.email.from_name', 'Synapticore Billing')
            );
            $mail->subject($subject);

            if (config('billing.email.attach_pdf', true)) {
                $this->attachPdf($mail, $invoice);
            }

            $mail->send();

            // Update invoice metadata
            $invoice->update([
                'meta' => array_merge($invoice->meta ?? [], [
                    'last_reminder_sent_at' => Carbon::now()->toIso8601String(),
                    'reminder_count' => ($invoice->meta['reminder_count'] ?? 0) + 1,
                ]),
            ]);

            Log::info('Invoice reminder sent', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'days_overdue' => $daysOverdue,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send invoice reminder', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send batch of invoices.
     */
    public function sendBatch(array $invoiceIds): array
    {
        $results = [
            'sent' => [],
            'failed' => [],
        ];

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::find($invoiceId);

            if (! $invoice) {
                $results['failed'][] = [
                    'id' => $invoiceId,
                    'error' => 'Invoice not found',
                ];

                continue;
            }

            if ($this->send($invoice)) {
                $results['sent'][] = $invoiceId;
            } else {
                $results['failed'][] = [
                    'id' => $invoiceId,
                    'error' => 'Send failed',
                ];
            }
        }

        Log::info('Batch invoice email results', $results);

        return $results;
    }
}
