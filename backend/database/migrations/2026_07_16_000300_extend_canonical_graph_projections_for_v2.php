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

        Schema::table('canonical_graph_projections', function (Blueprint $table) use ($driver): void {
            $table->foreignUlid('graph_import_id')
                ->nullable()
                ->after('project_id');
            $table->string('graph_contract_version')->nullable();
            $table->char('artifact_graph_version', 64)->nullable();
            $table->char('verification_set_hash', 64)->nullable();
            $table->char('projection_version', 64)->nullable();
            $driver === 'pgsql'
                ? $table->jsonb('source_identity')->nullable()
                : $table->json('source_identity')->nullable();
            $driver === 'pgsql'
                ? $table->jsonb('completeness')->nullable()
                : $table->json('completeness')->nullable();
            $table->unsignedBigInteger('base_node_count')->nullable();
            $table->unsignedBigInteger('base_relationship_count')->nullable();
            $table->unsignedBigInteger('base_flow_count')->nullable();
            $table->unsignedBigInteger('effective_node_count')->nullable();
            $table->unsignedBigInteger('effective_relationship_count')->nullable();
            $table->unsignedBigInteger('effective_flow_count')->nullable();

            $table->index('graph_import_id', 'canonical_graph_projections_graph_import_id_idx');
            $table->index(
                'artifact_graph_version',
                'canonical_graph_projections_artifact_graph_version_idx',
            );
            $table->index(
                'projection_version',
                'canonical_graph_projections_projection_version_idx',
            );
            $table->unique(
                ['project_id', 'source_scope_type', 'source_scope_id', 'projection_version'],
                'canonical_graph_projections_v2_scope_version_unique',
            );
            $table->foreign('graph_import_id', 'canonical_graph_projections_graph_import_foreign')
                ->references('id')
                ->on('hades_graph_imports')
                ->nullOnDelete();
        });

        Schema::table('canonical_graph_projection_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('desired_generation')->nullable();
            $table->char('candidate_projection_version', 64)->nullable();
        });

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE canonical_graph_projections
                ADD CONSTRAINT canonical_graph_projections_v2_unsigned_check
                CHECK (
                    (base_node_count IS NULL OR base_node_count >= 0)
                    AND (base_relationship_count IS NULL OR base_relationship_count >= 0)
                    AND (base_flow_count IS NULL OR base_flow_count >= 0)
                    AND (effective_node_count IS NULL OR effective_node_count >= 0)
                    AND (effective_relationship_count IS NULL OR effective_relationship_count >= 0)
                    AND (effective_flow_count IS NULL OR effective_flow_count >= 0)
                )
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE canonical_graph_projection_attempts
                ADD CONSTRAINT canonical_graph_projection_attempts_v2_unsigned_check
                CHECK (desired_generation IS NULL OR desired_generation >= 0)
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER canonical_graph_projections_v2_unsigned_insert
                BEFORE INSERT ON canonical_graph_projections
                BEGIN
                    SELECT CASE
                        WHEN NEW.base_node_count < 0
                            OR NEW.base_relationship_count < 0
                            OR NEW.base_flow_count < 0
                            OR NEW.effective_node_count < 0
                            OR NEW.effective_relationship_count < 0
                            OR NEW.effective_flow_count < 0
                            THEN RAISE(ABORT, 'canonical graph projection unsigned value must be non-negative')
                    END;
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER canonical_graph_projections_v2_unsigned_update
                BEFORE UPDATE ON canonical_graph_projections
                BEGIN
                    SELECT CASE
                        WHEN NEW.base_node_count < 0
                            OR NEW.base_relationship_count < 0
                            OR NEW.base_flow_count < 0
                            OR NEW.effective_node_count < 0
                            OR NEW.effective_relationship_count < 0
                            OR NEW.effective_flow_count < 0
                            THEN RAISE(ABORT, 'canonical graph projection unsigned value must be non-negative')
                    END;
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER canonical_graph_projection_attempts_v2_unsigned_insert
                BEFORE INSERT ON canonical_graph_projection_attempts
                WHEN NEW.desired_generation < 0
                BEGIN
                    SELECT RAISE(ABORT, 'canonical graph projection attempt generation must be non-negative');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER canonical_graph_projection_attempts_v2_unsigned_update
                BEFORE UPDATE ON canonical_graph_projection_attempts
                WHEN NEW.desired_generation < 0
                BEGIN
                    SELECT RAISE(ABORT, 'canonical graph projection attempt generation must be non-negative');
                END
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE canonical_graph_projection_attempts DROP CONSTRAINT IF EXISTS canonical_graph_projection_attempts_v2_unsigned_check',
            );
            DB::statement(
                'ALTER TABLE canonical_graph_projections DROP CONSTRAINT IF EXISTS canonical_graph_projections_v2_unsigned_check',
            );
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS canonical_graph_projections_v2_unsigned_insert');
            DB::statement('DROP TRIGGER IF EXISTS canonical_graph_projections_v2_unsigned_update');
            DB::statement('DROP TRIGGER IF EXISTS canonical_graph_projection_attempts_v2_unsigned_insert');
            DB::statement('DROP TRIGGER IF EXISTS canonical_graph_projection_attempts_v2_unsigned_update');
        }

        Schema::table('canonical_graph_projection_attempts', function (Blueprint $table): void {
            $table->dropColumn(['desired_generation', 'candidate_projection_version']);
        });

        Schema::table('canonical_graph_projections', function (Blueprint $table): void {
            $table->dropForeign('canonical_graph_projections_graph_import_foreign');
            $table->dropUnique('canonical_graph_projections_v2_scope_version_unique');
            $table->dropIndex('canonical_graph_projections_graph_import_id_idx');
            $table->dropIndex('canonical_graph_projections_artifact_graph_version_idx');
            $table->dropIndex('canonical_graph_projections_projection_version_idx');
            $table->dropColumn([
                'graph_import_id',
                'graph_contract_version',
                'artifact_graph_version',
                'verification_set_hash',
                'projection_version',
                'source_identity',
                'completeness',
                'base_node_count',
                'base_relationship_count',
                'base_flow_count',
                'effective_node_count',
                'effective_relationship_count',
                'effective_flow_count',
            ]);
        });
    }
};
