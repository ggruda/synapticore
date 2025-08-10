<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Worklog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admin controller for managing worklogs.
 */
class WorklogController extends Controller
{
    /**
     * Display a listing of worklogs with filters.
     */
    public function index(Request $request): View
    {
        Gate::authorize('admin');
        
        // Build query
        $query = Worklog::with(['ticket.project', 'user']);
        
        // Filter by project
        if ($request->filled('project_id')) {
            $query->whereHas('ticket', function ($q) use ($request) {
                $q->where('project_id', $request->project_id);
            });
        }
        
        // Filter by phase
        if ($request->filled('phase')) {
            $query->where('phase', $request->phase);
        }
        
        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('started_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->whereDate('started_at', '<=', $request->date_to);
        }
        
        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Filter by sync status
        if ($request->filled('sync_status')) {
            if ($request->sync_status === 'synced') {
                $query->whereNotNull('synced_at');
            } elseif ($request->sync_status === 'unsynced') {
                $query->whereNull('synced_at');
            }
        }
        
        // Sort
        $sortBy = $request->get('sort_by', 'started_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);
        
        // Paginate
        $worklogs = $query->paginate(50)->withQueryString();
        
        // Get filter options
        $projects = Project::orderBy('name')->get();
        $phases = ['plan', 'implement', 'test', 'review', 'pr', 'repair', 'context'];
        $statuses = ['in_progress', 'completed', 'failed'];
        
        // Calculate statistics
        $stats = $this->calculateStatistics($request);
        
        return view('admin.worklogs.index', compact(
            'worklogs',
            'projects',
            'phases',
            'statuses',
            'stats'
        ));
    }
    
    /**
     * Export worklogs to CSV.
     */
    public function export(Request $request): Response
    {
        Gate::authorize('admin');
        
        // Build same query as index
        $query = Worklog::with(['ticket.project', 'user']);
        
        // Apply filters
        if ($request->filled('project_id')) {
            $query->whereHas('ticket', function ($q) use ($request) {
                $q->where('project_id', $request->project_id);
            });
        }
        
        if ($request->filled('phase')) {
            $query->where('phase', $request->phase);
        }
        
        if ($request->filled('date_from')) {
            $query->whereDate('started_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->whereDate('started_at', '<=', $request->date_to);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Get all results (no pagination for export)
        $worklogs = $query->orderBy('started_at', 'desc')->get();
        
        // Generate CSV
        $csv = $this->generateCsv($worklogs);
        
        // Return as download
        $filename = 'worklogs_' . Carbon::now()->format('Y-m-d_His') . '.csv';
        
        return response($csv, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
    
    /**
     * Show details of a specific worklog.
     */
    public function show(Worklog $worklog): View
    {
        Gate::authorize('admin');
        
        $worklog->load(['ticket.project', 'user']);
        
        return view('admin.worklogs.show', compact('worklog'));
    }
    
    /**
     * Delete a worklog.
     */
    public function destroy(Worklog $worklog)
    {
        Gate::authorize('admin');
        
        $worklog->delete();
        
        return redirect()
            ->route('admin.worklogs.index')
            ->with('success', 'Worklog deleted successfully');
    }
    
    /**
     * Sync worklog to external system.
     */
    public function sync(Worklog $worklog)
    {
        Gate::authorize('admin');
        
        try {
            $tracker = app(\App\Services\Time\TrackedSection::class);
            $tracker->pushToTicketProvider($worklog->ticket, $worklog);
            
            return redirect()
                ->back()
                ->with('success', 'Worklog synced successfully');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Failed to sync worklog: ' . $e->getMessage());
        }
    }
    
    /**
     * Calculate statistics for filtered worklogs.
     */
    private function calculateStatistics(Request $request): array
    {
        $query = Worklog::query();
        
        // Apply same filters
        if ($request->filled('project_id')) {
            $query->whereHas('ticket', function ($q) use ($request) {
                $q->where('project_id', $request->project_id);
            });
        }
        
        if ($request->filled('phase')) {
            $query->where('phase', $request->phase);
        }
        
        if ($request->filled('date_from')) {
            $query->whereDate('started_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->whereDate('started_at', '<=', $request->date_to);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Calculate stats
        $totalSeconds = $query->sum('seconds');
        $totalHours = round($totalSeconds / 3600, 2);
        $count = $query->count();
        $avgSeconds = $count > 0 ? $totalSeconds / $count : 0;
        $avgMinutes = round($avgSeconds / 60, 1);
        
        // By phase breakdown
        $byPhase = $query->select('phase')
            ->selectRaw('SUM(seconds) as total_seconds')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('phase')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->phase => [
                    'seconds' => $item->total_seconds,
                    'hours' => round($item->total_seconds / 3600, 2),
                    'count' => $item->count,
                ]];
            })->toArray();
        
        return [
            'total_seconds' => $totalSeconds,
            'total_hours' => $totalHours,
            'count' => $count,
            'avg_minutes' => $avgMinutes,
            'by_phase' => $byPhase,
        ];
    }
    
    /**
     * Generate CSV content from worklogs.
     */
    private function generateCsv($worklogs): string
    {
        $csv = [];
        
        // Headers
        $csv[] = [
            'ID',
            'Date',
            'Project',
            'Ticket',
            'Phase',
            'Duration (seconds)',
            'Duration (hours)',
            'User',
            'Status',
            'Synced',
            'Notes',
        ];
        
        // Data rows
        foreach ($worklogs as $worklog) {
            $csv[] = [
                $worklog->id,
                $worklog->started_at->format('Y-m-d H:i:s'),
                $worklog->ticket->project->name ?? 'N/A',
                $worklog->ticket->external_key ?? 'N/A',
                $worklog->phase,
                $worklog->seconds,
                round($worklog->seconds / 3600, 2),
                $worklog->user->name ?? 'System',
                $worklog->status,
                $worklog->synced_at ? 'Yes' : 'No',
                $worklog->notes ?? '',
            ];
        }
        
        // Convert to CSV string
        $output = '';
        foreach ($csv as $row) {
            $output .= $this->csvRow($row);
        }
        
        return $output;
    }
    
    /**
     * Format a CSV row.
     */
    private function csvRow(array $data): string
    {
        $escaped = array_map(function ($field) {
            // Escape quotes and wrap in quotes if contains comma, quote, or newline
            if (strpbrk($field, '",\n\r') !== false) {
                return '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $data);
        
        return implode(',', $escaped) . "\n";
    }
}