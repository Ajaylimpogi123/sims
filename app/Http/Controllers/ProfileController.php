<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // users -> students -> attendances / internship_reports is an FK
        // cascade, so no model events fire. Snapshot the student's private
        // files now (the report rows are gone after the delete) and remove
        // them only once the delete has succeeded.
        $storedFiles = $user->student?->storedFiles();

        DB::transaction(function () use ($user) {
            // Same guard as User Management: the last active Administrator
            // can't remove themselves, or nobody could grant the role again.
            // Locking the admins makes two admins deleting at once safe.
            $current = User::query()->lockForUpdate()->find($user->id);

            if ($current?->hasRole(User::ROLE_ADMIN) && $current->isActive()) {
                $otherAdmins = User::query()
                    ->where('role_id', User::ROLE_ADMIN)
                    ->where('status', 'active')
                    ->whereKeyNot($user->id)
                    ->lockForUpdate()
                    ->exists();

                if (! $otherAdmins) {
                    throw ValidationException::withMessages([
                        'password' => UserManagementService::LAST_ADMIN_MESSAGE,
                    ]);
                }
            }

            Auth::logout();

            $user->delete();
        }, 3);

        if ($storedFiles) {
            Student::deleteStoredFiles($storedFiles);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
