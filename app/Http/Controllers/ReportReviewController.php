<?php

namespace App\Http\Controllers;

use App\Models\InternshipReport;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ReportReviewController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(): Response
    {
        $query = InternshipReport::query()->with('student.user:id,name');

        if (Auth::user()->role_id === 3) {
            $query->whereHas('student', fn ($q) => $q->where('supervisor_id', Auth::id()));
        }

        $reports = $query
            ->orderByRaw("status = 'pending' desc")
            ->orderByDesc('period_start')
            ->get();

        return Inertia::render('ReportReviews/Index', [
            'reports' => $reports,
        ]);
    }

    public function review(Request $request, InternshipReport $report): RedirectResponse
    {
        if (Auth::user()->role_id === 3) {
            abort_unless($report->student->supervisor_id === Auth::id(), 403);
        }

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
}
