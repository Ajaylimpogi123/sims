<?php

namespace App\Http\Controllers;

use App\Exceptions\ReportRuleException;
use App\Models\InternshipReport;
use App\Services\InternshipReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportReviewController extends Controller
{
    public function __construct(private InternshipReportService $reports) {}

    public function index(): Response
    {
        $reports = InternshipReport::query()
            ->with('student.user:id,name')
            ->visibleTo(Auth::user())
            ->orderByRaw("status = 'pending' desc")
            ->orderByDesc('period_start')
            ->get();

        return Inertia::render('ReportReviews/Index', [
            'reports' => $reports,
        ]);
    }

    public function review(Request $request, InternshipReport $report): RedirectResponse
    {
        $this->authorize('review', $report);

        $validated = $request->validate(InternshipReportService::reviewRules());

        try {
            $this->reports->review($report, Auth::user(), $validated['comment'] ?? null);
        } catch (ReportRuleException $e) {
            // Already reviewed (by someone else, or a stale page / replay).
            return redirect()->route('report-reviews.index')->with('error', $e->getMessage());
        } catch (ModelNotFoundException) {
            // The student deleted it after this request loaded it.
            return redirect()->route('report-reviews.index')->with('error', 'This report no longer exists.');
        }

        return redirect()->route('report-reviews.index')
            ->with('success', 'Report reviewed.');
    }

    /**
     * Stream a report's attachment to an authorized reviewer only (a
     * Supervisor scoped to their own students, or a Coordinator/Admin with
     * full-program oversight), mirroring the same scoping as index().
     * Attachments are never linked to directly from the frontend (which
     * would bypass this check via a raw /storage/... URL).
     */
    public function downloadAttachment(InternshipReport $report): StreamedResponse
    {
        $this->authorize('view', $report);

        abort_unless($report->attachment_path, 404);

        return Storage::disk(InternshipReportService::ATTACHMENT_DISK)->response(
            $report->attachment_path,
            $report->attachment_original_name,
        );
    }
}
