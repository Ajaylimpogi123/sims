<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ReportRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListReportReviewsRequest;
use App\Http\Resources\ReportReviewResource;
use App\Models\InternshipReport;
use App\Services\InternshipReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Report Reviews (Coordinator / Admin: every report, can review;
 * Supervisor: own students' reports, view only). The list is the website's
 * ReportReviewController query (InternshipReport::visibleTo); per record,
 * InternshipReportPolicy::view (404 when not visible) and ::review; the
 * review itself goes through InternshipReportService::review (row-locked,
 * once only, notifies the student). The route's role:2,4 keeps Supervisors
 * off the review endpoint.
 */
class ReportReviewController extends Controller
{
    private const RELATIONS = [
        'reviewer:id,name',
        'student.user:id,name',
        'student.company:id,company_name',
    ];

    public function __construct(private InternshipReportService $reports) {}

    /**
     * Pending first, then newest period (the website's order), with id as
     * the tie-breaker, cursor-paginated.
     */
    public function index(ListReportReviewsRequest $request): JsonResponse
    {
        $search = $request->search();

        $page = InternshipReport::query()
            ->with(self::RELATIONS)
            ->visibleTo($request->user())
            ->when($request->type(), fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($request->status(), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->studentId(), fn (Builder $query, int $id) => $query->where('student_id', $id))
            ->when($search !== null, fn (Builder $query) => $query->whereHas('student', function (Builder $student) use ($search) {
                $like = '%'.self::escapeLike($search).'%';

                $student->where('student_number', 'like', $like)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $like));
            }))
            // 'pending' sorts before 'reviewed': the website's pending-first order.
            ->orderBy('status')
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage());

        return response()->json([
            'data' => ReportReviewResource::collection($page->items())->resolve($request),
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
            'report' => $this->present($request, $this->visibleReport($request, $report)),
        ]);
    }

    public function review(Request $request, int $report): JsonResponse
    {
        $model = $this->visibleReport($request, $report);
        abort_if(Gate::forUser($request->user())->denies('review', $model), 403, 'Unauthorized access');

        $validated = $request->validate(InternshipReportService::reviewRules());

        try {
            $this->reports->review($model, $request->user(), $validated['comment'] ?? null);
        } catch (ReportRuleException $e) {
            // Already reviewed (by someone else, or a double tap / retry).
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'report_not_pending',
                'report' => $this->present($request, $model->refresh()),
            ], 422);
        } catch (ModelNotFoundException) {
            // The student deleted it after this request loaded it.
            abort(404);
        }

        return response()->json([
            'message' => 'Report reviewed.',
            'report' => $this->present($request, $model),
        ]);
    }

    /**
     * A report the caller may see; anything else (unknown id, another
     * supervisor's student) is a 404 so ids can't be probed.
     */
    private function visibleReport(Request $request, int $id): InternshipReport
    {
        $report = InternshipReport::query()->find($id);

        abort_if($report === null || Gate::forUser($request->user())->denies('view', $report), 404);

        return $report;
    }

    private function present(Request $request, InternshipReport $report): array
    {
        $report->load(self::RELATIONS);

        return (new ReportReviewResource($report))->resolve($request);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
