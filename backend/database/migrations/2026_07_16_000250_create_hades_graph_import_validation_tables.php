<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_graph_import_record_keys', function (Blueprint $table): void {
            $table->foreignUlid('graph_import_id');
            $table->string('record_kind', 32);
            $table->string('public_id', 191);
            $table->string('record_subkind', 64)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('identity_variant', 64)->nullable();
            $table->string('owner_public_id', 191)->nullable();
            $table->string('aux_public_id', 191)->nullable();
            $table->string('language', 64)->nullable();
            $table->string('analysis_status', 32)->nullable();
            $table->string('flow_public_id', 191)->nullable();
            $table->string('edge_public_id', 191)->nullable();
            $table->string('source_node_public_id', 191)->nullable();
            $table->string('target_node_public_id', 191)->nullable();
            $table->string('uncertainty_public_id', 191)->nullable();
            $table->string('root_node_public_id', 191)->nullable();
            $table->string('stage_from', 32)->nullable();
            $table->string('stage_to', 32)->nullable();
            $table->string('async_context', 32)->nullable();
            $table->string('async_child_flow_id', 191)->nullable();
            $table->string('relation', 32)->nullable();
            $table->string('edge_flow', 32)->nullable();
            $table->string('branch_group_id', 191)->nullable();
            $table->string('occurrence_owner_public_id', 191)->nullable();
            $table->string('backbone_role', 32)->nullable();
            $table->string('omission_reason', 64)->nullable();
            $table->char('identity_digest', 64)->nullable();
            $table->char('entrypoint_identity_digest', 64)->nullable();
            $table->unsignedInteger('count_hint')->nullable();
            $table->json('flow_counts')->nullable();
            $table->json('flow_capabilities')->nullable();
            $table->string('completeness_status', 32)->nullable();
            $table->json('stage_counts')->nullable();
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('record_ordinal');

            $table->primary(
                ['graph_import_id', 'record_kind', 'public_id'],
                'hades_graph_import_record_keys_primary',
            );
            $table->index(
                ['graph_import_id', 'public_id'],
                'hades_graph_import_record_keys_import_public_id_idx',
            );
            $table->index(
                ['graph_import_id', 'record_kind', 'record_subkind'],
                'hades_graph_import_record_keys_subkind_idx',
            );
            $table->index(
                ['graph_import_id', 'record_kind', 'uncertainty_public_id'],
                'hades_graph_import_record_keys_uncertainty_idx',
            );
            $table->index(
                ['graph_import_id', 'record_kind', 'flow_public_id'],
                'hades_graph_import_record_keys_flow_idx',
            );
            $table->index(
                ['graph_import_id', 'record_kind', 'edge_public_id'],
                'hades_graph_import_record_keys_edge_idx',
            );
            $table->foreign('graph_import_id', 'hades_graph_import_record_keys_import_foreign')
                ->references('id')
                ->on('hades_graph_imports')
                ->cascadeOnDelete();
        });

        Schema::create('hades_graph_import_file_paths', function (Blueprint $table): void {
            $table->foreignUlid('graph_import_id');
            $table->string('path', 1024);
            $table->string('file_node_public_id', 191);
            $table->char('file_sha256', 64);

            $table->primary(
                ['graph_import_id', 'path'],
                'hades_graph_import_file_paths_primary',
            );
            $table->unique(
                ['graph_import_id', 'file_node_public_id'],
                'hades_graph_import_file_paths_import_file_node_unique',
            );
            $table->foreign('graph_import_id', 'hades_graph_import_file_paths_import_foreign')
                ->references('id')
                ->on('hades_graph_imports')
                ->cascadeOnDelete();
        });

        Schema::create('hades_graph_import_references', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUlid('graph_import_id');
            $table->string('owner_record_kind', 32);
            $table->string('owner_public_id', 191);
            $table->string('reference_kind', 64);
            $table->string('target_record_kind', 32)->nullable();
            $table->string('target_public_id', 191)->nullable();

            $table->index(
                ['graph_import_id', 'target_record_kind', 'target_public_id'],
                'hades_graph_import_references_target_idx',
            );
            $table->index(
                ['graph_import_id', 'owner_record_kind', 'owner_public_id', 'reference_kind'],
                'hades_graph_import_references_owner_lookup_idx',
            );
            $table->foreign('graph_import_id', 'hades_graph_import_references_import_foreign')
                ->references('id')
                ->on('hades_graph_imports')
                ->cascadeOnDelete();
            $table->foreign(
                ['graph_import_id', 'owner_record_kind', 'owner_public_id'],
                'hades_graph_import_references_owner_foreign',
            )
                ->references(['graph_import_id', 'record_kind', 'public_id'])
                ->on('hades_graph_import_record_keys')
                ->cascadeOnDelete();
            $table->foreign(
                ['graph_import_id', 'target_record_kind', 'target_public_id'],
                'hades_graph_import_references_target_foreign',
            )
                ->references(['graph_import_id', 'record_kind', 'public_id'])
                ->on('hades_graph_import_record_keys')
                ->cascadeOnDelete();
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_import_record_keys
                ADD CONSTRAINT hades_graph_import_record_keys_kind_check
                CHECK (record_kind IN ('nodes', 'entrypoints', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties'))
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_import_record_keys
                ADD CONSTRAINT hades_graph_import_record_keys_unsigned_check
                CHECK (chunk_index >= 0 AND record_ordinal >= 0)
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_import_references
                ADD CONSTRAINT hades_graph_import_references_id_unsigned_check
                CHECK (id >= 0)
            SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION hades_graph_import_record_keys_cross_kind_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM hades_graph_import_record_keys existing
                        WHERE existing.graph_import_id = NEW.graph_import_id
                          AND existing.public_id = NEW.public_id
                          AND NOT (
                              TG_OP = 'UPDATE'
                              AND existing.graph_import_id = OLD.graph_import_id
                              AND existing.record_kind = OLD.record_kind
                              AND existing.public_id = OLD.public_id
                          )
                          AND existing.record_kind <> NEW.record_kind
                          AND NOT (
                              (NEW.record_kind = 'nodes' AND existing.record_kind = 'entrypoints')
                              OR (NEW.record_kind = 'entrypoints' AND existing.record_kind = 'nodes')
                          )
                    ) THEN
                        RAISE EXCEPTION 'cross-kind public id collision is not allowed';
                    END IF;

                    RETURN NEW;
                END;
                $$
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_record_keys_cross_kind_trigger
                BEFORE INSERT OR UPDATE ON hades_graph_import_record_keys
                FOR EACH ROW
                EXECUTE FUNCTION hades_graph_import_record_keys_cross_kind_guard()
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_record_keys_kind_check_insert
                BEFORE INSERT ON hades_graph_import_record_keys
                BEGIN
                    SELECT CASE
                        WHEN NEW.record_kind NOT IN ('nodes', 'entrypoints', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties')
                            THEN RAISE(ABORT, 'invalid graph import record kind')
                        WHEN NEW.chunk_index < 0 OR NEW.record_ordinal < 0
                            THEN RAISE(ABORT, 'graph import record key unsigned value must be non-negative')
                    END;
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_record_keys_kind_check_update
                BEFORE UPDATE ON hades_graph_import_record_keys
                BEGIN
                    SELECT CASE
                        WHEN NEW.record_kind NOT IN ('nodes', 'entrypoints', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties')
                            THEN RAISE(ABORT, 'invalid graph import record kind')
                        WHEN NEW.chunk_index < 0 OR NEW.record_ordinal < 0
                            THEN RAISE(ABORT, 'graph import record key unsigned value must be non-negative')
                    END;
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_record_keys_cross_kind_insert
                BEFORE INSERT ON hades_graph_import_record_keys
                WHEN EXISTS (
                    SELECT 1
                    FROM hades_graph_import_record_keys existing
                    WHERE existing.graph_import_id = NEW.graph_import_id
                      AND existing.public_id = NEW.public_id
                      AND existing.record_kind <> NEW.record_kind
                      AND NOT (
                          (NEW.record_kind = 'nodes' AND existing.record_kind = 'entrypoints')
                          OR (NEW.record_kind = 'entrypoints' AND existing.record_kind = 'nodes')
                      )
                )
                BEGIN
                    SELECT RAISE(ABORT, 'cross-kind public id collision is not allowed');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_record_keys_cross_kind_update
                BEFORE UPDATE ON hades_graph_import_record_keys
                WHEN EXISTS (
                    SELECT 1
                    FROM hades_graph_import_record_keys existing
                    WHERE existing.graph_import_id = NEW.graph_import_id
                      AND existing.public_id = NEW.public_id
                      AND NOT (
                          existing.graph_import_id = OLD.graph_import_id
                          AND existing.record_kind = OLD.record_kind
                          AND existing.public_id = OLD.public_id
                      )
                      AND existing.record_kind <> NEW.record_kind
                      AND NOT (
                          (NEW.record_kind = 'nodes' AND existing.record_kind = 'entrypoints')
                          OR (NEW.record_kind = 'entrypoints' AND existing.record_kind = 'nodes')
                      )
                )
                BEGIN
                    SELECT RAISE(ABORT, 'cross-kind public id collision is not allowed');
                END
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'DROP TRIGGER IF EXISTS hades_graph_import_record_keys_cross_kind_trigger ON hades_graph_import_record_keys',
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS hades_graph_import_record_keys_cross_kind_guard()',
            );
            DB::statement(
                'ALTER TABLE hades_graph_import_record_keys DROP CONSTRAINT IF EXISTS hades_graph_import_record_keys_kind_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_import_record_keys DROP CONSTRAINT IF EXISTS hades_graph_import_record_keys_unsigned_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_import_references DROP CONSTRAINT IF EXISTS hades_graph_import_references_id_unsigned_check',
            );
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_record_keys_kind_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_record_keys_kind_check_update');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_record_keys_cross_kind_insert');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_record_keys_cross_kind_update');
        }

        Schema::dropIfExists('hades_graph_import_references');
        Schema::dropIfExists('hades_graph_import_file_paths');
        Schema::dropIfExists('hades_graph_import_record_keys');
    }
};
