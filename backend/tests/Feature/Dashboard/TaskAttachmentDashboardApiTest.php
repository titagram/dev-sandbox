<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
    $this->withSession(['_token' => 'test-token']);
});

it('uploads task attachments through the dashboard API and exposes them on task detail', function () {
    $pm = taskAttachmentUserWithRole('PM');
    $projectId = taskAttachmentCreateProject('Attachment Project', 'attachment-project');
    $taskId = taskAttachmentTask($projectId);
    $artifactCount = DB::table('artifacts')->count();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);

    $response = $this->actingAs($pm)
        ->post("/api/dashboard/tasks/{$taskId}/attachments", [
            '_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('screen.png', $png)->mimeType('image/png'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('task_id', $taskId)
        ->assertJsonPath('project_id', $projectId)
        ->assertJsonPath('name', 'screen.png')
        ->assertJsonPath('kind', 'image')
        ->assertJsonPath('mime_type', 'image/png')
        ->assertJsonPath('status', 'available')
        ->assertJsonPath('scan_status', 'not_scanned');

    $attachmentId = (string) $response->json('id');
    $row = DB::table('task_attachments')->where('id', $attachmentId)->first();

    expect($row)->not->toBeNull()
        ->and((string) $response->json('download_url'))->toBe("/api/dashboard/tasks/{$taskId}/attachments/{$attachmentId}/download")
        ->and((string) $row->storage_path)->toStartWith("devboard/task-attachments/{$projectId}/{$taskId}/{$attachmentId}/")
        ->and((string) $row->sha256)->toBe(hash('sha256', $png))
        ->and(DB::table('artifacts')->count())->toBe($artifactCount)
        ->and(DB::table('audit_logs')->where('action', 'task_attachment.uploaded')->where('target_id', $attachmentId)->exists())->toBeTrue();

    Storage::disk('local')->assertExists((string) $row->storage_path);

    $this->actingAs($pm)
        ->getJson("/api/dashboard/tasks/{$taskId}")
        ->assertOk()
        ->assertJsonPath('attachment_count', 1)
        ->assertJsonPath('image_attachment_count', 1)
        ->assertJsonPath('attachments.0.id', $attachmentId)
        ->assertJsonPath('attachments.0.preview_url', "/api/dashboard/tasks/{$taskId}/attachments/{$attachmentId}/download");
});

it('downloads authenticated task attachment bytes with safe headers', function () {
    $developer = taskAttachmentUserWithRole('Developer');
    $projectId = taskAttachmentCreateProject('Download Project', 'download-project');
    $taskId = taskAttachmentTask($projectId);
    $payload = "notes for the task\n";

    $attachment = $this->actingAs($developer)
        ->post("/api/dashboard/tasks/{$taskId}/attachments", [
            '_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('notes.txt', $payload)->mimeType('text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json();

    $response = $this->actingAs($developer)
        ->get("/api/dashboard/tasks/{$taskId}/attachments/{$attachment['id']}/download");

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->streamedContent())->toBe($payload)
        ->and(DB::table('audit_logs')->where('action', 'task_attachment.downloaded')->where('target_id', $attachment['id'])->exists())->toBeTrue();
});

it('soft-deletes task attachments while preserving private storage bytes', function () {
    $pm = taskAttachmentUserWithRole('PM');
    $sysadmin = taskAttachmentUserWithRole('Sysadmin');
    $projectId = taskAttachmentCreateProject('Delete Attachments', 'delete-attachments');
    $taskId = taskAttachmentTask($projectId);
    $payload = "delete me softly\n";

    $attachment = $this->actingAs($pm)
        ->post("/api/dashboard/tasks/{$taskId}/attachments", [
            '_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('delete-me.txt', $payload)->mimeType('text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json();
    $storagePath = (string) DB::table('task_attachments')->where('id', $attachment['id'])->value('storage_path');

    $this->actingAs($sysadmin)
        ->delete("/api/dashboard/tasks/{$taskId}/attachments/{$attachment['id']}", [
            '_token' => 'test-token',
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($pm)
        ->delete("/api/dashboard/tasks/{$taskId}/attachments/{$attachment['id']}", [
            '_token' => 'test-token',
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('attachment_count', 0)
        ->assertJsonPath('image_attachment_count', 0)
        ->assertJsonPath('attachments', []);

    $row = DB::table('task_attachments')->where('id', $attachment['id'])->first();

    expect($row->status)->toBe('deleted')
        ->and($row->deleted_at)->not->toBeNull()
        ->and($row->deleted_by_user_id)->toBe($pm->id)
        ->and(DB::table('audit_logs')->where('action', 'task_attachment.deleted')->where('target_id', $attachment['id'])->exists())->toBeTrue();
    Storage::disk('local')->assertExists($storagePath);

    $this->actingAs($pm)
        ->delete("/api/dashboard/tasks/{$taskId}/attachments/{$attachment['id']}", [
            '_token' => 'test-token',
        ], ['Accept' => 'application/json'])
        ->assertNotFound();
    expect(DB::table('audit_logs')->where('action', 'task_attachment.deleted')->where('target_id', $attachment['id'])->count())->toBe(1);

    $this->actingAs($pm)
        ->getJson("/api/dashboard/tasks/{$taskId}")
        ->assertOk()
        ->assertJsonPath('attachment_count', 0)
        ->assertJsonPath('attachments', []);

    $this->actingAs($pm)
        ->get("/api/dashboard/tasks/{$taskId}/attachments/{$attachment['id']}/download")
        ->assertNotFound();
});

it('blocks attachment soft-delete for archived projects but keeps reads available', function () {
    $pm = taskAttachmentUserWithRole('PM');
    $projectId = taskAttachmentCreateProject('Archived Delete Attachments', 'archived-delete-attachments');
    $taskId = taskAttachmentTask($projectId);

    $attachment = $this->actingAs($pm)
        ->post("/api/dashboard/tasks/{$taskId}/attachments", [
            '_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('keep.txt', 'keep')->mimeType('text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json();

    DB::table('projects')->where('id', $projectId)->update(['status' => 'archived', 'archived_at' => now()]);

    $this->actingAs($pm)
        ->delete("/api/dashboard/tasks/{$taskId}/attachments/{$attachment['id']}", [
            '_token' => 'test-token',
        ], ['Accept' => 'application/json'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    expect(DB::table('task_attachments')->where('id', $attachment['id'])->value('deleted_at'))->toBeNull();

    $this->actingAs($pm)
        ->getJson("/api/dashboard/tasks/{$taskId}")
        ->assertOk()
        ->assertJsonPath('attachments.0.id', $attachment['id']);

    $this->actingAs($pm)
        ->get("/api/dashboard/tasks/{$taskId}/attachments/{$attachment['id']}/download")
        ->assertOk();
});

it('blocks upload for read-only roles and non-active projects while keeping archived attachments readable', function () {
    $pm = taskAttachmentUserWithRole('PM');
    $sysadmin = taskAttachmentUserWithRole('Sysadmin');
    $projectId = taskAttachmentCreateProject('Lifecycle Attachments', 'lifecycle-attachments');
    $taskId = taskAttachmentTask($projectId);

    $attachment = $this->actingAs($pm)
        ->post("/api/dashboard/tasks/{$taskId}/attachments", [
            '_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('readme.md', '# Evidence')->mimeType('text/markdown'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json();

    $this->actingAs($sysadmin)
        ->post("/api/dashboard/tasks/{$taskId}/attachments", [
            '_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('sysadmin.txt', 'read only')->mimeType('text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    DB::table('projects')->where('id', $projectId)->update(['status' => 'archived', 'archived_at' => now()]);

    $this->actingAs($pm)
        ->post("/api/dashboard/tasks/{$taskId}/attachments", [
            '_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('blocked.txt', 'blocked')->mimeType('text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project_not_active');

    $this->actingAs($sysadmin)
        ->getJson("/api/dashboard/tasks/{$taskId}")
        ->assertOk()
        ->assertJsonPath('attachments.0.id', $attachment['id']);

    $this->actingAs($sysadmin)
        ->get("/api/dashboard/tasks/{$taskId}/attachments/{$attachment['id']}/download")
        ->assertOk();
});

it('rejects source bundles and keeps task attachment routes out of the plugin namespace', function () {
    $pm = taskAttachmentUserWithRole('PM');
    $projectId = taskAttachmentCreateProject('Rejected Attachments', 'rejected-attachments');
    $taskId = taskAttachmentTask($projectId);

    $this->actingAs($pm)
        ->post("/api/dashboard/tasks/{$taskId}/attachments", [
            '_token' => 'test-token',
            'file' => UploadedFile::fake()->createWithContent('repo.zip', 'PK source bundle')->mimeType('application/zip'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect(Schema::hasTable('task_attachments') ? DB::table('task_attachments')->count() : 0)->toBe(0);

    $this->actingAs($pm)
        ->post("/api/plugin/v1/tasks/{$taskId}/attachments", [], ['Accept' => 'application/json'])
        ->assertNotFound();
});

function taskAttachmentUserWithRole(string $roleName): User
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

function taskAttachmentCreateProject(string $name, string $slug, string $status = 'active'): string
{
    $projectId = (string) Str::ulid();
    $boardId = (string) Str::ulid();
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');
    $now = now();
    $columns = Schema::getColumnListing('projects');

    $row = [
        'id' => $projectId,
        'name' => $name,
        'slug' => $slug,
        'description' => "{$name} description.",
        'status' => $status,
        'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $adminId,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    foreach ([
        'archived_at',
        'archived_by_user_id',
        'deleted_at',
        'deleted_by_user_id',
        'restored_at',
        'restored_by_user_id',
    ] as $column) {
        if (in_array($column, $columns, true)) {
            $row[$column] = match ($column) {
                'archived_at' => $status === 'archived' ? $now : null,
                'archived_by_user_id' => $status === 'archived' ? $adminId : null,
                'deleted_at' => $status === 'deleted' ? $now : null,
                'deleted_by_user_id' => $status === 'deleted' ? $adminId : null,
                default => null,
            };
        }
    }

    DB::table('projects')->insert($row);
    DB::table('kanban_boards')->insert([
        'id' => $boardId,
        'project_id' => $projectId,
        'name' => 'Default Board',
        'is_default' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach (['backlog', 'ready', 'in_progress', 'blocked', 'review', 'done'] as $index => $statusKey) {
        DB::table('kanban_columns')->insert([
            'id' => (string) Str::ulid(),
            'board_id' => $boardId,
            'name' => Str::headline($statusKey),
            'position' => $index + 1,
            'status_key' => $statusKey,
            'wip_limit' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $projectId;
}

function taskAttachmentTask(string $projectId): string
{
    $taskId = (string) Str::ulid();
    $columnId = DB::table('kanban_columns')
        ->join('kanban_boards', 'kanban_boards.id', '=', 'kanban_columns.board_id')
        ->where('kanban_boards.project_id', $projectId)
        ->where('kanban_columns.status_key', 'ready')
        ->value('kanban_columns.id');
    $adminId = DB::table('users')->where('email', 'admin@example.com')->value('id');

    DB::table('tasks')->insert([
        'id' => $taskId,
        'project_id' => $projectId,
        'title' => 'Attachment task',
        'description' => 'Task for attachment API tests.',
        'status_column_id' => $columnId,
        'priority' => 'normal',
        'risk_level' => 'medium',
        'owner_user_id' => $adminId,
        'created_by_user_id' => $adminId,
        'due_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $taskId;
}
