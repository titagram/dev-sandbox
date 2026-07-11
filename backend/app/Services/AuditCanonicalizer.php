<?php

namespace App\Services;

use Carbon\CarbonInterface;
use DateTimeInterface;

class AuditCanonicalizer
{
    /**
     * @param  array<string, mixed>|object  $row
     */
    public function hash(array|object $row): string
    {
        return hash('sha256', $this->canonicalJson($row));
    }

    /**
     * @param  array<string, mixed>|object  $row
     */
    public function canonicalJson(array|object $row): string
    {
        $row = (array) $row;
        $payload = $row['payload'] ?? null;

        if (is_string($payload)) {
            $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        }

        return json_encode($this->sortKeys([
            'chain_version' => $this->integerOrNull($row['chain_version'] ?? null),
            'sequence' => $this->integerOrNull($row['sequence'] ?? null),
            'id' => $row['id'] ?? null,
            'actor_user_ref' => $row['actor_user_ref'] ?? null,
            'actor_device_ref' => $row['actor_device_ref'] ?? null,
            'actor_type' => $row['actor_type'] ?? null,
            'action' => $row['action'] ?? null,
            'target_type' => $row['target_type'] ?? null,
            'target_id' => $row['target_id'] ?? null,
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'payload' => $payload,
            'created_at' => $this->timestamp($row['created_at'] ?? null),
            'prev_hash' => $row['prev_hash'] ?? null,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function integerOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function timestamp(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortKeys($item);
        }

        if (! $isList) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
