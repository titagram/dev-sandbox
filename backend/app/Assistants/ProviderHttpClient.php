<?php

namespace App\Assistants;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ProviderHttpClient
{
    /** @var array<string, ProviderEndpointResolution> */
    private array $scopes = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private ?string $token = null;

    private bool $acceptsJson = false;

    private bool $sendsJson = false;

    /** @var array{times: int, sleep: int}|null */
    private ?array $retry = null;

    public function __construct(private readonly ProviderEndpointPolicy $policy)
    {
    }

    public function withToken(string $token): self
    {
        $clone = clone $this;
        $clone->token = $token;

        return $clone;
    }

    public function acceptJson(): self
    {
        $clone = clone $this;
        $clone->acceptsJson = true;

        return $clone;
    }

    public function asJson(): self
    {
        $clone = clone $this;
        $clone->sendsJson = true;

        return $clone;
    }

    public function timeout(int|float $seconds): self
    {
        $clone = clone $this;
        $clone->options['timeout'] = $seconds;

        return $clone;
    }

    public function retry(int $times, int $sleepMilliseconds): self
    {
        $clone = clone $this;
        $clone->retry = ['times' => $times, 'sleep' => $sleepMilliseconds];

        return $clone;
    }

    public function get(string $url): Response
    {
        return $this->request('get', $url);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $url, array $data = []): Response
    {
        return $this->request('post', $url, $data);
    }

    public function resolve(string $url): ProviderEndpointResolution
    {
        return $this->policy->resolve($url);
    }

    public function beginScope(string $url): ?ProviderEndpointResolution
    {
        $resolution = $this->resolve($url);

        if (! $resolution->allowed()) {
            return null;
        }

        $this->scopes[$resolution->host()] = $resolution;

        return $resolution;
    }

    public function endScope(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';

        if ($host !== '') {
            unset($this->scopes[$host]);
        }
    }

    public function scopeForHost(string $host): ?ProviderEndpointResolution
    {
        return $this->scopes[strtolower($host)] ?? null;
    }

    /**
     * @return array{allow_redirects: false, curl: array<int, mixed>}
     */
    public function curlOptionsForResolution(ProviderEndpointResolution $resolution): array
    {
        if (! defined('CURLOPT_RESOLVE') || ! defined('CURLOPT_FOLLOWLOCATION')) {
            throw new RuntimeException('Provider HTTP transport pinning is unavailable: cURL constants are missing.');
        }

        if (! $resolution->allowed() || $resolution->addresses() === []) {
            throw new RuntimeException('Provider endpoint URL is not allowed.');
        }

        $port = $resolution->port() ?? ($resolution->scheme() === 'http' ? 80 : 443);
        $pins = array_map(
            fn (string $address): string => $resolution->host().':'.$port.':'.$address,
            $resolution->addresses(),
        );

        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => $pins,
                CURLOPT_FOLLOWLOCATION => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function request(string $method, string $url, array $data = []): Response
    {
        $resolution = $this->resolve($url);

        if (! $resolution->allowed()) {
            throw new RuntimeException('Provider endpoint URL is not allowed.');
        }

        $request = $this->pendingRequest($resolution);

        return $method === 'post'
            ? $request->post($url, $data)
            : $request->get($url);
    }

    private function pendingRequest(ProviderEndpointResolution $resolution): PendingRequest
    {
        $request = Http::withOptions(array_replace_recursive(
            $this->options,
            $this->curlOptionsForResolution($resolution),
        ));

        if ($this->token !== null) {
            $request = $request->withToken($this->token);
        }

        if ($this->acceptsJson) {
            $request = $request->acceptJson();
        }

        if ($this->sendsJson) {
            $request = $request->asJson();
        }

        if ($this->retry !== null) {
            $request = $request->retry($this->retry['times'], $this->retry['sleep']);
        }

        return $request;
    }
}
