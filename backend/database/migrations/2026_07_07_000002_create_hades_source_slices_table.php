<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hades_source_slices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hades_agent_id')->nullable()->constrained('hades_agents')->nullOnDelete();
            $table->foreignUlid('workspace_binding_id')->constrained('hades_workspace_bindings')->cascadeOnDelete();
            $table->foreignUlid('job_id')->nullable()->constrained('hades_agent_jobs')->nullOnDelete();
            $table->string('path', 1024);
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');
            $table->string('language', 64)->nullable();
            $table->string('symbol', 512)->nullable();
            $table->string('head_commit', 80)->nullable();
            $table->string('sha256', 64);
            $table->mediumText('content_redacted');
            $table->unsignedInteger('redactions')->default(0);
            $table->boolean('truncated')->default(false);
            $table->string('retention_class')->default('source_slice');
            $table->string('policy')->default('manual_review');
            $table->timestamps();

            $table->index(['project_id', 'path']);
            $table->index(['workspace_binding_id', 'path']);
            $table->index(['workspace_binding_id', 'head_commit']);
            $table->index(['job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hades_source_slices');
    }
};
