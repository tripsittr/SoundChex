<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Resolves whose library is being viewed.
 *
 * Everything that was per-user — history, resume points, highlights — becomes
 * per-profile. Rather than rewriting every call site, this is the single place
 * that answers "who is this", and the models ask it.
 *
 * An account always has at least one profile: the first is created on demand
 * from the account's own name, so a fresh install behaves exactly as it did
 * before profiles existed.
 */
class CurrentProfile
{
    private const SESSION_KEY = 'profile_id';

    private ?Profile $resolved = null;

    /**
     * The profile in use, creating a default if the account has none.
     */
    public function get(): ?Profile
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        $id = Session::get(self::SESSION_KEY);

        if ($id !== null) {
            // Scoped to the account: a stale session id from another login
            // must not resolve to someone else's profile.
            $profile = Profile::where('user_id', $user->id)->find($id);

            if ($profile !== null) {
                return $this->resolved = $profile;
            }
        }

        return $this->resolved = $this->defaultFor($user);
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    /**
     * Switches profile for this session.
     *
     * A PIN is required where one is set. The household shares a login, so
     * without this a member could simply pick the owner's profile from the
     * menu and inherit every right it holds — the permission would be a label
     * rather than a boundary.
     *
     * Returns false when the profile is not on this account, when a PIN is
     * required and wrong, or when too many attempts have locked it.
     */
    public function switchTo(int $profileId, ?string $pin = null): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        $profile = Profile::where('user_id', $user->id)->find($profileId);

        if ($profile === null) {
            return false;
        }

        if ($profile->requiresPin() && ! $profile->verifyPin((string) $pin)) {
            return false;
        }

        Session::put(self::SESSION_KEY, $profile->id);

        $profile->forceFill(['last_used_at' => now()])->saveQuietly();

        $this->resolved = $profile;

        // Anything already holding a resolved profile — a MediaBrowser or
        // ContentGate built earlier in the request — would keep scoping to the
        // previous one. Clearing them means the next resolve sees the switch.
        app()->forgetInstance(ContentGate::class);
        app()->forgetInstance(MediaBrowser::class);

        return true;
    }

    public function forget(): void
    {
        Session::forget(self::SESSION_KEY);

        $this->resolved = null;
    }

    /**
     * Every profile on the signed-in account.
     *
     * @return \Illuminate\Support\Collection<int, Profile>
     */
    public function all()
    {
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        $profiles = Profile::where('user_id', $user->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $profiles->isEmpty()
            ? collect([$this->defaultFor($user)])
            : $profiles;
    }

    /**
     * Whether the current profile is restricted.
     *
     * Used to hide the admin link and cap what's browsable. Not a security
     * boundary — the admin panel enforces its own permissions — but it keeps
     * a child from wandering into it.
     */
    public function isKids(): bool
    {
        return $this->get()?->is_kids ?? false;
    }

    /**
     * The account's default profile, created on first use.
     *
     * Existing installs have history keyed to the user with no profile at all;
     * this adopts it rather than stranding it, so upgrading loses nothing.
     */
    private function defaultFor(User $user): Profile
    {
        $existing = Profile::where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Profile::create([
            'user_id' => $user->id,
            'name' => $user->name ?: 'Me',
            'color' => Profile::COLORS[0],
            'is_default' => true,
        ]);
    }
}
