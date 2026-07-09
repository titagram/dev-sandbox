<?php

namespace App\Assistants;

final class BehaviorWikiDraftService
{
    /**
     * @param  string  $symbolId  The fully qualified symbol identifier (e.g. App\Services\InvoiceService).
     * @param  array<string, mixed>  $graphContext  Graph query results for the symbol.
     * @param  list<array<string, string>>  $evidenceRefs  Evidence references backing the draft.
     * @return array{symbol_id: string, summary: string, preconditions: list<string>, side_effects: list<string>, evidence_refs: list<array<string, string>>, source_status: string, symbol_name: string, symbol_labels: list<string>, created_at: string}
     */
    public function draftFromGraphContext(string $symbolId, array $graphContext, array $evidenceRefs = []): array
    {
        $labels = $this->safeStringList($graphContext['labels'] ?? []);
        $name = trim((string) ($graphContext['name'] ?? class_basename($symbolId))) ?: $symbolId;
        $path = $graphContext['path'] ?? null;
        $properties = is_array($graphContext['properties'] ?? null) ? $graphContext['properties'] : [];
        $callees = $this->safeStringList($graphContext['callees'] ?? []);
        $callers = $this->safeStringList($graphContext['callers'] ?? []);
        $relationships = is_array($graphContext['relationships'] ?? null) ? $graphContext['relationships'] : [];

        $summary = $this->buildSummary($symbolId, $name, $labels, $callers, $callees, $relationships, $path);
        $preconditions = $this->buildPreconditions($symbolId, $labels, $properties, $callers);
        $sideEffects = $this->buildSideEffects($symbolId, $labels, $callees, $relationships);

        return [
            'symbol_id' => $symbolId,
            'symbol_name' => $name,
            'symbol_labels' => $labels,
            'summary' => $summary,
            'preconditions' => $preconditions,
            'side_effects' => $sideEffects,
            'evidence_refs' => $this->normalizeEvidenceRefs($evidenceRefs),
            'source_status' => 'needs_verification',
            'created_at' => now()->toISOString(),
        ];
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $callers
     * @param  list<string>  $callees
     * @param  list<array<string, mixed>>  $relationships
     */
    private function buildSummary(string $symbolId, string $name, array $labels, array $callers, array $callees, array $relationships, ?string $path): string
    {
        $labelSummary = $labels === [] ? 'symbol' : implode(', ', $labels);
        $parts = ["{$name} is a {$labelSummary}"];

        if ($path !== null) {
            $parts[] = "located at {$path}";
        }

        if ($callers !== []) {
            $parts[] = 'called by ' . $this->listPrefix($callers);
        }

        if ($callees !== []) {
            $parts[] = 'calls ' . $this->listPrefix($callees);
        }

        if ($relationships !== []) {
            $parts[] = $this->relationshipSummary($relationships);
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * @param  list<string>  $labels
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $callers
     * @return list<string>
     */
    private function buildPreconditions(string $symbolId, array $labels, array $properties, array $callers): array
    {
        $preconditions = [];

        if (in_array('Class', $labels, true) || in_array('Interface', $labels, true)) {
            $preconditions[] = "The class {$symbolId} must be autoloadable by the application.";
        }

        if (in_array('Controller', $labels, true)) {
            $preconditions[] = "The controller {$symbolId} must be registered in the application route map.";
        }

        if (in_array('Service', $labels, true)) {
            $preconditions[] = "The service {$symbolId} must be registered and resolvable through the service container.";
        }

        if ($callers !== []) {
            $preconditions[] = 'Inbound callers ' . $this->listPrefix($callers) . ' must provide valid arguments matching the expected signature.';
        }

        if (isset($properties['visibility']) && $properties['visibility'] === 'public') {
            $preconditions[] = 'Callers must respect the method visibility contract.';
        }

        if ($preconditions === []) {
            $preconditions[] = "Preconditions for {$symbolId} could not be determined from available graph evidence.";
        }

        return $preconditions;
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $callees
     * @param  list<array<string, mixed>>  $relationships
     * @return list<string>
     */
    private function buildSideEffects(string $symbolId, array $labels, array $callees, array $relationships): array
    {
        $sideEffects = [];

        if ($callees !== []) {
            $sideEffects[] = "Invocation of {$symbolId} may trigger downstream calls to " . $this->listPrefix($callees) . '.';
        }

        foreach ($relationships as $relationship) {
            $relType = (string) ($relationship['type'] ?? 'RELATED');
            $target = (string) ($relationship['to'] ?? '');
            if ($target !== '') {
                $sideEffects[] = "Graph evidence records a {$relType} relationship to {$target}.";
            }
        }

        if (in_array('Controller', $labels, true)) {
            $sideEffects[] = "Executing {$symbolId} may produce an HTTP response and trigger middleware side effects.";
        }

        if (in_array('Event', $labels, true) || in_array('Listener', $labels, true)) {
            $sideEffects[] = "Dispatching {$symbolId} may invoke registered listeners and trigger observable state changes.";
        }

        if ($sideEffects === []) {
            $sideEffects[] = "Side effects for {$symbolId} could not be determined from available graph evidence.";
        }

        return $sideEffects;
    }

    /**
     * @param  list<string>  $items
     */
    private function listPrefix(array $items): string
    {
        $prefix = count($items) > 3
            ? implode(', ', array_slice($items, 0, 3)) . ' and ' . (count($items) - 3) . ' more'
            : implode(', ', $items);

        return $prefix;
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     */
    private function relationshipSummary(array $relationships): string
    {
        $byType = [];
        foreach ($relationships as $relationship) {
            $type = (string) ($relationship['type'] ?? 'RELATED');
            $to = (string) ($relationship['to'] ?? '');
            if ($to !== '') {
                $byType[$type][] = $to;
            }
        }

        $summaries = [];
        foreach ($byType as $type => $targets) {
            $summaries[] = "{$type} " . $this->listPrefix($targets);
        }

        return 'Holds relationship(s): ' . implode('; ', $summaries);
    }

    /**
     * @param  list<array<string, string>>  $refs
     * @return list<array<string, string>>
     */
    private function normalizeEvidenceRefs(array $refs): array
    {
        $normalized = [];

        foreach ($refs as $ref) {
            if (! is_array($ref) || ! isset($ref['type'], $ref['id'])) {
                continue;
            }

            $normalized[] = [
                'type' => (string) $ref['type'],
                'id' => (string) $ref['id'],
                'source_status' => (string) ($ref['source_status'] ?? 'needs_verification'),
            ];
        }

        return $normalized;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function safeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value,
        )));
    }
}
