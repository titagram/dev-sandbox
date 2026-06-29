<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'acceptance_criteria')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->json('acceptance_criteria')->nullable()->after('description');
            });
        }

        Schema::create('repository_task', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUlid('repository_id')->constrained('repositories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'repository_id']);
            $table->index('repository_id');
        });

        Schema::create('project_memory_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('agent_key')->nullable();
            $table->string('source');
            $table->string('kind');
            $table->string('completeness')->default('complete');
            $table->string('summary');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['project_id', 'occurred_at']);
            $table->index(['project_id', 'kind']);
            $table->index(['project_id', 'agent_key']);
            $table->index('repository_id');
            $table->index('task_id');
            $table->index('run_id');
        });

        Schema::create('project_memory_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('memory_entry_id')->constrained('project_memory_entries')->cascadeOnDelete();
            $table->string('target_type');
            $table->string('target_id');
            $table->timestamps();

            $table->index(['memory_entry_id', 'target_type']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('agent_work_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('repository_id')->nullable()->constrained('repositories')->nullOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_agent_key');
            $table->string('status')->default('draft');
            $table->string('priority')->default('normal');
            $table->string('title');
            $table->text('prompt');
            $table->json('payload');
            $table->boolean('requires_memory_entry')->default(true);
            $table->foreignUlid('result_memory_entry_id')->nullable()->constrained('project_memory_entries')->nullOnDelete();
            $table->foreignUlid('claimed_by_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['assigned_agent_key', 'status']);
            $table->index(['repository_id', 'status']);
            $table->index(['task_id', 'status']);
            $table->index('result_memory_entry_id');
            $table->index('claimed_by_device_id');
        });

        Schema::create('agent_work_item_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_work_item_id')->constrained('agent_work_items')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('actor_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('event_type');
            $table->text('message')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['agent_work_item_id', 'created_at']);
            $table->index('actor_user_id');
            $table->index('actor_device_id');
        });

        Schema::create('agent_work_item_leases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('agent_work_item_id')->constrained('agent_work_items')->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('lease_token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'released_at']);
            $table->index(['agent_work_item_id', 'released_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_work_item_leases');
        Schema::dropIfExists('agent_work_item_events');
        Schema::dropIfExists('agent_work_items');
        Schema::dropIfExists('project_memory_links');
        Schema::dropIfExists('project_memory_entries');
        Schema::dropIfExists('repository_task');

        if (Schema::hasColumn('tasks', 'acceptance_criteria')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropColumn('acceptance_criteria');
            });
        }
    }
};
