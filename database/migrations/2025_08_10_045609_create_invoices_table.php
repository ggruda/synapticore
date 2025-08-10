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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3)->default('CHF');
            $table->decimal('net_total', 10, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('gross_total', 10, 2);
            $table->string('number')->unique();
            $table->enum('status', ['draft', 'sent', 'paid']);
            $table->string('pdf_path')->nullable();
            $table->json('meta');
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('number');
            $table->index(['period_start', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
