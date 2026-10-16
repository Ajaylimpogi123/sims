<?php

namespace App\Http\Resources;

use App\Support\TermGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One row of a student's log in Attendance Monitoring: the Attendance
 * object plus the website's "Recorded By" column and what the signed-in
 * user may do with the entry. Set the `student` relation to avoid a query
 * per row.
 *
 * @mixin \App\Models\Attendance
 */
class MonitoringAttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gate = Gate::forUser($request->user());
        $writable = $this->student === null || TermGuard::isWritable($this->student, $request->user());
        $studentUserId = $this->student?->user_id;

        return [
            ...(new AttendanceResource($this->resource))->toArray($request),
            // The website shows "Self" when the student's own account
            // recorded the row (a self-service leg), otherwise "Staff".
            'recorded_by' => $studentUserId !== null && (int) $this->recorded_by === (int) $studentUserId ? 'self' : 'staff',
            // Past terms are read-only but for the Administrator.
            'can_edit' => $gate->allows('update', $this->resource) && $writable,
            'can_delete' => $gate->allows('delete', $this->resource) && $writable,
        ];
    }
}
