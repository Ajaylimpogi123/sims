<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User Management (Coordinator, Administrator). The rules live in
 * UserManagementService, shared with the mobile API.
 */
class UserController extends Controller
{
    public function __construct(private UserManagementService $users) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();

        $users = $this->users->query($viewer, $request->only(['role_id', 'status']))
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('UserManagement/Index', [
            'roles' => $this->users->filterRoles($viewer),
            'users' => $users,
            'filters' => $request->only(['role_id', 'status']),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! $this->users->canEdit($request->user(), $user)) {
            abort(403, 'You cannot manage an Administrator account.');
        }

        $validated = $request->validate(
            $this->users->updateRules($request->user(), $user, $request->filled('password')),
        );

        $this->users->update($request->user(), $user, $validated);

        return redirect()->route('user-management.index')
            ->with('success', 'User updated successfully.');
    }

    public function toggleStatus(Request $request, int $id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if ($request->user()->id === $user->id) {
            abort(403, 'You cannot change your own account status.');
        }

        if (! $this->users->canEdit($request->user(), $user)) {
            abort(403, 'You cannot manage an Administrator account.');
        }

        $user = $this->users->setStatus($request->user(), $user, $user->status !== 'active');

        $message = $user->status === 'inactive'
            ? 'User account deactivated successfully.'
            : 'User account activated successfully.';

        return redirect()->route('user-management.index')
            ->with('success', $message);
    }
}
