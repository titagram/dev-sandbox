<?php

namespace App\Support;

final class DevBoardUlid
{
    public const REGEX = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public static function isStrict(string $value): bool
    {
        return preg_match(self::REGEX, $value) === 1;
    }

    public static function assertStrict(string $value, string $field = 'id'): void
    {
        if (! self::isStrict($value)) {
            throw new \InvalidArgumentException("Unsafe {$field}.");
        }
    }
}
