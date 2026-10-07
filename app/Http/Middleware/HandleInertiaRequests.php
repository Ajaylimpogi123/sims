<?php

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'pendingApprovalsCount' => fn () => in_array($request->user()?->role_id, [3, 4], true)
                ? \App\Models\Attendance::query()
                    ->where(fn ($query) => $query
                        ->where('time_in_status', 'pending')
                        ->orWhere('time_out_status', 'pending'))
                    ->when(
                        $request->user()->role_id === 3,
                        fn ($query) => $query->whereHas(
                            'student',
                            fn ($q) => $q->where('supervisor_id', $request->user()->id),
                        ),
                    )
                    ->count()
                : 0,
            'unreadNotificationsCount' => fn () => $request->user()
                ? $request->user()->notifications()->unread()->count()
                : 0,
            'recentNotifications' => fn () => $request->user()
                ? app(NotificationService::class)->withUrls(
                    $request->user()->notifications()->limit(5)->get(),
                    $request->user(),
                )
                : [],
        ];
    }
}
