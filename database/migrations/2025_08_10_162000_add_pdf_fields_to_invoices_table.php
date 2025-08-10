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
            // PDF storage
            $table->string('pdf_path')->nullable()->after('notes')
                ->comment('Path to PDF file in storage');
            $table->timestamp('pdf_generated_at')->nullable()->after('pdf_path')
                ->comment('When PDF was generated');
            
            // Email tracking
            $table->timestamp('sent_at')->nullable()->after('pdf_generated_at')
                ->comment('When invoice was sent via email');
            
            // Add index for status queries
            $table->index(['status', 'sent_at']);
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'sent_at']);
            $table->dropIndex(['due_date']);
            
            $table->dropColumn([
                'pdf_path',
                'pdf_generated_at',
                'sent_at',
            ]);
        });
    }
};