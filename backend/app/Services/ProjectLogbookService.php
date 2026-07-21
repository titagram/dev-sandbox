<?php

namespace App\Services;

use App\Exceptions\ProjectLogbookException;
use App\Models\ProjectLogbookEntry;
use App\Services\Hades\HadesEvidencePolicy;
use App\Support\ProjectLogbookActor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class ProjectLogbookService
{
    /** @var list<string> */
    private const EVENT_TYPES = [
        'change',
        'creation',
        'import',
        'projection',
        'verification',
        'wiki',
        'decision',
        'failure',
        'rollback',
        'note',
    ];

    /** @var list<string> */
    private const SEVERITIES = ['info', 'warning', 'error'];

    /** @var list<string> */
    private const COMMAND_KEYS = [
        'project_id',
        'event_type',
        'severity',
        'summary',
        'narrative_markdown',
        'references',
        'correlation_id',
        'idempotency_key',
        'payload',
        'supersedes_entry_id',
    ];

    public function __construct(
        private readonly ProjectLogbookReferenceValidator $references,
        private readonly HadesEvidencePolicy $evidencePolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $command
     * @return array{entry:ProjectLogbookEntry,replayed:bool}
     */
    public function append(array $command, ProjectLogbookActor $actor): array
    {
        [$normalized, $digest] = $this->normalize($command, $actor);

        try {
            return DB::transaction(function () use ($normalized, $digest, $actor): array {
                $existing = ProjectLogbookEntry::query()
                    ->where('project_id', $normalized['project_id'])
                    ->where('idempotency_key', $normalized['idempotency_key'])
                    ->first();

                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $digest);
                }

                $now = now();
                $entry = ProjectLogbookEntry::query()->create([
                    'id' => (string) Str::ulid(),
                    'project_id' => $normalized['project_id'],
                    'occurred_at' => $now,
                    'recorded_at' => $now,
                    'actor_kind' => $actor->kind,
                    'actor_label' => $actor->label,
                    'actor_user_id' => $actor->userId,
                    'actor_agent_id' => $actor->agentId,
                    'actor_device_id' => $actor->deviceId,
                    'actor_role' => $actor->role,
                    'actor_model' => $actor->model,
                    'event_type' => $normalized['event_type'],
                    'severity' => $normalized['severity'],
                    'summary' => $normalized['summary'],
                    'narrative_markdown' => $normalized['narrative_markdown'],
                    'references' => $normalized['references'],
                    'correlation_id' => $normalized['correlation_id'],
                    'idempotency_key' => $normalized['idempotency_key'],
                    'request_sha256' => $digest,
                    'payload' => $normalized['payload'] === [] ? (object) [] : $normalized['payload'],
                    'supersedes_entry_id' => $normalized['supersedes_entry_id'],
                ]);

                app(AuditLogger::class)->record(
                    'project_logbook.appended',
                    'project_logbook_entry',
                    $entry->id,
                    [
                        'project_id' => $entry->project_id,
                        'event_type' => $entry->event_type,
                        'actor' => [
                            'kind' => $actor->kind,
                            'agent_id' => $actor->agentId,
                            'device_id' => $actor->deviceId,
                        ],
                    ],
                    [
                        'type' => $actor->kind,
                        'user_id' => $actor->userId,
                    ],
                );

                return ['entry' => $entry->fresh(), 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isIdempotencyRace($exception)) {
                throw $exception;
            }

            $winner = ProjectLogbookEntry::query()
                ->where('project_id', $normalized['project_id'])
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->first();

            if ($winner === null) {
                throw $exception;
            }

            return $this->replayOrConflict($winner, $digest);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items:list<ProjectLogbookEntry>,next_cursor:?string}
     */
    public function listForProject(
        string $projectId,
        array $filters = [],
        ?string $cursor = null,
        int $limit = 20,
    ): array {
        if ($limit < 1 || $limit > 50) {
            throw ProjectLogbookException::invalid('List limit must be between 1 and 50.');
        }

        $query = ProjectLogbookEntry::query()->where('project_id', $projectId);
        $this->applyFilters($query, $filters);

        if ($cursor !== null) {
            [$recordedAt, $id] = $this->decodeCursor($cursor);
            $query->where(function ($builder) use ($recordedAt, $id): void {
                $builder->where('recorded_at', '<', $recordedAt)
                    ->orWhere(function ($sameTime) use ($recordedAt, $id): void {
                        $sameTime->where('recorded_at', $recordedAt)->where('id', '<', $id);
                    });
            });
        }

        $rows = $query->orderByDesc('recorded_at')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return [
            'items' => $items->all(),
            'next_cursor' => $hasMore && $last instanceof ProjectLogbookEntry
                ? $this->encodeCursor($last)
                : null,
        ];
    }

    public function showForProject(string $projectId, string $entryId): ?ProjectLogbookEntry
    {
        return ProjectLogbookEntry::query()
            ->where('project_id', $projectId)
            ->where('id', $entryId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array{array<string,mixed>,string}
     */
    private function normalize(array $command, ProjectLogbookActor $actor): array
    {
        $unknown = array_diff(array_keys($command), self::COMMAND_KEYS);
        $missing = array_diff(self::COMMAND_KEYS, array_keys($command));
        if ($unknown !== [] || $missing !== []) {
            throw ProjectLogbookException::invalid('Logbook command fields do not match the closed contract.');
        }

        $projectId = $this->requiredString($command['project_id'], 'project_id', 191);
        if (! DB::table('projects')->where('id', $projectId)->exists()) {
            throw ProjectLogbookException::invalid('Project does not exist.');
        }

        $eventType = $this->requiredString($command['event_type'], 'event_type', 32);
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw ProjectLogbookException::invalid('Unsupported event_type.');
        }

        $severity = $this->requiredString($command['severity'], 'severity', 16);
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw ProjectLogbookException::invalid('Unsupported severity.');
        }

        $summary = $this->requiredString($command['summary'], 'summary', 240);
        if (preg_match('/[\x00-\x1F\x7F]/u', $summary) === 1 || strip_tags($summary) !== $summary) {
            throw ProjectLogbookException::invalid('Summary must be plain text.');
        }

        $narrative = $this->nullableString($command['narrative_markdown'], 'narrative_markdown', 8000);
        if ($narrative !== null && strip_tags($narrative) !== $narrative) {
            throw ProjectLogbookException::invalid('Narrative Markdown must not contain raw HTML.');
        }

        if (! is_array($command['references'])) {
            throw ProjectLogbookException::invalid('References must be an array.');
        }
        $references = $this->references->canonicalize($projectId, $command['references']);

        $correlationId = $this->nullableString($command['correlation_id'], 'correlation_id', 191);
        $idempotencyKey = $this->requiredString($command['idempotency_key'], 'idempotency_key', 128);
        if (preg_match('/\A[!-~]{16,128}\z/D', $idempotencyKey) !== 1) {
            throw ProjectLogbookException::invalid('Idempotency key must contain 16 to 128 printable ASCII characters without spaces.');
        }

        if (! is_array($command['payload'])) {
            throw ProjectLogbookException::invalid('Payload must be an object.');
        }
        $payload = $this->canonicalValue($command['payload'], 0);
        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw ProjectLogbookException::invalid('Payload must be a JSON object.');
        }

        if ($this->evidencePolicy->validateProjectLogbook(
            $summary,
            $narrative,
            $references,
            $correlationId,
            $payload,
        ) !== null) {
            throw ProjectLogbookException::secretDetected();
        }

        $supersedes = $this->nullableString($command['supersedes_entry_id'], 'supersedes_entry_id', 191);
        if ($supersedes !== null && ! ProjectLogbookEntry::query()
            ->where('project_id', $projectId)
            ->where('id', $supersedes)
            ->exists()) {
            throw ProjectLogbookException::invalid('Superseded entry does not exist in this project.');
        }

        $normalized = [
            'project_id' => $projectId,
            'event_type' => $eventType,
            'severity' => $severity,
            'summary' => $summary,
            'narrative_markdown' => $narrative,
            'references' => $references,
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
            'supersedes_entry_id' => $supersedes,
        ];

        try {
            $canonicalValue = $this->canonicalValue(['actor' => $this->actorIdentity($actor), 'command' => $normalized], 0);
            if ($payload === []) {
                $canonicalValue['command']['payload'] = (object) [];
            }
            $canonical = json_encode(
                $canonicalValue,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw ProjectLogbookException::invalid('Logbook command is not valid JSON: '.$exception->getMessage());
        }

        if (strlen($canonical) > 65536) {
            throw ProjectLogbookException::invalid('Logbook command exceeds 65,536 canonical JSON bytes.');
        }

        return [$normalized, hash('sha256', $canonical)];
    }

    /** @return array<string, int|string|null> */
    private function actorIdentity(ProjectLogbookActor $actor): array
    {
        return match ($actor->kind) {
            'agent', 'subagent' => ['kind' => $actor->kind, 'agent_id' => $actor->agentId],
            'user' => ['kind' => $actor->kind, 'user_id' => $actor->userId],
            'system' => ['kind' => $actor->kind],
            default => ['kind' => $actor->kind],
        };
    }

    private function requiredString(mixed $value, string $field, int $max): string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $max) {
            throw ProjectLogbookException::invalid($field.' must be a non-empty bounded string.');
        }

        return $value;
    }

    private function nullableString(mixed $value, string $field, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '' || mb_strlen($value) > $max || preg_match('/\x00/u', $value) === 1) {
            throw ProjectLogbookException::invalid($field.' must be null or a non-empty bounded string.');
        }

        return $value;
    }

    private function canonicalValue(mixed $value, int $depth): mixed
    {
        if ($depth > 12) {
            throw ProjectLogbookException::invalid('Logbook JSON exceeds the maximum nesting depth.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value) || is_object($value) || is_resource($value) || ! is_array($value)) {
            throw ProjectLogbookException::invalid('Logbook JSON accepts only null, booleans, integers, strings, arrays, and objects.');
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalValue($item, $depth + 1), $value);
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || $key === '') {
                throw ProjectLogbookException::invalid('Logbook JSON object keys must be non-empty strings.');
            }
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item, $depth + 1);
        }

        return $value;
    }

    /**
     * @return array{entry:ProjectLogbookEntry,replayed:bool}
     */
    private function replayOrConflict(ProjectLogbookEntry $entry, string $digest): array
    {
        if (! hash_equals($entry->request_sha256, $digest)) {
            throw ProjectLogbookException::idempotencyConflict();
        }

        return ['entry' => $entry, 'replayed' => true];
    }

    private function isIdempotencyRace(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'project_logbook_entries_project_idempotency_unique');
    }

    private function applyFilters($query, array $filters): void
    {
        $allowed = ['event_types', 'actor_kind', 'severity', 'from', 'to', 'q'];
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw ProjectLogbookException::invalid('Unsupported logbook filter.');
        }

        if (isset($filters['event_types'])) {
            if (! is_array($filters['event_types'])
                || $filters['event_types'] === []
                || array_diff($filters['event_types'], self::EVENT_TYPES) !== []) {
                throw ProjectLogbookException::invalid('Invalid event type filter.');
            }
            $query->whereIn('event_type', array_values(array_unique($filters['event_types'])));
        }

        foreach (['actor_kind', 'severity'] as $field) {
            if (isset($filters[$field])) {
                $value = $this->requiredString($filters[$field], $field, 32);
                $allowedValues = $field === 'severity' ? self::SEVERITIES : ['user', 'agent', 'subagent', 'system'];
                if (! in_array($value, $allowedValues, true)) {
                    throw ProjectLogbookException::invalid('Invalid '.$field.' filter.');
                }
                $query->where($field, $value);
            }
        }

        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (isset($filters[$field])) {
                try {
                    $time = Carbon::parse($this->requiredString($filters[$field], $field, 64))->utc();
                } catch (Throwable) {
                    throw ProjectLogbookException::invalid('Invalid '.$field.' timestamp filter.');
                }
                $query->where('recorded_at', $operator, $time);
            }
        }

        if (isset($filters['q'])) {
            $term = $this->requiredString($filters['q'], 'q', 200);
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereRaw("summary LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("narrative_markdown LIKE ? ESCAPE '!'", [$like]);
            });
        }
    }

    private function encodeCursor(ProjectLogbookEntry $entry): string
    {
        $json = json_encode(
            ['recorded_at' => $entry->recorded_at->utc()->toISOString(), 'id' => $entry->id],
            JSON_THROW_ON_ERROR,
        );

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{string,string} */
    private function decodeCursor(string $cursor): array
    {
        $padding = strlen($cursor) % 4;
        $decoded = base64_decode(strtr($cursor.($padding === 0 ? '' : str_repeat('=', 4 - $padding)), '-_', '+/'), true);

        try {
            $value = is_string($decoded) ? json_decode($decoded, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $value = null;
        }

        if (! is_array($value)
            || array_keys($value) !== ['recorded_at', 'id']
            || ! is_string($value['recorded_at'])
            || ! is_string($value['id'])
            || ! Str::isUlid($value['id'])) {
            throw ProjectLogbookException::invalid('Invalid logbook cursor.');
        }

        try {
            $recordedAt = Carbon::parse($value['recorded_at'])->utc()->format('Y-m-d H:i:s.uP');
        } catch (Throwable) {
            throw ProjectLogbookException::invalid('Invalid logbook cursor timestamp.');
        }

        return [$recordedAt, $value['id']];
    }
}
