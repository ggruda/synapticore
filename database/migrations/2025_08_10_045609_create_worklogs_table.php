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
        Schema::create('worklogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->onDelete('cascade');
            $table->enum('phase', ['plan', 'implement', 'test', 'review', 'pr']);
            $table->integer('seconds');
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'phase']);
            $table->index(['started_at', 'ended_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worklogs');
    }
};
