<?php

namespace App\Quality\Actor;

final class ActorRegistry
{
    /**
     * @return list<string>
     */
    public function supportedActors(): array
    {
        return ['guest'];
    }

    public function supports(string $actor): bool
    {
        return in_array($actor, $this->supportedActors(), true);
    }
}
