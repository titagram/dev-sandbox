<?php

namespace App\Services\Hades;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HadesCausalPackService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(object $agent, object $binding, array $payload): object
    {
        $evidenceRefs = $this->normaliseRefs($payload['evidence_refs'] ?? []);
        $graphRefs = $this->normaliseRefs($payload['graph_refs'] ?? []);
        $sourceSliceRefs = $this->normaliseRefs($payload['source_slice_refs'] ?? []);
        $affectedRefs = $this->normaliseStrings($payload['affected_refs'] ?? []);
        $freshness = $this->normaliseMap($payload['freshness'] ?? []);
        $awareness = $this->normaliseMap($payload['awareness'] ?? []);
        $replay = ['required_refs' => array_values(array_merge($evidenceRefs, $graphRefs, $sourceSliceRefs))];
        $blockers = $this->validatePack(
            rootCauseId: (string) ($payload['root_cause_id'] ?? ''),
            bugClass: (string) ($payload['bug_class'] ?? ''),
            failureClassification: (string) ($payload['failure_classification'] ?? ''),
            freshness: $freshness,
            awareness: $awareness,
            evidenceRefs: $evidenceRefs,
            graphRefs: $graphRefs,
            sourceSliceRefs: $sourceSliceRefs,
        );
        $status = $blockers === [] ? 'valid' : 'invalid';
        $packKey = $this->packKey($binding, (string) ($payload['bug_id'] ?? ''), (string) ($payload['root_cause_id'] ?? ''), $freshness, $replay);
        $now = now();

        $existing = DB::table('hades_causal_packs')
            ->where('project_id', $binding->project_id)
            ->where('pack_key', $packKey)
            ->first();
        $id = $existing?->id ?? (string) Str::ulid();

        DB::table('hades_causal_packs')->updateOrInsert(
            ['project_id' => $binding->project_id, 'pack_key' => $packKey],
            [
                'id' => $id,
                'bug_report_id' => $payload['bug_report_id'] ?? null,
                'hades_agent_id' => $agent->id,
                'workspace_binding_id' => $binding->id,
                'bug_id' => $this->blankToNull((string) ($payload['bug_id'] ?? '')),
                'root_cause_id' => $this->compact((string) ($payload['root_cause_id'] ?? ''), 191),
                'bug_class' => $this->blankToNull($this->compact((string) ($payload['bug_class'] ?? ''), 128)),
                'failure_classification' => $this->blankToNull($this->compact((string) ($payload['failure_classification'] ?? ''), 128)),
                'affected_refs' => $affectedRefs !== [] ? json_encode($affectedRefs, JSON_THROW_ON_ERROR) : null,
                'freshness' => $freshness !== [] ? json_encode($freshness, JSON_THROW_ON_ERROR) : null,
                'awareness' => $awareness !== [] ? json_encode($awareness, JSON_THROW_ON_ERROR) : null,
                'evidence_refs' => $evidenceRefs !== [] ? json_encode($evidenceRefs, JSON_THROW_ON_ERROR) : null,
                'graph_refs' => $graphRefs !== [] ? json_encode($graphRefs, JSON_THROW_ON_ERROR) : null,
                'source_slice_refs' => $sourceSliceRefs !== [] ? json_encode($sourceSliceRefs, JSON_THROW_ON_ERROR) : null,
                'replay' => json_encode($replay, JSON_THROW_ON_ERROR),
                'status' => $status,
                'blockers' => $blockers !== [] ? json_encode($blockers, JSON_THROW_ON_ERROR) : null,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ],
        );

        return DB::table('hades_causal_packs')->where('id', $id)->first();
    }

    /**
     * @return array{replayable: bool, status: string, blockers: list<string>, missing_refs: list<mixed>, checked_refs: list<mixed>}
     */
    public function replay(object $pack): array
    {
        $blockers = $this->decodeList($pack->blockers ?? null);
        $requiredRefs = $this->normaliseRefs($this->decodeMap($pack->replay ?? null)['required_refs'] ?? []);
        $missing = [];

        foreach ($requiredRefs as $ref) {
            if (! $this->refExists((string) $pack->project_id, (string) $pack->workspace_binding_id, $ref)) {
                $missing[] = $ref;
            }
        }

        return [
            'replayable' => (string) $pack->status === 'valid' && $blockers === [] && $missing === [],
            'status' => (string) $pack->status,
            'blockers' => $blockers,
            'missing_refs' => $missing,
            'checked_refs' => $requiredRefs,
        ];
    }

    /**
     * @param  list<mixed>  $refs
     */
    public function hasReplayablePackForRefs(object $project, array $refs, ?string $rootCauseId = null): bool
    {
        $query = DB::table('hades_causal_packs')
            ->where('project_id', $project->id)
            ->where('status', 'valid');

        if ($rootCauseId !== null && trim($rootCauseId) !== '') {
            $query->where('root_cause_id', $rootCauseId);
        }

        foreach ($query->get() as $pack) {
            $replay = $this->replay($pack);
            if (! $replay['replayable']) {
                continue;
            }

            $packRefs = array_map(fn (mixed $ref): string => $this->refFingerprint($ref), $replay['checked_refs']);
            $requestedRefs = array_map(fn (mixed $ref): string => $this->refFingerprint($ref), $refs);
            if ($requestedRefs === [] || array_diff($requestedRefs, $packRefs) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @param  array<string, mixed>  $awareness
     * @param  list<mixed>  $evidenceRefs
     * @param  list<mixed>  $graphRefs
     * @param  list<mixed>  $sourceSliceRefs
     * @return list<string>
     */
    private function validatePack(string $rootCauseId, string $bugClass, string $failureClassification, array $freshness, array $awareness, array $evidenceRefs, array $graphRefs, array $sourceSliceRefs): array
    {
        $blockers = [];
        if (($freshness['status'] ?? null) !== 'current') {
            $blockers[] = 'freshness_not_current';
        }
        if (($awareness['diagnosable_without_source'] ?? false) !== true) {
            $blockers[] = 'awareness_not_diagnosable';
        }
        if ($evidenceRefs === []) {
            $blockers[] = 'evidence_refs_required';
        }
        if ($graphRefs === []) {
            $blockers[] = 'graph_refs_required';
        }
        if ($sourceSliceRefs === []) {
            $blockers[] = 'source_slice_refs_required';
        }
        foreach ([
            'root_cause_id' => $rootCauseId,
            'bug_class' => $bugClass,
            'failure_classification' => $failureClassification,
        ] as $field => $value) {
            if (trim($value) === '') {
                $blockers[] = $field.'_required';
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $freshness
     * @param  array<string, mixed>  $replay
     */
    private function packKey(object $binding, string $bugId, string $rootCauseId, array $freshness, array $replay): string
    {
        $required = $replay['required_refs'] ?? [];
        $fingerprints = array_map(fn (mixed $ref): string => $this->refFingerprint($ref), is_array($required) ? $required : []);
        sort($fingerprints);
        $material = json_encode([
            'project_id' => (string) $binding->project_id,
            'binding_id' => (string) $binding->id,
            'bug_id' => $bugId,
            'root_cause_id' => $rootCauseId,
            'head_commit' => (string) ($freshness['head_commit'] ?? $freshness['workspace_head_commit'] ?? ''),
            'required_refs' => $fingerprints,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $material);
    }

    private function refExists(string $projectId, string $bindingId, mixed $ref): bool
    {
        if (is_string($ref)) {
            return trim($ref) !== '';
        }
        if (! is_array($ref)) {
            return false;
        }

        $type = Str::lower(trim((string) ($ref['type'] ?? '')));
        $id = trim((string) ($ref['id'] ?? ''));
        if ($type === '' || $id === '') {
            return false;
        }

        return match ($type) {
            'bug_evidence' => DB::table('hades_bug_evidence_items')->where('id', $id)->where('project_id', $projectId)->where('workspace_binding_id', $bindingId)->exists(),
            'source_slice' => DB::table('hades_source_slices')->where('id', $id)->where('project_id', $projectId)->where('workspace_binding_id', $bindingId)->exists(),
            'artifact' => DB::table('hades_agent_artifacts')->where('id', $id)->where('project_id', $projectId)->where('workspace_binding_id', $bindingId)->exists(),
            'evidence_pack' => DB::table('hades_evidence_packs')->where('id', $id)->where('project_id', $projectId)->where('workspace_binding_id', $bindingId)->exists(),
            default => true,
        };
    }

    /**
     * @return list<mixed>
     */
    private function normaliseRefs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $refs = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $refs[] = $this->compact(trim($item), 500);
            } elseif (is_array($item)) {
                $clean = [];
                foreach ($item as $key => $inner) {
                    if (is_scalar($inner) && trim((string) $inner) !== '') {
                        $clean[(string) $key] = $this->compact(trim((string) $inner), 500);
                    }
                }
                if ($clean !== []) {
                    ksort($clean);
                    $refs[] = $clean;
                }
            }
            if (count($refs) >= 100) {
                break;
            }
        }

        return $refs;
    }

    /**
     * @return list<string>
     */
    private function normaliseStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $items[] = $this->compact($text, 500);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseMap(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMap(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private function refFingerprint(mixed $ref): string
    {
        if (is_array($ref)) {
            ksort($ref);

            return json_encode($ref, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return (string) $ref;
    }

    private function compact(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if (strlen($value) <= $max) {
            return $value;
        }

        return rtrim(substr($value, 0, $max - 3)).'...';
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
