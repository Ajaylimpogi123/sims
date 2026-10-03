<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ReportRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListReportsRequest;
use App\Http\Resources\InternshipReportResource;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use App\Services\InternshipReportService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Student self-service reports (role 1 only, own reports only): list,
 * detail, create, update and delete. Validation, the duplicate-period rule,
 * attachment storage and notifications all live in InternshipReportService,
 * shared with the website's InternshipReportController.
 *
 * Another student's report is a 404 (ids can't be probed); the owner's
 * report that is no longer pending is a 422 with code report_not_pending.
 */
class InternshipReportController extends Controller
{
    public function __construct(private InternshipReportService $reports) {}

    /**
     * Newest period first (the website's order), cursor-paginated.
     */
    public function index(ListReportsRequest $request): JsonResponse
    {
        $page = $this->student($request)->internshipReports()
            ->with('reviewer:id,name')
            ->when($request->type(), fn ($query, string $type) => $query->where('type', $type))
            ->when($request->status(), fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        return response()->json([
            'data' => InternshipReportResource::collection($page->items())->resolve($request),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function show(Request $request, int $report): JsonResponse
    {
        return response()->json([
            'report' => $this->present($request, $this->ownReport($request, $report)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $validated = $request->validate(InternshipReportService::rules());

        $report = $this->reports->create($student, $validated, $request->file('attachment'));

        return response()->json([
            'message' => 'Report submitted for review.',
            'report' => $this->present($request, $report),
        ], 201);
    }

    /**
     * Multipart clients send POST with `_method=PATCH` (PHP only parses
     * multipart bodies on POST); a real PATCH works with a JSON body.
     */
    public function update(Request $request, int $report): JsonResponse
    {
        $model = $this->ownReport($request, $report);
        $this->ensurePending($request, $model);

        $validated = $request->validate([
            ...InternshipReportService::rules(),
            'remove_attachment' => ['sometimes', 'nullable', 'in:0,1,true,false'],
        ]);

        $remove = in_array((string) ($validated['remove_attachment'] ?? ''), ['1', 'true'], true);

        if ($remove && $request->hasFile('attachment')) {
            throw ValidationException::withMessages([
                'remove_attachment' => 'Either upload a new attachment or remove the current one, not both.',
            ]);
        }

        return $this->refusable($request, $model, function () use ($request, $model, $validated, $remove) {
            $this->reports->update($model, $validated, $request->file('attachment'), $remove);

            return response()->json([
                'message' => 'Report updated.',
                'report' => $this->present($request, $model),
            ]);
        });
    }

    public function destroy(Request $request, int $report): JsonResponse
    {
        $model = $this->ownReport($request, $report);
        $this->ensurePending($request, $model);

        return $this->refusable($request, $model, function () use ($model) {
            $this->reports->delete($model);

            return response()->json(['message' => 'Report deleted.']);
        });
    }

    /**
     * The policy check up front (no file is written for an obvious
     * refusal); the service re-checks under a row lock.
     */
    private function ensurePending(Request $request, InternshipReport $report): void
    {
        if (Gate::forUser($request->user())->denies('update', $report)) {
            throw new HttpResponseException($this->notPending($request, $report));
        }
    }

    /**
     * @param  callable(): JsonResponse  $action
     */
    private function refusable(Request $request, InternshipReport $report, callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ReportRuleException) {
            return $this->notPending($request, $report->refresh());
        }
    }

    private function notPending(Request $request, InternshipReport $report): JsonResponse
    {
        return response()->json([
            'message' => InternshipReportService::NOT_PENDING_MESSAGE,
            'code' => 'report_not_pending',
            'report' => $this->present($request, $report),
        ], 422);
    }

    private function present(Request $request, InternshipReport $report): array
    {
        $report->loadMissing('reviewer:id,name');

        return (new InternshipReportResource($report))->resolve($request);
    }

    /**
     * One of the signed-in student's own reports; anything else is a 404.
     */
    private function ownReport(Request $request, int $id): InternshipReport
    {
        return $this->student($request)->internshipReports()->find($id)
            ?? abort(404);
    }

    /**
     * The signed-in student's own profile. A Student account without one
     * gets 409 with a stable code, not a logout (same as attendance).
     */
    private function student(Request $request): Student
    {
        /** @var User $user */
        $user = $request->user();

        return $user->student ?? throw new HttpResponseException(response()->json([
            'message' => AttendanceController::NO_PROFILE_MESSAGE,
            'code' => 'no_student_profile',
        ], 409));
    }
}
