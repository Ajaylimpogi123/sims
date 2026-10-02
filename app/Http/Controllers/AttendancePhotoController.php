<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the live photo a student captured on a time-in / time-out leg.
 * Photos live on the private disk and are never linked to directly, so this
 * is the only way to view them — scoping is enforced here:
 * Student = own records, Supervisor = own students, Coordinator/Admin = all.
 */
class AttendancePhotoController extends Controller
{
    public function __invoke(Attendance $attendance, string $leg): StreamedResponse
    {
        abort_unless(in_array($leg, ['time_in', 'time_out'], true), 404);

        $user = Auth::user();

        match ($user->role_id) {
            1 => abort_unless(
                $user->student && $attendance->student_id === $user->student->id,
                403
            ),
            3 => abort_unless($attendance->student?->supervisor_id === $user->id, 403),
            2, 4 => null,
            default => abort(403),
        };

        $path = $attendance->{"{$leg}_photo_path"};

        abort_unless($path && Storage::disk(AttendanceController::PHOTO_DISK)->exists($path), 404);

        return Storage::disk(AttendanceController::PHOTO_DISK)->response($path);
    }
}
