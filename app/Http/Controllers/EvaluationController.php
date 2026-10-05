<?php

namespace App\Http\Controllers;

use App\Exceptions\EvaluationRuleException;
use App\Models\Evaluation;
use App\Models\Student;
use App\Services\EvaluationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class EvaluationController extends Controller
{
    public function __construct(private EvaluationService $evaluations) {}

    public function index(): Response
    {
        $evaluations = Evaluation::query()
            ->with([
                'student.user:id,name',
                'company:id,company_name',
                'supervisor:id,name',
            ])
            ->visibleTo(Auth::user())
            ->orderByDesc('evaluation_period_start')
            ->get();

        $students = Student::query()
            ->with('user:id,name')
            ->visibleTo(Auth::user())
            ->orderBy('created_at', 'desc')
            ->get(['id', 'user_id', 'student_number', 'supervisor_id']);

        return Inertia::render('SupervisorEvaluations/Index', [
            'evaluations' => $evaluations,
            'students' => $students,
            'criteria' => $this->evaluations->activeCriteria(),
        ]);
    }

    public function show(Evaluation $evaluation): Response
    {
        $this->authorize('view', $evaluation);

        $evaluation->load([
            'student.user:id,name',
            'company:id,company_name',
            'supervisor:id,name',
            'lockedBy:id,name',
            'responses.criteria',
        ]);

        return Inertia::render('SupervisorEvaluations/Show', [
            'evaluation' => $evaluation,
            'criteria' => $this->evaluations->activeCriteria(),
            'canEdit' => Auth::user()->can('update', $evaluation),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(EvaluationService::rules());

        $student = Student::findOrFail($validated['student_id']);

        $this->authorize('evaluate', $student);

        $evaluation = $this->evaluations->createDraft($student, $validated);

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation draft saved.');
    }

    public function update(Request $request, Evaluation $evaluation): RedirectResponse
    {
        $this->authorize('update', $evaluation);

        $validated = $request->validate(EvaluationService::rules(forStudentSwitch: false));

        $this->refuseLostRace(fn () => $this->evaluations->updateDraft($evaluation, $validated));

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation draft updated.');
    }

    public function submit(Evaluation $evaluation): RedirectResponse
    {
        $this->authorize('submit', $evaluation);

        $this->refuseLostRace(fn () => $this->evaluations->submit($evaluation));

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation submitted.');
    }

    public function lock(Evaluation $evaluation): RedirectResponse
    {
        $this->authorize('lock', $evaluation);

        $this->refuseLostRace(fn () => $this->evaluations->lock($evaluation, Auth::user()));

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation locked.');
    }

    public function reopen(Evaluation $evaluation): RedirectResponse
    {
        $this->authorize('reopen', $evaluation);

        $this->refuseLostRace(fn () => $this->evaluations->reopen($evaluation));

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation reopened for editing.');
    }

    /**
     * The policy has already checked the state; if another request changed
     * it in the meantime the service refuses under its row lock, and the
     * answer is the same 403 the policy gives for that state.
     */
    private function refuseLostRace(callable $change): void
    {
        try {
            $change();
        } catch (EvaluationRuleException $e) {
            abort(403, $e->getMessage());
        }
    }

    public function myFeedback(): Response
    {
        $student = Auth::user()->student;

        $evaluations = $student
            ? $this->evaluations->studentFeedback($student)
                ->with(['company:id,company_name', 'supervisor:id,name', 'responses.criteria'])
                ->orderByDesc('evaluation_period_start')
                ->get()
            : collect();

        return Inertia::render('SupervisorEvaluations/MyFeedback', [
            'evaluations' => $evaluations,
        ]);
    }
}
