<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\EvaluationRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListEvaluationsRequest;
use App\Http\Resources\EvaluationResource;
use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\Student;
use App\Models\User;
use App\Policies\EvaluationPolicy;
use App\Services\EvaluationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Supervisor Evaluations (Coordinator: every evaluation, view only;
 * Supervisor: own students' evaluations, create / edit / submit drafts;
 * Administrator: every evaluation, all of that plus lock / reopen). The
 * list is the website's query (Evaluation::visibleTo); per record,
 * EvaluationPolicy (404 when not visible, 422 `evaluation_rule` for the
 * wrong state, 403 otherwise); writes go through EvaluationService, which
 * re-checks the state under a row lock. The routes' role: middleware keeps
 * Coordinators off every write and everyone but Administrators off
 * lock / reopen. No notifications (the website sends none).
 */
class EvaluationController extends Controller
{
    private const RELATIONS = [
        'student.user:id,name',
        'company:id,company_name',
        'supervisor:id,name',
        'lockedBy:id,name',
    ];

    public const RULE_CODE = 'evaluation_rule';

    public function __construct(private EvaluationService $evaluations) {}

    /**
     * Newest period first (the website's order), id as the tie-breaker,
     * cursor-paginated.
     */
    public function index(ListEvaluationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $search = $request->search();

        $page = Evaluation::query()
            ->with(self::RELATIONS)
            ->visibleTo($user)
            ->when($request->status(), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->studentId(), fn (Builder $query, int $id) => $query->where('student_id', $id))
            ->when($search !== null, fn (Builder $query) => $query->whereHas('student', function (Builder $student) use ($search) {
                $like = '%'.self::escapeLike($search).'%';

                $student->where(fn (Builder $match) => $match->where('student_number', 'like', $like)
                    ->orWhereHas('user', fn (Builder $account) => $account->where('name', 'like', $like)));
            }))
            ->orderByDesc('evaluation_period_start')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        return response()->json([
            'data' => EvaluationResource::collection($page->items())->resolve($request),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
            'can_create' => $user->hasRole(User::ROLE_SUPERVISOR, User::ROLE_ADMIN),
        ]);
    }

    public function show(Request $request, int $evaluation): JsonResponse
    {
        return response()->json([
            'evaluation' => $this->present($request, $this->visibleEvaluation($request, $evaluation)),
        ]);
    }

    /**
     * The rating form's criteria: active ones, grouped by category in the
     * website's order (category, then sort order).
     */
    public function criteria(): JsonResponse
    {
        $categories = $this->evaluations->activeCriteria()
            ->groupBy(fn (EvaluationCriteria $criterion) => (string) $criterion->category)
            ->map(fn ($criteria, string|int $category) => [
                'category' => (string) $category,
                'criteria' => $criteria->map(fn (EvaluationCriteria $criterion) => [
                    'id' => $criterion->id,
                    'label' => $criterion->label,
                    'description' => $criterion->description,
                    'sort_order' => (int) $criterion->sort_order,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return response()->json([
            'rating_max' => EvaluationService::RATING_MAX,
            'categories' => $categories,
        ]);
    }

    /**
     * The form's student picker: the students the caller may evaluate
     * (StudentPolicy::evaluate = own students for a Supervisor, everyone for
     * an Administrator), newest profile first like the website.
     */
    public function students(Request $request): JsonResponse
    {
        $students = Student::query()
            ->with(['user:id,name', 'company:id,company_name'])
            ->visibleTo($request->user())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Student $student) => [
                'id' => $student->id,
                'user_id' => $student->user_id,
                'name' => $student->user?->name,
                'student_number' => $student->student_number,
                'company' => $student->company ? ['id' => $student->company->id, 'name' => $student->company->company_name] : null,
            ]);

        return response()->json(['students' => $students]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(EvaluationService::rules(activeCriteriaOnly: true));

        // Unknown and not-yours look the same, so ids can't be probed.
        $student = Student::query()->find($validated['student_id']);

        if ($student === null || Gate::forUser($request->user())->denies('evaluate', $student)) {
            throw ValidationException::withMessages([
                'student_id' => __('validation.exists', ['attribute' => 'student id']),
            ]);
        }

        $evaluation = $this->evaluations->createDraft($student, $validated);

        return response()->json([
            'message' => 'Evaluation draft saved.',
            'evaluation' => $this->present($request, $evaluation),
        ], 201);
    }

    public function update(Request $request, int $evaluation): JsonResponse
    {
        $model = $this->visibleEvaluation($request, $evaluation);

        if ($refusal = $this->refusal($request, 'update', $model)) {
            return $refusal;
        }

        $validated = $request->validate(EvaluationService::rules(forStudentSwitch: false, activeCriteriaOnly: true));

        return $this->change($request, $model, 'Evaluation draft updated.',
            fn () => $this->evaluations->updateDraft($model, $validated));
    }

    public function submit(Request $request, int $evaluation): JsonResponse
    {
        $model = $this->visibleEvaluation($request, $evaluation);

        return $this->refusal($request, 'submit', $model)
            ?? $this->change($request, $model, 'Evaluation submitted.',
                fn () => $this->evaluations->submit($model));
    }

    public function lock(Request $request, int $evaluation): JsonResponse
    {
        $model = $this->visibleEvaluation($request, $evaluation);

        return $this->refusal($request, 'lock', $model)
            ?? $this->change($request, $model, 'Evaluation locked.',
                fn () => $this->evaluations->lock($model, $request->user()));
    }

    public function reopen(Request $request, int $evaluation): JsonResponse
    {
        $model = $this->visibleEvaluation($request, $evaluation);

        return $this->refusal($request, 'reopen', $model)
            ?? $this->change($request, $model, 'Evaluation reopened for editing.',
                fn () => $this->evaluations->reopen($model));
    }

    /**
     * An evaluation the caller may see; anything else (unknown id, another
     * supervisor's student) is a 404 so ids can't be probed.
     */
    private function visibleEvaluation(Request $request, int $id): Evaluation
    {
        $evaluation = Evaluation::query()->with('student')->find($id);

        abort_if($evaluation === null || Gate::forUser($request->user())->denies('view', $evaluation), 404);

        return $evaluation;
    }

    /**
     * EvaluationPolicy's answer for $ability: null when allowed, a 422
     * `evaluation_rule` for the wrong state, otherwise 403.
     */
    private function refusal(Request $request, string $ability, Evaluation $evaluation): ?JsonResponse
    {
        $decision = Gate::forUser($request->user())->inspect($ability, $evaluation);

        if ($decision->allowed()) {
            return null;
        }

        abort_unless($decision->code() === EvaluationPolicy::STATE_DENIAL_CODE, 403, 'Unauthorized access');

        return $this->ruleRefusal($request, $evaluation, (string) $decision->message());
    }

    /**
     * Run a service change; a state change that landed meanwhile (checked
     * under the row lock) is the same 422 as `refusal()`.
     */
    private function change(Request $request, Evaluation $evaluation, string $message, callable $change): JsonResponse
    {
        try {
            $change();
        } catch (EvaluationRuleException $e) {
            return $this->ruleRefusal($request, $evaluation, $e->getMessage());
        } catch (ModelNotFoundException) {
            // The student (and with it the evaluation) was deleted meanwhile.
            abort(404);
        }

        return response()->json([
            'message' => $message,
            'evaluation' => $this->present($request, $evaluation),
        ]);
    }

    private function ruleRefusal(Request $request, Evaluation $evaluation, string $message): JsonResponse
    {
        $fresh = Evaluation::query()->find($evaluation->id);
        abort_if($fresh === null, 404);

        return response()->json([
            'message' => $message,
            'code' => self::RULE_CODE,
            'evaluation' => $this->present($request, $fresh),
        ], 422);
    }

    private function present(Request $request, Evaluation $evaluation): array
    {
        $evaluation->load([...self::RELATIONS, 'responses.criteria']);

        return (new EvaluationResource($evaluation))->detail()->resolve($request);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
