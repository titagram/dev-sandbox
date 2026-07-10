<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('sequence')->nullable()->after('id');
            $table->smallInteger('chain_version')->nullable()->after('sequence');
            $table->string('actor_user_ref')->nullable()->after('actor_user_id');
            $table->string('actor_device_ref')->nullable()->after('actor_device_id');

            $table->index('sequence');
        });

        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('chain_key')->primary();
            $table->unsignedBigInteger('last_sequence');
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('updated_at');
        });

        DB::table('audit_chain_heads')->insert([
            'chain_key' => 'global',
            'last_sequence' => 0,
            'last_hash' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['sequence']);
            $table->dropColumn(['sequence', 'chain_version', 'actor_user_ref', 'actor_device_ref']);
        });
    }
};
