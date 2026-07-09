<?php

return [
    'artifacts' => [
        'disk' => env('DEVBOARD_ARTIFACT_DISK', 'local'),
        'max_chunk_bytes' => (int) env('DEVBOARD_MAX_ARTIFACT_CHUNK_BYTES', 8 * 1024 * 1024),
        'max_chunks' => (int) env('DEVBOARD_MAX_ARTIFACT_CHUNKS', 512),
        'max_artifact_bytes' => (int) env('DEVBOARD_MAX_ARTIFACT_BYTES', 512 * 1024 * 1024),
    ],
];
