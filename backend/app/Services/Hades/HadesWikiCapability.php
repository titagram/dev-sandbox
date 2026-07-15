<?php

namespace App\Services\Hades;

use Symfony\Component\HttpFoundation\Response;

class HadesWikiCapability
{
    public function assertCanWrite(object $agent): void
    {
        $this->assertCapability($agent, 'populate_project_wiki', 'wiki_capability_not_allowed');
    }

    public function assertCanVerify(object $agent): void
    {
        $this->assertCapability($agent, 'verify_project_wiki', 'wiki_verification_capability_not_allowed');
    }

    private function assertCapability(object $agent, string $capability, string $errorCode): void
    {
        $decoded = is_string($agent->effective_capabilities ?? null)
            ? json_decode($agent->effective_capabilities, true)
            : ($agent->effective_capabilities ?? []);

        $effectiveCapabilities = is_array($decoded) ? $decoded : [];

        if (! in_array($capability, $effectiveCapabilities, true)) {
            throw new HadesTokenException(
                $errorCode,
                "The {$capability} capability is not enabled for this Hades agent.",
                Response::HTTP_FORBIDDEN,
            );
        }
    }
}
