<?php

namespace App\Quality\Route;

final class RouteClassification
{
    public const SAFE_READ = 'SAFE_READ';

    public const MUTATING = 'MUTATING';

    public const DESTRUCTIVE = 'DESTRUCTIVE';

    public const EXTERNAL_SIDE_EFFECT = 'EXTERNAL_SIDE_EFFECT';

    public const AUTH = 'AUTH';

    public const UNKNOWN = 'UNKNOWN';
}
