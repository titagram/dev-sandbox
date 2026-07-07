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

class SourceSliceController extends Controller
{
    private const RETENTION_CLASSES = [
        'source_slice',
        'source_reference',
        'diagnosis_evidence',
    ];

    private const MAX_LINE_WINDOW = 400;

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
            'agent_id' => ['nullable', 'string', 'max:191'],
            'job_id' => ['nullable', 'string'],
            'path' => ['required', 'string', 'max:1024'],
            'start_line' => ['required', 'integer', 'min:1', 'max:10000000'],
            'end_line' => ['required', 'integer', 'min:1', 'gte:start_line', 'max:10000000'],
            'language' => ['nullable', 'string', 'max:64'],
            'symbol' => ['nullable', 'string', 'max:512'],
            'head_commit' => ['nullable', 'string', 'max:80'],
            'sha256' => ['nullable', 'string', 'size:64'],
            'content_redacted' => ['required', 'string', 'max:200000'],
            'redactions' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'truncated' => ['nullable', 'boolean'],
            'retention_class' => ['nullable', 'string', Rule::in(self::RETENTION_CLASSES)],
            'policy' => ['nullable', 'string', 'max:191'],
        ]);

        if (((int) $validated['end_line'] - (int) $validated['start_line'] + 1) > self::MAX_LINE_WINDOW) {
            return $this->error('source_slice_window_too_large', 'Source slice line window is too large.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($policyError = $this->policy->validateSourceSlice(
            $validated['path'],
            (string) $validated['content_redacted'],
            (int) ($validated['redactions'] ?? 0),
        )) {
            return $this->error($policyError['code'], $policyError['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id'], $validated['agent_id'] ?? null);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        if (($validated['job_id'] ?? null) !== null) {
            $jobExists = DB::table('hades_agent_jobs')
                ->where('id', $validated['job_id'])
                ->where('project_id', $validated['project_id'])
                ->where('workspace_binding_id', $binding->id)
                ->exists();

            if (! $jobExists) {
                return $this->error('job_not_found', 'Hades agent job was not found.', Response::HTTP_NOT_FOUND);
            }
        }

        $id = (string) Str::ulid();
        $now = now();
        $content = (string) $validated['content_redacted'];

        DB::table('hades_source_slices')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'job_id' => $validated['job_id'] ?? null,
            'path' => $validated['path'],
            'start_line' => (int) $validated['start_line'],
            'end_line' => (int) $validated['end_line'],
            'language' => $validated['language'] ?? null,
            'symbol' => $validated['symbol'] ?? null,
            'head_commit' => $validated['head_commit'] ?? null,
            'sha256' => $validated['sha256'] ?? hash('sha256', $content),
            'content_redacted' => $content,
            'redactions' => (int) ($validated['redactions'] ?? 0),
            'truncated' => (bool) ($validated['truncated'] ?? false),
            'retention_class' => $validated['retention_class'] ?? 'source_slice',
            'policy' => $validated['policy'] ?? 'manual_review',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $slice = DB::table('hades_source_slices')->where('id', $id)->first();
        $this->searchIndexer->indexSourceSlice($slice);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'workspace_binding_id' => $binding->id,
            'source_slice' => self::slicePayload($slice),
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'workspace_binding_id' => ['required', 'string'],
            'id' => ['nullable', 'string'],
            'query' => ['nullable', 'string', 'max:1000'],
            'path' => ['nullable', 'string', 'max:1024'],
            'symbol' => ['nullable', 'string', 'max:512'],
            'line' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id']);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $query = trim((string) ($validated['query'] ?? ''));
        $tokens = $this->tokens($query);
        $limit = (int) ($validated['limit'] ?? 5);
        $searchFilters = array_filter([
            'path' => trim((string) ($validated['path'] ?? '')),
            'symbol' => trim((string) ($validated['symbol'] ?? '')),
        ], fn (string $value): bool => $value !== '');
        $indexedSliceIds = $this->searchIndexer->matchingSourceIds($validated['project_id'], $binding->id, ['source_slices'], $query, $searchFilters, $limit, false);

        $rows = DB::table('hades_source_slices')
            ->where('project_id', $validated['project_id'])
            ->where('workspace_binding_id', $binding->id)
            ->when(($validated['id'] ?? null) === null && $indexedSliceIds !== [], fn ($builder) => $builder->whereIn('id', $indexedSliceIds))
            ->when(($validated['id'] ?? null) !== null, fn ($builder) => $builder->where('id', $validated['id']))
            ->when(($validated['path'] ?? null) !== null, fn ($builder) => $builder->where('path', $validated['path']))
            ->when(($validated['symbol'] ?? null) !== null, fn ($builder) => $builder->where('symbol', $validated['symbol']))
            ->when(($validated['line'] ?? null) !== null, function ($builder) use ($validated): void {
                $line = (int) $validated['line'];
                $builder->where('start_line', '<=', $line)->where('end_line', '>=', $line);
            })
            ->when($query !== '' && $indexedSliceIds === [], function ($builder) use ($query, $tokens): void {
                $patterns = array_values(array_unique(array_filter(array_merge([$query], $tokens))));
                $builder->where(function ($nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $like = '%'.$pattern.'%';
                        $nested
                            ->orWhere('path', 'like', $like)
                            ->orWhere('symbol', 'like', $like)
                            ->orWhere('language', 'like', $like)
                            ->orWhere('content_redacted', 'like', $like);
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(20, $limit * 4))
            ->get()
            ->map(function (object $row) use ($query, $tokens): array {
                $item = self::slicePayload($row);
                $item['score'] = $this->score($query, $tokens, [
                    (string) $row->path,
                    (string) $row->symbol,
                    (string) $row->language,
                    (string) $row->content_redacted,
                ]);

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
            'path' => $validated['path'] ?? null,
            'symbol' => $validated['symbol'] ?? null,
            'line' => $validated['line'] ?? null,
            'limit' => $limit,
            'count' => count($items),
            'candidate_count' => $candidateCount,
            'truncated' => $candidateCount > count($items),
            'freshness' => $this->awareness->freshnessForBinding($binding),
            'items' => array_values($items),
            'server_time' => now()->toISOString(),
        ]);
    }

    public static function slicePayload(object $slice): array
    {
        return [
            'id' => $slice->id,
            'project_id' => $slice->project_id,
            'workspace_binding_id' => $slice->workspace_binding_id,
            'job_id' => $slice->job_id,
            'path' => $slice->path,
            'start_line' => (int) $slice->start_line,
            'end_line' => (int) $slice->end_line,
            'language' => $slice->language,
            'symbol' => $slice->symbol,
            'head_commit' => $slice->head_commit,
            'sha256' => $slice->sha256,
            'content_redacted' => $slice->content_redacted,
            'redactions' => (int) $slice->redactions,
            'truncated' => (bool) $slice->truncated,
            'retention_class' => $slice->retention_class,
            'policy' => $slice->policy,
            'created_at' => self::toIsoString($slice->created_at),
            'updated_at' => self::toIsoString($slice->updated_at),
            'version' => 'source_slice_'.hash('sha256', $slice->id.'|'.$slice->updated_at.'|'.$slice->sha256),
        ];
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId, ?string $externalAgentId = null): mixed
    {
        if ($agent->project_id !== $projectId) {
            return $this->error('project_mismatch', 'Hades agent token is scoped to a different project.', Response::HTTP_FORBIDDEN);
        }

        if ($externalAgentId !== null && $externalAgentId !== $agent->external_agent_id) {
            return $this->error('agent_mismatch', 'Hades agent token is scoped to a different external agent.', Response::HTTP_FORBIDDEN);
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
    private function tokens(string $query): array
    {
        preg_match_all('/[A-Za-z0-9_.:\/-]{2,}/', $query, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $haystacks
     */
    private function score(string $query, array $tokens, array $haystacks): int
    {
        $query = Str::lower(trim($query));
        $summary = Str::lower($haystacks[0] ?? '');
        $joined = Str::lower(implode(PHP_EOL, $haystacks));
        $score = 0;

        if ($query !== '' && str_contains($summary, $query)) {
            $score += 40;
        } elseif ($query !== '' && str_contains($joined, $query)) {
            $score += 20;
        }

        foreach ($tokens as $token) {
            if (str_contains($summary, $token)) {
                $score += 4;
            } elseif (str_contains($joined, $token)) {
                $score += 2;
            }
        }

        return $score;
    }

    private static function toIsoString(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    private function searchVersion(string $projectId, string $bindingId, string $query, array $items): string
    {
        return 'source_slice_search_'.hash('sha256', json_encode([
            'project_id' => $projectId,
            'workspace_binding_id' => $bindingId,
            'query' => $query,
            'ids' => array_map(fn (array $item): string => (string) $item['id'], $items),
            'versions' => array_map(fn (array $item): string => (string) $item['version'], $items),
        ], JSON_THROW_ON_ERROR));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
