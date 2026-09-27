# Registration that is actually closed

Sign-up was open on every install that had never saved the setting — which is
every fresh install. The middleware was written to prevent exactly that and
believed it did.

## The guard never fired

`EnsureRegistrationIsOpen` documented itself as failing closed:

> Defaults to closed when the setting has never been saved: a server that might
> be public should not accept sign-ups because nobody has visited the settings
> page yet.

```php
return (bool) (Settings::getStoredSettings()['allow_registration'] ?? false);
```

`getStoredSettings()` fills every key from its own defaults, and that one was
`'allow_registration' => true`. So the key is never absent from the array, the
`??` never sees a null, and the `false` is dead code.

Measured on a real install before changing anything — nothing saved, and the
door open:

```
raw stored value                      NULL
allow_registration (resolved)         true
EnsureRegistrationIsOpen::isOpen()    true
GET /register                         200
```

The `settings` table held one row, `tmdb_api_key`. Nothing had ever written
`app_allow_registration`.

## Why it matters more than an unused toggle

The middleware's own comment names the threat: this server is meant to be
reachable from outside, and this project tunnels it. So a fresh, internet-facing
install accepted sign-ups from anyone who found the URL — and
`AuthController::register` gives the **first** account the `owner` role. A
stranger registering before the owner did would have owned the library.

## First run still works, by a different route

The permissive default existed for a real reason: with no users there is no admin
panel to add anyone from, so a strictly closed door cannot be opened. The gate
now answers that from the absence of accounts rather than from a setting:

```php
if (User::query()->doesntExist()) {
    return true;
}
```

Open on an empty install, shut the moment an account exists, and re-openable from
the settings page for a household that wants it. That is what the setting alone
could not express.

`Settings::getStoredSettings()` now defaults `allow_registration` to `false`, so
the documented behaviour and the actual behaviour agree.

## A link that pointed at a 404

`layouts/app.blade.php` offered **Register** unconditionally. The login page
already guarded its equivalent; this one did not, and with closed now the default
it would have sent people to a dead end. It is guarded the same way. Reachable on
one live page (`api/index.blade.php`), which is how it was found.

## Worth knowing

- **Existing installs that relied on the old default will find sign-up closed.**
  That is the intent. They have accounts, so they can reopen it from the settings
  page; nobody is locked out.
- **A stored `false` was always honoured** and still is. Only the never-saved
  case changes.
- No migration, no data rewritten.

## Still wrong

- `resources/views/emails/team-invitation.blade.php` links to `route('register')`
  and is dead scaffold — Fortify is not installed, no invitation routes exist,
  and nothing sends the mail. Left alone rather than half-fixed, but it should
  go: it describes a flow this app does not have.
- No tracker item. `Issues.md` now points at the SoundChexWebsite admin tracker
  and that repo is not on this machine, so this needs logging there by hand.

## Tests

Four new cases in `tests/Feature/RegistrationGateTest.php`, and the middle one is
the regression:

| | |
| --- | --- |
| first account, nothing saved | `/register` 200, account gets `owner` |
| an account exists, nothing saved | `/register` **404**, no user created |
| an account exists, setting `true` | `/register` 200, account gets `member` |
| an account exists, setting `false` | `/register` 404 |

Checked for teeth: restoring `'allow_registration' => true` turns exactly
`test_registration_closes_once_an_account_exists` red and leaves the other three
green.

PHP: 1120 tests, 1073 passed, 32 skipped, plus the 4 new. The 7 failures and 8
errors are pre-existing and environmental — verified by running the same filter
with the change stashed and getting an identical set: `gd`/`exif` missing from
the local PHP, `rename()` over an open file on Windows, a path-traversal
assertion expecting POSIX separators, and a `--force` option a test expects but
the command does not define.
