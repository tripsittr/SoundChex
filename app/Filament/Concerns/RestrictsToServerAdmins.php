<?php

namespace App\Filament\Concerns;

use App\Services\CurrentProfile;

/**
 * Gates the screens that administer the *server* rather than the library.
 *
 * The admin panel does two jobs. Most of it is about content — music, films,
 * profiles, settings, statistics — and belongs to anyone trusted to run the
 * household's library. A smaller part is about the machine underneath it:
 * starting and stopping services, moving a server to another computer, reading
 * what devices reported going wrong, and the network addresses clients race.
 *
 * Those are a different kind of dangerous. `Services` can stop the web server
 * that serves this page; `ServerTransfer` copies 46 GB between machines and
 * deletes on failure. Someone administering a library has no reason to reach
 * them, and reaching them by accident is expensive.
 *
 * **One permission for the group, not four.** The four pages are a single
 * decision — "is this person responsible for the machine?" — and four separate
 * permissions is four chances to grant three of them and wonder why the fourth
 * is missing.
 *
 * The owner short-circuits, as everywhere: a household that could lock its own
 * administrator out of the services page would need database surgery to fix.
 */
trait RestrictsToServerAdmins
{
    public static function canAccess(): bool
    {
        $profile = app(CurrentProfile::class)->get();

        if ($profile === null) {
            return false;
        }

        return $profile->can(\App\Models\Profile::SERVER_ADMINISTRATION);
    }

    /**
     * Hidden from the sidebar as well as refused.
     *
     * Hiding alone is not access control — Filament resolves a page by URL
     * whether or not anything links to it, which is why `canAccess()` above is
     * the part that actually refuses. This only stops the navigation offering
     * a door that will not open.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
