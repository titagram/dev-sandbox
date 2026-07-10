<?php

namespace App\Assistants;

final class ProviderEndpointPolicy
{
    public function __construct(private readonly ProviderHostResolver $resolver)
    {
    }

    public function resolve(string $url): ProviderEndpointResolution
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['host'])) {
            return $this->denied('', null, '', 'The provider endpoint must be an absolute URL with a host.');
        }

        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            return $this->denied('', null, '', 'The provider endpoint must not contain URL userinfo (user:pass@host).');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));

        if ($scheme !== 'https') {
            $devAllowed = app()->environment('local') || app()->environment('testing');

            if (! $devAllowed) {
                return $this->denied('', '', [], 'The provider endpoint must use HTTPS.');
            }
        }

        $rawHost = strtolower((string) $parts['host']);
        $host = self::stripIpv6Brackets($rawHost);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($host === '' || ! self::isValidHost($host)) {
            return $this->denied($host, $port, $scheme, 'The provider endpoint host is invalid.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$this->normalizeAddress($host)];
        } else {
            try {
                $resolved = $this->resolver->resolve($host);
            } catch (\Throwable) {
                $resolved = [];
            }

            $addresses = [];
            foreach ($resolved as $addr) {
                $addresses[] = $this->normalizeAddress($addr);
            }
            $addresses = array_values(array_unique($addresses));
        }

        if (count($addresses) === 0) {
            return $this->denied($host, $port, $scheme, 'The provider host could not be resolved to any public address.');
        }

        foreach ($addresses as $addr) {
            if (! $this->isPublicAddress($addr)) {
                return $this->denied($host, $port, $scheme, 'The provider host resolved to a non-public address.');
            }
        }

        return new ProviderEndpointResolution($host, $port, $scheme, $addresses, true);
    }

    public function isAllowed(string $url): bool
    {
        return $this->resolve($url)->allowed();
    }

    public static function validate(string $url): bool
    {
        return app(self::class)->isAllowed($url);
    }

    public static function errorMessage(): string
    {
        return 'The provider endpoint must be a public HTTPS URL and resolve only to public, non-private, non-loopback, non-link-local addresses. Unresolved hosts are not allowed.';
    }

    private function denied(string $host, ?int $port, string $scheme, string $reason, array $addresses = []): ProviderEndpointResolution
    {
        return new ProviderEndpointResolution($host, $port, $scheme, $addresses, false, $reason);
    }

    private static function stripIpv6Brackets(string $host): string
    {
        if (preg_match('/^\[(.+)\]$/', $host, $matches)) {
            return $matches[1];
        }

        return $host;
    }

    private static function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i', $host) === 1;
    }

    private function normalizeAddress(string $addr): string
    {
        $addr = self::stripIpv6Brackets($addr);

        if (str_starts_with(strtolower($addr), '::ffff:') && filter_var(substr($addr, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($addr, 7);
        }

        return $addr;
    }

    private function isPublicAddress(string $addr): bool
    {
        if (! filter_var($addr, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($addr);

            if ($long === false) {
                return false;
            }

            if (($long & 0xFF000000) === 0x7F000000) { // 127.0.0.0/8 loopback
                return false;
            }

            if ($addr === '0.0.0.0') { // unspecified
                return false;
            }

            if (($long & 0xFFFF0000) === 0xA9FE0000) { // 169.254.0.0/16 link-local
                return false;
            }

            if (($long & 0xF0000000) === 0xE0000000) { // 224.0.0.0/4 multicast
                return false;
            }

            return true;
        }

        // IPv6 explicit checks
        $packed = @inet_pton($addr);

        if ($packed === false) {
            return false;
        }

        // ::1 loopback
        if ($addr === '::1') {
            return false;
        }

        // :: unspecified
        if ($addr === '::') {
            return false;
        }

        // fc00::/7 unique local
        if ((ord($packed[0]) & 0xFE) === 0xFC) {
            return false;
        }

        // fe80::/10 link-local
        if ((((ord($packed[0]) << 8) | ord($packed[1])) & 0xFFC0) === 0xFE80) {
            return false;
        }

        // ff00::/8 multicast
        if ((ord($packed[0]) & 0xFF) === 0xFF) {
            return false;
        }

        return true;
    }
}