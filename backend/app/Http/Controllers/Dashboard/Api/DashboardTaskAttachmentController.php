<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Projects\ProjectLifecycleService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DashboardTaskAttachmentController extends Controller
{
    use ChecksDashboardRoles;

    private const MAX_ACTIVE_ATTACHMENTS = 25;

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_EXTENSIONS_BY_MIME = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt', 'log', 'md', 'markdown'],
        'text/markdown' => ['md', 'markdown'],
        'text/csv' => ['csv'],
        'application/json' => ['json'],
    ];

    public function store(
        Request $request,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        string $task,
    ): JsonResponse {
        $this->abortUnlessDashboardMutator($request);

        if ($error = $lifecycle->assertTaskProjectActive($task)) {
            return $error;
        }

        $taskRow = DB::table('tasks')->where('id', $task)->first();
        abort_unless($taskRow, 404);

        if (DB::table('task_attachments')->where('task_id', $task)->whereNull('deleted_at')->count() >= self::MAX_ACTIVE_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'file' => 'Task attachment limit reached.',
            ]);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $mime = $this->validatedMime($file);
        $kind = str_starts_with($mime, 'image/') ? 'image' : 'file';
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw ValidationException::withMessages([
                'file' => 'Unable to read attachment contents.',
            ]);
        }

        $attachmentId = (string) Str::ulid();
        $originalName = $this->displayName($file);
        $storedName = $this->storedName($originalName, $mime);
        $storagePath = "devboard/task-attachments/{$taskRow->project_id}/{$task}/{$attachmentId}/{$storedName}";
        $sha256 = hash('sha256', $contents);
        $now = now();

        DB::transaction(function () use ($request, $file, $task, $taskRow, $attachmentId, $originalName, $storedName, $storagePath, $sha256, $mime, $kind, $contents, $now): void {
            Storage::disk('local')->put($storagePath, $contents);

            DB::table('task_attachments')->insert([
                'id' => $attachmentId,
                'project_id' => $taskRow->project_id,
                'task_id' => $task,
                'uploaded_by_user_id' => $request->user()->id,
                'deleted_by_user_id' => null,
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'storage_path' => $storagePath,
                'sha256' => $sha256,
                'size_bytes' => $file->getSize(),
                'mime_type' => $mime,
                'kind' => $kind,
                'status' => 'available',
                'scan_status' => 'not_scanned',
                'metadata' => json_encode([
                    'client_original_extension' => strtolower((string) $file->getClientOriginalExtension()),
                    'scan_status' => 'not_scanned',
                ], JSON_THROW_ON_ERROR),
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            app(AuditLogger::class)->record('task_attachment.uploaded', 'task_attachment', $attachmentId, [
                'project_id' => (string) $taskRow->project_id,
                'task_id' => $task,
                'attachment_id' => $attachmentId,
                'name' => $originalName,
                'mime_type' => $mime,
                'size_bytes' => $file->getSize(),
                'sha256' => $sha256,
            ], [
                'type' => 'user',
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json($reader->taskAttachment($attachmentId), 201);
    }

    public function download(Request $request, string $task, string $attachment): StreamedResponse
    {
        $this->abortUnlessDashboardReader($request);

        $attachmentRow = DB::table('task_attachments')
            ->where('id', $attachment)
            ->where('task_id', $task)
            ->whereNull('deleted_at')
            ->first();
        abort_unless($attachmentRow, 404);

        abort_unless(
            DB::table('projects')
                ->where('id', $attachmentRow->project_id)
                ->where('status', '!=', ProjectLifecycleService::DELETED)
                ->exists(),
            404,
        );
        abort_unless(Storage::disk('local')->exists((string) $attachmentRow->storage_path), 404);

        $contents = Storage::disk('local')->get((string) $attachmentRow->storage_path);

        app(AuditLogger::class)->record('task_attachment.downloaded', 'task_attachment', (string) $attachmentRow->id, [
            'project_id' => (string) $attachmentRow->project_id,
            'task_id' => (string) $attachmentRow->task_id,
            'attachment_id' => (string) $attachmentRow->id,
            'name' => (string) $attachmentRow->original_name,
            'mime_type' => (string) $attachmentRow->mime_type,
            'size_bytes' => (int) $attachmentRow->size_bytes,
            'sha256' => (string) $attachmentRow->sha256,
        ], [
            'type' => 'user',
            'user_id' => $request->user()->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $disposition = $attachmentRow->kind === 'image' ? 'inline' : 'attachment';
        $filename = addcslashes((string) $attachmentRow->original_name, '"\\');

        return Response::stream(
            static function () use ($contents): void {
                echo $contents;
            },
            200,
            [
                'Content-Type' => $this->contentType((string) $attachmentRow->mime_type),
                'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                'Content-Length' => (string) strlen($contents),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function destroy(
        Request $request,
        DashboardApiReader $reader,
        ProjectLifecycleService $lifecycle,
        string $task,
        string $attachment,
    ): JsonResponse {
        $this->abortUnlessDashboardMutator($request);

        if ($error = $lifecycle->assertTaskProjectActive($task)) {
            return $error;
        }

        $now = now();

        DB::transaction(function () use ($request, $task, $attachment, $now): void {
            $attachmentRow = DB::table('task_attachments')
                ->where('id', $attachment)
                ->where('task_id', $task)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            abort_unless($attachmentRow, 404);

            DB::table('task_attachments')
                ->where('id', $attachmentRow->id)
                ->update([
                    'status' => 'deleted',
                    'deleted_at' => $now,
                    'deleted_by_user_id' => $request->user()->id,
                    'updated_at' => $now,
                ]);

            DB::table('tasks')
                ->where('id', $task)
                ->update(['updated_at' => $now]);

            app(AuditLogger::class)->record('task_attachment.deleted', 'task_attachment', (string) $attachmentRow->id, [
                'project_id' => (string) $attachmentRow->project_id,
                'task_id' => (string) $attachmentRow->task_id,
                'attachment_id' => (string) $attachmentRow->id,
                'name' => (string) $attachmentRow->original_name,
                'mime_type' => (string) $attachmentRow->mime_type,
                'size_bytes' => (int) $attachmentRow->size_bytes,
                'sha256' => (string) $attachmentRow->sha256,
            ], [
                'type' => 'user',
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json($reader->task($task));
    }

    private function abortUnlessDashboardReader(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Sysadmin')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }

    private function abortUnlessDashboardMutator(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'PM')
            || $this->userHasRole($request->user(), 'Developer')
            || $this->userHasRole($request->user(), 'Admin'),
            403,
        );
    }

    private function validatedMime(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! isset(self::ALLOWED_EXTENSIONS_BY_MIME[$mime]) || ! in_array($extension, self::ALLOWED_EXTENSIONS_BY_MIME[$mime], true)) {
            throw ValidationException::withMessages([
                'file' => 'Unsupported attachment type.',
            ]);
        }

        return $mime;
    }

    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace(["\0", "\r", "\n"], '', $file->getClientOriginalName()));
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'attachment';
        }

        return Str::limit($name, 240, '');
    }

    private function storedName(string $displayName, string $mime): string
    {
        $base = pathinfo($displayName, PATHINFO_FILENAME);
        $base = Str::slug($base) ?: 'attachment';
        $extension = self::ALLOWED_EXTENSIONS_BY_MIME[$mime][0] ?? 'bin';

        return "{$base}.{$extension}";
    }

    private function contentType(string $mime): string
    {
        if (str_starts_with($mime, 'text/') || $mime === 'application/json') {
            return "{$mime}; charset=UTF-8";
        }

        return $mime !== '' ? $mime : 'application/octet-stream';
    }
}
