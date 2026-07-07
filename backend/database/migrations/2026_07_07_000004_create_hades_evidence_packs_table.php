<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_evidence_packs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bug_report_id')->nullable()->constrained('hades_bug_reports')->nullOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->string('title', 512);
            $table->text('summary');
            $table->json('evidence_refs')->nullable();
            $table->json('graph_refs')->nullable();
            $table->json('source_slice_ids')->nullable();
            $table->json('payload')->nullable();
            $table->string('sha256', 64);
            $table->unsignedInteger('redactions')->default(0);
            $table->string('retention_class')->default('diagnosis_evidence');
            $table->string('head_commit', 80)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['workspace_binding_id', 'created_at']);
            $table->index(['bug_report_id', 'created_at']);
            $table->index(['workspace_binding_id', 'head_commit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_evidence_packs');
    }
};
