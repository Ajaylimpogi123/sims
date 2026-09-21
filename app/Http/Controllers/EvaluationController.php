<?php

namespace App\Http\Controllers;

use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EvaluationController extends Controller
{
    private const SUPERVISOR_ROLE_ID = 3;

    private const ADMIN_ROLE_ID = 4;

    public function index(): Response
    {
        $evaluations = Evaluation::query()
            ->with([
                'student.user:id,name',
                'company:id,company_name',
                'supervisor:id,name',
            ])
            ->when(
                Auth::user()->role_id === self::SUPERVISOR_ROLE_ID,
                fn ($query) => $query->whereHas(
                    'student',
                    fn ($q) => $q->where('supervisor_id', Auth::id()),
                ),
            )
            ->orderByDesc('evaluation_period_start')
            ->get();

        $students = Student::query()
            ->with('user:id,name')
            ->when(
                Auth::user()->role_id === self::SUPERVISOR_ROLE_ID,
                fn ($query) => $query->where('supervisor_id', Auth::id()),
            )
            ->orderBy('created_at', 'desc')
            ->get(['id', 'user_id', 'student_number', 'supervisor_id']);

        return Inertia::render('SupervisorEvaluations/Index', [
            'evaluations' => $evaluations,
            'students' => $students,
            'criteria' => $this->activeCriteria(),
        ]);
    }

    public function show(Evaluation $evaluation): Response
    {
        $this->authorizeView($evaluation);

        $evaluation->load([
            'student.user:id,name',
            'company:id,company_name',
            'supervisor:id,name',
            'lockedBy:id,name',
            'responses.criteria',
        ]);

        return Inertia::render('SupervisorEvaluations/Show', [
            'evaluation' => $evaluation,
            'criteria' => $this->activeCriteria(),
            'canEdit' => $this->canMutate($evaluation) && $evaluation->status === 'draft',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateEvaluation($request);

        $student = Student::findOrFail($validated['student_id']);

        $this->authorizeSupervisedStudent($student);

        $evaluation = DB::transaction(function () use ($validated, $student) {
            $evaluation = Evaluation::create([
                'student_id' => $student->id,
                'company_id' => $student->company_id,
                'supervisor_id' => $student->supervisor_id,
                'evaluation_period_start' => $validated['evaluation_period_start'],
                'evaluation_period_end' => $validated['evaluation_period_end'],
                'strengths' => $validated['strengths'] ?? null,
                'areas_for_improvement' => $validated['areas_for_improvement'] ?? null,
                'recommendations' => $validated['recommendations'] ?? null,
                'supervisor_remarks' => $validated['supervisor_remarks'] ?? null,
                'status' => 'draft',
            ]);

            $this->syncResponses($evaluation, $validated['responses'] ?? []);
            $this->refreshOverallRating($evaluation);

            return $evaluation;
        });

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation draft saved.');
    }

    public function update(Request $request, Evaluation $evaluation): RedirectResponse
    {
        $this->authorizeMutation($evaluation);

        abort_unless($evaluation->status === 'draft', 403, 'Only draft evaluations can be edited.');

        $validated = $this->validateEvaluation($request, forStudentSwitch: false);

        DB::transaction(function () use ($evaluation, $validated) {
            $evaluation->update([
                'evaluation_period_start' => $validated['evaluation_period_start'],
                'evaluation_period_end' => $validated['evaluation_period_end'],
                'strengths' => $validated['strengths'] ?? null,
                'areas_for_improvement' => $validated['areas_for_improvement'] ?? null,
                'recommendations' => $validated['recommendations'] ?? null,
                'supervisor_remarks' => $validated['supervisor_remarks'] ?? null,
            ]);

            $this->syncResponses($evaluation, $validated['responses'] ?? []);
            $this->refreshOverallRating($evaluation);
        });

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation draft updated.');
    }

    public function submit(Evaluation $evaluation): RedirectResponse
    {
        $this->authorizeMutation($evaluation);

        abort_unless($evaluation->status === 'draft', 403, 'Only draft evaluations can be submitted.');

        $activeCriteriaIds = EvaluationCriteria::query()->where('is_active', true)->pluck('id');
        $respondedCriteriaIds = $evaluation->responses()->pluck('evaluation_criteria_id');

        if ($activeCriteriaIds->diff($respondedCriteriaIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'responses' => 'Every evaluation criterion must be rated before submitting.',
            ]);
        }

        $this->refreshOverallRating($evaluation);

        $evaluation->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation submitted.');
    }

    public function lock(Evaluation $evaluation): RedirectResponse
    {
        abort_unless($evaluation->status === 'submitted', 403, 'Only submitted evaluations can be locked.');

        $evaluation->update([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => Auth::id(),
        ]);

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation locked.');
    }

    public function reopen(Evaluation $evaluation): RedirectResponse
    {
        abort_unless(in_array($evaluation->status, ['submitted', 'locked'], true), 403, 'Only submitted or locked evaluations can be reopened.');

        $evaluation->update([
            'status' => 'draft',
            'submitted_at' => null,
            'locked_at' => null,
            'locked_by' => null,
        ]);

        return redirect()->route('supervisor-evaluations.show', $evaluation)
            ->with('success', 'Evaluation reopened for editing.');
    }

    public function myFeedback(): Response
    {
        $student = Auth::user()->student;

        $evaluations = $student
            ? $student->evaluations()
                ->with(['company:id,company_name', 'supervisor:id,name', 'responses.criteria'])
                ->whereIn('status', ['submitted', 'locked'])
                ->orderByDesc('evaluation_period_start')
                ->get()
            : collect();

        return Inertia::render('SupervisorEvaluations/MyFeedback', [
            'evaluations' => $evaluations,
        ]);
    }

    private function activeCriteria()
    {
        return EvaluationCriteria::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();
    }

    private function validateEvaluation(Request $request, bool $forStudentSwitch = true): array
    {
        $rules = [
            'evaluation_period_start' => ['required', 'date'],
            'evaluation_period_end' => ['required', 'date', 'after_or_equal:evaluation_period_start'],
            'strengths' => ['nullable', 'string', 'max:5000'],
            'areas_for_improvement' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'supervisor_remarks' => ['nullable', 'string', 'max:5000'],
            'responses' => ['nullable', 'array'],
            'responses.*.evaluation_criteria_id' => ['required', 'integer', 'exists:evaluation_criteria,id', 'distinct'],
            'responses.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'responses.*.comment' => ['nullable', 'string', 'max:2000'],
        ];

        if ($forStudentSwitch) {
            $rules['student_id'] = ['required', 'exists:students,id'];
        }

        return $request->validate($rules);
    }

    private function syncResponses(Evaluation $evaluation, array $responses): void
    {
        $evaluation->responses()->delete();

        foreach ($responses as $response) {
            $evaluation->responses()->create([
                'evaluation_criteria_id' => $response['evaluation_criteria_id'],
                'rating' => $response['rating'],
                'comment' => $response['comment'] ?? null,
            ]);
        }
    }

    private function refreshOverallRating(Evaluation $evaluation): void
    {
        $average = $evaluation->responses()->avg('rating');

        $evaluation->update([
            'overall_rating' => $average !== null ? round($average, 2) : null,
        ]);
    }

    private function authorizeSupervisedStudent(Student $student): void
    {
        if (Auth::user()->role_id === self::SUPERVISOR_ROLE_ID) {
            abort_unless($student->supervisor_id === Auth::id(), 403);
        }
    }

    private function authorizeView(Evaluation $evaluation): void
    {
        if (Auth::user()->role_id === self::SUPERVISOR_ROLE_ID) {
            abort_unless($evaluation->student->supervisor_id === Auth::id(), 403);
        }
    }

    private function authorizeMutation(Evaluation $evaluation): void
    {
        if (Auth::user()->role_id === self::SUPERVISOR_ROLE_ID) {
            abort_unless($evaluation->student->supervisor_id === Auth::id(), 403);
        }
    }

    private function canMutate(Evaluation $evaluation): bool
    {
        if (Auth::user()->role_id === self::SUPERVISOR_ROLE_ID) {
            return $evaluation->student->supervisor_id === Auth::id();
        }

        return in_array(Auth::user()->role_id, [self::SUPERVISOR_ROLE_ID, self::ADMIN_ROLE_ID], true);
    }
}
