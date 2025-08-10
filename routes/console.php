<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Task Scheduling
|--------------------------------------------------------------------------
|
| Here is where you can schedule recurring tasks for your application.
| These tasks will be run by the Laravel scheduler when properly configured.
|
*/

// Generate monthly invoices on the 1st of each month at 3:00 AM (Europe/Zurich)
Schedule::command('invoices:generate-monthly')
    ->monthlyOn(1, '03:00')
    ->timezone('Europe/Zurich')
    ->appendOutputTo(storage_path('logs/invoices.log'))
    ->emailOutputOnFailure(config('billing.admin_email'))
    ->description('Generate monthly invoices for all projects');

// Optional: Send invoice reminders on the 15th of each month
Schedule::command('invoices:send-reminders')
    ->monthlyOn(15, '09:00')
    ->timezone('Europe/Zurich')
    ->description('Send reminders for unpaid invoices');

// Optional: Clean up old invoice PDFs (older than 7 years)
Schedule::command('invoices:cleanup-old')
    ->yearly()
    ->description('Clean up invoice PDFs older than retention period');
