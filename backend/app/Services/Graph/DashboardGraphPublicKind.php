<?php

namespace App\Services\Graph;

final class DashboardGraphPublicKind
{
    /** @var list<string> */
    private const VOCABULARY = [
        'method', 'class', 'method_reference', 'external_class', 'table',
        'route', 'trait', 'external_symbol', 'interface', 'file',
    ];

    public function map(mixed $value): string
    {
        return is_string($value) && in_array($value, self::VOCABULARY, true)
            ? $value
            : 'unknown';
    }
}
