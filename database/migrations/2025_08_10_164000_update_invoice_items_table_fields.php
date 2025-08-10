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
        Schema::table('invoice_items', function (Blueprint $table) {
            // Rename columns if they exist
            if (Schema::hasColumn('invoice_items', 'seconds')) {
                $table->renameColumn('seconds', 'quantity');
            } else {
                $table->decimal('quantity', 10, 4)->after('description');
            }
            
            if (Schema::hasColumn('invoice_items', 'net_amount')) {
                $table->renameColumn('net_amount', 'amount');
            } else {
                $table->decimal('amount', 10, 2)->after('unit_price');
            }
            
            // Add missing columns
            if (!Schema::hasColumn('invoice_items', 'unit')) {
                $table->string('unit', 20)->default('Stunden')->after('quantity');
            }
            
            if (!Schema::hasColumn('invoice_items', 'meta')) {
                $table->jsonb('meta')->nullable()->after('amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // Revert column names
            if (Schema::hasColumn('invoice_items', 'quantity')) {
                $table->renameColumn('quantity', 'seconds');
            }
            
            if (Schema::hasColumn('invoice_items', 'amount')) {
                $table->renameColumn('amount', 'net_amount');
            }
            
            // Drop added columns
            $table->dropColumn(['unit', 'meta']);
        });
    }
};