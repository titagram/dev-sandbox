<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_logbook_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->string('actor_kind', 32);
            $table->string('actor_label', 191);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_agent_id', 191)->nullable();
            $table->string('actor_device_id', 191)->nullable();
            $table->string('actor_role', 64)->nullable();
            $table->string('actor_model', 191)->nullable();
            $table->string('event_type', 32);
            $table->string('severity', 16);
            $table->string('summary', 240);
            $table->longText('narrative_markdown')->nullable();
            $table->jsonb('references');
            $table->string('correlation_id', 191)->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_sha256', 64);
            $table->jsonb('payload');
            $table->foreignUlid('supersedes_entry_id')->nullable();

            $table->unique(
                ['project_id', 'idempotency_key'],
                'project_logbook_entries_project_idempotency_unique',
            );
            $table->index(
                ['project_id', 'recorded_at', 'id'],
                'project_logbook_entries_project_timeline_index',
            );
            $table->index(
                ['project_id', 'event_type', 'recorded_at'],
                'project_logbook_entries_project_type_index',
            );
            $table->index(
                ['project_id', 'actor_kind', 'recorded_at'],
                'project_logbook_entries_project_actor_index',
            );
        });

        Schema::table('project_logbook_entries', function (Blueprint $table): void {
            $table->foreign('supersedes_entry_id', 'project_logbook_entries_supersedes_foreign')
                ->references('id')
                ->on('project_logbook_entries')
                ->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE project_logbook_entries
                ADD CONSTRAINT project_logbook_entries_actor_kind_check
                    CHECK (actor_kind IN ('user', 'agent', 'subagent', 'system')),
                ADD CONSTRAINT project_logbook_entries_event_type_check
                    CHECK (event_type IN ('change', 'creation', 'import', 'projection', 'verification', 'wiki', 'decision', 'failure', 'rollback', 'note')),
                ADD CONSTRAINT project_logbook_entries_severity_check
                    CHECK (severity IN ('info', 'warning', 'error')),
                ADD CONSTRAINT project_logbook_entries_summary_check
                    CHECK (char_length(summary) BETWEEN 1 AND 240),
                ADD CONSTRAINT project_logbook_entries_request_sha256_check
                    CHECK (request_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT project_logbook_entries_idempotency_key_check
                    CHECK (char_length(idempotency_key) BETWEEN 16 AND 128 AND idempotency_key ~ '^[!-~]+$')
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION project_logbook_entries_reject_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'project_logbook_entries is append-only'
                    USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER project_logbook_entries_immutable_trigger
            BEFORE UPDATE OR DELETE ON project_logbook_entries
            FOR EACH ROW
            EXECUTE FUNCTION project_logbook_entries_reject_mutation();
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS project_logbook_entries_immutable_trigger ON project_logbook_entries');
            DB::statement('DROP FUNCTION IF EXISTS project_logbook_entries_reject_mutation()');
        }

        Schema::dropIfExists('project_logbook_entries');
    }
};
