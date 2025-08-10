<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin routes
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard
    Route::get('/', [App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');
    
    // Projects
    Route::resource('projects', App\Http\Controllers\Admin\ProjectController::class);
    Route::post('projects/{project}/regenerate-secrets', [App\Http\Controllers\Admin\ProjectController::class, 'regenerateSecrets'])
        ->name('projects.regenerate-secrets');
    
    // Tickets
    Route::resource('tickets', App\Http\Controllers\Admin\TicketController::class)->only(['index', 'show', 'destroy']);
    Route::post('tickets/{ticket}/start-workflow', [App\Http\Controllers\Admin\TicketController::class, 'startWorkflow'])
        ->name('tickets.start-workflow');
    Route::post('tickets/{ticket}/cancel-workflow', [App\Http\Controllers\Admin\TicketController::class, 'cancelWorkflow'])
        ->name('tickets.cancel-workflow');
    Route::post('tickets/{ticket}/retry-workflow', [App\Http\Controllers\Admin\TicketController::class, 'retryWorkflow'])
        ->name('tickets.retry-workflow');
    
    // Worklogs
    Route::get('worklogs', [App\Http\Controllers\Admin\WorklogController::class, 'index'])
        ->name('worklogs.index');
    Route::get('worklogs/export', [App\Http\Controllers\Admin\WorklogController::class, 'export'])
        ->name('worklogs.export');
    Route::get('worklogs/{worklog}', [App\Http\Controllers\Admin\WorklogController::class, 'show'])
        ->name('worklogs.show');
    Route::post('worklogs/{worklog}/sync', [App\Http\Controllers\Admin\WorklogController::class, 'sync'])
        ->name('worklogs.sync');
    Route::delete('worklogs/{worklog}', [App\Http\Controllers\Admin\WorklogController::class, 'destroy'])
        ->name('worklogs.destroy');
    
    // Invoices
    Route::get('invoices', [App\Http\Controllers\Admin\InvoiceController::class, 'index'])
        ->name('invoices.index');
    Route::get('invoices/create', [App\Http\Controllers\Admin\InvoiceController::class, 'create'])
        ->name('invoices.create');
    Route::post('invoices', [App\Http\Controllers\Admin\InvoiceController::class, 'store'])
        ->name('invoices.store');
    Route::get('invoices/{invoice}', [App\Http\Controllers\Admin\InvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::get('invoices/{invoice}/edit', [App\Http\Controllers\Admin\InvoiceController::class, 'edit'])
        ->name('invoices.edit');
    Route::put('invoices/{invoice}', [App\Http\Controllers\Admin\InvoiceController::class, 'update'])
        ->name('invoices.update');
    Route::delete('invoices/{invoice}', [App\Http\Controllers\Admin\InvoiceController::class, 'destroy'])
        ->name('invoices.destroy');
    Route::post('invoices/{invoice}/regenerate-pdf', [App\Http\Controllers\Admin\InvoiceController::class, 'regeneratePdf'])
        ->name('invoices.regenerate-pdf');
    Route::post('invoices/{invoice}/resend-email', [App\Http\Controllers\Admin\InvoiceController::class, 'resendEmail'])
        ->name('invoices.resend-email');
    Route::post('invoices/{invoice}/mark-paid', [App\Http\Controllers\Admin\InvoiceController::class, 'markPaid'])
        ->name('invoices.mark-paid');
    Route::post('invoices/{invoice}/mark-unpaid', [App\Http\Controllers\Admin\InvoiceController::class, 'markUnpaid'])
        ->name('invoices.mark-unpaid');
    Route::get('invoices/{invoice}/download-pdf', [App\Http\Controllers\Admin\InvoiceController::class, 'downloadPdf'])
        ->name('invoices.download-pdf');
});
