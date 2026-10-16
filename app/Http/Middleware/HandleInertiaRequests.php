<?php

namespace App\Http\Middleware;

use App\Models\Term;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\TermContext;
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
                    ->inTerm(TermContext::id($request))
                    ->count()
                : 0,
            // Terms (owner feature, 2026-10-16): every term for the header
            // switcher (newest first), the current one, the one the lists
            // show (a Student's own term) and whether that one is archived,
            // i.e. before the current term (termReadOnly: archived and not an
            // Administrator, so edit actions are hidden). Upcoming terms are
            // open.
            'terms' => fn () => $request->user()
                ? Term::query()->newestFirst()->get()->map(fn (Term $term) => $term->summary())->values()
                : [],
            'currentTerm' => fn () => ($current = Term::current())
                ? ['id' => $current->id, 'label' => $current->label]
                : null,
            'selectedTerm' => fn () => ($selected = $this->selectedTerm($request))
                ? $selected->summary()
                : null,
            'termArchived' => fn () => (bool) $this->selectedTerm($request)?->isPast(),
            'termReadOnly' => fn () => $this->selectedTerm($request) !== null
                && $this->selectedTerm($request)->isPast()
                && ! $request->user()?->hasRole(User::ROLE_ADMIN),
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

    /**
     * The term the signed-in user's lists show; null for guests and for a
     * Student without a student record.
     */
    private function selectedTerm(Request $request): ?Term
    {
        $user = $request->user();

        if ($user === null || ($user->hasRole(User::ROLE_STUDENT) && $user->student === null)) {
            return null;
        }

        return TermContext::term($request);
    }
}
