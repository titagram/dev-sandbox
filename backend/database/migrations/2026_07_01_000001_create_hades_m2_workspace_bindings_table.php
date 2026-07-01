<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_workspace_bindings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->constrained('hades_agents')->cascadeOnDelete();
            $table->string('external_agent_id');
            $table->string('local_project_id')->nullable();
            $table->string('workspace_fingerprint');
            $table->string('display_path', 512);
            $table->string('git_remote_display', 512)->nullable();
            $table->string('git_remote_hash')->nullable();
            $table->string('head_commit', 80)->nullable();
            $table->string('platform')->nullable();
            $table->string('status')->default('linked');
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['project_id', 'hades_agent_id', 'workspace_fingerprint'],
                'hades_workspace_agent_fingerprint_unique',
            );
            $table->index(['project_id', 'status']);
            $table->index(['hades_agent_id', 'status']);
            $table->index(['workspace_fingerprint', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_workspace_bindings');
    }
};
