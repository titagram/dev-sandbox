<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\Backup\BackupBundleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DashboardBackupController extends Controller
{
    use ChecksDashboardRoles;

    public function readiness(Request $request, BackupBundleService $backups): JsonResponse
    {
        $this->abortUnlessSystemOperator($request);

        return response()->json($backups->readiness());
    }

    public function export(Request $request, BackupBundleService $backups): JsonResponse
    {
        $this->abortUnlessSystemOperator($request);

        return response()->json($backups->export($request->user()), 201);
    }

    public function download(Request $request, string $backup, BackupBundleService $backups): StreamedResponse
    {
        $this->abortUnlessSystemOperator($request);

        $bundle = $backups->storedBundle($backup);

        return response()->streamDownload(
            fn () => print $bundle['content'],
            $bundle['filename'],
            [
                'Content-Type' => 'application/json',
                'X-Content-Type-Options' => 'nosniff',
                'X-DevBoard-Backup-SHA256' => $bundle['sha256'],
            ],
        );
    }

    public function validateBundle(Request $request, BackupBundleService $backups): JsonResponse
    {
        $this->abortUnlessSystemOperator($request);

        $validated = $request->validate([
            'bundle' => ['required', 'file', 'max:102400'],
        ]);

        $content = $validated['bundle']->get();
        abort_unless(is_string($content), 422, 'Unable to read uploaded backup bundle.');

        return response()->json($backups->validateDryRun($content, $request->user()));
    }

    private function abortUnlessSystemOperator(Request $request): void
    {
        abort_unless(
            $this->userHasRole($request->user(), 'Admin')
            || $this->userHasRole($request->user(), 'Sysadmin'),
            403,
        );
    }
}
