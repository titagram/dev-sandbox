<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    public function record(string $action, string $targetType, ?string $targetId, array $payload = [], array $actor = []): void
    {
        $now = now();

        $prevHash = null;
        if ($this->hasHashChainColumns()) {
            $lastRow = DB::table('audit_logs')->orderByDesc('created_at')->orderByDesc('id')->first();
            $prevHash = $lastRow->row_hash ?? null;
        }

        $canonical = json_encode([
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload,
            'actor_type' => $actor['type'] ?? 'system',
            'prev_hash' => $prevHash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $rowHash = hash('sha256', $canonical);

        $row = [
            'id' => (string) Str::ulid(),
            'actor_user_id' => $actor['user_id'] ?? null,
            'actor_device_id' => $actor['device_id'] ?? null,
            'actor_type' => $actor['type'] ?? 'system',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => $actor['ip_address'] ?? null,
            'user_agent' => $actor['user_agent'] ?? null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ];

        if ($this->hasHashChainColumns()) {
            $row['prev_hash'] = $prevHash;
            $row['row_hash'] = $rowHash;
        }

        DB::table('audit_logs')->insert($row);
    }

    private function hasHashChainColumns(): bool
    {
        static $cache;

        if ($cache !== null) {
            return $cache;
        }

        $cache = \Illuminate\Support\Facades\Schema::hasColumns('audit_logs', ['prev_hash', 'row_hash']);

        return $cache;
    }
}
