@extends('layouts.admin')

@section('title', 'Worklog Details')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">Worklog #{{ $worklog->id }}</h1>
        </div>
        <div class="col-auto">
            <a href="{{ route('admin.worklogs.index') }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Worklog Information</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted">Project</label>
                            <p class="mb-0"><strong>{{ $worklog->ticket->project->name ?? 'N/A' }}</strong></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted">Ticket</label>
                            <p class="mb-0">
                                <a href="{{ route('admin.tickets.show', $worklog->ticket_id) }}" target="_blank">
                                    <strong>{{ $worklog->ticket->external_key ?? 'N/A' }}</strong>
                                </a>
                                <br>
                                <small>{{ $worklog->ticket->title ?? '' }}</small>
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted">Phase</label>
                            <p class="mb-0">
                                <span class="badge bg-secondary">{{ ucfirst($worklog->phase) }}</span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted">Status</label>
                            <p class="mb-0">
                                @if($worklog->status === 'completed')
                                    <span class="badge bg-success">Completed</span>
                                @elseif($worklog->status === 'in_progress')
                                    <span class="badge bg-warning">In Progress</span>
                                @else
                                    <span class="badge bg-danger">Failed</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted">Started At</label>
                            <p class="mb-0"><strong>{{ $worklog->started_at->format('d.m.Y H:i:s') }}</strong></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted">Ended At</label>
                            <p class="mb-0">
                                @if($worklog->ended_at)
                                    <strong>{{ $worklog->ended_at->format('d.m.Y H:i:s') }}</strong>
                                @else
                                    <span class="text-muted">Not finished</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted">Duration</label>
                            <p class="mb-0">
                                <strong class="h4">
                                    @if($worklog->seconds >= 3600)
                                        {{ number_format($worklog->seconds / 3600, 2) }} hours
                                    @else
                                        {{ number_format($worklog->seconds / 60, 1) }} minutes
                                    @endif
                                </strong>
                                <br>
                                <small class="text-muted">{{ number_format($worklog->seconds) }} seconds</small>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted">User</label>
                            <p class="mb-0"><strong>{{ $worklog->user->name ?? 'System' }}</strong></p>
                        </div>
                    </div>

                    @if($worklog->notes)
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="text-muted">Notes</label>
                            <p class="mb-0">{{ $worklog->notes }}</p>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Sync Information</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted">Sync Status</label>
                        <p class="mb-0">
                            @if($worklog->synced_at)
                                <span class="badge bg-success">
                                    <i class="bi bi-check-circle"></i> Synced
                                </span>
                            @else
                                <span class="badge bg-secondary">
                                    <i class="bi bi-x-circle"></i> Not Synced
                                </span>
                            @endif
                        </p>
                    </div>

                    @if($worklog->synced_at)
                    <div class="mb-3">
                        <label class="text-muted">Synced At</label>
                        <p class="mb-0">{{ $worklog->synced_at->format('d.m.Y H:i:s') }}</p>
                    </div>
                    @endif

                    @if($worklog->sync_status)
                    <div class="mb-3">
                        <label class="text-muted">Sync Result</label>
                        <p class="mb-0">
                            @if($worklog->sync_status === 'success')
                                <span class="text-success">Success</span>
                            @else
                                <span class="text-danger">Failed</span>
                            @endif
                        </p>
                    </div>
                    @endif

                    @if($worklog->sync_error)
                    <div class="mb-3">
                        <label class="text-muted">Sync Error</label>
                        <p class="mb-0 text-danger">{{ $worklog->sync_error }}</p>
                    </div>
                    @endif

                    @if(!$worklog->synced_at && $worklog->status === 'completed')
                    <form method="POST" action="{{ route('admin.worklogs.sync', $worklog) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-cloud-upload"></i> Sync to Jira
                        </button>
                    </form>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Actions</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.worklogs.destroy', $worklog) }}" onsubmit="return confirm('Are you sure you want to delete this worklog?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Delete Worklog
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Metadata</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <label class="text-muted">Created At</label>
                    <p>{{ $worklog->created_at->format('d.m.Y H:i:s') }}</p>
                </div>
                <div class="col-md-6">
                    <label class="text-muted">Updated At</label>
                    <p>{{ $worklog->updated_at->format('d.m.Y H:i:s') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection