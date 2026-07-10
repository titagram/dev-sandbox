<?php

namespace App\Assistants;

interface ProviderHostResolver
{
    /**
     * Resolve a hostname to its A/AAAA IP addresses.
     *
     * @return list<string> normalized IPv4/IPv6 address strings (no brackets)
     */
    public function resolve(string $host): array;
}