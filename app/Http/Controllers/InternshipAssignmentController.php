<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InternshipAssignmentController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(): Response
    {
        $students = Student::query()
            ->with(['user:id,name,email,status', 'company:id,company_name', 'supervisor:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        $companies = Company::query()
            ->withCount('students')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'slots']);

        $supervisors = User::query()
            ->where('role_id', 3)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('InternshipAssignment/Index', [
            'students' => $students,
            'companies' => $companies,
            'supervisors' => $supervisors,
        ]);
    }

    public function assign(Request $request, Student $student): RedirectResponse
    {
        $request->merge([
            'company_id' => $request->company_id ?: null,
            'supervisor_id' => $request->supervisor_id ?: null,
        ]);

        $validated = $request->validate([
            'company_id' => ['nullable', 'exists:companies,id'],
            'supervisor_id' => ['nullable', Rule::exists('users', 'id')->where('role_id', 3)],
            'internship_status' => ['required', 'in:not_started,ongoing,completed'],
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

        $hasChanges = collect($validated)->contains(
            fn ($value, $key) => (string) $student->{$key} !== (string) $value,
        );

        $student->update($validated);

        if ($hasChanges) {
            $this->notifications->assignmentUpdated($student->fresh(['user', 'company', 'supervisor']));
        }

        return redirect()->route('internship-assignment.index')
            ->with('success', 'Internship assignment updated successfully.');
    }
}
