<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactDownloadController extends Controller
{
    use ChecksDashboardRoles;

    public function __invoke(Request $request, string $run, string $artifact): StreamedResponse
    {
        abort_unless($this->canDownloadArtifacts($request), 403);

        $artifactRow = DB::table('artifacts')
            ->where('id', $artifact)
            ->where('run_id', $run)
            ->first();

        abort_unless($artifactRow, 404);
        abort_if(! in_array($artifactRow->status, ['validated', 'imported'], true), 409, 'Artifact is not downloadable yet.');
        abort_unless(Storage::disk('local')->exists($artifactRow->storage_path), 404);

        $contents = Storage::disk('local')->get($artifactRow->storage_path);

        return Response::streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            basename($artifactRow->storage_path),
            ['Content-Type' => $artifactRow->mime_type ?? 'application/octet-stream'],
        );
    }

    private function canDownloadArtifacts(Request $request): bool
    {
        $user = $request->user();

        return $this->userHasRole($user, 'PM')
            || $this->userHasRole($user, 'Developer')
            || $this->userHasRole($user, 'Sysadmin')
            || $this->userHasRole($user, 'Admin');
    }
}
