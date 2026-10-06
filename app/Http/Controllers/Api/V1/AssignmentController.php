<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AssignmentRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAssignmentsRequest;
use App\Http\Resources\AssignmentStudentResource;
use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use App\Services\InternshipAssignmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internship Assignment (Coordinator, Administrator by the route's
 * role:2,4): the website's InternshipAssignmentController through
 * InternshipAssignmentService, same rules and messages. {student} is the
 * student record id. A rule refusal (slots, roster, inactive company /
 * supervisor) is a 422 with `code` and the field error. Activate /
 * deactivate are explicit (idempotent) where the website toggles.
 */
class AssignmentController extends Controller
{
    public function __construct(private InternshipAssignmentService $assignments) {}

    public function index(ListAssignmentsRequest $request): JsonResponse
    {
        $page = $this->assignments->query($request->filters())
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        $this->assignments->loadRosterFlags($page->getCollection());

        return response()->json([
            'data' => AssignmentStudentResource::collection($page->items())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        $status = fn ($model) => $model->status === 'inactive' ? 'inactive' : 'active';

        return response()->json([
            'companies' => $this->assignments->companyOptions()->map(fn (Company $company) => [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'status' => $status($company),
                'slots' => (int) $company->slots,
                'students_count' => (int) $company->students_count,
                'slots_available' => max(0, (int) $company->slots - (int) $company->students_count),
                'supervisors' => $company->supervisors->map(fn (User $supervisor) => [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'email' => $supervisor->email,
                    'status' => $status($supervisor),
                ])->values()->all(),
            ])->values()->all(),
            'internship_statuses' => collect(InternshipAssignmentService::STATUSES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, int $student): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $this->find($student))]);
    }

    public function update(Request $request, int $student): JsonResponse
    {
        $model = $this->find($student);

        $this->assignments->prepare($request);

        $validated = $request->validate($this->assignments->rules($model, $request, rosterCheck: false));

        try {
            $this->assignments->update($model, $validated);
        } catch (AssignmentRuleException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first(),
                'code' => $e->reason,
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return response()->json([
            'message' => 'Student updated successfully.',
            'student' => $this->present($request, $model),
        ]);
    }

    public function activate(Request $request, int $student): JsonResponse
    {
        return $this->setStatus($request, $student, true);
    }

    public function deactivate(Request $request, int $student): JsonResponse
    {
        return $this->setStatus($request, $student, false);
    }

    private function setStatus(Request $request, int $id, bool $active): JsonResponse
    {
        $model = $this->find($id);

        try {
            $this->assignments->setStatus($model, $active);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        return response()->json([
            'message' => 'Student status updated.',
            'student' => $this->present($request, $model),
        ]);
    }

    private function find(int $id): Student
    {
        $student = Student::query()->find($id);

        abort_if($student === null, 404);

        return $student;
    }

    private function present(Request $request, Student $student): array
    {
        $fresh = Student::query()
            ->with(['user:id,name,email,status', 'company:id,company_name,status', 'supervisor:id,name,email,status'])
            ->find($student->id);

        abort_if($fresh === null, 404);

        $this->assignments->loadRosterFlags([$fresh]);

        return (new AssignmentStudentResource($fresh))->resolve($request);
    }
}
