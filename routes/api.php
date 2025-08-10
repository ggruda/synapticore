<?php

use App\Http\Controllers\Api\ArtifactController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Webhooks\GithubWebhookController;
use App\Http\Controllers\Webhooks\JiraWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Routes for external webhook integrations.
| These routes use signature validation in the Form Requests.
|
*/

Route::prefix('webhooks')->name('webhooks.')->group(function () {
    // Jira webhooks - project is identified by token in URL
    Route::post('jira/{project:id}', [JiraWebhookController::class, 'handle'])
        ->name('jira')
        ->withoutMiddleware(['auth:sanctum']);

    // GitHub webhooks - project is identified by token in URL
    Route::post('github/{project:id}', [GithubWebhookController::class, 'handle'])
        ->name('github')
        ->withoutMiddleware(['auth:sanctum']);

    // Alternative: Use a webhook token for authentication
    Route::prefix('{token}')->group(function () {
        Route::post('jira', [JiraWebhookController::class, 'handle'])
            ->name('jira.token')
            ->withoutMiddleware(['auth:sanctum']);

        Route::post('github', [GithubWebhookController::class, 'handle'])
            ->name('github.token')
            ->withoutMiddleware(['auth:sanctum']);
    })->where('token', '[a-zA-Z0-9]{32}');
});

/*
|--------------------------------------------------------------------------
| Health Check Routes
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toIso8601String(),
        'services' => [
            'database' => DB::connection()->getPdo() ? 'connected' : 'disconnected',
            'redis' => Redis::connection()->ping() ? 'connected' : 'disconnected',
        ],
    ]);
})->name('health');

/*
|--------------------------------------------------------------------------
| API Management Routes
|--------------------------------------------------------------------------
|
| Routes for managing projects, workflows, and artifacts.
|
*/

// Authenticated API routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Project management
    Route::apiResource('projects', ProjectController::class);
    Route::post('projects/{project}/regenerate-secrets', [ProjectController::class, 'regenerateSecrets'])
        ->name('projects.regenerate-secrets');
    
    // Workflow management
    Route::post('workflows/start', [WorkflowController::class, 'start'])
        ->name('workflows.start');
    Route::get('workflows', [WorkflowController::class, 'index'])
        ->name('workflows.index');
    Route::get('workflows/statistics', [WorkflowController::class, 'statistics'])
        ->name('workflows.statistics');
    Route::get('workflows/{identifier}/status', [WorkflowController::class, 'status'])
        ->name('workflows.status');
    Route::get('workflows/{workflow}/artifacts', [WorkflowController::class, 'artifacts'])
        ->name('workflows.artifacts');
    Route::post('workflows/{workflow}/cancel', [WorkflowController::class, 'cancel'])
        ->name('workflows.cancel');
    Route::post('workflows/{workflow}/retry', [WorkflowController::class, 'retry'])
        ->name('workflows.retry');
    
    // Artifact management
    Route::get('artifacts/download', [ArtifactController::class, 'download'])
        ->name('artifacts.download');
    Route::get('artifacts/list', [ArtifactController::class, 'list'])
        ->name('artifacts.list');
    Route::post('artifacts/upload', [ArtifactController::class, 'upload'])
        ->name('artifacts.upload');
});
