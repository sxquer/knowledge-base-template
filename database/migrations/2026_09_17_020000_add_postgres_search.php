<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement("ALTER TABLE chunks ADD COLUMN search_document tsvector GENERATED ALWAYS AS (to_tsvector('russian', coalesce(text, ''))) STORED");
        DB::statement('CREATE INDEX chunks_text_gin ON chunks USING gin(search_document)');
        DB::statement('ALTER TABLE chunks ADD COLUMN embedding_vector vector');
        DB::statement('ALTER TABLE chunks ADD COLUMN embedding_dimensions integer');
        DB::statement('CREATE INDEX chunks_vector_hnsw_1536 ON chunks USING hnsw ((embedding_vector::vector(1536)) vector_cosine_ops) WHERE embedding_dimensions = 1536');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE chunks DROP COLUMN search_document, DROP COLUMN embedding_vector, DROP COLUMN embedding_dimensions');
    }
};
