<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListCompaniesRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyManagementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company Management with the Company Supervisor (Coordinator,
 * Administrator by the route's role:2,4): the website's CompanyController
 * through CompanyManagementService, same rules and messages. Activate /
 * deactivate are explicit (idempotent) where the website toggles.
 */
class CompanyController extends Controller
{
    public function __construct(private CompanyManagementService $companies) {}

    public function index(ListCompaniesRequest $request): JsonResponse
    {
        $page = $this->companies->query($request->filters())
            ->orderBy('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        $this->companies->loadDetails($page->getCollection());

        return response()->json([
            'data' => CompanyResource::collection($page->items())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ],
            'can_create' => true,
        ]);
    }

    public function show(Request $request, int $company): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $this->find($company))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->companies->prepare($request);

        $company = $this->companies->create($request->validate($this->companies->rules()));

        return response()->json([
            'message' => 'Company added successfully.',
            'company' => $this->present($request, $company),
        ], 201);
    }

    public function update(Request $request, int $company): JsonResponse
    {
        $model = $this->find($company);

        $this->companies->prepare($request);

        $validated = $request->validate($this->companies->rules($model));

        $moved = 0;
        $this->guard(function () use ($model, $validated, &$moved) {
            $moved = $this->companies->update($model, $validated);
        });

        return response()->json([
            'message' => 'Company updated successfully.'.CompanyManagementService::movedMessage($moved, $model->load('supervisor')),
            'company' => $this->present($request, $model),
            'students_reassigned' => $moved,
        ]);
    }

    public function activate(Request $request, int $company): JsonResponse
    {
        return $this->setStatus($request, $company, true);
    }

    public function deactivate(Request $request, int $company): JsonResponse
    {
        return $this->setStatus($request, $company, false);
    }

    public function destroy(int $company): JsonResponse
    {
        $blocker = $this->companies->delete($this->find($company));

        if ($blocker !== null) {
            return response()->json([
                'message' => CompanyManagementService::DELETE_MESSAGES[$blocker],
                'code' => $blocker,
            ], 422);
        }

        return response()->json(['message' => 'Company deleted successfully.']);
    }

    /**
     * The supervisor picker: active Supervisor accounts, by name.
     */
    public function supervisorOptions(): JsonResponse
    {
        return response()->json([
            'data' => $this->companies->supervisorOptions()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all(),
        ]);
    }

    private function setStatus(Request $request, int $company, bool $active): JsonResponse
    {
        $model = $this->find($company);

        $this->guard(fn () => $this->companies->setStatus($model, $active));

        return response()->json([
            'message' => 'Company status updated.',
            'company' => $this->present($request, $model),
        ]);
    }

    private function find(int $id): Company
    {
        $company = Company::query()->find($id);

        abort_if($company === null, 404);

        return $company;
    }

    /**
     * The company was deleted meanwhile: 404.
     */
    private function guard(callable $action): void
    {
        try {
            $action();
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function present(Request $request, Company $company): array
    {
        $fresh = Company::query()->find($company->id);
        abort_if($fresh === null, 404);

        return (new CompanyResource($this->companies->loadDetail($fresh)))->resolve($request);
    }
}
