<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditExportService
{
    /**
     * @param array{action?: string|null, actor_type?: string|null, from?: string|null, to?: string|null} $filters
     * @return array{format: string, path: string, exported: int, row_count: int, sha256: string}
     */
    public function export(array $filters = [], string $formatOrPath = 'jsonl', ?string $path = null): array
    {
        $format = $formatOrPath;

        if ($path === null && ! in_array($formatOrPath, ['jsonl', 'csv'], true)) {
            $format = 'jsonl';
            $path = $formatOrPath;
        }

        if (! in_array($format, ['jsonl', 'csv'], true)) {
            throw new InvalidArgumentException('Audit export format must be jsonl or csv.');
        }

        $rows = $this->auditRows($filters);
        $path ??= 'devboard/audit-exports/audit-'.now()->format('YmdHis').'-'.Str::ulid().'.'.$format;
        $content = $format === 'csv' ? $this->csv($rows) : $this->jsonl($rows);
        $hash = hash('sha256', $content);

        Storage::disk('local')->put($path, $content);
        $this->recordAuditExport($path, $rows->count(), $hash, $filters);

        return [
            'format' => $format,
            'path' => $path,
            'exported' => $rows->count(),
            'row_count' => $rows->count(),
            'sha256' => $hash,
        ];
    }

    /**
     * @param Collection<int, object> $rows
     */
    private function csv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to allocate audit export CSV buffer.');
        }

        fputcsv($handle, ['id', 'actor_type', 'action', 'target_type', 'target_id', 'created_at', 'payload']);

        foreach ($rows as $row) {
            $exportRow = $this->exportRow($row);
            fputcsv($handle, [
                $exportRow['id'],
                $exportRow['actor_type'],
                $exportRow['action'],
                $exportRow['target_type'],
                $exportRow['target_id'],
                $exportRow['created_at'],
                json_encode($exportRow['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false) {
            throw new \RuntimeException('Unable to read audit export CSV buffer.');
        }

        return $content;
    }

    /**
     * @param array{action?: string|null, actor_type?: string|null, from?: string|null, to?: string|null} $filters
     * @return Collection<int, object>
     */
    private function auditRows(array $filters): Collection
    {
        return DB::table('audit_logs')
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($filters['actor_type'] ?? null, fn ($query, string $actorType) => $query->where('actor_type', $actorType))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', $to))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int, object> $rows
     */
    private function jsonl(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return '';
        }

        return $rows
            ->map(fn (object $row): string => json_encode($this->exportRow($row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->implode("\n")."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function exportRow(object $row): array
    {
        return [
            'id' => $row->id,
            'actor_user_id' => $row->actor_user_id,
            'actor_device_id' => $row->actor_device_id,
            'actor_type' => $row->actor_type,
            'action' => $row->action,
            'target_type' => $row->target_type,
            'target_id' => $row->target_id,
            'ip_address' => $row->ip_address,
            'user_agent' => $row->user_agent,
            'payload' => $this->sanitize(json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)),
            'created_at' => $row->created_at,
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/authorization|credential|password|private_key|secret|token|api_key/i', $key)) {
                    $sanitized[$key] = '[REDACTED]';

                    continue;
                }

                $sanitized[$key] = $this->sanitize($item);
            }

            return $sanitized;
        }

        if (is_string($value) && preg_match('/devb_live_[^\s|]+\|[^\s]+/', $value)) {
            return '[REDACTED]';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function recordAuditExport(string $path, int $rowCount, string $hash, array $filters): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => null,
            'actor_device_id' => null,
            'actor_type' => 'system',
            'action' => 'audit.exported',
            'target_type' => 'audit_export',
            'target_id' => null,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => json_encode([
                'path' => $path,
                'row_count' => $rowCount,
                'sha256' => $hash,
                'filters' => $filters,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
