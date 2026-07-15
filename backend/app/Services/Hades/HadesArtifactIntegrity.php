<?php

namespace App\Services\Hades;

use InvalidArgumentException;

class HadesArtifactIntegrity
{
    public const CODE_SCHEMAS = [
        'hades.git_tree.v1',
        'hades.symbols.v1',
        'hades.php_graph.v1',
        'hades.code_graph.v1',
    ];

    public function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function sha256(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /** @return array<string, mixed> */
    public function validateWikiArtifact(object $artifact): array
    {
        if (! in_array($artifact->schema, self::CODE_SCHEMAS, true) || (bool) $artifact->truncated) {
            throw new InvalidArgumentException('Artifact is not complete code evidence.');
        }

        $payload = is_string($artifact->artifact) ? json_decode($artifact->artifact, true) : $artifact->artifact;
        if (! is_array($payload) || ($payload['schema'] ?? null) !== $artifact->schema) {
            throw new InvalidArgumentException('Artifact schema is invalid.');
        }

        $storedHash = strtolower((string) ($artifact->sha256 ?? ''));
        if (preg_match('/\A[0-9a-f]{64}\z/', $storedHash) !== 1 || ! hash_equals($storedHash, $this->sha256($payload))) {
            throw new InvalidArgumentException('Artifact hash is invalid.');
        }

        $nonEmpty = match ($artifact->schema) {
            'hades.git_tree.v1' => $this->validFiles($payload['files'] ?? null),
            'hades.symbols.v1' => $this->validSymbols($payload['symbols'] ?? null),
            'hades.php_graph.v1', 'hades.code_graph.v1' => collect(['symbols', 'nodes', 'routes', 'edges'])
                ->contains(fn (string $key): bool => $this->nonEmptyObjectList($payload[$key] ?? null)),
            default => false,
        };

        if (! $nonEmpty) {
            throw new InvalidArgumentException('Artifact contains no usable code evidence.');
        }

        return $payload;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function nonEmptyList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value !== [];
    }

    private function nonEmptyObjectList(mixed $value): bool
    {
        return $this->nonEmptyList($value)
            && collect($value)->contains(fn (mixed $item): bool => is_array($item) && ! array_is_list($item) && $item !== []);
    }

    private function validSymbols(mixed $symbols): bool
    {
        return $this->nonEmptyObjectList($symbols)
            && collect($symbols)->contains(fn (mixed $symbol): bool => is_array($symbol)
                && is_string($symbol['name'] ?? null)
                && trim($symbol['name']) !== '');
    }

    private function validFiles(mixed $files): bool
    {
        if (! $this->nonEmptyList($files)) {
            return false;
        }

        foreach ($files as $file) {
            $hash = is_array($file) ? ($file['sha256'] ?? $file['hash'] ?? null) : null;
            if (! is_array($file)
                || ! $this->isSafeRelativePath($file['path'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/i', (string) $hash) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function isSafeRelativePath(mixed $value): bool
    {
        if (! is_string($value)
            || $value === ''
            || strlen($value) > 2048
            || str_starts_with($value, '/')
            || str_contains($value, '\\')
            || preg_match('/\A[A-Za-z]:/', $value) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
