<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_bug_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->string('title');
            $table->text('symptom');
            $table->string('severity')->default('unknown');
            $table->string('status')->default('open');
            $table->json('environment')->nullable();
            $table->json('affected_refs')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'severity']);
            $table->index(['workspace_binding_id', 'status']);
            $table->index(['hades_agent_id', 'created_at']);
        });

        Schema::create('hades_bug_evidence_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bug_report_id')->nullable()->constrained('hades_bug_reports')->nullOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->string('kind');
            $table->text('summary');
            $table->json('payload');
            $table->string('source')->nullable();
            $table->string('sha256', 64);
            $table->unsignedInteger('redactions')->default(0);
            $table->string('retention_class')->default('runtime_evidence');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'kind']);
            $table->index(['project_id', 'occurred_at']);
            $table->index(['bug_report_id', 'kind']);
            $table->index(['workspace_binding_id', 'kind']);
            $table->index(['hades_agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_bug_evidence_items');
        Schema::dropIfExists('hades_bug_reports');
    }
};
