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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('external_key')->unique();
            $table->enum('source', ['jira', 'linear', 'azure']);
            $table->string('title');
            $table->text('body');
            $table->json('acceptance_criteria');
            $table->json('labels');
            $table->string('status');
            $table->string('priority');
            $table->json('meta');
            $table->timestamps();

            $table->index(['project_id', 'source']);
            $table->index('external_key');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
