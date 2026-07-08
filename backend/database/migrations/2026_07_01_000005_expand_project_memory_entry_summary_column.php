<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_memory_entries') || ! Schema::hasColumn('project_memory_entries', 'summary')) {
            return;
        }

        if (Schema::getColumnType('project_memory_entries', 'summary') === 'text') {
            return;
        }

        Schema::table('project_memory_entries', function (Blueprint $table): void {
            $table->text('summary')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('project_memory_entries') || ! Schema::hasColumn('project_memory_entries', 'summary')) {
            return;
        }

        if (Schema::getColumnType('project_memory_entries', 'summary') !== 'text') {
            return;
        }

        Schema::table('project_memory_entries', function (Blueprint $table): void {
            $table->string('summary')->change();
        });
    }
};
