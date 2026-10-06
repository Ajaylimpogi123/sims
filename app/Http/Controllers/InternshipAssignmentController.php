<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Student;
use App\Services\InternshipAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internship Assignment (Coordinator, Administrator). The rules live in
 * InternshipAssignmentService, shared with the mobile API.
 */
class InternshipAssignmentController extends Controller
{
    public function __construct(private InternshipAssignmentService $assignments) {}

    public function index(): Response
    {
        $students = Student::query()
            ->with(['user:id,name,email,status', 'company:id,company_name', 'supervisor:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        // `status` on companies and roster supervisors lets the form keep
        // inactive ones out of new picks (the save refuses them).
        $companies = Company::query()
            ->withCount('students')
            ->with('supervisors:id,name,email,status')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'slots', 'status']);

        return Inertia::render('InternshipAssignment/Index', [
            'students' => $students,
            'companies' => $companies,
        ]);
    }

    /**
     * Single consolidated save covering the student's profile fields and
     * their internship assignment (company/supervisor/status/schedule).
     */
    public function update(Request $request, Student $student): RedirectResponse
    {
        $this->assignments->prepare($request);

        $this->assignments->update($student, $request->validate($this->assignments->rules($student, $request)));

        return redirect()->route('internship-assignment.index')
            ->with('success', 'Student updated successfully.');
    }

    public function toggleStatus(Student $student): RedirectResponse
    {
        $this->assignments->setStatus($student);

        return redirect()->route('internship-assignment.index')
            ->with('success', 'Student status updated.');
    }
}
