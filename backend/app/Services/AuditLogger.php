<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private readonly AuditCanonicalizer $canonicalizer) {}

    public function record(string $action, string $targetType, ?string $targetId, array $payload = [], array $actor = []): void
    {
        $this->recordMany([[
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload,
            'actor' => $actor,
        ]]);
    }

    /**
     * @param  list<array{action: string, target_type: string, target_id?: string|null, payload?: array<string, mixed>, actor?: array<string, mixed>}>  $events
     */
    public function recordMany(array $events): void
    {
        if ($events === []) {
            return;
        }

        DB::transaction(function () use ($events): void {
            $head = DB::table('audit_chain_heads')
                ->where('chain_key', 'global')
                ->lockForUpdate()
                ->first();

            if ($head === null) {
                throw new \RuntimeException('Missing global audit chain head.');
            }

            $sequence = (int) $head->last_sequence;
            $previousHash = $head->last_hash;

            foreach ($events as $event) {
                $sequence++;
                $actor = $event['actor'] ?? [];
                $createdAt = now()->format('Y-m-d H:i:s');
                $row = [
                    'id' => (string) Str::ulid(),
                    'sequence' => $sequence,
                    'chain_version' => 1,
                    'actor_user_id' => $actor['user_id'] ?? null,
                    'actor_user_ref' => isset($actor['user_id']) ? 'user:'.$actor['user_id'] : null,
                    'actor_device_id' => $actor['device_id'] ?? null,
                    'actor_device_ref' => isset($actor['device_id']) ? 'device:'.$actor['device_id'] : null,
                    'actor_type' => $actor['type'] ?? 'system',
                    'action' => $event['action'],
                    'target_type' => $event['target_type'],
                    'target_id' => $event['target_id'] ?? null,
                    'ip_address' => $actor['ip_address'] ?? null,
                    'user_agent' => $actor['user_agent'] ?? null,
                    'payload' => json_encode($event['payload'] ?? [], JSON_THROW_ON_ERROR),
                    'prev_hash' => $previousHash,
                    'created_at' => $createdAt,
                ];

                $row['row_hash'] = $this->canonicalizer->hash($row);

                DB::table('audit_logs')->insert($row);

                $previousHash = $row['row_hash'];
            }

            DB::table('audit_chain_heads')->where('chain_key', 'global')->update([
                'last_sequence' => $sequence,
                'last_hash' => $previousHash,
                'updated_at' => now(),
            ]);
        });
    }
}
