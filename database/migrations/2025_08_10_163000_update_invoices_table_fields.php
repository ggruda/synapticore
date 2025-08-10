<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Rename columns if they exist
            if (Schema::hasColumn('invoices', 'number')) {
                $table->renameColumn('number', 'invoice_number');
            } else {
                $table->string('invoice_number')->unique()->after('project_id');
            }
            
            if (Schema::hasColumn('invoices', 'net_total')) {
                $table->renameColumn('net_total', 'subtotal');
            } else {
                $table->decimal('subtotal', 10, 2)->default(0)->after('period_end');
            }
            
            if (Schema::hasColumn('invoices', 'gross_total')) {
                $table->renameColumn('gross_total', 'total');
            } else {
                $table->decimal('total', 10, 2)->default(0)->after('tax_amount');
            }
            
            // Add missing columns
            if (!Schema::hasColumn('invoices', 'due_date')) {
                $table->date('due_date')->nullable()->after('period_end');
            }
            
            if (!Schema::hasColumn('invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->nullable()->after('tax_rate');
            }
            
            if (!Schema::hasColumn('invoices', 'notes')) {
                $table->text('notes')->nullable()->after('total');
            }
            
            // Ensure currency has default
            if (Schema::hasColumn('invoices', 'currency')) {
                $table->string('currency', 3)->default('CHF')->change();
            } else {
                $table->string('currency', 3)->default('CHF')->after('due_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Revert column names
            if (Schema::hasColumn('invoices', 'invoice_number')) {
                $table->renameColumn('invoice_number', 'number');
            }
            
            if (Schema::hasColumn('invoices', 'subtotal')) {
                $table->renameColumn('subtotal', 'net_total');
            }
            
            if (Schema::hasColumn('invoices', 'total')) {
                $table->renameColumn('total', 'gross_total');
            }
            
            // Drop added columns
            $table->dropColumn(['due_date', 'tax_amount', 'notes']);
        });
    }
};