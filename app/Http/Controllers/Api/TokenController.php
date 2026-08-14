<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Issues and revokes API tokens for the native app.
 *
 * The app authenticates with a token rather than a session cookie: it runs
 * from a `tauri://` origin, so every request to the server is cross-origin and
 * a session cookie would be subject to CORS and SameSite rules that no amount
 * of configuration makes reliable across platforms.
 *
 * **A token names one profile and cannot change it.** The profile is baked in
 * as a Sanctum ability at issue time, so a client cannot ask for a different
 * one — see CurrentProfile, which reads it back. Without that the API would
 * fall through to the account's default profile and hand a capped device the
 * entire uncapped library in a single sync.
 */
class TokenController extends Controller
{
    /**
     * Exchanges credentials for a token bound to one profile.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'profile_id' => ['required', 'integer'],
            'pin' => ['nullable', 'string', 'max:6'],
            // Shown in the token list so a lost device can be identified and
            // revoked without guessing which row it is.
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $this->ensureNotRateLimited($request, $data['email']);

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request, $data['email']));

            // One message for both cases: distinguishing them tells an
            // attacker which addresses have accounts.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $profile = Profile::where('user_id', $user->id)->find($data['profile_id']);

        if ($profile === null) {
            throw ValidationException::withMessages([
                'profile_id' => 'That profile does not exist on this account.',
            ]);
        }

        // The PIN is enforced here exactly as it is in the web picker. A token
        // is longer-lived than a session, so skipping it would make the API
        // the easy way around a PIN rather than a parallel path to it.
        if ($profile->requiresPin() && ! $profile->verifyPin($data['pin'] ?? '')) {
            RateLimiter::hit($this->throttleKey($request, $data['email']));

            throw ValidationException::withMessages([
                'pin' => $profile->pinIsLocked()
                    ? 'Too many attempts. Try again in a few minutes.'
                    : 'That PIN is not right.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $data['email']));

        $token = $user->createToken(
            $data['device_name'],
            ['profile:' . $profile->id],
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_owner' => (bool) $profile->is_owner,
                'max_rating' => $profile->max_rating,
            ],
        ], 201);
    }

    /**
     * Revokes the token that made this request — signing out one device.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['revoked' => true]);
    }

    /**
     * Whoever the token belongs to, for the app to confirm it is still valid.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = app(\App\Services\CurrentProfile::class)->get();

        return response()->json([
            'user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'profile' => $profile === null ? null : [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_owner' => (bool) $profile->is_owner,
                'max_rating' => $profile->max_rating,
            ],
        ]);
    }

    /**
     * Same protection the web login has: a token endpoint that accepts
     * unlimited guesses is a longer-lived way in than the form it mirrors.
     */
    private function ensureNotRateLimited(Request $request, string $email): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($request, $email), 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in a minute.',
            ]);
        }
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'api-token:' . mb_strtolower($email) . '|' . $request->ip();
    }
}
