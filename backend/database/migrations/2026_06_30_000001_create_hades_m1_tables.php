<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_bootstrap_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('token_prefix')->index();
            $table->string('token_hash');
            $table->string('name');
            $table->json('scopes');
            $table->json('allowed_capabilities')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
        });

        Schema::create('hades_agents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('external_agent_id');
            $table->string('label');
            $table->string('platform')->default('unknown');
            $table->string('version')->default('unknown');
            $table->json('declared_capabilities');
            $table->json('effective_capabilities');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('project_id');
            $table->unique(['project_id', 'external_agent_id']);
        });

        Schema::create('hades_agent_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->constrained('hades_agents')->cascadeOnDelete();
            $table->string('token_prefix')->index();
            $table->string('token_hash');
            $table->string('name');
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'hades_agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_agent_tokens');
        Schema::dropIfExists('hades_agents');
        Schema::dropIfExists('hades_bootstrap_tokens');
    }
};
