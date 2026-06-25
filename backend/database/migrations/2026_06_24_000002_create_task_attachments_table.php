<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('storage_path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type');
            $table->string('kind');
            $table->string('status')->default('available');
            $table->string('scan_status')->default('not_scanned');
            $table->json('metadata');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('task_id');
            $table->index('uploaded_by_user_id');
            $table->index('deleted_at');
            $table->index(['project_id', 'task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
