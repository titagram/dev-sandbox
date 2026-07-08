<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('agent_profile_id')->nullable()->constrained('ai_agent_profiles')->nullOnDelete();
            $table->string('target_type')->index();
            $table->string('target_id')->index();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->index();
            $table->foreignUlid('model_provider_id')->nullable()->constrained('ai_model_providers')->nullOnDelete();
            $table->foreignUlid('model_profile_id')->nullable()->constrained('ai_model_profiles')->nullOnDelete();
            $table->string('context_hash', 64);
            $table->json('context_snapshot');
            $table->text('result_summary')->nullable();
            $table->json('metadata');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'target_type', 'target_id']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assistant_run_id')->constrained('assistant_runs')->cascadeOnDelete();
            $table->string('role');
            $table->longText('content');
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent();

            $table->index('assistant_run_id');
        });

        Schema::create('assistant_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assistant_run_id')->constrained('assistant_runs')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('target_type')->index();
            $table->string('target_id')->index();
            $table->string('suggestion_type')->index();
            $table->string('title');
            $table->longText('body_markdown');
            $table->json('structured_payload');
            $table->json('evidence_refs');
            $table->decimal('confidence', 4, 2)->default(0);
            $table->boolean('approval_required')->default(true);
            $table->string('status')->default('pending')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_suggestions');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_runs');
    }
};
