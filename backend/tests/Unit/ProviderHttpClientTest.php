<?php

use App\Assistants\ProviderEndpointPolicy;
use App\Assistants\ProviderEndpointResolution;
use App\Assistants\ProviderHttpClient;
use App\Assistants\ProviderHostResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

describe('ProviderHttpClient transport pinning', function () {
    function makeClient(ProviderHostResolver $resolver): ProviderHttpClient
    {
        return new ProviderHttpClient(new ProviderEndpointPolicy($resolver));
    }

    it('disables redirect following in the pinned curl options', function () {
        $client = makeClient(new class implements ProviderHostResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        });

        $resolution = $client->resolve('https://api.example.com/v1');
        $options = $client->curlOptionsForResolution($resolution);

        expect($options)->toHaveKey('curl')
            ->and($options['curl'])->toHaveKey(CURLOPT_RESOLVE)
            ->and($options['curl'])->toHaveKey(CURLOPT_FOLLOWLOCATION)
            ->and($options['curl'][CURLOPT_FOLLOWLOCATION])->toBeFalse()
            ->and($options['allow_redirects'])->toBeFalse();
    });

    it('pins the checked IP via CURLOPT_RESOLVE while preserving the hostname in the URL', function () {
        $resolver = new class implements ProviderHostResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $policy = new ProviderEndpointPolicy($resolver);
        $client = new ProviderHttpClient($policy);

        $resolution = $client->resolve('https://api.example.com/v1/models');
        $options = $client->curlOptionsForResolution($resolution);

        expect($options['curl'][CURLOPT_RESOLVE])->toBe(['api.example.com:443:8.8.8.8'])
            ->and($resolution->host())->toBe('api.example.com')
            ->and($resolution->addresses())->toBe(['8.8.8.8']);
    });

    it('resolves and dispatches as one operation with no TOCTOU gap', function () {
        $resolver = new class implements ProviderHostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                return ['8.8.8.8'];
            }
        };
        $client = makeClient($resolver);

        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 200)]);

        $response = $client->get('https://api.example.com/v1/models');

        expect($response->ok())->toBeTrue()
            ->and($resolver->calls)->toBe(1);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.example.com/v1/models');
    });

    it('fails closed without dispatching when the host resolves to a private address', function () {
        $resolver = new class implements ProviderHostResolver
        {
            public function resolve(string $host): array
            {
                return ['169.254.169.254'];
            }
        };
        $client = makeClient($resolver);

        Http::preventStrayRequests();
        Http::fake(fn () => Http::response('should not be called', 200));

        expect(fn () => $client->get('https://ssrf.example.test/latest/meta-data'))
            ->toThrow(RuntimeException::class, 'Provider endpoint URL is not allowed.');

        Http::assertNothingSent();
    });

    it('fails closed without dispatching when the host cannot be resolved', function () {
        $resolver = new class implements ProviderHostResolver
        {
            public function resolve(string $host): array
            {
                return [];
            }
        };
        $client = makeClient($resolver);

        Http::preventStrayRequests();
        Http::fake(fn () => Http::response('should not be called', 200));

        expect(fn () => $client->post('https://unresolved.example.test/v1/chat/completions', []))
            ->toThrow(RuntimeException::class, 'Provider endpoint URL is not allowed.');

        Http::assertNothingSent();
    });

    it('pins and dispatches a public host whose A/AAAA answers are all public', function () {
        $resolver = new class implements ProviderHostResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8', '1.1.1.1'];
            }
        };
        $client = makeClient($resolver);

        Http::fake(['api.example.com/*' => Http::response(['data' => [['id' => 'gpt-5.4']]], 200)]);

        $resolution = $client->resolve('https://api.example.com/v1/models');
        $options = $client->curlOptionsForResolution($resolution);

        expect($resolution->allowed())->toBeTrue()
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe([
                'api.example.com:443:8.8.8.8',
                'api.example.com:443:1.1.1.1',
            ]);

        $response = $client->acceptJson()->withToken('secret')->get('https://api.example.com/v1/models');

        expect($response->ok())->toBeTrue()
            ->and($response->json('data.0.id'))->toBe('gpt-5.4');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.example.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer secret'));
    });
});

describe('ProviderHttpClient pinned scope', function () {
    it('registers and clears a resolved scope for a public host', function () {
        $resolver = new class implements ProviderHostResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $client = new ProviderHttpClient(new ProviderEndpointPolicy($resolver));

        expect($client->scopeForHost('api.example.com'))->toBeNull();

        $resolution = $client->beginScope('https://api.example.com/v1');

        expect($resolution)->toBeInstanceOf(ProviderEndpointResolution::class)
            ->and($client->scopeForHost('api.example.com'))->not->toBeNull()
            ->and($client->curlOptionsForResolution($client->scopeForHost('api.example.com'))['curl'][CURLOPT_RESOLVE])
            ->toBe(['api.example.com:443:8.8.8.8']);

        $client->endScope('https://api.example.com/v1');

        expect($client->scopeForHost('api.example.com'))->toBeNull();
    });

    it('returns null instead of registering a scope for an unsafe host', function () {
        $resolver = new class implements ProviderHostResolver
        {
            public function resolve(string $host): array
            {
                return ['10.0.0.5'];
            }
        };
        $client = new ProviderHttpClient(new ProviderEndpointPolicy($resolver));

        expect($client->beginScope('https://ssrf.example.test'))->toBeNull()
            ->and($client->scopeForHost('ssrf.example.test'))->toBeNull();
    });
});