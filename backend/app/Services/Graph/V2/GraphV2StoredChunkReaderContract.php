<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImport;

interface GraphV2StoredChunkReaderContract
{
    /** @param resource $source */
    public function streamRecords(HadesGraphImport $import, int $index, $source, array $headers, array $descriptor): iterable;
}
