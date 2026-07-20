<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImport;

final class GraphV2StoredChunkReader implements GraphV2StoredChunkReaderContract
{
    public function __construct(private readonly GraphV2ChunkValidator $chunks) {}

    public function streamRecords(HadesGraphImport $import, int $index, $source, array $headers, array $descriptor): iterable
    {
        return $this->chunks->streamRecords($import, $index, $source, $headers, $descriptor);
    }
}
