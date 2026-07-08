<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the devboard core tables', function () {
    $tables = [
        'roles',
        'permissions',
        'api_tokens',
        'devices',
        'projects',
        'repositories',
        'local_workspaces',
        'tasks',
        'kanban_boards',
        'kanban_columns',
        'runs',
        'run_events',
        'artifacts',
        'snapshots',
        'genesis_imports',
        'delta_syncs',
        'wiki_pages',
        'wiki_revisions',
        'ai_model_providers',
        'ai_model_profiles',
        'ai_agent_profiles',
        'assistant_runs',
        'assistant_messages',
        'assistant_suggestions',
        'audit_logs',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }
});

it('seeds the required role names and default kanban columns', function () {
    expect(class_exists(\Database\Seeders\DevBoardSeeder::class))->toBeTrue();

    $this->seed(\Database\Seeders\DevBoardSeeder::class);

    expect(DB::table('roles')->pluck('name')->all())
        ->toEqualCanonicalizing(['Admin', 'PM', 'Developer', 'Sysadmin', 'Agent']);

    expect(DB::table('kanban_columns')->pluck('name')->all())
        ->toEqualCanonicalizing(['Backlog', 'Ready', 'In Progress', 'Blocked', 'Review', 'Done']);

    expect(DB::table('ai_agent_profiles')->pluck('agent_key')->all())
        ->toEqualCanonicalizing([
            'socrate_supervisor',
            'task_clarifier',
            'backlog_triage',
            'wiki_query',
            'watchman',
            'intake_normalizer',
        ]);

    expect(DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('model_name'))
        ->toBe('gpt-5.4');

    expect(DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->value('default_model_profile_id'))
        ->not->toBeNull();
});

it('repairs missing default ai agent registry rows and normalizes opencode go defaults', function () {
    $providerId = DB::table('ai_model_providers')->where('provider_key', 'opencode_go')->value('id');
    $modelProfileId = (string) \Illuminate\Support\Str::ulid();
    expect($providerId)->not->toBeNull();

    DB::table('ai_model_profiles')->insert([
        'id' => $modelProfileId,
        'provider_id' => $providerId,
        'profile_key' => 'opencode_go_default',
        'display_name' => 'OpenCode Go Default',
        'model_name' => 'opencode-go',
        'runtime_profile' => 'compact_readonly',
        'max_context' => null,
        'max_output_tokens' => 2048,
        'temperature' => 0,
        'timeout_seconds' => 30,
        'enabled' => true,
        'metadata' => json_encode(['source_status' => 'developer_provided'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('ai_agent_profiles')->delete();

    expect(DB::table('ai_agent_profiles')->count())->toBe(0);

    $migration = require base_path('database/migrations/2026_07_09_000002_repair_ai_agent_registry_defaults.php');
    $migration->up();

    expect(DB::table('ai_model_profiles')->where('profile_key', 'opencode_go_default')->value('model_name'))
        ->toBe('glm-5.2');

    foreach (['socrate_supervisor', 'task_clarifier', 'backlog_triage'] as $agentKey) {
        $agent = DB::table('ai_agent_profiles')->where('agent_key', $agentKey)->first();

        expect($agent)->not->toBeNull();
        expect($agent->default_model_profile_id)->toBe($modelProfileId);

        expect(json_decode((string) $agent->allowed_tools, true, flags: JSON_THROW_ON_ERROR))->toBeArray();
        expect(json_decode((string) $agent->output_schema, true, flags: JSON_THROW_ON_ERROR))->toBeArray();
        expect(json_decode((string) $agent->trigger_events, true, flags: JSON_THROW_ON_ERROR))->toBeArray();
    }

    expect(DB::table('ai_agent_profiles')->pluck('agent_key')->all())
        ->toContain('intake_normalizer', 'wiki_query', 'watchman');

    $repairedAgentCount = DB::table('ai_agent_profiles')->count();

    $migration->down();

    expect(DB::table('ai_agent_profiles')->count())->toBe($repairedAgentCount);
});
