<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->string('device_signing_secret_hash')->nullable();
            $table->index(['project_id', 'hades_agent_id'], 'api_tokens_hades_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            $table->dropIndex('api_tokens_hades_scope_index');
            $table->dropConstrainedForeignId('hades_agent_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('device_signing_secret_hash');
        });
    }
};
