<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMobileAppAccess;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\StudentProfileResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** bcrypt (cost 12) of a random string; never matches a real password. */
    private const TIMING_DUMMY_HASH = '$2y$12$aQ4vPeY6PHlcEhKO2HgvqO6PmHN1W0RtzweXJPRk9scgRot8BnQoW';

    /**
     * Exchange email + password for a personal access token.
     *
     * Any active account with a SIMS role (1-4) may use the app. Wrong email
     * and wrong password produce the identical 422 so the response never
     * reveals whether an account exists; a missing role or inactive status
     * is only disclosed (403) after the password has been verified.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::with('role')
            ->where('email', $request->validated('email'))
            ->first();

        // For an unknown email, still run one bcrypt verification (against a
        // fixed dummy hash) so both failure paths take about the same time.
        $passwordMatches = Hash::check(
            $request->validated('password'),
            $user?->password ?? self::TIMING_DUMMY_HASH,
        );

        if ($user === null || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        if (! $user->canUseMobileApp()) {
            return EnsureMobileAppAccess::deny(EnsureMobileAppAccess::CODE_ROLE_NOT_ALLOWED);
        }

        if (! $user->isActive()) {
            return EnsureMobileAppAccess::deny(EnsureMobileAppAccess::CODE_ACCOUNT_INACTIVE);
        }

        $token = $user->createToken($request->validated('device_name'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Revoke only the token used for this request; the user's other devices
     * stay signed in.
     */
    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('role');

        $student = null;
        $supervisor = null;

        // Coordinator / Administrator: permission flags for navigation.
        $staff = $user->staffPermissions();

        if ((int) $user->role_id === 1) {
            $profile = $user->student()->with(['company', 'supervisor'])->first();
            $student = $profile ? new StudentProfileResource($profile) : null;
        }

        if ((int) $user->role_id === 3) {
            $supervisor = [
                'students_count' => $user->supervisedStudents()->count(),
            ];
        }

        return response()->json([
            'user' => new UserResource($user),
            'student' => $student,
            'supervisor' => $supervisor,
            'staff' => $staff,
        ]);
    }
}
