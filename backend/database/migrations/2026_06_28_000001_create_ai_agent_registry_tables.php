<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider_key')->unique();
            $table->string('display_name');
            $table->string('provider_type')->default('openai_compatible');
            $table->string('base_url')->nullable();
            $table->text('encrypted_api_key')->nullable();
            $table->string('api_key_last_four', 16)->nullable();
            $table->timestamp('api_key_updated_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->json('metadata');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_model_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('provider_id')->constrained('ai_model_providers')->cascadeOnDelete();
            $table->string('profile_key')->unique();
            $table->string('display_name');
            $table->string('model_name');
            $table->string('runtime_profile')->default('compact_readonly');
            $table->unsignedInteger('max_context')->nullable();
            $table->unsignedInteger('max_output_tokens')->default(2048);
            $table->decimal('temperature', 4, 2)->default(0);
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->boolean('enabled')->default(false);
            $table->json('metadata');
            $table->timestamps();

            $table->index('provider_id');
        });

        Schema::create('ai_agent_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('agent_key')->unique();
            $table->string('display_name');
            $table->text('description');
            $table->string('agent_type');
            $table->string('delegation_mode')->default('controlled_registry');
            $table->string('parent_agent_key')->nullable()->index();
            $table->foreignUlid('default_model_profile_id')->nullable()->constrained('ai_model_profiles')->nullOnDelete();
            $table->boolean('requires_human_approval')->default(true);
            $table->boolean('enabled')->default(true);
            $table->json('allowed_tools');
            $table->json('output_schema');
            $table->json('trigger_events');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_profiles');
        Schema::dropIfExists('ai_model_profiles');
        Schema::dropIfExists('ai_model_providers');
    }
};
