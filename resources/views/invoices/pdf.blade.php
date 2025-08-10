<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rechnung {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: {{ config('billing.design.text_color', '#1F2937') }};
        }
        
        .container {
            padding: 40px;
            max-width: 210mm;
            margin: 0 auto;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            border-bottom: 3px solid {{ config('billing.design.primary_color', '#1E3A8A') }};
            padding-bottom: 20px;
        }
        
        .logo-section {
            flex: 1;
        }
        
        .logo {
            font-size: 24pt;
            font-weight: bold;
            color: {{ config('billing.design.primary_color', '#1E3A8A') }};
            margin-bottom: 5px;
        }
        
        .tagline {
            color: {{ config('billing.design.secondary_color', '#06B6D4') }};
            font-size: 10pt;
            font-style: italic;
        }
        
        .invoice-info {
            text-align: right;
            flex: 1;
        }
        
        .invoice-number {
            font-size: 18pt;
            font-weight: bold;
            color: {{ config('billing.design.primary_color', '#1E3A8A') }};
            margin-bottom: 10px;
        }
        
        .invoice-date {
            font-size: 10pt;
            color: #6B7280;
        }
        
        /* Addresses */
        .addresses {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        
        .address-block {
            flex: 1;
        }
        
        .address-label {
            font-size: 9pt;
            color: #6B7280;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        
        .address-content {
            line-height: 1.6;
        }
        
        .company-name {
            font-weight: bold;
            color: {{ config('billing.design.primary_color', '#1E3A8A') }};
            margin-bottom: 5px;
        }
        
        /* Invoice Details */
        .invoice-details {
            background: {{ config('billing.design.light_gray', '#F3F4F6') }};
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .detail-label {
            font-weight: bold;
            color: #6B7280;
        }
        
        .detail-value {
            color: {{ config('billing.design.text_color', '#1F2937') }};
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            margin-bottom: 30px;
            border-collapse: collapse;
        }
        
        .items-table thead {
            background: {{ config('billing.design.primary_color', '#1E3A8A') }};
            color: white;
        }
        
        .items-table th {
            padding: 12px;
            text-align: left;
            font-weight: bold;
            font-size: 10pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .items-table th:last-child {
            text-align: right;
        }
        
        .items-table tbody tr {
            border-bottom: 1px solid {{ config('billing.design.border_color', '#E5E7EB') }};
        }
        
        .items-table tbody tr:hover {
            background: {{ config('billing.design.light_gray', '#F3F4F6') }};
        }
        
        .items-table td {
            padding: 12px;
            font-size: 10pt;
        }
        
        .items-table td:last-child {
            text-align: right;
            font-weight: bold;
        }
        
        .item-description {
            color: {{ config('billing.design.text_color', '#1F2937') }};
            font-weight: 500;
        }
        
        .item-details {
            font-size: 9pt;
            color: #6B7280;
            margin-top: 3px;
        }
        
        /* Totals */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 40px;
        }
        
        .totals-table {
            width: 300px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid {{ config('billing.design.border_color', '#E5E7EB') }};
        }
        
        .total-row.grand-total {
            border-bottom: 3px double {{ config('billing.design.primary_color', '#1E3A8A') }};
            border-top: 1px solid {{ config('billing.design.border_color', '#E5E7EB') }};
            margin-top: 10px;
            padding-top: 15px;
            font-size: 14pt;
            font-weight: bold;
            color: {{ config('billing.design.primary_color', '#1E3A8A') }};
        }
        
        .total-label {
            color: #6B7280;
        }
        
        .total-value {
            font-weight: bold;
            text-align: right;
        }
        
        /* Payment Information */
        .payment-section {
            background: linear-gradient(135deg, {{ config('billing.design.primary_color', '#1E3A8A') }}10, {{ config('billing.design.secondary_color', '#06B6D4') }}10);
            border-left: 4px solid {{ config('billing.design.secondary_color', '#06B6D4') }};
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .payment-title {
            font-size: 12pt;
            font-weight: bold;
            color: {{ config('billing.design.primary_color', '#1E3A8A') }};
            margin-bottom: 15px;
        }
        
        .bank-details {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 10px;
        }
        
        .bank-label {
            font-weight: bold;
            color: #6B7280;
        }
        
        .bank-value {
            color: {{ config('billing.design.text_color', '#1F2937') }};
        }
        
        .iban {
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }
        
        /* Footer */
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid {{ config('billing.design.border_color', '#E5E7EB') }};
            text-align: center;
            font-size: 9pt;
            color: #6B7280;
        }
        
        .footer-company {
            font-weight: bold;
            color: {{ config('billing.design.primary_color', '#1E3A8A') }};
            margin-bottom: 5px;
        }
        
        /* Notes */
        .notes-section {
            margin-top: 30px;
            padding: 15px;
            background: #FFFBEB;
            border-left: 4px solid #F59E0B;
            border-radius: 4px;
        }
        
        .notes-title {
            font-weight: bold;
            color: #92400E;
            margin-bottom: 8px;
        }
        
        .notes-content {
            color: #78350F;
            font-size: 10pt;
        }
        
        /* Page break for printing */
        @media print {
            .container {
                padding: 20px;
            }
            
            .items-table tbody tr:hover {
                background: transparent;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo-section">
                <div class="logo">SYNAPTICORE</div>
                <div class="tagline">Intelligent Automation Solutions</div>
            </div>
            <div class="invoice-info">
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
                <div class="invoice-date">{{ $invoice->created_at->format('d.m.Y') }}</div>
            </div>
        </div>
        
        <!-- Addresses -->
        <div class="addresses">
            <div class="address-block">
                <div class="address-label">Von</div>
                <div class="address-content">
                    <div class="company-name">{{ $from['company'] }}</div>
                    <div>{{ $from['address'] }}</div>
                    <div>{{ $from['city'] }}</div>
                    <div>{{ $from['country'] }}</div>
                    @if(!empty($from['vat_number']))
                    <div style="margin-top: 10px;">{{ $from['vat_number'] }}</div>
                    @endif
                </div>
            </div>
            
            <div class="address-block" style="text-align: right;">
                <div class="address-label">An</div>
                <div class="address-content">
                    <div class="company-name">{{ $to['company'] ?? $project->name }}</div>
                    <div>{{ $to['address'] ?? '' }}</div>
                    <div>{{ $to['city'] ?? '' }}</div>
                    <div>{{ $to['country'] ?? '' }}</div>
                </div>
            </div>
        </div>
        
        <!-- Invoice Details -->
        <div class="invoice-details">
            <div class="detail-row">
                <span class="detail-label">Rechnungsnummer:</span>
                <span class="detail-value">{{ $invoice->invoice_number }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Rechnungsdatum:</span>
                <span class="detail-value">{{ $invoice->created_at->format('d.m.Y') }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Leistungszeitraum:</span>
                <span class="detail-value">{{ $period['start']->format('d.m.Y') }} - {{ $period['end']->format('d.m.Y') }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Zahlungsziel:</span>
                <span class="detail-value">{{ $invoice->due_date->format('d.m.Y') }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Projekt:</span>
                <span class="detail-value">{{ $project->name }}</span>
            </div>
        </div>
        
        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Beschreibung</th>
                    <th style="width: 15%;">Menge</th>
                    <th style="width: 10%;">Einheit</th>
                    <th style="width: 12%;">Preis/Einheit</th>
                    <th style="width: 13%;">Betrag</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                <tr>
                    <td>
                        <div class="item-description">{{ $item->description }}</div>
                        @if($item->meta && isset($item->meta['actual_hours']))
                        <div class="item-details">
                            Tatsächliche Zeit: {{ number_format($item->meta['actual_hours'], 2) }}h
                            | {{ $item->meta['worklog_count'] ?? 0 }} Einträge
                        </div>
                        @endif
                    </td>
                    <td>{{ number_format($item->quantity, 2) }}</td>
                    <td>{{ $item->unit }}</td>
                    <td>{{ $currency }} {{ number_format($item->unit_price, 2) }}</td>
                    <td>{{ $currency }} {{ number_format($item->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-table">
                <div class="total-row">
                    <span class="total-label">Zwischensumme:</span>
                    <span class="total-value">{{ $currency }} {{ number_format($invoice->subtotal, 2) }}</span>
                </div>
                <div class="total-row">
                    <span class="total-label">{{ config('billing.tax_name', 'MwSt') }} ({{ number_format($invoice->tax_rate * 100, 1) }}%):</span>
                    <span class="total-value">{{ $currency }} {{ number_format($invoice->tax_amount, 2) }}</span>
                </div>
                <div class="total-row grand-total">
                    <span class="total-label">Gesamtbetrag:</span>
                    <span class="total-value">{{ $currency }} {{ number_format($invoice->total, 2) }}</span>
                </div>
            </div>
        </div>
        
        <!-- Payment Information -->
        <div class="payment-section">
            <div class="payment-title">Zahlungsinformationen</div>
            <div class="bank-details">
                <div class="bank-label">Bank:</div>
                <div class="bank-value">{{ $bank['bank_name'] }}</div>
                
                <div class="bank-label">IBAN:</div>
                <div class="bank-value iban">{{ $bank['iban'] }}</div>
                
                <div class="bank-label">BIC/SWIFT:</div>
                <div class="bank-value">{{ $bank['bic'] }}</div>
                
                <div class="bank-label">Kontoinhaber:</div>
                <div class="bank-value">{{ $bank['account_holder'] }}</div>
                
                <div class="bank-label">Verwendungszweck:</div>
                <div class="bank-value">{{ $invoice->invoice_number }}</div>
                
                <div class="bank-label">Zahlungsbedingungen:</div>
                <div class="bank-value">{{ config('billing.payment_instructions', 'Zahlbar innert 30 Tagen') }}</div>
            </div>
        </div>
        
        <!-- Notes (if any) -->
        @if(!empty($invoice->notes))
        <div class="notes-section">
            <div class="notes-title">Hinweise</div>
            <div class="notes-content">{{ $invoice->notes }}</div>
        </div>
        @endif
        
        <!-- Footer -->
        <div class="footer">
            <div class="footer-company">{{ $from['company'] }}</div>
            <div>{{ $from['address'] }} | {{ $from['city'] }} | {{ $from['country'] }}</div>
            <div>{{ $from['email'] }} | {{ $from['phone'] }} | {{ $from['website'] }}</div>
            @if(!empty($from['vat_number']))
            <div>{{ $from['vat_number'] }}</div>
            @endif
        </div>
    </div>
</body>
</html>