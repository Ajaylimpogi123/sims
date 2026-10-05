<?php

namespace App\Http\Controllers;

use App\Models\EvaluationCriteria;
use App\Services\EvaluationCriteriaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EvaluationCriteriaController extends Controller
{
    public function __construct(private EvaluationCriteriaService $criteria) {}

    public function index(): Response
    {
        $criteria = EvaluationCriteria::query()
            ->withCount('responses')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('EvaluationCriteria/Index', [
            'criteria' => $criteria,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->criteria->create($request->validate(EvaluationCriteriaService::rules()));

        return redirect()->route('evaluation-criteria.index')
            ->with('success', 'Evaluation criterion added.');
    }

    public function update(Request $request, EvaluationCriteria $evaluationCriterion): RedirectResponse
    {
        $this->criteria->update($evaluationCriterion, $request->validate(EvaluationCriteriaService::rules()));

        return redirect()->route('evaluation-criteria.index')
            ->with('success', 'Evaluation criterion updated.');
    }

    public function toggleActive(EvaluationCriteria $evaluationCriterion): RedirectResponse
    {
        $this->criteria->setActive($evaluationCriterion, ! $evaluationCriterion->is_active);

        return redirect()->route('evaluation-criteria.index')
            ->with('success', 'Evaluation criterion status updated.');
    }

    public function destroy(EvaluationCriteria $evaluationCriterion): RedirectResponse
    {
        // Criteria that already have evaluation responses must be
        // deactivated, not deleted, so historical evaluations keep their
        // data intact — deletion is only for criteria that were never used.
        if (! $this->criteria->delete($evaluationCriterion)) {
            return redirect()->route('evaluation-criteria.index')
                ->with('success', 'This criterion has existing responses, so it was deactivated instead of deleted.');
        }

        return redirect()->route('evaluation-criteria.index')
            ->with('success', 'Evaluation criterion deleted.');
    }
}
