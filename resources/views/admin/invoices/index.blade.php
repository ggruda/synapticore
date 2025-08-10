@extends('layouts.admin')

@section('title', 'Invoices Management')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">Invoices Management</h1>
        </div>
        <div class="col-auto">
            <a href="{{ route('admin.invoices.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Create Invoice
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Amount</h5>
                    <h2 class="mb-0">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($stats['total_amount'], 2) }}</h2>
                    <small>{{ $stats['count'] }} invoices</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Average Invoice</h5>
                    <h2 class="mb-0">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($stats['avg_amount'], 2) }}</h2>
                    <small>per invoice</small>
                </div>
            </div>
        </div>
        @if($stats['overdue_amount'] > 0)
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Overdue Amount</h5>
                    <h2 class="mb-0">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($stats['overdue_amount'], 2) }}</h2>
                    <small>needs attention</small>
                </div>
            </div>
        </div>
        @endif
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">By Status</h5>
                    @foreach($stats['by_status'] as $status => $data)
                    <div class="d-flex justify-content-between mb-1">
                        <span>
                            @if($status === 'draft')
                                <span class="badge bg-secondary">Draft</span>
                            @elseif($status === 'sent')
                                <span class="badge bg-primary">Sent</span>
                            @elseif($status === 'paid')
                                <span class="badge bg-success">Paid</span>
                            @endif
                        </span>
                        <strong>{{ $data['count'] }}</strong>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.invoices.index') }}">
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
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                @foreach($statuses as $status)
                                <option value="{{ $status }}" {{ request('status') == $status ? 'selected' : '' }}>
                                    {{ ucfirst($status) }}
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
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label class="form-label">Overdue</label>
                            <select name="overdue" class="form-select">
                                <option value="">All</option>
                                <option value="yes" {{ request('overdue') == 'yes' ? 'selected' : '' }}>Overdue Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-1">
                        <div class="mb-3">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="{{ route('admin.invoices.index') }}" class="btn btn-link">Clear</a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Invoices Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Project</th>
                        <th>Period</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>PDF</th>
                        <th>Sent</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $invoice)
                    <tr @if($invoice->status === 'sent' && $invoice->due_date->isPast()) class="table-warning" @endif>
                        <td>
                            <a href="{{ route('admin.invoices.show', $invoice) }}">
                                <strong>{{ $invoice->invoice_number }}</strong>
                            </a>
                        </td>
                        <td>{{ $invoice->project->name }}</td>
                        <td>
                            {{ Carbon\Carbon::parse($invoice->period_start)->format('d.m.Y') }} -
                            {{ Carbon\Carbon::parse($invoice->period_end)->format('d.m.Y') }}
                        </td>
                        <td>
                            <strong>{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($invoice->total, 2) }}</strong>
                            <br>
                            <small class="text-muted">
                                Subtotal: {{ number_format($invoice->subtotal, 2) }}<br>
                                Tax: {{ number_format($invoice->tax_amount, 2) }}
                            </small>
                        </td>
                        <td>
                            {{ $invoice->due_date->format('d.m.Y') }}
                            @if($invoice->status === 'sent' && $invoice->due_date->isPast())
                                <br><span class="badge bg-danger">Overdue</span>
                            @endif
                        </td>
                        <td>
                            @if($invoice->status === 'draft')
                                <span class="badge bg-secondary">Draft</span>
                            @elseif($invoice->status === 'sent')
                                <span class="badge bg-primary">Sent</span>
                            @elseif($invoice->status === 'paid')
                                <span class="badge bg-success">Paid</span>
                            @endif
                        </td>
                        <td>
                            @if($invoice->pdf_path)
                                <span class="badge bg-success" title="Generated {{ $invoice->pdf_generated_at?->format('d.m.Y H:i') }}">
                                    <i class="bi bi-file-pdf"></i> Yes
                                </span>
                            @else
                                <span class="badge bg-secondary">No</span>
                            @endif
                        </td>
                        <td>
                            @if($invoice->sent_at)
                                <span class="badge bg-success" title="{{ $invoice->sent_at->format('d.m.Y H:i') }}">
                                    <i class="bi bi-envelope-check"></i> Yes
                                </span>
                            @else
                                <span class="badge bg-secondary">No</span>
                            @endif
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="{{ route('admin.invoices.show', $invoice) }}" class="btn btn-outline-info" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if($invoice->status === 'draft')
                                <a href="{{ route('admin.invoices.edit', $invoice) }}" class="btn btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @endif
                                @if($invoice->pdf_path)
                                <a href="{{ route('admin.invoices.download-pdf', $invoice) }}" class="btn btn-outline-success" title="Download PDF">
                                    <i class="bi bi-download"></i>
                                </a>
                                @endif
                                @if($invoice->status === 'draft')
                                <form method="POST" action="{{ route('admin.invoices.destroy', $invoice) }}" class="d-inline" onsubmit="return confirm('Are you sure?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <p class="text-muted mb-0">No invoices found</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($invoices->hasPages())
        <div class="card-footer">
            {{ $invoices->links() }}
        </div>
        @endif
    </div>
</div>
@endsection