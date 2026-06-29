<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates project workspace memory and work queue schema', function () {
    expect(Schema::hasColumn('tasks', 'acceptance_criteria'))->toBeTrue()
        ->and(Schema::hasTable('repository_task'))->toBeTrue()
        ->and(Schema::hasColumns('repository_task', ['id', 'task_id', 'repository_id', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasTable('project_memory_entries'))->toBeTrue()
        ->and(Schema::hasColumns('project_memory_entries', [
            'id',
            'project_id',
            'repository_id',
            'task_id',
            'run_id',
            'author_user_id',
            'agent_key',
            'source',
            'kind',
            'completeness',
            'summary',
            'payload',
            'occurred_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('project_memory_links'))->toBeTrue()
        ->and(Schema::hasColumns('project_memory_links', ['id', 'memory_entry_id', 'target_type', 'target_id', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasTable('agent_work_items'))->toBeTrue()
        ->and(Schema::hasColumns('agent_work_items', [
            'id',
            'project_id',
            'repository_id',
            'task_id',
            'requested_by_user_id',
            'assigned_agent_key',
            'status',
            'priority',
            'title',
            'prompt',
            'payload',
            'requires_memory_entry',
            'result_memory_entry_id',
            'claimed_by_device_id',
            'claimed_at',
            'heartbeat_at',
            'completed_at',
            'failed_at',
            'canceled_at',
            'failure_reason',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('agent_work_item_events'))->toBeTrue()
        ->and(Schema::hasColumns('agent_work_item_events', ['id', 'agent_work_item_id', 'actor_user_id', 'actor_device_id', 'event_type', 'message', 'payload', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasTable('agent_work_item_leases'))->toBeTrue()
        ->and(Schema::hasColumns('agent_work_item_leases', ['id', 'agent_work_item_id', 'device_id', 'lease_token_hash', 'expires_at', 'released_at', 'created_at', 'updated_at']))->toBeTrue();
});
