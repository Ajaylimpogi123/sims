<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListFeedbackRequest;
use App\Http\Resources\FeedbackResource;
use App\Models\Student;
use App\Models\User;
use App\Services\EvaluationService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student "My Feedback" (role 1 only): the signed-in student's own
 * submitted or locked evaluations, read-only. The visibility rule is
 * EvaluationService::studentFeedback(), shared with the website.
 *
 * A draft, another student's evaluation or an unknown id is a 404 (ids
 * can't be probed).
 */
class FeedbackController extends Controller
{
    public function __construct(private EvaluationService $evaluations) {}

    /**
     * Newest period first (the website's order), cursor-paginated.
     */
    public function index(ListFeedbackRequest $request): JsonResponse
    {
        $page = $this->feedback($request)
            ->with(['company:id,company_name', 'supervisor:id,name'])
            ->orderByDesc('evaluation_period_start')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        return response()->json([
            'data' => FeedbackResource::collection($page->items())->resolve($request),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, int $evaluation): JsonResponse
    {
        $model = $this->feedback($request)
            ->with(['company:id,company_name', 'supervisor:id,name', 'responses.criteria'])
            ->find($evaluation) ?? abort(404);

        return response()->json([
            'evaluation' => (new FeedbackResource($model))->detail()->resolve($request),
        ]);
    }

    /**
     * The signed-in student's visible evaluations. A Student account without
     * a profile gets 409 with a stable code (same as attendance / reports).
     */
    private function feedback(Request $request): HasMany
    {
        /** @var User $user */
        $user = $request->user();

        $student = $user->student ?? throw new HttpResponseException(response()->json([
            'message' => AttendanceController::NO_PROFILE_MESSAGE,
            'code' => 'no_student_profile',
        ], 409));

        return $this->evaluations->studentFeedback($student);
    }
}
