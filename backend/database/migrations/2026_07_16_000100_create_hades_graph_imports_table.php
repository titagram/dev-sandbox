<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('hades_workspace_bindings', function (Blueprint $table): void {
            $table->unique(
                ['project_id', 'id'],
                'hades_workspace_bindings_project_id_id_unique',
            );
        });

        Schema::create('hades_graph_imports', function (Blueprint $table) use ($driver): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id');
            $table->foreignUlid('workspace_binding_id');
            $table->foreignUlid('hades_agent_id')->nullable();
            $table->unsignedInteger('attempt_generation');
            $table->unsignedBigInteger('scope_generation');
            $table->string('schema', 64);
            $table->char('artifact_graph_version', 64);
            $table->char('manifest_semantic_sha256', 64);
            $driver === 'pgsql'
                ? $table->jsonb('source_identity')
                : $table->json('source_identity');
            $driver === 'pgsql'
                ? $table->jsonb('manifest')
                : $table->json('manifest');
            $table->string('status', 32);
            $table->string('completeness_status', 32);
            $table->unsignedInteger('expected_chunks');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->unsignedBigInteger('expected_uncompressed_bytes');
            $table->unsignedBigInteger('received_uncompressed_bytes')->default(0);
            $table->unsignedBigInteger('expected_compressed_bytes');
            $table->unsignedBigInteger('received_compressed_bytes')->default(0);
            $table->string('failure_code')->nullable();
            $driver === 'pgsql'
                ? $table->jsonb('failure_details')->nullable()
                : $table->json('failure_details')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('validation_started_at')->nullable();
            $table->timestampTz('validation_heartbeat_at')->nullable();
            $table->unsignedInteger('validation_attempts')->default(0);
            $table->char('validation_run_token_hash', 64)->nullable();
            $table->timestampTz('validation_lease_expires_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index('project_id', 'hades_graph_imports_project_id_idx');
            $table->index(
                'workspace_binding_id',
                'hades_graph_imports_workspace_binding_id_idx',
            );
            $table->unique(
                ['project_id', 'workspace_binding_id', 'artifact_graph_version', 'attempt_generation'],
                'hades_graph_imports_identity_unique',
            );
            $table->unique(
                ['project_id', 'workspace_binding_id', 'scope_generation'],
                'hades_graph_imports_scope_generation_unique',
            );
            $table->foreign('project_id', 'hades_graph_imports_project_id_foreign')
                ->references('id')
                ->on('projects')
                ->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'workspace_binding_id'],
                'hades_graph_imports_project_workspace_binding_foreign',
            )
                ->references(['project_id', 'id'])
                ->on('hades_workspace_bindings')
                ->cascadeOnDelete();
            $table->foreign('hades_agent_id', 'hades_graph_imports_hades_agent_id_foreign')
                ->references('id')
                ->on('hades_agents')
                ->nullOnDelete();
        });

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_imports
                ADD CONSTRAINT hades_graph_imports_schema_check
                CHECK ("schema" = 'hades.code_graph.v2')
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_imports
                ADD CONSTRAINT hades_graph_imports_status_check
                CHECK (status IN ('staging', 'validating', 'validated', 'failed', 'stale'))
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_imports
                ADD CONSTRAINT hades_graph_imports_attempt_generation_check
                CHECK (attempt_generation >= 1)
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_imports
                ADD CONSTRAINT hades_graph_imports_scope_generation_check
                CHECK (scope_generation >= 1)
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_imports
                ADD CONSTRAINT hades_graph_imports_unsigned_check
                CHECK (
                    expected_chunks >= 0
                    AND received_chunks >= 0
                    AND expected_uncompressed_bytes >= 0
                    AND received_uncompressed_bytes >= 0
                    AND expected_compressed_bytes >= 0
                    AND received_compressed_bytes >= 0
                    AND validation_attempts >= 0
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_imports
                ADD CONSTRAINT hades_graph_imports_validated_expiry_check
                CHECK (status <> 'validated' OR expires_at IS NULL)
            SQL);
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX hades_graph_imports_live_unique
                ON hades_graph_imports (
                    project_id,
                    workspace_binding_id,
                    artifact_graph_version
                )
                WHERE status IN ('staging', 'validating')
            SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION hades_graph_imports_immutable_validated()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' AND OLD.status = 'validated' THEN
                        RAISE EXCEPTION 'validated graph imports are immutable';
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END;
                $$
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_imports_immutable_validated_trigger
                BEFORE UPDATE OR DELETE ON hades_graph_imports
                FOR EACH ROW
                EXECUTE FUNCTION hades_graph_imports_immutable_validated()
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_imports_schema_status_insert
                BEFORE INSERT ON hades_graph_imports
                BEGIN
                    SELECT CASE
                        WHEN NEW."schema" <> 'hades.code_graph.v2'
                            THEN RAISE(ABORT, 'invalid graph import schema')
                        WHEN NEW.status NOT IN ('staging', 'validating', 'validated', 'failed', 'stale')
                            THEN RAISE(ABORT, 'invalid graph import status')
                        WHEN NEW.attempt_generation < 1
                            THEN RAISE(ABORT, 'graph import attempt generation must be positive')
                        WHEN NEW.scope_generation < 1
                            THEN RAISE(ABORT, 'graph import scope generation must be positive')
                        WHEN NEW.expected_chunks < 0
                            OR NEW.received_chunks < 0
                            OR NEW.expected_uncompressed_bytes < 0
                            OR NEW.received_uncompressed_bytes < 0
                            OR NEW.expected_compressed_bytes < 0
                            OR NEW.received_compressed_bytes < 0
                            OR NEW.validation_attempts < 0
                            THEN RAISE(ABORT, 'graph import unsigned value must be non-negative')
                        WHEN NEW.status = 'validated' AND NEW.expires_at IS NOT NULL
                            THEN RAISE(ABORT, 'validated graph imports cannot expire')
                    END;
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_imports_schema_status_update
                BEFORE UPDATE ON hades_graph_imports
                BEGIN
                    SELECT CASE
                        WHEN NEW."schema" <> 'hades.code_graph.v2'
                            THEN RAISE(ABORT, 'invalid graph import schema')
                        WHEN NEW.status NOT IN ('staging', 'validating', 'validated', 'failed', 'stale')
                            THEN RAISE(ABORT, 'invalid graph import status')
                        WHEN NEW.attempt_generation < 1
                            THEN RAISE(ABORT, 'graph import attempt generation must be positive')
                        WHEN NEW.scope_generation < 1
                            THEN RAISE(ABORT, 'graph import scope generation must be positive')
                        WHEN NEW.expected_chunks < 0
                            OR NEW.received_chunks < 0
                            OR NEW.expected_uncompressed_bytes < 0
                            OR NEW.received_uncompressed_bytes < 0
                            OR NEW.expected_compressed_bytes < 0
                            OR NEW.received_compressed_bytes < 0
                            OR NEW.validation_attempts < 0
                            THEN RAISE(ABORT, 'graph import unsigned value must be non-negative')
                        WHEN NEW.status = 'validated' AND NEW.expires_at IS NOT NULL
                            THEN RAISE(ABORT, 'validated graph imports cannot expire')
                    END;
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX hades_graph_imports_live_unique
                ON hades_graph_imports (
                    project_id,
                    workspace_binding_id,
                    artifact_graph_version
                )
                WHERE status IN ('staging', 'validating')
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_imports_immutable_validated_update
                BEFORE UPDATE ON hades_graph_imports
                WHEN OLD.status = 'validated'
                BEGIN
                    SELECT RAISE(ABORT, 'validated graph imports are immutable');
                END
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'DROP TRIGGER IF EXISTS hades_graph_imports_immutable_validated_trigger ON hades_graph_imports',
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS hades_graph_imports_immutable_validated()',
            );
            DB::statement(
                'DROP INDEX IF EXISTS hades_graph_imports_live_unique',
            );
            DB::statement(
                'ALTER TABLE hades_graph_imports DROP CONSTRAINT IF EXISTS hades_graph_imports_status_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_imports DROP CONSTRAINT IF EXISTS hades_graph_imports_schema_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_imports DROP CONSTRAINT IF EXISTS hades_graph_imports_attempt_generation_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_imports DROP CONSTRAINT IF EXISTS hades_graph_imports_scope_generation_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_imports DROP CONSTRAINT IF EXISTS hades_graph_imports_unsigned_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_imports DROP CONSTRAINT IF EXISTS hades_graph_imports_validated_expiry_check',
            );
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_imports_schema_status_insert');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_imports_schema_status_update');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_imports_immutable_validated_update');
            DB::statement('DROP INDEX IF EXISTS hades_graph_imports_live_unique');
        }

        Schema::table('hades_graph_imports', function (Blueprint $table): void {
            $table->dropUnique('hades_graph_imports_scope_generation_unique');
        });

        Schema::dropIfExists('hades_graph_imports');
        Schema::table('hades_workspace_bindings', function (Blueprint $table): void {
            $table->dropUnique('hades_workspace_bindings_project_id_id_unique');
        });
    }
};
