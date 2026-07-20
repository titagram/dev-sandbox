<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImport;
use stdClass;

/**
 * Recomputes the closed v2 identity preimages at the import boundary.
 *
 * The canonical byte algorithm intentionally remains in GraphV2Canonicalizer;
 * this class only selects and validates the contract's exact preimages.
 */
final class GraphV2IdentityValidator
{
    private const array NODE_KEYS = [
        'source_declaration' => ['variant', 'workspace_binding_id', 'language', 'kind', 'namespace', 'qualified_name', 'path'],
        'file' => ['variant', 'workspace_binding_id', 'language', 'kind', 'path'],
        'source_occurrence' => ['variant', 'workspace_binding_id', 'language', 'kind', 'owner_node_id', 'structural_path', 'ordinal', 'semantic_role'],
        'anonymous_callable' => ['variant', 'workspace_binding_id', 'language', 'kind', 'owner_node_id', 'structural_path', 'ordinal'],
        'entrypoint' => ['variant', 'workspace_binding_id', 'language', 'kind', 'path', 'entrypoint_identity'],
        'semantic_resource' => ['variant', 'workspace_binding_id', 'language', 'kind', 'framework', 'namespace', 'qualified_name', 'public_resource_name', 'protocol', 'operation'],
    ];

    private const array FLOW_KINDS = ['request_lifecycle', 'execution_flow', 'async_flow'];

    private const array STAGES = ['entry', 'routing', 'middleware', 'security', 'input', 'handler', 'domain', 'data', 'integration', 'async', 'response', 'error'];

    private const array ASYNC_CONTEXTS = ['synchronous', 'linked_async'];

    public function nodeId(mixed $identity): string
    {
        $value = $this->objectArray($identity, 'node identity');
        $variant = $value['variant'] ?? null;
        if (! is_string($variant) || ! isset(self::NODE_KEYS[$variant])) {
            $this->invalid('node identity variant is invalid');
        }
        $this->exactKeys($value, self::NODE_KEYS[$variant], 'node identity');

        return 'hades:node:v2:'.app(GraphV2Canonicalizer::class)->sha256($this->normalizeForIdentity($value));
    }

    public function structureId(mixed $record): string
    {
        $value = array_intersect_key($this->objectArray($record, 'structure'), array_flip(['kind', 'owner_node_id', 'structural_path', 'ordinal', 'subtype']));
        $this->requireKeys($value, ['kind', 'owner_node_id', 'structural_path', 'ordinal', 'subtype'], 'structure');
        $prefix = match ($value['kind'] ?? null) {
            'call_site' => 'hades:call-site:v2:',
            'branch_group' => 'hades:branch:v2:',
            'exception_scope' => 'hades:exception-scope:v2:',
            default => null,
        };
        if ($prefix === null) {
            $this->invalid('structure kind is invalid');
        }

        return $prefix.app(GraphV2Canonicalizer::class)->sha256($this->normalizeForIdentity($value));
    }

    public function edgeId(mixed $record): string
    {
        $source = $this->objectArray($record, 'edge');
        $value = array_intersect_key($source, array_flip(['source_id', 'target_id', 'relation', 'flow', 'condition_hash', 'branch_group_id', 'call_site_id', 'exception_scope_id', 'occurrence']));
        if (! array_key_exists('condition_hash', $value)) {
            $condition = $source['condition'] ?? null;
            $value['condition_hash'] = $condition instanceof stdClass
                ? ($condition->hash ?? null)
                : (is_array($condition) ? ($condition['hash'] ?? null) : null);
        }
        ksort($value);
        $this->exactKeys($value, ['source_id', 'target_id', 'relation', 'flow', 'condition_hash', 'branch_group_id', 'call_site_id', 'exception_scope_id', 'occurrence'], 'edge');

        return 'hades:edge:v2:'.app(GraphV2Canonicalizer::class)->sha256($this->normalizeForIdentity($value));
    }

    public function flowId(mixed $record): string
    {
        $value = array_intersect_key($this->objectArray($record, 'flow'), array_flip(['entrypoint_id', 'root_node_id', 'kind']));
        $this->exactKeys($value, ['entrypoint_id', 'root_node_id', 'kind'], 'flow');
        if (! in_array($value['kind'] ?? null, self::FLOW_KINDS, true)) {
            $this->invalid('flow kind is invalid');
        }

        return 'hades:flow:v2:'.app(GraphV2Canonicalizer::class)->sha256($this->normalizeForIdentity($value));
    }

    public function flowStepId(mixed $record): string
    {
        $value = array_intersect_key($this->objectArray($record, 'flow step'), array_flip(['flow_id', 'edge_id', 'stage_from', 'stage_to', 'async_context']));
        $this->exactKeys($value, ['flow_id', 'edge_id', 'stage_from', 'stage_to', 'async_context'], 'flow step');
        if (! in_array($value['stage_from'] ?? null, self::STAGES, true)
            || ! in_array($value['stage_to'] ?? null, self::STAGES, true)
            || ! in_array($value['async_context'] ?? null, self::ASYNC_CONTEXTS, true)) {
            $this->invalid('flow step discriminator is invalid');
        }

        return 'hades:flow-step:v2:'.app(GraphV2Canonicalizer::class)->sha256($this->normalizeForIdentity($value));
    }

    public function uncertaintyId(mixed $record, HadesGraphImport $import): string
    {
        $value = $this->objectArray($record, 'uncertainty');
        $identity = [
            'domain' => $value['domain'] ?? null,
            'project_id' => $import->project_id,
            'workspace_binding_id' => $import->workspace_binding_id,
            'subject' => $value['subject'] ?? null,
            'resolution_kind' => $value['resolution_kind'] ?? null,
            'reason_code' => $value['reason_code'] ?? null,
            'question' => $value['question'] ?? null,
        ];
        $this->exactKeys($identity, ['domain', 'project_id', 'workspace_binding_id', 'subject', 'resolution_kind', 'reason_code', 'question'], 'uncertainty');

        return 'hades:uncertainty:v2:'.app(GraphV2Canonicalizer::class)->sha256($this->normalizeForIdentity($identity));
    }

    public function assertRecord(HadesGraphImport $import, string $kind, stdClass $record): void
    {
        try {
            $expected = match ($kind) {
                'nodes' => $this->assertNode($import, $record),
                'structures' => $this->structureId($record),
                'edges' => $this->edgeId($record),
                'flows' => $this->flowId($record),
                'flow_steps' => $this->flowStepId($record),
                'uncertainties' => $this->uncertaintyId($record, $import),
                // Entrypoint records intentionally share their paired node ID.
                'entrypoints' => $this->prefixedId($record->id ?? null, 'hades:node:v2:'),
                default => $this->invalid('record kind is invalid'),
            };
        } catch (GraphV2ImportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new GraphV2ImportException('graph_validation_identity_invalid', 'Graph record identity is invalid.', previous: $exception);
        }

        if (($record->id ?? null) !== $expected) {
            $this->invalid('Graph record ID does not match its exact identity preimage.');
        }
        if ($kind === 'uncertainties') {
            $fingerprint = $record->fingerprint ?? null;
            $expectedFingerprint = substr($expected, strlen('hades:uncertainty:v2:'));
            if (! is_string($fingerprint) || ! hash_equals($expectedFingerprint, $fingerprint)) {
                $this->invalid('Uncertainty fingerprint is required and must match its exact identity preimage.');
            }
        }
    }

    private function assertNode(HadesGraphImport $import, stdClass $record): string
    {
        $identity = $this->objectArray($record->identity ?? null, 'node identity');
        if (($identity['workspace_binding_id'] ?? null) !== $import->workspace_binding_id
            || ($identity['kind'] ?? null) !== ($record->kind ?? null)
            || ($identity['language'] ?? null) !== ($record->language ?? null)) {
            $this->invalid('Node identity scope, kind, or language disagrees with the record.');
        }
        $variant = $identity['variant'] ?? null;
        if (($variant === 'file' && ($record->kind ?? null) !== 'file')
            || ($variant === 'entrypoint' && ($record->kind ?? null) !== 'entrypoint')
            || ($variant === 'anonymous_callable' && ($identity['kind'] ?? null) !== 'function')) {
            $this->invalid('Node identity variant is incompatible with its record kind.');
        }
        $id = $this->nodeId((object) $identity);

        return $id;
    }

    /** @return array<string,mixed> */
    private function objectArray(mixed $value, string $label): array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }
        if (is_array($value)) {
            return $value;
        }
        $this->invalid($label.' must be an object');
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys, string $label): void
    {
        $actual = array_keys($value);
        sort($actual);
        $expected = $keys;
        sort($expected);
        if ($actual !== $expected) {
            $this->invalid($label.' must contain its exact closed identity fields');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private function requireKeys(array $value, array $keys, string $label): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $value)) {
                $this->invalid($label.' is missing a required identity field');
            }
        }
    }

    /** @return array<string,mixed> */
    private function normalizeForIdentity(array $value): array
    {
        $normalize = function (mixed $item, ?string $key = null) use (&$normalize): mixed {
            if (is_string($item) && in_array($key, ['path'], true)) {
                return str_replace('\\', '/', $item);
            }
            if (is_string($item) && in_array($key, ['structural_path', 'structural_pointer', 'ast_path'], true)) {
                return str_replace('\\', '/', $item);
            }
            if ($item instanceof stdClass) {
                $item = get_object_vars($item);
            }
            if (is_array($item)) {
                $result = [];
                foreach ($item as $childKey => $child) {
                    $result[$childKey] = $normalize($child, is_string($childKey) ? $childKey : null);
                }

                return $result;
            }

            return $item;
        };

        return $normalize($value);
    }

    private function prefixedId(mixed $id, string $prefix): string
    {
        if (! is_string($id) || ! str_starts_with($id, $prefix) || preg_match('/\A[0-9a-f]{64}\z/', substr($id, strlen($prefix))) !== 1) {
            $this->invalid('record ID has the wrong prefix or digest shape');
        }

        return $id;
    }

    private function invalid(string $message): never
    {
        throw new GraphV2ImportException('graph_validation_identity_invalid', $message);
    }
}
