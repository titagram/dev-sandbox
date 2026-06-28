<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
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
