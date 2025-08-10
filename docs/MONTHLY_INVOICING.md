# Monthly Invoicing System

## Overview

This document describes the automated monthly invoicing system that generates, stores, and sends PDF invoices for billable work tracked through worklogs.

## Features Implemented (Prompt 18)

### 1. Billing Configuration

The `config/billing.php` file contains all billing-related settings:

- **Company Details**: From/To addresses, VAT numbers
- **Pricing**: Currency (CHF), hourly rate (150.00 CHF)
- **Tax**: Swiss VAT 7.7% configurable
- **Payment Terms**: 30 days default
- **Bank Details**: IBAN, BIC, account holder
- **Invoice Numbering**: Format `SC-YYYYMM-SEQ` (e.g., SC-202508-001)
- **Hour Rounding**: Rounds to nearest 0.25h with 0.25h minimum
- **Corporate Design**: Synapticore blue (#1E3A8A) and turquoise (#06B6D4)

### 2. Services

#### WorklogAggregator (`app/Services/Billing/WorklogAggregator.php`)

- Collects worklogs for a project within date range
- Converts seconds to hours
- Applies configurable rounding (default: 0.25h increments)
- Groups by phase and ticket
- Creates line items for invoice

```php
$aggregated = $aggregator->aggregateForProject($project, $startDate, $endDate);
// Returns:
[
    'total_seconds' => 12600,
    'total_hours' => 3.5,
    'billable_hours' => 3.5,  // Rounded
    'items' => Collection,     // Line items
    'worklog_ids' => [1,2,3], // For audit
    'by_phase' => [...],
    'by_ticket' => [...],
]
```

#### InvoiceNumberGenerator (`app/Services/Billing/InvoiceNumberGenerator.php`)

- Generates unique invoice numbers: `SC-YYYYMM-SEQ`
- Sequence resets monthly
- Thread-safe with database locking
- Customizable format via config

```php
$number = $generator->generate($project, Carbon::now());
// Returns: "SC-202508-001"
```

#### InvoicePdfGenerator (`app/Services/Billing/InvoicePdfGenerator.php`)

- Renders professional PDF using Blade template
- Corporate design with Synapticore colors
- Saves to local storage temporarily
- Uploads to DO Spaces for permanent storage
- Generates signed URLs for secure access

```php
$pdfPath = $pdfGenerator->generate($invoice);
$signedUrl = $pdfGenerator->getSignedUrl($invoice, 90); // 90 days expiry
```

#### InvoiceMailer (`app/Services/Billing/InvoiceMailer.php`)

- Sends invoice emails to configured recipients
- Attaches PDF or includes download link
- Responsive HTML email template
- Retry logic for transient failures
- Support for reminders

```php
$success = $mailer->send($invoice);
$mailer->sendReminder($overdueInvoice);
```

### 3. Views

#### PDF Template (`resources/views/invoices/pdf.blade.php`)

Professional invoice layout featuring:
- Corporate header with logo
- From/To addresses
- Invoice details table
- Line items with descriptions
- Subtotal, tax, and total
- Payment information with IBAN
- Footer with company details

#### Email Template (`resources/views/mail/invoice.blade.php`)

Responsive email design including:
- Gradient header with branding
- Invoice summary box
- Amount highlight
- Statistics grid (hours, tickets, worklogs)
- Download button
- Payment instructions

### 4. Command & Scheduling

#### GenerateMonthlyInvoices Command

```bash
# Generate for previous month
php artisan invoices:generate-monthly

# Generate for specific month
php artisan invoices:generate-monthly --month=2025-07

# Dry run (no creation)
php artisan invoices:generate-monthly --dry-run

# Generate without sending emails
php artisan invoices:generate-monthly --no-email

# Generate for specific project only
php artisan invoices:generate-monthly --project=1
```

#### Automatic Scheduling

Configured in `routes/console.php`:

```php
Schedule::command('invoices:generate-monthly')
    ->monthlyOn(1, '03:00')
    ->timezone('Europe/Zurich')
    ->appendOutputTo(storage_path('logs/invoices.log'))
    ->emailOutputOnFailure(config('billing.admin_email'));
```

Runs automatically on the 1st of each month at 3:00 AM.

### 5. Database Schema

#### Invoices Table

- `invoice_number` - Unique invoice number
- `period_start/end` - Billing period
- `due_date` - Payment due date
- `subtotal` - Amount before tax
- `tax_rate` - Applied tax rate
- `tax_amount` - Calculated tax
- `total` - Total amount due
- `pdf_path` - Storage path of PDF
- `sent_at` - When email was sent
- `meta` - JSON with worklog IDs, statistics

#### Invoice Items Table

- `description` - Line item description
- `quantity` - Billable hours
- `unit` - Unit of measure (Stunden)
- `unit_price` - Price per hour
- `amount` - Line total
- `meta` - Ticket info, actual hours

## Usage Examples

### Manual Invoice Generation

```bash
# Test with dry run first
docker compose exec app php artisan invoices:generate-monthly --dry-run

# Generate for last month
docker compose exec app php artisan invoices:generate-monthly

# Generate for specific month without email
docker compose exec app php artisan invoices:generate-monthly --month=2025-07 --no-email
```

### Example Output

```
🧾 Starting monthly invoice generation

Period: 2025-07-01 to 2025-07-31
Found 3 projects with billable work

Processing: Mobile App Project
  → Billable hours: 42.75
  → Line items: 8
  → Created invoice: SC-202507-001
  → PDF generated: invoices/2025/07/sc-202507-001_mobile-app_2025-07-31.pdf
  → Email sent

========== SUMMARY ==========
✅ Created: 3 invoices
   - Mobile App Project: SC-202507-001
     Hours: 42.75 | Amount: CHF 6,412.50
   - API Gateway Project: SC-202507-002
     Hours: 28.50 | Amount: CHF 4,275.00
   - Website Redesign: SC-202507-003
     Hours: 15.25 | Amount: CHF 2,287.50

Total Hours: 86.50
Total Amount: CHF 12,975.00
```

## Configuration

### Environment Variables

```env
# Billing Admin
BILLING_ADMIN_EMAIL=admin@company.com
BILLING_CC_EMAILS=finance@company.com,manager@company.com

# Pricing
BILLING_CURRENCY=CHF
BILLING_PRICE_PER_HOUR=150.00
BILLING_TAX_RATE=0.077

# Company Details
BILLING_FROM_COMPANY="Synapticore AG"
BILLING_FROM_ADDRESS="Technoparkstrasse 1"
BILLING_FROM_CITY="8005 Zürich"
BILLING_FROM_VAT="CHE-123.456.789 MWST"

# Bank Details
BILLING_BANK_NAME="PostFinance AG"
BILLING_IBAN="CH12 3456 7890 1234 5678 9"
BILLING_BIC="POFICHBEXXX"

# Hour Rounding
BILLING_ROUND_HOURS=true
BILLING_ROUND_INCREMENT=0.25
BILLING_MIN_HOURS=0.25

# Storage
BILLING_STORAGE_DISK=spaces
BILLING_STORAGE_PATH=invoices/{YEAR}/{MONTH}
```

### DO Spaces Configuration

For production, configure DO Spaces in `config/filesystems.php`:

```php
'spaces' => [
    'driver' => 's3',
    'key' => env('DO_SPACES_KEY'),
    'secret' => env('DO_SPACES_SECRET'),
    'region' => env('DO_SPACES_REGION', 'fra1'),
    'bucket' => env('DO_SPACES_BUCKET'),
    'endpoint' => env('DO_SPACES_ENDPOINT'),
    'use_path_style_endpoint' => false,
],
```

## Acceptance Criteria ✅

1. **Monthly Generation**: On the 1st of each month, invoices are automatically generated for the previous month ✅
2. **PDF Generation**: Professional PDFs with corporate design are created ✅
3. **Storage**: PDFs are uploaded to DO Spaces (or configured storage) ✅
4. **Email Delivery**: Emails sent to admin with PDF attachment or download link ✅
5. **Correct Calculations**: Totals and tax calculated correctly ✅
6. **Audit Trail**: Each invoice references aggregated worklog IDs in metadata ✅

## Testing

### Generate Test Data

```bash
# Create test worklogs for current month
docker compose exec app php artisan worklog:test --test-tracking

# Generate invoice for current month (test)
docker compose exec app php artisan invoices:generate-monthly --month=2025-08 --dry-run
```

### Verify Scheduler

```bash
# List scheduled tasks
docker compose exec app php artisan schedule:list

# Run scheduler manually
docker compose exec app php artisan schedule:run
```

## Troubleshooting

### PDF Generation Fails

1. Check DomPDF is installed: `composer require barryvdh/laravel-dompdf`
2. Verify storage permissions: `storage/app/temp/invoices/`
3. Check Spaces/S3 credentials are configured

### Email Not Sending

1. Verify mail configuration in `.env`
2. Check `BILLING_ADMIN_EMAIL` is set
3. Review logs: `storage/logs/laravel.log`

### Invoice Number Conflicts

The system uses database locking to prevent duplicates. If conflicts occur:
1. Check for concurrent jobs
2. Review the `invoices` table for duplicate numbers
3. The system will auto-increment on collision

## Security Considerations

- **PDF Access**: Uses signed URLs with expiry (default 90 days)
- **Storage**: PDFs stored in private buckets
- **Email**: Sensitive data not included in email body
- **Audit**: Complete worklog ID tracking for verification
- **Retention**: Configurable retention period (default 7 years)

## Future Enhancements

1. **Multi-currency Support**: Handle different currencies per project
2. **Custom Templates**: Per-client invoice templates
3. **Payment Gateway Integration**: Accept online payments
4. **Dunning Process**: Automated payment reminders
5. **Credit Notes**: Handle refunds and adjustments
6. **Recurring Invoices**: Support for subscriptions
7. **Invoice Portal**: Client self-service portal
8. **Accounting Integration**: Export to QuickBooks, Xero, etc.