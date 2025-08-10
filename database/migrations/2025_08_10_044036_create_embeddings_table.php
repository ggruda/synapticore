<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable pgvector extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Add vector column for embeddings (384 dimensions for all-MiniLM-L6-v2 model)
            // You can adjust the dimension based on your embedding model
            $table->index(['model_type', 'model_id']);
        });

        // Add vector column using raw SQL
        DB::statement('ALTER TABLE embeddings ADD COLUMN embedding vector(384)');

        // Create an index for similarity search using IVFFlat
        DB::statement('CREATE INDEX embeddings_embedding_idx ON embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};
