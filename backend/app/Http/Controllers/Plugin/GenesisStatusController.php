<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class GenesisStatusController extends Controller
{
    public function __invoke(string $genesisImport): JsonResponse
    {
        $import = DB::table('genesis_imports')->where('id', $genesisImport)->first();
        abort_unless($import, 404);

        return response()->json([
            'import_id' => $import->id,
            'status' => $import->status,
            'snapshot_id' => $import->snapshot_id,
        ]);
    }
}
