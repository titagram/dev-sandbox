<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('default_code_exposure_policy');
            $table->foreignId('archived_by_user_id')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable()->after('archived_by_user_id');
            $table->foreignId('deleted_by_user_id')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable()->after('deleted_by_user_id');
            $table->foreignId('restored_by_user_id')->nullable()->after('restored_at')->constrained('users')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('restored_by_user_id');
            $table->dropColumn('restored_at');
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropColumn('deleted_at');
            $table->dropConstrainedForeignId('archived_by_user_id');
            $table->dropColumn('archived_at');
        });
    }
};
