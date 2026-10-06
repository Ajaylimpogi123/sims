<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Internship Assignment row (a student record). Expects user, company
 * and supervisor loaded and InternshipAssignmentService::loadRosterFlags()
 * to have run.
 *
 * @mixin \App\Models\Student
 */
class AssignmentStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = fn ($model) => $model->status === 'inactive' ? 'inactive' : 'active';

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'status' => $this->user ? $status($this->user) : 'inactive',
            'student_number' => $this->student_number,
            'course' => $this->course,
            'section' => $this->section,
            'company' => $this->company ? [
                'id' => $this->company->id,
                'company_name' => $this->company->company_name,
                'status' => $status($this->company),
            ] : null,
            'supervisor' => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'name' => $this->supervisor->name,
                'email' => $this->supervisor->email,
                'status' => $status($this->supervisor),
            ] : null,
            'supervisor_on_roster' => $this->supervisor_on_roster,
            'internship_status' => $this->internship_status,
            'internship_schedule' => $this->internship_schedule,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can_edit' => true,
            'can_toggle_status' => true,
        ];
    }
}
