<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_causal_packs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bug_report_id')->nullable()->constrained('hades_bug_reports')->nullOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->string('pack_key', 64);
            $table->string('bug_id', 191)->nullable();
            $table->string('root_cause_id', 191);
            $table->string('bug_class', 128)->nullable();
            $table->string('failure_classification', 128)->nullable();
            $table->json('affected_refs')->nullable();
            $table->json('freshness')->nullable();
            $table->json('awareness')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('graph_refs')->nullable();
            $table->json('source_slice_refs')->nullable();
            $table->json('replay')->nullable();
            $table->string('status', 64)->default('invalid');
            $table->json('blockers')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'pack_key']);
            $table->index(['project_id', 'workspace_binding_id', 'status']);
            $table->index(['bug_report_id', 'created_at']);
            $table->index(['root_cause_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_causal_packs');
    }
};
