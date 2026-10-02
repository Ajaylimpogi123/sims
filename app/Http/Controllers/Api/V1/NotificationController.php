<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's own notification inbox (every role). Read/unread
 * rules and the tap destination are shared with the website through
 * NotificationService.
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Newest first, cursor-paginated so items arriving while the user
     * scrolls never shift a page and show up twice.
     */
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $page = $user->notifications()
            ->when($request->onlyUnread(), fn ($query) => $query->unread())
            ->cursorPaginate($request->perPage());

        return response()->json([
            'data' => NotificationResource::collection($page->items())->resolve($request),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->unreadNotificationsCount(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Scoped lookup: someone else's notification is a 404, same as one
        // that doesn't exist, so ids can't be probed.
        $model = $user->notifications()->whereKey($notification)->firstOrFail();

        $this->notifications->markRead($model);

        return response()->json([
            'notification' => (new NotificationResource($model))->resolve($request),
            'unread_count' => $user->unreadNotificationsCount(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'marked' => $this->notifications->markAllRead($user),
            'unread_count' => $user->unreadNotificationsCount(),
        ]);
    }
}
