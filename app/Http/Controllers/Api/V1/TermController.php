<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\User;
use App\Services\TermService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Terms (School Year + Semester). The list is open to every role (the
 * app's term picker and labels); create / edit / delete / set current are
 * Coordinator and Administrator only (route role:2,4), through
 * TermService like the website.
 */
class TermController extends Controller
{
    public function __construct(private TermService $terms) {}

    /**
     * Every term, newest first, plus the current term's id. Staff also get
     * students_count / can_edit / can_delete per term.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->terms->list()->map(fn (Term $term) => TermService::present($term, $user))->values(),
            'current_term_id' => Term::currentId(),
            'can_create' => $user->hasRole(User::ROLE_COORDINATOR, User::ROLE_ADMIN),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $term = $this->terms->create($request->validate($this->terms->rules($request->all())));

        return $this->respond($request, 'Term added.', $term, 201);
    }

    public function update(Request $request, int $term): JsonResponse
    {
        $model = $this->find($term);

        $this->terms->update($model, $request->validate($this->terms->rules($request->all(), $model)));

        return $this->respond($request, 'Term updated.', $model);
    }

    public function destroy(int $term): JsonResponse
    {
        $blocker = $this->terms->delete($this->find($term));

        if ($blocker !== null) {
            $message = TermService::DELETE_MESSAGES[$blocker];

            return response()->json([
                'message' => $message,
                'code' => $blocker,
                'errors' => ['term' => [$message]],
            ], 422);
        }

        return response()->json(['message' => 'Term deleted.']);
    }

    public function setCurrent(Request $request, int $term): JsonResponse
    {
        $model = $this->terms->setCurrent($this->find($term));

        return $this->respond($request, "{$model->label} is now the current term.", $model);
    }

    private function find(int $id): Term
    {
        return Term::query()->find($id) ?? abort(404);
    }

    private function respond(Request $request, string $message, Term $term, int $status = 200): JsonResponse
    {
        $term->refresh()->loadCount('students');

        return response()->json([
            'message' => $message,
            'term' => TermService::present($term, $request->user()),
        ], $status);
    }
}
