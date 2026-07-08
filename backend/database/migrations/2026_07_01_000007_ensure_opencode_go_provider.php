<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $existing = DB::table('ai_model_providers')->where('provider_key', 'opencode_go')->first();

        if (! $existing) {
            DB::table('ai_model_providers')->insert([
                'id' => (string) Str::ulid(),
                'provider_key' => 'opencode_go',
                'display_name' => 'OpenCode Go',
                'provider_type' => 'openai_compatible',
                'base_url' => 'https://opencode.ai/zen/go/v1',
                'encrypted_api_key' => null,
                'api_key_last_four' => null,
                'api_key_updated_at' => null,
                'enabled' => false,
                'metadata' => json_encode([
                    'source_status' => 'migration_seeded',
                    'notes' => 'OpenCode Go provider slot. Admin supplies credentials from the dashboard.',
                ], JSON_THROW_ON_ERROR),
                'created_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        if ($existing->base_url === null || trim((string) $existing->base_url) === '') {
            DB::table('ai_model_providers')->where('id', $existing->id)->update([
                'base_url' => 'https://opencode.ai/zen/go/v1',
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ai_model_providers')
            ->where('provider_key', 'opencode_go')
            ->whereNull('encrypted_api_key')
            ->delete();
    }
};
