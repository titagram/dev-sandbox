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

class BugEvidenceController extends Controller
{
    private const EVIDENCE_KINDS = [
        'stack_trace',
        'log_excerpt',
        'failing_test',
        'http_request',
        'http_response',
        'browser_console',
        'deploy_version',
        'config_snapshot',
        'user_steps',
        'screenshot_ref',
        'other',
    ];

    private const RETENTION_CLASSES = [
        'runtime_evidence',
        'stack_trace',
        'test_failure',
        'http_trace',
        'log_excerpt',
        'diagnosis_evidence',
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
            'kind' => ['required', 'string', Rule::in(self::EVIDENCE_KINDS)],
            'summary' => ['required', 'string', 'max:4000'],
            'payload' => ['required', 'array'],
            'source' => ['nullable', 'string', 'max:512'],
            'sha256' => ['nullable', 'string', 'size:64'],
            'redactions' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'retention_class' => ['nullable', 'string', Rule::in(self::RETENTION_CLASSES)],
            'occurred_at' => ['nullable', 'date'],
        ]);

        if ($policyError = $this->policy->validateBugEvidence($validated['summary'], $validated['payload'])) {
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

        $payloadJson = json_encode($validated['payload'], JSON_THROW_ON_ERROR);
        $id = (string) Str::ulid();
        $now = now();
        DB::table('hades_bug_evidence_items')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'bug_report_id' => $validated['bug_report_id'] ?? null,
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'kind' => $validated['kind'],
            'summary' => $validated['summary'],
            'payload' => $payloadJson,
            'source' => $validated['source'] ?? null,
            'sha256' => $validated['sha256'] ?? hash('sha256', $payloadJson),
            'redactions' => (int) ($validated['redactions'] ?? 0),
            'retention_class' => $validated['retention_class'] ?? 'runtime_evidence',
            'occurred_at' => isset($validated['occurred_at']) ? Carbon::parse($validated['occurred_at']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $item = DB::table('hades_bug_evidence_items')->where('id', $id)->first();
        $this->searchIndexer->indexBugEvidence($item);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'evidence' => self::evidencePayload($item),
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'query' => ['nullable', 'string', 'max:1000'],
            'kind' => ['nullable', 'string', Rule::in(self::EVIDENCE_KINDS)],
            'bug_report_id' => ['nullable', 'string'],
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
        $searchFilters = [];
        if (($validated['kind'] ?? null) !== null) {
            $searchFilters['kind'] = $validated['kind'];
        }
        $indexedEvidenceScores = $this->searchIndexer->matchingSourceScores($validated['project_id'], $binding->id, ['bug_evidence'], $query, $searchFilters, $limit, false);
        $indexedEvidenceIds = array_keys($indexedEvidenceScores);

        $rows = DB::table('hades_bug_evidence_items')
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->when($indexedEvidenceIds !== [], fn ($builder) => $builder->whereIn('id', $indexedEvidenceIds))
            ->when(($validated['kind'] ?? null) !== null, fn ($builder) => $builder->where('kind', $validated['kind']))
            ->when(($validated['bug_report_id'] ?? null) !== null, fn ($builder) => $builder->where('bug_report_id', $validated['bug_report_id']))
            ->when($query !== '' && $indexedEvidenceIds === [], function ($builder) use ($query, $tokens): void {
                $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                $builder->where(function ($nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $like = '%'.$pattern.'%';
                        $nested
                            ->orWhere('summary', 'like', $like)
                            ->orWhere('payload', 'like', $like)
                            ->orWhere('kind', 'like', $like)
                            ->orWhere('source', 'like', $like);
                    }
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(max(50, $limit * 8))
            ->get()
            ->map(function (object $row) use ($indexedEvidenceScores, $query, $tokens): array {
                $item = self::evidencePayload($row);
                $payload = json_encode($item['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
                $item['score'] = max($indexedEvidenceScores[(string) $row->id] ?? 0, $this->score($query, $tokens, [
                    (string) $row->summary,
                    (string) $row->kind,
                    (string) $row->source,
                    $payload,
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

            return strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
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
            'kind' => $validated['kind'] ?? null,
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

    public static function evidencePayload(object $item): array
    {
        return [
            'id' => $item->id,
            'project_id' => $item->project_id,
            'workspace_binding_id' => $item->workspace_binding_id,
            'bug_report_id' => $item->bug_report_id,
            'kind' => $item->kind,
            'summary' => $item->summary,
            'payload' => self::decode($item->payload),
            'source' => $item->source,
            'sha256' => $item->sha256,
            'redactions' => (int) $item->redactions,
            'retention_class' => $item->retention_class,
            'occurred_at' => self::toIsoString($item->occurred_at),
            'created_at' => self::toIsoString($item->created_at),
            'updated_at' => self::toIsoString($item->updated_at),
            'version' => 'bug_evidence_'.hash('sha256', $item->id.'|'.$item->updated_at.'|'.$item->sha256),
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

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function searchVersion(string $projectId, string $bindingId, string $query, array $items): string
    {
        $material = [$projectId, $bindingId, $query, (string) count($items)];
        foreach ($items as $item) {
            $material[] = (string) ($item['id'] ?? '').':'.(string) ($item['version'] ?? '');
        }

        return 'bug_evidence_search_'.hash('sha256', implode('|', $material));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
