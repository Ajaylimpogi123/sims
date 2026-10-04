<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AttendanceRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAttendanceRequest;
use App\Http\Resources\ApprovalResource;
use App\Models\Attendance;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pending Approvals (Supervisor = own students, Administrator = all; the
 * route's role:3,4 keeps Coordinators and Students out). The list is the
 * website's AttendanceApprovalController list; decisions go through the
 * same AttendancePolicy::review and AttendanceService methods. A record the
 * caller may not review is a 404 so ids can't be probed.
 */
class ApprovalController extends Controller
{
    private const RELATIONS = ['student.user:id,name', 'student.company:id,company_name'];

    public function __construct(private AttendanceService $attendance) {}

    /**
     * Newest date first, cursor-paginated like the student's history.
     */
    public function index(ListAttendanceRequest $request): JsonResponse
    {
        $page = $this->pending($request->user())
            ->with(self::RELATIONS)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        return response()->json([
            'data' => ApprovalResource::collection($page->items())->resolve($request),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->pending($request->user())->count()]);
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorizeReview($request, $attendance);

        return response()->json(['data' => $this->item($request, $attendance)]);
    }

    public function approveTimeIn(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorizeReview($request, $attendance);

        return $this->decide($request, $attendance, 'Time-in approved.', fn () => $this->attendance->approveTimeIn($attendance));
    }

    public function rejectTimeIn(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorizeReview($request, $attendance);
        $validated = $request->validate(AttendanceService::rejectionRules());

        return $this->decide($request, $attendance, 'Time-in rejected.', fn () => $this->attendance->rejectTimeIn($attendance, $validated['reason'] ?? null));
    }

    public function approveTimeOut(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorizeReview($request, $attendance);

        return $this->decide($request, $attendance, 'Time-out approved.', fn () => $this->attendance->approveTimeOut($attendance));
    }

    public function rejectTimeOut(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorizeReview($request, $attendance);
        $validated = $request->validate(AttendanceService::rejectionRules());

        return $this->decide($request, $attendance, 'Time-out rejected.', fn () => $this->attendance->rejectTimeOut($attendance, $validated['reason'] ?? null));
    }

    /**
     * The website's Pending Approvals query for this reviewer.
     */
    private function pending(User $user): Builder
    {
        return Attendance::query()->awaitingReview()->visibleTo($user);
    }

    private function authorizeReview(Request $request, Attendance $attendance): void
    {
        abort_if(Gate::forUser($request->user())->denies('review', $attendance), 404);
    }

    /**
     * Run one decision and answer with the fresh row either way. A leg that
     * is no longer pending is a 422 with `code: attendance_rule`.
     */
    private function decide(Request $request, Attendance $attendance, string $success, callable $action): JsonResponse
    {
        try {
            $action();
        } catch (AttendanceRuleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'attendance_rule',
                'attendance' => $this->item($request, $attendance->fresh()),
            ], 422);
        }

        return response()->json([
            'message' => $success,
            'attendance' => $this->item($request, $attendance->fresh()),
        ]);
    }

    private function item(Request $request, Attendance $attendance): array
    {
        return (new ApprovalResource($attendance->load(self::RELATIONS)))->resolve($request);
    }
}
