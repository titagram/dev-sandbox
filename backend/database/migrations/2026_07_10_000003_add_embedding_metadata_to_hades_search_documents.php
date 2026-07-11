<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hades_search_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('hades_search_documents', 'embedding_status')) {
                $table->string('embedding_status')->nullable()->after('checksum');
            }
            if (! Schema::hasColumn('hades_search_documents', 'embedding_model')) {
                $table->string('embedding_model')->nullable()->after('embedding_status');
            }
            if (! Schema::hasColumn('hades_search_documents', 'embedding_dimensions')) {
                $table->unsignedInteger('embedding_dimensions')->nullable()->after('embedding_model');
            }
            if (! Schema::hasColumn('hades_search_documents', 'embedding_checksum')) {
                $table->string('embedding_checksum', 64)->nullable()->after('embedding_dimensions');
            }
            if (! Schema::hasColumn('hades_search_documents', 'embedding_updated_at')) {
                $table->timestamp('embedding_updated_at')->nullable()->after('embedding_checksum');
            }
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('ALTER TABLE hades_search_documents ADD COLUMN IF NOT EXISTS embedding vector(1536)');
        DB::statement('CREATE INDEX IF NOT EXISTS hades_search_documents_embedding_hnsw_idx ON hades_search_documents USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS hades_search_documents_embedding_hnsw_idx');
        }

        Schema::table('hades_search_documents', function (Blueprint $table): void {
            foreach (['embedding_updated_at', 'embedding_checksum', 'embedding_dimensions', 'embedding_model', 'embedding_status'] as $column) {
                if (Schema::hasColumn('hades_search_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
