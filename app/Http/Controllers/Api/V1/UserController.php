<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListUsersRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User Management (Coordinator, Administrator by the route's role:2,4),
 * the website's UserController / RegisteredUserController through
 * UserManagementService: same rules, same messages. Administrator accounts
 * are 404 for non-Administrators (the website answers 403), so their ids
 * can't be probed. Activate / deactivate are explicit (idempotent) where
 * the website toggles.
 */
class UserController extends Controller
{
    public function __construct(private UserManagementService $users) {}

    public function index(ListUsersRequest $request): JsonResponse
    {
        $page = $this->users->query($request->user(), $request->filters())
            ->orderByDesc('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        return response()->json([
            'data' => ManagedUserResource::collection($page->items())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ],
            'can_create' => true,
        ] + $this->roleOptions($request->user()));
    }

    public function roles(Request $request): JsonResponse
    {
        return response()->json($this->roleOptions($request->user()));
    }

    public function show(Request $request, int $user): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $this->find($request, $user))]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->users->create($request->validate($this->users->createRules($request->user())));

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => $this->present($request, $user),
        ], 201);
    }

    public function update(Request $request, int $user): JsonResponse
    {
        $model = $this->find($request, $user);

        $validated = $request->validate(
            $this->users->updateRules($request->user(), $model, $request->filled('password')),
            $this->users->roleMessages($model),
        );

        $this->guard(fn () => $this->users->update($request->user(), $model, $validated));

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $this->present($request, $model),
        ]);
    }

    public function activate(Request $request, int $user): JsonResponse
    {
        return $this->setStatus($request, $user, true);
    }

    public function deactivate(Request $request, int $user): JsonResponse
    {
        return $this->setStatus($request, $user, false);
    }

    private function setStatus(Request $request, int $id, bool $active): JsonResponse
    {
        $model = $this->find($request, $id);

        abort_if((int) $model->id === (int) $request->user()->id, 403, 'You cannot change your own account status.');

        $this->guard(fn () => $this->users->setStatus($request->user(), $model, $active));

        return response()->json([
            'message' => $active ? 'User account activated successfully.' : 'User account deactivated successfully.',
            'user' => $this->present($request, $model),
        ]);
    }

    /**
     * A user the caller can't see (an Administrator, for a Coordinator) is
     * a 404, like an unknown id.
     */
    private function find(Request $request, int $id): User
    {
        $user = User::query()->find($id);

        abort_if($user === null || ! $this->users->canView($request->user(), $user), 404);

        return $user;
    }

    /**
     * The row changed under the lock (became an Administrator, or was
     * deleted): it is no longer visible, so 404.
     */
    private function guard(callable $action): void
    {
        try {
            $action();
        } catch (ModelNotFoundException|AuthorizationException) {
            abort(404);
        }
    }

    /**
     * @return array{roles: list<array{id: int, role_name: string}>, create_roles: list<array{id: int, role_name: string}>}
     */
    private function roleOptions(User $viewer): array
    {
        return [
            'roles' => self::roleList($this->users->filterRoles($viewer)),
            'create_roles' => self::roleList($this->users->assignableRoles($viewer)),
        ];
    }

    private static function roleList(Collection $roles): array
    {
        return $roles->map(fn (Role $role) => ['id' => (int) $role->id, 'role_name' => $role->role_name])->values()->all();
    }

    private function present(Request $request, User $user): array
    {
        $user->refresh()->load('role:id,role_name');

        return (new ManagedUserResource($user))->resolve($request);
    }
}
