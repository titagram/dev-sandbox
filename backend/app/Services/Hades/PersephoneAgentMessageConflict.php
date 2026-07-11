<?php

namespace App\Services\Hades;

use RuntimeException;

class PersephoneAgentMessageConflict extends RuntimeException
{
    public function __construct(string $projectId, string $messageId)
    {
        parent::__construct("Persephone message [{$messageId}] already exists with different content in project [{$projectId}].");
    }
}
