<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class ProjectLogbookActor
{
    private const KINDS = ['user', 'agent', 'subagent', 'system'];

    public function __construct(
        public string $kind,
        public string $label,
        public ?int $userId = null,
        public ?string $agentId = null,
        public ?string $deviceId = null,
        public ?string $role = null,
        public ?string $model = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unsupported project logbook actor kind.');
        }

        if ($label === '' || mb_strlen($label) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1) {
            throw new InvalidArgumentException('Invalid project logbook actor label.');
        }

        foreach ([$agentId, $deviceId, $model] as $value) {
            if ($value !== null && ($value === '' || mb_strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1)) {
                throw new InvalidArgumentException('Invalid project logbook actor field.');
            }
        }

        if ($role !== null && ($role === '' || mb_strlen($role) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $role) === 1)) {
            throw new InvalidArgumentException('Invalid project logbook actor role.');
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'user_id' => $this->userId,
            'agent_id' => $this->agentId,
            'device_id' => $this->deviceId,
            'role' => $this->role,
            'model' => $this->model,
        ];
    }
}
