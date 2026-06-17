<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'neo4j' => [
        'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
        'auth' => [
            env('NEO4J_USER', 'neo4j'),
            env('NEO4J_PASSWORD', 'graphify-sandbox'),
        ],
    ],

    'devboard' => [
        'graph_import_mode' => env('DEVBOARD_GRAPH_IMPORT_MODE', 'neo4j'),
        'plugin_rate_limit_per_minute' => env('DEVBOARD_PLUGIN_RATE_LIMIT_PER_MINUTE', 120),
        'plugin_light_rate_limit_per_minute' => env('DEVBOARD_PLUGIN_LIGHT_RATE_LIMIT_PER_MINUTE', env('DEVBOARD_PLUGIN_RATE_LIMIT_PER_MINUTE', 240)),
        'plugin_heavy_rate_limit_per_minute' => env('DEVBOARD_PLUGIN_HEAVY_RATE_LIMIT_PER_MINUTE', env('DEVBOARD_PLUGIN_RATE_LIMIT_PER_MINUTE', 30)),
        'artifact_retention_days' => env('DEVBOARD_ARTIFACT_RETENTION_DAYS', 90),
    ],

];
