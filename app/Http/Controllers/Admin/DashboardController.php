<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workflow;
use App\Services\WorkflowOrchestrator;
use Illuminate\Routing\Controller;

/**
 * Admin Dashboard Controller.
 */
class DashboardController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly WorkflowOrchestrator $orchestrator,
    ) {
        $this->middleware('auth');
        $this->middleware('can:admin');
    }

    /**
     * Show the admin dashboard.
     */
    public function index()
    {
        // Get statistics
        $stats = $this->orchestrator->getStatistics();

        // Recent tickets
        $recentTickets = Ticket::with(['project', 'workflow'])
            ->latest()
            ->limit(10)
            ->get();

        // Active workflows
        $activeWorkflows = Workflow::with('ticket')
            ->whereNotIn('state', [Workflow::STATE_DONE, Workflow::STATE_FAILED])
            ->latest()
            ->limit(10)
            ->get();

        // Failed workflows
        $failedWorkflows = Workflow::with('ticket')
            ->where('state', Workflow::STATE_FAILED)
            ->latest()
            ->limit(5)
            ->get();

        // Projects summary
        $projects = Project::withCount(['tickets'])
            ->get()
            ->map(function ($project) {
                $project->active_workflows = $project->tickets()
                    ->whereHas('workflow', function ($q) {
                        $q->whereNotIn('state', [Workflow::STATE_DONE, Workflow::STATE_FAILED]);
                    })
                    ->count();

                return $project;
            });

        // Workflow state distribution
        $workflowStates = Workflow::selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state')
            ->toArray();

        return view('admin.dashboard', compact(
            'stats',
            'recentTickets',
            'activeWorkflows',
            'failedWorkflows',
            'projects',
            'workflowStates'
        ));
    }
}
