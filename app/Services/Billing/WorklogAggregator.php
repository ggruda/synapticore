<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Project;
use App\Models\Worklog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for aggregating worklogs for billing purposes.
 */
class WorklogAggregator
{
    /**
     * Aggregate worklogs for a project within a date range.
     *
     * @return array{
     *     total_seconds: int,
     *     total_hours: float,
     *     billable_hours: float,
     *     items: Collection,
     *     worklog_ids: array<int>,
     *     by_phase: array<string, array>,
     *     by_ticket: array<string, array>
     * }
     */
    public function aggregateForProject(
        Project $project,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        // Fetch worklogs for the period
        $worklogs = Worklog::with(['ticket', 'user'])
            ->whereHas('ticket', function ($query) use ($project) {
                $query->where('project_id', $project->id);
            })
            ->where('status', 'completed')
            ->whereBetween('started_at', [$startDate, $endDate])
            ->orderBy('started_at')
            ->get();

        if ($worklogs->isEmpty()) {
            return [
                'total_seconds' => 0,
                'total_hours' => 0.0,
                'billable_hours' => 0.0,
                'items' => collect(),
                'worklog_ids' => [],
                'by_phase' => [],
                'by_ticket' => [],
            ];
        }

        // Calculate totals
        $totalSeconds = $worklogs->sum('seconds');
        $totalHours = $this->secondsToHours($totalSeconds);
        $billableHours = $this->applyRounding($totalHours);

        // Group by phase
        $byPhase = $this->groupByPhase($worklogs);

        // Group by ticket
        $byTicket = $this->groupByTicket($worklogs);

        // Create line items for invoice
        $items = $this->createLineItems($byTicket);

        Log::info('Aggregated worklogs for billing', [
            'project' => $project->name,
            'period' => "{$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}",
            'worklog_count' => $worklogs->count(),
            'total_seconds' => $totalSeconds,
            'total_hours' => $totalHours,
            'billable_hours' => $billableHours,
        ]);

        return [
            'total_seconds' => $totalSeconds,
            'total_hours' => $totalHours,
            'billable_hours' => $billableHours,
            'items' => $items,
            'worklog_ids' => $worklogs->pluck('id')->toArray(),
            'by_phase' => $byPhase,
            'by_ticket' => $byTicket,
        ];
    }

    /**
     * Get all projects with billable worklogs for a period.
     *
     * @return Collection<Project>
     */
    public function getProjectsWithBillableWork(
        Carbon $startDate,
        Carbon $endDate
    ): Collection {
        return Project::whereHas('tickets.worklogs', function ($query) use ($startDate, $endDate) {
            $query->where('status', 'completed')
                ->whereBetween('started_at', [$startDate, $endDate]);
        })->get();
    }

    /**
     * Convert seconds to hours.
     */
    private function secondsToHours(int $seconds): float
    {
        return round($seconds / 3600, 4);
    }

    /**
     * Apply rounding rules to hours.
     */
    private function applyRounding(float $hours): float
    {
        if (! config('billing.hours_rounding.enabled')) {
            return $hours;
        }

        $increment = config('billing.hours_rounding.increment', 0.25);
        $minimum = config('billing.hours_rounding.minimum', 0.25);

        // Apply minimum
        if ($hours > 0 && $hours < $minimum) {
            return $minimum;
        }

        // Round to nearest increment
        return round($hours / $increment) * $increment;
    }

    /**
     * Group worklogs by phase.
     */
    private function groupByPhase(Collection $worklogs): array
    {
        $grouped = [];

        foreach ($worklogs->groupBy('phase') as $phase => $phaseWorklogs) {
            $seconds = $phaseWorklogs->sum('seconds');
            $hours = $this->secondsToHours($seconds);

            $grouped[$phase] = [
                'seconds' => $seconds,
                'hours' => $hours,
                'billable_hours' => $this->applyRounding($hours),
                'count' => $phaseWorklogs->count(),
                'percentage' => 0, // Will be calculated later
            ];
        }

        // Calculate percentages
        $totalSeconds = array_sum(array_column($grouped, 'seconds'));
        foreach ($grouped as $phase => &$data) {
            $data['percentage'] = $totalSeconds > 0
                ? round(($data['seconds'] / $totalSeconds) * 100, 1)
                : 0;
        }

        return $grouped;
    }

    /**
     * Group worklogs by ticket.
     */
    private function groupByTicket(Collection $worklogs): array
    {
        $grouped = [];

        foreach ($worklogs->groupBy('ticket_id') as $ticketId => $ticketWorklogs) {
            $ticket = $ticketWorklogs->first()->ticket;
            $seconds = $ticketWorklogs->sum('seconds');
            $hours = $this->secondsToHours($seconds);

            $grouped[$ticket->external_key] = [
                'ticket_id' => $ticketId,
                'title' => $ticket->title,
                'external_key' => $ticket->external_key,
                'seconds' => $seconds,
                'hours' => $hours,
                'billable_hours' => $this->applyRounding($hours),
                'worklogs' => $ticketWorklogs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'phase' => $log->phase,
                        'seconds' => $log->seconds,
                        'hours' => $this->secondsToHours($log->seconds),
                        'date' => $log->started_at->format('Y-m-d'),
                        'notes' => $log->notes,
                        'user' => $log->user?->name ?? 'System',
                    ];
                })->toArray(),
            ];
        }

        return $grouped;
    }

    /**
     * Create line items for invoice.
     */
    private function createLineItems(array $byTicket): Collection
    {
        $items = collect();

        foreach ($byTicket as $ticketKey => $data) {
            if ($data['billable_hours'] > 0) {
                $items->push([
                    'description' => "[{$ticketKey}] {$data['title']}",
                    'quantity' => $data['billable_hours'],
                    'unit' => 'Stunden',
                    'unit_price' => config('billing.unit_price_per_hour'),
                    'amount' => $data['billable_hours'] * config('billing.unit_price_per_hour'),
                    'meta' => [
                        'ticket_id' => $data['ticket_id'],
                        'ticket_key' => $ticketKey,
                        'actual_hours' => $data['hours'],
                        'worklog_count' => count($data['worklogs']),
                    ],
                ]);
            }
        }

        // Sort by ticket key
        return $items->sortBy('description');
    }

    /**
     * Get summary statistics for a period.
     */
    public function getPeriodSummary(Carbon $startDate, Carbon $endDate): array
    {
        $projects = $this->getProjectsWithBillableWork($startDate, $endDate);
        $totalSeconds = 0;
        $totalBillableHours = 0;
        $projectSummaries = [];

        foreach ($projects as $project) {
            $aggregated = $this->aggregateForProject($project, $startDate, $endDate);
            $totalSeconds += $aggregated['total_seconds'];
            $totalBillableHours += $aggregated['billable_hours'];

            $projectSummaries[] = [
                'project' => $project->name,
                'hours' => $aggregated['total_hours'],
                'billable_hours' => $aggregated['billable_hours'],
                'amount' => $aggregated['billable_hours'] * config('billing.unit_price_per_hour'),
            ];
        }

        return [
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
                'month' => $startDate->format('F Y'),
            ],
            'projects_count' => $projects->count(),
            'total_seconds' => $totalSeconds,
            'total_hours' => $this->secondsToHours($totalSeconds),
            'total_billable_hours' => $totalBillableHours,
            'total_amount' => $totalBillableHours * config('billing.unit_price_per_hour'),
            'projects' => $projectSummaries,
        ];
    }
}
