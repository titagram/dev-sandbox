<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Models\HadesAgent;
use App\Models\HadesGraphImport;
use App\Models\HadesWorkspaceBinding;
use App\Models\Project;
use App\Services\Graph\V2\GraphV2ImportException;
use App\Services\Graph\V2\GraphV2ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GraphImportController extends Controller
{
    private const MAX_MANIFEST_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly GraphV2ImportService $imports) {}

    public function store(Request $request): JsonResponse
    {
        try {
            if (strtolower((string) strtok((string) $request->header('Content-Type'), ';')) !== 'application/json') {
                throw new GraphV2ImportException('graph_manifest_invalid', 'Graph manifest content type must be application/json.');
            }
            $manifest = $this->decodeObject($request);
            if (! is_array($manifest)) {
                throw new GraphV2ImportException('graph_manifest_invalid', 'Graph manifest must be a JSON object.');
            }
            if ($manifest === []) {
                throw new GraphV2ImportException('graph_manifest_invalid', 'Graph manifest must not be empty.');
            }
            $scope = $this->createScope($manifest, $request);
            $result = $this->imports->create($scope['project'], $scope['binding'], $scope['agent'], $manifest);

            return response()->json($result['payload'], $result['status_code']);
        } catch (GraphV2ImportException $exception) {
            return $this->error($exception);
        }
    }

    public function putChunk(Request $request, string $graphImport, string $index): JsonResponse
    {
        try {
            $index = $this->parseChunkIndex($index);
            $import = $this->resolveImport($request, $graphImport);
            $result = $this->imports->putChunk($import, $index, $request->getContent(true), $this->headers($request));

            return response()->json($result['payload'], $result['status_code']);
        } catch (GraphV2ImportException $exception) {
            return $this->error($exception);
        }
    }

    public function complete(Request $request, string $graphImport): JsonResponse
    {
        try {
            $import = $this->resolveImport($request, $graphImport);
            if (strtolower((string) strtok((string) $request->header('Content-Type'), ';')) !== 'application/json') {
                throw new GraphV2ImportException('graph_manifest_invalid', 'Complete content type must be application/json.');
            }
            $payload = $this->decodeObject($request);
            if (! is_array($payload) || array_keys($payload) !== ['artifact_graph_version'] || ! is_string($payload['artifact_graph_version'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $payload['artifact_graph_version']) !== 1) {
                throw new GraphV2ImportException('graph_manifest_invalid', 'Complete accepts exactly artifact_graph_version.');
            }
            $result = $this->imports->complete($import, $payload['artifact_graph_version']);

            return response()->json($result['payload'], $result['status_code']);
        } catch (GraphV2ImportException $exception) {
            return $this->error($exception);
        }
    }

    public function show(Request $request, string $graphImport): JsonResponse
    {
        try {
            $import = $this->resolveImport($request, $graphImport);
            $result = $this->imports->show($import);

            return response()->json($result['payload'], $result['status_code']);
        } catch (GraphV2ImportException $exception) {
            return $this->error($exception);
        }
    }

    /** @return array{project:Project,binding:HadesWorkspaceBinding,agent:HadesAgent} */
    private function createScope(array $manifest, Request $request): array
    {
        $authAgent = $request->attributes->get('hades_auth')['agent'] ?? null;
        $projectId = $manifest['project']['project_id'] ?? null;
        $bindingId = $manifest['project']['workspace_binding_id'] ?? null;
        $project = is_string($projectId) ? Project::query()->whereKey($projectId)->first() : null;
        $agent = $authAgent?->id ? HadesAgent::query()->whereKey($authAgent->id)->first() : null;
        $binding = $project && $agent && is_string($bindingId)
            ? HadesWorkspaceBinding::query()->whereKey($bindingId)->where('project_id', $project->id)->where('hades_agent_id', $agent->id)->where('status', 'linked')->first()
            : null;

        if (! $project || ! $agent || ! $binding || $agent->project_id !== $project->id) {
            throw new GraphV2ImportException('graph_import_not_found', 'Graph import was not found.', Response::HTTP_NOT_FOUND);
        }
        $this->requireCapability($agent);

        return ['project' => $project, 'binding' => $binding, 'agent' => $agent];
    }

    private function authorizeImport(Request $request, HadesGraphImport $import): void
    {
        $authAgent = $request->attributes->get('hades_auth')['agent'] ?? null;
        $agent = $authAgent?->id ? HadesAgent::query()->whereKey($authAgent->id)->first() : null;
        $authorized = $agent
            && $agent->project_id === $import->project_id
            && HadesWorkspaceBinding::query()
                ->whereKey($import->workspace_binding_id)
                ->where('project_id', $import->project_id)
                ->where('hades_agent_id', $agent->id)
                ->where('status', 'linked')
                ->exists();
        if (! $authorized) {
            throw new GraphV2ImportException('graph_import_not_found', 'Graph import was not found.', Response::HTTP_NOT_FOUND);
        }
        $this->requireCapability($agent);
    }

    private function resolveImport(Request $request, string $id): HadesGraphImport
    {
        $import = HadesGraphImport::query()->whereKey($id)->first();
        if (! $import) {
            throw new GraphV2ImportException('graph_import_not_found', 'Graph import was not found.', Response::HTTP_NOT_FOUND);
        }
        $this->authorizeImport($request, $import);

        return $import;
    }

    /** @return array<string, mixed> */
    private function decodeObject(Request $request): array
    {
        $body = $this->readManifestBody($request);
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'JSON body is malformed.');
        }
        if (! $decoded instanceof \stdClass) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'JSON body must be an object.');
        }
        if (get_object_vars($decoded) === []) {
            return [];
        }
        $decoded = $this->wireObjectToArray($decoded);
        $this->rejectUnsafeNumbers($decoded);

        return $decoded;
    }

    private function readManifestBody(Request $request): string
    {
        $source = $request->getContent(true);
        if (! is_resource($source)) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'JSON body could not be read.');
        }
        $target = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($target)) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'JSON body could not be buffered.');
        }
        try {
            while (! feof($source)) {
                $remaining = self::MAX_MANIFEST_BYTES - (int) ftell($target);
                $part = fread($source, min(65536, $remaining + 1));
                if ($part === false) {
                    throw new GraphV2ImportException('graph_manifest_invalid', 'JSON body could not be read.');
                }
                if ($part === '') {
                    if (feof($source)) {
                        break;
                    }
                    throw new GraphV2ImportException('graph_manifest_invalid', 'JSON body could not be read.');
                }
                if (strlen($part) > $remaining || fwrite($target, $part) !== strlen($part)) {
                    throw new GraphV2ImportException('graph_manifest_invalid', 'JSON body exceeds the 4 MiB limit.');
                }
            }
            rewind($target);
            $body = stream_get_contents($target);
            if (! is_string($body)) {
                throw new GraphV2ImportException('graph_manifest_invalid', 'JSON body could not be read.');
            }

            return $body;
        } finally {
            fclose($target);
        }
    }

    private function wireObjectToArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            if (get_object_vars($value) === []) {
                return $value;
            }
            $result = [];
            foreach (get_object_vars($value) as $key => $item) {
                $result[$key] = $this->wireObjectToArray($item);
            }

            return $result;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->wireObjectToArray($item), $value);
        }

        return $value;
    }

    private function rejectUnsafeNumbers(mixed $value): void
    {
        if (is_float($value) || (is_int($value) && abs($value) > 9007199254740991)) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'JSON numbers must be safe integers.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->rejectUnsafeNumbers($item);
            }
        }
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $item) {
                $this->rejectUnsafeNumbers($item);
            }
        }
    }

    private function requireCapability(HadesAgent $agent): void
    {
        $raw = $agent->getRawOriginal('effective_capabilities');
        if (is_string($raw)) {
            try {
                $capabilities = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $capabilities = [];
            }
        } else {
            $capabilities = $agent->effective_capabilities ?? [];
        }
        if (! is_array($capabilities) || ! in_array('populate_backend_ast', $capabilities, true)) {
            throw new GraphV2ImportException('capability_missing', 'The populate_backend_ast capability is required.', Response::HTTP_FORBIDDEN);
        }
    }

    private function parseChunkIndex(string $raw): int
    {
        if (($raw !== '0' && preg_match('/\A[1-9][0-9]*\z/', $raw) !== 1)
            || strlen($raw) > strlen((string) PHP_INT_MAX)
            || (strlen($raw) === strlen((string) PHP_INT_MAX) && strcmp($raw, (string) PHP_INT_MAX) > 0)) {
            throw new GraphV2ImportException('graph_chunk_invalid', 'Chunk index must be a canonical non-negative integer.');
        }

        return (int) $raw;
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $key => $value) {
            $headers[$key] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        return $headers;
    }

    private function error(GraphV2ImportException $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ],
        ], $exception->statusCode);
    }
}
