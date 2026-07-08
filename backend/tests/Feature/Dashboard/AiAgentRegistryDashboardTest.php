<?php

use App\Models\User;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(DevBoardSeeder::class);
});

it('shows the controlled AI agent registry page to admins only', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');
    $pm = aiAgentRegistryUserWithRole('PM');

    $this->actingAs($admin)->get('/admin/ai-agents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AiAgents')
            ->where('providers.0.provider_key', 'openai')
            ->where('providers.0.api_key_configured', false)
            ->missing('providers.0.encrypted_api_key')
            ->where('agentProfiles.0.agent_key', 'socrate_supervisor')
            ->where('agentProfiles.0.delegation_mode', 'controlled_registry')
        );

    $this->actingAs($pm)->get('/admin/ai-agents')->assertForbidden();
});

it('lets an admin store a model provider api key without returning the secret', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');

    $response = $this->actingAs($admin)->putJson('/api/dashboard/admin/ai-model-providers/openai', [
        'display_name' => 'OpenAI Gateway',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'sk-test-devboard-secret-123456',
        'enabled' => true,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('provider.provider_key', 'openai')
        ->assertJsonPath('provider.display_name', 'OpenAI Gateway')
        ->assertJsonPath('provider.base_url', 'https://api.openai.com/v1')
        ->assertJsonPath('provider.api_key_configured', true)
        ->assertJsonPath('provider.api_key_last_four', '3456')
        ->assertJsonMissing(['api_key' => 'sk-test-devboard-secret-123456'])
        ->assertJsonMissing(['encrypted_api_key' => 'sk-test-devboard-secret-123456']);

    $stored = DB::table('ai_model_providers')->where('provider_key', 'openai')->first();

    expect($stored)->not->toBeNull();
    expect($stored->encrypted_api_key)->not->toBeNull();
    expect($stored->encrypted_api_key)->not->toBe('sk-test-devboard-secret-123456');
    expect($stored->api_key_last_four)->toBe('3456');
});

it('lets an admin clear a stored model provider api key', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');

    $this->actingAs($admin)->putJson('/api/dashboard/admin/ai-model-providers/openai', [
        'display_name' => 'OpenAI Gateway',
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'sk-test-devboard-secret-123456',
        'enabled' => true,
    ])->assertOk();

    $this->actingAs($admin)->putJson('/api/dashboard/admin/ai-model-providers/openai', [
        'display_name' => 'OpenAI Gateway',
        'base_url' => 'https://api.openai.com/v1',
        'clear_api_key' => true,
        'enabled' => true,
    ])->assertOk()
        ->assertJsonPath('provider.provider_key', 'openai')
        ->assertJsonPath('provider.api_key_configured', false)
        ->assertJsonPath('provider.api_key_last_four', null)
        ->assertJsonMissing(['encrypted_api_key']);

    $stored = DB::table('ai_model_providers')->where('provider_key', 'openai')->first();

    expect($stored->encrypted_api_key)->toBeNull()
        ->and($stored->api_key_last_four)->toBeNull()
        ->and($stored->api_key_updated_at)->toBeNull();
});

it('seeds opencode go as a first class provider preset and validates it without exposing secrets', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');

    $this->actingAs($admin)
        ->getJson('/api/dashboard/admin/ai-agents')
        ->assertOk()
        ->assertJsonFragment(['provider_key' => 'opencode_go'])
        ->assertJsonMissing(['encrypted_api_key']);

    $secret = 'opencode-go-secret-123456';

    $this->actingAs($admin)->putJson('/api/dashboard/admin/ai-model-providers/opencode_go', [
        'display_name' => 'OpenCode Go',
        'base_url' => 'https://opencode.example.test/zen/go/v1/chat/completions',
        'api_key' => $secret,
        'enabled' => true,
    ])->assertOk()
        ->assertJsonPath('provider.provider_key', 'opencode_go')
        ->assertJsonPath('provider.api_key_configured', true)
        ->assertJsonMissing(['api_key' => $secret])
        ->assertJsonMissing(['encrypted_api_key' => $secret]);

    Http::fake([
        'https://opencode.example.test/zen/go/v1/models' => Http::response([
            'data' => [
                ['id' => 'glm-5.2'],
                ['id' => 'kimi-k2.7-code'],
            ],
        ]),
        'https://opencode.example.test/zen/go/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-test',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'p']],
            ],
        ]),
    ]);

    $this->actingAs($admin)->postJson('/api/dashboard/admin/ai-model-providers/opencode_go/validate')
        ->assertOk()
        ->assertJsonPath('validation.provider_key', 'opencode_go')
        ->assertJsonPath('validation.status', 'ready_for_runtime')
        ->assertJsonPath('validation.checks.api_key_configured', true)
        ->assertJsonPath('validation.checks.api_reachable', true)
        ->assertJsonPath('validation.checks.models_endpoint', 'https://opencode.example.test/zen/go/v1/models')
        ->assertJsonPath('validation.checks.chat_completions_endpoint', 'https://opencode.example.test/zen/go/v1/chat/completions')
        ->assertJsonPath('validation.checks.checked_model', 'glm-5.2')
        ->assertJsonPath('validation.models.0', 'glm-5.2')
        ->assertJsonMissing(['api_key' => $secret])
        ->assertJsonMissing(['encrypted_api_key' => $secret]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.example.test/zen/go/v1/models'
        && $request->hasHeader('Authorization', 'Bearer '.$secret));
    Http::assertSent(fn ($request): bool => $request->url() === 'https://opencode.example.test/zen/go/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer '.$secret)
        && $request['model'] === 'glm-5.2'
        && $request['max_tokens'] === 1);
});

it('lets an admin create a model profile for a provider', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');

    $response = $this->actingAs($admin)->postJson('/api/dashboard/admin/ai-model-profiles', [
        'provider_key' => 'opencode_go',
        'profile_key' => 'opencode_go_default',
        'display_name' => 'OpenCode Go Default',
        'model_name' => 'opencode-go/default',
        'runtime_profile' => 'compact_readonly',
        'max_context' => 128000,
        'max_output_tokens' => 2048,
        'temperature' => 0,
        'timeout_seconds' => 30,
        'enabled' => false,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('model_profile.profile_key', 'opencode_go_default')
        ->assertJsonPath('model_profile.provider_key', 'opencode_go')
        ->assertJsonPath('model_profile.model_name', 'opencode-go/default')
        ->assertJsonMissing(['encrypted_api_key']);
});

it('prevents non-admin users from managing model provider credentials', function () {
    $sysadmin = aiAgentRegistryUserWithRole('Sysadmin');

    $this->actingAs($sysadmin)->putJson('/api/dashboard/admin/ai-model-providers/openai', [
        'display_name' => 'OpenAI',
        'api_key' => 'sk-test-forbidden',
        'enabled' => true,
    ])->assertForbidden();
});

it('lets an admin update an existing model profile', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');

    $response = $this->actingAs($admin)->putJson('/api/dashboard/admin/ai-model-profiles/openai_default_text', [
        'display_name' => 'OpenAI Task Review',
        'model_name' => 'gpt-5-mini',
        'runtime_profile' => 'task_clarifier_readonly',
        'max_context' => 128000,
        'max_output_tokens' => 1200,
        'temperature' => 0.2,
        'timeout_seconds' => 45,
        'enabled' => true,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('model_profile.profile_key', 'openai_default_text')
        ->assertJsonPath('model_profile.display_name', 'OpenAI Task Review')
        ->assertJsonPath('model_profile.model_name', 'gpt-5-mini')
        ->assertJsonPath('model_profile.runtime_profile', 'task_clarifier_readonly')
        ->assertJsonPath('model_profile.max_context', 128000)
        ->assertJsonPath('model_profile.max_output_tokens', 1200)
        ->assertJsonPath('model_profile.temperature', 0.2)
        ->assertJsonPath('model_profile.timeout_seconds', 45)
        ->assertJsonPath('model_profile.enabled', true)
        ->assertJsonMissing(['encrypted_api_key']);

    expect(DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('model_name'))
        ->toBe('gpt-5-mini')
        ->and(DB::table('audit_logs')
            ->where('action', 'ai_model_profile.updated')
            ->where('target_type', 'ai_model_profile')
            ->exists())->toBeTrue();
});

it('lets an admin delete an unassigned model profile', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');

    $this->actingAs($admin)->postJson('/api/dashboard/admin/ai-model-profiles', [
        'provider_key' => 'opencode_go',
        'profile_key' => 'temporary_delete_me',
        'display_name' => 'Temporary Delete Me',
        'model_name' => 'opencode-go/delete-me',
        'runtime_profile' => 'compact_readonly',
        'max_context' => null,
        'max_output_tokens' => 2048,
        'temperature' => 0,
        'timeout_seconds' => 30,
        'enabled' => false,
    ])->assertCreated();

    $profileId = DB::table('ai_model_profiles')->where('profile_key', 'temporary_delete_me')->value('id');

    $this->actingAs($admin)
        ->deleteJson('/api/dashboard/admin/ai-model-profiles/temporary_delete_me')
        ->assertNoContent();

    expect(DB::table('ai_model_profiles')->where('profile_key', 'temporary_delete_me')->exists())->toBeFalse()
        ->and(DB::table('audit_logs')
            ->where('action', 'ai_model_profile.deleted')
            ->where('target_type', 'ai_model_profile')
            ->where('target_id', $profileId)
            ->exists())->toBeTrue();
});

it('lets an admin delete an assigned model profile and clears agent assignments', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');
    $profileId = DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('id');

    DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->update([
        'default_model_profile_id' => $profileId,
    ]);

    $this->actingAs($admin)
        ->deleteJson('/api/dashboard/admin/ai-model-profiles/openai_default_text')
        ->assertNoContent();

    $auditPayload = json_decode((string) DB::table('audit_logs')
        ->where('action', 'ai_model_profile.deleted')
        ->where('target_type', 'ai_model_profile')
        ->where('target_id', $profileId)
        ->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    expect(DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->exists())->toBeFalse()
        ->and(DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->value('default_model_profile_id'))
        ->toBeNull()
        ->and($auditPayload['unassigned_agent_keys'])->toContain('task_clarifier');
});

it('lets an admin assign a model profile to a controlled agent', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');
    $providerId = DB::table('ai_model_providers')->where('provider_key', 'openai')->value('id');
    $profileId = (string) Str::ulid();

    DB::table('ai_model_profiles')->insert([
        'id' => $profileId,
        'provider_id' => $providerId,
        'profile_key' => 'openai_long_context_review',
        'display_name' => 'OpenAI Long Context Review',
        'model_name' => 'gpt-5.4',
        'runtime_profile' => 'long_context_readonly',
        'max_context' => 256000,
        'max_output_tokens' => 4096,
        'temperature' => 0,
        'timeout_seconds' => 60,
        'enabled' => true,
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($admin)->patchJson('/api/dashboard/admin/ai-agent-profiles/task_clarifier', [
        'default_model_profile_id' => $profileId,
        'enabled' => true,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('agent_profile.agent_key', 'task_clarifier')
        ->assertJsonPath('agent_profile.default_model_profile_id', $profileId)
        ->assertJsonPath('agent_profile.enabled', true);

    expect(DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->value('default_model_profile_id'))
        ->toBe($profileId)
        ->and(DB::table('audit_logs')
            ->where('action', 'ai_agent_profile.updated')
            ->where('target_type', 'ai_agent_profile')
            ->where('target_id', DB::table('ai_agent_profiles')->where('agent_key', 'task_clarifier')->value('id'))
            ->exists())->toBeTrue();
});

it('lets an admin create replace and delete a custom agent profile', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');
    $profileId = DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('id');

    $created = $this->actingAs($admin)->postJson('/api/dashboard/admin/ai-agent-profiles', [
        'agent_key' => 'security_reviewer',
        'display_name' => 'Security Reviewer',
        'description' => 'Reviews project memory for security-sensitive decisions.',
        'agent_type' => 'specialist',
        'delegation_mode' => 'controlled_registry',
        'parent_agent_key' => null,
        'default_model_profile_id' => $profileId,
        'requires_human_approval' => true,
        'enabled' => true,
        'allowed_tools' => ['search_project_memory'],
        'output_schema' => ['type' => 'object'],
        'trigger_events' => ['manual_chat'],
    ])->assertCreated()
        ->assertJsonPath('agent_profile.agent_key', 'security_reviewer')
        ->assertJsonPath('agent_profile.allowed_tools.0', 'search_project_memory')
        ->assertJsonPath('agent_profile.output_schema.type', 'object')
        ->json('agent_profile');

    $this->actingAs($admin)->putJson('/api/dashboard/admin/ai-agent-profiles/security_reviewer', [
        'display_name' => 'Security Reviewer',
        'description' => 'Updated description.',
        'agent_type' => 'specialist',
        'delegation_mode' => 'controlled_registry',
        'parent_agent_key' => null,
        'default_model_profile_id' => $profileId,
        'requires_human_approval' => false,
        'enabled' => true,
        'allowed_tools' => ['search_project_memory', 'query_project_graph'],
        'output_schema' => ['type' => 'object', 'required' => ['answer']],
        'trigger_events' => ['manual_chat', 'scheduled_scan'],
    ])->assertOk()
        ->assertJsonPath('agent_profile.description', 'Updated description.')
        ->assertJsonPath('agent_profile.requires_human_approval', false)
        ->assertJsonPath('agent_profile.allowed_tools.1', 'query_project_graph')
        ->assertJsonPath('agent_profile.output_schema.required.0', 'answer');

    $this->actingAs($admin)
        ->deleteJson('/api/dashboard/admin/ai-agent-profiles/security_reviewer')
        ->assertNoContent();

    expect(DB::table('ai_agent_profiles')->where('id', $created['id'])->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'ai_agent_profile.created')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'ai_agent_profile.replaced')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'ai_agent_profile.deleted')->exists())->toBeTrue();
});

it('rejects duplicate custom agent profile keys', function () {
    $admin = aiAgentRegistryUserWithRole('Admin');
    $profileId = DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('id');

    $payload = [
        'agent_key' => 'duplicate_reviewer',
        'display_name' => 'Duplicate Reviewer',
        'description' => 'First profile.',
        'agent_type' => 'specialist',
        'delegation_mode' => 'controlled_registry',
        'parent_agent_key' => null,
        'default_model_profile_id' => $profileId,
        'requires_human_approval' => true,
        'enabled' => true,
        'allowed_tools' => [],
        'output_schema' => ['type' => 'object'],
        'trigger_events' => ['manual_chat'],
    ];

    $this->actingAs($admin)->postJson('/api/dashboard/admin/ai-agent-profiles', $payload)->assertCreated();
    $this->actingAs($admin)->postJson('/api/dashboard/admin/ai-agent-profiles', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['agent_key']);
});

it('prevents non-admin users from managing custom agent profiles', function () {
    $sysadmin = aiAgentRegistryUserWithRole('Sysadmin');
    $profileId = DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('id');

    $payload = [
        'agent_key' => 'forbidden_reviewer',
        'display_name' => 'Forbidden Reviewer',
        'description' => 'Sysadmin cannot create this.',
        'agent_type' => 'specialist',
        'delegation_mode' => 'controlled_registry',
        'parent_agent_key' => null,
        'default_model_profile_id' => $profileId,
        'requires_human_approval' => true,
        'enabled' => true,
        'allowed_tools' => [],
        'output_schema' => ['type' => 'object'],
        'trigger_events' => ['manual_chat'],
    ];

    $this->actingAs($sysadmin)->postJson('/api/dashboard/admin/ai-agent-profiles', $payload)->assertForbidden();
    $this->actingAs($sysadmin)->putJson('/api/dashboard/admin/ai-agent-profiles/task_clarifier', array_diff_key($payload, ['agent_key' => true]))->assertForbidden();
    $this->actingAs($sysadmin)->deleteJson('/api/dashboard/admin/ai-agent-profiles/task_clarifier')->assertForbidden();
});

it('prevents non-admin users from managing model profiles and agent model selection', function () {
    $sysadmin = aiAgentRegistryUserWithRole('Sysadmin');
    $profileId = DB::table('ai_model_profiles')->where('profile_key', 'openai_default_text')->value('id');

    $this->actingAs($sysadmin)->putJson('/api/dashboard/admin/ai-model-profiles/openai_default_text', [
        'display_name' => 'Forbidden',
        'model_name' => 'gpt-5-mini',
        'runtime_profile' => 'compact_readonly',
        'max_context' => null,
        'max_output_tokens' => 2048,
        'temperature' => 0,
        'timeout_seconds' => 30,
        'enabled' => true,
    ])->assertForbidden();

    $this->actingAs($sysadmin)->patchJson('/api/dashboard/admin/ai-agent-profiles/task_clarifier', [
        'default_model_profile_id' => $profileId,
        'enabled' => true,
    ])->assertForbidden();

    $this->actingAs($sysadmin)
        ->deleteJson('/api/dashboard/admin/ai-model-profiles/openai_default_text')
        ->assertForbidden();
});

function aiAgentRegistryUserWithRole(string $roleName): User
{
    $user = User::factory()->create();
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}
