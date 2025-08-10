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
        Schema::table('worklogs', function (Blueprint $table) {
            // Make ended_at nullable for async tracking
            $table->datetime('ended_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('worklogs', function (Blueprint $table) {
            // Revert to non-nullable
            $table->datetime('ended_at')->nullable(false)->change();
        });
    }
};