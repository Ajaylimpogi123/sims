<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InternshipReport;
use App\Services\InternshipReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a report's attachment from the private disk to the app (any
 * role), scoped by InternshipReportPolicy::view: Student = own reports,
 * Supervisor = own students', Coordinator/Admin = all. A report the user
 * may not see is a 404 (unlike the website's 403) so ids can't be probed.
 */
class ReportAttachmentController extends Controller
{
    public function __construct(private InternshipReportService $reports) {}

    public function __invoke(Request $request, int $report): StreamedResponse
    {
        $model = InternshipReport::query()->find($report);

        abort_if($model === null || Gate::forUser($request->user())->denies('view', $model), 404);

        // The ?v= URL fingerprint changes with the file, so the app may
        // cache it privately.
        return $this->reports->attachmentResponse($model, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
