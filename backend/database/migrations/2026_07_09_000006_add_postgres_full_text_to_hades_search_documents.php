<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE hades_search_documents ADD COLUMN search_vector tsvector");
        DB::statement("UPDATE hades_search_documents SET search_vector = to_tsvector('english', coalesce(title, '') || ' ' || coalesce(body, '') || ' ' || coalesce(source_schema, ''))");
        DB::statement("CREATE INDEX hades_search_documents_tsvector_idx ON hades_search_documents USING GIN (search_vector)");

        DB::statement("
            CREATE OR REPLACE FUNCTION hades_search_documents_tsvector_trigger() RETURNS trigger AS \$\$
            BEGIN
                NEW.search_vector := to_tsvector('english', coalesce(NEW.title, '') || ' ' || coalesce(NEW.body, '') || ' ' || coalesce(NEW.source_schema, ''));
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        ");

        DB::statement("CREATE TRIGGER hades_search_documents_tsvector_update BEFORE INSERT OR UPDATE ON hades_search_documents FOR EACH ROW EXECUTE FUNCTION hades_search_documents_tsvector_trigger()");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DROP TRIGGER IF EXISTS hades_search_documents_tsvector_update ON hades_search_documents");
        DB::statement("DROP FUNCTION IF EXISTS hades_search_documents_tsvector_trigger()");
        DB::statement("DROP INDEX IF EXISTS hades_search_documents_tsvector_idx");
        DB::statement("ALTER TABLE hades_search_documents DROP COLUMN IF EXISTS search_vector");
    }
};
