<?php

namespace App\Services\Hades;

use Symfony\Component\HttpFoundation\Response;

class HadesWikiCapability
{
    public function assertCanWrite(object $agent): void
    {
        $decoded = is_string($agent->effective_capabilities ?? null)
            ? json_decode($agent->effective_capabilities, true)
            : ($agent->effective_capabilities ?? []);

        $effectiveCapabilities = is_array($decoded) ? $decoded : [];

        if (! in_array('populate_project_wiki', $effectiveCapabilities, true)) {
            throw new HadesTokenException(
                'wiki_capability_not_allowed',
                'The populate_project_wiki capability is not enabled for this Hades agent.',
                Response::HTTP_FORBIDDEN,
            );
        }
    }
}
