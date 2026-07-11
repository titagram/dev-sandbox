<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agent_profiles', function (Blueprint $table): void {
            $table->string('visibility_scope')->default('global')->index();
        });

        Schema::create('ai_agent_project_visibility', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_agent_profile_id')->constrained('ai_agent_profiles')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['ai_agent_profile_id', 'project_id'], 'ai_agent_project_visibility_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_project_visibility');

        Schema::table('ai_agent_profiles', function (Blueprint $table): void {
            $table->dropColumn('visibility_scope');
        });
    }
};
