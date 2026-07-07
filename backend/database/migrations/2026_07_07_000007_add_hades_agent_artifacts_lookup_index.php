<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hades_agent_artifacts', function (Blueprint $table): void {
            $table->index(['project_id', 'workspace_binding_id', 'schema', 'sha256'], 'hades_agent_artifacts_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hades_agent_artifacts', function (Blueprint $table): void {
            $table->dropIndex('hades_agent_artifacts_lookup_idx');
        });
    }
};
