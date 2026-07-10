<?php

use App\Assistants\ProviderEndpointPolicy;
use App\Assistants\ProviderEndpointResolution;
use App\Assistants\ProviderHostResolver;

final class FakeProviderHostResolver implements ProviderHostResolver
{
    /** @var array<string, list<string>> */
    private array $answers = [];

    /** @var list<string> */
    private array $default = [];

    /**
     * @param  list<string>  $default
     */
    public function __construct(array $default = [])
    {
        $this->default = $default;
    }

    /**
     * @param  list<string>  $addresses
     */
    public function forHost(string $host, array $addresses): self
    {
        $this->answers[strtolower($host)] = array_map('strval', $addresses);

        return $this;
    }

    public function resolve(string $host): array
    {
        $key = strtolower($host);

        return $this->answers[$key] ?? $this->default;
    }
}

function makePolicy(ProviderHostResolver $resolver): ProviderEndpointPolicy
{
    return new ProviderEndpointPolicy($resolver);
}

function deniedResolution(string $url, ProviderHostResolver $resolver): ProviderEndpointResolution
{
    return makePolicy($resolver)->resolve($url);
}

it('rejects a host that returns zero DNS answers', function () {
    $resolver = new FakeProviderHostResolver([]);
    $resolution = deniedResolution('https://unresolved.example.test', $resolver);

    expect($resolution->allowed())->toBeFalse()
        ->and($resolution->denialReason())->not->toBeNull();
});

it('rejects malformed URLs and URLs containing userinfo', function () {
    $resolver = new FakeProviderHostResolver(['8.8.8.8']);

    expect(deniedResolution('not-a-url', $resolver)->allowed())->toBeFalse()
        ->and(deniedResolution('/relative/path', $resolver)->allowed())->toBeFalse()
        ->and(deniedResolution('https://user:pass@host.example.test', $resolver)->allowed())->toBeFalse()
        ->and(deniedResolution('https://user@host.example.test', $resolver)->allowed())->toBeFalse();
});

it('rejects unsafe IPv4 literals', function (string $url) {
    $resolver = new FakeProviderHostResolver(['8.8.8.8']);
    $resolution = deniedResolution($url, $resolver);

    expect($resolution->allowed())->toBeFalse();
})->with([
    'loopback' => 'https://127.0.0.1',
    'private 10' => 'https://10.0.0.5',
    'private 172.16' => 'https://172.16.0.5',
    'private 192.168' => 'https://192.168.1.2',
    'link-local' => 'https://169.254.169.254',
    'unspecified' => 'https://0.0.0.0',
    'multicast' => 'https://224.0.0.1',
    'reserved benchmark' => 'https://240.0.0.1',
]);

it('rejects bracketed unsafe IPv6 literals', function (string $url) {
    $resolver = new FakeProviderHostResolver([]);
    $resolution = deniedResolution($url, $resolver);

    expect($resolution->allowed())->toBeFalse();
})->with([
    'loopback' => 'https://[::1]',
    'unspecified' => 'https://[::]',
    'unique local' => 'https://[fc00::1]',
    'link-local' => 'https://[fe80::1]',
    'multicast' => 'https://[ff02::1]',
]);

it('rejects IPv4-mapped IPv6 literals that map to unsafe IPv4', function (string $url) {
    $resolver = new FakeProviderHostResolver([]);
    $resolution = deniedResolution($url, $resolver);

    expect($resolution->allowed())->toBeFalse();
})->with([
    'mapped loopback' => 'https://[::ffff:127.0.0.1]',
    'mapped link-local' => 'https://[::ffff:169.254.169.254]',
]);

it('rejects a hostname when one A answer is public and one A answer is private', function () {
    $resolver = (new FakeProviderHostResolver())
        ->forHost('mixed-a.example.test', ['8.8.8.8', '10.0.0.5']);

    $resolution = deniedResolution('https://mixed-a.example.test', $resolver);

    expect($resolution->allowed())->toBeFalse();
});

it('rejects a hostname with a public A answer and a private AAAA answer', function () {
    $resolver = (new FakeProviderHostResolver())
        ->forHost('mixed-aaaa.example.test', ['8.8.8.8', 'fc00::1']);

    $resolution = deniedResolution('https://mixed-aaaa.example.test', $resolver);

    expect($resolution->allowed())->toBeFalse();
});

it('accepts a hostname only when every returned A/AAAA address is public', function () {
    $resolver = (new FakeProviderHostResolver())
        ->forHost('all-public.example.test', ['8.8.8.8', '2606:4700:4700::1111']);

    $resolution = deniedResolution('https://all-public.example.test:443', $resolver);

    expect($resolution->allowed())->toBeTrue()
        ->and($resolution->host())->toBe('all-public.example.test')
        ->and($resolution->port())->toBe(443)
        ->and($resolution->scheme())->toBe('https')
        ->and($resolution->addresses())->toBe(['8.8.8.8', '2606:4700:4700::1111']);
});

it('accepts a public IPv6 literal after bracket normalization', function () {
    $resolver = new FakeProviderHostResolver([]);
    $resolution = deniedResolution('https://[2606:4700:4700::1111]', $resolver);

    expect($resolution->allowed())->toBeTrue()
        ->and($resolution->addresses())->toBe(['2606:4700:4700::1111']);
});