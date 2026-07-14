<?php

namespace App\Services\Graph;

use InvalidArgumentException;
use JsonException;

final class DashboardGraphExplorerCursor
{
    private const PREFIX = 'gc1_';

    private const MAX_LENGTH = 512;

    public function encode(
        string $projectId,
        ?string $sourceScopeType,
        ?string $sourceScopeId,
        string $activeGraphVersion,
        string $queryType,
        string $query,
        string $sortKey,
    ): string {
        if (preg_match('//u', $query) !== 1) {
            throw new InvalidArgumentException('invalid_cursor');
        }
        $query = self::normalizeQuery($query);
        if ($projectId === ''
            || $activeGraphVersion === ''
            || $queryType === ''
            || $sortKey === ''
            || ($sourceScopeType === null) !== ($sourceScopeId === null)
            || self::isBoundedStringInvalid($projectId)
            || self::isBoundedStringInvalid($activeGraphVersion)
            || self::isBoundedStringInvalid($queryType)
            || self::isBoundedStringInvalid($query)
            || self::isBoundedStringInvalid($sortKey)
            || ($sourceScopeType !== null && self::isBoundedStringInvalid($sourceScopeType))
            || ($sourceScopeId !== null && self::isBoundedStringInvalid($sourceScopeId))) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        $key = (string) config('app.key');
        if ($key === '') {
            throw new InvalidArgumentException('invalid_cursor');
        }

        try {
            $canonicalJson = json_encode([
                'project_id' => $projectId,
                'source_scope_type' => $sourceScopeType,
                'source_scope_id' => $sourceScopeId,
                'active_graph_version' => $activeGraphVersion,
                'query_type' => $queryType,
                'query' => $query,
                'sort_key' => $sortKey,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        $cursor = self::PREFIX
            .self::base64UrlEncode($canonicalJson)
            .'.'
            .self::base64UrlEncode(hash_hmac('sha256', $canonicalJson, $key, true));
        if (strlen($cursor) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        return $cursor;
    }

    /** @return array{project_id:string,source_scope_type:?string,source_scope_id:?string,active_graph_version:string,query_type:string,query:string,sort_key:string} */
    public function decode(string $cursor): array
    {
        if (strlen($cursor) === 0 || strlen($cursor) > self::MAX_LENGTH || ! str_starts_with($cursor, self::PREFIX)) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        $encoded = substr($cursor, strlen(self::PREFIX));
        $parts = explode('.', $encoded);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        $canonicalJson = self::base64UrlDecode($parts[0]);
        $signature = self::base64UrlDecode($parts[1]);
        if ($canonicalJson === null
            || $signature === null
            || strlen($signature) !== 32
            || self::base64UrlEncode($canonicalJson) !== $parts[0]
            || self::base64UrlEncode($signature) !== $parts[1]) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        $key = (string) config('app.key');
        if ($key === '' || ! hash_equals(hash_hmac('sha256', $canonicalJson, $key, true), $signature)) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        try {
            $payload = json_decode($canonicalJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        $expectedKeys = [
            'project_id',
            'source_scope_type',
            'source_scope_id',
            'active_graph_version',
            'query_type',
            'query',
            'sort_key',
        ];
        if (! is_array($payload)
            || array_keys($payload) !== $expectedKeys
            || ! is_string($payload['project_id'])
            || ! is_string($payload['active_graph_version'])
            || ! is_string($payload['query_type'])
            || ! is_string($payload['query'])
            || ! is_string($payload['sort_key'])
            || ($payload['source_scope_type'] !== null && ! is_string($payload['source_scope_type']))
            || ($payload['source_scope_id'] !== null && ! is_string($payload['source_scope_id']))
            || ($payload['source_scope_type'] === null) !== ($payload['source_scope_id'] === null)
            || self::isBoundedStringInvalid($payload['project_id'])
            || self::isBoundedStringInvalid($payload['active_graph_version'])
            || self::isBoundedStringInvalid($payload['query_type'])
            || self::isBoundedStringInvalid($payload['query'])
            || self::isBoundedStringInvalid($payload['sort_key'])
            || ($payload['source_scope_type'] !== null && self::isBoundedStringInvalid($payload['source_scope_type']))
            || ($payload['source_scope_id'] !== null && self::isBoundedStringInvalid($payload['source_scope_id']))) {
            throw new InvalidArgumentException('invalid_cursor');
        }

        return $payload;
    }

    private static function normalizeQuery(string $query): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }

    private static function isBoundedStringInvalid(string $value): bool
    {
        return preg_match('//u', $value) !== 1
            || strlen($value) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return null;
        }

        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
