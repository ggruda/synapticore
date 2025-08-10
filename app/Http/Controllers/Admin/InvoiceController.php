<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Services\Billing\InvoiceMailer;
use App\Services\Billing\InvoicePdfGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Admin controller for managing invoices.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private InvoicePdfGenerator $pdfGenerator,
        private InvoiceMailer $mailer
    ) {}
    
    /**
     * Display a listing of invoices with filters.
     */
    public function index(Request $request): View
    {
        Gate::authorize('admin');
        
        // Build query
        $query = Invoice::with(['project', 'items']);
        
        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Filter by project
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        
        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        // Filter by overdue
        if ($request->get('overdue') === 'yes') {
            $query->where('status', 'sent')
                ->where('due_date', '<', Carbon::today());
        }
        
        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);
        
        // Paginate
        $invoices = $query->paginate(25)->withQueryString();
        
        // Get filter options
        $projects = Project::orderBy('name')->get();
        $statuses = ['draft', 'sent', 'paid'];
        
        // Calculate statistics
        $stats = $this->calculateStatistics($request);
        
        return view('admin.invoices.index', compact(
            'invoices',
            'projects',
            'statuses',
            'stats'
        ));
    }
    
    /**
     * Show form to create a new invoice.
     */
    public function create(): View
    {
        Gate::authorize('admin');
        
        $projects = Project::orderBy('name')->get();
        
        return view('admin.invoices.create', compact('projects'));
    }
    
    /**
     * Store a newly created invoice.
     */
    public function store(Request $request)
    {
        Gate::authorize('admin');
        
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'due_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:20',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);
        
        DB::transaction(function () use ($validated) {
            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }
            
            $taxRate = config('billing.tax_rate', 0.077);
            $taxAmount = round($subtotal * $taxRate, 2);
            $total = $subtotal + $taxAmount;
            
            // Generate invoice number
            $project = Project::find($validated['project_id']);
            $numberGenerator = app(\App\Services\Billing\InvoiceNumberGenerator::class);
            $invoiceNumber = $numberGenerator->generate($project, Carbon::parse($validated['period_start']));
            
            // Create invoice
            $invoice = Invoice::create([
                'project_id' => $validated['project_id'],
                'invoice_number' => $invoiceNumber,
                'period_start' => $validated['period_start'],
                'period_end' => $validated['period_end'],
                'due_date' => $validated['due_date'],
                'currency' => config('billing.default_currency', 'CHF'),
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'notes' => $validated['notes'] ?? null,
                'status' => 'draft',
                'meta' => [
                    'created_by' => auth()->user()->name,
                    'created_manually' => true,
                ],
            ]);
            
            // Create items
            foreach ($validated['items'] as $itemData) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit' => $itemData['unit'],
                    'unit_price' => $itemData['unit_price'],
                    'amount' => $itemData['quantity'] * $itemData['unit_price'],
                ]);
            }
            
            return $invoice;
        });
        
        return redirect()
            ->route('admin.invoices.index')
            ->with('success', 'Invoice created successfully');
    }
    
    /**
     * Show invoice details.
     */
    public function show(Invoice $invoice): View
    {
        Gate::authorize('admin');
        
        $invoice->load(['project', 'items']);
        
        // Get worklog statistics if available
        $worklogStats = null;
        if (!empty($invoice->meta['worklog_ids'])) {
            $worklogStats = [
                'count' => count($invoice->meta['worklog_ids']),
                'total_hours' => $invoice->meta['total_hours'] ?? 0,
                'billable_hours' => $invoice->meta['billable_hours'] ?? 0,
                'by_phase' => $invoice->meta['by_phase'] ?? [],
            ];
        }
        
        return view('admin.invoices.show', compact('invoice', 'worklogStats'));
    }
    
    /**
     * Show form to edit invoice.
     */
    public function edit(Invoice $invoice): View
    {
        Gate::authorize('admin');
        
        // Only allow editing draft invoices
        if ($invoice->status !== 'draft') {
            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be edited');
        }
        
        $invoice->load(['project', 'items']);
        
        return view('admin.invoices.edit', compact('invoice'));
    }
    
    /**
     * Update invoice.
     */
    public function update(Request $request, Invoice $invoice)
    {
        Gate::authorize('admin');
        
        // Only allow editing draft invoices
        if ($invoice->status !== 'draft') {
            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be edited');
        }
        
        $validated = $request->validate([
            'due_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:invoice_items,id',
            'items.*.description' => 'required|string|max:500',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string|max:20',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);
        
        DB::transaction(function () use ($invoice, $validated) {
            // Update invoice
            $invoice->update([
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes'] ?? null,
            ]);
            
            // Update/create items
            $processedIds = [];
            $subtotal = 0;
            
            foreach ($validated['items'] as $itemData) {
                $amount = $itemData['quantity'] * $itemData['unit_price'];
                $subtotal += $amount;
                
                if (!empty($itemData['id'])) {
                    // Update existing item
                    InvoiceItem::where('id', $itemData['id'])
                        ->where('invoice_id', $invoice->id)
                        ->update([
                            'description' => $itemData['description'],
                            'quantity' => $itemData['quantity'],
                            'unit' => $itemData['unit'],
                            'unit_price' => $itemData['unit_price'],
                            'amount' => $amount,
                        ]);
                    $processedIds[] = $itemData['id'];
                } else {
                    // Create new item
                    $item = InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => $itemData['description'],
                        'quantity' => $itemData['quantity'],
                        'unit' => $itemData['unit'],
                        'unit_price' => $itemData['unit_price'],
                        'amount' => $amount,
                    ]);
                    $processedIds[] = $item->id;
                }
            }
            
            // Delete removed items
            InvoiceItem::where('invoice_id', $invoice->id)
                ->whereNotIn('id', $processedIds)
                ->delete();
            
            // Recalculate totals
            $taxAmount = round($subtotal * $invoice->tax_rate, 2);
            $total = $subtotal + $taxAmount;
            
            $invoice->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ]);
        });
        
        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('success', 'Invoice updated successfully');
    }
    
    /**
     * Regenerate PDF for invoice.
     */
    public function regeneratePdf(Invoice $invoice)
    {
        Gate::authorize('admin');
        
        try {
            $this->pdfGenerator->regenerate($invoice);
            
            return redirect()
                ->back()
                ->with('success', 'PDF regenerated successfully');
        } catch (\Exception $e) {
            Log::error('Failed to regenerate PDF', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()
                ->back()
                ->with('error', 'Failed to regenerate PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Resend invoice email.
     */
    public function resendEmail(Invoice $invoice)
    {
        Gate::authorize('admin');
        
        try {
            if ($this->mailer->send($invoice)) {
                return redirect()
                    ->back()
                    ->with('success', 'Invoice email sent successfully');
            } else {
                return redirect()
                    ->back()
                    ->with('error', 'Failed to send invoice email');
            }
        } catch (\Exception $e) {
            Log::error('Failed to send invoice email', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            
            return redirect()
                ->back()
                ->with('error', 'Failed to send email: ' . $e->getMessage());
        }
    }
    
    /**
     * Mark invoice as paid.
     */
    public function markPaid(Invoice $invoice)
    {
        Gate::authorize('admin');
        
        $invoice->update([
            'status' => 'paid',
            'meta' => array_merge($invoice->meta ?? [], [
                'paid_at' => Carbon::now()->toIso8601String(),
                'paid_by' => auth()->user()->name,
            ]),
        ]);
        
        return redirect()
            ->back()
            ->with('success', 'Invoice marked as paid');
    }
    
    /**
     * Mark invoice as unpaid.
     */
    public function markUnpaid(Invoice $invoice)
    {
        Gate::authorize('admin');
        
        $invoice->update([
            'status' => 'sent',
            'meta' => array_merge($invoice->meta ?? [], [
                'unpaid_at' => Carbon::now()->toIso8601String(),
                'unpaid_by' => auth()->user()->name,
            ]),
        ]);
        
        return redirect()
            ->back()
            ->with('success', 'Invoice marked as unpaid');
    }
    
    /**
     * Delete invoice (only drafts).
     */
    public function destroy(Invoice $invoice)
    {
        Gate::authorize('admin');
        
        if ($invoice->status !== 'draft') {
            return redirect()
                ->back()
                ->with('error', 'Only draft invoices can be deleted');
        }
        
        // Delete PDF if exists
        if ($invoice->pdf_path) {
            try {
                $this->pdfGenerator->deletePdf($invoice);
            } catch (\Exception $e) {
                Log::warning('Failed to delete PDF', [
                    'invoice_id' => $invoice->id,
                    'path' => $invoice->pdf_path,
                ]);
            }
        }
        
        // Delete invoice and items (cascade)
        $invoice->delete();
        
        return redirect()
            ->route('admin.invoices.index')
            ->with('success', 'Invoice deleted successfully');
    }
    
    /**
     * Download invoice PDF.
     */
    public function downloadPdf(Invoice $invoice)
    {
        Gate::authorize('admin');
        
        if (!$invoice->pdf_path) {
            // Generate if not exists
            try {
                $this->pdfGenerator->generate($invoice);
                $invoice->refresh();
            } catch (\Exception $e) {
                return redirect()
                    ->back()
                    ->with('error', 'Failed to generate PDF');
            }
        }
        
        // Get PDF content
        $content = $this->pdfGenerator->getPdfContent($invoice);
        
        if (!$content) {
            return redirect()
                ->back()
                ->with('error', 'PDF file not found');
        }
        
        $filename = "invoice_{$invoice->invoice_number}.pdf";
        
        return response($content, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
    
    /**
     * Calculate statistics for invoices.
     */
    private function calculateStatistics(Request $request): array
    {
        $query = Invoice::query();
        
        // Apply same filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        // Calculate stats
        $total = $query->sum('total');
        $count = $query->count();
        $avgAmount = $count > 0 ? $total / $count : 0;
        
        // By status
        $byStatus = Invoice::select('status')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(total) as total')
            ->when($request->filled('project_id'), function ($q) use ($request) {
                $q->where('project_id', $request->project_id);
            })
            ->when($request->filled('date_from'), function ($q) use ($request) {
                $q->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($q) use ($request) {
                $q->whereDate('created_at', '<=', $request->date_to);
            })
            ->groupBy('status')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->status => [
                    'count' => $item->count,
                    'total' => $item->total,
                ]];
            })->toArray();
        
        // Overdue amount
        $overdueAmount = Invoice::where('status', 'sent')
            ->where('due_date', '<', Carbon::today())
            ->sum('total');
        
        return [
            'total_amount' => $total,
            'count' => $count,
            'avg_amount' => round($avgAmount, 2),
            'by_status' => $byStatus,
            'overdue_amount' => $overdueAmount,
        ];
    }
}