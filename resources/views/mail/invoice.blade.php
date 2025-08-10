<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rechnung {{ $invoice->invoice_number }}</title>
    <style>
        /* Reset styles */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        
        /* Mobile styles */
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; padding: 10px !important; }
            .content { padding: 20px !important; }
            .button { width: 100% !important; text-align: center !important; }
            .stats-grid { grid-template-columns: 1fr !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f7f8fa;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f7f8fa; padding: 40px 0;">
        <tr>
            <td align="center">
                <table class="container" width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, {{ config('billing.design.primary_color', '#1E3A8A') }}, {{ config('billing.design.secondary_color', '#06B6D4') }}); padding: 40px; border-radius: 12px 12px 0 0; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 32px; font-weight: bold;">SYNAPTICORE</h1>
                            <p style="color: #ffffff; margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;">Intelligent Automation Solutions</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding: 40px;">
                            <!-- Greeting -->
                            <h2 style="color: {{ config('billing.design.text_color', '#1F2937') }}; margin: 0 0 20px 0; font-size: 24px;">
                                Neue Rechnung verfügbar
                            </h2>
                            
                            <p style="color: #6B7280; line-height: 1.6; margin: 0 0 30px 0;">
                                Guten Tag,<br><br>
                                Ihre monatliche Rechnung für <strong>{{ $project->name }}</strong> wurde erstellt und steht zum Download bereit.
                            </p>
                            
                            <!-- Invoice Info Box -->
                            <div style="background-color: #F9FAFB; border-left: 4px solid {{ config('billing.design.secondary_color', '#06B6D4') }}; padding: 20px; margin: 0 0 30px 0; border-radius: 4px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="color: #6B7280; padding: 5px 0;">Rechnungsnummer:</td>
                                        <td style="color: {{ config('billing.design.text_color', '#1F2937') }}; font-weight: bold; text-align: right;">{{ $invoice->invoice_number }}</td>
                                    </tr>
                                    <tr>
                                        <td style="color: #6B7280; padding: 5px 0;">Rechnungsdatum:</td>
                                        <td style="color: {{ config('billing.design.text_color', '#1F2937') }}; text-align: right;">{{ $invoice->created_at->format('d.m.Y') }}</td>
                                    </tr>
                                    <tr>
                                        <td style="color: #6B7280; padding: 5px 0;">Leistungszeitraum:</td>
                                        <td style="color: {{ config('billing.design.text_color', '#1F2937') }}; text-align: right;">{{ $period }}</td>
                                    </tr>
                                    <tr>
                                        <td style="color: #6B7280; padding: 5px 0;">Fälligkeitsdatum:</td>
                                        <td style="color: {{ config('billing.design.text_color', '#1F2937') }}; text-align: right;">{{ $invoice->due_date->format('d.m.Y') }}</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Amount Box -->
                            <div style="background: linear-gradient(135deg, {{ config('billing.design.primary_color', '#1E3A8A') }}10, {{ config('billing.design.secondary_color', '#06B6D4') }}10); padding: 25px; margin: 0 0 30px 0; border-radius: 8px; text-align: center;">
                                <p style="color: #6B7280; margin: 0 0 10px 0; font-size: 14px;">Gesamtbetrag</p>
                                <p style="color: {{ config('billing.design.primary_color', '#1E3A8A') }}; margin: 0; font-size: 36px; font-weight: bold;">
                                    {{ $currency }} {{ number_format($invoice->total, 2) }}
                                </p>
                                <p style="color: #6B7280; margin: 10px 0 0 0; font-size: 12px;">
                                    inkl. {{ config('billing.tax_name', 'MwSt') }} ({{ number_format($invoice->tax_rate * 100, 1) }}%)
                                </p>
                            </div>
                            
                            <!-- Statistics Grid -->
                            <div class="stats-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin: 0 0 30px 0;">
                                <div style="text-align: center; padding: 15px; background-color: #F9FAFB; border-radius: 8px;">
                                    <p style="color: {{ config('billing.design.secondary_color', '#06B6D4') }}; font-size: 24px; font-weight: bold; margin: 0;">{{ number_format($total_hours, 1) }}h</p>
                                    <p style="color: #6B7280; font-size: 12px; margin: 5px 0 0 0;">Gearbeitete Stunden</p>
                                </div>
                                <div style="text-align: center; padding: 15px; background-color: #F9FAFB; border-radius: 8px;">
                                    <p style="color: {{ config('billing.design.secondary_color', '#06B6D4') }}; font-size: 24px; font-weight: bold; margin: 0;">{{ $ticket_count }}</p>
                                    <p style="color: #6B7280; font-size: 12px; margin: 5px 0 0 0;">Bearbeitete Tickets</p>
                                </div>
                                <div style="text-align: center; padding: 15px; background-color: #F9FAFB; border-radius: 8px;">
                                    <p style="color: {{ config('billing.design.secondary_color', '#06B6D4') }}; font-size: 24px; font-weight: bold; margin: 0;">{{ $worklog_count }}</p>
                                    <p style="color: #6B7280; font-size: 12px; margin: 5px 0 0 0;">Zeiteinträge</p>
                                </div>
                            </div>
                            
                            <!-- Download Button -->
                            @if($pdf_url)
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $pdf_url }}" class="button" style="display: inline-block; padding: 16px 32px; background: linear-gradient(135deg, {{ config('billing.design.primary_color', '#1E3A8A') }}, {{ config('billing.design.secondary_color', '#06B6D4') }}); color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 8px; font-size: 16px;">
                                            Rechnung herunterladen
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="color: #9CA3AF; font-size: 12px; text-align: center; margin: 0 0 30px 0;">
                                Der Download-Link ist {{ config('billing.email.link_expiry_days', 90) }} Tage gültig.
                            </p>
                            @endif
                            
                            <!-- Payment Information -->
                            <div style="background-color: #FFFBEB; border-left: 4px solid #F59E0B; padding: 20px; margin: 0 0 30px 0; border-radius: 4px;">
                                <h3 style="color: #92400E; margin: 0 0 15px 0; font-size: 16px;">Zahlungsinformationen</h3>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="color: #78350F; padding: 3px 0;">Bank:</td>
                                        <td style="color: #78350F; text-align: right;">{{ config('billing.bank_details.bank_name') }}</td>
                                    </tr>
                                    <tr>
                                        <td style="color: #78350F; padding: 3px 0;">IBAN:</td>
                                        <td style="color: #78350F; text-align: right; font-family: monospace;">{{ config('billing.bank_details.iban') }}</td>
                                    </tr>
                                    <tr>
                                        <td style="color: #78350F; padding: 3px 0;">Verwendungszweck:</td>
                                        <td style="color: #78350F; text-align: right; font-weight: bold;">{{ $invoice->invoice_number }}</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Help Text -->
                            <p style="color: #6B7280; line-height: 1.6; margin: 0;">
                                Bei Fragen zu dieser Rechnung wenden Sie sich bitte an unser Billing-Team unter 
                                <a href="mailto:{{ config('billing.invoice_address_from.email') }}" style="color: {{ config('billing.design.secondary_color', '#06B6D4') }};">{{ config('billing.invoice_address_from.email') }}</a>.
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #F9FAFB; padding: 30px; border-radius: 0 0 12px 12px; text-align: center;">
                            <p style="color: #6B7280; margin: 0 0 10px 0; font-size: 14px;">
                                <strong>{{ config('billing.invoice_address_from.company') }}</strong>
                            </p>
                            <p style="color: #9CA3AF; margin: 0 0 5px 0; font-size: 12px;">
                                {{ config('billing.invoice_address_from.address') }} | {{ config('billing.invoice_address_from.city') }}
                            </p>
                            <p style="color: #9CA3AF; margin: 0 0 5px 0; font-size: 12px;">
                                {{ config('billing.invoice_address_from.phone') }} | {{ config('billing.invoice_address_from.website') }}
                            </p>
                            @if(config('billing.invoice_address_from.vat_number'))
                            <p style="color: #9CA3AF; margin: 0; font-size: 12px;">
                                {{ config('billing.invoice_address_from.vat_number') }}
                            </p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>