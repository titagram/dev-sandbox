<?php

namespace App\Assistants;

final class ProviderEndpointPolicy
{
    public static function validate(string $url): bool
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);

        if ($scheme !== 'https') {
            $isLocal = app()->environment('local') || app()->environment('testing');

            if (! $isLocal) {
                return false;
            }
        }

        $blockedHosts = [
            'localhost',
            'localhost.localdomain',
            'localhost6',
            '127.0.0.1',
            '::1',
            '0.0.0.0',
        ];

        if (in_array($host, $blockedHosts, true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        $ip = gethostbyname($host);

        if ($ip !== $host) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    public static function errorMessage(): string
    {
        return 'The provider endpoint must be a public HTTPS URL. Private, loopback, and link-local addresses are not allowed.';
    }
}
