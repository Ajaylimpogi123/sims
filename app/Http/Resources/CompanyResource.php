<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Company Management row. Expects CompanyManagementService::loadDetail()
 * to have run (students_count, supervisor, supervisor_issue, delete
 * blocker).
 *
 * @mixin \App\Models\Company
 */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $studentsCount = (int) $this->students_count;
        $slots = (int) $this->slots;
        $blocker = $this->delete_blocker;
        $status = fn ($model) => $model->status === 'inactive' ? 'inactive' : 'active';

        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'address' => $this->address,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'industry' => $this->industry,
            'slots' => $slots,
            'students_count' => $studentsCount,
            'slots_available' => max(0, $slots - $studentsCount),
            'status' => $status($this->resource),
            'supervisor_id' => $this->supervisor_id === null ? null : (int) $this->supervisor_id,
            'supervisor' => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'name' => $this->supervisor->name,
                'email' => $this->supervisor->email,
                'status' => $status($this->supervisor),
                'role_id' => (int) $this->supervisor->role_id,
            ] : null,
            // null | no_supervisor | not_a_supervisor | inactive
            'supervisor_issue' => $this->supervisor_issue,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can_edit' => true,
            'can_toggle_status' => true,
            'can_delete' => $blocker === null,
            'delete_blocked_reason' => $blocker,
        ];
    }
}
