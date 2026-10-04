<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One attendance row in Pending Approvals (Supervisor / Admin): the
 * Attendance object plus the student it belongs to, which legs are pending,
 * and what the signed-in reviewer may do with each leg. Load
 * `student.user` and `student.company` to avoid extra queries.
 *
 * @mixin Attendance
 */
class ApprovalResource extends JsonResource
{
    private const LEGS = ['time_in', 'time_out'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $student = $this->student;
        $company = $student?->company;
        $canReview = Gate::forUser($request->user())->allows('review', $this->resource);

        return [
            ...(new AttendanceResource($this->resource))->toArray($request),
            'student' => $student ? [
                'id' => $student->id,
                'name' => $student->user?->name,
                'student_number' => $student->student_number,
                'company' => $company ? ['id' => $company->id, 'name' => $company->company_name] : null,
            ] : null,
            'pending_legs' => array_values(array_filter(
                self::LEGS,
                fn (string $leg) => $this->{"{$leg}_status"} === 'pending',
            )),
            'review' => collect(self::LEGS)->mapWithKeys(function (string $leg) use ($canReview) {
                $allowed = $canReview && AttendanceService::isReviewable($this->resource, $leg);

                return [$leg => ['can_approve' => $allowed, 'can_reject' => $allowed]];
            })->all(),
        ];
    }
}
