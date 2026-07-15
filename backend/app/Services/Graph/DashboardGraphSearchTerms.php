<?php

namespace App\Services\Graph;

final class DashboardGraphSearchTerms
{
    private const MAX_QUERY_LENGTH = 160;

    private const MAX_QUERY_TOKENS = 16;

    private const MAX_NODE_TOKENS = 48;

    private const MAX_TOKEN_LENGTH = 64;

    /** @return array{normalized:string,tokens:list<string>,lucene:string} */
    public function forQuery(string $query): array
    {
        // Keep one character beyond the public limit so callers can reject an
        // overlong query instead of silently searching for a truncated value.
        $normalized = $this->normalizeText($query, self::MAX_QUERY_LENGTH + 1);
        $normalized = trim(preg_replace('/\Aroute\s*:/iu', '', $normalized) ?? '');
        $tokens = $this->tokens([$normalized], self::MAX_QUERY_TOKENS, false);

        return [
            'normalized' => $normalized,
            'tokens' => $tokens,
            'lucene' => implode(' AND ', array_map(
                static fn (string $token): string => 'public_search_terms:'.$token.'*',
                $tokens,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function forNode(array $properties, string $kind, bool $trustedProducerRoute): array
    {
        $name = $this->safeSearchValue($properties['name'] ?? null);
        $label = $this->safeSearchValue($properties['label'] ?? null);
        $sourceFile = $this->safeSourceFile(
            $properties['source_file']
                ?? $properties['file_path']
                ?? $properties['source_path']
                ?? ($kind === 'file' ? ($properties['path'] ?? null) : null),
        );
        $lineStart = $this->safeLine($properties['line_start'] ?? $properties['line'] ?? null);
        $lineEnd = $this->safeLine($properties['line_end'] ?? null);
        $namespace = $this->safeNamespace($properties['namespace'] ?? null);
        $path = strtolower(trim($kind)) === 'route'
            ? $this->safeRoutePath(
                $properties['uri'] ?? $properties['route'] ?? $properties['route_path'] ?? $properties['url'] ?? $properties['path'] ?? null,
                $trustedProducerRoute,
            )
            : null;

        $aliases = [$name, $label, $path];
        foreach (['handler', 'defined_handler'] as $field) {
            $aliases[] = $this->safeSearchValue($properties[$field] ?? null);
        }

        return [
            'public_search_name' => $name,
            'public_search_label' => $label,
            'public_search_path' => $path,
            'public_search_name_normalized' => $name === null ? null : mb_strtolower($name),
            'public_search_path_normalized' => $path === null ? null : mb_strtolower($path),
            'public_search_terms' => $this->tokens($aliases, self::MAX_NODE_TOKENS, true),
            'public_source_file' => $sourceFile,
            'public_line_start' => $lineStart,
            'public_line_end' => $lineEnd,
            'public_namespace' => $namespace,
        ];
    }

    /** @param list<?string> $values @return list<string> */
    private function tokens(array $values, int $limit, bool $includeCompactAliases): array
    {
        $tokens = [];
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $value = preg_replace('/\Aroute\s*:/iu', '', $value) ?? '';
            if ($includeCompactAliases) {
                preg_match_all('/[\p{L}\p{N}]+/u', $value, $matches);
                foreach ($matches[0] ?? [] as $word) {
                    $this->appendToken($tokens, mb_strtolower($word), $limit);
                }
            }

            $camelSplit = preg_replace('/(?<=[\p{Ll}\p{N}])(?=\p{Lu})/u', ' ', $value) ?? '';
            preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($camelSplit), $matches);
            foreach ($matches[0] ?? [] as $token) {
                $this->appendToken($tokens, $token, $limit);
            }
            if (count($tokens) >= $limit) {
                break;
            }
        }

        return array_keys($tokens);
    }

    /** @param array<string, true> $tokens */
    private function appendToken(array &$tokens, string $token, int $limit): void
    {
        $token = mb_substr($token, 0, self::MAX_TOKEN_LENGTH);
        if ($token !== '' && ! isset($tokens[$token]) && count($tokens) < $limit) {
            $tokens[$token] = true;
        }
    }

    private function normalizeText(string $value, int $limit): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return mb_strtolower(mb_substr($value, 0, $limit));
    }

    private function safeSearchValue(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $value === '' || $this->isTechnicalIdentity($value) ? null : mb_substr($value, 0, 200);
    }

    private function isTechnicalIdentity(string $value, bool $allowPublicRouteSyntax = false): bool
    {
        if (preg_match('/\A(?:hades-public-|legacy[-_:]|(?:node|edge|internal)[-_:])/i', $value) === 1) {
            return true;
        }
        if (str_contains($value, '\\') && $this->isValidPhpFqcn($value)) {
            return false;
        }

        $normalised = str_replace('\\', '/', $value);
        if (stripos($normalised, 'file://') !== false
            || preg_match('~(?:\A|[^A-Za-z0-9_])([A-Za-z]):(?:[\\/]|[A-Za-z0-9_.-]*[\\/])~', $value) === 1
            || preg_match('~(?:\A|[\s:=\[\(,|])(?:\\\?\\|\\\\|//)~', $value) === 1
            || preg_match('~(?:\A|[\s:=\[\(,|])\.\.?[\\/]~', $value) === 1
            || (str_contains($value, '\\') && ! $this->isValidPhpFqcn($value))) {
            return true;
        }
        if (! $allowPublicRouteSyntax && preg_match('~(?:\A|[\s:=\[\(,|])/~', $normalised) === 1) {
            return true;
        }
        if (! $allowPublicRouteSyntax
            && preg_match('~(?:\A|[\s:=\[\(,|])(?:[^\s:=\[\(,|/]+/)+[^\s/]+~', $normalised) === 1) {
            return true;
        }
        if (! $allowPublicRouteSyntax
            && preg_match('/(?:\A|[\s:=\[\(,|])(?:\.\.?|[A-Za-z0-9_.-]+)[\\/][^\s]+/D', $value) === 1) {
            return true;
        }

        return $allowPublicRouteSyntax
            && preg_match('/(?:\A|\/)[^\/{}?*]+\.(?:php|phar|inc|phtml|ts|tsx|js|jsx|mjs|cjs|py|rb|go|java|kt|kts|rs|c|cc|cpp|h|hpp|swift|dart|vue|svelte|sql|yaml|yml|json|xml|toml|ini|env)(?:\/|\z)/i', $normalised) === 1;
    }

    private function isValidPhpFqcn(string $value): bool
    {
        return preg_match('/\A\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+\z/D', $value) === 1;
    }

    private function safeRoutePath(mixed $value, bool $trustedProducerRoute): ?string
    {
        if (! is_string($value) || ! $trustedProducerRoute || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim($value);

        return strlen($value) <= 512
            && ! $this->isTechnicalIdentity($value, true)
            && preg_match('#\A/(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._~!$&\x27()*+,;=:@%{}\-/]*\z#', $value) === 1
                ? $value
                : null;
    }

    private function safeSourceFile(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim($value);

        if ($value === '' || strlen($value) > 512 || str_contains($value, '\\')
            || str_contains($value, '://') || str_starts_with($value, '/')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $value) === 1
            || preg_match('~(?:\A|/)\.\.(?:/|\z)~', $value) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function safeLine(mixed $value): ?int
    {
        if (is_int($value) && $value >= 1 && $value <= 10_000_000) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,7}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function safeNamespace(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 255
            || preg_match('/\A\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
