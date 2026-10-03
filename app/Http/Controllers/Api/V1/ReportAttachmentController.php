<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InternshipReport;
use App\Services\InternshipReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a report's attachment from the private disk to the app (any
 * role), scoped by InternshipReportPolicy::view: Student = own reports,
 * Supervisor = own students', Coordinator/Admin = all. A report the user
 * may not see is a 404 (unlike the website's 403) so ids can't be probed.
 */
class ReportAttachmentController extends Controller
{
    public function __invoke(Request $request, int $report): StreamedResponse
    {
        $model = InternshipReport::query()->find($report);

        abort_if($model === null || Gate::forUser($request->user())->denies('view', $model), 404);

        $path = $model->attachment_path;
        $disk = Storage::disk(InternshipReportService::ATTACHMENT_DISK);

        abort_unless($path && $disk->exists($path), 404);

        // Content-Type is detected from the stored bytes (only jpg / png /
        // pdf pass validation); nosniff + a sandbox CSP keep a browser from
        // treating it as anything active. The ?v= URL fingerprint changes
        // with the file, so the app may cache it privately.
        return $disk->response($path, $model->attachment_original_name ?: basename($path), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
