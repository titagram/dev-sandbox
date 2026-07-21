<?php

namespace App\Services\Hades;

class HadesCapabilityPolicy
{
    /** @var list<string> */
    private const LEGACY_NULL_GRANT_CAPABILITIES = [
        'read_files',
        'read_source_slice',
        'project_inspection',
        'sync_git_tree',
        'populate_backend_ast',
        'populate_project_wiki',
    ];

    /** @var list<string> */
    private const SUPPORTED_M1_CAPABILITIES = [
        'read_files',
        'read_source_slice',
        'project_inspection',
        'sync_git_tree',
        'populate_backend_ast',
        'populate_project_wiki',
        'verify_project_wiki',
        'write_project_logbook',
    ];

    /**
     * @return list<string>
     */
    public function supportedNames(): array
    {
        return self::SUPPORTED_M1_CAPABILITIES;
    }

    /**
     * Capabilities implicitly granted by bootstrap tokens created before
     * explicit capability catalogs were persisted.
     *
     * @return list<string>
     */
    public function legacyNullGrantNames(): array
    {
        return self::LEGACY_NULL_GRANT_CAPABILITIES;
    }

    /**
     * @param  array<mixed>  $capabilities
     * @return list<string>
     */
    public function normalizeNames(array $capabilities): array
    {
        $names = [];

        foreach ($capabilities as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                if ($value) {
                    $names[] = $key;
                }

                continue;
            }

            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }

        return array_values(array_filter(
            $this->supportedNames(),
            fn (string $capability): bool => in_array($capability, $names, true),
        ));
    }

    /**
     * @param  list<string>  $declared
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function intersect(array $declared, array $allowed): array
    {
        return array_values(array_filter(
            $this->supportedNames(),
            fn (string $capability): bool => in_array($capability, $declared, true)
                && in_array($capability, $allowed, true),
        ));
    }

    /**
     * @param  list<string>  $capabilities
     * @return array<string, bool>
     */
    public function toMap(array $capabilities): array
    {
        return array_fill_keys($capabilities, true);
    }

    /**
     * @return array<string, bool>
     */
    public function m1Policy(): array
    {
        return [
            'workspace_binding_required' => true,
            'memory' => true,
            'jobs' => true,
            'artifacts' => true,
            'persephone' => true,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function m1Limits(): array
    {
        return [
            'max_capabilities_per_agent' => count($this->supportedNames()),
        ];
    }
}
