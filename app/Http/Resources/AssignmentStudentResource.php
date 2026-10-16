<?php

namespace App\Http\Resources;

use App\Support\TermGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Internship Assignment row (a student record). Expects user, company,
 * supervisor and term loaded and InternshipAssignmentService::loadSupervisorFlags()
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
            'needs_supervisor' => (bool) $this->needs_supervisor,
            // null | no_supervisor | not_a_supervisor | inactive
            'supervisor_issue' => $this->supervisor_issue,
            'internship_status' => $this->internship_status,
            // null = not set; needed before the status can become ongoing.
            'required_hours' => $this->required_hours === null ? null : (int) $this->required_hours,
            'internship_schedule' => $this->internship_schedule,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'term_id' => (int) $this->term_id,
            'term' => $this->term?->summary(),
            // Past terms are read-only but for the Administrator.
            'can_edit' => $writable = TermGuard::isWritable($this->resource, $request->user()),
            'can_toggle_status' => $writable,
        ];
    }
}
