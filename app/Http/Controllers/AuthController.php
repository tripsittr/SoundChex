<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Facades\Filament;
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

        return redirect()->intended($this->dashboardUrlFor(Auth::user()));
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

            $role = $this->safeOrganizationRole($invite->role);

            setPermissionsTeamId($organization->getKey());
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
            setPermissionsTeamId(null);

            $invite->forceFill([
                'accepted_at' => now(),
            ])->save();
        } else {
            $organization = Organization::create([
                'name' => $data['organization_name'],
            ]);

            $user->organizations()->syncWithoutDetaching([$organization->getKey()]);

            $defaultRole = $this->safeOrganizationRole(
                (string) config('organization_roles.default_self_signup_role', 'admin')
            );
            setPermissionsTeamId($organization->getKey());
            Role::findOrCreate($defaultRole, 'web');
            $user->assignRole($defaultRole);
            setPermissionsTeamId(null);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect($this->dashboardUrlFor($user, $organization));
    }

    /**
     * Build the post-auth landing URL: the customer panel dashboard scoped to
     * the user's organization. Falls back to the panel root (which lets
     * Filament resolve/select a tenant) when no specific organization applies.
     */
    protected function dashboardUrlFor(User $user, ?Organization $tenant = null): string
    {
        $panel = Filament::getPanel('customer');

        $tenant ??= $user->organizations()->first();

        return $panel->getUrl($tenant) ?? $panel->getUrl();
    }

    /**
     * Resolve a requested organization role to one that is safe to grant within
     * a tenant. Protected platform roles (e.g. super_admin) and unknown roles
     * are never assignable here — they fall back to the default signup role.
     */
    protected function safeOrganizationRole(?string $requested): string
    {
        $assignable = array_keys(config('organization_roles.assignable', []));
        $fallback = (string) config('organization_roles.default_self_signup_role', 'admin');

        return in_array($requested, $assignable, true) ? $requested : $fallback;
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
