<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(): Response
    {
        $notifications = Auth::user()->notifications()->paginate(15);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
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
