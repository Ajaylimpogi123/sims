<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One student in Attendance / Progress Monitoring: who they are, where
 * they are placed, their hours progress (the website's columns and progress
 * bar) and what the signed-in user may change. Query with
 * Student::monitoredBy() and load `user`, `company` and `supervisor`.
 *
 * @mixin \App\Models\Student
 */
class MonitoredStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rendered = round((float) $this->total_rendered_hours, 2);
        $required = $this->required_hours !== null ? (int) $this->required_hours : null;
        $canManage = Gate::forUser($request->user())->allows('manageAttendance', $this->resource);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'student_number' => $this->student_number,
            'course' => $this->course,
            'section' => $this->section,
            'internship_status' => $this->internship_status,
            'company' => $this->company ? ['id' => $this->company->id, 'name' => $this->company->company_name] : null,
            'supervisor' => $this->supervisor ? ['id' => $this->supervisor->id, 'name' => $this->supervisor->name] : null,
            'rendered_hours' => $rendered,
            'required_hours' => $required,
            'remaining_hours' => $required !== null ? round(max($required - $rendered, 0), 2) : null,
            'progress_percent' => self::percent($rendered, $required),
            'can_edit_attendance' => $canManage,
            'can_edit_required_hours' => $canManage,
        ];
    }

    /**
     * The website's progress bar: rendered / required as a whole
     * percentage capped at 100, 0 when required is 0, null when not set.
     */
    private static function percent(float $rendered, ?int $required): ?int
    {
        if ($required === null) {
            return null;
        }

        if ($required <= 0) {
            return 0;
        }

        return (int) min(round(($rendered / $required) * 100), 100);
    }
}
