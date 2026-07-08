<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesEvidencePolicy;
use App\Services\Hades\HadesProjectAwareness;
use App\Services\Hades\HadesSearchDocumentIndexer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class EvidencePackController extends Controller
{
    private const RETENTION_CLASSES = [
        'diagnosis_evidence',
        'evidence_pack',
        'runtime_evidence',
        'source_reference',
    ];

    public function __construct(
        private readonly HadesProjectAwareness $awareness,
        private readonly HadesEvidencePolicy $policy,
        private readonly HadesSearchDocumentIndexer $searchIndexer,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'bug_report_id' => ['nullable', 'string'],
            'title' => ['required', 'string', 'max:512'],
            'summary' => ['required', 'string', 'max:4000'],
            'evidence_refs' => ['nullable', 'array'],
            'graph_refs' => ['nullable', 'array'],
            'source_slice_ids' => ['nullable', 'array'],
            'source_slice_ids.*' => ['string'],
            'payload' => ['nullable', 'array'],
            'redactions' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'retention_class' => ['nullable', 'string', Rule::in(self::RETENTION_CLASSES)],
            'head_commit' => ['nullable', 'string', 'max:80'],
        ]);

        if ($policyError = $this->policy->validateEvidencePack(
            $validated['title'],
            $validated['summary'],
            $validated['evidence_refs'] ?? [],
            $validated['graph_refs'] ?? [],
            $validated['source_slice_ids'] ?? [],
            $validated['payload'] ?? [],
        )) {
            return $this->error($policyError['code'], $policyError['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        if (($validated['bug_report_id'] ?? null) !== null) {
            $exists = DB::table('hades_bug_reports')
                ->where('id', $validated['bug_report_id'])
                ->where('project_id', $validated['project_id'])
                ->where('workspace_binding_id', $binding->id)
                ->exists();

            if (! $exists) {
                return $this->error('bug_report_not_found', 'Hades bug report was not found.', Response::HTTP_NOT_FOUND);
            }
        }

        $sourceSliceIds = $this->normaliseStringList($validated['source_slice_ids'] ?? []);
        if ($sourceSliceIds !== []) {
            $found = DB::table('hades_source_slices')
                ->where('project_id', $validated['project_id'])
                ->where('workspace_binding_id', $binding->id)
                ->whereIn('id', $sourceSliceIds)
                ->pluck('id')
                ->all();
            $missing = array_values(array_diff($sourceSliceIds, array_map('strval', $found)));

            if ($missing !== []) {
                return $this->error('source_slice_not_found', 'One or more source slices were not found for this workspace.', Response::HTTP_NOT_FOUND);
            }
        }

        $packMaterial = [
            'title' => $validated['title'],
            'summary' => $validated['summary'],
            'evidence_refs' => $validated['evidence_refs'] ?? [],
            'graph_refs' => $validated['graph_refs'] ?? [],
            'source_slice_ids' => $sourceSliceIds,
            'payload' => $validated['payload'] ?? [],
            'head_commit' => $validated['head_commit'] ?? null,
        ];
        $packJson = json_encode($packMaterial, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $id = (string) Str::ulid();
        $now = now();

        DB::table('hades_evidence_packs')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'bug_report_id' => $validated['bug_report_id'] ?? null,
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'title' => $validated['title'],
            'summary' => $validated['summary'],
            'evidence_refs' => isset($validated['evidence_refs']) ? json_encode($validated['evidence_refs'], JSON_THROW_ON_ERROR) : null,
            'graph_refs' => isset($validated['graph_refs']) ? json_encode($validated['graph_refs'], JSON_THROW_ON_ERROR) : null,
            'source_slice_ids' => $sourceSliceIds !== [] ? json_encode($sourceSliceIds, JSON_THROW_ON_ERROR) : null,
            'payload' => isset($validated['payload']) ? json_encode($validated['payload'], JSON_THROW_ON_ERROR) : null,
            'sha256' => hash('sha256', $packJson),
            'redactions' => (int) ($validated['redactions'] ?? 0),
            'retention_class' => $validated['retention_class'] ?? 'diagnosis_evidence',
            'head_commit' => $validated['head_commit'] ?? $binding->head_commit,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pack = DB::table('hades_evidence_packs')->where('id', $id)->first();
        $this->searchIndexer->indexEvidencePack($pack);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'evidence_pack' => self::packPayload($pack),
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'id' => ['nullable', 'string'],
            'bug_report_id' => ['nullable', 'string'],
            'query' => ['nullable', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $query = trim((string) ($validated['query'] ?? ''));
        $tokens = $this->tokens($query);
        $limit = (int) ($validated['limit'] ?? 10);
        $indexedPackScores = $this->searchIndexer->matchingSourceScores($validated['project_id'], $binding->id, ['evidence_packs'], $query, [], $limit, false);
        $indexedPackIds = array_keys($indexedPackScores);

        $rows = DB::table('hades_evidence_packs')
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->when(($validated['id'] ?? null) === null && $indexedPackIds !== [], fn ($builder) => $builder->whereIn('id', $indexedPackIds))
            ->when(($validated['id'] ?? null) !== null, fn ($builder) => $builder->where('id', $validated['id']))
            ->when(($validated['bug_report_id'] ?? null) !== null, fn ($builder) => $builder->where('bug_report_id', $validated['bug_report_id']))
            ->when($query !== '' && $indexedPackIds === [], function ($builder) use ($query, $tokens): void {
                $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                $builder->where(function ($nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $like = '%'.$pattern.'%';
                        $nested
                            ->orWhere('title', 'like', $like)
                            ->orWhere('summary', 'like', $like)
                            ->orWhere('evidence_refs', 'like', $like)
                            ->orWhere('graph_refs', 'like', $like)
                            ->orWhere('payload', 'like', $like);
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(50, $limit * 8))
            ->get()
            ->map(function (object $row) use ($indexedPackScores, $query, $tokens): array {
                $item = self::packPayload($row);
                $item['score'] = max($indexedPackScores[(string) $row->id] ?? 0, $this->score($query, $tokens, [
                    (string) $row->title,
                    (string) $row->summary,
                    (string) $row->evidence_refs,
                    (string) $row->graph_refs,
                    (string) $row->payload,
                ]));

                return $item;
            })
            ->values()
            ->all();

        usort($rows, function (array $a, array $b): int {
            $score = ($b['score'] <=> $a['score']);

            if ($score !== 0) {
                return $score;
            }

            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });

        $candidateCount = count($rows);
        $items = array_slice($rows, 0, $limit);
        $version = $this->searchVersion($validated['project_id'], $binding->id, $query, $items);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'version' => $version,
            'etag' => $version,
            'query' => $query,
            'bug_report_id' => $validated['bug_report_id'] ?? null,
            'limit' => $limit,
            'count' => count($items),
            'candidate_count' => $candidateCount,
            'truncated' => $candidateCount > count($items),
            'freshness' => $this->awareness->freshnessForBinding($binding),
            'items' => array_values($items),
            'server_time' => now()->toISOString(),
        ]);
    }

    public static function packPayload(object $pack): array
    {
        return [
            'id' => $pack->id,
            'project_id' => $pack->project_id,
            'workspace_binding_id' => $pack->workspace_binding_id,
            'bug_report_id' => $pack->bug_report_id,
            'title' => $pack->title,
            'summary' => $pack->summary,
            'evidence_refs' => self::decode($pack->evidence_refs),
            'graph_refs' => self::decode($pack->graph_refs),
            'source_slice_ids' => self::decode($pack->source_slice_ids),
            'payload' => self::decode($pack->payload),
            'sha256' => $pack->sha256,
            'redactions' => (int) $pack->redactions,
            'retention_class' => $pack->retention_class,
            'head_commit' => $pack->head_commit,
            'created_at' => self::toIsoString($pack->created_at),
            'updated_at' => self::toIsoString($pack->updated_at),
            'version' => 'evidence_pack_'.hash('sha256', $pack->id.'|'.$pack->updated_at.'|'.$pack->sha256),
        ];
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        $binding = DB::table('hades_workspace_bindings')
            ->where('id', $bindingId)
            ->where('project_id', $projectId)
            ->where('hades_agent_id', $agent->id)
            ->first();

        if (! $binding) {
            return $this->error('workspace_binding_not_found', 'Workspace binding was not found.', Response::HTTP_NOT_FOUND);
        }

        if ($binding->status !== 'linked') {
            return $this->error('workspace_binding_unlinked', 'Workspace binding is not linked.', Response::HTTP_CONFLICT);
        }

        return $binding;
    }

    /**
     * @return list<string>
     */
    private function normaliseStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value,
        ))));
    }

    private static function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    private static function toIsoString(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        preg_match_all('/[A-Za-z0-9_.:\\/-]{2,}/', Str::lower($query), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $fields
     */
    private function score(string $query, array $tokens, array $fields): int
    {
        if ($query === '') {
            return 1;
        }

        $haystack = Str::lower(implode(' ', $fields));
        $score = Str::contains($haystack, Str::lower($query)) ? 50 : 0;

        foreach ($tokens as $token) {
            if (Str::contains($haystack, $token)) {
                $score += max(1, strlen($token));
            }
        }

        return $score;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function searchVersion(string $projectId, string $bindingId, string $query, array $items): string
    {
        $material = [$projectId, $bindingId, $query, (string) count($items)];
        foreach ($items as $item) {
            $material[] = (string) ($item['id'] ?? '').':'.(string) ($item['version'] ?? '');
        }

        return 'evidence_pack_search_'.hash('sha256', implode('|', $material));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
