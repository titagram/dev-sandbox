<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missing = DB::table('audit_logs')
            ->whereNull('sequence')
            ->orWhereNull('chain_version')
            ->orWhereNull('row_hash')
            ->count();

        if ($missing > 0) {
            throw new RuntimeException('Cannot enforce audit chain constraints while audit_logs contains null chain fields. Run audit:chain-backfill first.');
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unique('sequence', 'audit_logs_sequence_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE audit_logs ALTER COLUMN sequence SET NOT NULL');
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN chain_version SET NOT NULL');
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN row_hash SET NOT NULL');
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_row_hash_length_check CHECK (length(row_hash) = 64)');
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_first_prev_hash_check CHECK ((sequence = 1 AND prev_hash IS NULL) OR (sequence > 1 AND prev_hash IS NOT NULL))');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_first_prev_hash_check');
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_row_hash_length_check');
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN row_hash DROP NOT NULL');
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN chain_version DROP NOT NULL');
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN sequence DROP NOT NULL');
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropUnique('audit_logs_sequence_unique');
        });
    }
};
