<?php

namespace App\Http\Controllers;

use App\Models\InternshipReport;
use App\Models\Student;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InternshipReportController extends Controller
{
    private const NO_PROFILE_MESSAGE = 'No student profile is linked to your account yet. Please contact your coordinator.';

    public function __construct(private NotificationService $notifications) {}

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
        $validated = $this->validateReport($request);

        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        $this->guardAgainstDuplicate($student, $validated);

        if ($request->hasFile('attachment')) {
            $validated['attachment_path'] = $request->file('attachment')->store('report-attachments', 'public');
            $validated['attachment_original_name'] = $request->file('attachment')->getClientOriginalName();
        }

        $validated['status'] = 'pending';

        $report = $student->internshipReports()->create($validated);

        $this->notifications->reportSubmitted($report);

        return redirect()->route('reports.index')
            ->with('success', 'Report submitted for review.');
    }

    public function update(Request $request, InternshipReport $report): RedirectResponse
    {
        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        abort_unless(
            $report->student_id === $student->id && $report->status === 'pending',
            403,
        );

        $validated = $this->validateReport($request);

        $this->guardAgainstDuplicate($student, $validated, $report->id);

        if ($request->hasFile('attachment')) {
            if ($report->attachment_path) {
                Storage::disk('public')->delete($report->attachment_path);
            }

            $validated['attachment_path'] = $request->file('attachment')->store('report-attachments', 'public');
            $validated['attachment_original_name'] = $request->file('attachment')->getClientOriginalName();
        }

        $report->update($validated);

        return redirect()->route('reports.index')
            ->with('success', 'Report updated.');
    }

    public function destroy(InternshipReport $report): RedirectResponse
    {
        $student = Auth::user()->student;

        if (! $student) {
            return redirect()->route('dashboard')
                ->with('error', self::NO_PROFILE_MESSAGE);
        }

        abort_unless(
            $report->student_id === $student->id && $report->status === 'pending',
            403,
        );

        if ($report->attachment_path) {
            Storage::disk('public')->delete($report->attachment_path);
        }

        $report->delete();

        return redirect()->route('reports.index')
            ->with('success', 'Report deleted.');
    }

    private function validateReport(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'in:daily,weekly'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'content' => ['required', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function guardAgainstDuplicate(Student $student, array $validated, ?int $ignoreReportId = null): void
    {
        $duplicateExists = $student->internshipReports()
            ->where('period_start', $validated['period_start'])
            ->where('type', $validated['type'])
            ->when($ignoreReportId, fn ($query) => $query->where('id', '!=', $ignoreReportId))
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'period_start' => "You've already submitted a {$validated['type']} report for this period.",
            ]);
        }
    }
}
