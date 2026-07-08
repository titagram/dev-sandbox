<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_search_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_binding_id')->nullable()->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->string('domain');
            $table->string('kind');
            $table->string('source_table');
            $table->string('source_id');
            $table->string('source_schema')->nullable();
            $table->string('title')->default('');
            $table->longText('body');
            $table->json('metadata')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamps();

            $table->unique(['source_table', 'source_id']);
            $table->index(['project_id', 'domain', 'kind']);
            $table->index(['workspace_binding_id', 'domain']);
            $table->index('source_schema');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_search_documents');
    }
};
