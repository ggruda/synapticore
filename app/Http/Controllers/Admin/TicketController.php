<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workflow;
use App\Services\WorkflowOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin Controller for ticket management.
 */
class TicketController extends Controller
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
     * Display a listing of tickets.
     */
    public function index(Request $request)
    {
        $query = Ticket::with(['project', 'workflow', 'plan', 'patches', 'pullRequests']);

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by workflow state
        if ($request->has('workflow_state')) {
            $query->whereHas('workflow', function ($q) use ($request) {
                $q->where('state', $request->workflow_state);
            });
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('external_key', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $tickets = $query->paginate(20);
        $projects = Project::all();

        return view('admin.tickets.index', compact('tickets', 'projects'));
    }

    /**
     * Display the specified ticket.
     */
    public function show(Ticket $ticket)
    {
        $ticket->load([
            'project',
            'workflow',
            'plan',
            'patches',
            'runs',
            'pullRequests',
            'worklogs',
        ]);

        // Get workflow status if exists
        $workflowStatus = null;
        if ($ticket->workflow) {
            $workflowStatus = $this->orchestrator->getStatus($ticket->workflow);
        }

        // Get artifacts
        $artifacts = $this->collectArtifacts($ticket);

        return view('admin.tickets.show', compact('ticket', 'workflowStatus', 'artifacts'));
    }

    /**
     * Start workflow for a ticket.
     */
    public function startWorkflow(Ticket $ticket)
    {
        try {
            if ($ticket->workflow) {
                return redirect()->route('admin.tickets.show', $ticket)
                    ->with('error', 'Workflow already exists for this ticket');
            }

            $workflow = $this->orchestrator->startWorkflow($ticket);

            return redirect()->route('admin.tickets.show', $ticket)
                ->with('success', 'Workflow started successfully');
        } catch (\Exception $e) {
            return redirect()->route('admin.tickets.show', $ticket)
                ->with('error', 'Failed to start workflow: '.$e->getMessage());
        }
    }

    /**
     * Cancel workflow for a ticket.
     */
    public function cancelWorkflow(Ticket $ticket)
    {
        try {
            if (! $ticket->workflow) {
                return redirect()->route('admin.tickets.show', $ticket)
                    ->with('error', 'No workflow exists for this ticket');
            }

            $this->orchestrator->cancelWorkflow($ticket->workflow);

            return redirect()->route('admin.tickets.show', $ticket)
                ->with('success', 'Workflow cancelled successfully');
        } catch (\Exception $e) {
            return redirect()->route('admin.tickets.show', $ticket)
                ->with('error', 'Failed to cancel workflow: '.$e->getMessage());
        }
    }

    /**
     * Retry workflow for a ticket.
     */
    public function retryWorkflow(Ticket $ticket)
    {
        try {
            if (! $ticket->workflow) {
                return redirect()->route('admin.tickets.show', $ticket)
                    ->with('error', 'No workflow exists for this ticket');
            }

            if ($ticket->workflow->state !== Workflow::STATE_FAILED) {
                return redirect()->route('admin.tickets.show', $ticket)
                    ->with('error', 'Can only retry failed workflows');
            }

            $this->orchestrator->retryWorkflow($ticket->workflow);

            return redirect()->route('admin.tickets.show', $ticket)
                ->with('success', 'Workflow retry initiated');
        } catch (\Exception $e) {
            return redirect()->route('admin.tickets.show', $ticket)
                ->with('error', 'Failed to retry workflow: '.$e->getMessage());
        }
    }

    /**
     * Delete a ticket.
     */
    public function destroy(Ticket $ticket)
    {
        try {
            // Check if ticket has active workflow
            if ($ticket->workflow && ! in_array($ticket->workflow->state, [Workflow::STATE_DONE, Workflow::STATE_FAILED])) {
                return redirect()->route('admin.tickets.index')
                    ->with('error', 'Cannot delete ticket with active workflow');
            }

            $ticket->delete();

            return redirect()->route('admin.tickets.index')
                ->with('success', 'Ticket deleted successfully');
        } catch (\Exception $e) {
            return redirect()->route('admin.tickets.index')
                ->with('error', 'Failed to delete ticket: '.$e->getMessage());
        }
    }

    /**
     * Collect artifacts for a ticket.
     */
    private function collectArtifacts(Ticket $ticket): array
    {
        $artifacts = [];

        // Run artifacts
        foreach ($ticket->runs as $run) {
            if ($run->junit_path) {
                $artifacts[] = [
                    'type' => 'JUnit Results',
                    'name' => ucfirst($run->type).' JUnit',
                    'path' => $run->junit_path,
                    'created_at' => $run->created_at,
                ];
            }
            if ($run->coverage_path) {
                $artifacts[] = [
                    'type' => 'Coverage Report',
                    'name' => ucfirst($run->type).' Coverage',
                    'path' => $run->coverage_path,
                    'created_at' => $run->created_at,
                ];
            }
            if ($run->logs_path) {
                $artifacts[] = [
                    'type' => 'Logs',
                    'name' => ucfirst($run->type).' Logs',
                    'path' => $run->logs_path,
                    'created_at' => $run->created_at,
                ];
            }
        }

        // Failure bundles
        if ($ticket->workflow) {
            $failures = $ticket->workflow->meta['failures'] ?? [];
            foreach ($failures as $failure) {
                if (isset($failure['bundle_path'])) {
                    $artifacts[] = [
                        'type' => 'Failure Bundle',
                        'name' => 'Failure at '.$failure['timestamp'],
                        'path' => $failure['bundle_path'],
                        'created_at' => $failure['timestamp'],
                    ];
                }
            }
        }

        return $artifacts;
    }
}
