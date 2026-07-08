<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesCausalPackService;
use App\Services\Hades\HadesSearchDocumentIndexer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CausalPackController extends Controller
{
    public function __construct(
        private readonly HadesCausalPackService $causalPacks,
        private readonly HadesSearchDocumentIndexer $searchIndexer,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'bug_report_id' => ['nullable', 'string'],
            'bug_id' => ['nullable', 'string', 'max:191'],
            'root_cause_id' => ['required', 'string', 'max:191'],
            'bug_class' => ['required', 'string', 'max:128'],
            'failure_classification' => ['required', 'string', 'max:128'],
            'affected_refs' => ['nullable', 'array'],
            'freshness' => ['nullable', 'array'],
            'awareness' => ['nullable', 'array'],
            'evidence_refs' => ['nullable', 'array'],
            'graph_refs' => ['nullable', 'array'],
            'source_slice_refs' => ['nullable', 'array'],
        ]);

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

        $pack = $this->causalPacks->create($agent, $binding, $validated);
        $this->searchIndexer->indexCausalPack($pack);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'causal_pack' => self::packPayload($pack),
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
            'root_cause_id' => ['nullable', 'string', 'max:191'],
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
        $indexedScores = $this->searchIndexer->matchingSourceScores($validated['project_id'], $binding->id, ['causal_packs'], $query, [], $limit, false);
        $indexedIds = array_keys($indexedScores);

        $items = DB::table('hades_causal_packs')
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->when(($validated['id'] ?? null) === null && $indexedIds !== [], fn ($builder) => $builder->whereIn('id', $indexedIds))
            ->when(($validated['id'] ?? null) !== null, fn ($builder) => $builder->where('id', $validated['id']))
            ->when(($validated['bug_report_id'] ?? null) !== null, fn ($builder) => $builder->where('bug_report_id', $validated['bug_report_id']))
            ->when(($validated['root_cause_id'] ?? null) !== null, fn ($builder) => $builder->where('root_cause_id', $validated['root_cause_id']))
            ->when($query !== '' && $indexedIds === [], function ($builder) use ($query, $tokens): void {
                $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                $builder->where(function ($nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $like = '%'.$pattern.'%';
                        $nested
                            ->orWhere('root_cause_id', 'like', $like)
                            ->orWhere('bug_class', 'like', $like)
                            ->orWhere('failure_classification', 'like', $like)
                            ->orWhere('affected_refs', 'like', $like)
                            ->orWhere('evidence_refs', 'like', $like)
                            ->orWhere('graph_refs', 'like', $like)
                            ->orWhere('source_slice_refs', 'like', $like);
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(50, $limit * 8))
            ->get()
            ->map(function (object $row) use ($indexedScores, $query, $tokens): array {
                $item = self::packPayload($row);
                $item['score'] = max($indexedScores[(string) $row->id] ?? 0, $this->score($query, $tokens, [
                    (string) $row->root_cause_id,
                    (string) $row->bug_class,
                    (string) $row->failure_classification,
                    (string) $row->affected_refs,
                    (string) $row->evidence_refs,
                    (string) $row->graph_refs,
                    (string) $row->source_slice_refs,
                ]));

                return $item;
            })
            ->values()
            ->all();

        usort($items, fn (array $a, array $b): int => (($b['score'] ?? 0) <=> ($a['score'] ?? 0)) ?: strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        $items = array_slice($items, 0, $limit);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'query' => $query,
            'count' => count($items),
            'items' => $items,
            'server_time' => now()->toISOString(),
        ]);
    }

    public function show(Request $request, string $causalPack): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $pack = $this->findPack($validated['project_id'], $binding->id, $causalPack);
        if (! $pack) {
            return $this->error('causal_pack_not_found', 'Hades causal evidence pack was not found.', Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'causal_pack' => self::packPayload($pack),
            'server_time' => now()->toISOString(),
        ]);
    }

    public function replay(Request $request, string $causalPack): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $pack = $this->findPack($validated['project_id'], $binding->id, $causalPack);
        if (! $pack) {
            return $this->error('causal_pack_not_found', 'Hades causal evidence pack was not found.', Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'causal_pack_id' => $pack->id,
            'replay' => $this->causalPacks->replay($pack),
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
            'pack_key' => $pack->pack_key,
            'bug_id' => $pack->bug_id,
            'root_cause_id' => $pack->root_cause_id,
            'bug_class' => $pack->bug_class,
            'failure_classification' => $pack->failure_classification,
            'affected_refs' => self::decode($pack->affected_refs),
            'freshness' => self::decode($pack->freshness),
            'awareness' => self::decode($pack->awareness),
            'evidence_refs' => self::decode($pack->evidence_refs),
            'graph_refs' => self::decode($pack->graph_refs),
            'source_slice_refs' => self::decode($pack->source_slice_refs),
            'replay' => self::decode($pack->replay),
            'status' => $pack->status,
            'blockers' => self::decode($pack->blockers),
            'created_at' => self::toIsoString($pack->created_at),
            'updated_at' => self::toIsoString($pack->updated_at),
            'version' => 'causal_pack_'.hash('sha256', $pack->id.'|'.$pack->updated_at.'|'.$pack->pack_key),
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

    private function findPack(string $projectId, string $bindingId, string $id): ?object
    {
        return DB::table('hades_causal_packs')
            ->where('id', $id)
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $bindingId)
            ->first();
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

    private function tokens(string $query): array
    {
        preg_match_all('/[A-Za-z0-9_.:\/-]{2,}/', Str::lower($query), $matches);

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

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
