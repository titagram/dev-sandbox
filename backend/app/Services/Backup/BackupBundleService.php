<?php

namespace App\Services\Backup;

use App\Models\User;
use App\Services\AuditChainVerifier;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class BackupBundleService
{
    public function __construct(
        private readonly BackupManifestService $manifests,
        private readonly AuditChainVerifier $auditChainVerifier,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        return $this->manifests->readiness();
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $actor): array
    {
        $backupId = (string) Str::ulid();
        $tables = $this->manifests->databaseSnapshot();
        $storageFiles = $this->manifests->storageSnapshot();
        $manifest = $this->manifests->manifest($backupId, $actor, $tables, $storageFiles);
        $requirements = $this->manifests->restoreRequirements();
        $checksums = $this->manifests->checksums($tables, $storageFiles);

        $bundle = [
            'format' => BackupManifestService::FORMAT,
            'manifest' => $manifest,
            'checksums' => $checksums,
            'database' => ['tables' => $tables],
            'storage' => ['files' => $storageFiles],
            'restore_requirements' => $requirements,
            'audit_summary' => $this->auditSummary(),
        ];

        $content = json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $path = "devboard/backups/devboard-backup-{$backupId}.json";
        Storage::disk('local')->put($path, $content);

        $result = [
            'id' => $backupId,
            'format' => BackupManifestService::FORMAT,
            'filename' => basename($path),
            'path' => $path,
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'download_url' => "/api/dashboard/system/backups/{$backupId}/download",
            'manifest' => $manifest,
            'restore_requirements' => $requirements,
        ];

        $this->audit(
            actor: $actor,
            action: 'backup.exported',
            targetType: 'backup',
            targetId: $backupId,
            payload: [
                'path' => $path,
                'sha256' => $result['sha256'],
                'size_bytes' => $result['size_bytes'],
                'counts' => $manifest['counts'],
            ],
        );

        return $result;
    }

    /**
     * @return array{path: string, filename: string, content: string, sha256: string}
     */
    public function storedBundle(string $backupId): array
    {
        abort_unless((bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $backupId), 404);

        $path = "devboard/backups/devboard-backup-{$backupId}.json";
        abort_unless(Storage::disk('local')->exists($path), 404);

        $content = Storage::disk('local')->get($path);

        return [
            'path' => $path,
            'filename' => basename($path),
            'content' => $content,
            'sha256' => hash('sha256', $content),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateDryRun(string $content, User $actor): array
    {
        $report = $this->baseReport();

        try {
            $bundle = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->finishReport($report, [
                'code' => 'invalid_json',
                'message' => 'The uploaded backup bundle is not valid JSON.',
            ], $actor);
        }

        if (! is_array($bundle)) {
            return $this->finishReport($report, [
                'code' => 'invalid_bundle',
                'message' => 'The uploaded backup bundle must be a JSON object.',
            ], $actor);
        }

        $manifest = $bundle['manifest'] ?? [];
        $report['manifest'] = is_array($manifest) ? $this->manifestSummary($manifest) : null;

        if (($bundle['format'] ?? null) !== BackupManifestService::FORMAT) {
            $report = $this->addBlocker($report, 'invalid_format', 'Backup format is not devboard-backup-v1.');
        } else {
            $report['checks'][] = ['key' => 'format', 'label' => 'Bundle format', 'status' => 'ok', 'detail' => BackupManifestService::FORMAT];
        }

        if (($manifest['compatibility_version'] ?? null) !== BackupManifestService::COMPATIBILITY_VERSION) {
            $report = $this->addBlocker($report, 'incompatible_version', 'Backup compatibility version is not supported by this DevBoard build.');
        } else {
            $report['checks'][] = ['key' => 'compatibility', 'label' => 'Compatibility version', 'status' => 'ok', 'detail' => 'Version 1'];
        }

        $report = $this->validateRequiredSecrets($bundle, $report);
        $report = $this->validateDatabaseChecksums($bundle, $report);
        $report = $this->validateAuditChain($bundle, $report);
        $report = $this->validateStorageFiles($bundle, $report);

        return $this->finishReport($report, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function validateAuditChain(array $bundle, array $report): array
    {
        $rows = $bundle['database']['tables']['audit_logs']['rows'] ?? null;
        if (! is_array($rows)) {
            return $this->addBlocker($report, 'missing_audit_logs', 'Audit log rows are missing from the database snapshot.');
        }

        $result = $this->auditChainVerifier->verifyRows($rows);
        if (! $result->valid) {
            $failure = $result->failures[0] ?? null;
            $message = $failure === null
                ? 'Audit chain verification failed for the database snapshot.'
                : "Audit chain verification failed at sequence {$failure->sequence}: {$failure->message}";

            return $this->addBlocker($report, 'audit_chain_invalid', $message);
        }

        $report['checks'][] = ['key' => 'audit_chain', 'label' => 'Audit hash chain', 'status' => 'ok', 'detail' => "{$result->lastSequence} audit row(s) verified"];

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReport(): array
    {
        return [
            'mode' => 'dry_run',
            'valid' => true,
            'can_restore' => true,
            'manifest' => null,
            'summary' => [
                'tables' => 0,
                'rows' => 0,
                'storage_files' => 0,
                'storage_bytes' => 0,
                'required_secrets' => 0,
            ],
            'checks' => [],
            'blockers' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{code: string, message: string}|null  $extraBlocker
     * @return array<string, mixed>
     */
    private function finishReport(array $report, ?array $extraBlocker, User $actor): array
    {
        if ($extraBlocker !== null) {
            $report = $this->addBlocker($report, $extraBlocker['code'], $extraBlocker['message']);
        }

        $report['valid'] = ! collect($report['blockers'])->contains(fn (array $blocker): bool => ($blocker['severity'] ?? 'error') === 'error');
        $report['can_restore'] = $report['valid'] && empty($report['blockers']);

        $this->audit(
            actor: $actor,
            action: 'backup.restore_dry_run',
            targetType: 'backup',
            targetId: is_array($report['manifest']) ? ($report['manifest']['backup_id'] ?? null) : null,
            payload: [
                'valid' => $report['valid'],
                'can_restore' => $report['can_restore'],
                'blockers' => $report['blockers'],
                'summary' => $report['summary'],
            ],
        );

        return $report;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function validateRequiredSecrets(array $bundle, array $report): array
    {
        $required = $bundle['restore_requirements']['required_secrets'] ?? [];
        if (! is_array($required)) {
            return $this->addBlocker($report, 'missing_restore_requirements', 'Restore requirements are missing from the bundle.');
        }

        $localSecrets = collect($this->manifests->requiredSecrets())->keyBy('name');
        $report['summary']['required_secrets'] = count($required);

        foreach ($required as $secret) {
            if (! is_array($secret) || ! ($secret['required'] ?? false)) {
                continue;
            }

            $name = (string) ($secret['name'] ?? '');
            $local = $localSecrets->get($name);

            if (! $local || ! ($local['present'] ?? false)) {
                $report = $this->addBlocker($report, 'missing_required_secret', "Required restore secret {$name} is not configured in this DevBoard runtime.");
            }
        }

        $report['checks'][] = ['key' => 'required_secrets', 'label' => 'Required secrets', 'status' => 'ok', 'detail' => (string) count($required).' requirement(s) inspected'];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function validateDatabaseChecksums(array $bundle, array $report): array
    {
        $tables = $bundle['database']['tables'] ?? null;
        $checksums = $bundle['checksums'] ?? [];

        if (! is_array($tables) || ! is_array($checksums)) {
            return $this->addBlocker($report, 'missing_database_snapshot', 'Database table snapshots or checksums are missing.');
        }

        foreach ($tables as $table => $snapshot) {
            if (! is_array($snapshot)) {
                $report = $this->addBlocker($report, 'invalid_table_snapshot', "Table {$table} snapshot is invalid.");

                continue;
            }

            $rows = $snapshot['rows'] ?? [];
            if (! is_array($rows)) {
                $report = $this->addBlocker($report, 'invalid_table_rows', "Table {$table} rows are invalid.");

                continue;
            }

            $expected = $checksums["database:{$table}"] ?? null;
            $actual = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($expected !== $actual || ($snapshot['sha256'] ?? null) !== $actual) {
                $report = $this->addBlocker($report, 'checksum_mismatch', "Database checksum mismatch for {$table}.");
            }

            $report['summary']['tables']++;
            $report['summary']['rows'] += count($rows);
        }

        $report['checks'][] = ['key' => 'database_checksums', 'label' => 'Database checksums', 'status' => empty($report['blockers']) ? 'ok' : 'error', 'detail' => (string) $report['summary']['tables'].' table(s) inspected'];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function validateStorageFiles(array $bundle, array $report): array
    {
        $files = $bundle['storage']['files'] ?? null;
        $checksums = $bundle['checksums'] ?? [];

        if (! is_array($files)) {
            return $this->addBlocker($report, 'missing_storage_snapshot', 'Storage file snapshot is missing.');
        }

        foreach ($files as $file) {
            if (! is_array($file)) {
                $report = $this->addBlocker($report, 'invalid_storage_file', 'Storage file entry is invalid.');

                continue;
            }

            $path = (string) ($file['path'] ?? '');
            if (! $this->isSafeStoragePath($path)) {
                $report = $this->addBlocker($report, 'unsafe_storage_path', "Storage path {$path} is not safe to restore.");

                continue;
            }

            $contents = base64_decode((string) ($file['content_base64'] ?? ''), true);
            if ($contents === false) {
                $report = $this->addBlocker($report, 'invalid_storage_encoding', "Storage file {$path} is not valid base64.");

                continue;
            }

            $actual = hash('sha256', $contents);
            $expected = $checksums["storage:{$path}"] ?? null;

            if ($expected !== $actual || ($file['sha256'] ?? null) !== $actual) {
                $report = $this->addBlocker($report, 'checksum_mismatch', "Storage checksum mismatch for {$path}.");
            }

            if ((int) ($file['size_bytes'] ?? -1) !== strlen($contents)) {
                $report = $this->addBlocker($report, 'size_mismatch', "Storage size mismatch for {$path}.");
            }

            $report['summary']['storage_files']++;
            $report['summary']['storage_bytes'] += strlen($contents);
        }

        $report['checks'][] = ['key' => 'storage_checksums', 'label' => 'Storage checksums', 'status' => empty($report['blockers']) ? 'ok' : 'error', 'detail' => (string) $report['summary']['storage_files'].' file(s) inspected'];

        return $report;
    }

    private function isSafeStoragePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }

        foreach (['devboard/artifacts/', 'devboard/task-attachments/', 'devboard/audit-exports/', 'devboard/quality/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function addBlocker(array $report, string $code, string $message): array
    {
        $report['blockers'][] = [
            'code' => $code,
            'severity' => 'error',
            'message' => $message,
        ];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function manifestSummary(array $manifest): array
    {
        return [
            'backup_id' => $manifest['backup_id'] ?? null,
            'created_at' => $manifest['created_at'] ?? null,
            'compatibility_version' => $manifest['compatibility_version'] ?? null,
            'source_host_label' => $manifest['source_host_label'] ?? null,
            'counts' => $manifest['counts'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSummary(): array
    {
        return [
            'audit_log_rows' => SchemaCompat::hasTable('audit_logs') ? DB::table('audit_logs')->count() : 0,
            'last_audit_at' => SchemaCompat::hasTable('audit_logs') ? DB::table('audit_logs')->max('created_at') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, string $action, string $targetType, ?string $targetId, array $payload): void
    {
        app(AuditLogger::class)->record($action, $targetType, $targetId, $payload, [
            'type' => 'dashboard',
            'user_id' => $actor->id,
        ]);
    }
}

final class SchemaCompat
{
    public static function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (RuntimeException) {
            return false;
        }
    }
}
