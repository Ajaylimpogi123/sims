<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Company Management row. Expects CompanyManagementService::loadDetail()
 * to have run (counts, roster, delete blocker).
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

        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'address' => $this->address,
            'contact_person' => $this->contact_person,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'industry' => $this->industry,
            'slots' => $slots,
            'students_count' => $studentsCount,
            'slots_available' => max(0, $slots - $studentsCount),
            'status' => $this->status === 'inactive' ? 'inactive' : 'active',
            'supervisors' => $this->supervisors->map(fn ($supervisor) => [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'email' => $supervisor->email,
                'status' => $supervisor->status === 'inactive' ? 'inactive' : 'active',
                'students_count' => (int) $supervisor->company_students_count,
            ])->values()->all(),
            'supervisors_count' => (int) $this->supervisors_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can_edit' => true,
            'can_toggle_status' => true,
            'can_manage_roster' => true,
            'can_delete' => $blocker === null,
            'delete_blocked_reason' => $blocker,
        ];
    }
}
