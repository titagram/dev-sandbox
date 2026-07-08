<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_diagnosis_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bug_report_id')->nullable()->constrained('hades_bug_reports')->nullOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->string('confidence')->default('insufficient');
            $table->text('root_cause');
            $table->text('mechanism')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('freshness')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('redactions')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'confidence']);
            $table->index(['workspace_binding_id', 'status']);
            $table->index(['bug_report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_diagnosis_reports');
    }
};
