<?php

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
