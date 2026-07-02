<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemoryImportService
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public function createBatch(
        string $projectId,
        ?string $sourceWorkspaceBindingId,
        string $targetWorkspaceBindingId,
        ?int $requestedByUserId,
        ?string $requestedByHadesAgentId,
        array $entries,
        ?string $reason = null,
        array $source = [],
    ): array {
        $now = now();
        $batchId = (string) Str::ulid();

        DB::transaction(function () use (
            $batchId,
            $projectId,
            $sourceWorkspaceBindingId,
            $targetWorkspaceBindingId,
            $requestedByUserId,
            $requestedByHadesAgentId,
            $entries,
            $reason,
            $source,
            $now,
        ): void {
            DB::table('memory_import_batches')->insert([
                'id' => $batchId,
                'project_id' => $projectId,
                'source_workspace_binding_id' => $sourceWorkspaceBindingId,
                'target_workspace_binding_id' => $targetWorkspaceBindingId,
                'requested_by_user_id' => $requestedByUserId,
                'requested_by_hades_agent_id' => $requestedByHadesAgentId,
                'status' => 'pending',
                'mode' => (string) ($source['mode'] ?? 'copy_as_proposals'),
                'dedupe_strategy' => (string) ($source['dedupe_strategy'] ?? 'source_hash'),
                'conflict_policy' => (string) ($source['conflict_policy'] ?? 'skip'),
                'reason' => $reason,
                'source_payload' => $source === [] ? null : json_encode($source, JSON_THROW_ON_ERROR),
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($entries as $entry) {
                $sourceHash = (string) $entry['source_hash'];
                $localProposalId = 'memory-import:'.$sourceHash;
                $provenance = [
                    'schema' => 'devboard.memory_import_provenance.v1',
                    'import_batch_id' => $batchId,
                    'source_hash' => $sourceHash,
                    'source_workspace_binding_id' => $sourceWorkspaceBindingId,
                    'target_workspace_binding_id' => $targetWorkspaceBindingId,
                    'source' => $source,
                    'entry_provenance' => $entry['provenance'] ?? [],
                    'entry_payload' => $entry['payload'] ?? [],
                ];

                $existingProposal = DB::table('hades_memory_proposals')
                    ->where('project_id', $projectId)
                    ->where('workspace_binding_id', $targetWorkspaceBindingId)
                    ->where('local_proposal_id', $localProposalId)
                    ->first();

                $itemId = (string) Str::ulid();
                if ($existingProposal) {
                    DB::table('memory_import_items')->insert([
                        'id' => $itemId,
                        'batch_id' => $batchId,
                        'source_local_id' => $entry['source_local_id'] ?? null,
                        'source_hash' => $sourceHash,
                        'proposal_id' => $existingProposal->id,
                        'target_memory_entry_id' => null,
                        'status' => 'duplicate_skipped',
                        'conflict_reason' => 'source_hash already proposed for this target workspace binding',
                        'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    continue;
                }

                $proposalId = (string) Str::ulid();
                DB::table('hades_memory_proposals')->insert([
                    'id' => $proposalId,
                    'project_id' => $projectId,
                    'hades_agent_id' => $requestedByHadesAgentId ?? $this->targetBindingAgentId($targetWorkspaceBindingId),
                    'workspace_binding_id' => $targetWorkspaceBindingId,
                    'local_proposal_id' => $localProposalId,
                    'action' => 'create',
                    'intent' => 'memory_import',
                    'summary' => (string) $entry['summary'],
                    'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    'base_version' => null,
                    'target_memory_entry_id' => null,
                    'memory_entry_id' => null,
                    'status' => 'pending',
                    'reason_code' => null,
                    'reason_message' => null,
                    'decided_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('memory_import_items')->insert([
                    'id' => $itemId,
                    'batch_id' => $batchId,
                    'source_local_id' => $entry['source_local_id'] ?? null,
                    'source_hash' => $sourceHash,
                    'proposal_id' => $proposalId,
                    'target_memory_entry_id' => null,
                    'status' => 'proposal_created',
                    'conflict_reason' => null,
                    'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('memory_import_batches')->where('id', $batchId)->update([
                'status' => 'completed',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $this->batchPayload($batchId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function entriesFromProjectMemory(string $projectId, ?string $sourceWorkspaceBindingId, string $targetWorkspaceBindingId, array $filters = []): array
    {
        $query = DB::table('project_memory_entries')->where('project_id', $projectId);

        $kinds = $filters['kinds'] ?? [];
        if (is_array($kinds) && $kinds !== []) {
            $query->whereIn('kind', array_values($kinds));
        }

        if (isset($filters['since']) && is_string($filters['since']) && trim($filters['since']) !== '') {
            $query->where('occurred_at', '>=', $filters['since']);
        }

        $limit = (int) ($filters['limit'] ?? 25);
        $limit = max(1, min(100, $limit));

        return $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (object $entry) use ($sourceWorkspaceBindingId, $targetWorkspaceBindingId): array {
                $payload = $this->decode($entry->payload);
                $sourceHash = hash('sha256', json_encode([
                    'project_memory_entry_id' => (string) $entry->id,
                    'source_workspace_binding_id' => $sourceWorkspaceBindingId,
                    'target_workspace_binding_id' => $targetWorkspaceBindingId,
                    'summary' => (string) $entry->summary,
                    'payload' => $payload,
                ], JSON_THROW_ON_ERROR));

                return [
                    'source_local_id' => (string) $entry->id,
                    'source_hash' => $sourceHash,
                    'kind' => (string) $entry->kind,
                    'summary' => (string) $entry->summary,
                    'payload' => $payload,
                    'provenance' => [
                        'source' => 'dashboard_workspace_transfer',
                        'memory_entry_id' => (string) $entry->id,
                        'occurred_at' => $entry->occurred_at ? (string) $entry->occurred_at : null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function batchPayload(string $batchId): array
    {
        $batch = DB::table('memory_import_batches')->where('id', $batchId)->first();
        abort_unless($batch, 404);

        $items = DB::table('memory_import_items')
            ->where('batch_id', $batchId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $item): array => [
                'id' => (string) $item->id,
                'source_local_id' => $item->source_local_id ? (string) $item->source_local_id : null,
                'source_hash' => (string) $item->source_hash,
                'proposal_id' => $item->proposal_id ? (string) $item->proposal_id : null,
                'target_memory_entry_id' => $item->target_memory_entry_id ? (string) $item->target_memory_entry_id : null,
                'status' => (string) $item->status,
                'conflict_reason' => $item->conflict_reason ? (string) $item->conflict_reason : null,
                'provenance' => $this->decode($item->provenance),
            ])
            ->all();

        $source = $this->decode($batch->source_payload);
        $statuses = collect($items)->countBy('status');
        $counts = [
            'entries_found' => count($items),
            'proposals_created' => (int) ($statuses->get('proposal_created') ?? 0),
            'accepted_created' => (int) ($statuses->get('accepted_created') ?? 0),
            'skipped_duplicates' => (int) ($statuses->get('duplicate_skipped') ?? 0),
            'conflicted' => (int) ($statuses->get('conflicted') ?? 0),
        ];

        return [
            'id' => (string) $batch->id,
            'project_id' => (string) $batch->project_id,
            'source_workspace_binding_id' => $batch->source_workspace_binding_id ? (string) $batch->source_workspace_binding_id : null,
            'target_workspace_binding_id' => (string) $batch->target_workspace_binding_id,
            'requested_by_user_id' => $batch->requested_by_user_id === null ? null : (int) $batch->requested_by_user_id,
            'requested_by_hades_agent_id' => $batch->requested_by_hades_agent_id ? (string) $batch->requested_by_hades_agent_id : null,
            'status' => (string) $batch->status,
            'mode' => (string) $batch->mode,
            'dedupe_strategy' => (string) $batch->dedupe_strategy,
            'conflict_policy' => (string) $batch->conflict_policy,
            'reason' => $batch->reason ? (string) $batch->reason : null,
            'source' => $source,
            'filters' => is_array($source['filters'] ?? null) ? $source['filters'] : [],
            'counts' => $counts,
            'completed_at' => $batch->completed_at ? (string) $batch->completed_at : null,
            'created_at' => (string) $batch->created_at,
            'updated_at' => (string) $batch->updated_at,
            'items' => $items,
        ];
    }

    private function targetBindingAgentId(string $targetWorkspaceBindingId): string
    {
        return (string) DB::table('hades_workspace_bindings')
            ->where('id', $targetWorkspaceBindingId)
            ->value('hades_agent_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
