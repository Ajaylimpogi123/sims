<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ValidatesAttendanceMonitoring;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAttendanceRequest;
use App\Http\Requests\Api\V1\ListMonitoredStudentsRequest;
use App\Http\Resources\MonitoredStudentResource;
use App\Http\Resources\MonitoringAttendanceResource;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Attendance Monitoring and Progress Monitoring (Coordinator: view only,
 * Supervisor: own students, Administrator: everyone). Lists use the
 * website's Student::monitoredBy() query; writes use the website's
 * validation (ValidatesAttendanceMonitoring), StudentPolicy /
 * AttendancePolicy and AttendanceService. The route's role:3,4 keeps
 * Coordinators off every write. A student or entry the caller can't see
 * is a 404 so ids can't be probed.
 */
class MonitoringController extends Controller
{
    use ValidatesAttendanceMonitoring;

    private const STUDENT_RELATIONS = ['user:id,name', 'company:id,company_name', 'supervisor:id,name'];

    public function __construct(private AttendanceService $attendance) {}

    /**
     * GET /monitoring/students (the website's Attendance Monitoring list).
     */
    public function students(ListMonitoredStudentsRequest $request): JsonResponse
    {
        return $this->studentList($request);
    }

    /**
     * GET /progress (the website's Progress Monitoring list: the same
     * students and hours, with the internship status).
     */
    public function progress(ListMonitoredStudentsRequest $request): JsonResponse
    {
        return $this->studentList($request);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorizeView($request, $student);

        return response()->json(['data' => $this->studentItem($request, $student)]);
    }

    /**
     * GET /monitoring/students/{student}/attendance: the website's log
     * modal, newest date first, cursor-paginated like GET /attendance.
     */
    public function attendance(ListAttendanceRequest $request, Student $student): JsonResponse
    {
        $this->authorizeView($request, $student);

        $page = $student->attendances()
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        $items = collect($page->items())->each(fn (Attendance $row) => $row->setRelation('student', $student));

        return response()->json([
            'data' => MonitoringAttendanceResource::collection($items)->resolve($request),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        $this->authorizeManage($request, $student);

        $validated = $this->validateAttendanceEntry($request, $student);
        $entry = $this->attendance->createEntry($student, $request->user(), $validated);

        return response()->json([
            'message' => 'Attendance entry added.',
            'attendance' => $this->attendanceItem($request, $entry->fresh(), $student),
            'student' => $this->studentItem($request, $student),
        ], 201);
    }

    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        $student = $this->authorizeEntry($request, $attendance, 'update');

        $validated = $this->validateAttendanceEntry($request, $student, $attendance->id);
        $this->attendance->updateEntry($attendance, $request->user(), $validated);

        return response()->json([
            'message' => 'Attendance entry updated.',
            'attendance' => $this->attendanceItem($request, $attendance->fresh(), $student),
            'student' => $this->studentItem($request, $student),
        ]);
    }

    public function destroy(Request $request, Attendance $attendance): JsonResponse
    {
        $student = $this->authorizeEntry($request, $attendance, 'delete');

        $this->attendance->deleteEntry($attendance);

        return response()->json([
            'message' => 'Attendance entry deleted.',
            'student' => $this->studentItem($request, $student),
        ]);
    }

    public function updateRequiredHours(Request $request, Student $student): JsonResponse
    {
        $this->authorizeManage($request, $student);

        $student->update($this->validateRequiredHours($request));

        return response()->json([
            'message' => 'Required hours updated.',
            'student' => $this->studentItem($request, $student),
        ]);
    }

    private function studentList(ListMonitoredStudentsRequest $request): JsonResponse
    {
        $search = $request->search();

        $page = Student::query()
            ->with(self::STUDENT_RELATIONS)
            ->monitoredBy($request->user())
            ->orderByDesc('id')
            ->when($search !== null, fn (Builder $query) => $query->whereHas(
                'user',
                fn (Builder $user) => $user->where('name', 'like', '%'.self::escapeLike($search).'%'),
            ))
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        return response()->json([
            'data' => MonitoredStudentResource::collection($page->items())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function authorizeView(Request $request, Student $student): void
    {
        abort_if(Gate::forUser($request->user())->denies('view', $student), 404);
    }

    /**
     * Not visible = 404; visible but read-only = 403.
     */
    private function authorizeManage(Request $request, Student $student): void
    {
        $this->authorizeView($request, $student);
        abort_if(Gate::forUser($request->user())->denies('manageAttendance', $student), 403, 'Unauthorized access');
    }

    /**
     * @param  'update'|'delete'  $ability
     */
    private function authorizeEntry(Request $request, Attendance $attendance, string $ability): Student
    {
        $gate = Gate::forUser($request->user());

        abort_if($attendance->student === null || $gate->denies('view', $attendance), 404);
        abort_if($gate->denies($ability, $attendance), 403, 'Unauthorized access');

        return $attendance->student;
    }

    /**
     * Re-read through the list query so the hours are the fresh sum.
     */
    private function studentItem(Request $request, Student $student): array
    {
        $fresh = Student::query()
            ->with(self::STUDENT_RELATIONS)
            ->monitoredBy($request->user())
            ->whereKey($student->id)
            ->firstOrFail();

        return (new MonitoredStudentResource($fresh))->resolve($request);
    }

    private function attendanceItem(Request $request, Attendance $attendance, Student $student): array
    {
        return (new MonitoringAttendanceResource($attendance->setRelation('student', $student)))->resolve($request);
    }
}
