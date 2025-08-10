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
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->onDelete('cascade');
            $table->enum('state', [
                'INGESTED',
                'CONTEXT_READY',
                'PLANNED',
                'IMPLEMENTING',
                'TESTING',
                'REVIEWING',
                'FIXING',
                'PR_CREATED',
                'DONE',
                'FAILED',
            ]);
            $table->integer('retries')->default(0);
            $table->timestamps();

            $table->index(['ticket_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
