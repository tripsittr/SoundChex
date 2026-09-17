<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Always remembered, the way a media app behaves. Signing in is a
        // one-off act of setting the device up, not something to repeat every
        // couple of hours — and a phone that asks for a password on the train
        // is a phone whose downloads may as well not exist. The PIN, not the
        // password, is what guards a profile day to day.
        if (! Auth::attempt($credentials, remember: true)) {
            return back()->withErrors([
                'email' => 'The provided credentials are incorrect.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('media.home'));
    }

    public function showRegister(): View
    {
        return view('auth.register', [
            'isFirstAccount' => $this->isFirstAccount(),
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        // Self-hosted: the person who sets the server up gets the keys, and
        // everyone after them is a library member until promoted.
        $isFirstAccount = $this->isFirstAccount();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $role = $isFirstAccount ? 'owner' : 'member';

        Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('media.home');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Whether nobody has registered yet — the very first account on a freshly
     * installed server.
     */
    private function isFirstAccount(): bool
    {
        return User::query()->doesntExist();
    }
}
