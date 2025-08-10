<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Project;
use App\Models\Secret;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin Controller for project management.
 */
class ProjectController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('can:admin');
    }

    /**
     * Display a listing of projects.
     */
    public function index()
    {
        $projects = Project::withCount(['tickets'])
            ->with('secrets')
            ->paginate(20);

        return view('admin.projects.index', compact('projects'));
    }

    /**
     * Show the form for creating a new project.
     */
    public function create()
    {
        return view('admin.projects.create');
    }

    /**
     * Store a newly created project.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:projects',
            'repo_urls' => 'required|string',
            'default_branch' => 'required|string|max:100',
            'allowed_paths' => 'nullable|string',
        ]);

        try {
            $project = DB::transaction(function () use ($request) {
                // Parse comma-separated values
                $repoUrls = array_map('trim', explode(',', $request->repo_urls));
                $allowedPaths = $request->allowed_paths
                    ? array_map('trim', explode(',', $request->allowed_paths))
                    : ['app/', 'src/', 'lib/'];

                $project = Project::create([
                    'name' => $request->name,
                    'repo_urls' => $repoUrls,
                    'default_branch' => $request->default_branch,
                    'allowed_paths' => $allowedPaths,
                    'language_profile' => [],
                ]);

                // Generate secrets
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

                return $project;
            });

            return redirect()->route('admin.projects.show', $project)
                ->with('success', 'Project created successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create project: '.$e->getMessage());
        }
    }

    /**
     * Display the specified project.
     */
    public function show(Project $project)
    {
        $project->load(['tickets.workflow', 'secrets']);

        // Get recent tickets
        $recentTickets = $project->tickets()
            ->with('workflow')
            ->latest()
            ->limit(10)
            ->get();

        // Get workflow statistics
        $workflowStats = [
            'total' => $project->tickets()->whereHas('workflow')->count(),
            'active' => $project->tickets()->whereHas('workflow', function ($q) {
                $q->whereNotIn('state', ['DONE', 'FAILED']);
            })->count(),
            'completed' => $project->tickets()->whereHas('workflow', function ($q) {
                $q->where('state', 'DONE');
            })->count(),
            'failed' => $project->tickets()->whereHas('workflow', function ($q) {
                $q->where('state', 'FAILED');
            })->count(),
        ];

        // Get webhook URLs
        $webhookUrls = [
            'jira' => url("/api/webhooks/jira/{$project->id}"),
            'github' => url("/api/webhooks/github/{$project->id}"),
        ];

        // Decrypt secrets for display
        $secrets = [];
        foreach ($project->secrets as $secret) {
            $secrets[$secret->key_id] = decrypt($secret->payload);
        }

        return view('admin.projects.show', compact(
            'project',
            'recentTickets',
            'workflowStats',
            'webhookUrls',
            'secrets'
        ));
    }

    /**
     * Show the form for editing the project.
     */
    public function edit(Project $project)
    {
        // Convert arrays to comma-separated strings for form
        $project->repo_urls_string = implode(', ', $project->repo_urls ?? []);
        $project->allowed_paths_string = implode(', ', $project->allowed_paths ?? []);

        return view('admin.projects.edit', compact('project'));
    }

    /**
     * Update the specified project.
     */
    public function update(Request $request, Project $project)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:projects,name,'.$project->id,
            'repo_urls' => 'required|string',
            'default_branch' => 'required|string|max:100',
            'allowed_paths' => 'nullable|string',
        ]);

        try {
            // Parse comma-separated values
            $repoUrls = array_map('trim', explode(',', $request->repo_urls));
            $allowedPaths = $request->allowed_paths
                ? array_map('trim', explode(',', $request->allowed_paths))
                : ['app/', 'src/', 'lib/'];

            $project->update([
                'name' => $request->name,
                'repo_urls' => $repoUrls,
                'default_branch' => $request->default_branch,
                'allowed_paths' => $allowedPaths,
            ]);

            return redirect()->route('admin.projects.show', $project)
                ->with('success', 'Project updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to update project: '.$e->getMessage());
        }
    }

    /**
     * Regenerate project secrets.
     */
    public function regenerateSecrets(Project $project)
    {
        try {
            DB::transaction(function () use ($project) {
                $apiKey = 'sk_'.Str::random(32);
                $webhookSecret = Str::random(32);

                // Update or create API key
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

                // Update or create webhook secret
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

            return redirect()->route('admin.projects.show', $project)
                ->with('success', 'Secrets regenerated successfully');
        } catch (\Exception $e) {
            return redirect()->route('admin.projects.show', $project)
                ->with('error', 'Failed to regenerate secrets: '.$e->getMessage());
        }
    }

    /**
     * Remove the specified project.
     */
    public function destroy(Project $project)
    {
        try {
            // Check for active workflows
            $activeWorkflows = $project->tickets()
                ->whereHas('workflow', function ($q) {
                    $q->whereNotIn('state', ['DONE', 'FAILED']);
                })
                ->count();

            if ($activeWorkflows > 0) {
                return redirect()->route('admin.projects.index')
                    ->with('error', 'Cannot delete project with active workflows');
            }

            $project->delete();

            return redirect()->route('admin.projects.index')
                ->with('success', 'Project deleted successfully');
        } catch (\Exception $e) {
            return redirect()->route('admin.projects.index')
                ->with('error', 'Failed to delete project: '.$e->getMessage());
        }
    }
}
