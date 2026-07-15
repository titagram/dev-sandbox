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
            if ($driver === 'pgsql') {
                $table->jsonb('coverage')->nullable();
            } else {
                $table->json('coverage')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('canonical_graph_projections', function (Blueprint $table): void {
            $table->dropColumn('coverage');
        });
    }
};
