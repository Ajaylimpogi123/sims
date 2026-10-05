<?php

namespace App\Http\Resources;

use App\Models\Role;
use App\Services\UserManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A User Management row: the User object plus status and what the caller
 * may do with it. Never includes the password or remember token.
 *
 * @mixin \App\Models\User
 */
class ManagedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $users = app(UserManagementService::class);
        $actor = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role_id' => $this->role_id === null ? null : (int) $this->role_id,
            'role' => $this->role?->role_name,
            'status' => $this->status === 'inactive' ? 'inactive' : 'active',
            'created_at' => $this->created_at?->toIso8601String(),
            'is_self' => (int) $this->id === (int) $actor->id,
            'can_edit' => $users->canEdit($actor, $this->resource),
            'can_toggle_status' => $users->canToggleStatus($actor, $this->resource),
            'assignable_roles' => $users->assignableRoles($actor, $this->resource)
                ->map(fn (Role $role) => ['id' => (int) $role->id, 'role_name' => $role->role_name])
                ->values()
                ->all(),
        ];
    }
}
