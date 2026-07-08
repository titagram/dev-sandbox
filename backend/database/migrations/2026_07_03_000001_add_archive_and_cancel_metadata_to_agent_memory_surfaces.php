<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_work_items')) {
            Schema::table('agent_work_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('agent_work_items', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->after('failure_reason');
                }

                if (! Schema::hasColumn('agent_work_items', 'archived_by_user_id')) {
                    $table->foreignId('archived_by_user_id')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('agent_work_items', 'archive_reason')) {
                    $table->text('archive_reason')->nullable()->after('archived_by_user_id');
                }

                if (! Schema::hasIndex('agent_work_items', 'agent_work_project_archived_idx')) {
                    $table->index(['project_id', 'archived_at'], 'agent_work_project_archived_idx');
                }
            });
        }

        if (Schema::hasTable('agent_chat_threads')) {
            Schema::table('agent_chat_threads', function (Blueprint $table): void {
                if (! Schema::hasColumn('agent_chat_threads', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->after('last_message_at');
                }

                if (! Schema::hasColumn('agent_chat_threads', 'archived_by_user_id')) {
                    $table->foreignId('archived_by_user_id')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('agent_chat_threads', 'archive_reason')) {
                    $table->text('archive_reason')->nullable()->after('archived_by_user_id');
                }

                if (! Schema::hasIndex('agent_chat_threads', 'agent_chat_project_archived_idx')) {
                    $table->index(['project_id', 'archived_at'], 'agent_chat_project_archived_idx');
                }
            });
        }

        if (Schema::hasTable('memory_import_batches')) {
            Schema::table('memory_import_batches', function (Blueprint $table): void {
                if (! Schema::hasColumn('memory_import_batches', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('completed_at');
                }

                if (! Schema::hasColumn('memory_import_batches', 'cancelled_by_user_id')) {
                    $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('memory_import_batches', 'cancel_reason')) {
                    $table->text('cancel_reason')->nullable()->after('cancelled_by_user_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('memory_import_batches')) {
            Schema::table('memory_import_batches', function (Blueprint $table): void {
                foreach (['cancel_reason', 'cancelled_at'] as $column) {
                    if (Schema::hasColumn('memory_import_batches', $column)) {
                        $table->dropColumn($column);
                    }
                }

                if (Schema::hasColumn('memory_import_batches', 'cancelled_by_user_id')) {
                    $table->dropConstrainedForeignId('cancelled_by_user_id');
                }
            });
        }

        if (Schema::hasTable('agent_chat_threads')) {
            Schema::table('agent_chat_threads', function (Blueprint $table): void {
                if (Schema::hasIndex('agent_chat_threads', 'agent_chat_project_archived_idx')) {
                    $table->dropIndex('agent_chat_project_archived_idx');
                }

                foreach (['archive_reason', 'archived_at'] as $column) {
                    if (Schema::hasColumn('agent_chat_threads', $column)) {
                        $table->dropColumn($column);
                    }
                }

                if (Schema::hasColumn('agent_chat_threads', 'archived_by_user_id')) {
                    $table->dropConstrainedForeignId('archived_by_user_id');
                }
            });
        }

        if (Schema::hasTable('agent_work_items')) {
            Schema::table('agent_work_items', function (Blueprint $table): void {
                if (Schema::hasIndex('agent_work_items', 'agent_work_project_archived_idx')) {
                    $table->dropIndex('agent_work_project_archived_idx');
                }

                foreach (['archive_reason', 'archived_at'] as $column) {
                    if (Schema::hasColumn('agent_work_items', $column)) {
                        $table->dropColumn($column);
                    }
                }

                if (Schema::hasColumn('agent_work_items', 'archived_by_user_id')) {
                    $table->dropConstrainedForeignId('archived_by_user_id');
                }
            });
        }
    }
};
