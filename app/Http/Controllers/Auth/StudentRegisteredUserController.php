<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class StudentRegisteredUserController extends Controller
{
    private const STUDENT_ROLE_ID = 1;

    public function __construct(private NotificationService $notifications) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'student_number' => ['required', 'string', 'max:50', 'unique:students,student_number'],
            'course' => ['required', 'string', 'max:255'],
            'section' => ['required', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role_id' => self::STUDENT_ROLE_ID,
            'status' => 'active',
        ]);

        Student::create([
            'user_id' => $user->id,
            'student_number' => $validated['student_number'],
            'course' => $validated['course'],
            'section' => $validated['section'],
        ]);

        event(new Registered($user));

        $this->notifications->studentRegistered($user, $validated['student_number']);

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
