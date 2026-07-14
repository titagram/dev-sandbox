<?php

namespace App\Services\Graph;

use InvalidArgumentException;
use JsonException;

final class DashboardGraphPublicHandle
{
    private const VERSION = 'gh1';

    private const HASH_LENGTH = 43;

    public function forNode(
        string $projectId,
        string $scopeType,
        string $scopeId,
        string $graphVersion,
        string $externalId,
    ): string {
        foreach ([$projectId, $scopeType, $scopeId, $graphVersion, $externalId] as $value) {
            if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('invalid_handle');
            }
        }

        $key = (string) config('app.key');
        if ($key === '') {
            throw new InvalidArgumentException('invalid_handle');
        }

        try {
            $canonicalJson = json_encode([
                'v' => self::VERSION,
                'project_id' => $projectId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'graph_version' => $graphVersion,
                'external_id' => $externalId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid_handle');
        }

        return self::VERSION.'_'.self::base64UrlEncode(hash_hmac('sha256', $canonicalJson, $key, true));
    }

    public function isWellFormed(string $handle): bool
    {
        if (preg_match('/\A'.preg_quote(self::VERSION, '/').'_(?<encoded>[A-Za-z0-9_-]{'.self::HASH_LENGTH.'})\z/', $handle, $matches) !== 1) {
            return false;
        }

        $decoded = self::base64UrlDecode($matches['encoded']);

        return $decoded !== null
            && strlen($decoded) === 32
            && self::base64UrlEncode($decoded) === $matches['encoded'];
    }

    public function keyVersion(): string
    {
        return self::VERSION;
    }

    public function keyFingerprint(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new InvalidArgumentException('invalid_handle');
        }

        return hash('sha256', $key);
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
