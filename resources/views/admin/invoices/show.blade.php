@extends('layouts.admin')

@section('title', 'Invoice ' . $invoice->invoice_number)

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">Invoice {{ $invoice->invoice_number }}</h1>
        </div>
        <div class="col-auto">
            <a href="{{ route('admin.invoices.index') }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            @if($invoice->status === 'draft')
            <a href="{{ route('admin.invoices.edit', $invoice) }}" class="btn btn-primary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            <!-- Invoice Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Invoice Details</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted">Invoice Number</label>
                            <p class="mb-0"><strong class="h5">{{ $invoice->invoice_number }}</strong></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted">Status</label>
                            <p class="mb-0">
                                @if($invoice->status === 'draft')
                                    <span class="badge bg-secondary fs-6">Draft</span>
                                @elseif($invoice->status === 'sent')
                                    <span class="badge bg-primary fs-6">Sent</span>
                                @elseif($invoice->status === 'paid')
                                    <span class="badge bg-success fs-6">Paid</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted">Project</label>
                            <p class="mb-0"><strong>{{ $invoice->project->name }}</strong></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted">Period</label>
                            <p class="mb-0">
                                <strong>
                                    {{ Carbon\Carbon::parse($invoice->period_start)->format('d.m.Y') }} -
                                    {{ Carbon\Carbon::parse($invoice->period_end)->format('d.m.Y') }}
                                </strong>
                            </p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="text-muted">Created Date</label>
                            <p class="mb-0">{{ $invoice->created_at->format('d.m.Y') }}</p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted">Due Date</label>
                            <p class="mb-0">
                                <strong>{{ $invoice->due_date->format('d.m.Y') }}</strong>
                                @if($invoice->status === 'sent' && $invoice->due_date->isPast())
                                    <span class="badge bg-danger ms-2">Overdue by {{ $invoice->due_date->diffInDays() }} days</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    @if($invoice->notes)
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="text-muted">Notes</label>
                            <p class="mb-0">{{ $invoice->notes }}</p>
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Invoice Items -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Invoice Items</h5>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 50%">Description</th>
                                <th>Quantity</th>
                                <th>Unit</th>
                                <th>Unit Price</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->items as $item)
                            <tr>
                                <td>
                                    {{ $item->description }}
                                    @if(!empty($item->meta['ticket_key']))
                                        <br><small class="text-muted">Ticket: {{ $item->meta['ticket_key'] }}</small>
                                    @endif
                                    @if(!empty($item->meta['actual_hours']))
                                        <br><small class="text-muted">Actual: {{ number_format($item->meta['actual_hours'], 2) }}h | {{ $item->meta['worklog_count'] ?? 0 }} entries</small>
                                    @endif
                                </td>
                                <td>{{ number_format($item->quantity, 2) }}</td>
                                <td>{{ $item->unit }}</td>
                                <td>{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($item->unit_price, 2) }}</td>
                                <td class="text-end">
                                    <strong>{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($item->amount, 2) }}</strong>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Subtotal:</th>
                                <th class="text-end">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($invoice->subtotal, 2) }}</th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-end">Tax ({{ number_format($invoice->tax_rate * 100, 1) }}%):</th>
                                <th class="text-end">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($invoice->tax_amount, 2) }}</th>
                            </tr>
                            <tr class="table-primary">
                                <th colspan="4" class="text-end">Total:</th>
                                <th class="text-end h5">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($invoice->total, 2) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Worklog Statistics -->
            @if($worklogStats)
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Worklog Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="text-muted">Total Worklogs</label>
                            <p class="mb-0"><strong>{{ $worklogStats['count'] }}</strong></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted">Total Hours</label>
                            <p class="mb-0"><strong>{{ number_format($worklogStats['total_hours'], 2) }}h</strong></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted">Billable Hours</label>
                            <p class="mb-0"><strong>{{ number_format($worklogStats['billable_hours'], 2) }}h</strong></p>
                        </div>
                    </div>
                    @if(!empty($worklogStats['by_phase']))
                    <hr>
                    <label class="text-muted">By Phase</label>
                    <div class="row">
                        @foreach($worklogStats['by_phase'] as $phase => $data)
                        <div class="col-md-4 mb-2">
                            <strong>{{ ucfirst($phase) }}:</strong> {{ number_format($data['hours'] ?? 0, 2) }}h
                            ({{ number_format($data['percentage'] ?? 0, 1) }}%)
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
            @endif
        </div>

        <div class="col-md-4">
            <!-- Actions -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Actions</h5>
                </div>
                <div class="card-body">
                    @if($invoice->pdf_path)
                        <a href="{{ route('admin.invoices.download-pdf', $invoice) }}" class="btn btn-success w-100 mb-2">
                            <i class="bi bi-download"></i> Download PDF
                        </a>
                    @endif
                    
                    <form method="POST" action="{{ route('admin.invoices.regenerate-pdf', $invoice) }}">
                        @csrf
                        <button type="submit" class="btn btn-warning w-100 mb-2">
                            <i class="bi bi-arrow-clockwise"></i> Regenerate PDF
                        </button>
                    </form>
                    
                    <form method="POST" action="{{ route('admin.invoices.resend-email', $invoice) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary w-100 mb-2">
                            <i class="bi bi-envelope"></i> {{ $invoice->sent_at ? 'Resend' : 'Send' }} Email
                        </button>
                    </form>
                    
                    @if($invoice->status === 'sent')
                    <form method="POST" action="{{ route('admin.invoices.mark-paid', $invoice) }}">
                        @csrf
                        <button type="submit" class="btn btn-success w-100 mb-2">
                            <i class="bi bi-check-circle"></i> Mark as Paid
                        </button>
                    </form>
                    @elseif($invoice->status === 'paid')
                    <form method="POST" action="{{ route('admin.invoices.mark-unpaid', $invoice) }}">
                        @csrf
                        <button type="submit" class="btn btn-warning w-100 mb-2">
                            <i class="bi bi-x-circle"></i> Mark as Unpaid
                        </button>
                    </form>
                    @endif
                    
                    @if($invoice->status === 'draft')
                    <hr>
                    <form method="POST" action="{{ route('admin.invoices.destroy', $invoice) }}" onsubmit="return confirm('Are you sure you want to delete this invoice?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="bi bi-trash"></i> Delete Invoice
                        </button>
                    </form>
                    @endif
                </div>
            </div>

            <!-- PDF & Email Status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">PDF & Email Status</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted">PDF Status</label>
                        <p class="mb-0">
                            @if($invoice->pdf_path)
                                <span class="badge bg-success">
                                    <i class="bi bi-file-pdf"></i> Generated
                                </span>
                                @if($invoice->pdf_generated_at)
                                    <br><small>{{ $invoice->pdf_generated_at->format('d.m.Y H:i:s') }}</small>
                                @endif
                            @else
                                <span class="badge bg-secondary">Not Generated</span>
                            @endif
                        </p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="text-muted">Email Status</label>
                        <p class="mb-0">
                            @if($invoice->sent_at)
                                <span class="badge bg-success">
                                    <i class="bi bi-envelope-check"></i> Sent
                                </span>
                                <br><small>{{ $invoice->sent_at->format('d.m.Y H:i:s') }}</small>
                                @if(!empty($invoice->meta['email_sent_to']))
                                    <br><small>To: {{ $invoice->meta['email_sent_to'] }}</small>
                                @endif
                            @else
                                <span class="badge bg-secondary">Not Sent</span>
                            @endif
                        </p>
                    </div>
                    
                    @if(!empty($invoice->meta['email_error']))
                    <div class="mb-3">
                        <label class="text-muted">Last Email Error</label>
                        <p class="mb-0 text-danger">
                            <small>{{ $invoice->meta['email_error'] }}</small>
                        </p>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Metadata -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Metadata</h5>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <label class="text-muted">Created</label>
                        <p class="mb-0">{{ $invoice->created_at->format('d.m.Y H:i:s') }}</p>
                    </div>
                    <div class="mb-2">
                        <label class="text-muted">Updated</label>
                        <p class="mb-0">{{ $invoice->updated_at->format('d.m.Y H:i:s') }}</p>
                    </div>
                    @if(!empty($invoice->meta['generated_by']))
                    <div class="mb-2">
                        <label class="text-muted">Generated By</label>
                        <p class="mb-0">{{ $invoice->meta['generated_by'] }}</p>
                    </div>
                    @endif
                    @if(!empty($invoice->meta['paid_at']))
                    <div class="mb-2">
                        <label class="text-muted">Paid At</label>
                        <p class="mb-0">{{ $invoice->meta['paid_at'] }}</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection