<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hades_agent_jobs')) {
            Schema::table('hades_agent_jobs', function (Blueprint $table): void {
                if (! Schema::hasColumn('hades_agent_jobs', 'repository_id')) {
                    $table->foreignUlid('repository_id')->nullable()->after('project_id')->constrained('repositories')->nullOnDelete();
                }

                if (! Schema::hasColumn('hades_agent_jobs', 'requested_by_user_id')) {
                    $table->foreignId('requested_by_user_id')->nullable()->after('workspace_binding_id')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('hades_agent_jobs', 'job_type')) {
                    $table->string('job_type')->nullable()->after('capability')->index();
                }

                if (! Schema::hasColumn('hades_agent_jobs', 'result_applied_at')) {
                    $table->timestamp('result_applied_at')->nullable()->after('cancelled_at');
                }
            });
        }

        if (! Schema::hasTable('memory_import_batches')) {
            Schema::create('memory_import_batches', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('source_workspace_binding_id')->nullable()->constrained('hades_workspace_bindings')->nullOnDelete();
                $table->foreignUlid('target_workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
                $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('requested_by_hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
                $table->string('status')->default('pending');
                $table->string('mode')->default('copy_as_proposals');
                $table->string('dedupe_strategy')->default('source_hash');
                $table->string('conflict_policy')->default('skip');
                $table->text('reason')->nullable();
                $table->json('source_payload')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'status']);
                $table->index(['target_workspace_binding_id', 'status']);
                $table->index('requested_by_hades_agent_id');
            });
        }

        if (! Schema::hasTable('memory_import_items')) {
            Schema::create('memory_import_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('batch_id')->constrained('memory_import_batches')->cascadeOnDelete();
                $table->string('source_local_id')->nullable();
                $table->string('source_hash');
                $table->foreignUlid('proposal_id')->nullable()->constrained('hades_memory_proposals')->nullOnDelete();
                $table->foreignUlid('target_memory_entry_id')->nullable()->constrained('project_memory_entries')->nullOnDelete();
                $table->string('status');
                $table->text('conflict_reason')->nullable();
                $table->json('provenance');
                $table->timestamps();

                $table->unique(['batch_id', 'source_hash']);
                $table->index(['source_hash', 'status']);
                $table->index('proposal_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_import_items');
        Schema::dropIfExists('memory_import_batches');

        if (Schema::hasTable('hades_agent_jobs')) {
            Schema::table('hades_agent_jobs', function (Blueprint $table): void {
                foreach (['repository_id', 'requested_by_user_id', 'job_type', 'result_applied_at'] as $column) {
                    if (Schema::hasColumn('hades_agent_jobs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
