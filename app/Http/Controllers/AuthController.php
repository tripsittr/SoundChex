<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\View\View;

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

        if (! Auth::attempt($credentials, (bool) $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'The provided credentials are incorrect.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(Request $request, ?string $token = null): View
    {
        $inviteToken = $token ?? $request->query('invite');
        $invite = null;

        if ($inviteToken) {
            $invite = OrganizationInvite::query()
                ->where('token', $inviteToken)
                ->whereNull('accepted_at')
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();
        }

        return view('auth.register', [
            'invite' => $invite,
            'inviteToken' => $inviteToken,
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $inviteToken = $request->input('invite_token');
        $invite = null;

        if ($inviteToken) {
            $invite = OrganizationInvite::query()
                ->where('token', $inviteToken)
                ->whereNull('accepted_at')
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        if (! $invite) {
            $rules['organization_name'] = ['required', 'string', 'max:255'];
        }

        $data = $request->validate($rules);

        if ($invite && strtolower($data['email']) !== strtolower($invite->email)) {
            return back()->withErrors([
                'email' => 'This invite is only valid for '.$invite->email.'.',
            ])->withInput();
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        if ($invite) {
            $organization = $invite->organization;
            $user->organizations()->syncWithoutDetaching([$organization->getKey()]);

            setPermissionsTeamId($organization->getKey());
            Role::findOrCreate($invite->role, 'web');
            $user->assignRole($invite->role);
            setPermissionsTeamId(null);

            $invite->forceFill([
                'accepted_at' => now(),
            ])->save();
        } else {
            $organization = Organization::create([
                'name' => $data['organization_name'],
            ]);

            $user->organizations()->syncWithoutDetaching([$organization->getKey()]);

            $defaultRole = (string) config('organization_roles.default_self_signup_role', 'organization_admin');
            setPermissionsTeamId($organization->getKey());
            Role::findOrCreate($defaultRole, 'web');
            $user->assignRole($defaultRole);
            setPermissionsTeamId(null);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
