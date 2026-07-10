<?php

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

it('exposes backup readiness only to system operators', function () {
    $admin = backupUserWithRole('Admin');
    $sysadmin = backupUserWithRole('Sysadmin');
    $pm = backupUserWithRole('PM');

    $this->actingAs($pm)
        ->getJson('/api/dashboard/system/backups/readiness')
        ->assertForbidden();

    $this->actingAs($admin)
        ->getJson('/api/dashboard/system/backups/readiness')
        ->assertOk()
        ->assertJsonPath('format', 'devboard-backup-v1')
        ->assertJsonPath('can_export', true)
        ->assertJsonPath('components.0.key', 'database')
        ->assertJsonPath('secret_policy.includes_plaintext_secrets', false);

    $this->actingAs($sysadmin)
        ->getJson('/api/dashboard/system/backups/readiness')
        ->assertOk()
        ->assertJsonPath('can_export', true);
});

it('exports a portable DevBoard backup bundle without raw secrets', function () {
    $rawKey = str_repeat('k', 32);
    config(['app.key' => 'base64:'.base64_encode($rawKey)]);

    $admin = backupUserWithRole('Admin');
    $fixture = backupAttachmentFixture();

    $response = $this->actingAs($admin)
        ->postJson('/api/dashboard/system/backups/export')
        ->assertCreated()
        ->assertJsonPath('format', 'devboard-backup-v1')
        ->assertJsonPath('manifest.compatibility_version', 1)
        ->assertJsonPath('manifest.actor.user_id', (string) $admin->id)
        ->assertJsonPath('restore_requirements.required_secrets.0.name', 'APP_KEY')
        ->assertJsonStructure([
            'id',
            'filename',
            'path',
            'size_bytes',
            'sha256',
            'download_url',
            'manifest' => ['backup_id', 'created_at', 'counts', 'components'],
            'restore_requirements',
        ]);

    $path = (string) $response->json('path');
    Storage::disk('local')->assertExists($path);

    $content = Storage::disk('local')->get($path);
    $bundle = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

    expect($content)->not->toContain($rawKey)
        ->and($bundle['format'])->toBe('devboard-backup-v1')
        ->and($bundle['database']['tables']['projects']['rows'])->not->toBeEmpty()
        ->and($bundle['database']['tables']['task_attachments']['rows'][0]['id'])->toBe($fixture['attachment_id'])
        ->and($bundle['storage']['files'][0]['path'])->toBe($fixture['storage_path'])
        ->and(base64_decode($bundle['storage']['files'][0]['content_base64'], true))->toBe($fixture['contents'])
        ->and($bundle['checksums']['storage:'.$fixture['storage_path']])->toBe(hash('sha256', $fixture['contents']));

    expect(DB::table('audit_logs')
        ->where('action', 'backup.exported')
        ->where('actor_user_id', $admin->id)
        ->exists())->toBeTrue();

    $this->actingAs($admin)
        ->get((string) $response->json('download_url'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('preserves canonical audit chain fields exactly in backups', function () {
    $admin = backupUserWithRole('Admin');
    app(AuditLogger::class)->record('audit.canonical_fixture', 'audit', 'fixture', [
        'secret_label' => 'placeholder-not-a-secret',
        'nested' => ['b' => 2, 'a' => 1],
    ], ['type' => 'system']);

    $export = $this->actingAs($admin)
        ->postJson('/api/dashboard/system/backups/export')
        ->assertCreated()
        ->json();

    $bundle = json_decode(Storage::disk('local')->get((string) $export['path']), true, 512, JSON_THROW_ON_ERROR);
    $snapshotRow = collect($bundle['database']['tables']['audit_logs']['rows'])
        ->firstWhere('action', 'audit.canonical_fixture');
    $databaseRow = (array) DB::table('audit_logs')->where('action', 'audit.canonical_fixture')->first();

    foreach (['sequence', 'chain_version', 'actor_user_ref', 'actor_device_ref', 'payload', 'created_at', 'prev_hash', 'row_hash'] as $field) {
        expect($snapshotRow[$field] ?? null)->toBe($databaseRow[$field]);
    }
});

it('verifies the backed up audit chain independently during restore dry-run', function () {
    $admin = backupUserWithRole('Admin');

    $export = $this->actingAs($admin)
        ->postJson('/api/dashboard/system/backups/export')
        ->assertCreated()
        ->json();

    $bundle = json_decode(Storage::disk('local')->get((string) $export['path']), true, 512, JSON_THROW_ON_ERROR);
    $bundle['database']['tables']['audit_logs']['rows'][0]['action'] = 'audit.tampered_in_backup';
    $rows = $bundle['database']['tables']['audit_logs']['rows'];
    $hash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $bundle['database']['tables']['audit_logs']['sha256'] = $hash;
    $bundle['checksums']['database:audit_logs'] = $hash;
    $content = json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this->actingAs($admin)
        ->post('/api/dashboard/system/backups/validate', [
            'bundle' => UploadedFile::fake()->createWithContent('tampered-audit-chain-backup.json', $content),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('can_restore', false)
        ->assertJsonPath('blockers.0.code', 'audit_chain_invalid');
});

it('validates a backup bundle with dry-run restore and reports checksum tampering without mutation', function () {
    $admin = backupUserWithRole('Admin');
    backupAttachmentFixture();
    $projectCount = DB::table('projects')->count();

    $export = $this->actingAs($admin)
        ->postJson('/api/dashboard/system/backups/export')
        ->assertCreated()
        ->json();

    $content = Storage::disk('local')->get((string) $export['path']);

    $this->actingAs($admin)
        ->post('/api/dashboard/system/backups/validate', [
            'bundle' => UploadedFile::fake()->createWithContent('devboard-backup.json', $content),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('mode', 'dry_run')
        ->assertJsonPath('valid', true)
        ->assertJsonPath('can_restore', true)
        ->assertJsonPath('summary.tables', 23)
        ->assertJsonPath('checks.0.status', 'ok');

    $tampered = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    $tampered['storage']['files'][0]['content_base64'] = base64_encode('changed bytes');
    $tamperedContent = json_encode($tampered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this->actingAs($admin)
        ->post('/api/dashboard/system/backups/validate', [
            'bundle' => UploadedFile::fake()->createWithContent('tampered-devboard-backup.json', $tamperedContent),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('can_restore', false)
        ->assertJsonPath('blockers.0.code', 'checksum_mismatch');

    expect(DB::table('projects')->count())->toBe($projectCount)
        ->and(DB::table('audit_logs')->where('action', 'backup.restore_dry_run')->count())->toBe(2);
});

it('rejects path traversal storage entries during restore dry-run', function () {
    $admin = backupUserWithRole('Admin');
    backupAttachmentFixture();

    $export = $this->actingAs($admin)
        ->postJson('/api/dashboard/system/backups/export')
        ->assertCreated()
        ->json();

    $bundle = json_decode(Storage::disk('local')->get((string) $export['path']), true, 512, JSON_THROW_ON_ERROR);
    $bundle['storage']['files'][0]['path'] = '../.env';
    $bundle['checksums']['storage:../.env'] = hash('sha256', base64_decode($bundle['storage']['files'][0]['content_base64'], true));
    $content = json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this->actingAs($admin)
        ->post('/api/dashboard/system/backups/validate', [
            'bundle' => UploadedFile::fake()->createWithContent('unsafe-devboard-backup.json', $content),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('can_restore', false)
        ->assertJsonPath('blockers.0.code', 'unsafe_storage_path');
});

function backupUserWithRole(string $roleName): User
{
    $user = User::factory()->create(['status' => 'active']);
    $roleId = DB::table('roles')->where('name', $roleName)->value('id');

    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * @return array{project_id: string, task_id: string, attachment_id: string, storage_path: string, contents: string}
 */
function backupAttachmentFixture(): array
{
    $projectId = (string) DB::table('projects')->where('slug', 'demo-project')->value('id');
    $userId = (int) DB::table('users')->where('email', 'admin@example.com')->value('id');
    $columnId = (string) DB::table('kanban_columns')->where('status_key', 'ready')->value('id');
    $taskId = (string) Str::ulid();
    $attachmentId = (string) Str::ulid();
    $contents = "backup attachment evidence\n";
    $storagePath = "devboard/task-attachments/{$projectId}/{$taskId}/{$attachmentId}/evidence.txt";
    $now = now();

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Backup attachment task',
        'description' => 'Task attachment must be included in backup bundles.',
        'status_column_id' => $columnId,
        'priority' => 'medium',
        'risk_level' => 'low',
        'owner_user_id' => $userId,
        'created_by_user_id' => $userId,
        'due_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Storage::disk('local')->put($storagePath, $contents);

    DB::table('task_attachments')->insert([
        'id' => $attachmentId,
        'project_id' => $projectId,
        'task_id' => $taskId,
        'uploaded_by_user_id' => $userId,
        'deleted_by_user_id' => null,
        'original_name' => 'evidence.txt',
        'stored_name' => 'evidence.txt',
        'storage_path' => $storagePath,
        'sha256' => hash('sha256', $contents),
        'size_bytes' => strlen($contents),
        'mime_type' => 'text/plain',
        'kind' => 'document',
        'status' => 'available',
        'scan_status' => 'not_scanned',
        'metadata' => json_encode(['source' => 'test'], JSON_THROW_ON_ERROR),
        'deleted_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'project_id' => $projectId,
        'task_id' => $taskId,
        'attachment_id' => $attachmentId,
        'storage_path' => $storagePath,
        'contents' => $contents,
    ];
}
