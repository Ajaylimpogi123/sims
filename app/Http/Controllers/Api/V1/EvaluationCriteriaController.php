<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluationCriterionResource;
use App\Models\EvaluationCriteria;
use App\Services\EvaluationCriteriaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evaluation Criteria management (Administrator only, by the route's
 * role:4), the website's EvaluationCriteriaController through
 * EvaluationCriteriaService: same rules, same messages. Activate /
 * deactivate are explicit (idempotent) where the website toggles; a
 * criterion with saved ratings is deactivated instead of deleted, so saved
 * evaluations keep them.
 */
class EvaluationCriteriaController extends Controller
{
    public function __construct(private EvaluationCriteriaService $criteria) {}

    /**
     * Every criterion, inactive included, in the website's order
     * (category, sort order), id as the tie-breaker.
     */
    public function index(Request $request): JsonResponse
    {
        $criteria = EvaluationCriteria::query()
            ->withCount('responses')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'criteria' => EvaluationCriterionResource::collection($criteria)->resolve($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $criterion = $this->criteria->create($request->validate(EvaluationCriteriaService::rules()));

        return $this->respond($request, 'Evaluation criterion added.', $criterion, 201);
    }

    public function update(Request $request, int $criterion): JsonResponse
    {
        $model = $this->find($criterion);

        $this->criteria->update($model, $request->validate(EvaluationCriteriaService::rules()));

        return $this->respond($request, 'Evaluation criterion updated.', $model);
    }

    public function activate(Request $request, int $criterion): JsonResponse
    {
        return $this->respond($request, 'Evaluation criterion status updated.',
            $this->criteria->setActive($this->find($criterion), true));
    }

    public function deactivate(Request $request, int $criterion): JsonResponse
    {
        return $this->respond($request, 'Evaluation criterion status updated.',
            $this->criteria->setActive($this->find($criterion), false));
    }

    public function destroy(Request $request, int $criterion): JsonResponse
    {
        $model = $this->find($criterion);

        try {
            $deleted = $this->criteria->delete($model);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if ($deleted) {
            return response()->json([
                'message' => 'Evaluation criterion deleted.',
                'deleted' => true,
                'criterion' => null,
            ]);
        }

        return response()->json([
            'message' => 'This criterion has existing responses, so it was deactivated instead of deleted.',
            'deleted' => false,
            'criterion' => $this->present($request, $model),
        ]);
    }

    private function find(int $id): EvaluationCriteria
    {
        return EvaluationCriteria::query()->find($id) ?? abort(404);
    }

    private function respond(Request $request, string $message, EvaluationCriteria $criterion, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'criterion' => $this->present($request, $criterion),
        ], $status);
    }

    private function present(Request $request, EvaluationCriteria $criterion): array
    {
        $criterion->refresh()->loadCount('responses');

        return (new EvaluationCriterionResource($criterion))->resolve($request);
    }
}
