<?php

namespace App\Assistants;

final readonly class ProviderEndpointResolution
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        private string $host,
        private ?int $port,
        private string $scheme,
        private array $addresses,
        private bool $allowed,
        private ?string $denialReason = null,
    ) {}

    public function host(): string
    {
        return $this->host;
    }

    public function port(): ?int
    {
        return $this->port;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return $this->addresses;
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function denialReason(): ?string
    {
        return $this->denialReason;
    }
}
