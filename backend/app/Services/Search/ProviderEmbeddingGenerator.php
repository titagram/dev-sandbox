<?php

namespace App\Services\Search;

use App\Assistants\ProviderHttpClient;
use App\Contracts\EmbeddingGenerator;
use RuntimeException;

class ProviderEmbeddingGenerator implements EmbeddingGenerator
{
    public function __construct(private readonly ProviderHttpClient $httpClient) {}

    public function generate(string $input): array
    {
        $provider = trim((string) config('devboard.embeddings.provider'));
        $model = trim((string) config('devboard.embeddings.model'));
        $dimensions = max(1, (int) config('devboard.embeddings.dimensions', 1536));
        $timeout = max(1, (int) config('devboard.embeddings.timeout', 30));

        if ($provider === '' || $model === '') {
            throw new RuntimeException('Embedding provider and model must be configured.');
        }

        $response = $this->httpClient
            ->withToken((string) config('devboard.embeddings.api_key', ''))
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->post($this->embeddingsEndpoint($provider), [
                'model' => $model,
                'input' => $input,
                'dimensions' => $dimensions,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Embedding provider returned HTTP '.$response->status().'.');
        }

        $embedding = $response->json('data.0.embedding');
        if (! is_array($embedding)) {
            throw new RuntimeException('Embedding provider response did not include data.0.embedding.');
        }

        return array_values($embedding);
    }

    private function embeddingsEndpoint(string $provider): string
    {
        $baseUrl = rtrim((string) config('devboard.embeddings.base_url'), '/');

        if ($baseUrl === '') {
            $baseUrl = match ($provider) {
                'openai' => 'https://api.openai.com/v1',
                'openrouter' => 'https://openrouter.ai/api/v1',
                default => throw new RuntimeException('Embedding provider base URL must be configured.'),
            };
        }

        return preg_match('#/embeddings$#', $baseUrl) === 1 ? $baseUrl : $baseUrl.'/embeddings';
    }
}
