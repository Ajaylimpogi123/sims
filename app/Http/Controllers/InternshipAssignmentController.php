<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use App\Services\InternshipAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internship Assignment (Coordinator, Administrator). The rules live in
 * InternshipAssignmentService, shared with the mobile API.
 *
 * `?needs_supervisor=1` (the dashboard's "need a supervisor" link) only
 * seeds the page's client-side filter: `students` is always the full list.
 */
class InternshipAssignmentController extends Controller
{
    public function __construct(private InternshipAssignmentService $assignments) {}

    public function index(Request $request): Response
    {
        $students = Student::query()
            ->with(['user:id,name,email,status', 'company:id,company_name', 'supervisor:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Adds supervisor_on_roster and needs_supervisor to each student.
        $this->assignments->loadRosterFlags($students);

        // `status` on companies and roster supervisors lets the form keep
        // inactive ones out of new picks (the save refuses them). Roster
        // accounts no longer holding the Supervisor role are left out (a
        // role change leaves the roster row behind; the save refuses them as
        // a new pick); the page shows a student's current one from the
        // student's own supervisor fields.
        $companies = Company::query()
            ->withCount('students')
            ->with(['supervisors' => fn ($q) => $q
                ->select('users.id', 'users.name', 'users.email', 'users.status')
                ->where('users.role_id', User::ROLE_SUPERVISOR)])
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'slots', 'status']);

        return Inertia::render('InternshipAssignment/Index', [
            'students' => $students,
            'companies' => $companies,
            'filters' => [
                'needs_supervisor' => $request->boolean('needs_supervisor'),
            ],
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

        return $this->backToIndex($request)->with('success', 'Student updated successfully.');
    }

    public function toggleStatus(Request $request, Student $student): RedirectResponse
    {
        $this->assignments->setStatus($student);

        return $this->backToIndex($request)->with('success', 'Student status updated.');
    }

    /**
     * Back to the list, keeping the "needs supervisor" filter when the page
     * sends `needs_supervisor` along with the save.
     */
    private function backToIndex(Request $request): RedirectResponse
    {
        return redirect()->route('internship-assignment.index',
            $request->boolean('needs_supervisor') ? ['needs_supervisor' => 1] : []);
    }
}
