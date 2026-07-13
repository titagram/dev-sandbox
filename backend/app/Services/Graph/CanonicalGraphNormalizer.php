<?php

namespace App\Services\Graph;

use InvalidArgumentException;

class CanonicalGraphNormalizer
{
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
        $this->assertNonEmptyString($extractor['name'], 'extractor.name');
        $this->assertNonEmptyString($extractor['version'], 'extractor.version');
        if (! in_array($extractor['mode'], ['native', 'graphify', 'fallback', 'legacy_adapter'], true)) {
            $this->malformed('extractor.mode');
        }
        if (! in_array($extractor['quality'], ['full', 'partial', 'inventory_only'], true)) {
            $this->malformed('extractor.quality');
        }
        if ($extractor['fallback_reason'] !== null) {
            $this->assertNonEmptyString($extractor['fallback_reason'], 'extractor.fallback_reason');
        }

        $coverage = $contract['coverage'] ?? null;
        if (! is_array($coverage)) {
            $this->malformed('coverage');
        }
        $this->assertExactKeys($coverage, ['languages', 'files_total', 'files_analyzed', 'files_failed'], 'coverage');
        if (! is_array($coverage['languages']) || ! array_is_list($coverage['languages'])) {
            $this->malformed('coverage.languages');
        }
        foreach ($coverage['languages'] as $language) {
            $this->assertNonEmptyString($language, 'coverage.languages');
        }
        foreach (['files_total', 'files_analyzed', 'files_failed'] as $field) {
            if (! is_int($coverage[$field]) || $coverage[$field] < 0) {
                $this->malformed('coverage.'.$field);
            }
        }

        $source = $contract['source'] ?? null;
        if (! is_array($source)) {
            $this->malformed('source');
        }
        $this->assertExactKeys($source, ['branch', 'head_commit'], 'source');
        foreach (['branch', 'head_commit'] as $field) {
            if ($source[$field] !== null) {
                $this->assertNonEmptyString($source[$field], 'source.'.$field);
            }
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
