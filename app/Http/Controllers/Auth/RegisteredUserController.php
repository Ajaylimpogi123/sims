<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    private const ADMIN_ROLE_ID = 4;

    private const STUDENT_ROLE_ID = 1;

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $viewerIsAdmin = (int) Auth::user()?->role_id === self::ADMIN_ROLE_ID;

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],

            'role_id' => [
                'required',
                'exists:roles,id',
                // Students may only originate via the separate self-registration
                // flow (StudentRegisteredUserController), which also creates the
                // matching Student profile row. This admin-driven flow never
                // collects student profile fields, so Student must never be
                // selectable here.
                Rule::notIn($viewerIsAdmin ? [self::STUDENT_ROLE_ID] : [self::STUDENT_ROLE_ID, self::ADMIN_ROLE_ID]),
            ],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),

            'role_id' => $request->role_id,
            'status' => 'active',
        ]);

        event(new Registered($user));

        if (Auth::check()) {
            return redirect(route('user-management.index', absolute: false))
                ->with('success', 'User registered successfully.');
        }

        return redirect(route('login', absolute: false));
    }
}
