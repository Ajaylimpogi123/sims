<?php

namespace App\Http\Controllers;

use App\Models\InternshipReport;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportReviewController extends Controller
{
    // Kept in sync with InternshipReportController::ATTACHMENT_DISK —
    // attachments live on the private `local` disk, never on `public`.
    private const ATTACHMENT_DISK = 'local';

    public function __construct(private NotificationService $notifications) {}

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

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $report->update([
            'status' => 'reviewed',
            'reviewer_comment' => $validated['comment'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        $this->notifications->reportReviewed($report);

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

        return Storage::disk(self::ATTACHMENT_DISK)->response(
            $report->attachment_path,
            $report->attachment_original_name,
        );
    }
}
