<?php

namespace App\Services\Graph;

use InvalidArgumentException;

class CanonicalGraphNormalizer
{
    private const FALLBACK_REASONS = [
        'no_relationships_extracted',
        'bounded_or_omitted_input',
        'canonicalization_omissions',
        'graphify_unavailable',
        'missing_contract_metadata',
    ];

    public function normalize(array $payload, array $identity): array
    {
        $contract = $payload['graph_contract'] ?? null;
        if (! is_array($contract) || ($contract['version'] ?? null) !== 'hades.graph_artifact.v1') {
            throw new InvalidArgumentException('Canonical graph contract is missing or unsupported.');
        }
        $this->validateContract($contract);
        $rawNodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : ($payload['symbols'] ?? []);
        $rawEdges = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : ($payload['edges'] ?? []);
        $nodes = array_map(fn (array $node): array => $this->node($node), array_values(array_filter($rawNodes, 'is_array')));
        $relationships = array_map(fn (array $edge): array => $this->edge($edge), array_values(array_filter($rawEdges, 'is_array')));

        return ['contract' => $contract, 'identity' => $identity, 'nodes' => $nodes, 'relationships' => $relationships, 'stats' => ['nodes' => count($nodes), 'relationships' => count($relationships)]];
    }

    private function validateContract(array $contract): void
    {
        $this->assertExactKeys($contract, ['version', 'extractor', 'coverage', 'source'], 'graph_contract');

        $extractor = $contract['extractor'] ?? null;
        if (! is_array($extractor)) {
            $this->malformed('extractor');
        }
        $this->assertExactKeys($extractor, ['name', 'version', 'mode', 'quality', 'fallback_reason'], 'extractor');
        $this->assertPattern($extractor['name'], 'extractor.name', 64, '/\A[a-z0-9][a-z0-9._-]*\z/D');
        $this->assertPattern($extractor['version'], 'extractor.version', 32, '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D');
        if (! in_array($extractor['mode'], ['native', 'graphify', 'fallback', 'legacy_adapter'], true)) {
            $this->malformed('extractor.mode');
        }
        if (! in_array($extractor['quality'], ['full', 'partial', 'inventory_only'], true)) {
            $this->malformed('extractor.quality');
        }
        if ($extractor['fallback_reason'] !== null) {
            $reason = $extractor['fallback_reason'];
            if (! is_string($reason) || strlen($reason) > 100 || (! in_array($reason, self::FALLBACK_REASONS, true)
                && preg_match('/\Agraphify_failed:[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $reason) !== 1)) {
                $this->malformed('extractor.fallback_reason');
            }
        }
        if (($extractor['quality'] === 'full') !== ($extractor['fallback_reason'] === null)) {
            $this->malformed('extractor');
        }

        $coverage = $contract['coverage'] ?? null;
        if (! is_array($coverage)) {
            $this->malformed('coverage');
        }
        $requiredCoverageFields = ['languages', 'files_total', 'files_analyzed', 'files_failed'];
        $optionalCoverageFields = [
            'files_budget_omitted',
            'routes_promoted',
            'routes_omitted',
            'tests_promoted',
            'tests_omitted',
            'nodes_capacity_omitted',
        ];
        $this->assertRequiredAndAllowedKeys(
            $coverage,
            $requiredCoverageFields,
            [...$requiredCoverageFields, ...$optionalCoverageFields],
            'coverage',
        );
        if (! is_array($coverage['languages']) || ! array_is_list($coverage['languages'])
            || $coverage['languages'] === [] || count($coverage['languages']) > 16
            || count(array_unique($coverage['languages'], SORT_REGULAR)) !== count($coverage['languages'])) {
            $this->malformed('coverage.languages');
        }
        foreach ($coverage['languages'] as $language) {
            $this->assertPattern($language, 'coverage.languages', 32, '/\A[a-z0-9][a-z0-9+#._-]*\z/D');
        }
        foreach (['files_total', 'files_analyzed', 'files_failed', ...$optionalCoverageFields] as $field) {
            if (! array_key_exists($field, $coverage)) {
                continue;
            }
            if (! is_int($coverage[$field]) || $coverage[$field] < 0) {
                $this->malformed('coverage.'.$field);
            }
        }
        if ($coverage['files_analyzed'] > $coverage['files_total']
            || $coverage['files_failed'] > $coverage['files_total']
            || $coverage['files_total'] !== $coverage['files_analyzed'] + $coverage['files_failed']) {
            $this->malformed('coverage');
        }
        if (($coverage['files_budget_omitted'] ?? 0) > $coverage['files_failed']) {
            $this->malformed('coverage.files_budget_omitted');
        }

        $source = $contract['source'] ?? null;
        if (! is_array($source)) {
            $this->malformed('source');
        }
        $this->assertExactKeys($source, ['branch', 'head_commit'], 'source');
        if ($source['branch'] !== null) {
            $this->assertGitBranch($source['branch']);
        }
        if ($source['head_commit'] !== null) {
            $this->assertPattern($source['head_commit'], 'source.head_commit', 80, '/\A[0-9a-fA-F]{4,80}\z/D');
        }
    }

    private function assertExactKeys(array $value, array $expected, string $field): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            $this->malformed($field);
        }
    }

    private function assertRequiredAndAllowedKeys(array $value, array $required, array $allowed, string $field): void
    {
        $actual = array_keys($value);
        if (array_diff($required, $actual) !== [] || array_diff($actual, $allowed) !== []) {
            $this->malformed($field);
        }
    }

    private function assertNonEmptyString(mixed $value, string $field): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->malformed($field);
        }
    }

    private function assertPattern(mixed $value, string $field, int $maxLength, string $pattern): void
    {
        if (! is_string($value) || strlen($value) > $maxLength || preg_match($pattern, $value) !== 1) {
            $this->malformed($field);
        }
    }

    private function assertGitBranch(mixed $value): void
    {
        $this->assertPattern($value, 'source.branch', 255, '/\A[A-Za-z0-9][A-Za-z0-9._\/-]*\z/D');

        $invalid = str_contains($value, '..')
            || str_contains($value, '//')
            || str_contains($value, '@{')
            || str_ends_with($value, '/')
            || str_ends_with($value, '.')
            || preg_match('/(?:\A|\/)\./', $value) === 1
            || preg_match('/(?:\A|\/)[^\/]*\.lock(?:\/|\z)/D', $value) === 1;

        if ($invalid) {
            throw new InvalidArgumentException('Canonical graph contract is malformed at source.branch.');
        }
    }

    private function malformed(string $field): never
    {
        throw new InvalidArgumentException("Canonical graph contract is malformed at {$field}.");
    }

    private function node(array $node): array
    {
        $id = $node['id'] ?? $node['symbol_id'] ?? null;
        if (! is_string($id) || trim($id) === '') {
            throw new InvalidArgumentException('Canonical graph node id is missing.');
        }
        $properties = $this->propertyBag($node, 'node');
        foreach (['name', 'kind', 'path', 'uri', 'line_start', 'line_end', 'confidence'] as $key) {
            if (array_key_exists($key, $node)) {
                $properties[$key] = $node[$key];
            }
        }
        $kind = strtolower(trim((string) ($properties['kind'] ?? '')));
        $isRoute = in_array($kind, ['route', 'endpoint', 'http_endpoint'], true);
        $routeUri = $isRoute
            ? $this->routeUri($node['uri'] ?? $properties['uri'] ?? null, $node['path'] ?? $properties['path'] ?? null)
            : null;
        $sourceCandidates = [
            $node['source_file'] ?? null,
            $node['source_path'] ?? null,
            $node['file_path'] ?? null,
            $node['file'] ?? null,
            $properties['source_file'] ?? null,
            $properties['source_path'] ?? null,
            $properties['file_path'] ?? null,
            $properties['file'] ?? null,
        ];
        $path = $node['path'] ?? $properties['path'] ?? null;
        $pathUri = $isRoute ? $this->routeUri(null, $path) : null;
        if (! $isRoute || $routeUri === null || $pathUri === null || $routeUri !== $pathUri) {
            $sourceCandidates[] = $path;
        }
        $sourceFile = null;
        foreach ($sourceCandidates as $candidate) {
            $sourceFile = $this->safeSourceFile($candidate);
            if ($sourceFile !== null) {
                break;
            }
        }
        $lineStart = $this->safeLine($node['line_start'] ?? $node['line'] ?? $properties['line_start'] ?? $properties['line'] ?? null);
        $lineEnd = $this->safeLine($node['line_end'] ?? $properties['line_end'] ?? null);
        if ($sourceFile !== null) {
            $properties['source_file'] = $sourceFile;
        } else {
            unset($properties['source_file'], $properties['source_path'], $properties['file_path']);
        }
        if (array_key_exists('path', $properties) && $this->safeSourceFile($properties['path']) === null) {
            unset($properties['path']);
        }
        if ($sourceFile !== null && $lineStart !== null) {
            $properties['line_start'] = $lineStart;
        } else {
            unset($properties['line_start']);
        }
        if ($sourceFile !== null && $lineEnd !== null) {
            $properties['line_end'] = $lineEnd;
        } elseif (array_key_exists('line_end', $properties)) {
            unset($properties['line_end']);
        }
        if ($isRoute) {
            if ($routeUri !== null) {
                $properties['uri'] = $routeUri;
            } else {
                unset($properties['uri']);
            }
            unset($properties['path'], $properties['source_path'], $properties['file_path']);
        }
        $labels = is_array($node['labels'] ?? null) ? array_values($node['labels']) : [];
        if ($labels === []) {
            $labels = ['Symbol'];
            $kind = $node['kind'] ?? null;
            if (is_string($kind) && trim($kind) !== '') {
                $labels[] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', trim($kind))));
            }
        }

        return ['id' => $id, 'labels' => $labels, 'properties' => $properties];
    }

    private function routeUri(mixed $uri, mixed $path): ?string
    {
        foreach ([$uri, $path] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate === '' || strlen($candidate) > 512) {
                continue;
            }
            if (! str_starts_with($candidate, '/')) {
                $candidate = '/'.$candidate;
            }
            if (preg_match('#\A/(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._~!$&\x27()*+,;=:@%{}\-/]*\z#', $candidate) === 1
                && preg_match('/(?:\A|\/)[^\/{}?*]+\.(?:php|phar|inc|phtml|ts|tsx|js|jsx|mjs|cjs|py|rb|go|java|kt|kts|rs|c|cc|cpp|h|hpp|swift|dart|vue|svelte|sql|yaml|yml|json|xml|toml|ini|env)(?:\/|\z)/i', $candidate) !== 1
                && ! str_contains($candidate, '\\')
                && stripos($candidate, 'file://') === false) {
                return $candidate;
            }
        }

        return null;
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
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match('~(?:\A|/)(?:\.|\.\.)(?:/|\z)~', $value) === 1
            || str_contains($value, '//')) {
            return null;
        }

        return $value;
    }

    private function safeLine(mixed $value): ?int
    {
        if (is_int($value) && $value >= 1 && $value <= 10_000_000) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,7}\z/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function edge(array $edge): array
    {
        $sourceId = $edge['source_id'] ?? $edge['source'] ?? $edge['from'] ?? null;
        $targetId = $edge['target_id'] ?? $edge['target'] ?? $edge['to'] ?? null;
        if (! is_string($sourceId) || trim($sourceId) === '' || ! is_string($targetId) || trim($targetId) === '') {
            throw new InvalidArgumentException('Canonical graph edge endpoints are missing.');
        }
        $type = strtoupper((string) ($edge['type'] ?? $edge['kind'] ?? ''));
        $normalized = [
            'type' => preg_replace('/[^A-Z0-9_]/', '_', $type) ?? '',
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'properties' => $this->propertyBag($edge, 'edge'),
        ];
        if (array_key_exists('id', $edge)) {
            $normalized = ['id' => $edge['id']] + $normalized;
        }

        return $normalized;
    }

    private function propertyBag(array $item, string $kind): array
    {
        if (! array_key_exists('properties', $item)) {
            return [];
        }

        $properties = $item['properties'];
        if (! is_array($properties)) {
            throw new InvalidArgumentException("Canonical graph {$kind} properties must be a map.");
        }

        foreach (array_keys($properties) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("Canonical graph {$kind} properties must be a map.");
            }
        }

        return $properties;
    }
}
