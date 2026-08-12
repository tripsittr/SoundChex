# Users & Profiles

Two separate concepts, deliberately. Confusing them is how permission bugs get
written.

| | Purpose | Security |
|---|---|---|
| **User** | An account with a password. Owns permissions. | Real boundary |
| **Profile** | One person's taste and place in the library. | **Not** a boundary |

---

## Users

An account with credentials. A self-hosted install typically has one or two —
there is no tenant, no organization, no billing.

### Roles

| Role | Can do |
|---|---|
| `super_admin` | Everything, including granting roles |
| `admin` | Manage the catalog and settings |
| `owner` | Same as admin; kept for installs that used it |
| `member` | Browse the catalog, rate and annotate |

Defined in `config/user_types.php`. Roles are global — the tenant-scoped
Spatie teams setup was removed with the organization model, because a
household server has exactly one team and every query paid for the pretence.

### Locked out

```bash
php artisan user:password [email]
```

Resets from the console and verifies the new credentials authenticate before
reporting success. A self-hosted install has no reset email configured, so
without this the only way back in is editing the database by hand.

---

## Profiles

A household shares one account but not one taste. Each profile carries its own
history, resume points, highlights and watchlist.

**Profiles have no password.** Switching is a convenience, exactly as every
streaming service treats it. Anything that must be enforced lives on the User,
and the admin panel enforces its own permissions regardless of profile.

### Resolving the current profile

Always through `CurrentProfile`, never `Auth::id()` directly:

```php
$profileId = app(CurrentProfile::class)->id();
```

One place answers "who is this", so per-person scoping can't drift between
call sites. An account with no profile gets one created from its own name, so
a fresh install behaves as it did before profiles existed.

Rows written before profiles have a null `profile_id` and fall back to the
account. Don't break that — it's what keeps existing history visible.

### Kids mode

A profile may cap the highest rating it can see. `ContentGate` applies it:

- **Every** browse and search query, via `MediaBrowser`
- The detail, watch and stream routes directly

Both halves are required. Filtering the browse pages while a direct link still
plays reads as working while failing — which is exactly what it did before the
route guards were added.

Unrated titles pass through. Most music and books carry no certification, and
excluding them would empty a kids profile rather than protect it.

Ratings live in `movie_metadata.mpaa_rating` and
`show_metadata.content_rating`. The latter was added late: without it a cap
silently let every series through.

### Avatars

Optional photo on the public disk, falling back to a coloured initial. Managed
in the admin panel. Gitignored, so they are host-local and don't travel with
the repo.
