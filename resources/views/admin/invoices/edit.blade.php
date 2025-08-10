@extends('layouts.admin')

@section('title', 'Edit Invoice ' . $invoice->invoice_number)

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">Edit Invoice {{ $invoice->invoice_number }}</h1>
        </div>
        <div class="col-auto">
            <a href="{{ route('admin.invoices.show', $invoice) }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Cancel
            </a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.invoices.update', $invoice) }}" id="invoiceForm">
        @csrf
        @method('PUT')
        
        <div class="row">
            <div class="col-md-8">
                <!-- Invoice Details -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Invoice Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Invoice Number</label>
                                    <input type="text" class="form-control" value="{{ $invoice->invoice_number }}" readonly disabled>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Project</label>
                                    <input type="text" class="form-control" value="{{ $invoice->project->name }}" readonly disabled>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Period</label>
                                    <input type="text" class="form-control" 
                                           value="{{ Carbon\Carbon::parse($invoice->period_start)->format('d.m.Y') }} - {{ Carbon\Carbon::parse($invoice->period_end)->format('d.m.Y') }}" 
                                           readonly disabled>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="due_date">Due Date <span class="text-danger">*</span></label>
                                    <input type="date" 
                                           name="due_date" 
                                           id="due_date"
                                           class="form-control @error('due_date') is-invalid @enderror" 
                                           value="{{ old('due_date', $invoice->due_date->format('Y-m-d')) }}" 
                                           required>
                                    @error('due_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea name="notes" 
                                      id="notes"
                                      class="form-control @error('notes') is-invalid @enderror" 
                                      rows="3">{{ old('notes', $invoice->notes) }}</textarea>
                            @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Invoice Items -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Invoice Items</h5>
                        <button type="button" class="btn btn-sm btn-success" onclick="addItem()">
                            <i class="bi bi-plus-circle"></i> Add Item
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="items-container">
                            @foreach(old('items', $invoice->items->toArray()) as $index => $item)
                            <div class="item-row border rounded p-3 mb-3" data-index="{{ $index }}">
                                <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item['id'] ?? '' }}">
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label class="form-label">Description <span class="text-danger">*</span></label>
                                            <input type="text" 
                                                   name="items[{{ $index }}][description]" 
                                                   class="form-control @error('items.'.$index.'.description') is-invalid @enderror" 
                                                   value="{{ $item['description'] ?? '' }}" 
                                                   required>
                                            @error('items.'.$index.'.description')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                            <input type="number" 
                                                   step="0.01" 
                                                   name="items[{{ $index }}][quantity]" 
                                                   class="form-control quantity @error('items.'.$index.'.quantity') is-invalid @enderror" 
                                                   value="{{ $item['quantity'] ?? '' }}" 
                                                   onchange="calculateTotals()"
                                                   required>
                                            @error('items.'.$index.'.quantity')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Unit <span class="text-danger">*</span></label>
                                            <input type="text" 
                                                   name="items[{{ $index }}][unit]" 
                                                   class="form-control @error('items.'.$index.'.unit') is-invalid @enderror" 
                                                   value="{{ $item['unit'] ?? 'Stunden' }}" 
                                                   required>
                                            @error('items.'.$index.'.unit')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Unit Price <span class="text-danger">*</span></label>
                                            <input type="number" 
                                                   step="0.01" 
                                                   name="items[{{ $index }}][unit_price]" 
                                                   class="form-control unit-price @error('items.'.$index.'.unit_price') is-invalid @enderror" 
                                                   value="{{ $item['unit_price'] ?? '' }}" 
                                                   onchange="calculateTotals()"
                                                   required>
                                            @error('items.'.$index.'.unit_price')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">{{ config('billing.currency_symbol', 'CHF') }}</span>
                                                <input type="text" class="form-control amount" readonly value="0.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(this)">
                                        <i class="bi bi-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        
                        @if(empty(old('items', $invoice->items->toArray())))
                        <div class="text-center py-4 text-muted" id="no-items">
                            No items added. Click "Add Item" to start.
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Totals -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Totals</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal:</span>
                            <strong id="subtotal">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($invoice->subtotal, 2) }}</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Tax ({{ number_format($invoice->tax_rate * 100, 1) }}%):</span>
                            <strong id="tax">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($invoice->tax_amount, 2) }}</strong>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span class="h5">Total:</span>
                            <strong class="h5" id="total">{{ config('billing.currency_symbol', 'CHF') }} {{ number_format($invoice->total, 2) }}</strong>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="card">
                    <div class="card-body">
                        <button type="submit" class="btn btn-primary w-100 mb-2">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                        <a href="{{ route('admin.invoices.show', $invoice) }}" class="btn btn-secondary w-100">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let itemIndex = {{ count(old('items', $invoice->items->toArray())) }};
const taxRate = {{ $invoice->tax_rate }};
const currencySymbol = '{{ config('billing.currency_symbol', 'CHF') }}';

function addItem() {
    const container = document.getElementById('items-container');
    const noItems = document.getElementById('no-items');
    
    if (noItems) {
        noItems.remove();
    }
    
    const itemHtml = `
        <div class="item-row border rounded p-3 mb-3" data-index="${itemIndex}">
            <input type="hidden" name="items[${itemIndex}][id]" value="">
            
            <div class="row">
                <div class="col-md-12">
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <input type="text" 
                               name="items[${itemIndex}][description]" 
                               class="form-control" 
                               required>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3">
                    <div class="mb-3">
                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" 
                               step="0.01" 
                               name="items[${itemIndex}][quantity]" 
                               class="form-control quantity" 
                               value="1"
                               onchange="calculateTotals()"
                               required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                        <input type="text" 
                               name="items[${itemIndex}][unit]" 
                               class="form-control" 
                               value="Stunden"
                               required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label class="form-label">Unit Price <span class="text-danger">*</span></label>
                        <input type="number" 
                               step="0.01" 
                               name="items[${itemIndex}][unit_price]" 
                               class="form-control unit-price" 
                               value="{{ config('billing.unit_price_per_hour', 150) }}"
                               onchange="calculateTotals()"
                               required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label class="form-label">Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">${currencySymbol}</span>
                            <input type="text" class="form-control amount" readonly value="0.00">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="text-end">
                <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(this)">
                    <i class="bi bi-trash"></i> Remove
                </button>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', itemHtml);
    itemIndex++;
    calculateTotals();
}

function removeItem(button) {
    if (confirm('Are you sure you want to remove this item?')) {
        button.closest('.item-row').remove();
        calculateTotals();
        
        // Show "no items" message if all items removed
        const container = document.getElementById('items-container');
        if (container.children.length === 0) {
            container.insertAdjacentHTML('afterend', 
                '<div class="text-center py-4 text-muted" id="no-items">No items added. Click "Add Item" to start.</div>'
            );
        }
    }
}

function calculateTotals() {
    let subtotal = 0;
    
    document.querySelectorAll('.item-row').forEach(row => {
        const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price').value) || 0;
        const amount = quantity * unitPrice;
        
        row.querySelector('.amount').value = amount.toFixed(2);
        subtotal += amount;
    });
    
    const taxAmount = subtotal * taxRate;
    const total = subtotal + taxAmount;
    
    document.getElementById('subtotal').textContent = currencySymbol + ' ' + subtotal.toFixed(2);
    document.getElementById('tax').textContent = currencySymbol + ' ' + taxAmount.toFixed(2);
    document.getElementById('total').textContent = currencySymbol + ' ' + total.toFixed(2);
}

// Calculate totals on page load
document.addEventListener('DOMContentLoaded', calculateTotals);
</script>
@endsection