<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One attendance row (a day) as the mobile app sees it: both legs with
 * their status, evidence (GPS fix, fake-GPS flag, photo URL) and the
 * credited hours. Field names match the attendances columns and the
 * website's props; `*_photo_url` points at the API photo endpoint, which
 * re-checks AttendancePolicy::view on every request.
 *
 * @mixin \App\Models\Attendance
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $date = $this->date?->format('Y-m-d');

        return [
            'id' => $this->id,
            'date' => $date,
            ...$this->leg('time_in', $date),
            ...$this->leg('time_out', $date),
            'rendered_hours' => $this->rendered_hours !== null ? round((float) $this->rendered_hours, 2) : null,
            'is_emergency' => (bool) $this->is_emergency,
            'note' => $this->note,
        ];
    }

    /**
     * @param  'time_in'|'time_out'  $leg
     */
    private function leg(string $leg, ?string $date): array
    {
        return [
            $leg => self::dateTime($date, $this->{$leg}),
            "{$leg}_status" => $this->{"{$leg}_status"},
            "{$leg}_rejection_reason" => $this->{"{$leg}_rejection_reason"},
            "{$leg}_latitude" => self::number($this->{"{$leg}_latitude"}),
            "{$leg}_longitude" => self::number($this->{"{$leg}_longitude"}),
            "{$leg}_accuracy" => self::number($this->{"{$leg}_accuracy"}),
            "{$leg}_mocked" => $this->{"{$leg}_mocked"},
            "{$leg}_photo_url" => $this->photoUrl($leg),
        ];
    }

    /**
     * The URL carries a short fingerprint of the stored file, so it changes
     * when a rejected leg is re-submitted with a new photo and the app can
     * cache images by URL.
     */
    private function photoUrl(string $leg): ?string
    {
        $path = $this->{"{$leg}_photo_path"};

        if (! $path) {
            return null;
        }

        return route('api.v1.attendance.photo', [
            'attendance' => $this->id,
            'leg' => $leg,
            'v' => substr(sha1($path), 0, 12),
        ]);
    }

    private static function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * A local (app timezone) date + TIME column as ISO 8601 with offset.
     */
    private static function dateTime(?string $date, ?string $time): ?string
    {
        if ($date === null || $time === null || $time === '') {
            return null;
        }

        return Carbon::parse("{$date} {$time}", config('app.timezone'))->toIso8601String();
    }
}
