<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the live photo a student captured on a time-in / time-out leg.
 * Photos live on the private disk and are never linked to directly, so this
 * is the only way to view them — scoping is enforced by AttendancePolicy::view:
 * Student = own records, Supervisor = own students, Coordinator/Admin = all.
 */
class AttendancePhotoController extends Controller
{
    public function __invoke(Attendance $attendance, string $leg): StreamedResponse
    {
        abort_unless(in_array($leg, ['time_in', 'time_out'], true), 404);

        $this->authorize('view', $attendance);

        $path = $attendance->{"{$leg}_photo_path"};

        abort_unless($path && Storage::disk(AttendanceController::PHOTO_DISK)->exists($path), 404);

        return Storage::disk(AttendanceController::PHOTO_DISK)->response($path);
    }
}
