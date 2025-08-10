# Admin UI for Worklogs & Invoices

## Overview

This document describes the comprehensive admin interface for managing worklogs and invoices, providing full control over time tracking, billing, and invoice management with audit capabilities.

## Features Implemented (Prompt 19)

### 1. Worklog Management

#### Controller (`app/Http/Controllers/Admin/WorklogController.php`)

- **Index**: List all worklogs with comprehensive filtering
- **Show**: Detailed view of individual worklog
- **Export**: CSV export of filtered worklogs
- **Sync**: Manual sync to external ticket system (Jira)
- **Delete**: Remove worklogs with confirmation

#### Views (`resources/views/admin/worklogs/`)

**Index View** - Features:
- Statistics cards showing total hours, average duration, phase breakdown
- Advanced filters:
  - Project selection
  - Phase filter (plan, implement, test, review, pr)
  - Status filter (in_progress, completed, failed)
  - Date range (from/to)
  - Sync status (synced/unsynced)
- Sortable table with pagination
- CSV export button
- Action buttons for view, sync, delete

**Show View** - Details:
- Complete worklog information
- Project and ticket details
- Phase and status badges
- Start/end times with duration
- User assignment
- Sync status and history
- Notes display

#### CSV Export

Generates downloadable CSV with columns:
- ID, Date, Project, Ticket, Phase
- Duration (seconds and hours)
- User, Status, Synced, Notes

### 2. Invoice Management

#### Controller (`app/Http/Controllers/Admin/InvoiceController.php`)

Full CRUD operations plus:
- **Index**: List invoices with status filtering
- **Create/Store**: Manual invoice creation
- **Show**: Detailed invoice view with items
- **Edit/Update**: Modify draft invoices only
- **Delete**: Remove draft invoices only
- **Regenerate PDF**: Create new PDF version
- **Resend Email**: Send/resend invoice email
- **Mark Paid/Unpaid**: Update payment status
- **Download PDF**: Get invoice PDF file

#### Views (`resources/views/admin/invoices/`)

**Index View** - Features:
- Statistics cards:
  - Total amount across all invoices
  - Average invoice amount
  - Overdue amount (if any)
  - Status breakdown
- Filters:
  - Project selection
  - Status (draft, sent, paid)
  - Date range
  - Overdue filter
- Invoice table with:
  - Invoice number (linked to detail)
  - Project, period, amount
  - Due date with overdue indicator
  - Status badges
  - PDF and email status
  - Action buttons

**Show View** - Complete invoice details:
- Invoice header information
- Line items table with descriptions
- Subtotal, tax, and total calculations
- Worklog statistics (if applicable)
- Actions sidebar:
  - Download PDF
  - Regenerate PDF
  - Send/Resend Email
  - Mark as Paid/Unpaid
  - Delete (draft only)
- PDF & Email status tracking
- Metadata display

**Edit View** - For draft invoices:
- Due date modification
- Notes editing
- Dynamic line item management:
  - Add/remove items
  - Edit descriptions
  - Adjust quantities and prices
  - Real-time total calculation
- JavaScript-powered calculations
- Save/Cancel actions

### 3. Routes & Middleware

#### Web Routes (`routes/web.php`)

Protected by `auth` and `can:admin` middleware:

```php
// Worklogs
Route::get('worklogs', [WorklogController::class, 'index']);
Route::get('worklogs/export', [WorklogController::class, 'export']);
Route::get('worklogs/{worklog}', [WorklogController::class, 'show']);
Route::post('worklogs/{worklog}/sync', [WorklogController::class, 'sync']);
Route::delete('worklogs/{worklog}', [WorklogController::class, 'destroy']);

// Invoices
Route::resource('invoices', InvoiceController::class);
Route::post('invoices/{invoice}/regenerate-pdf', ...);
Route::post('invoices/{invoice}/resend-email', ...);
Route::post('invoices/{invoice}/mark-paid', ...);
Route::post('invoices/{invoice}/mark-unpaid', ...);
Route::get('invoices/{invoice}/download-pdf', ...);
```

#### Security

- **Authentication**: All routes require authenticated user
- **Authorization**: Admin role required via Gate
- **CSRF Protection**: Active on all POST/PUT/DELETE
- **Validation**: Form requests validate all inputs

### 4. Admin Layout

Updated `resources/views/layouts/admin.blade.php`:
- Bootstrap 5.3 CSS framework
- Bootstrap Icons
- Navigation menu with Worklogs and Invoices
- Flash message support (success/error)
- Responsive design

## Usage Examples

### Access Admin UI

```bash
# Test command to create sample data
docker compose exec app php artisan admin:test-billing --all

# Access URLs
http://localhost:8080/admin/worklogs
http://localhost:8080/admin/invoices
```

### Worklog Management

1. **View Worklogs**:
   - Navigate to `/admin/worklogs`
   - Apply filters (project, phase, date range)
   - Review statistics cards

2. **Export to CSV**:
   - Apply desired filters
   - Click "Export CSV" button
   - Download generated file

3. **Sync to Jira**:
   - Find unsynced worklog
   - Click sync button
   - Confirm in modal

### Invoice Management

1. **Create Invoice**:
   - Click "Create Invoice" button
   - Select project
   - Set period and due date
   - Add line items
   - Save as draft

2. **Edit Draft Invoice**:
   - Only draft invoices can be edited
   - Modify line items
   - Adjust quantities/prices
   - Real-time total calculation

3. **Process Invoice**:
   - Generate/Regenerate PDF
   - Send email to admin
   - Mark as paid when received

4. **Handle Corrections**:
   - Edit draft invoices before sending
   - Regenerate PDF after changes
   - Resend email if needed

## Testing

### Test Command

```bash
# Run all tests
docker compose exec app php artisan admin:test-billing --all

# Test specific features
docker compose exec app php artisan admin:test-billing --worklogs
docker compose exec app php artisan admin:test-billing --invoices
docker compose exec app php artisan admin:test-billing --csv
```

### Sample Output

```
🧪 Testing Admin Billing UI

Testing Worklog Management...
  → Created 41 worklogs
  → Total hours: 80.01
  → Testing filters:
    • plan: 13 entries, 27.30 hours
    • implement: 9 entries, 19.81 hours
    • test: 8 entries, 14.59 hours
  ✓ Worklog management ready

Testing Invoice Management...
  → Created invoice SC-202507-001
    • Project: API Gateway
    • Billable hours: 18.75
    • Total: CHF 3,029.06
  → Invoice Statistics:
    • Total: 11
    • Draft: 4
    • Sent: 3
    • Paid: 4
  ✓ Invoice management ready
```

## Acceptance Criteria ✅

1. **Worklog Filtering**: ✅
   - Filter by project, phase, date range
   - View statistics and breakdowns
   - Search and pagination

2. **CSV Export**: ✅
   - Export current filtered view
   - Proper CSV formatting
   - Download functionality

3. **Invoice Status Management**: ✅
   - Filter by status (draft|sent|paid)
   - Visual status indicators
   - Overdue highlighting

4. **Invoice Editing**: ✅
   - Edit items only when status = draft
   - Dynamic line item management
   - Real-time calculations

5. **PDF & Email Actions**: ✅
   - Regenerate PDF on demand
   - Resend email functionality
   - Download PDF directly

6. **Security**: ✅
   - Admin-only access via Gates
   - CSRF protection active
   - Form validation

## Permissions

### Admin Role Required

```php
// Ensure user has admin role
$user->assignRole('admin');

// Gate checks in controllers
Gate::authorize('admin');
```

### Protected Actions

- All worklog management
- Invoice creation/editing
- PDF generation
- Email sending
- Status changes

## Database Impact

### Queries Optimized

- Eager loading relationships
- Indexed columns for filtering
- Pagination for large datasets

### Performance Considerations

- CSV export limited to filtered results
- Pagination set to 50 worklogs / 25 invoices
- Statistics calculated with aggregation queries

## Security Features

1. **Authentication**: Laravel Auth required
2. **Authorization**: Admin role via Spatie Permissions
3. **CSRF**: Token validation on all forms
4. **XSS**: Blade escaping for all outputs
5. **SQL Injection**: Query builder and Eloquent ORM
6. **Validation**: Server-side validation for all inputs

## UI/UX Features

- **Responsive Design**: Works on desktop and tablet
- **Status Badges**: Visual indicators for status
- **Flash Messages**: Success/error notifications
- **Confirmation Dialogs**: For destructive actions
- **Real-time Calculations**: JavaScript for invoice editing
- **Sortable Tables**: Click headers to sort
- **Pagination**: Navigate large datasets
- **Export Function**: One-click CSV download

## Future Enhancements

1. **Bulk Operations**: Select multiple items for actions
2. **Advanced Reporting**: Charts and graphs
3. **Email Templates**: Customizable invoice emails
4. **Worklog Import**: CSV upload functionality
5. **Invoice Templates**: Multiple invoice formats
6. **Time Tracking**: Direct time entry in admin
7. **Client Portal**: Customer invoice access
8. **Payment Integration**: Online payment processing