<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE hades_search_documents ADD FULLTEXT hades_search_documents_fulltext_idx (title, body, source_schema)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE hades_search_documents DROP INDEX hades_search_documents_fulltext_idx');
    }
};
