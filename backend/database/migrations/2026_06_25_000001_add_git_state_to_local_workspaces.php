<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_workspaces', function (Blueprint $table): void {
            $table->string('remote_name')->nullable()->after('dirty_status');
            $table->string('remote_url_host')->nullable()->after('remote_name');
            $table->string('remote_url_hash')->nullable()->after('remote_url_host');
            $table->string('upstream_branch')->nullable()->after('remote_url_hash');
            $table->unsignedInteger('ahead_count')->nullable()->after('upstream_branch');
            $table->unsignedInteger('behind_count')->nullable()->after('ahead_count');
            $table->timestamp('git_state_observed_at')->nullable()->after('behind_count');
        });
    }

    public function down(): void
    {
        Schema::table('local_workspaces', function (Blueprint $table): void {
            $table->dropColumn([
                'remote_name',
                'remote_url_host',
                'remote_url_hash',
                'upstream_branch',
                'ahead_count',
                'behind_count',
                'git_state_observed_at',
            ]);
        });
    }
};
