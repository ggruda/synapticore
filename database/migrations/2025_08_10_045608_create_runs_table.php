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
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['lint', 'typecheck', 'test', 'build', 'review']);
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'skipped']);
            $table->string('junit_path')->nullable();
            $table->string('coverage_path')->nullable();
            $table->string('logs_path')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'type']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
