<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a leg's evidence photo from the private disk to the app (any
 * role), scoped by AttendancePolicy::view: Student = own records,
 * Supervisor = own students, Coordinator/Admin = all. Unlike the website
 * (403), a record the user may not see is a 404 so ids can't be probed.
 */
class AttendancePhotoController extends Controller
{
    public function __invoke(Request $request, Attendance $attendance, string $leg): StreamedResponse
    {
        abort_unless(in_array($leg, ['time_in', 'time_out'], true), 404);
        abort_if(Gate::forUser($request->user())->denies('view', $attendance), 404);

        $path = $attendance->{"{$leg}_photo_path"};
        $disk = Storage::disk(AttendanceService::PHOTO_DISK);

        abort_unless($path && $disk->exists($path), 404);

        // The URL changes with the file (?v= fingerprint), so the app may
        // cache it; "private" keeps shared caches from storing it.
        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
