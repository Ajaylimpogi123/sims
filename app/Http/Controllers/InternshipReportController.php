<?php

namespace App\Http\Controllers;

use App\Exceptions\ReportRuleException;
use App\Models\InternshipReport;
use App\Services\InternshipReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InternshipReportController extends Controller
{
    private const NO_PROFILE_MESSAGE = 'No student profile is linked to your account yet. Please contact your coordinator.';

    public function __construct(private InternshipReportService $reports) {}

    public function index(): Response|RedirectResponse
    {
        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $reports = $student->internshipReports()
            ->orderByDesc('period_start')
            ->get();

        return Inertia::render('InternshipReports/Index', [
            'reports' => $reports,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(InternshipReportService::rules());

        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $this->reports->create($student, $validated, $request->file('attachment'));

        return redirect()->route('reports.index')
            ->with('success', 'Report submitted for review.');
    }

    public function update(Request $request, InternshipReport $report): RedirectResponse
    {
        if (! Auth::user()->student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $this->authorize('update', $report);

        $validated = $request->validate(InternshipReportService::rules());

        try {
            $this->reports->update($report, $validated, $request->file('attachment'));
        } catch (ReportRuleException $e) {
            // Reviewed between the policy check above and the service's
            // locked re-check.
            return redirect()->route('reports.index')->with('error', $e->getMessage());
        }

        return redirect()->route('reports.index')
            ->with('success', 'Report updated.');
    }

    public function destroy(InternshipReport $report): RedirectResponse
    {
        if (! Auth::user()->student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $this->authorize('delete', $report);

        try {
            $this->reports->delete($report);
        } catch (ReportRuleException $e) {
            return redirect()->route('reports.index')->with('error', $e->getMessage());
        }

        return redirect()->route('reports.index')
            ->with('success', 'Report deleted.');
    }

    /**
     * Stream a report's attachment to its owning student only. Attachments
     * are never linked to directly from the frontend (which would bypass
     * this ownership check via a raw /storage/... URL).
     */
    public function downloadAttachment(InternshipReport $report): StreamedResponse
    {
        $this->authorize('view', $report);

        return $this->reports->attachmentResponse($report);
    }
}
