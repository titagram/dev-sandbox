<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImportChunk;
use App\Models\HadesWorkspaceBinding;
use App\Models\Project;

final class GraphV2ManifestValidator
{
    private const MAX_MANIFEST_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly GraphV2JsonSchemaValidator $schema) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{semantic_sha256:string, expected_chunks:int, expected_uncompressed_bytes:int, expected_compressed_bytes:int, completeness_status:string}
     */
    public function validate(array $manifest, Project $project, HadesWorkspaceBinding $binding): array
    {
        $this->schema->assertValid($manifest, 'bundle.schema.json');

        $canonicalizer = new GraphV2Canonicalizer;
        try {
            $canonicalManifestBytes = strlen($canonicalizer->canonicalJson($manifest));
            if ($canonicalManifestBytes > self::MAX_MANIFEST_BYTES) {
                throw new GraphV2ImportException('graph_manifest_invalid', 'Graph manifest exceeds the 4 MiB canonical size limit.');
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof GraphV2ImportException) {
                throw $exception;
            }

            throw new GraphV2ImportException('graph_manifest_invalid', 'Graph manifest is not canonicalizable.');
        }

        $this->assert(($manifest['schema'] ?? null) === 'hades.graph_bundle.v2');
        $this->assert(($manifest['artifact_schema'] ?? null) === 'hades.code_graph.v2');
        $this->assertDigest($manifest['artifact_graph_version'] ?? null);
        $this->assertTimestamp($manifest['generated_at'] ?? null);

        $manifestProject = $manifest['project'] ?? null;
        $this->assert(is_array($manifestProject));
        $this->assertObjectKeys($manifestProject, ['project_id', 'workspace_binding_id']);
        $this->assert($manifestProject['project_id'] === $project->id && $manifestProject['workspace_binding_id'] === $binding->id);

        $source = $manifest['source'] ?? null;
        $this->assert(is_array($source));
        $this->assertObjectKeys($source, ['head_commit', 'tree_sha256', 'dirty', 'branch']);
        $this->assert($source['head_commit'] === null || preg_match('/\A[0-9a-f]{40}\z/', (string) $source['head_commit']) === 1);
        $this->assertDigest($source['tree_sha256'] ?? null);
        $this->assert(is_bool($source['dirty']));
        $this->assert($source['branch'] === null || is_string($source['branch']));

        $contract = $manifest['graph_contract'] ?? null;
        $this->assert(is_array($contract));
        $this->assertObjectKeys($contract, ['version', 'artifact_graph_version', 'projection_state', 'completeness', 'coverage']);
        $this->assert($contract['version'] === 'hades.graph_artifact.v2');
        $this->assert($contract['artifact_graph_version'] === $manifest['artifact_graph_version']);
        $this->assert($contract['projection_state'] === 'queued');
        $this->assert(is_array($contract['completeness']) && isset($contract['completeness']['status']));
        $this->assert(is_array($contract['coverage']));

        foreach (['frameworks', 'languages'] as $field) {
            $this->assert(is_array($manifest[$field]));
        }

        $counts = $manifest['counts'] ?? null;
        $countFields = ['frameworks', 'languages', 'entrypoints', 'nodes', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties'];
        $this->assert(is_array($counts));
        $this->assertObjectKeys($counts, $countFields);
        foreach ($countFields as $field) {
            $this->assert(is_int($counts[$field]) && $counts[$field] >= 0);
        }
        $this->assert($counts['frameworks'] === count($manifest['frameworks']));
        $this->assert($counts['languages'] === count($manifest['languages']));

        $chunks = $manifest['chunks'] ?? null;
        $this->assert(is_array($chunks) && count($chunks) <= min((int) config('devboard.artifacts.max_chunks'), 512));

        $kindOrder = array_flip(HadesGraphImportChunk::KINDS);
        $totals = array_fill_keys(HadesGraphImportChunk::KINDS, 0);
        $expectedUncompressed = 0;
        $expectedCompressed = 0;
        $lastKind = -1;

        foreach ($chunks as $position => $descriptor) {
            $this->assert(is_array($descriptor));
            $this->assertObjectKeys($descriptor, [
                'index', 'kind', 'record_count', 'sha256', 'uncompressed_bytes', 'compression',
                'compressed_sha256', 'compressed_bytes',
            ]);
            $this->assert($descriptor['index'] === $position);
            $this->assert(isset($kindOrder[$descriptor['kind']]));
            $this->assert($kindOrder[$descriptor['kind']] >= $lastKind);
            $lastKind = $kindOrder[$descriptor['kind']];
            $this->assert(is_int($descriptor['record_count']) && $descriptor['record_count'] > 0);
            $this->assertDigest($descriptor['sha256'] ?? null);
            $this->assert($descriptor['compression'] === 'gzip');
            $this->assert(is_int($descriptor['uncompressed_bytes']) && $descriptor['uncompressed_bytes'] > 0);
            $this->assert(is_int($descriptor['compressed_bytes']) && $descriptor['compressed_bytes'] > 0);
            $this->assert($descriptor['uncompressed_bytes'] <= min((int) config('devboard.artifacts.max_chunk_bytes'), 8 * 1024 * 1024));
            $this->assert($descriptor['compressed_bytes'] <= min((int) config('devboard.artifacts.max_chunk_bytes'), 8 * 1024 * 1024));
            $this->assertDigest($descriptor['compressed_sha256'] ?? null);
            $totals[$descriptor['kind']] += $descriptor['record_count'];
            $expectedUncompressed += $descriptor['uncompressed_bytes'];
            $expectedCompressed += $descriptor['compressed_bytes'];
        }

        foreach (HadesGraphImportChunk::KINDS as $kind) {
            $this->assert($totals[$kind] === (int) ($counts[$kind] ?? 0));
        }
        $maxArtifactBytes = (int) config('devboard.artifacts.max_artifact_bytes');
        $this->assert($canonicalManifestBytes <= $maxArtifactBytes);
        $this->assert($expectedUncompressed <= $maxArtifactBytes - $canonicalManifestBytes);
        $this->assert($expectedCompressed <= (int) config('devboard.artifacts.max_artifact_bytes'));

        $semantic = $manifest;
        unset($semantic['generated_at']);

        return [
            'semantic_sha256' => $canonicalizer->sha256($semantic),
            'expected_chunks' => count($chunks),
            'expected_uncompressed_bytes' => $expectedUncompressed,
            'expected_compressed_bytes' => $expectedCompressed,
            'completeness_status' => (string) $contract['completeness']['status'],
        ];
    }

    /** @param array<string, mixed> $value */
    private function assertObjectKeys(array $value, array $allowed): void
    {
        $this->assert(array_diff(array_keys($value), $allowed) === [] && array_diff($allowed, array_keys($value)) === []);
    }

    private function assertDigest(mixed $value): void
    {
        $this->assert(is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1);
    }

    private function assertTimestamp(mixed $value): void
    {
        $this->assert(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $value) === 1);
    }

    private function assert(bool $condition): void
    {
        if (! $condition) {
            throw new GraphV2ImportException('graph_manifest_invalid', 'Graph manifest does not match the Graph Bundle v2 contract.');
        }
    }
}
