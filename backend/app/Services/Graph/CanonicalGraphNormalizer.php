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
        $rawNodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : ($payload['symbols'] ?? []);
        $rawEdges = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : ($payload['edges'] ?? []);
        $nodes = array_map(fn (array $node): array => $this->node($node), array_values(array_filter($rawNodes, 'is_array')));
        $relationships = array_map(fn (array $edge): array => $this->edge($edge), array_values(array_filter($rawEdges, 'is_array')));

        return ['contract' => $contract, 'identity' => $identity, 'nodes' => $nodes, 'relationships' => $relationships, 'stats' => ['nodes' => count($nodes), 'relationships' => count($relationships)]];
    }

    private function node(array $node): array
    {
        $id = $node['id'] ?? $node['symbol_id'] ?? null;
        if (! is_string($id) || trim($id) === '') {
            throw new InvalidArgumentException('Canonical graph node id is missing.');
        }
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
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
            'properties' => is_array($edge['properties'] ?? null) ? $edge['properties'] : [],
        ];
        if (array_key_exists('id', $edge)) {
            $normalized = ['id' => $edge['id']] + $normalized;
        }

        return $normalized;
    }
}
