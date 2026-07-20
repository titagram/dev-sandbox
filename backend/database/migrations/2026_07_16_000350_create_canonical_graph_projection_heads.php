<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_graph_projection_heads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id');
            $table->string('source_scope_type', 32);
            $table->string('source_scope_id', 191);
            $table->unsignedBigInteger('desired_generation')->default(0);
            $table->char('desired_artifact_graph_version', 64)->nullable();
            $table->char('desired_verification_set_hash', 64)->nullable();
            $table->char('desired_projection_version', 64)->nullable();
            $table->foreignUlid('active_projection_id')->nullable();
            $table->foreignUlid('previous_projection_id')->nullable();
            $table->unsignedBigInteger('failed_generation')->nullable();
            $table->char('failed_projection_version', 64)->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['project_id', 'source_scope_type', 'source_scope_id'],
                'canonical_graph_projection_heads_scope_unique',
            );
            $table->foreign('project_id', 'canonical_graph_projection_heads_project_foreign')
                ->references('id')
                ->on('projects')
                ->cascadeOnDelete();
            $table->foreign(
                'active_projection_id',
                'canonical_graph_projection_heads_active_projection_foreign',
            )
                ->references('id')
                ->on('canonical_graph_projections')
                ->nullOnDelete();
            $table->foreign(
                'previous_projection_id',
                'canonical_graph_projection_heads_previous_projection_foreign',
            )
                ->references('id')
                ->on('canonical_graph_projections')
                ->nullOnDelete();
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE canonical_graph_projection_heads
                ADD CONSTRAINT canonical_graph_projection_heads_scope_type_check
                CHECK (source_scope_type = 'workspace_binding')
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE canonical_graph_projection_heads
                ADD CONSTRAINT canonical_graph_projection_heads_unsigned_check
                CHECK (desired_generation >= 0 AND (failed_generation IS NULL OR failed_generation >= 0))
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER canonical_graph_projection_heads_scope_type_insert
                BEFORE INSERT ON canonical_graph_projection_heads
                WHEN NEW.source_scope_type <> 'workspace_binding'
                BEGIN
                    SELECT RAISE(ABORT, 'invalid graph projection head scope type');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER canonical_graph_projection_heads_scope_type_update
                BEFORE UPDATE ON canonical_graph_projection_heads
                WHEN NEW.source_scope_type <> 'workspace_binding'
                BEGIN
                    SELECT RAISE(ABORT, 'invalid graph projection head scope type');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER canonical_graph_projection_heads_unsigned_insert
                BEFORE INSERT ON canonical_graph_projection_heads
                WHEN NEW.desired_generation < 0 OR NEW.failed_generation < 0
                BEGIN
                    SELECT RAISE(ABORT, 'canonical graph projection head generation must be non-negative');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER canonical_graph_projection_heads_unsigned_update
                BEFORE UPDATE ON canonical_graph_projection_heads
                WHEN NEW.desired_generation < 0 OR NEW.failed_generation < 0
                BEGIN
                    SELECT RAISE(ABORT, 'canonical graph projection head generation must be non-negative');
                END
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE canonical_graph_projection_heads DROP CONSTRAINT IF EXISTS canonical_graph_projection_heads_scope_type_check',
            );
            DB::statement(
                'ALTER TABLE canonical_graph_projection_heads DROP CONSTRAINT IF EXISTS canonical_graph_projection_heads_unsigned_check',
            );
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS canonical_graph_projection_heads_scope_type_insert');
            DB::statement('DROP TRIGGER IF EXISTS canonical_graph_projection_heads_scope_type_update');
            DB::statement('DROP TRIGGER IF EXISTS canonical_graph_projection_heads_unsigned_insert');
            DB::statement('DROP TRIGGER IF EXISTS canonical_graph_projection_heads_unsigned_update');
        }

        Schema::dropIfExists('canonical_graph_projection_heads');
    }
};
