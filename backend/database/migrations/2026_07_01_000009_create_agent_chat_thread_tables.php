<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_chat_threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('agent_key');
            $table->string('title');
            $table->string('status')->default('active')->index();
            $table->foreignUlid('latest_agent_work_item_id')->nullable()->constrained('agent_work_items')->nullOnDelete();
            $table->foreignUlid('latest_assistant_run_id')->nullable()->constrained('assistant_runs')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata');
            $table->timestamps();

            $table->index(['project_id', 'agent_key', 'last_message_at']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('agent_chat_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_chat_thread_id')->constrained('agent_chat_threads')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('assistant_run_id')->nullable()->constrained('assistant_runs')->nullOnDelete();
            $table->foreignUlid('agent_work_item_id')->nullable()->constrained('agent_work_items')->nullOnDelete();
            $table->string('role');
            $table->longText('content');
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agent_chat_thread_id', 'created_at']);
            $table->index('assistant_run_id');
            $table->index('agent_work_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_chat_messages');
        Schema::dropIfExists('agent_chat_threads');
    }
};
