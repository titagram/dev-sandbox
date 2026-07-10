<?php

return [
    'artifacts' => [
        'disk' => env('DEVBOARD_ARTIFACT_DISK', 'local'),
        'max_chunk_bytes' => (int) env('DEVBOARD_MAX_ARTIFACT_CHUNK_BYTES', 8 * 1024 * 1024),
        'max_chunks' => (int) env('DEVBOARD_MAX_ARTIFACT_CHUNKS', 512),
        'max_artifact_bytes' => (int) env('DEVBOARD_MAX_ARTIFACT_BYTES', 512 * 1024 * 1024),
        'incomplete_upload_ttl_hours' => (int) env('DEVBOARD_INCOMPLETE_UPLOAD_TTL_HOURS', 24),
    ],
    'embeddings' => [
        'enabled' => filter_var(env('DEVBOARD_EMBEDDINGS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'provider' => env('DEVBOARD_EMBEDDINGS_PROVIDER', ''),
        'model' => env('DEVBOARD_EMBEDDINGS_MODEL', ''),
        'dimensions' => (int) env('DEVBOARD_EMBEDDINGS_DIMENSIONS', 1536),
        'timeout' => (int) env('DEVBOARD_EMBEDDINGS_TIMEOUT', 30),
        'base_url' => env('DEVBOARD_EMBEDDINGS_BASE_URL', ''),
        'api_key' => env('DEVBOARD_EMBEDDINGS_API_KEY', ''),
    ],
    'vector_score_weight' => (int) env('DEVBOARD_VECTOR_SCORE_WEIGHT', 20),
];
