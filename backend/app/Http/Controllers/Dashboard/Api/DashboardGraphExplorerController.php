<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\Graph\DashboardGraphExplorerService;
use App\Services\Graph\DashboardGraphPublicHandle;
use App\Services\Graph\DashboardGraphPublicKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class DashboardGraphExplorerController extends Controller
{
    use ChecksDashboardRoles;

    private const TYPES = [
        'scopes',
        'overview',
        'search',
        'detail',
        'neighborhood',
        'path',
        'impact',
    ];

    private const SCOPE_TYPES = ['repository', 'workspace_binding'];

    private const FAMILIES = ['call', 'dependency', 'route', 'test', 'table'];

    private const EDGE_FAMILIES = [
        'CALLS' => 'call',
        'CALLS_METHOD' => 'call',
        'STATIC_CALL' => 'call',
        'USES_DEPENDENCY' => 'dependency',
        'INSTANTIATES' => 'dependency',
        'EXTENDS' => 'dependency',
        'USES_FORM_REQUEST' => 'dependency',
        'THROWS_EXCEPTION' => 'dependency',
        'API_RESOURCE_REF' => 'dependency',
        'ROUTE_HANDLER' => 'route',
        'TEST_COVERS_SYMBOL' => 'test',
        'TEST_IMPORTS' => 'test',
        'TEST_COVERS_ROUTE' => 'test',
        'QUERY_TABLE' => 'table',
        'ELOQUENT_QUERY' => 'table',
    ];

    private const REASONS = [
        'graph_projection_not_ready',
        'graph_projection_rebuild_required',
        'graph_scope_not_found',
        'node_not_found',
        'path_not_found',
        'scope_required',
        'scope_not_found',
        'invalid_handle',
        'invalid_cursor',
        'invalid_query',
        'invalid_family',
        'invalid_direction',
        'validation_failed',
        'query_error',
        'exact_match_not_indexed_capacity',
        'exact_match_not_found',
    ];

    private const ALLOWED_FIELDS = [
        'type',
        'scope_type',
        'scope_id',
        'query',
        'node_handle',
        'from_handle',
        'to_handle',
        'direction',
        'families',
        'max_depth',
        'limit',
        'cursor',
    ];

    private readonly DashboardGraphExplorerService $explorer;

    private readonly DashboardGraphPublicKind $publicKinds;

    public function __construct(
        DashboardGraphExplorerService $explorer,
        ?DashboardGraphPublicKind $publicKinds = null,
    ) {
        $this->explorer = $explorer;
        $this->publicKinds = $publicKinds ?? new DashboardGraphPublicKind;
    }

    public function query(Request $request, string $project): JsonResponse
    {
        $this->abortUnlessDashboardReader($request);
        try {
            $this->assertNoPluginHeaders($request);
            $this->assertKnownRequestFields($request);
            $validated = $this->validated($request);
        } catch (ValidationException $exception) {
            $type = $request->input('type');
            $type = is_string($type) && in_array($type, self::TYPES, true) ? $type : 'invalid';
            $reason = array_intersect(['node_handle', 'from_handle', 'to_handle'], array_keys($exception->errors())) !== []
                ? 'invalid_handle'
                : 'validation_failed';

            return $this->response($project, $type, null, [
                'found' => false,
                'reason' => $reason,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $type = (string) $validated['type'];

        if ($type === 'scopes') {
            try {
                $result = $this->explorer->scopes(
                    $project,
                    (int) ($validated['limit'] ?? 50),
                    $validated['cursor'] ?? null,
                );
            } catch (\InvalidArgumentException $exception) {
                return $this->response($project, $type, null, [
                    'found' => false,
                    'reason' => $exception->getMessage(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->response($project, $type, null, $result, $this->resultStatus($result));
        }

        [$scopeType, $scopeId, $scopeError] = $this->resolveScope($project, $validated);
        if ($scopeError !== null) {
            return $scopeError;
        }

        try {
            $result = match ($type) {
                'overview' => $this->explorer->overview($project, $scopeType, $scopeId),
                'search' => $this->explorer->search(
                    $project,
                    $scopeType,
                    $scopeId,
                    (string) $validated['query'],
                    (int) ($validated['limit'] ?? 50),
                    $validated['cursor'] ?? null,
                ),
                'detail' => $this->explorer->detail(
                    $project,
                    $scopeType,
                    $scopeId,
                    (string) $validated['node_handle'],
                    (int) ($validated['limit'] ?? 50),
                ),
                'neighborhood' => $this->explorer->neighborhood(
                    $project,
                    $scopeType,
                    $scopeId,
                    (string) $validated['node_handle'],
                    (string) ($validated['direction'] ?? 'any'),
                    (int) ($validated['max_depth'] ?? 2),
                    (int) ($validated['limit'] ?? 50),
                    $validated['families'] ?? [],
                ),
                'path' => $this->explorer->path(
                    $project,
                    $scopeType,
                    $scopeId,
                    (string) $validated['from_handle'],
                    (string) $validated['to_handle'],
                    (int) ($validated['max_depth'] ?? 2),
                    (int) ($validated['limit'] ?? 50),
                ),
                'impact' => $this->explorer->impact(
                    $project,
                    $scopeType,
                    $scopeId,
                    (string) $validated['node_handle'],
                    (int) ($validated['limit'] ?? 50),
                ),
            };
        } catch (\InvalidArgumentException $exception) {
            return $this->response(
                $project,
                $type,
                ['type' => $scopeType, 'id' => $scopeId],
                ['found' => false, 'reason' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->response(
            $project,
            $type,
            ['type' => $scopeType, 'id' => $scopeId],
            $result,
            $this->resultStatus($result),
        );
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'scope_type' => ['nullable', 'required_with:scope_id', 'string', Rule::in(self::SCOPE_TYPES)],
            'scope_id' => ['nullable', 'required_with:scope_type', 'string', 'max:191'],
            'query' => ['required_if:type,search', 'string', 'min:1', 'max:160'],
            'node_handle' => ['required_if:type,detail,neighborhood,impact', 'string', 'regex:/\Agh1_[A-Za-z0-9_-]{43}\z/'],
            'from_handle' => ['required_if:type,path', 'string', 'regex:/\Agh1_[A-Za-z0-9_-]{43}\z/'],
            'to_handle' => ['required_if:type,path', 'string', 'regex:/\Agh1_[A-Za-z0-9_-]{43}\z/'],
            'direction' => ['sometimes', 'string', Rule::in(['in', 'out', 'any'])],
            'families' => ['sometimes', 'array'],
            'families.*' => ['string', Rule::in(self::FAMILIES)],
            'max_depth' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        $type = (string) $validated['type'];
        $limit = (int) ($validated['limit'] ?? 50);
        if ($type !== 'scopes' && $type !== 'search' && $limit > 50) {
            throw ValidationException::withMessages([
                'limit' => ['The limit may not be greater than 50 for this query type.'],
            ]);
        }
        if ($type === 'impact' && array_key_exists('max_depth', $validated) && (int) $validated['max_depth'] !== 2) {
            throw ValidationException::withMessages([
                'max_depth' => ['Impact queries require max_depth 2.'],
            ]);
        }
        if (($validated['cursor'] ?? null) !== null && ! in_array($type, ['scopes', 'search'], true)) {
            throw ValidationException::withMessages([
                'cursor' => ['Cursors are supported only for scopes and search.'],
            ]);
        }

        return $validated;
    }

    private function assertNoPluginHeaders(Request $request): void
    {
        foreach (array_keys($request->headers->all()) as $header) {
            $header = strtolower((string) $header);
            if ($header === 'authorization' || str_starts_with($header, 'x-devboard-')) {
                throw ValidationException::withMessages([
                    'headers' => ['Plugin authentication headers are not accepted by this endpoint.'],
                ]);
            }
        }
    }

    private function assertKnownRequestFields(Request $request): void
    {
        $unknown = array_diff(array_keys($request->all()), self::ALLOWED_FIELDS);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'request' => ['The request contains a prohibited or unsupported field.'],
            ]);
        }
    }

    /** @param array<string,mixed> $validated */
    private function resolveScope(string $project, array $validated): array
    {
        $scopeType = isset($validated['scope_type']) ? (string) $validated['scope_type'] : null;
        $scopeId = isset($validated['scope_id']) ? (string) $validated['scope_id'] : null;

        if ($scopeType === null || $scopeId === null) {
            $scopeResult = $this->explorer->scopes($project, 100);
            $scopes = is_array($scopeResult['items'] ?? null) ? $scopeResult['items'] : [];
            if ($scopes === []) {
                return [
                    null,
                    null,
                    $this->response(
                        $project,
                        (string) $validated['type'],
                        null,
                        [
                            ...$scopeResult,
                            'found' => false,
                            'reason' => 'graph_projection_not_ready',
                        ],
                        Response::HTTP_OK,
                    ),
                ];
            }
            if (count($scopes) > 1) {
                return [
                    null,
                    null,
                    $this->response(
                        $project,
                        (string) $validated['type'],
                        null,
                        [
                            ...$scopeResult,
                            'found' => false,
                            'reason' => 'scope_required',
                        ],
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                    ),
                ];
            }
            $scopeType = (string) $scopes[0]['source_scope_type'];
            $scopeId = (string) $scopes[0]['source_scope_id'];
        }

        if (! $this->scopeBelongsToProject($project, $scopeType, $scopeId)) {
            return [
                null,
                null,
                $this->response(
                    $project,
                    (string) $validated['type'],
                    null,
                    ['found' => false, 'reason' => 'scope_not_found'],
                    Response::HTTP_NOT_FOUND,
                ),
            ];
        }

        return [$scopeType, $scopeId, null];
    }

    private function scopeBelongsToProject(string $project, string $scopeType, string $scopeId): bool
    {
        return $scopeType === 'repository'
            ? DB::table('repositories')->where('id', $scopeId)->where('project_id', $project)->exists()
            : DB::table('hades_workspace_bindings')
                ->where('id', $scopeId)
                ->where('project_id', $project)
                ->where('status', 'linked')
                ->exists();
    }

    /** @param array<string,mixed> $result */
    private function resultStatus(array $result): int
    {
        return match ($this->publicReason($result['reason'] ?? null)) {
            'node_not_found', 'path_not_found' => Response::HTTP_NOT_FOUND,
            default => Response::HTTP_OK,
        };
    }

    /** @param array<string,mixed>|null $scope @param array<string,mixed> $result */
    private function response(
        string $project,
        string $type,
        ?array $scope,
        array $result,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        $projection = is_array($result['projection'] ?? null) ? $result['projection'] : [
            'status' => 'unavailable',
            'quality' => null,
            'generated_at' => null,
            'active_graph_version' => null,
            'node_count' => 0,
            'relationship_count' => 0,
            'unknown_kind_count' => 0,
            'missing_label_count' => 0,
            'excluded_node_count' => 0,
        ];
        $projection = $this->publicProjection($projection);
        $publicItems = $this->publicItems($result['items'] ?? []);
        $publicEdges = $this->publicEdges($result['edges'] ?? []);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $project,
            'query_type' => $type,
            'found' => (bool) ($result['found'] ?? false),
            'reason' => $this->publicReason($result['reason'] ?? null),
            'completeness' => $this->publicCompleteness($result['completeness'] ?? null),
            'scope' => $scope,
            'projection' => $projection,
            'node' => $this->publicNode($result['node'] ?? null),
            'items' => $publicItems,
            'edges' => $publicEdges,
            'returned' => count($publicItems),
            'limit' => isset($result['limit']) ? (int) $result['limit'] : 50,
            'next_cursor' => is_string($result['next_cursor'] ?? null) ? $result['next_cursor'] : null,
            'has_more' => (bool) ($result['has_more'] ?? false),
            'truncated' => (bool) ($result['truncated'] ?? false),
            'source' => [
                'type' => 'canonical_graph',
                'status' => 'verified_from_code',
                'origin' => 'canonical projection',
            ],
        ], $status);
    }

    /** @return list<array<string,mixed>> */
    private function publicItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $item): ?array {
            if (! is_array($item)) {
                return null;
            }

            $item = $this->stripForbiddenFields($item);

            return array_key_exists('source_scope_type', $item)
                || array_key_exists('source_scope_id', $item)
                ? $this->publicScopeItem($item)
                : $this->publicGraphItem($item);
        }, array_values(array_filter($items, 'is_array')))));
    }

    /** @param array<string,mixed> $item @return array<string,mixed>|null */
    private function publicScopeItem(array $item): ?array
    {
        $scopeType = $item['source_scope_type'] ?? null;
        $scopeId = $item['source_scope_id'] ?? null;
        if (! is_string($scopeType) || ! in_array($scopeType, self::SCOPE_TYPES, true)
            || ! is_string($scopeId) || $scopeId === '' || strlen($scopeId) > 191) {
            return null;
        }

        $public = [
            'source_scope_type' => $scopeType,
            'source_scope_id' => $scopeId,
        ];
        foreach (['active_graph_version', 'status', 'quality'] as $field) {
            $text = $this->publicText($item[$field] ?? null, 128);
            if ($text !== null) {
                $public[$field] = $text;
            }
        }
        foreach (['node_count', 'relationship_count'] as $field) {
            if (is_int($item[$field] ?? null) || is_float($item[$field] ?? null)) {
                $public[$field] = (int) $item[$field];
            }
        }

        return $public;
    }

    /** @param array<string,mixed> $item @return array<string,mixed>|null */
    private function publicGraphItem(array $item): ?array
    {
        $handle = $item['handle'] ?? null;
        if (! is_string($handle) || ! (new DashboardGraphPublicHandle)->isWellFormed($handle)) {
            return null;
        }

        $public = [
            'handle' => $handle,
            'kind' => $this->publicKinds->map($item['kind'] ?? null),
        ];
        if (($label = $this->publicText($item['label'] ?? null)) !== null) {
            $public['label'] = $label;
        }
        if (is_int($item['score'] ?? null) || is_float($item['score'] ?? null)) {
            $public['score'] = (float) $item['score'];
        }
        if (is_int($item['distance'] ?? null)) {
            $public['distance'] = $item['distance'];
        }
        if (is_string($item['family'] ?? null) && in_array($item['family'], self::FAMILIES, true)) {
            $public['family'] = $item['family'];
        }
        if (is_array($item['edge_types'] ?? null)) {
            $edgeTypes = array_values(array_filter(
                $item['edge_types'],
                static fn (mixed $type): bool => is_string($type) && isset(self::EDGE_FAMILIES[$type]),
            ));
            $public['edge_types'] = array_values(array_unique($edgeTypes));
        }
        if (($why = $this->publicText($item['why'] ?? null)) !== null) {
            $public['why'] = $why;
        }
        $this->appendPublicEvidence($public, $item);

        return $public;
    }

    /** @return list<array<string,mixed>> */
    private function publicEdges(mixed $edges): array
    {
        if (! is_array($edges)) {
            return [];
        }

        $handles = new DashboardGraphPublicHandle;

        return array_values(array_filter(array_map(function (mixed $edge) use ($handles): ?array {
            if (! is_array($edge)) {
                return null;
            }

            $edge = $this->stripForbiddenFields($edge);
            $from = $edge['from_handle'] ?? $edge['source_handle'] ?? null;
            $to = $edge['to_handle'] ?? $edge['target_handle'] ?? null;
            if (! is_string($from) || ! is_string($to)
                || ! $handles->isWellFormed($from)
                || ! $handles->isWellFormed($to)) {
                return null;
            }
            $edgeType = $edge['edge_type'] ?? $edge['type'] ?? null;
            if (! is_string($edgeType) || ! isset(self::EDGE_FAMILIES[$edgeType])) {
                return null;
            }

            return [
                'from_handle' => $from,
                'to_handle' => $to,
                'edge_type' => $edgeType,
                'family' => self::EDGE_FAMILIES[$edgeType],
            ];
        }, array_values(array_filter($edges, 'is_array')))));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function stripForbiddenFields(array $value): array
    {
        $forbidden = [
            'external_id',
            'artifact_id',
            'projection_id',
            'graph_version',
            'symbol_id',
            'from_symbol_id',
            'to_symbol_id',
            'source_id',
            'target_id',
            'from_id',
            'to_id',
            'id',
        ];
        foreach (array_keys($value) as $key) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $forbidden, true)
                || str_contains($lower, 'token')
                || str_contains($lower, 'credential')
                || str_contains($lower, 'authorization')
                || str_contains($lower, 'header')
                || str_contains($lower, 'path')) {
                unset($value[$key]);

                continue;
            }
            if (is_array($value[$key])) {
                $value[$key] = $this->stripForbiddenFields($value[$key]);
            }
        }

        return $value;
    }

    /** @param array<string,mixed> $projection @return array<string,mixed> */
    private function publicProjection(array $projection): array
    {
        $projection = $this->stripForbiddenFields($projection);
        $public = [
            'status' => is_string($projection['status'] ?? null) ? $projection['status'] : 'unavailable',
            'quality' => is_string($projection['quality'] ?? null) ? $projection['quality'] : null,
            'generated_at' => $this->publicIsoTimestamp($projection['generated_at'] ?? null),
            'active_graph_version' => is_string($projection['active_graph_version'] ?? null) ? $projection['active_graph_version'] : null,
        ];
        foreach (['node_count', 'relationship_count', 'unknown_kind_count', 'missing_label_count', 'excluded_node_count'] as $field) {
            $public[$field] = is_numeric($projection[$field] ?? null) ? (int) $projection[$field] : 0;
        }
        $public['coverage'] = $this->publicCoverage($projection['coverage'] ?? null);

        return $public;
    }

    /** @return array<string, mixed>|null */
    private function publicCoverage(mixed $coverage): ?array
    {
        if (! is_array($coverage) || ! is_array($coverage['languages'] ?? null)) {
            return null;
        }
        $languages = [];
        foreach ($coverage['languages'] as $language) {
            if (! is_string($language) || preg_match('/\A[a-z0-9][a-z0-9+#._-]{0,31}\z/D', $language) !== 1) {
                return null;
            }
            $languages[] = $language;
        }
        if ($languages === [] || count($languages) > 16) {
            return null;
        }

        $public = ['languages' => $languages];
        foreach ([
            'files_total', 'files_analyzed', 'files_failed', 'files_budget_omitted',
            'routes_promoted', 'routes_omitted', 'tests_promoted', 'tests_omitted',
            'nodes_capacity_omitted',
        ] as $field) {
            if (is_int($coverage[$field] ?? null) && $coverage[$field] >= 0) {
                $public[$field] = $coverage[$field];
            }
        }
        if (! isset($public['files_total'], $public['files_analyzed'], $public['files_failed'])) {
            return null;
        }

        return $public;
    }

    private function publicIsoTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{handle:string,kind:string,label:?string}|null */
    private function publicNode(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        $node = $this->stripForbiddenFields($node);
        if (! is_string($node['handle'] ?? null)
            || ! (new DashboardGraphPublicHandle)->isWellFormed($node['handle'])) {
            return null;
        }

        $public = [
            'handle' => $node['handle'],
            'kind' => $this->publicKinds->map($node['kind'] ?? null),
        ];
        $public['label'] = $this->publicText($node['label'] ?? null);
        $this->appendPublicEvidence($public, $node);

        return $public;
    }

    /** @param array<string,mixed> $public @param array<string,mixed> $source */
    private function appendPublicEvidence(array &$public, array $source): void
    {
        $sourceFile = $this->publicSourceFile($source['source_file'] ?? null);
        if ($sourceFile !== null) {
            $public['source_file'] = $sourceFile;
            foreach (['line_start', 'line_end'] as $field) {
                if (is_int($source[$field] ?? null) && $source[$field] >= 1 && $source[$field] <= 10_000_000) {
                    $public[$field] = $source[$field];
                }
            }
        }
        if (($namespace = $this->publicNamespace($source['namespace'] ?? null)) !== null) {
            $public['namespace'] = $namespace;
        }
        if (is_string($source['match_type'] ?? null)
            && in_array($source['match_type'], ['exact_symbol_name', 'exact_route_path', 'token_match', 'fuzzy', 'direct_lookup', 'relationship'], true)) {
            $public['match_type'] = $source['match_type'];
        }
        if (($reason = $this->publicText($source['match_reason'] ?? null, 160)) !== null) {
            $public['match_reason'] = $reason;
        }
    }

    private function publicSourceFile(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || $value === '' || strlen($value) > 512
            || str_starts_with($value, '/') || str_contains($value, '\\')
            || str_contains($value, '://')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $value) === 1
            || preg_match('~(?:\A|/)(?:\.|\.\.)(?:/|\z)~', $value) === 1
            || str_contains($value, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function publicNamespace(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/\A\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $value) === 1
            ? $value
            : null;
    }

    private function publicText(mixed $value, int $maxLength = 512): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= $maxLength ? $value : null;
    }

    private function publicReason(mixed $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return is_string($reason) && in_array($reason, self::REASONS, true)
            ? $reason
            : 'query_error';
    }

    private function publicCompleteness(mixed $completeness): string
    {
        return is_string($completeness)
            && in_array($completeness, ['complete', 'verified_none', 'partial', 'not_indexed'], true)
            ? $completeness
            : 'not_indexed';
    }
}
