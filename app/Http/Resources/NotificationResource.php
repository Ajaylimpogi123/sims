<?php

namespace App\Http\Resources;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One notification as the mobile app sees it. The viewer is always the
 * signed-in user (the API only ever returns a user's own notifications),
 * and `target` is the app-side counterpart of the web's click-through URL.
 *
 * @mixin \App\Models\Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array{id: int, type: string, title: string, message: string|null, read_at: string|null, created_at: string|null, target: array{screen: string, params: array<string, int>}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->body,
            'read_at' => self::iso($this->read_at),
            'created_at' => self::iso($this->created_at),
            'target' => app(NotificationService::class)->targetFor($this->resource, $request->user()),
        ];
    }

    /**
     * ISO 8601 in the app timezone, e.g. 2026-10-02T14:03:00+08:00.
     */
    private static function iso(?Carbon $value): ?string
    {
        return $value?->copy()->setTimezone(config('app.timezone'))->toIso8601String();
    }
}
