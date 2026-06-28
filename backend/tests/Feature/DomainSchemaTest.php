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
        ]);

    expect(DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('model_name'))
        ->toBe('gpt-5.4');

    expect(DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->value('default_model_profile_id'))
        ->not->toBeNull();
});
