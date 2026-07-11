<?php

namespace App\Services\Hades;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class PersephoneAgentMessageValidator
{
    private const SCHEMA = 'hades.persephone.agent-message.v1';

    /** @var list<string> */
    private const MESSAGE_TYPES = [
        'information_request',
        'local_decision',
        'information_response',
        'status_query',
        'status_response',
        'cancel_request',
    ];

    /** @var list<string> */
    private const EFFECTS = [
        'information_read',
        'mutating',
    ];

    /** @var list<string> */
    private const BINDING_CAPABILITIES = [
        'source_slice',
        'source_search',
        'symbol_lookup',
        'git_metadata',
        'artifact_metadata',
    ];

    public function __construct(private readonly PersephoneAgentMessageStore $store) {}

    public function requiresWorkspaceBinding(string $capability): bool
    {
        return in_array($capability, self::BINDING_CAPABILITIES, true);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function envelope(Request $request): array
    {
        $errors = [];
        $rawPayloadIsObject = false;

        try {
            $rawEnvelope = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);

            if (! is_object($rawEnvelope)) {
                $errors['envelope'][] = 'The envelope must be a JSON object.';
            } else {
                $rawPayloadIsObject = isset($rawEnvelope->payload) && is_object($rawEnvelope->payload);

                foreach ([
                    'target_workspace_binding_id',
                    'causation_id',
                    'remote_task_id',
                    'remote_task_version',
                ] as $nullableField) {
                    if (property_exists($rawEnvelope, $nullableField)
                        && is_string($rawEnvelope->{$nullableField})
                        && trim($rawEnvelope->{$nullableField}) === '') {
                        $errors[$nullableField][] = 'The '.$nullableField.' field must be null or a non-blank string.';
                    }
                }
            }

            $normalized = $this->store->normalizeEnvelope($request->json()->all());
        } catch (JsonException $exception) {
            $errors['envelope'][] = 'The envelope must be valid JSON.';
            $normalized = [];
        } catch (\InvalidArgumentException $exception) {
            $errors['envelope'][] = $exception->getMessage();
            $normalized = [];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages($errors === [] ? [
                'envelope' => 'The envelope is invalid.',
            ] : $errors);
        }

        if ($normalized['schema'] !== self::SCHEMA) {
            $errors['schema'][] = 'The schema must be '.self::SCHEMA.'.';
        }

        if (! in_array($normalized['message_type'], self::MESSAGE_TYPES, true)) {
            $errors['message_type'][] = 'The message type is invalid.';
        }

        if (! in_array($normalized['effect'], self::EFFECTS, true)) {
            $errors['effect'][] = 'The effect is invalid.';
        }

        if ($normalized['expires_at'] <= now()->timestamp) {
            $errors['expires_at'][] = 'The expiry must be a future Unix timestamp.';
        }

        if (! $rawPayloadIsObject) {
            $errors['payload'][] = 'The payload must be a JSON object.';
        } elseif (count($normalized['payload']) > 128) {
            $errors['payload'][] = 'The payload may contain at most 128 top-level properties.';
        } else {
            try {
                $payloadJson = json_encode(
                    $normalized['payload'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );

                if (strlen($payloadJson) > 65536) {
                    $errors['payload'][] = 'The canonical payload may not exceed 65536 bytes.';
                }
            } catch (JsonException $exception) {
                $errors['payload'][] = 'The payload must contain valid UTF-8 JSON.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @return array{
     *     project_id: string,
     *     target_agent_id: string,
     *     target_workspace_binding_id: string|null,
     *     cursor: string|null,
     *     limit: int
     * }
     *
     * @throws ValidationException
     */
    public function inbox(Request $request): array
    {
        $errors = [];
        $projectId = $this->queryString($request, 'project_id', true, $errors);
        $targetAgentId = $this->queryString($request, 'target_agent_id', true, $errors);
        $bindingId = $this->queryString($request, 'target_workspace_binding_id', false, $errors);
        $cursor = $this->queryString($request, 'cursor', false, $errors);
        $limit = $this->queryInteger($request, 'limit', $errors);

        if ($cursor !== null && ! Str::isUlid($cursor)) {
            $errors['cursor'][] = 'The cursor must be a valid ULID.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'project_id' => $projectId,
            'target_agent_id' => $targetAgentId,
            'target_workspace_binding_id' => $bindingId,
            'cursor' => $cursor,
            'limit' => $limit,
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function queryString(Request $request, string $key, bool $required, array &$errors): ?string
    {
        $value = $request->query($key);

        if ($value === null) {
            if ($required) {
                $errors[$key][] = 'The '.$key.' field is required.';
            }

            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            $errors[$key][] = 'The '.$key.' field must be a non-blank string.';

            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function queryInteger(Request $request, string $key, array &$errors): int
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return 100;
        }

        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            $errors[$key][] = 'The '.$key.' field must be an integer.';

            return 100;
        }

        $limit = (int) $value;

        if ($limit < 1 || $limit > 100) {
            $errors[$key][] = 'The '.$key.' field must be between 1 and 100.';
        }

        return $limit;
    }
}
