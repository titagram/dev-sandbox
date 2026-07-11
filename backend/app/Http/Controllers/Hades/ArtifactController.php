<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesSearchDocumentIndexer;
use App\Services\Hades\HadesSourceSliceCandidateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ArtifactController extends Controller
{
    private const MAX_COMPRESSED_BYTES = 4_000_000;

    private const MAX_UNCOMPRESSED_BYTES = 16_000_000;

    private const MAX_BASE64_BYTES = 5_333_336;

    public function __construct(
        private readonly HadesSearchDocumentIndexer $searchIndexer,
        private readonly HadesSourceSliceCandidateService $sourceSliceCandidates,
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
            'workspace_binding_id' => ['required', 'string'],
            'schema' => ['required', 'string', 'in:hades.git_tree.v1,hades.symbols.v1,hades.php_graph.v1,hades.code_graph.v1'],
            'sha256' => ['required', 'string', 'size:64'],
        ]);

        $auth = $request->attributes->get('hades_auth');
        $agent = $auth['agent'];
        $binding = $this->linkedBinding($agent, $validated['project_id'], $validated['workspace_binding_id'], $validated['agent_id'] ?? null);

        if ($binding instanceof JsonResponse) {
            return $binding;
        }

        $artifact = $this->findExistingArtifact($validated['project_id'], $binding->id, $validated['schema'], $validated['sha256']);

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'exists' => $artifact !== null,
            'artifact' => $artifact ? $this->payload($artifact) : null,
            'delta_upload' => [
                'required' => $artifact === null,
                'reason' => $artifact ? 'unchanged_on_backend' : 'missing_on_backend',
            ],
            'server_time' => now()->toISOString(),
        ]);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'agent_id' => ['nullable', 'string', 'max:191'],
            'workspace_binding_id' => ['required', 'string'],
            'job_id' => ['nullable', 'string'],
            'schema' => ['required', 'string', 'in:hades.git_tree.v1,hades.symbols.v1,hades.php_graph.v1,hades.code_graph.v1'],
            'artifact' => ['required_without:artifact_compressed', 'array'],
            'artifact_compressed' => ['required_without:artifact', 'string', 'max:'.self::MAX_BASE64_BYTES],
            'artifact_encoding' => ['nullable', 'required_with:artifact_compressed', 'string', 'in:gzip+base64'],
            'artifact_uncompressed_sha256' => ['nullable', 'required_with:artifact_compressed', 'string', 'size:64'],
            'artifact_uncompressed_bytes' => ['nullable', 'required_with:artifact_compressed', 'integer', 'min:1', 'max:'.self::MAX_UNCOMPRESSED_BYTES],
            'artifact_compressed_bytes' => ['nullable', 'required_with:artifact_compressed', 'integer', 'min:1', 'max:'.self::MAX_COMPRESSED_BYTES],
            'sha256' => ['nullable', 'string', 'size:64'],
            'truncated' => ['nullable', 'boolean'],
            'redactions' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

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

        $artifactPayload = $validated['artifact'] ?? null;
        if ($artifactPayload === null) {
            $artifactPayload = $this->decodeCompressedArtifact($validated);
            if ($artifactPayload instanceof JsonResponse) {
                return $artifactPayload;
            }
        }

        if (($artifactPayload['schema'] ?? null) !== $validated['schema']) {
            return $this->error('artifact_schema_mismatch', 'Artifact payload schema does not match the requested schema.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $artifactJson = json_encode($artifactPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($artifactJson) > self::MAX_UNCOMPRESSED_BYTES) {
            return $this->error('artifact_too_large', 'Artifact exceeds the uncompressed byte limit.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $payloadHash = $validated['sha256'] ?? hash('sha256', $artifactJson);
        $existing = $this->findExistingArtifact($validated['project_id'], $binding->id, $validated['schema'], $payloadHash);
        if ($existing) {
            return response()->json([
                'protocol_version' => 'v1',
                'project_id' => $validated['project_id'],
                'artifact' => $this->payload($existing),
                'deduplicated' => true,
                'delta_upload' => [
                    'required' => false,
                    'reason' => 'unchanged_on_backend',
                ],
                'server_time' => now()->toISOString(),
            ]);
        }

        $id = (string) Str::ulid();
        $now = now();

        DB::table('hades_agent_artifacts')->insert([
            'id' => $id,
            'project_id' => $validated['project_id'],
            'hades_agent_id' => $agent->id,
            'workspace_binding_id' => $binding->id,
            'job_id' => $validated['job_id'] ?? null,
            'schema' => $validated['schema'],
            'artifact' => $artifactJson,
            'sha256' => $payloadHash,
            'truncated' => (bool) ($validated['truncated'] ?? false),
            'redactions' => (int) ($validated['redactions'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $artifact = DB::table('hades_agent_artifacts')->where('id', $id)->first();
        $this->searchIndexer->indexArtifact($artifact, $artifactPayload, $artifactJson);
        $sourceSliceCandidates = $this->sourceSliceCandidates->ingestArtifactCandidates(
            $agent,
            $binding,
            $artifactPayload,
            $artifactPayload['head_commit'] ?? $artifactPayload['workspace_head_commit'] ?? null,
        );

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $validated['project_id'],
            'artifact' => $this->payload($artifact),
            'server_time' => now()->toISOString(),
        ], Response::HTTP_CREATED);
    }

    private function decodeCompressedArtifact(array $payload): array|JsonResponse
    {
        if (($payload['artifact_encoding'] ?? null) !== 'gzip+base64') {
            return $this->error('artifact_encoding_unsupported', 'Compressed artifact encoding is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $compressed = base64_decode((string) ($payload['artifact_compressed'] ?? ''), true);
        if ($compressed === false) {
            return $this->error('artifact_compressed_invalid', 'Compressed artifact is not valid base64.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $compressedBytes = strlen($compressed);
        if ($compressedBytes > self::MAX_COMPRESSED_BYTES) {
            return $this->error('artifact_compressed_too_large', 'Compressed artifact exceeds the byte limit.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if ($compressedBytes !== (int) $payload['artifact_compressed_bytes']) {
            return $this->error('artifact_compressed_size_mismatch', 'Compressed artifact byte count does not match.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $json = $this->decompressGzip($compressed, (int) $payload['artifact_uncompressed_bytes']);
        if ($json instanceof JsonResponse) {
            return $json;
        }

        if (strlen($json) !== (int) $payload['artifact_uncompressed_bytes']) {
            return $this->error('artifact_uncompressed_size_mismatch', 'Artifact byte count does not match.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! hash_equals(strtolower((string) $payload['artifact_uncompressed_sha256']), hash('sha256', $json))) {
            return $this->error('artifact_uncompressed_hash_mismatch', 'Artifact hash does not match.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $artifact = json_decode($json, true);
        if (! is_array($artifact)) {
            return $this->error('artifact_compressed_invalid', 'Compressed artifact JSON must decode to an object.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $artifact;
    }

    private function decompressGzip(string $compressed, int $declaredBytes): string|JsonResponse
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false || fwrite($stream, $compressed) !== strlen($compressed) || rewind($stream) === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $this->error('artifact_compressed_invalid', 'Compressed artifact could not be processed.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filter = @stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]);
        if ($filter === false) {
            fclose($stream);

            return $this->error('artifact_compressed_invalid', 'Compressed artifact is not valid gzip.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limit = min(self::MAX_UNCOMPRESSED_BYTES, $declaredBytes) + 1;
        $json = '';
        while (! feof($stream) && strlen($json) < $limit) {
            $chunk = @fread($stream, min(65_536, $limit - strlen($json)));
            if ($chunk === false) {
                fclose($stream);

                return $this->error('artifact_compressed_invalid', 'Compressed artifact is not valid gzip.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($chunk === '' && ! feof($stream)) {
                fclose($stream);

                return $this->error('artifact_compressed_invalid', 'Compressed artifact could not be fully decoded.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $json .= $chunk;
        }
        fclose($stream);

        if (strlen($json) > self::MAX_UNCOMPRESSED_BYTES) {
            return $this->error('artifact_uncompressed_too_large', 'Artifact exceeds the uncompressed byte limit.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if (strlen($json) > $declaredBytes) {
            return $this->error('artifact_uncompressed_size_mismatch', 'Artifact byte count does not match.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $json;
    }

    private function linkedBinding(object $agent, string $projectId, string $bindingId, ?string $externalAgentId): mixed
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

    private function findExistingArtifact(string $projectId, string $bindingId, string $schema, string $sha256): ?object
    {
        return DB::table('hades_agent_artifacts')
            ->where('project_id', $projectId)
            ->where('workspace_binding_id', $bindingId)
            ->where('schema', $schema)
            ->where('sha256', $sha256)
            ->orderByDesc('created_at')
            ->first();
    }

    private function payload(object $artifact): array
    {
        return [
            'id' => $artifact->id,
            'project_id' => $artifact->project_id,
            'workspace_binding_id' => $artifact->workspace_binding_id,
            'job_id' => $artifact->job_id,
            'schema' => $artifact->schema,
            'sha256' => $artifact->sha256,
            'truncated' => (bool) $artifact->truncated,
            'redactions' => (int) $artifact->redactions,
            'created_at' => $artifact->created_at,
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
