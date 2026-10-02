<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Student;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InternshipAssignmentController extends Controller
{
    /**
     * Fields that make up the "assignment" itself, as opposed to the
     * student's own profile fields — only changes to these are worth
     * notifying the student/supervisor about.
     */
    private const ASSIGNMENT_FIELDS = ['company_id', 'supervisor_id', 'internship_status'];

    public function __construct(private NotificationService $notifications) {}

    public function index(): Response
    {
        $students = Student::query()
            ->with(['user:id,name,email,status', 'company:id,company_name', 'supervisor:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        $companies = Company::query()
            ->withCount('students')
            ->with('supervisors:id,name,email')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'slots']);

        return Inertia::render('InternshipAssignment/Index', [
            'students' => $students,
            'companies' => $companies,
        ]);
    }

    /**
     * Single consolidated save covering the student's profile fields
     * (formerly StudentController::update) and their internship
     * assignment fields (company/supervisor/status/schedule) in one go,
     * now that Student Management has been merged into this page.
     */
    public function update(Request $request, Student $student): RedirectResponse
    {
        $request->merge([
            'company_id' => $request->company_id ?: null,
            'supervisor_id' => $request->supervisor_id ?: null,
        ]);

        // The company this student will end up with once this request is
        // applied — supervisor_id must be validated against *this*, not
        // the student's current (possibly different/about-to-change)
        // company_id, so changing both fields in the same request works.
        $resultingCompanyId = $request->input('company_id');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$student->user_id],
            'student_number' => ['required', 'string', 'max:50', 'unique:students,student_number,'.$student->id],
            'course' => ['required', 'string', 'max:255'],
            'section' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'supervisor_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('role_id', 3),
                function (string $attribute, mixed $value, \Closure $fail) use ($resultingCompanyId, $student) {
                    if (! $value) {
                        return;
                    }

                    // The company roster (company_supervisors pivot, "who's
                    // available to be assigned here") and a student's actual
                    // assignment (students.supervisor_id) are intentionally
                    // separate mechanisms and can legitimately drift apart —
                    // e.g. a supervisor detached from a company's roster
                    // while still actively assigned to a student there.
                    // Only re-validate roster membership when this request
                    // is actually changing the supervisor or company;
                    // otherwise an unrelated field edit (name, status, etc.)
                    // would be blocked by a pre-existing, already-accepted
                    // assignment every time the form round-trips it unchanged.
                    $supervisorUnchanged = (string) $value === (string) $student->supervisor_id;
                    $companyUnchanged = (string) $resultingCompanyId === (string) $student->company_id;

                    if ($supervisorUnchanged && $companyUnchanged) {
                        return;
                    }

                    if (! $resultingCompanyId) {
                        $fail('Assign a company before assigning a supervisor.');

                        return;
                    }

                    $onRoster = Company::whereKey($resultingCompanyId)
                        ->whereHas('supervisors', fn ($query) => $query->whereKey($value))
                        ->exists();

                    if (! $onRoster) {
                        $fail('This supervisor is not on the selected company\'s roster.');
                    }
                },
            ],
            'internship_status' => ['required', 'in:not_started,ongoing,completed'],
            'internship_schedule' => ['nullable', 'string', 'max:255'],
        ]);

        if (
            $validated['company_id']
            && (int) $validated['company_id'] !== (int) $student->company_id
        ) {
            $company = Company::findOrFail($validated['company_id']);

            $assignedCount = Student::where('company_id', $company->id)->count();

            if ($assignedCount >= $company->slots) {
                return back()
                    ->withErrors([
                        'company_id' => "{$company->company_name} has no available slots ({$assignedCount}/{$company->slots} filled).",
                    ])
                    ->withInput();
            }
        }

        $hasAssignmentChanges = collect($validated)
            ->only(self::ASSIGNMENT_FIELDS)
            ->contains(fn ($value, $key) => (string) $student->{$key} !== (string) $value);

        DB::transaction(function () use ($student, $validated) {
            $student->user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);

            $student->update([
                'student_number' => $validated['student_number'],
                'course' => $validated['course'],
                'section' => $validated['section'],
                'company_id' => $validated['company_id'],
                'supervisor_id' => $validated['supervisor_id'],
                'internship_status' => $validated['internship_status'],
                'internship_schedule' => $validated['internship_schedule'] ?? null,
            ]);
        });

        if ($hasAssignmentChanges) {
            $this->notifications->assignmentUpdated($student->fresh(['user', 'company', 'supervisor']));
        }

        return redirect()->route('internship-assignment.index')
            ->with('success', 'Student updated successfully.');
    }

    public function toggleStatus(Student $student): RedirectResponse
    {
        $newStatus = $student->user->status === 'active' ? 'inactive' : 'active';

        $student->user->update(['status' => $newStatus]);

        // Same as User Management: sign the mobile app out immediately.
        if ($newStatus === 'inactive') {
            $student->user->revokeApiTokens();
        }

        return redirect()->route('internship-assignment.index')
            ->with('success', 'Student status updated.');
    }
}
