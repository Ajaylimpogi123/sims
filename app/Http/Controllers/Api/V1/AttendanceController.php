<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AttendanceRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student self-service attendance (role 1 only, own record only): today's
 * state with the actions currently allowed, history, and the three
 * time-in / time-out legs. Rules, evidence storage and notifications all
 * live in AttendanceService, shared with the website's AttendanceController.
 */
class AttendanceController extends Controller
{
    public const NO_PROFILE_MESSAGE = 'No student profile is linked to your account yet. Please contact your coordinator.';

    public function __construct(private AttendanceService $attendance) {}

    public function today(Request $request): JsonResponse
    {
        return response()->json($this->todayState($request, $this->student($request)));
    }

    /**
     * Newest date first, cursor-paginated like notifications.
     */
    public function index(ListAttendanceRequest $request): JsonResponse
    {
        $page = $this->student($request)->attendances()
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        return response()->json([
            'data' => AttendanceResource::collection($page->items())->resolve($request),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function timeIn(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $validated = $this->validateCapture($request, AttendanceService::captureRules());

        return $this->submit($request, $student, 'Time-in submitted for approval.', fn () => $this->attendance->timeIn(
            $student, $request->user(), $request->file('photo'), $validated
        ));
    }

    public function timeOut(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $validated = $this->validateCapture($request, AttendanceService::captureRules());

        return $this->submit($request, $student, 'Time-out submitted for approval.', fn () => $this->attendance->timeOut(
            $student, $request->user(), $request->file('photo'), $validated
        ));
    }

    public function emergencyTimeOut(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $validated = $this->validateCapture($request, AttendanceService::emergencyTimeOutRules());

        return $this->submit($request, $student, 'Emergency time-out submitted for approval.', fn () => $this->attendance->emergencyTimeOut(
            $student, $request->user(), $request->file('photo'), $validated
        ));
    }

    /**
     * The website's capture rules plus the app-only `mocked` flag
     * (multipart sends it as text). Omitted / empty = null (unknown).
     */
    private function validateCapture(Request $request, array $rules): array
    {
        $validated = $request->validate([
            ...$rules,
            'mocked' => ['nullable', 'in:0,1,true,false'],
        ]);

        $mocked = $validated['mocked'] ?? null;
        $validated['mocked'] = $mocked === null ? null : in_array((string) $mocked, ['1', 'true'], true);

        return $validated;
    }

    /**
     * Run one leg; answer with the fresh today state either way. A state
     * rule refusal is a 422 with `code: attendance_rule` (no `errors`: it
     * isn't about a field).
     */
    private function submit(Request $request, Student $student, string $success, callable $action): JsonResponse
    {
        try {
            $action();
        } catch (AttendanceRuleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'attendance_rule',
                'today' => $this->todayState($request, $student),
            ], 422);
        }

        return response()->json([
            'message' => $success,
            ...$this->todayState($request, $student),
        ]);
    }

    /**
     * @return array{date: string, record: array|null, actions: array, hours: array}
     */
    private function todayState(Request $request, Student $student): array
    {
        $record = $this->attendance->todayRecord($student);
        $rendered = round((float) $student->attendances()->sum('rendered_hours'), 2);
        $required = $student->required_hours !== null ? (int) $student->required_hours : null;

        return [
            'date' => today()->toDateString(),
            'record' => $record ? (new AttendanceResource($record))->resolve($request) : null,
            'actions' => collect(AttendanceService::refusals($record))
                ->map(fn (?string $reason) => ['allowed' => $reason === null, 'reason' => $reason])
                ->all(),
            'hours' => [
                'rendered' => $rendered,
                'required' => $required,
                'remaining' => $required !== null ? round(max($required - $rendered, 0), 2) : null,
            ],
        ];
    }

    /**
     * The signed-in student's own profile. A Student account without one
     * (data not set up yet) gets 409 with a stable code, not a logout.
     */
    private function student(Request $request): Student
    {
        /** @var User $user */
        $user = $request->user();

        return $user->student ?? throw new HttpResponseException(response()->json([
            'message' => self::NO_PROFILE_MESSAGE,
            'code' => 'no_student_profile',
        ], 409));
    }
}
