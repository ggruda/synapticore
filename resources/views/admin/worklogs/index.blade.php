@extends('layouts.admin')

@section('title', 'Worklogs Management')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">Worklogs Management</h1>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-success" onclick="exportCsv()">
                <i class="bi bi-download"></i> Export CSV
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Hours</h5>
                    <h2 class="mb-0">{{ number_format($stats['total_hours'], 1) }}</h2>
                    <small>{{ number_format($stats['count']) }} entries</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Average Duration</h5>
                    <h2 class="mb-0">{{ number_format($stats['avg_minutes'], 1) }} min</h2>
                    <small>per worklog</small>
                </div>
            </div>
        </div>
        @if(!empty($stats['by_phase']))
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">By Phase</h5>
                    <div class="row">
                        @foreach($stats['by_phase'] as $phase => $data)
                        <div class="col-6 col-md-4 mb-2">
                            <strong>{{ ucfirst($phase) }}:</strong><br>
                            {{ number_format($data['hours'], 1) }}h ({{ $data['count'] }} entries)
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.worklogs.index') }}" id="filterForm">
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Project</label>
                            <select name="project_id" class="form-select">
                                <option value="">All Projects</option>
                                @foreach($projects as $project)
                                <option value="{{ $project->id }}" {{ request('project_id') == $project->id ? 'selected' : '' }}>
                                    {{ $project->name }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label">Phase</label>
                            <select name="phase" class="form-select">
                                <option value="">All Phases</option>
                                @foreach($phases as $phase)
                                <option value="{{ $phase }}" {{ request('phase') == $phase ? 'selected' : '' }}>
                                    {{ ucfirst($phase) }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                @foreach($statuses as $status)
                                <option value="{{ $status }}" {{ request('status') == $status ? 'selected' : '' }}>
                                    {{ ucfirst(str_replace('_', ' ', $status)) }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                        </div>
                    </div>
                    <div class="col-md-1">
                        <div class="mb-3">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="{{ route('admin.worklogs.index') }}" class="btn btn-link">Clear</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label">Sync Status</label>
                            <select name="sync_status" class="form-select">
                                <option value="">All</option>
                                <option value="synced" {{ request('sync_status') == 'synced' ? 'selected' : '' }}>Synced</option>
                                <option value="unsynced" {{ request('sync_status') == 'unsynced' ? 'selected' : '' }}>Not Synced</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Worklogs Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date/Time</th>
                        <th>Project</th>
                        <th>Ticket</th>
                        <th>Phase</th>
                        <th>Duration</th>
                        <th>User</th>
                        <th>Status</th>
                        <th>Synced</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($worklogs as $worklog)
                    <tr>
                        <td>{{ $worklog->id }}</td>
                        <td>
                            {{ $worklog->started_at->format('d.m.Y H:i') }}
                            @if($worklog->ended_at)
                            <br><small class="text-muted">→ {{ $worklog->ended_at->format('H:i') }}</small>
                            @endif
                        </td>
                        <td>{{ $worklog->ticket->project->name ?? 'N/A' }}</td>
                        <td>
                            <a href="{{ route('admin.tickets.show', $worklog->ticket_id) }}" target="_blank">
                                {{ $worklog->ticket->external_key ?? 'N/A' }}
                            </a>
                        </td>
                        <td>
                            <span class="badge bg-secondary">{{ ucfirst($worklog->phase) }}</span>
                        </td>
                        <td>
                            <strong>{{ $worklog->seconds >= 3600 ? number_format($worklog->seconds / 3600, 2) . 'h' : number_format($worklog->seconds / 60, 1) . 'm' }}</strong>
                            <br><small class="text-muted">{{ number_format($worklog->seconds) }}s</small>
                        </td>
                        <td>{{ $worklog->user->name ?? 'System' }}</td>
                        <td>
                            @if($worklog->status === 'completed')
                                <span class="badge bg-success">Completed</span>
                            @elseif($worklog->status === 'in_progress')
                                <span class="badge bg-warning">In Progress</span>
                            @else
                                <span class="badge bg-danger">Failed</span>
                            @endif
                        </td>
                        <td>
                            @if($worklog->synced_at)
                                <span class="badge bg-success" title="{{ $worklog->synced_at->format('d.m.Y H:i:s') }}">
                                    <i class="bi bi-check-circle"></i> Yes
                                </span>
                                @if($worklog->sync_status === 'failed')
                                <br><small class="text-danger">{{ $worklog->sync_error }}</small>
                                @endif
                            @else
                                <span class="badge bg-secondary">
                                    <i class="bi bi-x-circle"></i> No
                                </span>
                            @endif
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="{{ route('admin.worklogs.show', $worklog) }}" class="btn btn-outline-info" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if(!$worklog->synced_at && $worklog->status === 'completed')
                                <form method="POST" action="{{ route('admin.worklogs.sync', $worklog) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-primary" title="Sync to Jira">
                                        <i class="bi bi-cloud-upload"></i>
                                    </button>
                                </form>
                                @endif
                                <form method="POST" action="{{ route('admin.worklogs.destroy', $worklog) }}" class="d-inline" onsubmit="return confirm('Are you sure?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            <p class="text-muted mb-0">No worklogs found</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($worklogs->hasPages())
        <div class="card-footer">
            {{ $worklogs->links() }}
        </div>
        @endif
    </div>
</div>

<script>
function exportCsv() {
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams(new FormData(form));
    window.location.href = '{{ route("admin.worklogs.export") }}?' + params.toString();
}
</script>
@endsection