<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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
 * Lists the profiles on an account, for the native app's sign-in.
 *
 * A token is bound to one profile (see TokenController), and the token endpoint
 * needs a `profile_id` the app cannot know before it has authenticated at all.
 * So sign-in is two steps: this verifies the credentials and returns the
 * account's profiles for the user to choose from, then the app mints a token for
 * the chosen one.
 *
 * Deliberately reveals only what the picker needs — a name, whether it is a kids
 * profile, whether it is locked — never the PIN or the rating cap. It is the
 * same information the web profile picker shows before you are past the door.
 */
class ProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureNotRateLimited($request, $data['email']);

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request, $data['email']));

            // One message for both cases, so this cannot be used to discover
            // which addresses have accounts — the same rule TokenController uses.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $data['email']));

        $profiles = Profile::where('user_id', $user->id)
            ->orderByDesc('is_owner')
            ->orderBy('name')
            ->get()
            ->map(fn (Profile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_owner' => (bool) $profile->is_owner,
                'is_kids' => (bool) $profile->is_kids,
                // Whether the app must ask for a PIN before minting the token.
                // The PIN itself never leaves the server.
                'requires_pin' => $profile->requiresPin(),
                // For the picker's avatar: the profile's colour, its initial for
                // the fallback tile, and an avatar image URL when it has one.
                'color' => $profile->color,
                'initial' => $profile->initial(),
                'avatar_url' => $profile->avatarUrl(),
            ]);

        return response()->json(['profiles' => $profiles]);
    }

    /**
     * The account's profiles, for a *signed-in* device — used to switch profile.
     *
     * Unlike index(), this needs no password: the bearer token already proves
     * whose account this is. It is the list the in-app switcher shows.
     */
    public function mine(Request $request): JsonResponse
    {
        $profiles = Profile::where('user_id', $request->user()->id)
            ->orderByDesc('is_owner')
            ->orderBy('name')
            ->get()
            ->map(fn (Profile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_owner' => (bool) $profile->is_owner,
                'is_kids' => (bool) $profile->is_kids,
                'requires_pin' => $profile->requiresPin(),
                'color' => $profile->color,
                'initial' => $profile->initial(),
                'avatar_url' => $profile->avatarUrl(),
            ]);

        return response()->json(['profiles' => $profiles]);
    }

    /**
     * Switches the signed-in device to another of the account's profiles.
     *
     * No password: the current token proves this is the account owner, and a
     * profile is a choice within an account rather than a separate login. A PIN
     * is still enforced where the target profile requires one — it is the wall
     * between profiles, not between accounts. Issues a fresh token for the new
     * profile and revokes the current one, so the device holds exactly one.
     */
    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile_id' => ['required', 'integer'],
            'pin' => ['nullable', 'string', 'max:6'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = $request->user();

        $profile = Profile::where('user_id', $user->id)->find($data['profile_id']);

        if ($profile === null) {
            throw ValidationException::withMessages([
                'profile_id' => 'That profile does not exist on this account.',
            ]);
        }

        if ($profile->requiresPin() && ! $profile->verifyPin($data['pin'] ?? '')) {
            throw ValidationException::withMessages([
                'pin' => $profile->pinIsLocked()
                    ? 'Too many attempts. Try again in a few minutes.'
                    : 'That PIN is not right.',
            ]);
        }

        $token = $user->createToken($data['device_name'], ['profile:' . $profile->id]);

        // Drop the token that made this request: the device is moving to the new
        // profile, and leaving the old one live would be a second key to a
        // profile the user has stepped out of.
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'token' => $token->plainTextToken,
            'profile' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'is_owner' => (bool) $profile->is_owner,
                'max_rating' => $profile->max_rating,
            ],
        ]);
    }

    /**
     * The same guess-limit the token endpoint has: an endpoint that confirms a
     * password (by returning profiles rather than an error) is as much a login
     * as the one that returns a token, and must be as hard to brute-force.
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
        return 'api-profiles:' . mb_strtolower($email) . '|' . $request->ip();
    }
}
