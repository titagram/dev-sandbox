<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_agent_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->string('capability');
            $table->string('status')->default('queued');
            $table->string('policy')->default('auto');
            $table->string('priority')->default('normal');
            $table->json('payload');
            $table->json('result')->nullable();
            $table->boolean('requires_confirmation')->default(false);
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['workspace_binding_id', 'status']);
            $table->index(['hades_agent_id', 'status']);
            $table->index(['capability', 'status']);
            $table->index('idempotency_key');
        });

        Schema::create('hades_agent_job_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('job_id')->constrained('hades_agent_jobs')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('status')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['job_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_agent_job_events');
        Schema::dropIfExists('hades_agent_jobs');
    }
};
