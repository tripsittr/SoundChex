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

    /**
     * When the PIN on this profile was last proven.
     *
     * The session outlives the app being closed — deliberately, so nobody has
     * to sign in again to play music — which means the profile alone is no
     * longer evidence that the person holding the phone is the one who unlocked
     * it. This is what the lock screen checks.
     */
    private const UNLOCKED_KEY = 'profile_unlocked_at';

    /**
     * How long a PIN entry lasts.
     *
     * Long enough that using the app is not an interrogation, short enough that
     * a phone left on a table is not an open door. Twelve hours means one entry
     * covers a day's listening and the next morning asks again.
     */
    private const UNLOCK_MINUTES = 720;

    private ?Profile $resolved = null;

    /**
     * Who `$resolved` was resolved *for*.
     *
     * This is a singleton, so the cache outlives a change of identity — and a
     * cached profile handed to a different viewer is a capped device seeing an
     * uncapped library. The account and the session's profile id live here;
     * the token is compared separately, in `$resolvedToken`.
     */
    private ?string $resolvedFor = null;

    /**
     * The access token `$resolved` was resolved under, held rather than keyed.
     *
     * Two tokens on one account can name different profiles. Keeping the object
     * itself both distinguishes them and keeps it alive, so its identity cannot
     * be recycled onto a different token while this cache still trusts it.
     */
    private ?object $resolvedToken = null;

    /**
     * The profile in use, creating a default if the account has none.
     */
    public function get(): ?Profile
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        // The token is held rather than reduced to a key.
        //
        // Two tokens on one account can name different profiles, so the account
        // alone is not enough to tell them apart. The token's *id* is not
        // enough either: Sanctum's test double reports the same id (`false`)
        // for every one, which let a second profile read the first's cached
        // answer — the leak `test_two_profiles_do_not_share_a_cache_entry`
        // exists to catch, and a capped device seeing an uncapped library.
        //
        // Comparing the object itself rather than `spl_object_id()`, because
        // that id is reused once an object is freed: a token released and
        // another allocated in its place would match a key it never wrote.
        // Holding a reference also keeps the object alive, so there is no
        // window in which reuse could happen.
        $token = $user->currentAccessToken();

        $key = implode('|', [
            $user->getAuthIdentifier(),
            Session::get(self::SESSION_KEY) ?? '-',
        ]);

        if ($this->resolved !== null && $this->resolvedFor === $key && $this->resolvedToken === $token) {
            return $this->resolved;
        }

        $this->resolvedFor = $key;
        $this->resolvedToken = $token;

        // A token request has no session, so without this the API would fall
        // through to defaultFor() — the owner — and hand a capped profile the
        // entire uncapped library. The profile is baked into the token as an
        // ability at issue time, so a client cannot claim a different one.
        if ($user->currentAccessToken() !== null) {
            // Asked through tokenCan() rather than by reading the abilities
            // array: that array is not populated on the token double Sanctum
            // uses in tests, so parsing it silently resolved to null and no
            // cap applied — a leak that only showed up because the test
            // asserted the payload rather than the plumbing.
            //
            // Candidates come from this account's own profiles, so a token
            // cannot name one belonging to someone else.
            $profile = Profile::where('user_id', $user->id)
                ->get()
                ->first(fn (Profile $candidate) => $user->tokenCan('profile:' . $candidate->id));

            // Fails closed: a token naming a profile that no longer exists
            // resolves to nothing rather than to a default with more access
            // than the token was ever issued for.
            return $this->resolved = $profile;
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
        Session::put(self::UNLOCKED_KEY, now()->timestamp);

        $profile->forceFill(['last_used_at' => now()])->saveQuietly();

        $this->resolved = $profile;
        // Cleared rather than recomputed: the session was just written, and
        // the next get() rebuilds the key from it. Leaving a stale key here
        // would let the *previous* profile's cache answer for this one.
        $this->resolvedFor = null;
        $this->resolvedToken = null;

        // Anything already holding a resolved profile — a MediaBrowser or
        // ContentGate built earlier in the request — would keep scoping to the
        // previous one. Clearing them means the next resolve sees the switch.
        app()->forgetInstance(ContentGate::class);
        app()->forgetInstance(MediaBrowser::class);

        return true;
    }

    /**
     * Whether this session still counts as unlocked.
     *
     * A profile with no PIN is always unlocked: there is nothing to prove. One
     * with a PIN stays unlocked for a while after it was entered, so moving
     * between pages does not ask repeatedly, but a phone picked up the next
     * morning asks again.
     */
    public function isUnlocked(): bool
    {
        $profile = $this->get();

        if ($profile === null || ! $profile->requiresPin()) {
            return true;
        }

        $at = Session::get(self::UNLOCKED_KEY);

        if (! is_int($at)) {
            return false;
        }

        return $at > now()->subMinutes(self::UNLOCK_MINUTES)->timestamp;
    }

    /** Requires the PIN again on the next request. */
    public function lock(): void
    {
        Session::forget(self::UNLOCKED_KEY);
    }

    public function forget(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::forget(self::UNLOCKED_KEY);

        $this->resolved = null;
        $this->resolvedFor = null;
        $this->resolvedToken = null;
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
