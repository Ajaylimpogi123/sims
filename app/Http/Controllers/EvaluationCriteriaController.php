<?php

namespace App\Http\Controllers;

use App\Models\EvaluationCriteria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EvaluationCriteriaController extends Controller
{
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
        $validated = $this->validateCriteria($request);

        EvaluationCriteria::create($validated + ['is_active' => true]);

        return redirect()->route('evaluation-criteria.index')
            ->with('success', 'Evaluation criterion added.');
    }

    public function update(Request $request, EvaluationCriteria $evaluationCriterion): RedirectResponse
    {
        $validated = $this->validateCriteria($request);

        $evaluationCriterion->update($validated);

        return redirect()->route('evaluation-criteria.index')
            ->with('success', 'Evaluation criterion updated.');
    }

    public function toggleActive(EvaluationCriteria $evaluationCriterion): RedirectResponse
    {
        $evaluationCriterion->update([
            'is_active' => ! $evaluationCriterion->is_active,
        ]);

        return redirect()->route('evaluation-criteria.index')
            ->with('success', 'Evaluation criterion status updated.');
    }

    public function destroy(EvaluationCriteria $evaluationCriterion): RedirectResponse
    {
        // Criteria that already have evaluation responses must be
        // deactivated, not deleted, so historical evaluations keep their
        // data intact — deletion is only for criteria that were never used.
        if ($evaluationCriterion->responses()->exists()) {
            $evaluationCriterion->update(['is_active' => false]);

            return redirect()->route('evaluation-criteria.index')
                ->with('success', 'This criterion has existing responses, so it was deactivated instead of deleted.');
        }

        $evaluationCriterion->delete();

        return redirect()->route('evaluation-criteria.index')
            ->with('success', 'Evaluation criterion deleted.');
    }

    private function validateCriteria(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
