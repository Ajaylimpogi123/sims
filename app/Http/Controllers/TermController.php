<?php

namespace App\Http\Controllers;

use App\Models\Term;
use App\Rules\NotBoolean;
use App\Services\TermService;
use App\Support\TermContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Term management (Coordinator, Administrator) and the header term
 * switcher (Coordinator, Supervisor, Administrator). The rules live in
 * TermService, shared with the mobile API.
 */
class TermController extends Controller
{
    public function __construct(private TermService $terms) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Terms/Index', [
            'termList' => $this->terms->list()
                ->map(fn (Term $term) => TermService::present($term, $request->user()))
                ->values(),
            'semesters' => TermService::semesterOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->terms->create($request->validate($this->terms->rules($request->all())));

        return redirect()->route('terms.index')->with('success', 'Term added.');
    }

    public function update(Request $request, Term $term): RedirectResponse
    {
        $this->terms->update($term, $request->validate($this->terms->rules($request->all(), $term)));

        return redirect()->route('terms.index')->with('success', 'Term updated.');
    }

    public function destroy(Term $term): RedirectResponse
    {
        $blocker = $this->terms->delete($term);

        if ($blocker !== null) {
            return redirect()->route('terms.index')->with('error', TermService::DELETE_MESSAGES[$blocker]);
        }

        return redirect()->route('terms.index')->with('success', 'Term deleted.');
    }

    public function setCurrent(Request $request, Term $term): RedirectResponse
    {
        $term = $this->terms->setCurrent($term);

        // The switcher follows the new current term.
        $request->session()->forget(TermContext::SESSION_KEY);

        return redirect()->route('terms.index')->with('success', "{$term->label} is now the current term.");
    }

    /**
     * The header switcher: which term the lists show, kept in the session.
     * Picking the current term clears the choice, so the lists follow the
     * current term when it changes.
     */
    public function select(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'term_id' => ['bail', 'required', new NotBoolean, 'integer', Rule::exists('terms', 'id')],
        ]);

        $termId = (int) $validated['term_id'];

        if ($termId === Term::currentId()) {
            $request->session()->forget(TermContext::SESSION_KEY);
        } else {
            $request->session()->put(TermContext::SESSION_KEY, $termId);
        }

        return back();
    }
}
