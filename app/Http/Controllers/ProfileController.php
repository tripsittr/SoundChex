<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Services\CurrentProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Viewing profiles.
 *
 * Switching is deliberately not a security boundary — it's the same
 * convenience every streaming service offers, so a household doesn't have to
 * share one Continue Watching row. Anything that needs enforcing stays on the
 * account.
 */
class ProfileController extends Controller
{
    /** The "who's watching?" screen. */
    public function index(CurrentProfile $profiles): View
    {
        return view('profiles.index', [
            'profiles' => $profiles->all(),
            'current' => $profiles->get(),
        ]);
    }

    public function switch(Request $request, CurrentProfile $profiles): RedirectResponse
    {
        $data = $request->validate([
            'profile_id' => ['required', 'integer'],
        ]);

        // Fails closed: a profile on another account is simply not switched to.
        if (! $profiles->switchTo((int) $data['profile_id'])) {
            return back()->withErrors(['profile_id' => 'That profile is not available.']);
        }

        return redirect()->route('media.home');
    }

    public function store(Request $request, CurrentProfile $profiles): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'in:' . implode(',', Profile::COLORS)],
            'is_kids' => ['nullable', 'boolean'],
        ]);

        $profile = Profile::create([
            'user_id' => Auth::id(),
            'name' => $data['name'],
            'color' => $data['color'] ?? Profile::COLORS[0],
            'is_kids' => (bool) ($data['is_kids'] ?? false),
            // A kids profile is capped by default; without one the flag would
            // only hide the admin link, which is not what anyone means by it.
            'max_rating' => ($data['is_kids'] ?? false) ? 'PG' : null,
            'sort_order' => Profile::where('user_id', Auth::id())->max('sort_order') + 1,
        ]);

        $profiles->switchTo($profile->id);

        return redirect()->route('media.home');
    }

    public function destroy(Profile $profile, CurrentProfile $profiles): RedirectResponse
    {
        abort_unless($profile->user_id === Auth::id(), 404);

        // An account must always have somewhere to land.
        if (Profile::where('user_id', Auth::id())->count() <= 1) {
            return back()->withErrors(['profile' => 'An account needs at least one profile.']);
        }

        $wasCurrent = $profiles->id() === $profile->id;

        $profile->delete();

        if ($wasCurrent) {
            $profiles->forget();
        }

        return redirect()->route('profiles.index');
    }
}
