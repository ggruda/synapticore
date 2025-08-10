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
        Schema::create('secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->enum('kind', ['jira', 'github', 'gitlab', 'bitbucket', 'linear', 'azure']);
            $table->string('key_id');
            $table->json('meta');
            $table->text('payload'); // Will be encrypted via cast
            $table->timestamps();

            $table->index(['project_id', 'kind']);
            $table->unique(['project_id', 'kind', 'key_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
