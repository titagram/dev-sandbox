<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImport;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class GraphV2Normalizer implements GraphV2NormalizerContract
{
    private const int BATCH_SIZE = 1000;

    /** @var list<string> */
    private const array KINDS = ['entrypoints', 'nodes', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties'];

    /**
     * @param  iterable<array{kind:string,index:int,records:list<\stdClass>}>  $batches
     */
    public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void
    {
        DB::transaction(function () use ($import): void {
            DB::table('hades_graph_import_references')->where('graph_import_id', $import->id)->delete();
            DB::table('hades_graph_import_file_paths')->where('graph_import_id', $import->id)->delete();
            DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->delete();
        });

        $counts = array_fill_keys(self::KINDS, 0);
        $ordinals = array_fill_keys(self::KINDS, 0);
        $lastIds = array_fill_keys(self::KINDS, null);
        $lastChunkIndex = -1;
        $lastKindPosition = -1;

        foreach ($batches as $batch) {
            $this->assertBatch($batch);
            $this->assertChunkOrder($batch, $lastChunkIndex, $lastKindPosition);
            $heartbeat(true);
            $keys = [];
            $files = [];
            foreach ($batch['records'] as $record) {
                $heartbeat(false);
                $this->assertRecordScope($import, $record);
                $kind = $batch['kind'];
                $id = $this->recordId($import, $kind, $record);
                if (is_string($lastIds[$kind]) && strcmp($lastIds[$kind], $id) >= 0) {
                    throw new GraphV2ImportException('graph_validation_record_order_invalid', 'Graph record IDs are not strictly increasing across chunks.');
                }
                $lastIds[$kind] = $id;
                $metadata = $this->recordMetadata($kind, $record);
                $keys[] = [
                    'graph_import_id' => $import->id,
                    'record_kind' => $kind,
                    'public_id' => $id,
                    'record_subkind' => $metadata['record_subkind'],
                    'reason_code' => $metadata['reason_code'],
                    'identity_variant' => $metadata['identity_variant'],
                    'owner_public_id' => $metadata['owner_public_id'],
                    'aux_public_id' => $metadata['aux_public_id'],
                    'language' => $metadata['language'],
                    'analysis_status' => $metadata['analysis_status'],
                    'flow_public_id' => $metadata['flow_public_id'],
                    'edge_public_id' => $metadata['edge_public_id'],
                    'source_node_public_id' => $metadata['source_node_public_id'],
                    'target_node_public_id' => $metadata['target_node_public_id'],
                    'uncertainty_public_id' => $metadata['uncertainty_public_id'],
                    'root_node_public_id' => $metadata['root_node_public_id'],
                    'stage_from' => $metadata['stage_from'],
                    'stage_to' => $metadata['stage_to'],
                    'async_context' => $metadata['async_context'],
                    'async_child_flow_id' => $metadata['async_child_flow_id'],
                    'relation' => $metadata['relation'],
                    'edge_flow' => $metadata['edge_flow'],
                    'branch_group_id' => $metadata['branch_group_id'],
                    'occurrence_owner_public_id' => $metadata['occurrence_owner_public_id'],
                    'backbone_role' => $metadata['backbone_role'],
                    'omission_reason' => $metadata['omission_reason'],
                    'identity_digest' => $metadata['identity_digest'],
                    'entrypoint_identity_digest' => $metadata['entrypoint_identity_digest'],
                    'count_hint' => $metadata['count_hint'],
                    'flow_counts' => $metadata['flow_counts'],
                    'flow_capabilities' => $metadata['flow_capabilities'],
                    'completeness_status' => $metadata['completeness_status'],
                    'stage_counts' => $metadata['stage_counts'],
                    'chunk_index' => $batch['index'],
                    'record_ordinal' => $ordinals[$kind]++,
                ];
                $file = $this->filePath($import, $kind, $record);
                if ($file !== null) {
                    $files[] = [
                        'graph_import_id' => $import->id,
                        'path' => $file['path'],
                        'file_node_public_id' => $id,
                        'file_sha256' => $file['sha256'],
                    ];
                }
                $counts[$kind]++;
            }

            $this->insertRows($keys, 'graph_validation_record_collision', 'Graph record identity collides within the import.');
            $this->insertRows($files, 'graph_validation_file_identity_invalid', 'A source path or file node identity collides within the import.');
            $heartbeat(true);
        }

        foreach (self::KINDS as $kind) {
            if ($counts[$kind] !== (int) ($import->manifest['counts'][$kind] ?? 0)) {
                throw new GraphV2ImportException('graph_validation_count_mismatch', 'Graph record counts do not match the manifest.');
            }
        }
    }

    /**
     * @param  iterable<array{kind:string,index:int,records:list<\stdClass>}>  $batches
     * @return array{artifact_graph_version:string}
     */
    public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array
    {
        $streams = [];
        $recordSeen = array_fill_keys(self::KINDS, 0);
        $lastIds = array_fill_keys(self::KINDS, null);
        $lastChunkIndex = -1;
        $lastKindPosition = -1;
        $canonicalizer = app(GraphV2Canonicalizer::class);
        $entrypointIdentityDigests = [];

        try {
            foreach ($batches as $batch) {
                $this->assertBatch($batch);
                $this->assertChunkOrder($batch, $lastChunkIndex, $lastKindPosition);
                $heartbeat(true);
                $references = [];
                $fileLookup = $this->loadFilePathLookup($import, $batch['records']);
                $stream = $streams[$batch['kind']] ??= $this->openArrayStream();
                foreach ($batch['records'] as $record) {
                    $heartbeat(false);
                    $this->assertRecordScope($import, $record);
                    $kind = $batch['kind'];
                    $id = $this->recordId($import, $kind, $record);
                    if (is_string($lastIds[$kind]) && strcmp($lastIds[$kind], $id) >= 0) {
                        throw new GraphV2ImportException('graph_validation_record_order_invalid', 'Graph record IDs are not strictly increasing across chunks.');
                    }
                    $lastIds[$kind] = $id;
                    $this->assertEvidenceAndPaths($import, $kind, $record, $fileLookup);
                    if ($kind === 'entrypoints') {
                        $entrypointMetadata = $this->recordMetadata($kind, $record);
                        $entrypointIdentityDigests[$id] = $entrypointMetadata['entrypoint_identity_digest'];
                    }
                    foreach ($this->referencesFor($kind, $id, $record) as $reference) {
                        $references[] = $reference + ['graph_import_id' => $import->id];
                    }
                    $canonical = $canonicalizer->canonicalJson($record);
                    if ($recordSeen[$kind]++ > 0 && fwrite($stream, ',') !== 1) {
                        throw new GraphV2InfrastructureException('Graph validation staging storage could not be written.');
                    }
                    if (fwrite($stream, $canonical) !== strlen($canonical)) {
                        throw new GraphV2InfrastructureException('Graph validation staging storage could not be written.');
                    }
                }
                foreach (array_chunk($references, self::BATCH_SIZE) as $referenceBatch) {
                    $this->insertRows($referenceBatch, 'graph_validation_reference_invalid', 'Graph reference staging data is invalid.');
                    $heartbeat(true);
                }
                $heartbeat(true);
            }

            foreach ($streams as $stream) {
                if (fwrite($stream, ']') !== 1 || ! rewind($stream)) {
                    throw new GraphV2InfrastructureException('Graph validation staging storage could not be finalized.');
                }
            }
            foreach (self::KINDS as $kind) {
                if ($recordSeen[$kind] !== (int) ($import->manifest['counts'][$kind] ?? 0)) {
                    throw new GraphV2ImportException('graph_validation_count_mismatch', 'Graph record counts do not match the manifest.');
                }
            }
            $this->assertSecondPassEntrypointIdentities($import, $entrypointIdentityDigests);
            $this->assertPostPassIntegrity($import);

            return ['artifact_graph_version' => $this->semanticDigest($import, $streams)];
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /** @param array{kind:string,index:int,records:list<\stdClass>} $batch */
    private function assertBatch(array $batch): void
    {
        if (! isset($batch['kind'], $batch['index'], $batch['records'])
            || ! in_array($batch['kind'], self::KINDS, true)
            || ! is_int($batch['index'])
            || ! is_array($batch['records'])
            || ! array_is_list($batch['records'])
            || count($batch['records']) > self::BATCH_SIZE) {
            throw new GraphV2ImportException('graph_validation_chunk_invalid', 'Graph validation batch is invalid.');
        }
    }

    /** @param array{kind:string,index:int,records:list<\stdClass>} $batch */
    private function assertChunkOrder(array $batch, int &$lastChunkIndex, int &$lastKindPosition): void
    {
        $kindPosition = array_search($batch['kind'], self::KINDS, true);
        if (! is_int($kindPosition)
            || $batch['index'] < $lastChunkIndex
            || ($batch['index'] === $lastChunkIndex && $kindPosition < $lastKindPosition)
            || ($batch['index'] > $lastChunkIndex && $kindPosition < $lastKindPosition)) {
            throw new GraphV2ImportException('graph_validation_chunk_order_invalid', 'Graph chunks are not in the declared kind/index order.');
        }
        $lastChunkIndex = max($lastChunkIndex, $batch['index']);
        $lastKindPosition = $kindPosition;
    }

    private function recordId(HadesGraphImport $import, string $kind, \stdClass $record): string
    {
        $id = $record->id ?? null;
        if (! is_string($id) || $id === '') {
            throw new GraphV2ImportException('graph_validation_record_invalid', 'Graph record ID is invalid.');
        }

        app(GraphV2IdentityValidator::class)->assertRecord($import, $kind, $record);

        return $id;
    }

    /** @return array<string,mixed> */
    private function recordMetadata(string $kind, \stdClass $record): array
    {
        $identity = $record->identity ?? null;
        $owner = null;
        if ($kind === 'structures') {
            $owner = is_object($record->owner_node_id ?? null) ? null : ($record->owner_node_id ?? null);
        } elseif ($kind === 'flows') {
            $owner = $record->entrypoint_id ?? null;
        } elseif ($kind === 'nodes' && $identity instanceof \stdClass && in_array($identity->variant ?? null, ['source_occurrence', 'anonymous_callable'], true)) {
            $owner = $identity->owner_node_id ?? null;
        }

        $properties = $record->properties ?? null;
        $stageCounts = $record->stage_counts ?? null;
        $completeness = $record->completeness ?? null;
        $flowPublicId = $kind === 'flow_steps' ? ($record->flow_id ?? null) : null;
        $edgePublicId = $kind === 'flow_steps' ? ($record->edge_id ?? null) : null;
        $flowCounts = $kind === 'flows' ? (object) [
            'terminal_count' => $record->terminal_count ?? null,
            'linked_async_flow_count' => $record->linked_async_flow_count ?? null,
            'uncertainty_count' => $record->uncertainty_count ?? null,
        ] : null;
        $completenessCapabilities = $kind === 'flows' && $completeness instanceof \stdClass ? ($completeness->capabilities ?? null) : null;
        $entrypointIdentity = null;
        if ($kind === 'nodes' && $identity instanceof \stdClass && ($identity->variant ?? null) === 'entrypoint') {
            $entrypointIdentity = $identity->entrypoint_identity ?? null;
        } elseif ($kind === 'entrypoints') {
            $entrypointIdentity = $this->entrypointIdentity($record);
        }
        $occurrence = $kind === 'edges' && ($record->occurrence ?? null) instanceof \stdClass ? $record->occurrence : null;
        $propertiesOmissionReason = $kind === 'nodes' && ($record->kind ?? null) === 'file' && $properties instanceof \stdClass && is_string($properties->omission_reason ?? null)
            ? $properties->omission_reason : null;

        return [
            'record_subkind' => $kind === 'uncertainties' && is_string($record->resolution_kind ?? null) ? $record->resolution_kind : (is_string($record->kind ?? null) ? $record->kind : (is_string($record->entrypoint_kind ?? null) ? $record->entrypoint_kind : null)),
            'reason_code' => $kind === 'uncertainties' && is_string($record->reason_code ?? null) ? $record->reason_code : null,
            'identity_variant' => $kind === 'nodes' && is_string($identity?->variant ?? null) ? $identity->variant : null,
            'owner_public_id' => is_string($owner) ? $owner : null,
            'aux_public_id' => $kind === 'uncertainties' && is_string($record->candidate_set_knowledge ?? null) ? $record->candidate_set_knowledge : null,
            'language' => is_string($record->language ?? null) ? $record->language : ($identity instanceof \stdClass && is_string($identity->language ?? null) ? $identity->language : null),
            'analysis_status' => $kind === 'nodes' && $record->kind === 'file' && $properties instanceof \stdClass && is_string($properties->analysis_status ?? null) ? $properties->analysis_status : ($kind === 'entrypoints' ? 'analyzed' : null),
            'flow_public_id' => is_string($flowPublicId) ? $flowPublicId : null,
            'edge_public_id' => is_string($edgePublicId) ? $edgePublicId : null,
            'source_node_public_id' => $kind === 'edges' && is_string($record->source_id ?? null) ? $record->source_id : null,
            'target_node_public_id' => $kind === 'edges' && is_string($record->target_id ?? null) ? $record->target_id : null,
            'uncertainty_public_id' => $kind === 'edges' && is_string($record->uncertainty_id ?? null) ? $record->uncertainty_id : null,
            'root_node_public_id' => $kind === 'flows' && is_string($record->root_node_id ?? null) ? $record->root_node_id : null,
            'stage_from' => $kind === 'flow_steps' && is_string($record->stage_from ?? null) ? $record->stage_from : null,
            'stage_to' => $kind === 'flow_steps' && is_string($record->stage_to ?? null) ? $record->stage_to : null,
            'async_context' => $kind === 'flow_steps' && is_string($record->async_context ?? null) ? $record->async_context : null,
            'async_child_flow_id' => $kind === 'flow_steps' && is_string($record->async_child_flow_id ?? null) ? $record->async_child_flow_id : null,
            'relation' => $kind === 'edges' && is_string($record->relation ?? null) ? $record->relation : null,
            'edge_flow' => $kind === 'edges' && is_string($record->flow ?? null) ? $record->flow : null,
            'branch_group_id' => in_array($kind, ['edges', 'flow_steps'], true) && is_string($record->branch_group_id ?? null) ? $record->branch_group_id : null,
            'occurrence_owner_public_id' => $occurrence instanceof \stdClass && is_string($occurrence->owner_node_id ?? null) ? $occurrence->owner_node_id : null,
            'backbone_role' => $kind === 'flow_steps' && is_string($record->backbone_role ?? null) ? $record->backbone_role : null,
            'omission_reason' => $propertiesOmissionReason,
            'identity_digest' => $kind === 'nodes' && $identity instanceof \stdClass ? app(GraphV2Canonicalizer::class)->sha256($identity) : ($kind === 'uncertainties' && is_string($record->fingerprint ?? null) ? $record->fingerprint : null),
            'entrypoint_identity_digest' => $entrypointIdentity instanceof \stdClass ? app(GraphV2Canonicalizer::class)->sha256($entrypointIdentity) : null,
            'count_hint' => in_array($kind, ['flows'], true) && is_int($record->represented_step_count ?? null) ? $record->represented_step_count : null,
            'flow_counts' => $flowCounts instanceof \stdClass ? json_encode($flowCounts, JSON_THROW_ON_ERROR) : null,
            'flow_capabilities' => $completenessCapabilities instanceof \stdClass ? json_encode($completenessCapabilities, JSON_THROW_ON_ERROR) : null,
            'completeness_status' => $kind === 'flows' && $completeness instanceof \stdClass && is_string($completeness->status ?? null) ? $completeness->status : null,
            'stage_counts' => $kind === 'flows' && $stageCounts instanceof \stdClass ? json_encode($stageCounts, JSON_THROW_ON_ERROR) : null,
        ];
    }

    private function entrypointIdentity(\stdClass $record): ?\stdClass
    {
        $fields = ['entrypoint_kind', 'framework', 'method_semantics', 'methods', 'public_path', 'public_name', 'trigger', 'match_constraints', 'registration_occurrence'];
        foreach ($fields as $field) {
            if (! property_exists($record, $field)) {
                return null;
            }
        }
        $identity = new \stdClass;
        foreach ($fields as $field) {
            $identity->{$field} = $record->{$field};
        }

        return $identity;
    }

    /** @param array<string,string|null> $entrypointIdentityDigests */
    private function assertSecondPassEntrypointIdentities(HadesGraphImport $import, array $entrypointIdentityDigests): void
    {
        if ($entrypointIdentityDigests === []) {
            return;
        }

        foreach (DB::table('hades_graph_import_record_keys')
            ->where('graph_import_id', $import->id)
            ->where('record_kind', 'entrypoints')
            ->whereIn('public_id', array_keys($entrypointIdentityDigests))
            ->select('public_id', 'entrypoint_identity_digest')
            ->cursor() as $staged) {
            if (($entrypointIdentityDigests[(string) $staged->public_id] ?? null) !== $staged->entrypoint_identity_digest) {
                throw new GraphV2ImportException('graph_validation_entrypoint_pair_invalid', 'The second-pass entrypoint pair identity differs from the staged first-pass record.');
            }
        }
    }

    /** @return array{path:string,sha256:string}|null */
    private function filePath(HadesGraphImport $import, string $kind, \stdClass $record): ?array
    {
        if ($kind !== 'nodes' || ($record->kind ?? null) !== 'file') {
            return null;
        }
        $identity = $record->identity ?? null;
        $properties = $record->properties ?? null;
        $path = $identity instanceof \stdClass ? ($identity->path ?? null) : null;
        $sha256 = $properties instanceof \stdClass ? ($properties->file_sha256 ?? null) : null;
        if (! is_string($path) || ! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new GraphV2ImportException('graph_validation_file_identity_invalid', 'File node identity is invalid.');
        }
        if (($identity->workspace_binding_id ?? null) !== $import->workspace_binding_id) {
            throw new GraphV2ImportException('graph_validation_scope_mismatch', 'Graph record scope does not match the import.');
        }

        return ['path' => $path, 'sha256' => $sha256];
    }

    /** @param array<string,object> $fileLookup */
    private function assertEvidenceAndPaths(HadesGraphImport $import, string $recordKind, \stdClass $record, array $fileLookup): void
    {
        foreach ($this->recordPaths($record) as $path) {
            $this->assertFilePath($fileLookup, $path);
        }
        if (! in_array($recordKind, ['entrypoints', 'nodes', 'structures', 'edges'], true)) {
            return;
        }
        $evidence = $record->evidence ?? null;
        if (! $evidence instanceof \stdClass || ! ($evidence->primary ?? null) instanceof \stdClass) {
            throw new GraphV2ImportException('graph_validation_evidence_invalid', 'Graph evidence envelope is invalid.');
        }
        $this->assertEvidenceItem($import, $recordKind, $record, $evidence->primary, $fileLookup);
        foreach (($evidence->supporting ?? []) as $supporting) {
            if (! $supporting instanceof \stdClass) {
                throw new GraphV2ImportException('graph_validation_evidence_invalid', 'Graph supporting evidence is invalid.');
            }
            $this->assertEvidenceItem($import, $recordKind, $record, $supporting, $fileLookup);
        }
    }

    /** @param list<\stdClass> $records @return array<string,object> */
    private function loadFilePathLookup(HadesGraphImport $import, array $records): array
    {
        $paths = [];
        foreach ($records as $record) {
            foreach ($this->recordPaths($record) as $path) {
                $paths[$path] = true;
            }
            $evidence = $record->evidence ?? null;
            $items = $evidence instanceof \stdClass
                ? array_merge([$evidence->primary ?? null], (array) ($evidence->supporting ?? []))
                : [];
            foreach ($items as $item) {
                $locator = $item instanceof \stdClass ? ($item->source_locator ?? null) : null;
                if ($locator instanceof \stdClass && is_string($locator->path ?? null)) {
                    $paths[$locator->path] = true;
                }
            }
        }
        if ($paths === []) {
            return [];
        }

        $lookup = [];
        foreach (array_chunk(array_keys($paths), 400) as $pathChunk) {
            foreach (DB::table('hades_graph_import_file_paths')
                ->where('graph_import_id', $import->id)
                ->whereIn('path', $pathChunk)
                ->get() as $file) {
                $lookup[(string) $file->path] = $file;
            }
        }

        return $lookup;
    }

    /** @return list<string> */
    private function recordPaths(\stdClass $record): array
    {
        $paths = [];
        foreach ([$record->identity ?? null, $record->location ?? null, $record->occurrence ?? null, $record->registration_occurrence ?? null] as $object) {
            if ($object instanceof \stdClass && is_string($object->path ?? null)) {
                $paths[] = $object->path;
            }
        }
        foreach (($record->source_refs ?? []) as $sourceRef) {
            if ($sourceRef instanceof \stdClass && is_string($sourceRef->path ?? null)) {
                $paths[] = $sourceRef->path;
            }
        }
        $evidence = $record->evidence ?? null;
        $items = $evidence instanceof \stdClass
            ? array_merge([$evidence->primary ?? null], (array) ($evidence->supporting ?? []))
            : [];
        foreach ($items as $item) {
            $locator = $item instanceof \stdClass ? ($item->source_locator ?? null) : null;
            if ($locator instanceof \stdClass && is_string($locator->path ?? null)) {
                $paths[] = $locator->path;
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param array<string,object>|null $fileLookup */
    private function assertEvidenceItem(HadesGraphImport $import, string $recordKind, \stdClass $record, \stdClass $item, ?array $fileLookup = null): void
    {
        if (! in_array($item->origin ?? null, ['verified_from_code', 'inferred', 'unresolved'], true)
            || ! is_string($item->extractor ?? null)
            || ! is_string($item->source_fingerprint ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $item->source_fingerprint) !== 1
            || ! (($item->inference_rule ?? null) === null || is_string($item->inference_rule))) {
            throw new GraphV2ImportException('graph_validation_evidence_invalid', 'Graph evidence item is invalid.');
        }
        $locator = $item->source_locator ?? null;
        if (! $locator instanceof \stdClass || ! in_array($locator->kind ?? null, ['file', 'ast', 'config'], true)) {
            throw new GraphV2ImportException('graph_validation_evidence_invalid', 'Server-derived evidence locators are forbidden in producer artifacts.');
        }
        $isFileNode = $recordKind === 'nodes' && ($record->kind ?? null) === 'file';
        if (($locator->kind === 'file') !== $isFileNode) {
            throw new GraphV2ImportException('graph_validation_evidence_invalid', 'File evidence is legal only for file inventory nodes.');
        }
        $path = $locator->path ?? null;
        if (! is_string($path) || $path === '' || ($locator->kind === 'ast' && ! is_string($locator->structural_path ?? null)) || ($locator->kind === 'config' && ! is_string($locator->structural_pointer ?? null))) {
            throw new GraphV2ImportException('graph_validation_evidence_invalid', 'Graph evidence locator is invalid.');
        }
        if ($fileLookup === null) {
            $fileLookup = [];
            $row = DB::table('hades_graph_import_file_paths')->where('graph_import_id', $import->id)->where('path', $path)->first();
            if ($row !== null) {
                $fileLookup[$path] = $row;
            }
        }
        $file = $fileLookup[$path] ?? null;
        if ($file === null) {
            throw new GraphV2ImportException('graph_validation_evidence_missing', 'Graph evidence path is not represented by a file node.');
        }
        if ($locator->kind === 'file') {
            $identity = $record->identity ?? null;
            $properties = $record->properties ?? null;
            if (! $identity instanceof \stdClass || ! $properties instanceof \stdClass
                || ($identity->path ?? null) !== $path || $file->file_node_public_id !== ($record->id ?? null)
                || $file->file_sha256 !== ($properties->file_sha256 ?? null)) {
                throw new GraphV2ImportException('graph_validation_evidence_invalid', 'File evidence must bind to the same file node and digest.');
            }
        }
        $preimage = ['file_sha256' => (string) $file->file_sha256, 'occurrence_kind' => $locator->kind, 'path' => $path];
        if ($locator->kind === 'ast') {
            $preimage['structural_path'] = $locator->structural_path;
        } elseif ($locator->kind === 'config') {
            $preimage['structural_pointer'] = $locator->structural_pointer;
        }
        if (! hash_equals(app(GraphV2Canonicalizer::class)->sha256($preimage), $item->source_fingerprint)) {
            throw new GraphV2ImportException('graph_validation_evidence_invalid', 'Graph evidence fingerprint does not match its exact locator preimage.');
        }
    }

    /** @param array<string,object> $fileLookup */
    private function assertFilePath(array $fileLookup, mixed $path): void
    {
        if (! is_string($path) || $path === '') {
            throw new GraphV2ImportException('graph_validation_evidence_invalid', 'Graph evidence path is invalid.');
        }
        if (! isset($fileLookup[$path])) {
            throw new GraphV2ImportException('graph_validation_evidence_missing', 'Graph evidence path is not represented by a file node.');
        }
    }

    private function assertRecordScope(HadesGraphImport $import, \stdClass $record): void
    {
        $identity = $record->identity ?? null;
        if ($identity instanceof \stdClass && isset($identity->workspace_binding_id) && $identity->workspace_binding_id !== $import->workspace_binding_id) {
            throw new GraphV2ImportException('graph_validation_scope_mismatch', 'Graph record scope does not match the import.');
        }
    }

    /** @return list<array{owner_record_kind:string,owner_public_id:string,reference_kind:string,target_record_kind:string,target_public_id:string}> */
    private function referencesFor(string $ownerKind, string $ownerId, \stdClass $record): array
    {
        $references = [];
        $field = static fn (\stdClass $object, string $name): mixed => property_exists($object, $name) ? $object->{$name} : null;
        $add = function (string $referenceKind, string $targetKind, mixed $target) use (&$references, $ownerKind, $ownerId): void {
            if ($target === null) {
                return;
            }
            if (! is_string($target) || ! $this->idMatchesKind($target, $targetKind)) {
                throw new GraphV2ImportException('graph_validation_reference_invalid', 'Graph reference field has the wrong target kind.');
            }
            $references[] = ['owner_record_kind' => $ownerKind, 'owner_public_id' => $ownerId, 'reference_kind' => $referenceKind, 'target_record_kind' => $targetKind, 'target_public_id' => $target];
        };

        switch ($ownerKind) {
            case 'entrypoints':
                $add('entrypoint_id', 'nodes', $ownerId);
                $add('handler_node_id', 'nodes', $field($record, 'handler_node_id'));
                $add('uncertainty_id', 'uncertainties', $field($record, 'uncertainty_id'));
                break;
            case 'nodes':
                $identity = $field($record, 'identity');
                if ($identity instanceof \stdClass && in_array($identity->variant ?? null, ['source_occurrence', 'anonymous_callable'], true)) {
                    $add('identity.owner_node_id', 'nodes', $field($identity, 'owner_node_id'));
                }
                if (($record->kind ?? null) === 'unknown_boundary') {
                    $add('uncertainty_id', 'uncertainties', $field($record, 'uncertainty_id'));
                } elseif ($field($record, 'uncertainty_id') !== null) {
                    throw new GraphV2ImportException('graph_validation_reference_invalid', 'Only unknown boundary nodes may reference uncertainty.');
                }
                break;
            case 'structures':
                $add('owner_node_id', 'nodes', $field($record, 'owner_node_id'));
                $add('continuation_node_id', 'nodes', $field($record, 'continuation_node_id'));
                $add('parent_structure_id', 'structures', $field($record, 'parent_structure_id'));
                break;
            case 'edges':
                $relation = $field($record, 'relation');
                $structuralRelations = ['declares', 'contains', 'imports', 'inherits', 'implements', 'references', 'tests', 'documents', 'maps_to'];
                $callSite = $field($record, 'call_site_id');
                if (in_array($relation, $structuralRelations, true) && ($callSite !== null || $field($record, 'branch_group_id') !== null || $field($record, 'exception_scope_id') !== null || $field($record, 'order') !== null)) {
                    throw new GraphV2ImportException('graph_validation_reference_invalid', 'Structural graph edges cannot carry executable structure references.');
                }
                if (in_array($relation, ['invokes', 'returns_to'], true) !== ($callSite !== null)) {
                    throw new GraphV2ImportException('graph_validation_reference_invalid', 'Invocation and return edges require an exact call-site structure.');
                }
                $add('source_id', 'nodes', $field($record, 'source_id'));
                $add('target_id', 'nodes', $field($record, 'target_id'));
                $occurrence = $field($record, 'occurrence');
                if ($occurrence instanceof \stdClass) {
                    $add('occurrence.owner_node_id', 'nodes', $field($occurrence, 'owner_node_id'));
                }
                $add('call_site_id', 'structures', $callSite);
                $add('branch_group_id', 'structures', $field($record, 'branch_group_id'));
                $add('exception_scope_id', 'structures', $field($record, 'exception_scope_id'));
                $add('uncertainty_id', 'uncertainties', $field($record, 'uncertainty_id'));
                break;
            case 'flows':
                $add('entrypoint_id', 'entrypoints', $field($record, 'entrypoint_id'));
                $add('root_node_id', 'nodes', $field($record, 'root_node_id'));
                break;
            case 'flow_steps':
                $add('flow_id', 'flows', $field($record, 'flow_id'));
                $add('edge_id', 'edges', $field($record, 'edge_id'));
                $add('branch_group_id', 'structures', $field($record, 'branch_group_id'));
                $add('async_child_flow_id', 'flows', $field($record, 'async_child_flow_id'));
                break;
            case 'uncertainties':
                $subject = $field($record, 'subject');
                if (! $subject instanceof \stdClass) {
                    throw new GraphV2ImportException('graph_validation_reference_invalid', 'Uncertainty subject is invalid.');
                }
                $subjectKeys = array_keys(get_object_vars($subject));
                sort($subjectKeys);
                if (($record->resolution_kind ?? null) === 'call_target' && $subjectKeys === ['call_site_id'] && $field($subject, 'call_site_id') !== null) {
                    $add('subject.call_site_id', 'structures', $field($subject, 'call_site_id'));
                } elseif (in_array($record->resolution_kind ?? null, ['entrypoint_handler', 'async_target', 'exception_target', 'framework_target', 'external_target'], true) && $subjectKeys === ['edge_id'] && $field($subject, 'edge_id') !== null) {
                    $add('subject.edge_id', 'edges', $field($subject, 'edge_id'));
                } else {
                    throw new GraphV2ImportException('graph_validation_reference_invalid', 'Uncertainty resolution kind and subject discriminator are not part of the closed graph reference matrix.');
                }
                foreach ((array) ($record->candidate_target_node_ids ?? []) as $target) {
                    $add('candidate_target_node_ids', 'nodes', $target);
                }
                foreach ((array) ($record->candidate_edge_ids ?? []) as $target) {
                    $add('candidate_edge_ids', 'edges', $target);
                }
                break;
        }

        return $references;
    }

    private function assertPostPassIntegrity(HadesGraphImport $import): void
    {
        $missingTarget = DB::table('hades_graph_import_references as reference')
            ->leftJoin('hades_graph_import_record_keys as target', function ($join): void {
                $join->on('target.graph_import_id', '=', 'reference.graph_import_id')->on('target.record_kind', '=', 'reference.target_record_kind')->on('target.public_id', '=', 'reference.target_public_id');
            })->where('reference.graph_import_id', $import->id)->whereNull('target.public_id')->exists();
        $missingOwner = DB::table('hades_graph_import_references as reference')
            ->leftJoin('hades_graph_import_record_keys as owner', function ($join): void {
                $join->on('owner.graph_import_id', '=', 'reference.graph_import_id')->on('owner.record_kind', '=', 'reference.owner_record_kind')->on('owner.public_id', '=', 'reference.owner_public_id');
            })->where('reference.graph_import_id', $import->id)->whereNull('owner.public_id')->exists();
        if ($missingTarget || $missingOwner) {
            throw new GraphV2ImportException('graph_validation_reference_missing', 'A graph reference target is missing or belongs to another import.');
        }
        $this->assertEntrypointPairs($import);
        $this->assertOwnerVariants($import);
        $this->assertStructureSubtypes($import);
        $this->assertStructureOwners($import);
        $this->assertBoundaryClosure($import);
        $this->assertFlowMembershipAndIsolation($import);
        $this->assertCoverageAndFlowFacts($import);
    }

    private function assertFlowMembershipAndIsolation(HadesGraphImport $import): void
    {
        $coverage = data_get($import->manifest, 'graph_contract.coverage');
        $recordKeys = DB::table('hades_graph_import_record_keys');
        if (! is_array($coverage) || $coverage === [] || ! $recordKeys->where('graph_import_id', $import->id)->whereIn('record_kind', ['entrypoints', 'flows', 'flow_steps'])->exists()) {
            return;
        }
        $structuralRelations = ['declares', 'contains', 'imports', 'inherits', 'implements', 'references', 'tests', 'documents', 'maps_to'];
        $structuralStep = DB::table('hades_graph_import_record_keys as step')
            ->join('hades_graph_import_record_keys as edge', function ($join): void {
                $join->on('edge.graph_import_id', '=', 'step.graph_import_id')->on('edge.public_id', '=', 'step.edge_public_id')->where('edge.record_kind', 'edges');
            })->where('step.graph_import_id', $import->id)->where('step.record_kind', 'flow_steps')->whereIn('edge.relation', $structuralRelations)->exists();
        $branchMismatch = DB::table('hades_graph_import_record_keys as step')->join('hades_graph_import_record_keys as edge', function ($join): void {
            $join->on('edge.graph_import_id', '=', 'step.graph_import_id')->on('edge.public_id', '=', 'step.edge_public_id')->where('edge.record_kind', 'edges');
        })->where('step.graph_import_id', $import->id)->where('step.record_kind', 'flow_steps')->whereRaw('NOT ((step.branch_group_id = edge.branch_group_id) OR (step.branch_group_id IS NULL AND edge.branch_group_id IS NULL))')->exists();
        $asyncContextMismatch = DB::table('hades_graph_import_record_keys as step')->join('hades_graph_import_record_keys as flow', function ($join): void {
            $join->on('flow.graph_import_id', '=', 'step.graph_import_id')->on('flow.public_id', '=', 'step.flow_public_id')->where('flow.record_kind', 'flows');
        })->where('step.graph_import_id', $import->id)->where('step.record_kind', 'flow_steps')->whereRaw("((flow.record_subkind = 'async_flow' AND step.async_context <> 'linked_async') OR (flow.record_subkind <> 'async_flow' AND step.async_context <> 'synchronous'))")->exists();
        $asyncLinkMismatch = DB::table('hades_graph_import_record_keys as step')->join('hades_graph_import_record_keys as edge', function ($join): void {
            $join->on('edge.graph_import_id', '=', 'step.graph_import_id')->on('edge.public_id', '=', 'step.edge_public_id')->where('edge.record_kind', 'edges');
        })->where('step.graph_import_id', $import->id)->where('step.record_kind', 'flow_steps')->whereNotNull('step.async_child_flow_id')->where(function ($query): void {
            $query->where('edge.edge_flow', '<>', 'async')->orWhereNull('edge.edge_flow')->orWhere('step.backbone_role', '<>', 'async')->orWhereNull('step.backbone_role');
        })->exists();
        $rootMismatch = DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', 'flows')->where('record_subkind', '<>', 'async_flow')->whereColumn('root_node_public_id', '<>', 'owner_public_id')->exists();
        $entrypointKindMismatch = DB::table('hades_graph_import_record_keys as flow')->join('hades_graph_import_record_keys as entrypoint', function ($join): void {
            $join->on('entrypoint.graph_import_id', '=', 'flow.graph_import_id')->on('entrypoint.public_id', '=', 'flow.owner_public_id')->where('entrypoint.record_kind', 'entrypoints');
        })->where('flow.graph_import_id', $import->id)->where('flow.record_kind', 'flows')->where(function ($query): void {
            $query->where(function ($nested): void {
                $nested->where('flow.record_subkind', 'request_lifecycle')->where('entrypoint.record_subkind', '<>', 'http_route');
            })->orWhere(function ($nested): void {
                $nested->where('entrypoint.record_subkind', 'http_route')->where('flow.record_subkind', '<>', 'request_lifecycle');
            });
        })->exists();
        $missingSynchronousFlow = DB::table('hades_graph_import_record_keys as entrypoint')->leftJoin('hades_graph_import_record_keys as flow', function ($join): void {
            $join->on('flow.graph_import_id', '=', 'entrypoint.graph_import_id')->on('flow.owner_public_id', '=', 'entrypoint.public_id')->where('flow.record_kind', 'flows')->where('flow.record_subkind', '<>', 'async_flow');
        })->where('entrypoint.graph_import_id', $import->id)->where('entrypoint.record_kind', 'entrypoints')->groupBy('entrypoint.graph_import_id', 'entrypoint.public_id')->havingRaw('COUNT(flow.public_id) <> 1')->exists();
        if ($structuralStep || $branchMismatch || $asyncContextMismatch || $asyncLinkMismatch || $rootMismatch || $entrypointKindMismatch || $missingSynchronousFlow) {
            throw new GraphV2ImportException('graph_validation_flow_membership_invalid', 'Flow step membership, entrypoint flow, or lifecycle edge semantics are invalid.');
        }

        $disconnected = DB::selectOne(<<<'SQL'
            WITH RECURSIVE reachable(graph_import_id, flow_public_id, node_public_id, stage) AS (
                SELECT graph_import_id, public_id, root_node_public_id, 'entry'
                FROM hades_graph_import_record_keys
                WHERE graph_import_id = ? AND record_kind = 'flows'
                UNION
                SELECT reachable.graph_import_id, reachable.flow_public_id, edge.target_node_public_id, step.stage_to
                FROM reachable
                JOIN hades_graph_import_record_keys AS step
                  ON step.graph_import_id = reachable.graph_import_id
                 AND step.flow_public_id = reachable.flow_public_id
                 AND step.record_kind = 'flow_steps'
                 AND step.stage_from = reachable.stage
                JOIN hades_graph_import_record_keys AS edge
                  ON edge.graph_import_id = step.graph_import_id
                 AND edge.public_id = step.edge_public_id
                 AND edge.record_kind = 'edges'
                 AND edge.source_node_public_id = reachable.node_public_id
                WHERE edge.uncertainty_public_id IS NULL
                  AND NOT EXISTS (
                      SELECT 1
                      FROM hades_graph_import_record_keys AS target_node
                      WHERE target_node.graph_import_id = edge.graph_import_id
                        AND target_node.record_kind = 'nodes'
                        AND target_node.public_id = edge.target_node_public_id
                        AND target_node.record_subkind IN ('response', 'redirect', 'abort', 'exception', 'exit')
                  )
            )
            SELECT 1
            FROM hades_graph_import_record_keys AS step
            JOIN hades_graph_import_record_keys AS edge
              ON edge.graph_import_id = step.graph_import_id
             AND edge.public_id = step.edge_public_id
             AND edge.record_kind = 'edges'
            WHERE step.graph_import_id = ?
              AND step.record_kind = 'flow_steps'
              AND NOT EXISTS (
                  SELECT 1 FROM reachable
                  WHERE reachable.graph_import_id = step.graph_import_id
                    AND reachable.flow_public_id = step.flow_public_id
                    AND reachable.node_public_id = edge.source_node_public_id
                    AND reachable.stage = step.stage_from
              )
            LIMIT 1
            SQL, [$import->id, $import->id]);
        if ($disconnected !== null) {
            throw new GraphV2ImportException('graph_validation_flow_membership_invalid', 'A flow step edge is disconnected from its declared flow root.');
        }
    }

    private function assertCoverageAndFlowFacts(HadesGraphImport $import): void
    {
        $coverage = data_get($import->manifest, 'graph_contract.coverage');
        if (! is_array($coverage) || $coverage === []) {
            return;
        }

        $recordCoverage = $coverage['records'] ?? null;
        if (is_array($recordCoverage)) {
            foreach (['nodes', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties'] as $kind) {
                if (array_key_exists($kind, $recordCoverage)
                    && (int) $recordCoverage[$kind] !== (int) DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', $kind)->count()) {
                    throw new GraphV2ImportException('graph_validation_coverage_mismatch', 'Manifest record coverage does not close over staged records.');
                }
            }
        }

        $fileCoverage = $coverage['files'] ?? null;
        $fileDiscovered = 0;
        $representedFiles = (int) DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', 'nodes')->where('record_subkind', 'file')->count();
        $fileStatusCounts = [];
        if (is_array($fileCoverage)) {
            $files = DB::table('hades_graph_import_record_keys')
                ->where('graph_import_id', $import->id)->where('record_kind', 'nodes')->where('record_subkind', 'file');
            $fileDiscovered = (int) ($fileCoverage['discovered'] ?? -1);
            $hashed = (int) ($fileCoverage['hashed'] ?? -1);
            if ($fileDiscovered < $representedFiles || $fileDiscovered !== $hashed) {
                $this->coverageMismatch('File discovery and hash coverage do not close over represented files.');
            }
            $fileStatusCounts = (clone $files)->select('analysis_status', DB::raw('COUNT(*) as aggregate'))->groupBy('analysis_status')->pluck('aggregate', 'analysis_status')->map(static fn (mixed $value): int => (int) $value)->all();
            if (array_sum($fileStatusCounts) !== $representedFiles) {
                $this->coverageMismatch('Represented file analysis statuses do not close.');
            }
            foreach (['analyzed', 'unsupported', 'failed', 'too_large'] as $field) {
                if ((int) ($fileCoverage[$field] ?? -1) !== (int) ($fileStatusCounts[$field] ?? 0)) {
                    $this->coverageMismatch('Manifest file status coverage does not close over represented files.');
                }
            }
            if ((int) ($fileCoverage['budget_omitted'] ?? -1) !== (int) ($fileStatusCounts['budget_omitted'] ?? 0) + ($fileDiscovered - $representedFiles)) {
                $this->coverageMismatch('File omission coverage does not close over discovered and represented files.');
            }
            if ((int) ($fileCoverage['parser_candidates'] ?? -1) > $fileDiscovered || (int) ($fileCoverage['analyzed'] ?? -1) > (int) ($fileCoverage['parser_candidates'] ?? -1)) {
                $this->coverageMismatch('Parser candidate coverage is inconsistent.');
            }
        }

        $entrypointCoverage = $coverage['entrypoints'] ?? null;
        $representedEntrypoints = (int) DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', 'entrypoints')->count();
        $rejectedEntrypoints = 0;
        if (is_array($entrypointCoverage)) {
            $entrypoints = DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->where('record_kind', 'entrypoints');
            $detected = (int) ($entrypointCoverage['detected'] ?? -1);
            $analyzed = (int) ($entrypointCoverage['analyzed'] ?? -1);
            if ($detected < $representedEntrypoints || $analyzed !== $representedEntrypoints || $analyzed > $detected) {
                $this->coverageMismatch('Entrypoint detection and analysis coverage do not close.');
            }
            $rejectedEntrypoints = $detected - $representedEntrypoints;
            $partial = (int) DB::table('hades_graph_import_record_keys')
                ->where('graph_import_id', $import->id)->where('record_kind', 'flows')
                ->where('record_subkind', '<>', 'async_flow')->where('completeness_status', 'partial')->count();
            if ((int) ($entrypointCoverage['partial'] ?? -1) !== $partial + $rejectedEntrypoints || (int) ($entrypointCoverage['partial'] ?? -1) > $detected) {
                $this->coverageMismatch('Entrypoint partial coverage does not close.');
            }
            $byKind = $entrypointCoverage['by_kind'] ?? null;
            if (! is_array($byKind)) {
                $this->coverageMismatch('Entrypoint kind coverage is missing.');
            }
            if (is_array($byKind)) {
                $actualByKind = (clone $entrypoints)->select('record_subkind', DB::raw('COUNT(*) as aggregate'))->groupBy('record_subkind')->pluck('aggregate', 'record_subkind');
                $declaredKindTotal = 0;
                foreach ($byKind as $kind => $expected) {
                    if ($expected !== null) {
                        $declaredKindTotal += (int) $expected;
                    }
                }
                foreach ($actualByKind as $kind => $represented) {
                    if ((int) ($byKind[$kind] ?? 0) < (int) $represented) {
                        $this->coverageMismatch('Entrypoint kind coverage does not dominate represented kinds.');
                    }
                }
                if ($declaredKindTotal !== $detected) {
                    $this->coverageMismatch('Entrypoint kind coverage does not sum to detected entrypoints.');
                }
            }
        }

        $manifestLanguages = collect((array) ($import->manifest['languages'] ?? []))->filter(static fn (mixed $language): bool => is_array($language) && is_string($language['name'] ?? null))->values();
        $fileLanguageCounts = DB::table('hades_graph_import_record_keys')
            ->where('graph_import_id', $import->id)
            ->where('record_kind', 'nodes')
            ->where('record_subkind', 'file')
            ->whereNotNull('language')
            ->select(
                'language',
                DB::raw('COUNT(*) as detected'),
                DB::raw("SUM(CASE WHEN analysis_status = 'analyzed' THEN 1 ELSE 0 END) as analyzed"),
                DB::raw("SUM(CASE WHEN analysis_status = 'budget_omitted' THEN 1 ELSE 0 END) as budget_omitted"),
            )
            ->groupBy('language')
            ->get()
            ->keyBy('language');
        $this->assertCompletenessAndReasonClosure($import);
        $omittedLedger = data_get($recordCoverage, 'omitted_by_bundle_budget');
        if ($omittedLedger !== null) {
            $missingFiles = max(0, $fileDiscovered - $representedFiles);
            if ((int) $omittedLedger < $missingFiles + $rejectedEntrypoints) {
                $this->coverageMismatch('Bundle omission coverage is below the derivable public-record gap.');
            }
            $globalReasonCodes = [];
            foreach ((array) data_get($import->manifest, 'graph_contract.completeness.capabilities') as $capability) {
                foreach ((array) ($capability['reasons'] ?? []) as $reason) {
                    if (is_string($reason['code'] ?? null)) {
                        $globalReasonCodes[$reason['code']] = true;
                    }
                }
            }
            $budgetReasons = ['record_too_large', 'resource_budget_reached'];
            if ((int) $omittedLedger > 0) {
                $hasGlobalBudget = (bool) array_intersect_key(array_flip($budgetReasons), $globalReasonCodes);
                $globalPartial = data_get($import->manifest, 'graph_contract.completeness.status') === 'partial';
                $capabilityPartial = false;
                foreach ((array) data_get($import->manifest, 'graph_contract.completeness.capabilities') as $capability) {
                    if (is_array($capability) && ($capability['status'] ?? null) === 'partial' && array_intersect($budgetReasons, array_map(static fn (array $reason): string => (string) ($reason['code'] ?? ''), (array) ($capability['reasons'] ?? []))) !== []) {
                        $capabilityPartial = true;
                    }
                }
                if (! $hasGlobalBudget || ! $globalPartial || ! $capabilityPartial) {
                    $this->coverageMismatch('Bundle omission requires global partial budget evidence.');
                }
            }
            $omittedReasons = DB::table('hades_graph_import_record_keys')->where('graph_import_id', $import->id)->whereNotNull('omission_reason')->pluck('omission_reason')->unique()->all();
            if (array_diff($omittedReasons, array_keys($globalReasonCodes)) !== []) {
                $this->coverageMismatch('Represented file omission reasons are not globally counted.');
            }
            if ($missingFiles > 0 && ! $this->hasBudgetEvidence(data_get($import->manifest, 'graph_contract.completeness.capabilities.inventory'))) {
                $this->coverageMismatch('Missing file records require partial inventory budget evidence.');
            }
            if ($rejectedEntrypoints > 0 && ! $this->hasBudgetEvidence(data_get($import->manifest, 'graph_contract.completeness.capabilities.entrypoint_discovery'))) {
                $this->coverageMismatch('Rejected entrypoints require partial discovery budget evidence.');
            }
            foreach ($manifestLanguages as $language) {
                $facts = $fileLanguageCounts->get($language['name']);
                $missingLanguageFiles = max(0, (int) $language['detected_file_count'] - (int) ($facts->detected ?? 0));
                $budgetOmittedLanguageFiles = (int) ($facts->budget_omitted ?? 0);
                if ($missingLanguageFiles + $budgetOmittedLanguageFiles === 0) {
                    continue;
                }
                $scoped = collect((array) data_get($import->manifest, 'graph_contract.completeness.languages'))->firstWhere('language', $language['name']);
                $inventory = is_array($scoped) ? ($scoped['capabilities']['inventory'] ?? null) : null;
                $budgetCount = array_sum(array_map(static fn (mixed $reason): int => is_array($reason) && in_array($reason['code'] ?? null, $budgetReasons, true) && ($reason['language'] ?? null) === $language['name'] ? (int) ($reason['count'] ?? 0) : 0, (array) ($inventory['reasons'] ?? [])));
                if (! is_array($scoped) || ($scoped['status'] ?? null) !== 'partial' || ! $this->hasBudgetEvidence($inventory) || $budgetCount < $missingLanguageFiles + $budgetOmittedLanguageFiles) {
                    $this->coverageMismatch('Language file omissions require scoped partial inventory budget evidence.');
                }
            }
        }

        $manifestLanguageNames = $manifestLanguages->pluck('name')->all();
        foreach ($fileLanguageCounts as $language => $facts) {
            if (! in_array($language, $manifestLanguageNames, true)) {
                $this->coverageMismatch('File inventory language is missing its manifest language record.');
            }
        }
        $detectedLanguageTotal = 0;
        foreach ($manifestLanguages as $language) {
            $facts = $fileLanguageCounts->get($language['name']);
            if ((int) ($language['detected_file_count'] ?? -1) < (int) ($facts->detected ?? 0) || (int) ($language['analyzed_file_count'] ?? -1) !== (int) ($facts->analyzed ?? 0)) {
                $this->coverageMismatch('Language file counts do not close over represented files.');
            }
            $detectedLanguageTotal += (int) $language['detected_file_count'];
        }
        if ($detectedLanguageTotal > $fileDiscovered) {
            $this->coverageMismatch('Language detection exceeds discovered file coverage.');
        }
        $completenessLanguages = collect((array) data_get($import->manifest, 'graph_contract.completeness.languages'))->filter(static fn (mixed $language): bool => is_array($language) && is_string($language['language'] ?? null))->pluck('language')->all();
        sort($manifestLanguageNames);
        sort($completenessLanguages);
        if ($manifestLanguageNames !== $completenessLanguages) {
            $this->coverageMismatch('Language completeness records do not close over manifest languages.');
        }
        $flowMismatch = DB::table('hades_graph_import_record_keys as flow')
            ->leftJoin('hades_graph_import_record_keys as step', function ($join): void {
                $join->on('step.graph_import_id', '=', 'flow.graph_import_id')->on('step.flow_public_id', '=', 'flow.public_id')->where('step.record_kind', 'flow_steps');
            })
            ->where('flow.graph_import_id', $import->id)->where('flow.record_kind', 'flows')->whereNotNull('flow.count_hint')
            ->groupBy('flow.graph_import_id', 'flow.public_id', 'flow.count_hint')
            ->havingRaw('flow.count_hint <> COUNT(step.public_id)')->exists();
        if ($flowMismatch) {
            throw new GraphV2ImportException('graph_validation_coverage_mismatch', 'Flow represented step counts do not close over staged flow steps.');
        }

        $terminalKinds = ['response', 'redirect', 'abort', 'exception', 'exit'];
        $flowFacts = DB::table('hades_graph_import_record_keys as flow')
            ->leftJoin('hades_graph_import_record_keys as step', function ($join): void {
                $join->on('step.graph_import_id', '=', 'flow.graph_import_id')->on('step.flow_public_id', '=', 'flow.public_id')->where('step.record_kind', 'flow_steps');
            })
            ->leftJoin('hades_graph_import_record_keys as edge', function ($join): void {
                $join->on('edge.graph_import_id', '=', 'step.graph_import_id')->on('edge.public_id', '=', 'step.edge_public_id')->where('edge.record_kind', 'edges');
            })
            ->leftJoin('hades_graph_import_record_keys as terminal', function ($join) use ($terminalKinds): void {
                $join->on('terminal.graph_import_id', '=', 'edge.graph_import_id')->on('terminal.public_id', '=', 'edge.target_node_public_id')->where('terminal.record_kind', 'nodes')->whereIn('terminal.record_subkind', $terminalKinds);
            })
            ->where('flow.graph_import_id', $import->id)->where('flow.record_kind', 'flows')
            ->groupBy('flow.graph_import_id', 'flow.public_id', 'flow.flow_counts', 'flow.flow_capabilities', 'flow.completeness_status')
            ->select('flow.flow_counts', 'flow.flow_capabilities', 'flow.completeness_status')
            ->selectRaw('COUNT(DISTINCT step.async_child_flow_id) as linked_async_count')
            ->selectRaw('COUNT(DISTINCT edge.uncertainty_public_id) as uncertainty_count')
            ->selectRaw('COUNT(DISTINCT terminal.public_id) as terminal_count')
            ->cursor();
        foreach ($flowFacts as $facts) {
            $counts = json_decode((string) $facts->flow_counts, true);
            $capabilities = json_decode((string) $facts->flow_capabilities, true);
            if (! is_array($counts) || ! is_array($capabilities)) {
                $this->coverageMismatch('Flow count and capability metadata is invalid.');
            }
            $this->assertFlowCount($counts['linked_async_flow_count'] ?? null, (int) $facts->linked_async_count, $capabilities, ['inventory', 'call_graph', 'async']);
            $this->assertFlowCount($counts['uncertainty_count'] ?? null, (int) $facts->uncertainty_count, $capabilities, ['inventory', 'entrypoint_discovery', 'symbol_resolution', 'call_graph', 'control_flow', 'framework_lifecycle', 'exceptions', 'async', 'data_access']);
            $this->assertFlowCount($counts['terminal_count'] ?? null, (int) $facts->terminal_count, $capabilities, ['inventory', 'call_graph', 'control_flow', 'exceptions']);
            $partial = false;
            foreach ($capabilities as $capability) {
                if (is_array($capability) && in_array($capability['status'] ?? null, ['partial', 'unsupported'], true)) {
                    $partial = true;
                    break;
                }
            }
            if (($facts->completeness_status === 'partial') !== $partial) {
                $this->coverageMismatch('Flow completeness status does not close over flow capabilities.');
            }
        }

        $rootMembers = DB::table('hades_graph_import_record_keys')
            ->where('graph_import_id', $import->id)->where('record_kind', 'flows')
            ->selectRaw('graph_import_id, public_id as flow_public_id, ? as stage, root_node_public_id as member_id', ['entry']);
        $stepFromMembers = DB::table('hades_graph_import_record_keys as step')
            ->join('hades_graph_import_record_keys as edge', function ($join): void {
                $join->on('edge.graph_import_id', '=', 'step.graph_import_id')->on('edge.public_id', '=', 'step.edge_public_id')->where('edge.record_kind', 'edges');
            })
            ->where('step.graph_import_id', $import->id)->where('step.record_kind', 'flow_steps')
            ->selectRaw('step.graph_import_id, step.flow_public_id, step.stage_from as stage, edge.source_node_public_id as member_id');
        $stepToMembers = DB::table('hades_graph_import_record_keys as step')
            ->join('hades_graph_import_record_keys as edge', function ($join): void {
                $join->on('edge.graph_import_id', '=', 'step.graph_import_id')->on('edge.public_id', '=', 'step.edge_public_id')->where('edge.record_kind', 'edges');
            })
            ->where('step.graph_import_id', $import->id)->where('step.record_kind', 'flow_steps')
            ->selectRaw('step.graph_import_id, step.flow_public_id, step.stage_to as stage, edge.target_node_public_id as member_id');
        $members = $rootMembers->unionAll($stepFromMembers)->unionAll($stepToMembers);
        $stageCapabilities = [
            'entry' => ['entrypoint_discovery'],
            'routing' => ['entrypoint_discovery', 'framework_lifecycle'],
            'middleware' => ['framework_lifecycle'],
            'security' => ['framework_lifecycle'],
            'input' => ['framework_lifecycle'],
            'handler' => ['symbol_resolution'],
            'domain' => ['call_graph', 'control_flow'],
            'data' => ['data_access'],
            'integration' => ['data_access'],
            'async' => ['async'],
            'response' => ['control_flow'],
            'error' => ['exceptions'],
        ];
        $stageRows = null;
        foreach (array_keys($stageCapabilities) as $stage) {
            $stageQuery = DB::query()->selectRaw('? as stage', [$stage]);
            $stageRows = $stageRows === null ? $stageQuery : $stageRows->unionAll($stageQuery);
        }
        $stageCounts = DB::query()->fromSub($members, 'members')
            ->groupBy('graph_import_id', 'flow_public_id', 'stage')
            ->select('graph_import_id', 'flow_public_id', 'stage')
            ->selectRaw('COUNT(DISTINCT member_id) as represented');
        $flowStageFacts = DB::table('hades_graph_import_record_keys as flow')
            ->crossJoinSub($stageRows, 'stages')
            ->leftJoinSub($stageCounts, 'stage_counts', function ($join): void {
                $join->on('stage_counts.graph_import_id', '=', 'flow.graph_import_id')
                    ->on('stage_counts.flow_public_id', '=', 'flow.public_id')
                    ->on('stage_counts.stage', '=', 'stages.stage');
            })
            ->where('flow.graph_import_id', $import->id)->where('flow.record_kind', 'flows')
            ->select('flow.stage_counts', 'flow.flow_capabilities', 'stages.stage')
            ->selectRaw('COALESCE(stage_counts.represented, 0) as represented')
            ->cursor();
        foreach ($flowStageFacts as $facts) {
            $declaredStages = json_decode((string) $facts->stage_counts, true);
            if (! is_array($declaredStages)) {
                throw new GraphV2ImportException('graph_validation_coverage_mismatch', 'Flow stage-count metadata is invalid.');
            }
            $capabilities = json_decode((string) $facts->flow_capabilities, true);
            if (! is_array($capabilities)) {
                throw new GraphV2ImportException('graph_validation_coverage_mismatch', 'Flow capability metadata is invalid.');
            }
            $stage = (string) $facts->stage;
            $declaration = $declaredStages[$stage] ?? null;
            if ($declaration === null && (int) $facts->represented === 0) {
                continue;
            }
            $this->assertFlowCount(
                is_array($declaration) ? $declaration : null,
                (int) $facts->represented,
                $capabilities,
                $stageCapabilities[$stage],
            );
        }
    }

    private function assertCompletenessAndReasonClosure(HadesGraphImport $import): void
    {
        $completeness = data_get($import->manifest, 'graph_contract.completeness');
        if (! is_array($completeness)) {
            $this->coverageMismatch('Completeness metadata is invalid.');
        }

        $globalCapabilities = $completeness['capabilities'] ?? null;
        if ($globalCapabilities === [] || $globalCapabilities === null) {
            return;
        }
        if (! is_array($globalCapabilities)) {
            $this->coverageMismatch('Global capability metadata is invalid.');
        }
        $this->assertCapabilityShapes($globalCapabilities);

        $globalReasons = [];
        $globalReasonCounts = [];
        $globalScopeRows = [];
        foreach ($globalCapabilities as $name => $capability) {
            foreach ((array) ($capability['reasons'] ?? []) as $reason) {
                $this->appendReason($globalReasons, $name, null, $reason);
                $key = $this->reasonKey($name, $reason['code'], $reason['language'] ?? null);
                $globalReasonCounts[$key] = (int) $reason['count'];
                $globalScopeRows[$key] = ['name' => $name, 'language' => $reason['language'] ?? null, 'reason' => $reason];
            }
        }

        $languageReasons = [];
        $languageReasonCounts = [];
        $languageScopeRows = [];
        $anyPartial = $this->capabilitiesArePartial($globalCapabilities);
        foreach ((array) ($completeness['languages'] ?? []) as $language) {
            if (! is_array($language) || ! is_string($language['language'] ?? null) || ! is_array($language['capabilities'] ?? null)) {
                $this->coverageMismatch('Language completeness metadata is invalid.');
            }
            $languageName = $language['language'];
            $capabilities = $language['capabilities'];
            $this->assertCapabilityShapes($capabilities);
            $languagePartial = $this->capabilitiesArePartial($capabilities);
            if (($language['status'] ?? null) !== ($languagePartial ? 'partial' : 'full')) {
                $this->coverageMismatch('Language completeness status does not close over capabilities.');
            }
            $anyPartial = $anyPartial || $languagePartial || $language['status'] === 'partial';
            foreach ($capabilities as $name => $capability) {
                foreach ((array) ($capability['reasons'] ?? []) as $reason) {
                    if (($reason['language'] ?? null) !== null && $reason['language'] !== $languageName) {
                        $this->coverageMismatch('Language capability reason names another language scope.');
                    }
                    $this->appendReason($languageReasons, $name, $languageName, $reason);
                    $key = $this->reasonKey($name, $reason['code'], $languageName);
                    $languageReasonCounts[$key] = (int) $reason['count'];
                    $languageScopeRows[$key] = ['name' => $name, 'language' => $languageName, 'reason' => $reason];
                }
            }
        }

        foreach (array_values($globalScopeRows) as $item) {
            $reason = $item['reason'];
            $language = $reason['language'] ?? null;
            if ($language !== null) {
                $key = $this->reasonKey($item['name'], $reason['code'], $language);
                if (($languageReasonCounts[$key] ?? null) !== (int) $reason['count']) {
                    $this->coverageMismatch('Global and language capability reason counts disagree.');
                }

                continue;
            }
            $scoped = [];
            foreach ($languageReasonCounts as $key => $count) {
                [$scopedLanguage, $scopedName, $scopedCode] = explode("\0", $key, 3);
                if ($scopedName === $item['name'] && $scopedCode === $reason['code']) {
                    $scoped[$scopedLanguage] = $count;
                }
            }
            if ($scoped !== [] && array_sum($scoped) !== (int) $reason['count']) {
                $this->coverageMismatch('Aggregate capability reason count does not equal language scopes.');
            }
        }
        foreach (array_values($languageScopeRows) as $item) {
            $reason = $item['reason'];
            $scopedKey = $this->reasonKey($item['name'], $reason['code'], $item['language']);
            $aggregateKey = $this->reasonKey($item['name'], $reason['code'], null);
            if (! array_key_exists($scopedKey, $globalReasonCounts) && ! array_key_exists($aggregateKey, $globalReasonCounts)) {
                $this->coverageMismatch('Language capability reason has no global counterpart.');
            }
        }

        $observed = [];
        $reconcilable = [];
        foreach ($this->uncertaintyLanguageRelation($import)
            ->select('code', 'language')
            ->selectRaw('COUNT(DISTINCT uncertainty_public_id) as aggregate')
            ->groupBy('code', 'language')
            ->cursor() as $row) {
            $code = (string) $row->code;
            $language = $row->language === null ? null : (string) $row->language;
            $key = $this->reasonKey('', $code, $language);
            $observed[$key] = ($observed[$key] ?? 0) + (int) $row->aggregate;
            $reconcilable[$code] = true;
        }
        foreach (DB::table('hades_graph_import_record_keys')
            ->where('graph_import_id', $import->id)
            ->whereNotNull('omission_reason')
            ->groupBy('omission_reason', 'language')
            ->select('omission_reason as code', 'language')
            ->selectRaw('COUNT(*) as aggregate')
            ->cursor() as $row) {
            $code = (string) $row->code;
            $language = $row->language === null ? null : (string) $row->language;
            $key = $this->reasonKey('', $code, $language);
            $observed[$key] = ($observed[$key] ?? 0) + (int) $row->aggregate;
            $reconcilable[$code] = true;
        }
        $unsupportedFiles = [];
        foreach (DB::table('hades_graph_import_record_keys')
            ->where('graph_import_id', $import->id)
            ->where('record_kind', 'nodes')
            ->where('record_subkind', 'file')
            ->where('analysis_status', 'unsupported')
            ->groupBy('language')
            ->select('language')
            ->selectRaw('COUNT(*) as aggregate')
            ->cursor() as $row) {
            $unsupportedFiles[$row->language === null ? '' : (string) $row->language] = (int) $row->aggregate;
        }
        $declaredCodes = [];
        foreach (array_merge($globalReasons, $languageReasons) as $item) {
            $declaredCodes[$item['reason']['code']] = true;
        }
        foreach (['unsupported_language', 'parser_unavailable'] as $code) {
            if (! isset($declaredCodes[$code])) {
                continue;
            }
            foreach ($unsupportedFiles as $language => $count) {
                $key = $this->reasonKey('', $code, $language === '' ? null : $language);
                $observed[$key] = ($observed[$key] ?? 0) + $count;
                $reconcilable[$code] = true;
            }
        }

        $recordCoverage = data_get($import->manifest, 'graph_contract.coverage.records');
        if (is_array($recordCoverage) && array_key_exists('omitted_by_bundle_budget', $recordCoverage)) {
            $this->assertReasonRecordCounts($globalReasons, $languageReasons, $observed, $reconcilable, (int) $recordCoverage['omitted_by_bundle_budget']);
        }

        $frontierCounts = DB::table('hades_graph_import_record_keys as step')
            ->join('hades_graph_import_record_keys as edge', function ($join): void {
                $join->on('edge.graph_import_id', '=', 'step.graph_import_id')
                    ->on('edge.public_id', '=', 'step.edge_public_id')
                    ->where('edge.record_kind', 'edges');
            })
            ->joinSub($this->uncertaintyLanguageRelation($import), 'uncertainty_language', function ($join): void {
                $join->on('uncertainty_language.uncertainty_public_id', '=', 'edge.uncertainty_public_id');
            })
            ->where('step.graph_import_id', $import->id)
            ->where('step.record_kind', 'flow_steps')
            ->groupBy('step.flow_public_id', 'uncertainty_language.code', 'uncertainty_language.language')
            ->select('step.flow_public_id', 'uncertainty_language.code', 'uncertainty_language.language')
            ->selectRaw('COUNT(DISTINCT edge.uncertainty_public_id) as aggregate');

        $flowRows = DB::table('hades_graph_import_record_keys as flow')
            ->leftJoinSub($frontierCounts, 'frontier_counts', function ($join): void {
                $join->on('frontier_counts.flow_public_id', '=', 'flow.public_id');
            })
            ->where('flow.graph_import_id', $import->id)
            ->where('flow.record_kind', 'flows')
            ->select('flow.public_id', 'flow.flow_capabilities', 'flow.completeness_status', 'frontier_counts.code', 'frontier_counts.language')
            ->selectRaw('COALESCE(frontier_counts.aggregate, 0) as frontier_count')
            ->orderBy('flow.public_id')
            ->cursor();
        $currentFlow = null;
        $currentFrontiers = [];
        $currentCapabilities = null;
        $currentStatus = null;
        foreach ($flowRows as $flow) {
            if ($currentFlow !== null && $currentFlow !== (string) $flow->public_id) {
                $this->assertFlowFrontierReasons($currentCapabilities, $currentStatus, $currentFrontiers, $globalReasonCounts, $languageReasonCounts, $anyPartial);
                $currentFrontiers = [];
            }
            if ($currentFlow === null || $currentFlow !== (string) $flow->public_id) {
                $currentFlow = (string) $flow->public_id;
                $currentCapabilities = json_decode((string) $flow->flow_capabilities, true);
                $currentStatus = $flow->completeness_status;
                if (! is_array($currentCapabilities)) {
                    $this->coverageMismatch('Flow capability metadata is invalid.');
                }
                $currentFrontiers = [];
            }
            if ($flow->code !== null) {
                $currentFrontiers[$this->reasonKey('', (string) $flow->code, $flow->language === null ? null : (string) $flow->language)] = (int) $flow->frontier_count;
            }
        }
        if ($currentFlow !== null) {
            $this->assertFlowFrontierReasons($currentCapabilities, $currentStatus, $currentFrontiers, $globalReasonCounts, $languageReasonCounts, $anyPartial);
        }

        if (($completeness['status'] ?? null) !== ($anyPartial ? 'partial' : 'full')) {
            $this->coverageMismatch('Global completeness status does not close over capabilities.');
        }
    }

    /** @param array<string,mixed>|null $capabilities @param array<string,int> $frontiers @param array<string,int> $globalReasonCounts @param array<string,int> $languageReasonCounts */
    private function assertFlowFrontierReasons(?array $capabilities, mixed $completenessStatus, array $frontiers, array $globalReasonCounts, array $languageReasonCounts, bool &$anyPartial): void
    {
        if (! is_array($capabilities)) {
            $this->coverageMismatch('Flow capability metadata is invalid.');
        }
        $this->assertCapabilityShapes($capabilities);
        $flowPartial = $this->capabilitiesArePartial($capabilities);
        if (($completenessStatus === 'partial') !== $flowPartial) {
            $this->coverageMismatch('Flow completeness status does not close over flow capabilities.');
        }
        $anyPartial = $anyPartial || $flowPartial || $completenessStatus === 'partial';
        foreach ($capabilities as $name => $capability) {
            foreach ((array) ($capability['reasons'] ?? []) as $reason) {
                if (in_array($reason['code'], ['entrypoint_unresolved', 'call_target_unresolved', 'dynamic_dispatch', 'reflection_or_generated_code', 'framework_config_unresolved', 'exception_target_unresolved', 'async_target_unresolved', 'external_boundary_unresolved', 'graphify_candidate'], true)) {
                    $expected = $frontiers[$this->reasonKey('', $reason['code'], $reason['language'] ?? null)] ?? 0;
                    if (($reason['language'] ?? null) === null) {
                        $expected = 0;
                        foreach ($frontiers as $key => $count) {
                            [, , $code] = explode("\0", $key, 3);
                            if ($code === $reason['code']) {
                                $expected += $count;
                            }
                        }
                    }
                    if ((int) $reason['count'] !== $expected) {
                        $this->coverageMismatch('Flow capability reason count does not match its frontiers.');
                    }
                }
                $globalCount = $globalReasonCounts[$this->reasonKey($name, $reason['code'], $reason['language'] ?? null)]
                    ?? $globalReasonCounts[$this->reasonKey($name, $reason['code'], null)]
                    ?? null;
                if ($globalCount === null || $globalCount < (int) $reason['count']) {
                    $this->coverageMismatch('Flow capability reason has no containing global scope.');
                }
                if (($reason['language'] ?? null) !== null) {
                    $languageCount = $languageReasonCounts[$this->reasonKey($name, $reason['code'], $reason['language'])] ?? null;
                    if ($languageCount === null || $languageCount < (int) $reason['count']) {
                        $this->coverageMismatch('Flow capability reason has no containing language scope.');
                    }
                }
            }
        }
    }

    private function uncertaintyLanguageRelation(HadesGraphImport $import): Builder
    {
        $edgeSubject = DB::table('hades_graph_import_record_keys as uncertainty')
            ->join('hades_graph_import_references as subject', function ($join): void {
                $join->on('subject.graph_import_id', '=', 'uncertainty.graph_import_id')
                    ->on('subject.owner_public_id', '=', 'uncertainty.public_id')
                    ->where('subject.owner_record_kind', 'uncertainties')
                    ->where('subject.reference_kind', 'subject.edge_id');
            })
            ->join('hades_graph_import_record_keys as edge', function ($join): void {
                $join->on('edge.graph_import_id', '=', 'subject.graph_import_id')
                    ->on('edge.public_id', '=', 'subject.target_public_id')
                    ->where('edge.record_kind', 'edges');
            })
            ->join('hades_graph_import_record_keys as source', function ($join): void {
                $join->on('source.graph_import_id', '=', 'edge.graph_import_id')
                    ->on('source.public_id', '=', 'edge.source_node_public_id')
                    ->where('source.record_kind', 'nodes');
            })
            ->where('uncertainty.graph_import_id', $import->id)
            ->where('uncertainty.record_kind', 'uncertainties')
            ->select('uncertainty.public_id as uncertainty_public_id', 'uncertainty.reason_code as code', 'source.language')
            ->selectRaw('0 as candidate_priority, uncertainty.chunk_index, uncertainty.record_ordinal, uncertainty.public_id');
        $callSiteSubject = DB::table('hades_graph_import_record_keys as uncertainty')
            ->join('hades_graph_import_references as subject', function ($join): void {
                $join->on('subject.graph_import_id', '=', 'uncertainty.graph_import_id')
                    ->on('subject.owner_public_id', '=', 'uncertainty.public_id')
                    ->where('subject.owner_record_kind', 'uncertainties')
                    ->where('subject.reference_kind', 'subject.call_site_id');
            })
            ->join('hades_graph_import_references as carrier_reference', function ($join): void {
                $join->on('carrier_reference.graph_import_id', '=', 'subject.graph_import_id')
                    ->on('carrier_reference.target_public_id', '=', 'subject.target_public_id')
                    ->where('carrier_reference.owner_record_kind', 'edges')
                    ->where('carrier_reference.reference_kind', 'call_site_id');
            })
            ->join('hades_graph_import_record_keys as carrier_edge', function ($join): void {
                $join->on('carrier_edge.graph_import_id', '=', 'carrier_reference.graph_import_id')
                    ->on('carrier_edge.public_id', '=', 'carrier_reference.owner_public_id')
                    ->where('carrier_edge.record_kind', 'edges')
                    ->whereColumn('carrier_edge.uncertainty_public_id', 'uncertainty.public_id');
            })
            ->join('hades_graph_import_record_keys as source', function ($join): void {
                $join->on('source.graph_import_id', '=', 'carrier_edge.graph_import_id')
                    ->on('source.public_id', '=', 'carrier_edge.source_node_public_id')
                    ->where('source.record_kind', 'nodes');
            })
            ->where('uncertainty.graph_import_id', $import->id)
            ->where('uncertainty.record_kind', 'uncertainties')
            ->select('uncertainty.public_id as uncertainty_public_id', 'uncertainty.reason_code as code', 'source.language')
            ->selectRaw('1 as candidate_priority, carrier_edge.chunk_index, carrier_edge.record_ordinal, carrier_edge.public_id');
        $candidates = $edgeSubject->unionAll($callSiteSubject);
        $ranked = DB::query()->fromSub($candidates, 'candidates')
            ->select('uncertainty_public_id', 'code', 'language')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY uncertainty_public_id ORDER BY candidate_priority, chunk_index, record_ordinal, public_id) as carrier_rank');

        return DB::query()->fromSub($ranked, 'uncertainty_language')->where('carrier_rank', 1);
    }

    /** @param array<string,mixed> $capabilities */
    private function assertCapabilityShapes(array $capabilities): void
    {
        foreach (['inventory', 'entrypoint_discovery', 'symbol_resolution', 'call_graph', 'control_flow', 'framework_lifecycle', 'exceptions', 'async', 'data_access'] as $name) {
            $capability = $capabilities[$name] ?? null;
            if (! is_array($capability) || ! is_array($capability['reasons'] ?? null)) {
                $this->coverageMismatch('Capability metadata is invalid.');
            }
            $status = $capability['status'] ?? null;
            $reasons = $capability['reasons'];
            if (! in_array($status, ['full', 'partial', 'unsupported', 'not_applicable'], true)
                || (($status === 'full' || $status === 'not_applicable') !== ($reasons === []))) {
                $this->coverageMismatch('Capability status and reasons disagree.');
            }
            foreach ($reasons as $reason) {
                if (! is_array($reason) || ! is_string($reason['code'] ?? null) || ! is_int($reason['count'] ?? null) || $reason['count'] < 0 || ($reason['language'] ?? null) !== null && ! is_string($reason['language'])) {
                    $this->coverageMismatch('Capability reason metadata is invalid.');
                }
            }
        }
    }

    /** @param list<array{name:string,language:string|null,reason:array<string,mixed>}> $rows */
    private function appendReason(array &$rows, string $name, ?string $language, mixed $reason): void
    {
        if (! is_array($reason)) {
            $this->coverageMismatch('Capability reason metadata is invalid.');
        }
        $rows[] = ['name' => $name, 'language' => $language, 'reason' => $reason];
    }

    private function reasonKey(string $name, string $code, ?string $language): string
    {
        return ($language ?? '')."\0{$name}\0{$code}";
    }

    /** @param list<array{name:string,language:string|null,reason:array<string,mixed>}> $globalReasons @param list<array{name:string,language:string|null,reason:array<string,mixed>}> $languageReasons @param array<string,int> $observed @param array<string,bool> $reconcilable */
    private function assertReasonRecordCounts(array $globalReasons, array $languageReasons, array $observed, array $reconcilable, int $omittedLedger): void
    {
        $producerFacts = [];
        foreach ($languageReasons as $item) {
            if ($item['reason']['code'] === 'resource_budget_reached' && ! in_array($item['name'], ['inventory', 'entrypoint_discovery'], true)) {
                $producerFacts[$item['language']][$item['name']] ??= (int) $item['reason']['count'];
            }
        }
        $canonicalGlobalReasons = [];
        foreach ($globalReasons as $item) {
            $canonicalGlobalReasons[$this->reasonKey($item['name'], $item['reason']['code'], $item['reason']['language'] ?? null)] = $item;
        }
        $canonicalLanguageReasons = [];
        foreach ($languageReasons as $item) {
            $canonicalLanguageReasons[$this->reasonKey($item['name'], $item['reason']['code'], $item['language'])] = $item;
        }
        $envelopes = [[array_values($canonicalGlobalReasons), null], [array_values($canonicalLanguageReasons), 'language']];
        foreach ($envelopes as [$rows, $scopeType]) {
            $byCapability = [];
            foreach ($rows as $item) {
                $reason = $item['reason'];
                $language = $scopeType === null ? ($reason['language'] ?? null) : $item['language'];
                $groupKey = $scopeType === null
                    ? $item['name']
                    : $item['name']."\0".$item['language'];
                $byCapability[$groupKey][] = $item;
                $expected = $language === null ? 0 : ($observed[$this->reasonKey('', $reason['code'], $language)] ?? 0);
                if ($language === null) {
                    foreach ($observed as $key => $count) {
                        [, , $code] = explode("\0", $key, 3);
                        if ($code === $reason['code']) {
                            $expected += $count;
                        }
                    }
                }
                if ($reason['code'] === 'resource_budget_reached' && ! in_array($item['name'], ['inventory', 'entrypoint_discovery'], true)) {
                    if ($scopeType === 'language') {
                        $expected = $producerFacts[$item['language']][$item['name']] ?? 0;
                    } elseif ($language === null) {
                        $expected = array_sum(array_map(static fn (array $facts): int => (int) ($facts[$item['name']] ?? 0), $producerFacts));
                    } else {
                        $expected = $producerFacts[$language][$item['name']] ?? 0;
                    }
                }
                if (in_array($reason['code'], ['record_too_large', 'resource_budget_reached'], true) && ! ($reason['code'] === 'resource_budget_reached' && ! in_array($item['name'], ['inventory', 'entrypoint_discovery'], true))) {
                    if ((int) $reason['count'] < $expected || (int) $reason['count'] > $expected + $omittedLedger) {
                        $this->coverageMismatch('Bundle-budget reason exceeds observable events and omission ledger.');
                    }
                } elseif (isset($reconcilable[$reason['code']]) && (int) $reason['count'] !== $expected) {
                    $this->coverageMismatch('Capability reason count does not match affected records.');
                } elseif ($reason['code'] === 'resource_budget_reached' && ! in_array($item['name'], ['inventory', 'entrypoint_discovery'], true) && (int) $reason['count'] !== $expected) {
                    $this->coverageMismatch('Producer-fact budget reason does not match its language omission ledger.');
                }
            }
            foreach ($byCapability as $reasons) {
                $budgetExcess = 0;
                foreach ($reasons as $item) {
                    if (in_array($item['reason']['code'], ['record_too_large', 'resource_budget_reached'], true)
                        && ! ($item['reason']['code'] === 'resource_budget_reached' && ! in_array($item['name'], ['inventory', 'entrypoint_discovery'], true))) {
                        $language = $scopeType === null ? ($item['reason']['language'] ?? null) : $item['language'];
                        $expected = $language === null ? 0 : ($observed[$this->reasonKey('', $item['reason']['code'], $language)] ?? 0);
                        if ($language === null) {
                            foreach ($observed as $key => $count) {
                                [, , $code] = explode("\0", $key, 3);
                                if ($code === $item['reason']['code']) {
                                    $expected += $count;
                                }
                            }
                        }
                        $budgetExcess += max(0, (int) $item['reason']['count'] - $expected);
                    }
                }
                if ($budgetExcess > $omittedLedger) {
                    $this->coverageMismatch('Bundle-budget reasons double-count the explicit omission ledger.');
                }
            }
        }
    }

    private function coverageMismatch(string $message): never
    {
        throw new GraphV2ImportException('graph_validation_coverage_mismatch', $message);
    }

    private function hasBudgetEvidence(mixed $capability): bool
    {
        if (! is_array($capability) || ($capability['status'] ?? null) !== 'partial') {
            return false;
        }

        foreach ((array) ($capability['reasons'] ?? []) as $reason) {
            if (is_array($reason) && in_array($reason['code'] ?? null, ['record_too_large', 'resource_budget_reached'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $capabilities */
    private function capabilitiesArePartial(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (is_array($capability) && in_array($capability['status'] ?? null, ['partial', 'unsupported'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed>|null $declaration @param array<string,mixed> $capabilities @param list<string> $capabilityNames */
    private function assertFlowCount(?array $declaration, int $actual, array $capabilities = [], array $capabilityNames = []): void
    {
        if ($declaration === null || array_diff(['represented', 'value', 'knowledge', 'reason'], array_keys($declaration)) !== []
            || array_diff(array_keys($declaration), ['represented', 'value', 'knowledge', 'reason']) !== []
            || ! is_int($declaration['represented']) || (! is_int($declaration['value']) && $declaration['value'] !== null)
            || ! is_string($declaration['knowledge']) || ($declaration['reason'] !== null && ! is_string($declaration['reason']))) {
            $this->coverageMismatch('Flow count declaration shape is invalid.');
        }
        if ($declaration['represented'] !== $actual || $declaration['represented'] < 0) {
            $this->coverageMismatch('Flow count represented value does not close.');
        }
        $knowledge = $declaration['knowledge'];
        $value = $declaration['value'];
        $reason = $declaration['reason'];
        if ($knowledge === 'exact' && ! ($value === $actual && $actual > 0 && $reason === null)) {
            $this->coverageMismatch('Exact flow count shape does not close.');
        }
        if ($knowledge === 'absence_verified' && ! ($actual === 0 && $value === 0 && $reason === null)) {
            $this->coverageMismatch('Verified-absence flow count shape does not close.');
        }
        if ($knowledge === 'unknown' && ! ($value === null && $declaration['represented'] >= 0 && $reason !== null)) {
            $this->coverageMismatch('Unknown flow count shape does not close.');
        }
        if (! in_array($knowledge, ['exact', 'absence_verified', 'unknown'], true)) {
            $this->coverageMismatch('Flow count knowledge is invalid.');
        }
        $reasons = [];
        foreach ($capabilityNames as $name) {
            $capability = $capabilities[$name] ?? null;
            if (! is_array($capability) || ! in_array($capability['status'] ?? null, ['partial', 'unsupported'], true)) {
                continue;
            }
            foreach ((array) ($capability['reasons'] ?? []) as $capabilityReason) {
                if (is_array($capabilityReason) && is_string($capabilityReason['code'] ?? null)) {
                    $reasons[$capabilityReason['code']] = true;
                }
            }
        }
        $reasonCodes = array_keys($reasons);
        sort($reasonCodes, SORT_STRING);
        if ($reasonCodes !== []) {
            if ($knowledge !== 'unknown' || $reason !== $reasonCodes[0]) {
                $this->coverageMismatch('Unknown flow count does not use the first relevant completeness reason.');
            }
        } elseif ($knowledge !== ($actual > 0 ? 'exact' : 'absence_verified')) {
            $this->coverageMismatch('Complete flow count is not exact or absence-verified.');
        }
    }

    private function assertEntrypointPairs(HadesGraphImport $import): void
    {
        $missingNode = DB::table('hades_graph_import_record_keys as entrypoint')
            ->leftJoin('hades_graph_import_record_keys as node', function ($join): void {
                $join->on('node.graph_import_id', '=', 'entrypoint.graph_import_id')->on('node.public_id', '=', 'entrypoint.public_id')->where('node.record_kind', 'nodes');
            })->where('entrypoint.graph_import_id', $import->id)->where('entrypoint.record_kind', 'entrypoints')
            ->where(function ($query): void {
                $query->whereNull('node.public_id')
                    ->orWhereNull('node.record_subkind')
                    ->orWhereNull('node.identity_variant')
                    ->orWhere('node.record_subkind', '<>', 'entrypoint')
                    ->orWhere('node.identity_variant', '<>', 'entrypoint');
            })->exists();
        $missingRecord = DB::table('hades_graph_import_record_keys as node')
            ->leftJoin('hades_graph_import_record_keys as entrypoint', function ($join): void {
                $join->on('entrypoint.graph_import_id', '=', 'node.graph_import_id')->on('entrypoint.public_id', '=', 'node.public_id')->where('entrypoint.record_kind', 'entrypoints');
            })->where('node.graph_import_id', $import->id)->where('node.record_kind', 'nodes')
            ->where(function ($query): void {
                $query->where('node.record_subkind', 'entrypoint')->orWhere('node.identity_variant', 'entrypoint');
            })->whereNull('entrypoint.public_id')->exists();
        $semanticMismatch = DB::table('hades_graph_import_record_keys as entrypoint')
            ->join('hades_graph_import_record_keys as node', function ($join): void {
                $join->on('node.graph_import_id', '=', 'entrypoint.graph_import_id')->on('node.public_id', '=', 'entrypoint.public_id')->where('node.record_kind', 'nodes');
            })->where('entrypoint.graph_import_id', $import->id)->where('entrypoint.record_kind', 'entrypoints')->where(function ($query): void {
                $query->whereNull('entrypoint.entrypoint_identity_digest')->orWhereNull('node.entrypoint_identity_digest')->orWhereColumn('entrypoint.entrypoint_identity_digest', '<>', 'node.entrypoint_identity_digest');
            })->exists();
        if ($missingNode || $missingRecord || $semanticMismatch) {
            throw new GraphV2ImportException('graph_validation_entrypoint_pair_invalid', 'Every entrypoint record and entrypoint node must have the same-ID pair.');
        }
    }

    private function assertOwnerVariants(HadesGraphImport $import): void
    {
        $invalid = DB::table('hades_graph_import_references as reference')->join('hades_graph_import_record_keys as target', function ($join): void {
            $join->on('target.graph_import_id', '=', 'reference.graph_import_id')->on('target.record_kind', '=', 'reference.target_record_kind')->on('target.public_id', '=', 'reference.target_public_id');
        })->where('reference.graph_import_id', $import->id)->whereIn('reference.reference_kind', ['owner_node_id', 'identity.owner_node_id', 'occurrence.owner_node_id'])->where('reference.target_record_kind', 'nodes')->where(function ($query): void {
            $query->whereNull('target.identity_variant')->orWhereNotIn('target.identity_variant', ['source_declaration', 'entrypoint', 'anonymous_callable']);
        })->exists();
        if ($invalid) {
            throw new GraphV2ImportException('graph_validation_reference_invalid', 'Owner targets must use a permitted identity variant.');
        }
    }

    private function assertStructureSubtypes(HadesGraphImport $import): void
    {
        $invalid = DB::table('hades_graph_import_references as reference')->join('hades_graph_import_record_keys as target', function ($join): void {
            $join->on('target.graph_import_id', '=', 'reference.graph_import_id')->on('target.record_kind', '=', 'reference.target_record_kind')->on('target.public_id', '=', 'reference.target_public_id');
        })->where('reference.graph_import_id', $import->id)->where('reference.target_record_kind', 'structures')->where(function ($query): void {
            $query->where(function ($q): void {
                $q->where('reference.reference_kind', 'call_site_id')->where(function ($subquery): void {
                    $subquery->whereNull('target.record_subkind')->orWhere('target.record_subkind', '<>', 'call_site');
                });
            })
                ->orWhere(function ($q): void {
                    $q->where('reference.reference_kind', 'branch_group_id')->where(function ($subquery): void {
                        $subquery->whereNull('target.record_subkind')->orWhere('target.record_subkind', '<>', 'branch_group');
                    });
                })
                ->orWhere(function ($q): void {
                    $q->where('reference.reference_kind', 'exception_scope_id')->where(function ($subquery): void {
                        $subquery->whereNull('target.record_subkind')->orWhere('target.record_subkind', '<>', 'exception_scope');
                    });
                })
                ->orWhere(function ($q): void {
                    $q->where('reference.reference_kind', 'subject.call_site_id')->where(function ($subquery): void {
                        $subquery->whereNull('target.record_subkind')->orWhere('target.record_subkind', '<>', 'call_site');
                    });
                });
        })->exists();
        if ($invalid) {
            throw new GraphV2ImportException('graph_validation_reference_invalid', 'Structure references must target their field-specific structure subtype.');
        }
    }

    private function assertStructureOwners(HadesGraphImport $import): void
    {
        $invalid = DB::table('hades_graph_import_references as structure_ref')
            ->join('hades_graph_import_record_keys as structure', function ($join): void {
                $join->on('structure.graph_import_id', '=', 'structure_ref.graph_import_id')->on('structure.record_kind', '=', 'structure_ref.target_record_kind')->on('structure.public_id', '=', 'structure_ref.target_public_id');
            })
            ->join('hades_graph_import_references as occurrence_ref', function ($join): void {
                $join->on('occurrence_ref.graph_import_id', '=', 'structure_ref.graph_import_id')->on('occurrence_ref.owner_record_kind', '=', 'structure_ref.owner_record_kind')->on('occurrence_ref.owner_public_id', '=', 'structure_ref.owner_public_id')->where('occurrence_ref.reference_kind', 'occurrence.owner_node_id');
            })
            ->where('structure_ref.graph_import_id', $import->id)->where('structure_ref.owner_record_kind', 'edges')->whereIn('structure_ref.reference_kind', ['call_site_id', 'branch_group_id', 'exception_scope_id'])->whereColumn('structure.owner_public_id', '<>', 'occurrence_ref.target_public_id')->exists();
        if ($invalid) {
            throw new GraphV2ImportException('graph_validation_reference_invalid', 'Edge occurrence owner conflicts with its referenced structure owner.');
        }
    }

    private function assertBoundaryClosure(HadesGraphImport $import): void
    {
        $withoutBoundary = DB::table('hades_graph_import_record_keys as uncertainty')->where('uncertainty.graph_import_id', $import->id)->where('uncertainty.record_kind', 'uncertainties')->whereIn('uncertainty.aux_public_id', ['incomplete', 'not_applicable'])->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('hades_graph_import_references as reference')->whereColumn('reference.graph_import_id', 'uncertainty.graph_import_id')->where('reference.owner_record_kind', 'nodes')->where('reference.reference_kind', 'uncertainty_id')->whereColumn('reference.target_public_id', 'uncertainty.public_id');
        })->exists();
        $completeBoundary = DB::table('hades_graph_import_record_keys as uncertainty')->join('hades_graph_import_references as reference', function ($join): void {
            $join->on('reference.graph_import_id', '=', 'uncertainty.graph_import_id')->on('reference.target_public_id', '=', 'uncertainty.public_id')->where('reference.owner_record_kind', 'nodes')->where('reference.reference_kind', 'uncertainty_id');
        })->where('uncertainty.graph_import_id', $import->id)->where('uncertainty.record_kind', 'uncertainties')->where('uncertainty.aux_public_id', 'complete')->exists();
        $boundaryWithoutIncoming = DB::table('hades_graph_import_record_keys as boundary')->where('boundary.graph_import_id', $import->id)->where('boundary.record_kind', 'nodes')->where('boundary.record_subkind', 'unknown_boundary')->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('hades_graph_import_references as reference')->whereColumn('reference.graph_import_id', 'boundary.graph_import_id')->where('reference.owner_record_kind', 'edges')->where('reference.reference_kind', 'target_id')->whereColumn('reference.target_public_id', 'boundary.public_id');
        })->exists();
        $multipleIncoming = DB::table('hades_graph_import_references as reference')->join('hades_graph_import_record_keys as boundary', function ($join): void {
            $join->on('boundary.graph_import_id', '=', 'reference.graph_import_id')->on('boundary.public_id', '=', 'reference.target_public_id')->where('boundary.record_kind', 'nodes')->where('boundary.record_subkind', 'unknown_boundary');
        })->where('reference.graph_import_id', $import->id)->where('reference.owner_record_kind', 'edges')->where('reference.reference_kind', 'target_id')->groupBy('reference.target_public_id')->havingRaw('COUNT(*) > 1')->exists();
        $unrelatedBoundaryReference = DB::table('hades_graph_import_references as reference')->join('hades_graph_import_record_keys as boundary', function ($join): void {
            $join->on('boundary.graph_import_id', '=', 'reference.graph_import_id')->on('boundary.public_id', '=', 'reference.target_public_id')->where('boundary.record_kind', 'nodes')->where('boundary.record_subkind', 'unknown_boundary');
        })->where('reference.graph_import_id', $import->id)->where('reference.target_record_kind', 'nodes')->where(function ($query): void {
            $query->where('reference.reference_kind', '<>', 'target_id')->orWhere('reference.owner_record_kind', '<>', 'edges');
        })->exists();
        $boundaryUncertaintyMismatch = DB::table('hades_graph_import_references as incoming')
            ->join('hades_graph_import_references as boundary_uncertainty', function ($join): void {
                $join->on('boundary_uncertainty.graph_import_id', '=', 'incoming.graph_import_id')->on('boundary_uncertainty.owner_public_id', '=', 'incoming.target_public_id')->where('boundary_uncertainty.owner_record_kind', 'nodes')->where('boundary_uncertainty.reference_kind', 'uncertainty_id');
            })->leftJoin('hades_graph_import_references as edge_uncertainty', function ($join): void {
                $join->on('edge_uncertainty.graph_import_id', '=', 'incoming.graph_import_id')->on('edge_uncertainty.owner_public_id', '=', 'incoming.owner_public_id')->on('edge_uncertainty.target_public_id', '=', 'boundary_uncertainty.target_public_id')->where('edge_uncertainty.owner_record_kind', 'edges')->where('edge_uncertainty.reference_kind', 'uncertainty_id');
            })->where('incoming.graph_import_id', $import->id)->where('incoming.owner_record_kind', 'edges')->where('incoming.reference_kind', 'target_id')->whereNull('edge_uncertainty.id')->exists();
        if ($withoutBoundary || $completeBoundary || $boundaryWithoutIncoming || $multipleIncoming || $unrelatedBoundaryReference || $boundaryUncertaintyMismatch) {
            throw new GraphV2ImportException('graph_validation_reference_invalid', 'Unknown-boundary ownership is not assertion-exclusive.');
        }
    }

    private function idMatchesKind(string $id, string $kind): bool
    {
        $prefix = match ($kind) {
            'nodes', 'entrypoints' => 'hades:node:v2:', 'edges' => 'hades:edge:v2:', 'flows' => 'hades:flow:v2:', 'flow_steps' => 'hades:flow-step:v2:', 'uncertainties' => 'hades:uncertainty:v2:', 'structures' => ['hades:branch:v2:', 'hades:call-site:v2:', 'hades:exception-scope:v2:'], default => '',
        };
        if (is_array($prefix)) {
            foreach ($prefix as $candidate) {
                if (str_starts_with($id, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return str_starts_with($id, $prefix);
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertRows(array $rows, string $integrityCode, string $integrityMessage): void
    {
        if ($rows === []) {
            return;
        }
        if (count($rows) > self::BATCH_SIZE) {
            throw new GraphV2InfrastructureException('Graph validation batch exceeds the bounded size.');
        }
        try {
            DB::table($this->tableForRows($rows))->insert($rows);
        } catch (QueryException $exception) {
            if ($this->isIntegrityViolation($exception)) {
                throw new GraphV2ImportException($integrityCode, $integrityMessage, previous: $exception);
            }
            throw new GraphV2InfrastructureException('Graph validation staging database is unavailable.', previous: $exception);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function tableForRows(array $rows): string
    {
        return array_key_exists('record_kind', $rows[0]) ? 'hades_graph_import_record_keys' : (array_key_exists('reference_kind', $rows[0]) ? 'hades_graph_import_references' : 'hades_graph_import_file_paths');
    }

    private function isIntegrityViolation(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (str_starts_with($state, '23')) {
            return true;
        }

        return preg_match('/(?:unique|primary key|foreign key|check) constraint|duplicate key|violates .*constraint/i', $exception->getMessage()) === 1;
    }

    /** @return resource */
    private function openArrayStream()
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($stream) || fwrite($stream, '[') !== 1) {
            throw new GraphV2InfrastructureException('Graph validation staging storage could not be created.');
        }

        return $stream;
    }

    /** @param array<string,resource> $streams */
    private function semanticDigest(HadesGraphImport $import, array $streams): string
    {
        $manifest = $import->manifest;
        $canonicalizer = app(GraphV2Canonicalizer::class);
        $context = hash_init('sha256');
        $fields = [
            'completeness' => $manifest['graph_contract']['completeness'] ?? [], 'coverage' => $manifest['graph_contract']['coverage'] ?? [],
            'edges' => 'edges', 'entrypoints' => 'entrypoints', 'flow_steps' => 'flow_steps', 'flows' => 'flows', 'frameworks' => $manifest['frameworks'] ?? [],
            'graph_contract_version' => 'hades.graph_artifact.v2', 'languages' => $manifest['languages'] ?? [], 'nodes' => 'nodes', 'project' => $manifest['project'] ?? [],
            'schema' => 'hades.code_graph.v2', 'source' => $manifest['source'] ?? [], 'structures' => 'structures', 'uncertainties' => 'uncertainties',
        ];
        hash_update($context, '{');
        $first = true;
        foreach ($fields as $key => $value) {
            if (! $first) {
                hash_update($context, ',');
            }
            $first = false;
            hash_update($context, $canonicalizer->canonicalJson($key).':');
            if (is_string($value) && in_array($value, self::KINDS, true)) {
                if (isset($streams[$value])) {
                    rewind($streams[$value]);
                    hash_update_stream($context, $streams[$value]);
                } else {
                    hash_update($context, '[]');
                }
            } else {
                hash_update($context, $canonicalizer->canonicalJson($value));
            }
        }
        hash_update($context, '}');

        return hash_final($context);
    }
}
