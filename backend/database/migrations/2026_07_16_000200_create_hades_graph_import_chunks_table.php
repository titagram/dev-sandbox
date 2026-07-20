<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_graph_import_chunks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('graph_import_id');
            $table->unsignedInteger('chunk_index');
            $table->string('kind', 32);
            $table->char('sha256', 64);
            $table->unsignedInteger('record_count');
            $table->unsignedInteger('uncompressed_bytes');
            $table->string('compression', 16);
            $table->char('compressed_sha256', 64);
            $table->unsignedInteger('compressed_bytes');
            $table->string('storage_disk', 64);
            $table->string('storage_path', 1024);
            $table->timestampTz('received_at');
            $table->timestamps();

            $table->unique(
                ['graph_import_id', 'chunk_index'],
                'hades_graph_import_chunks_import_index_unique',
            );
            $table->foreign('graph_import_id', 'hades_graph_import_chunks_import_foreign')
                ->references('id')
                ->on('hades_graph_imports')
                ->cascadeOnDelete();
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_import_chunks
                ADD CONSTRAINT hades_graph_import_chunks_compression_check
                CHECK (compression = 'gzip')
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_import_chunks
                ADD CONSTRAINT hades_graph_import_chunks_kind_check
                CHECK (kind IN ('nodes', 'entrypoints', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties'))
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE hades_graph_import_chunks
                ADD CONSTRAINT hades_graph_import_chunks_unsigned_check
                CHECK (
                    chunk_index >= 0
                    AND record_count >= 0
                    AND uncompressed_bytes >= 0
                    AND compressed_bytes >= 0
                )
            SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION hades_graph_import_chunks_contract_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP IN ('INSERT', 'UPDATE') THEN
                        IF NEW.compression <> 'gzip'
                            OR NEW.kind NOT IN ('nodes', 'entrypoints', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties') THEN
                            RAISE EXCEPTION 'invalid graph import chunk contract';
                        END IF;

                        IF NEW.chunk_index < 0
                            OR NEW.record_count < 0
                            OR NEW.uncompressed_bytes < 0
                            OR NEW.compressed_bytes < 0 THEN
                            RAISE EXCEPTION 'graph import chunk unsigned value must be non-negative';
                        END IF;
                    END IF;

                    IF EXISTS (
                        SELECT 1
                        FROM hades_graph_imports
                        WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.graph_import_id ELSE NEW.graph_import_id END
                          AND status = 'validated'
                    ) THEN
                        RAISE EXCEPTION 'validated graph import chunks are immutable';
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END;
                $$
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_chunks_contract_trigger
                BEFORE INSERT OR UPDATE OR DELETE ON hades_graph_import_chunks
                FOR EACH ROW
                EXECUTE FUNCTION hades_graph_import_chunks_contract_guard()
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_chunks_contract_insert
                BEFORE INSERT ON hades_graph_import_chunks
                BEGIN
                    SELECT CASE
                        WHEN NEW.compression <> 'gzip'
                            THEN RAISE(ABORT, 'invalid graph import chunk compression')
                        WHEN NEW.kind NOT IN ('nodes', 'entrypoints', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties')
                            THEN RAISE(ABORT, 'invalid graph import chunk kind')
                        WHEN NEW.chunk_index < 0
                            OR NEW.record_count < 0
                            OR NEW.uncompressed_bytes < 0
                            OR NEW.compressed_bytes < 0
                            THEN RAISE(ABORT, 'graph import chunk unsigned value must be non-negative')
                    END;
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_chunks_contract_update
                BEFORE UPDATE ON hades_graph_import_chunks
                BEGIN
                    SELECT CASE
                        WHEN NEW.compression <> 'gzip'
                            THEN RAISE(ABORT, 'invalid graph import chunk compression')
                        WHEN NEW.kind NOT IN ('nodes', 'entrypoints', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties')
                            THEN RAISE(ABORT, 'invalid graph import chunk kind')
                        WHEN NEW.chunk_index < 0
                            OR NEW.record_count < 0
                            OR NEW.uncompressed_bytes < 0
                            OR NEW.compressed_bytes < 0
                            THEN RAISE(ABORT, 'graph import chunk unsigned value must be non-negative')
                    END;
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_chunks_immutable_insert
                BEFORE INSERT ON hades_graph_import_chunks
                WHEN EXISTS (
                    SELECT 1
                    FROM hades_graph_imports
                    WHERE id = NEW.graph_import_id AND status = 'validated'
                )
                BEGIN
                    SELECT RAISE(ABORT, 'validated graph import chunks are immutable');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_chunks_immutable_update
                BEFORE UPDATE ON hades_graph_import_chunks
                WHEN EXISTS (
                    SELECT 1
                    FROM hades_graph_imports
                    WHERE id = NEW.graph_import_id AND status = 'validated'
                )
                BEGIN
                    SELECT RAISE(ABORT, 'validated graph import chunks are immutable');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER hades_graph_import_chunks_immutable_delete
                BEFORE DELETE ON hades_graph_import_chunks
                WHEN EXISTS (
                    SELECT 1
                    FROM hades_graph_imports
                    WHERE id = OLD.graph_import_id AND status = 'validated'
                )
                BEGIN
                    SELECT RAISE(ABORT, 'validated graph import chunks are immutable');
                END
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE hades_graph_import_chunks DROP CONSTRAINT IF EXISTS hades_graph_import_chunks_kind_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_import_chunks DROP CONSTRAINT IF EXISTS hades_graph_import_chunks_compression_check',
            );
            DB::statement(
                'ALTER TABLE hades_graph_import_chunks DROP CONSTRAINT IF EXISTS hades_graph_import_chunks_unsigned_check',
            );
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_chunks_contract_trigger ON hades_graph_import_chunks');
            DB::statement('DROP FUNCTION IF EXISTS hades_graph_import_chunks_contract_guard()');
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_chunks_contract_insert');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_chunks_contract_update');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_chunks_immutable_insert');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_chunks_immutable_update');
            DB::statement('DROP TRIGGER IF EXISTS hades_graph_import_chunks_immutable_delete');
        }

        Schema::dropIfExists('hades_graph_import_chunks');
    }
};
