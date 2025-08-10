<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workflow;
use App\Services\WorkflowOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * API Controller for workflow management.
 */
class WorkflowController extends Controller
{
    /**
     * Create a new workflow controller instance.
     */
    public function __construct(
        private readonly WorkflowOrchestrator $orchestrator,
    ) {}

    /**
     * Start a new workflow for a ticket.
     */
    public function start(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'external_key' => 'required|string|max:100',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'acceptance_criteria' => 'array',
            'acceptance_criteria.*' => 'string',
            'labels' => 'array',
            'labels.*' => 'string',
            'priority' => 'in:low,medium,high,urgent',
            'assignee' => 'nullable|string|max:100',
            'reporter' => 'nullable|string|max:100',
            'source' => 'in:jira,linear,azure,github',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Check if ticket already exists
            $existingTicket = Ticket::where('external_key', $request->external_key)
                ->where('project_id', $request->project_id)
                ->first();

            if ($existingTicket) {
                // Check if workflow already exists
                if ($existingTicket->workflow) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Workflow already exists for this ticket',
                        'data' => [
                            'ticket_id' => $existingTicket->id,
                            'workflow_id' => $existingTicket->workflow->id,
                            'state' => $existingTicket->workflow->state,
                        ],
                    ], 409);
                }

                $ticket = $existingTicket;
            } else {
                // Create new ticket
                $ticket = Ticket::create([
                    'project_id' => $request->project_id,
                    'external_key' => $request->external_key,
                    'source' => $request->source ?? 'jira',
                    'title' => $request->title,
                    'body' => $request->body,
                    'acceptance_criteria' => $request->acceptance_criteria ?? [],
                    'labels' => $request->labels ?? [],
                    'status' => 'in_progress',
                    'priority' => $request->priority ?? 'medium',
                    'assignee' => $request->assignee,
                    'reporter' => $request->reporter,
                    'meta' => [
                        'created_via' => 'api',
                        'api_user' => $request->user()?->id,
                    ],
                ]);
            }

            // Start workflow
            $workflow = $this->orchestrator->startWorkflow($ticket);

            return response()->json([
                'success' => true,
                'message' => 'Workflow started successfully',
                'data' => [
                    'ticket_id' => $ticket->id,
                    'workflow_id' => $workflow->id,
                    'state' => $workflow->state,
                    'external_key' => $ticket->external_key,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to start workflow',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get workflow status.
     */
    public function status(string $identifier): JsonResponse
    {
        // Find by ticket ID, external key, or workflow ID
        $workflow = null;

        if (is_numeric($identifier)) {
            // Try workflow ID first
            $workflow = Workflow::find($identifier);

            // Then try ticket ID
            if (! $workflow) {
                $ticket = Ticket::find($identifier);
                $workflow = $ticket?->workflow;
            }
        } else {
            // Try external key
            $ticket = Ticket::where('external_key', $identifier)->first();
            $workflow = $ticket?->workflow;
        }

        if (! $workflow) {
            return response()->json([
                'success' => false,
                'message' => 'Workflow not found',
            ], 404);
        }

        $status = $this->orchestrator->getStatus($workflow);

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * Cancel a workflow.
     */
    public function cancel(Workflow $workflow): JsonResponse
    {
        try {
            $this->orchestrator->cancelWorkflow($workflow);

            return response()->json([
                'success' => true,
                'message' => 'Workflow cancelled successfully',
                'data' => [
                    'workflow_id' => $workflow->id,
                    'state' => $workflow->fresh()->state,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel workflow',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Retry a failed workflow.
     */
    public function retry(Workflow $workflow): JsonResponse
    {
        try {
            $this->orchestrator->retryWorkflow($workflow);

            return response()->json([
                'success' => true,
                'message' => 'Workflow retry initiated',
                'data' => [
                    'workflow_id' => $workflow->id,
                    'state' => $workflow->fresh()->state,
                    'retries' => $workflow->fresh()->retries,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry workflow',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * List artifacts for a workflow.
     */
    public function artifacts(Workflow $workflow): JsonResponse
    {
        $ticket = $workflow->ticket;
        $artifacts = [];

        // Collect plan artifacts
        if ($ticket->plan) {
            $artifacts[] = [
                'type' => 'plan',
                'name' => 'Implementation Plan',
                'created_at' => $ticket->plan->created_at->toIso8601String(),
                'data' => [
                    'risk' => $ticket->plan->risk,
                    'test_strategy' => $ticket->plan->test_strategy,
                    'steps' => count($ticket->plan->plan_json['steps'] ?? []),
                ],
            ];
        }

        // Collect patch artifacts
        foreach ($ticket->patches as $patch) {
            $artifacts[] = [
                'type' => 'patch',
                'name' => 'Code Changes',
                'created_at' => $patch->created_at->toIso8601String(),
                'data' => [
                    'files_touched' => count($patch->files_touched ?? []),
                    'risk_score' => $patch->risk_score,
                    'lines_added' => $patch->diff_stats['additions'] ?? 0,
                    'lines_removed' => $patch->diff_stats['deletions'] ?? 0,
                ],
            ];
        }

        // Collect run artifacts
        foreach ($ticket->runs as $run) {
            $artifact = [
                'type' => 'run',
                'name' => ucfirst($run->type).' Results',
                'created_at' => $run->created_at->toIso8601String(),
                'status' => $run->status,
                'data' => [],
            ];

            if ($run->junit_path) {
                $artifact['data']['junit'] = url('/api/artifacts/download?path='.urlencode($run->junit_path));
            }
            if ($run->coverage_path) {
                $artifact['data']['coverage'] = url('/api/artifacts/download?path='.urlencode($run->coverage_path));
            }
            if ($run->logs_path) {
                $artifact['data']['logs'] = url('/api/artifacts/download?path='.urlencode($run->logs_path));
            }

            $artifacts[] = $artifact;
        }

        // Collect PR artifacts
        foreach ($ticket->pullRequests as $pr) {
            $artifacts[] = [
                'type' => 'pull_request',
                'name' => 'Pull Request',
                'created_at' => $pr->created_at->toIso8601String(),
                'data' => [
                    'url' => $pr->url,
                    'branch' => $pr->branch_name,
                    'is_draft' => $pr->is_draft,
                    'labels' => $pr->labels,
                ],
            ];
        }

        // Add failure bundles if any
        $failures = $workflow->meta['failures'] ?? [];
        foreach ($failures as $failure) {
            if (isset($failure['bundle_path'])) {
                $artifacts[] = [
                    'type' => 'failure_bundle',
                    'name' => 'Failure Bundle',
                    'created_at' => $failure['timestamp'],
                    'data' => [
                        'exception' => $failure['exception'] ?? 'unknown',
                        'download' => url('/api/artifacts/download?path='.urlencode($failure['bundle_path'])),
                    ],
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'workflow_id' => $workflow->id,
                'ticket_id' => $ticket->id,
                'external_key' => $ticket->external_key,
                'artifacts' => $artifacts,
                'total' => count($artifacts),
            ],
        ]);
    }

    /**
     * Get workflow statistics.
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->orchestrator->getStatistics();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * List workflows with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Workflow::with(['ticket.project']);

        // Filter by project
        if ($request->has('project_id')) {
            $query->whereHas('ticket', function ($q) use ($request) {
                $q->where('project_id', $request->project_id);
            });
        }

        // Filter by state
        if ($request->has('state')) {
            $states = is_array($request->state) ? $request->state : [$request->state];
            $query->whereIn('state', $states);
        }

        // Filter by date range
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $workflows = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $workflows,
        ]);
    }
}
