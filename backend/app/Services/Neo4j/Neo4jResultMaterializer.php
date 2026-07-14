<?php

namespace App\Services\Neo4j;

final class Neo4jResultMaterializer
{
    /** @return list<array<string,mixed>> */
    public static function materializeRows(mixed $result): array
    {
        $materialized = self::materializeValue($result);
        if (! is_array($materialized)) {
            return [];
        }

        $rows = [];
        foreach ($materialized as $row) {
            $row = self::materializeValue($row);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function materializeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::materializeValue($child);
            }

            return $value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            try {
                return self::materializeValue($value->toArray());
            } catch (\Throwable) {
                return null;
            }
        }

        if ($value instanceof \Traversable) {
            try {
                return self::materializeValue(iterator_to_array($value));
            } catch (\Throwable) {
                return null;
            }
        }

        if ($value instanceof \ArrayAccess) {
            $knownKeys = [
                'node',
                'labels',
                'score',
                'distance',
                'edge_types',
                'public_handle_key_version',
                'public_handle_key_fingerprint',
            ];
            $mapped = [];
            foreach ($knownKeys as $key) {
                if ($value->offsetExists($key)) {
                    $mapped[$key] = self::materializeValue($value[$key]);
                }
            }

            return $mapped === [] ? null : $mapped;
        }

        return $value;
    }
}
