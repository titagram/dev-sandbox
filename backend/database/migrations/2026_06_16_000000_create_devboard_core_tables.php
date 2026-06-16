<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->json('permissions');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('fingerprint_hash');
            $table->string('platform_os');
            $table->string('platform_arch');
            $table->string('plugin_version');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('token_prefix')->index();
            $table->string('token_hash');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('default_code_exposure_policy')->default('full_code_artifacts');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('default_branch')->default('main');
            $table->boolean('local_only')->default(true);
            $table->string('code_exposure_policy')->default('full_code_artifacts');
            $table->json('protected_paths');
            $table->json('excluded_paths');
            $table->json('stack_hints');
            $table->boolean('graph_enabled')->default(true);
            $table->timestamps();

            $table->index('project_id');
            $table->unique(['project_id', 'slug']);
        });

        Schema::create('local_workspaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->constrained()->cascadeOnDelete();
            $table->string('local_root_hash');
            $table->string('display_path');
            $table->string('current_branch');
            $table->string('last_head_sha')->nullable();
            $table->string('dirty_status')->default('unknown');
            $table->ulid('last_snapshot_id')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('repository_id');
        });

        Schema::create('kanban_boards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('kanban_columns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position');
            $table->string('status_key');
            $table->unsignedInteger('wip_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignUlid('status_column_id')->constrained('kanban_columns')->restrictOnDelete();
            $table->string('priority')->default('normal');
            $table->string('risk_level')->default('low');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
        });

        Schema::create('runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('local_workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('runtime_profile');
            $table->string('status');
            $table->string('branch');
            $table->string('base_branch');
            $table->string('base_sha');
            $table->string('head_sha')->nullable();
            $table->text('summary')->nullable();
            $table->string('risk_level')->default('low');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('repository_id');
            $table->index('task_id');
        });

        Schema::create('run_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('severity')->default('info');
            $table->text('message');
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('artifacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('artifact_type');
            $table->string('storage_path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type');
            $table->string('schema_version');
            $table->string('status')->default('uploading');
            $table->string('producer');
            $table->json('metadata');
            $table->timestamps();

            $table->index('run_id');
        });

        Schema::create('snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('local_workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->string('branch');
            $table->string('base_sha');
            $table->string('head_sha')->nullable();
            $table->string('dirty_status');
            $table->foreignUlid('file_inventory_artifact_id')->nullable()->constrained('artifacts')->nullOnDelete();
            $table->foreignUlid('graph_snapshot_artifact_id')->nullable()->constrained('artifacts')->nullOnDelete();
            $table->foreignUlid('created_by_run_id')->constrained('runs')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('repository_id');
        });

        Schema::create('genesis_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('local_workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('run_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('started');
            $table->foreignUlid('manifest_artifact_id')->nullable()->constrained('artifacts')->nullOnDelete();
            $table->foreignUlid('snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->string('base_branch');
            $table->string('base_sha');
            $table->string('head_sha');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('repository_id');
        });

        Schema::create('delta_syncs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('local_workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('run_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('started');
            $table->foreignUlid('base_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->foreignUlid('new_snapshot_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->string('branch');
            $table->string('base_sha');
            $table->string('head_sha')->nullable();
            $table->string('dirty_status');
            $table->unsignedInteger('changed_file_count')->default(0);
            $table->string('risk_level')->default('low');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->string('page_type');
            $table->ulid('current_revision_id')->nullable()->index();
            $table->string('source_status');
            $table->timestamps();

            $table->index('project_id');
            $table->index('repository_id');
            $table->unique(['project_id', 'repository_id', 'slug']);
        });

        Schema::create('wiki_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('wiki_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('author_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('producer');
            $table->string('source_type');
            $table->string('source_status');
            $table->longText('content_markdown');
            $table->json('evidence_refs');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('actor_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('actor_type')->index();
            $table->string('action')->index();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('wiki_revisions');
        Schema::dropIfExists('wiki_pages');
        Schema::dropIfExists('delta_syncs');
        Schema::dropIfExists('genesis_imports');
        Schema::dropIfExists('snapshots');
        Schema::dropIfExists('artifacts');
        Schema::dropIfExists('run_events');
        Schema::dropIfExists('runs');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('kanban_columns');
        Schema::dropIfExists('kanban_boards');
        Schema::dropIfExists('local_workspaces');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
