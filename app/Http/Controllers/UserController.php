<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const ADMIN_ROLE_ID = 4;

    public function index(Request $request): Response
    {
        $viewerIsAdmin = (int) $request->user()->role_id === self::ADMIN_ROLE_ID;

        $users = User::with('role:id,role_name')
            ->when(! $viewerIsAdmin, fn ($query) => $query->where('role_id', '!=', self::ADMIN_ROLE_ID))
            ->when($request->filled('role_id'), fn ($query) => $query->where('role_id', $request->role_id))
            ->when(
                $request->filled('status') && in_array($request->status, ['active', 'inactive'], true),
                fn ($query) => $query->where('status', $request->status),
            )
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        $roles = Role::orderBy('role_name')
            ->when(! $viewerIsAdmin, fn ($query) => $query->where('id', '!=', self::ADMIN_ROLE_ID))
            ->get(['id', 'role_name']);

        return Inertia::render('UserManagement/Index', [
            'roles' => $roles,
            'users' => $users,
            'filters' => $request->only(['role_id', 'status']),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $user = User::findOrFail($id);
        $actorIsAdmin = (int) $request->user()->role_id === self::ADMIN_ROLE_ID;

        if (! $actorIsAdmin && (int) $user->role_id === self::ADMIN_ROLE_ID) {
            abort(403, 'You cannot manage an Administrator account.');
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'role_id' => [
                'required',
                'exists:roles,id',
                Rule::notIn($actorIsAdmin ? [] : [self::ADMIN_ROLE_ID]),
            ],
        ];

        if ($request->filled('password')) {
            $rules['password'] = ['required', 'confirmed', Rules\Password::defaults()];
        }

        $validated = $request->validate($rules);

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role_id' => $validated['role_id'],
        ];

        if (! empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        return redirect()->route('user-management.index')
            ->with('success', 'User updated successfully.');
    }

    public function toggleStatus(Request $request, int $id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if ($request->user()->id === $user->id) {
            abort(403, 'You cannot change your own account status.');
        }

        $actorIsAdmin = (int) $request->user()->role_id === self::ADMIN_ROLE_ID;

        if (! $actorIsAdmin && (int) $user->role_id === self::ADMIN_ROLE_ID) {
            abort(403, 'You cannot manage an Administrator account.');
        }

        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        $message = $newStatus === 'inactive'
            ? 'User account deactivated successfully.'
            : 'User account activated successfully.';

        return redirect()->route('user-management.index')
            ->with('success', $message);
    }
}
