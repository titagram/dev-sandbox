<?php

namespace App\Quality\Route;

use Symfony\Component\Yaml\Yaml;

final class RouteRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $routesByMethodAndUri;

    public function __construct(?string $path = null)
    {
        $path ??= base_path('config/quality/route_registry.yaml');
        $this->routesByMethodAndUri = $this->load($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $method, string $uri): ?array
    {
        return $this->routesByMethodAndUri[$this->key($method, $uri)] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $data = Yaml::parseFile($path);
        $routes = is_array($data) ? ($data['routes'] ?? []) : [];
        $indexed = [];

        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }

            $method = (string) ($route['method'] ?? '');
            $uri = (string) ($route['uri'] ?? '');

            if ($method === '' || $uri === '') {
                continue;
            }

            $indexed[$this->key($method, $uri)] = $route;
        }

        return $indexed;
    }

    private function key(string $method, string $uri): string
    {
        return strtoupper($method).' '.$this->normalizeUri($uri);
    }

    private function normalizeUri(string $uri): string
    {
        $normalized = trim($uri, '/');

        return $normalized === '' ? '/' : $normalized;
    }
}

