<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_persephone_agent_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('sender_agent_id', 191);
            $table->string('target_agent_id', 191);
            $table->foreignUlid('target_workspace_binding_id')
                ->nullable()
                ->constrained('hades_workspace_bindings')
                ->nullOnDelete();
            $table->string('schema', 191);
            $table->string('message_id', 191);
            $table->string('correlation_id', 191);
            $table->string('causation_id', 191)->nullable();
            $table->string('remote_task_id', 191)->nullable();
            $table->string('remote_task_version', 191)->nullable();
            $table->string('message_type', 64);
            $table->string('effect', 64);
            $table->string('capability', 191);
            $table->unsignedBigInteger('expires_at');
            $table->jsonb('payload');
            $table->jsonb('envelope');
            $table->char('envelope_hash', 64);
            $table->timestamps();

            $table->unique(
                ['project_id', 'message_id'],
                'hades_persephone_agent_messages_project_message_unique',
            );
            $table->index(
                ['project_id', 'target_agent_id', 'id'],
                'hades_persephone_agent_messages_target_cursor_index',
            );
            $table->index(
                ['project_id', 'target_agent_id', 'target_workspace_binding_id', 'id'],
                'hades_persephone_agent_messages_workspace_cursor_index',
            );
            $table->index(
                ['project_id', 'expires_at', 'id'],
                'hades_persephone_agent_messages_expiry_cursor_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_persephone_agent_messages');
    }
};
