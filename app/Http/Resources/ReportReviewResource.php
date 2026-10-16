<?php

namespace App\Http\Resources;

use App\Support\TermGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One report in Report Reviews (Coordinator / Supervisor / Admin): the
 * Report object plus the student it belongs to and whether the signed-in
 * user may review it right now (InternshipReportPolicy::review and still
 * pending). Load `reviewer:id,name`, `student.user:id,name` and
 * `student.company:id,company_name` to avoid extra queries.
 *
 * @mixin \App\Models\InternshipReport
 */
class ReportReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $student = $this->student;
        $company = $student?->company;

        return [
            ...(new InternshipReportResource($this->resource))->toArray($request),
            'student' => $student ? [
                'id' => $student->id,
                'user_id' => $student->user_id,
                'name' => $student->user?->name,
                'student_number' => $student->student_number,
                'course' => $student->course,
                'section' => $student->section,
                'company' => $company ? ['id' => $company->id, 'name' => $company->company_name] : null,
            ] : null,
            // Past terms are read-only but for the Administrator.
            'can_review' => $this->status === 'pending'
                && Gate::forUser($request->user())->allows('review', $this->resource)
                && ($student === null || TermGuard::isWritable($student, $request->user())),
        ];
    }
}
