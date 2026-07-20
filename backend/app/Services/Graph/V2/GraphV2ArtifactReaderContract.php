<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImport;

interface GraphV2ArtifactReaderContract
{
    /** @return iterable<array{kind:string,index:int,records:list<\stdClass>}> */
    public function batches(HadesGraphImport $import): iterable;
}
