<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Billing Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains all billing-related configuration for the Synapticore
    | system including invoice generation, pricing, and email settings.
    |
    */

    // Email settings
    'admin_email' => env('BILLING_ADMIN_EMAIL', 'admin@synapticore.com'),
    'cc_emails' => env('BILLING_CC_EMAILS', ''), // Comma separated

    // Currency and pricing
    'default_currency' => env('BILLING_CURRENCY', 'CHF'),
    'currency_symbol' => env('BILLING_CURRENCY_SYMBOL', 'CHF'),
    'unit_price_per_hour' => (float) env('BILLING_PRICE_PER_HOUR', 150.00),

    // Tax settings
    'tax_rate' => (float) env('BILLING_TAX_RATE', 0.077), // 7.7% Swiss VAT
    'tax_name' => env('BILLING_TAX_NAME', 'MwSt'),

    // Invoice numbering
    'invoice_number_prefix' => env('BILLING_INVOICE_PREFIX', 'SC'),
    'invoice_number_format' => env('BILLING_INVOICE_FORMAT', '{PREFIX}-{YYYY}{MM}-{SEQ}'),

    // Payment terms
    'payment_terms_days' => (int) env('BILLING_PAYMENT_TERMS', 30),
    'payment_instructions' => env('BILLING_PAYMENT_INSTRUCTIONS', 'Zahlbar innert 30 Tagen'),

    // Bank details
    'bank_details' => [
        'bank_name' => env('BILLING_BANK_NAME', 'PostFinance AG'),
        'iban' => env('BILLING_IBAN', 'CH12 3456 7890 1234 5678 9'),
        'bic' => env('BILLING_BIC', 'POFICHBEXXX'),
        'account_holder' => env('BILLING_ACCOUNT_HOLDER', 'Synapticore AG'),
    ],

    // Company details (from)
    'invoice_address_from' => [
        'company' => env('BILLING_FROM_COMPANY', 'Synapticore AG'),
        'address' => env('BILLING_FROM_ADDRESS', 'Technoparkstrasse 1'),
        'city' => env('BILLING_FROM_CITY', '8005 Zürich'),
        'country' => env('BILLING_FROM_COUNTRY', 'Schweiz'),
        'email' => env('BILLING_FROM_EMAIL', 'billing@synapticore.com'),
        'phone' => env('BILLING_FROM_PHONE', '+41 44 123 45 67'),
        'website' => env('BILLING_FROM_WEBSITE', 'www.synapticore.com'),
        'vat_number' => env('BILLING_FROM_VAT', 'CHE-123.456.789 MWST'),
    ],

    // Default client details (to) - can be overridden per project
    'invoice_address_to' => [
        'company' => env('BILLING_TO_COMPANY', 'Client Company AG'),
        'address' => env('BILLING_TO_ADDRESS', 'Bahnhofstrasse 1'),
        'city' => env('BILLING_TO_CITY', '8001 Zürich'),
        'country' => env('BILLING_TO_COUNTRY', 'Schweiz'),
    ],

    // Rounding settings
    'hours_rounding' => [
        'enabled' => env('BILLING_ROUND_HOURS', true),
        'increment' => (float) env('BILLING_ROUND_INCREMENT', 0.25), // Round to nearest quarter hour
        'minimum' => (float) env('BILLING_MIN_HOURS', 0.25), // Minimum billable time
    ],

    // PDF settings
    'pdf' => [
        'paper_size' => env('BILLING_PDF_PAPER', 'A4'),
        'orientation' => env('BILLING_PDF_ORIENTATION', 'portrait'),
        'font_family' => env('BILLING_PDF_FONT', 'helvetica'),
    ],

    // Corporate design colors
    'design' => [
        'primary_color' => '#1E3A8A', // Synapticore primary blue
        'secondary_color' => '#06B6D4', // Turquoise accent
        'text_color' => '#1F2937',
        'light_gray' => '#F3F4F6',
        'border_color' => '#E5E7EB',
    ],

    // Storage settings
    'storage' => [
        'disk' => env('BILLING_STORAGE_DISK', 'spaces'),
        'path' => env('BILLING_STORAGE_PATH', 'invoices/{YEAR}/{MONTH}'),
        'public' => env('BILLING_STORAGE_PUBLIC', false),
        'retention_days' => (int) env('BILLING_RETENTION_DAYS', 2555), // 7 years
    ],

    // Email settings
    'email' => [
        'subject_template' => env('BILLING_EMAIL_SUBJECT', 'Rechnung {INVOICE_NUMBER} - {MONTH} {YEAR}'),
        'from_name' => env('BILLING_EMAIL_FROM_NAME', 'Synapticore Billing'),
        'from_address' => env('BILLING_EMAIL_FROM', 'billing@synapticore.com'),
        'attach_pdf' => env('BILLING_EMAIL_ATTACH_PDF', true),
        'include_link' => env('BILLING_EMAIL_INCLUDE_LINK', true),
        'link_expiry_days' => (int) env('BILLING_EMAIL_LINK_EXPIRY', 90),
    ],
];
