<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(): Response
    {
        $user = Auth::user();

        $notifications = $user->notifications()
            ->paginate(15)
            ->through(fn (Notification $notification) => $this->notifications->present($notification, $user));

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    /**
     * Click-through for a notification the viewer owns: mark it read, then
     * send them to the page it's about (resolved for their current role).
     */
    public function open(Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === Auth::id(), 403);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return redirect()->to($this->notifications->urlFor($notification, Auth::user()));
    }

    public function markRead(Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === Auth::id(), 403);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return back();
    }

    public function markAllRead(): RedirectResponse
    {
        Auth::user()->notifications()->unread()->update(['read_at' => now()]);

        return back();
    }
}
