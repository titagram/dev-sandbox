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
        $this->assertExactKeys($coverage, ['languages', 'files_total', 'files_analyzed', 'files_failed'], 'coverage');
        if (! is_array($coverage['languages']) || ! array_is_list($coverage['languages'])
            || $coverage['languages'] === [] || count($coverage['languages']) > 16
            || count(array_unique($coverage['languages'], SORT_REGULAR)) !== count($coverage['languages'])) {
            $this->malformed('coverage.languages');
        }
        foreach ($coverage['languages'] as $language) {
            $this->assertPattern($language, 'coverage.languages', 32, '/\A[a-z0-9][a-z0-9+#._-]*\z/D');
        }
        foreach (['files_total', 'files_analyzed', 'files_failed'] as $field) {
            if (! is_int($coverage[$field]) || $coverage[$field] < 0) {
                $this->malformed('coverage.'.$field);
            }
        }
        if ($coverage['files_analyzed'] > $coverage['files_total']
            || $coverage['files_failed'] > $coverage['files_total']
            || $coverage['files_total'] !== $coverage['files_analyzed'] + $coverage['files_failed']) {
            $this->malformed('coverage');
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
        foreach (['name', 'kind', 'path', 'line_start', 'line_end', 'confidence'] as $key) {
            if (array_key_exists($key, $node)) {
                $properties[$key] = $node[$key];
            }
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
