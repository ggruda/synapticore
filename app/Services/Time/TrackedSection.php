<?php

declare(strict_types=1);

namespace App\Services\Time;

use App\Contracts\TicketProviderContract;
use App\Models\Ticket;
use App\Models\Worklog;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service for tracking time spent on different phases of work.
 */
class TrackedSection
{
    /**
     * Run a closure while tracking time spent.
     *
     * @template T
     *
     * @param  Ticket  $ticket  The ticket being worked on
     * @param  string  $phase  The phase of work (plan, implement, test, review, pr)
     * @param  Closure(): T  $fn  The work to be done
     * @param  string|null  $notes  Optional notes about the work
     * @return T The result of the closure
     */
    public function run(
        Ticket $ticket,
        string $phase,
        Closure $fn,
        ?string $notes = null
    ): mixed {
        $startedAt = Carbon::now();
        $result = null;
        $exception = null;

        try {
            // Execute the work
            $result = $fn();
        } catch (Throwable $e) {
            // Capture exception to rethrow after logging
            $exception = $e;
        } finally {
            // Always track time, even if the work failed
            $endedAt = Carbon::now();
            $seconds = (int) $startedAt->diffInSeconds($endedAt);

            // Persist worklog to database
            $worklog = $this->persistWorklog(
                $ticket,
                $phase,
                $startedAt,
                $endedAt,
                $seconds,
                $notes,
                $exception !== null
            );

            // Push to ticket provider if configured for immediate mode
            if (config('synaptic.worklog.push_mode') === 'immediate') {
                $this->pushToTicketProvider($ticket, $worklog);
            }

            // Log the tracked time
            Log::info('Tracked work section', [
                'ticket_id' => $ticket->id,
                'phase' => $phase,
                'seconds' => $seconds,
                'duration' => $this->formatDuration($seconds),
                'notes' => $notes,
                'failed' => $exception !== null,
            ]);
        }

        // Rethrow exception if work failed
        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    /**
     * Track time for an async job (start only).
     */
    public function startAsync(
        Ticket $ticket,
        string $phase,
        ?string $notes = null
    ): Worklog {
        $startedAt = Carbon::now();

        // Create worklog with null ended_at
        $worklog = Worklog::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id() ?? 1, // System user if not authenticated
            'started_at' => $startedAt,
            'ended_at' => null,
            'seconds' => 0,
            'phase' => $phase,
            'notes' => $notes,
            'status' => 'in_progress',
        ]);

        Log::info('Started async work tracking', [
            'ticket_id' => $ticket->id,
            'phase' => $phase,
            'worklog_id' => $worklog->id,
        ]);

        return $worklog;
    }

    /**
     * Complete an async worklog.
     */
    public function completeAsync(
        Worklog $worklog,
        bool $failed = false
    ): void {
        $endedAt = Carbon::now();
        $seconds = (int) $worklog->started_at->diffInSeconds($endedAt);

        $worklog->update([
            'ended_at' => $endedAt,
            'seconds' => $seconds,
            'status' => $failed ? 'failed' : 'completed',
        ]);

        // Push to ticket provider if configured for immediate mode
        if (config('synaptic.worklog.push_mode') === 'immediate' && ! $failed) {
            $ticket = $worklog->ticket;
            if ($ticket) {
                $this->pushToTicketProvider($ticket, $worklog);
            }
        }

        Log::info('Completed async work tracking', [
            'worklog_id' => $worklog->id,
            'seconds' => $seconds,
            'duration' => $this->formatDuration($seconds),
            'failed' => $failed,
        ]);
    }

    /**
     * Persist worklog to database.
     */
    private function persistWorklog(
        Ticket $ticket,
        string $phase,
        Carbon $startedAt,
        Carbon $endedAt,
        int $seconds,
        ?string $notes,
        bool $failed
    ): Worklog {
        return Worklog::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id() ?? 1, // System user if not authenticated
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'seconds' => $seconds,
            'phase' => $phase,
            'notes' => $notes,
            'status' => $failed ? 'failed' : 'completed',
        ]);
    }

    /**
     * Push worklog to ticket provider.
     */
    public function pushToTicketProvider(Ticket $ticket, Worklog $worklog): void
    {
        try {
            // Resolve ticket provider
            $ticketProvider = app(TicketProviderContract::class);

            // Build notes for ticket system
            $notes = $this->buildTicketNotes($worklog);

            // Add worklog to ticket system
            $ticketProvider->addWorklog(
                $ticket->external_key,
                $worklog->seconds,
                $worklog->started_at,
                $notes
            );

            // Mark as synced
            $worklog->update([
                'synced_at' => Carbon::now(),
                'sync_status' => 'success',
            ]);

            Log::info('Pushed worklog to ticket provider', [
                'ticket_key' => $ticket->external_key,
                'worklog_id' => $worklog->id,
                'seconds' => $worklog->seconds,
            ]);

        } catch (Throwable $e) {
            Log::error('Failed to push worklog to ticket provider', [
                'ticket_key' => $ticket->external_key,
                'worklog_id' => $worklog->id,
                'error' => $e->getMessage(),
            ]);

            // Mark sync failure
            $worklog->update([
                'sync_status' => 'failed',
                'sync_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build notes for ticket system.
     */
    private function buildTicketNotes(Worklog $worklog): string
    {
        $notes = "Synapticore Bot - {$this->getPhaseLabel($worklog->phase)}";

        if ($worklog->notes) {
            $notes .= "\n\n{$worklog->notes}";
        }

        $notes .= "\n\nAutomated time tracking by Synapticore";

        return $notes;
    }

    /**
     * Get human-readable phase label.
     */
    private function getPhaseLabel(string $phase): string
    {
        return match ($phase) {
            'plan' => 'Planning & Analysis',
            'implement' => 'Implementation',
            'test' => 'Running Tests & Checks',
            'review' => 'Code Review',
            'pr' => 'Creating Pull Request',
            'repair' => 'Self-Healing Repair',
            'context' => 'Building Context',
            default => ucfirst($phase),
        };
    }

    /**
     * Format duration in human-readable format.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0
                ? "{$minutes}m {$remainingSeconds}s"
                : "{$minutes}m";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes > 0) {
            return "{$hours}h {$remainingMinutes}m";
        }

        return "{$hours}h";
    }

    /**
     * Get total time spent on a ticket.
     */
    public function getTotalTime(Ticket $ticket): array
    {
        $worklogs = Worklog::where('ticket_id', $ticket->id)
            ->where('status', 'completed')
            ->get();

        $totalSeconds = $worklogs->sum('seconds');
        $byPhase = $worklogs->groupBy('phase')
            ->map(fn ($logs) => $logs->sum('seconds'))
            ->toArray();

        return [
            'total_seconds' => $totalSeconds,
            'total_formatted' => $this->formatDuration($totalSeconds),
            'by_phase' => $byPhase,
            'by_phase_formatted' => array_map(
                fn ($seconds) => $this->formatDuration($seconds),
                $byPhase
            ),
        ];
    }

    /**
     * Batch sync worklogs to ticket provider.
     */
    public function batchSync(?int $limit = 100): int
    {
        $worklogs = Worklog::whereNull('synced_at')
            ->where('status', 'completed')
            ->with('ticket')
            ->limit($limit)
            ->get();

        $synced = 0;

        foreach ($worklogs as $worklog) {
            if ($worklog->ticket) {
                $this->pushToTicketProvider($worklog->ticket, $worklog);
                $synced++;
            }
        }

        Log::info('Batch synced worklogs', [
            'count' => $synced,
            'remaining' => Worklog::whereNull('synced_at')->count(),
        ]);

        return $synced;
    }
}
