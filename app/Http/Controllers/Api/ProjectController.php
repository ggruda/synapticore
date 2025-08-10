<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\Secret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * API Controller for project management.
 */
class ProjectController extends Controller
{
    /**
     * List all projects.
     */
    public function index(): JsonResponse
    {
        $projects = Project::with('secrets')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $projects,
        ]);
    }

    /**
     * Register a new project.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:projects',
            'repo_urls' => 'required|array|min:1',
            'repo_urls.*' => 'required|url',
            'default_branch' => 'required|string|max:100',
            'allowed_paths' => 'array',
            'allowed_paths.*' => 'string',
            'language_profile' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $project = DB::transaction(function () use ($request) {
                $project = Project::create([
                    'name' => $request->name,
                    'repo_urls' => $request->repo_urls,
                    'default_branch' => $request->default_branch,
                    'allowed_paths' => $request->allowed_paths ?? ['app/', 'src/', 'lib/'],
                    'language_profile' => $request->language_profile ?? [],
                ]);

                // Generate API secret for webhooks
                $apiKey = 'sk_'.Str::random(32);
                $webhookSecret = Str::random(32);

                Secret::create([
                    'project_id' => $project->id,
                    'kind' => 'api',
                    'key_id' => 'api_key',
                    'payload' => encrypt($apiKey),
                    'meta' => ['type' => 'api_key'],
                ]);

                Secret::create([
                    'project_id' => $project->id,
                    'kind' => 'webhook', 
                    'key_id' => 'webhook_secret',
                    'payload' => encrypt($webhookSecret),
                    'meta' => ['type' => 'webhook_secret'],
                ]);

                $project->api_key = $apiKey;
                $project->webhook_secret = $webhookSecret;

                return $project;
            });

            return response()->json([
                'success' => true,
                'message' => 'Project registered successfully',
                'data' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'api_key' => $project->api_key,
                    'webhook_secret' => $project->webhook_secret,
                    'webhook_urls' => [
                        'jira' => url("/api/webhooks/jira/{$project->id}"),
                        'github' => url("/api/webhooks/github/{$project->id}"),
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register project',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show project details.
     */
    public function show(Project $project): JsonResponse
    {
        $project->load(['tickets.workflow', 'secrets']);

        // Hide sensitive secret values
        $project->secrets->each(function ($secret) {
            $secret->value = '***';
        });

        return response()->json([
            'success' => true,
            'data' => $project,
        ]);
    }

    /**
     * Update project configuration.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:projects,name,'.$project->id,
            'repo_urls' => 'sometimes|array|min:1',
            'repo_urls.*' => 'required|url',
            'default_branch' => 'sometimes|string|max:100',
            'allowed_paths' => 'sometimes|array',
            'allowed_paths.*' => 'string',
            'language_profile' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $project->update($request->only([
                'name',
                'repo_urls',
                'default_branch',
                'allowed_paths',
                'language_profile',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Project updated successfully',
                'data' => $project,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update project',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Regenerate project secrets.
     */
    public function regenerateSecrets(Project $project): JsonResponse
    {
        try {
            $apiKey = 'sk_'.Str::random(32);
            $webhookSecret = Str::random(32);

            DB::transaction(function () use ($project, $apiKey, $webhookSecret) {
                // Update API key
                $apiKeySecret = $project->secrets()->where('key_id', 'api_key')->first();
                if ($apiKeySecret) {
                    $apiKeySecret->update(['payload' => encrypt($apiKey)]);
                } else {
                    Secret::create([
                        'project_id' => $project->id,
                        'kind' => 'api',
                        'key_id' => 'api_key',
                        'payload' => encrypt($apiKey),
                        'meta' => ['type' => 'api_key'],
                    ]);
                }

                // Update webhook secret
                $webhookSecretRecord = $project->secrets()->where('key_id', 'webhook_secret')->first();
                if ($webhookSecretRecord) {
                    $webhookSecretRecord->update(['payload' => encrypt($webhookSecret)]);
                } else {
                    Secret::create([
                        'project_id' => $project->id,
                        'kind' => 'webhook',
                        'key_id' => 'webhook_secret',
                        'payload' => encrypt($webhookSecret),
                        'meta' => ['type' => 'webhook_secret'],
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Secrets regenerated successfully',
                'data' => [
                    'api_key' => $apiKey,
                    'webhook_secret' => $webhookSecret,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate secrets',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete project.
     */
    public function destroy(Project $project): JsonResponse
    {
        try {
            // Check if project has active workflows
            $activeWorkflows = $project->tickets()
                ->whereHas('workflow', function ($query) {
                    $query->whereNotIn('state', ['DONE', 'FAILED']);
                })
                ->count();

            if ($activeWorkflows > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete project with active workflows',
                    'active_workflows' => $activeWorkflows,
                ], 400);
            }

            $project->delete();

            return response()->json([
                'success' => true,
                'message' => 'Project deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete project',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
