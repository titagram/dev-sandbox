<?php

namespace App\Services\Backup;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class BackupManifestService
{
    public const FORMAT = 'devboard-backup-v1';
    public const COMPATIBILITY_VERSION = 1;

    /**
     * @var list<string>
     */
    private const TABLES = [
        'permissions',
        'roles',
        'role_user',
        'users',
        'projects',
        'repositories',
        'devices',
        'api_tokens',
        'local_workspaces',
        'kanban_boards',
        'kanban_columns',
        'tasks',
        'runs',
        'run_events',
        'artifacts',
        'chunks',
        'genesis_imports',
        'delta_syncs',
        'snapshots',
        'wiki_pages',
        'wiki_revisions',
        'audit_chain_heads',
        'audit_logs',
        'task_attachments',
    ];

    /**
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        $latest = collect(Storage::disk('local')->files('devboard/backups'))
            ->filter(fn (string $path): bool => str_ends_with($path, '.json'))
            ->sort()
            ->last();

        return [
            'format' => self::FORMAT,
            'compatibility_version' => self::COMPATIBILITY_VERSION,
            'can_export' => true,
            'can_restore_dry_run' => true,
            'components' => [
                [
                    'key' => 'database',
                    'label' => 'Database metadata',
                    'included' => true,
                    'detail' => 'DevBoard control-plane tables, excluding target source repositories.',
                ],
                [
                    'key' => 'storage',
                    'label' => 'DevBoard storage',
                    'included' => true,
                    'detail' => 'Artifacts, task attachments, audit exports, and quality reports stored by DevBoard.',
                ],
                [
                    'key' => 'secrets',
                    'label' => 'Secrets',
                    'included' => false,
                    'detail' => 'Plaintext secrets are never included; restore requirements record names and fingerprints only.',
                ],
            ],
            'secret_policy' => [
                'includes_plaintext_secrets' => false,
                'required_secrets' => $this->requiredSecrets(),
            ],
            'last_backup' => $latest ? $this->storedBackupSummary($latest) : null,
            'warnings' => [],
        ];
    }

    /**
     * @return array<string, array{rows: list<array<string, mixed>>, row_count: int, sha256: string}>
     */
    public function databaseSnapshot(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);

            if (in_array('id', $columns, true)) {
                $query->orderBy('id');
            } elseif (in_array('user_id', $columns, true)) {
                $query->orderBy('user_id');
            }

            $rows = $query
                ->get()
                ->map(fn (object $row): array => $this->normalizeRow($table, (array) $row))
                ->values()
                ->all();

            $tables[$table] = [
                'rows' => $rows,
                'row_count' => count($rows),
                'sha256' => $this->hashJson($rows),
            ];
        }

        return $tables;
    }

    /**
     * @return list<array{path: string, sha256: string, size_bytes: int, content_base64: string}>
     */
    public function storageSnapshot(): array
    {
        return collect(Storage::disk('local')->allFiles('devboard'))
            ->filter(fn (string $path): bool => $this->isIncludedStoragePath($path))
            ->sort()
            ->map(function (string $path): array {
                $contents = Storage::disk('local')->get($path);

                return [
                    'path' => $path,
                    'sha256' => hash('sha256', $contents),
                    'size_bytes' => strlen($contents),
                    'content_base64' => base64_encode($contents),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, array{rows: list<array<string, mixed>>, row_count: int, sha256: string}> $tables
     * @param list<array{path: string, sha256: string, size_bytes: int, content_base64: string}> $storageFiles
     * @return array<string, mixed>
     */
    public function manifest(string $backupId, User $actor, array $tables, array $storageFiles): array
    {
        $rowCount = array_sum(array_map(fn (array $table): int => $table['row_count'], $tables));
        $storageBytes = array_sum(array_map(fn (array $file): int => $file['size_bytes'], $storageFiles));

        return [
            'backup_id' => $backupId,
            'format' => self::FORMAT,
            'created_at' => now()->toIso8601String(),
            'compatibility_version' => self::COMPATIBILITY_VERSION,
            'devboard_version' => [
                'app_env' => (string) Config::get('app.env'),
                'schema_migrations' => $this->migrationState(),
            ],
            'actor' => [
                'user_id' => (string) $actor->id,
                'email' => (string) $actor->email,
            ],
            'source_host_label' => gethostname() ?: 'unknown-host',
            'components' => [
                'database' => ['tables' => count($tables), 'rows' => $rowCount],
                'storage' => ['files' => count($storageFiles), 'bytes' => $storageBytes],
                'secrets' => ['included' => false],
            ],
            'counts' => [
                'tables' => count($tables),
                'rows' => $rowCount,
                'storage_files' => count($storageFiles),
                'storage_bytes' => $storageBytes,
                'task_attachments' => $tables['task_attachments']['row_count'] ?? 0,
            ],
        ];
    }

    /**
     * @param array<string, array{sha256: string}> $tables
     * @param list<array{path: string, sha256: string}> $storageFiles
     * @return array<string, string>
     */
    public function checksums(array $tables, array $storageFiles): array
    {
        $checksums = [];

        foreach ($tables as $table => $snapshot) {
            $checksums["database:{$table}"] = $snapshot['sha256'];
        }

        foreach ($storageFiles as $file) {
            $checksums['storage:'.$file['path']] = $file['sha256'];
        }

        ksort($checksums);

        return $checksums;
    }

    /**
     * @return array{required_secrets: list<array<string, mixed>>, policy: array<string, mixed>}
     */
    public function restoreRequirements(): array
    {
        return [
            'required_secrets' => $this->requiredSecrets(),
            'policy' => [
                'plaintext_secrets_included' => false,
                'operator_must_provide' => ['APP_KEY', 'database credentials', 'Neo4j credentials', 'external storage credentials when configured'],
            ],
        ];
    }

    /**
     * @return list<array{name: string, required: bool, present: bool, fingerprint: string|null}>
     */
    public function requiredSecrets(): array
    {
        $db = (string) Config::get('database.default', 'unknown');

        return [
            $this->secret('APP_KEY', (string) Config::get('app.key'), true),
            $this->secret('DB_CONNECTION', $db, true),
            $this->secret('DB_DATABASE', (string) Config::get("database.connections.{$db}.database"), true),
            $this->secret('NEO4J_URI', (string) Config::get('services.neo4j.uri', ''), false),
        ];
    }

    /**
     * @return array{path: string, filename: string, size_bytes: int, sha256: string}
     */
    private function storedBackupSummary(string $path): array
    {
        $contents = Storage::disk('local')->get($path);

        return [
            'path' => $path,
            'filename' => basename($path),
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function isIncludedStoragePath(string $path): bool
    {
        if (str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, 'devboard/backups/')) {
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(string $table, array $row): array
    {
        if ($table !== 'audit_logs' && isset($row['payload']) && is_string($row['payload'])) {
            $decoded = json_decode($row['payload'], true);
            if (is_array($decoded)) {
                $row['payload'] = json_encode($this->sanitize($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }

        ksort($row);

        return $row;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) && preg_match('/devb_live_[^\s|]+\|[^\s]+/', $value) ? '[REDACTED]' : $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/authorization|credential|password|private_key|secret|token|api_key/i', $key)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $this->sanitize($item);
            }
        }

        return $sanitized;
    }

    /**
     * @return list<string>
     */
    private function migrationState(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')
            ->orderBy('batch')
            ->orderBy('migration')
            ->pluck('migration')
            ->map(fn (mixed $migration): string => (string) $migration)
            ->all();
    }

    /**
     * @param mixed $value
     */
    private function hashJson($value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{name: string, required: bool, present: bool, fingerprint: string|null}
     */
    private function secret(string $name, string $value, bool $required): array
    {
        $present = trim($value) !== '';

        return [
            'name' => $name,
            'required' => $required,
            'present' => $present,
            'fingerprint' => $present ? 'sha256:'.substr(hash('sha256', $value), 0, 16) : null,
        ];
    }
}
