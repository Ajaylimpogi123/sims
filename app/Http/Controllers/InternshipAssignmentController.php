<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\InternshipAssignmentService;
use App\Support\TermContext;
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
        // The term picked in the header switcher (current by default).
        $students = Student::query()
            ->with(['user:id,name,email,status', 'company:id,company_name', 'supervisor:id,name,status', 'term'])
            ->inTerm(TermContext::id($request))
            ->orderBy('created_at', 'desc')
            ->get();

        // Adds needs_supervisor and supervisor_issue to each student.
        $this->assignments->loadSupervisorFlags($students);

        // `status` on companies lets the form keep inactive ones out of new
        // picks (the save refuses them); `supervisor` is whom a student
        // placed there gets, `supervisor_issue` why that one isn't a
        // working supervisor; students_count is the current term's.
        $companies = $this->assignments->companyOptions();

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
     * their internship assignment (company/status/schedule/required hours).
     * The supervisor follows the company.
     */
    public function update(Request $request, Student $student): RedirectResponse
    {
        $this->assignments->prepare($request);

        $this->assignments->update($student, $request->validate($this->assignments->rules($student)));

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
