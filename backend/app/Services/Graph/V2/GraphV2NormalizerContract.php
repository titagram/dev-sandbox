<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImport;
use Closure;

interface GraphV2NormalizerContract
{
    /** @param iterable<array{kind:string,index:int,records:list<\stdClass>}> $batches */
    public function passOne(HadesGraphImport $import, iterable $batches, Closure $heartbeat): void;

    /** @param iterable<array{kind:string,index:int,records:list<\stdClass>}> $batches @return array{artifact_graph_version:string} */
    public function passTwo(HadesGraphImport $import, iterable $batches, Closure $heartbeat): array;
}
