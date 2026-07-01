<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_agent_artifacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->foreignUlid('job_id')->nullable()->constrained('hades_agent_jobs')->nullOnDelete();
            $table->string('schema');
            $table->json('artifact');
            $table->string('sha256', 64)->nullable();
            $table->boolean('truncated')->default(false);
            $table->unsignedInteger('redactions')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'schema']);
            $table->index(['workspace_binding_id', 'schema']);
            $table->index(['hades_agent_id', 'created_at']);
        });

        Schema::create('hades_doctor_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->nullable()->constrained('hades_workspace_bindings')->nullOnDelete();
            $table->string('status');
            $table->json('payload');
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['hades_agent_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('hades_persephone_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->nullable()->constrained('hades_workspace_bindings')->nullOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'read_at']);
            $table->index(['hades_agent_id', 'read_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_persephone_events');
        Schema::dropIfExists('hades_doctor_reports');
        Schema::dropIfExists('hades_agent_artifacts');
    }
};
