<?php

namespace App\Services\Hades;

use App\Models\PersephoneAgentMessage;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class PersephoneAgentMessageStore
{
    private const SCHEMA = 'hades.persephone.agent-message.v1';

    /** @var list<string> */
    private const ENVELOPE_FIELDS = [
        'schema',
        'message_id',
        'correlation_id',
        'project_id',
        'sender_agent_id',
        'target_agent_id',
        'target_workspace_binding_id',
        'message_type',
        'effect',
        'capability',
        'expires_at',
        'payload',
        'causation_id',
        'remote_task_id',
        'remote_task_version',
    ];

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'schema',
        'message_id',
        'correlation_id',
        'project_id',
        'sender_agent_id',
        'target_agent_id',
        'target_workspace_binding_id',
        'message_type',
        'effect',
        'capability',
        'expires_at',
        'payload',
    ];

    /** @var list<string> */
    private const STRING_FIELDS = [
        'schema',
        'message_id',
        'correlation_id',
        'project_id',
        'sender_agent_id',
        'target_agent_id',
        'target_workspace_binding_id',
        'message_type',
        'effect',
        'capability',
        'causation_id',
        'remote_task_id',
        'remote_task_version',
    ];

    /** @var list<string> */
    private const NULLABLE_STRING_FIELDS = [
        'target_workspace_binding_id',
        'causation_id',
        'remote_task_id',
        'remote_task_version',
    ];

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeEnvelope(array $envelope): array
    {
        $unknown = array_diff(array_keys($envelope), self::ENVELOPE_FIELDS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown Persephone envelope fields: '.implode(', ', $unknown));
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $envelope)) {
                throw new InvalidArgumentException("Missing Persephone envelope field [{$field}].");
            }
        }

        $normalized = [];

        foreach (self::ENVELOPE_FIELDS as $field) {
            $value = $envelope[$field] ?? null;

            if (in_array($field, self::STRING_FIELDS, true)) {
                $nullable = in_array($field, self::NULLABLE_STRING_FIELDS, true);

                if ($value === null && ! $nullable) {
                    throw new InvalidArgumentException("Persephone envelope field [{$field}] must be a non-blank string.");
                }

                if ($value !== null && ! is_string($value)) {
                    throw new InvalidArgumentException("Persephone envelope field [{$field}] must be a string or null.");
                }

                $value = $value === null ? null : trim($value);

                if ($value === '') {
                    throw new InvalidArgumentException("Persephone envelope field [{$field}] must not be blank.");
                }
            }

            if ($field === 'expires_at' && ! is_int($value)) {
                throw new InvalidArgumentException('Persephone envelope field [expires_at] must be an integer.');
            }

            if ($field === 'payload' && ! is_array($value)) {
                throw new InvalidArgumentException('Persephone envelope field [payload] must be an object.');
            }

            $normalized[$field] = $field === 'payload'
                ? $this->sortKeys($value)
                : $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function fingerprint(array $envelope): string
    {
        return hash('sha256', $this->canonicalJson($this->normalizeEnvelope($envelope)));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function canonicalJson(array $envelope): string
    {
        return json_encode(
            $this->sortKeys($envelope),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{message: PersephoneAgentMessage, replayed: bool}
     */
    public function store(array $envelope): array
    {
        $normalized = $this->normalizeEnvelope($envelope);
        $fingerprint = $this->canonicalFingerprint($normalized);

        $existing = $this->find($normalized['project_id'], $normalized['message_id']);

        if ($existing) {
            return $this->resolveExisting($existing, $normalized, $fingerprint);
        }

        try {
            $message = PersephoneAgentMessage::query()->create([
                'project_id' => $normalized['project_id'],
                'sender_agent_id' => $normalized['sender_agent_id'],
                'target_agent_id' => $normalized['target_agent_id'],
                'target_workspace_binding_id' => $normalized['target_workspace_binding_id'],
                'schema' => $normalized['schema'],
                'message_id' => $normalized['message_id'],
                'correlation_id' => $normalized['correlation_id'],
                'causation_id' => $normalized['causation_id'],
                'remote_task_id' => $normalized['remote_task_id'],
                'remote_task_version' => $normalized['remote_task_version'],
                'message_type' => $normalized['message_type'],
                'effect' => $normalized['effect'],
                'capability' => $normalized['capability'],
                'expires_at' => $normalized['expires_at'],
                'payload' => $normalized['payload'],
                'envelope' => $normalized,
                'envelope_hash' => $fingerprint,
            ]);

            return ['message' => $message, 'replayed' => false];
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = $this->find($normalized['project_id'], $normalized['message_id']);

            if (! $existing) {
                throw $exception;
            }

            return $this->resolveExisting($existing, $normalized, $fingerprint);
        }
    }

    private function canonicalFingerprint(array $normalized): string
    {
        return hash('sha256', $this->canonicalJson($normalized));
    }

    private function find(string $projectId, string $messageId): ?PersephoneAgentMessage
    {
        return PersephoneAgentMessage::query()
            ->where('project_id', $projectId)
            ->where('message_id', $messageId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{message: PersephoneAgentMessage, replayed: bool}
     */
    private function resolveExisting(
        PersephoneAgentMessage $existing,
        array $normalized,
        string $fingerprint,
    ): array {
        if (! hash_equals((string) $existing->envelope_hash, $fingerprint)
            || $existing->envelope !== $normalized) {
            throw new PersephoneAgentMessageConflict(
                (string) $normalized['project_id'],
                (string) $normalized['message_id'],
            );
        }

        return ['message' => $existing, 'replayed' => true];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortKeys($item);
        }

        if (! $isList) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
