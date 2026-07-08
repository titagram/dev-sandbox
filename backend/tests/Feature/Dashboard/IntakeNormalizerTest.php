<?php

use App\Assistants\Agents\IntakeNormalizerAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('normalizes bug-like raw text with deterministic fallback', function () {
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => "The login page crashes with a 500 error when I submit an invalid email address.\n\nSteps: go to /login, type 'x@', click submit.\nVersion: 2.3.1 on production.",
        ])
        ->assertOk()
        ->assertJsonPath('normalization.task_type', 'bug')
        ->assertJsonPath('normalization.execution_mode', 'deterministic_fallback');

    $normalization = $response->json('normalization');

    expect($normalization['task_type'])->toBe('bug')
        ->and($normalization['suggested_title'])->toContain('login')
        ->and($normalization['confidence'])->toBeGreaterThan(0.5)
        ->and($normalization['clarifying_questions'])->toBeArray()
        ->and($normalization['requires_root_cause'])->toBeFalse();
});

it('normalizes feature-like raw text with deterministic fallback', function () {
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'Add a dark mode toggle to the dashboard settings page.',
        ])
        ->assertOk()
        ->assertJsonPath('normalization.task_type', 'feature')
        ->assertJsonPath('normalization.execution_mode', 'deterministic_fallback');

    $normalization = $response->json('normalization');

    expect($normalization['task_type'])->toBe('feature')
        ->and($normalization['suggested_title'])->toContain('dark mode');
});

it('normalizes vague input and returns clarifying questions', function () {
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'Fix it',
        ])
        ->assertOk()
        ->assertJsonPath('normalization.execution_mode', 'deterministic_fallback');

    $normalization = $response->json('normalization');

    expect($normalization['confidence'])->toBeLessThan(0.6)
        ->and($normalization['clarifying_questions'])->not->toBeEmpty();
});

it('returns bug type when text contains root cause or diagnose keywords', function () {
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'We need to diagnose the intermittent payment failure. Root cause unknown.',
        ])
        ->assertOk()
        ->assertJsonPath('normalization.task_type', 'bug')
        ->assertJsonPath('normalization.requires_root_cause', true);
});

it('returns question type for text ending with question mark', function () {
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'How does the artifact retention policy work?',
        ])
        ->assertOk()
        ->assertJsonPath('normalization.task_type', 'question');
});

it('validates raw_text minimum length', function () {
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'ab',
        ])
        ->assertUnprocessable();
});

it('validates raw_text maximum length', function () {
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => Str::random(5001),
        ])
        ->assertUnprocessable();
});

it('keeps intake normalization behind PM, Admin, and Developer dashboard roles', function () {
    $sysadmin = intakeUserWithRole('Sysadmin');
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($sysadmin)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'Add a dashboard export feature for PDF reports.',
        ])
        ->assertForbidden();

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'Add a dashboard export feature for PDF reports.',
        ])
        ->assertOk();
});

it('rejects unauthenticated requests', function () {
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
        'raw_text' => 'Something is broken.',
    ])->assertUnauthorized();
});

it('uses the Laravel AI SDK intake normalizer agent when faked', function () {
    IntakeNormalizerAgent::fake([[
        'task_type' => 'bug',
        'suggested_title' => 'Payment gateway returns 502 on retry',
        'suggested_description' => 'The payment gateway integration returns a 502 Bad Gateway error when the user retries a failed payment. This happens on the checkout page after the first attempt times out.',
        'clarifying_questions' => [
            'Which payment gateway provider is configured?',
            'Does the retry happen automatically or via user button click?',
        ],
        'confidence' => 0.88,
    ]])->preventStrayPrompts();

    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'Payment gateway returns 502 when retrying a failed payment on checkout.',
        ])
        ->assertOk()
        ->assertJsonPath('normalization.task_type', 'bug')
        ->assertJsonPath('normalization.suggested_title', 'Payment gateway returns 502 on retry')
        ->assertJsonPath('normalization.confidence', 0.88)
        ->assertJsonPath('normalization.execution_mode', 'laravel_ai_sdk_fake')
        ->assertJsonPath('normalization.clarifying_questions.0', 'Which payment gateway provider is configured?');

    IntakeNormalizerAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('502')
        && $prompt->contains('retrying')
        && $prompt->model === 'gpt-5.4');
});

it('redacts secrets from the LLM prompt using HadesEvidencePolicy when agent is faked', function () {
    IntakeNormalizerAgent::fake([[
        'task_type' => 'bug',
        'suggested_title' => 'API key rotation needed',
        'suggested_description' => 'The Stripe API key needs rotation after the security audit.',
        'clarifying_questions' => [],
        'confidence' => 0.90,
    ]])->preventStrayPrompts();

    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => <<<TEXT
The Stripe integration stopped working. The dev key sk-live-test-AbCdEfGhIjKlMnOpQrStUvWxYz leaked in the commit.
Error: "secret: xyz-token-abcdefghijklmnop"
Bearer token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozwZx8Jm7vs9fHk
Please rotate urgently.
TEXT,
        ])
        ->assertOk();

    IntakeNormalizerAgent::assertPrompted(function ($prompt): bool {
        // Must NOT contain the raw leaked API key token pattern.
        expect($prompt->contains('sk-live-test-AbCdEfGhIjKlMnOpQrStUvWxYz'))->toBeFalse();

        // Must NOT contain the raw Bearer token.
        expect($prompt->contains('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'))->toBeFalse();

        // Must NOT contain the raw colon-separated secret.
        expect($prompt->contains('xyz-token-abcdefghijklmnop'))->toBeFalse();

        // Must contain redaction markers instead.
        expect($prompt->contains('[redacted]'))->toBeTrue();

        return true;
    });
});

it('falls back to deterministic normalization when the SDK agent fails', function () {
    // Do not fake the agent; with no enabled provider the service falls back to keyword detection.
    $pm = intakeUserWithRole('PM');
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $response = $this->actingAs($pm)
        ->postJson("/api/dashboard/projects/{$projectId}/intake/normalize", [
            'raw_text' => 'The search feature crashes with an exception when filtering by date range.',
        ])
        ->assertOk()
        ->assertJsonPath('normalization.task_type', 'bug')
        ->assertJsonPath('normalization.execution_mode', 'deterministic_fallback');
});

function intakeUserWithRole(string $roleName): User
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
