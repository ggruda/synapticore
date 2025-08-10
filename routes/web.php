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
});
