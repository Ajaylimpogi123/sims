<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request (User Management "create").
     * Students may only originate via the separate self-registration flow
     * (StudentRegisteredUserController), which also creates the matching
     * Student profile row, so Student is never assignable here; see
     * UserManagementService.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, UserManagementService $users): RedirectResponse
    {
        $users->create($request->validate($users->createRules($request->user())));

        if (Auth::check()) {
            return redirect(route('user-management.index', absolute: false))
                ->with('success', 'User registered successfully.');
        }

        return redirect(route('login', absolute: false));
    }
}
