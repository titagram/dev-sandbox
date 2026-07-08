<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_memory_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->constrained('hades_agents')->cascadeOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->string('local_proposal_id')->nullable();
            $table->string('action');
            $table->string('intent');
            $table->text('summary');
            $table->json('provenance');
            $table->string('base_version')->nullable();
            $table->foreignUlid('target_memory_entry_id')->nullable()->constrained('project_memory_entries')->nullOnDelete();
            $table->foreignUlid('memory_entry_id')->nullable()->constrained('project_memory_entries')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('reason_code')->nullable();
            $table->text('reason_message')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['workspace_binding_id', 'local_proposal_id'],
                'hades_memory_workspace_local_proposal_unique',
            );
            $table->index(['project_id', 'status']);
            $table->index(['hades_agent_id', 'status']);
            $table->index(['workspace_binding_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_memory_proposals');
    }
};
