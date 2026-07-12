<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_graph_projections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_scope_type', 32);
            $table->string('source_scope_id', 191);
            $table->string('artifact_type', 32);
            $table->string('artifact_id', 191);
            $table->string('graph_version', 64)->unique();
            $table->string('checksum', 64);
            $table->string('head_commit', 80)->nullable();
            $table->string('quality', 32);
            $table->string('status', 32);
            $table->unsignedBigInteger('node_count')->nullable();
            $table->unsignedBigInteger('relationship_count')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('projected_at')->nullable();
            $table->timestamps();
            $table->unique(['artifact_type', 'artifact_id']);
            $table->index(['project_id', 'source_scope_type', 'source_scope_id', 'status'], 'canonical_graph_scope_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_graph_projections');
    }
};
