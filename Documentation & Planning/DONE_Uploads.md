# Uploads

Let household members add files without giving them the keys to everything.

## Where things stand

Single-file upload already exists on the Book and Music forms, and PHP is
configured for 2 GB uploads. What's missing is bulk, and access for anyone who
isn't an admin.

`User::canAccessPanel()` allows `super_admin`, `owner` and `admin`. Today Syd
has no roles at all, so she cannot reach `/admin`. Granting her `admin` to let
her upload a song would also hand over user management, library settings, the
metadata API keys, and every delete action.

## Scope

**In:**

- An `uploader` role that reaches the admin panel but sees **only** the upload
  page
- A bulk upload page: drop many files, they land in `media/unsorted`, get
  catalogued, enriched and filed by the existing pipeline
- Per-file progress, and an honest report of what was accepted and what wasn't
- Existing resources hidden from an uploader — navigation *and* direct URL

**Out:**

- Uploading straight into the library tree. Everything goes to the inbox and is
  filed by `LibraryOrganizer` once enrichment resolves real metadata, exactly
  as a scanned file is. One path, not two.
- Editing metadata. An uploader adds files; an admin curates them.
- Resumable uploads for very large files. Worth having later; a 2 GB film over
  a home connection is a single long request for now.

## The part that needs care

**Hiding navigation is not access control.** Filament resources are reachable
by URL whether or not they appear in the sidebar, so an uploader who guesses
`/admin/users` must be refused there too — not merely find it absent from the
menu.

So every resource gets an explicit check, and the upload page is the only thing
an uploader can open. This is the difference between a tidy menu and a
permission.

## Approach

1. Add `uploader` to `config/user_types.php` and to `canAccessPanel()`.
2. A `BulkUpload` page with a multi-file `FileUpload` writing to
   `media/unsorted` on the private disk.
3. After upload, run the scanner over the inbox so files are catalogued
   immediately rather than waiting up to five minutes for the schedule.
4. Gate every other resource and page with `canAccess()`, and confirm by
   requesting each URL as an uploader.
5. Report per file: catalogued, already present, or unrecognised type.

## Constraints

- Only extensions in `config/library.php` are accepted. The inbox takes what
  the scanner can classify; anything else would sit there forever.
- Uploads are attributed to the user who sent them, so a bad batch is
  traceable.
- **A kids profile must not be able to upload.** It is a restricted view of the
  library, not an author of it.
- The upload disk is the private one. Nothing user-supplied lands anywhere
  publicly served.

## Verification

- An uploader signs in, sees only Upload, and gets 403 on `/admin/users`,
  `/admin/settings` and every media resource by direct URL.
- A batch of mixed files uploads; each is catalogued and filed by type.
- An unsupported extension is refused with a message naming it.
- Re-uploading a file already in the library is recognised as a duplicate
  rather than filed twice.
- An admin still sees everything.

## Outcome

Built and verified. `DONE_TvFiling.md` landed first, so uploading a season now
files correctly rather than producing colliding filenames.

An `uploader` role reaches the panel and sees exactly one page. **Every other
resource refuses by URL**, which was the part worth getting right: before this,
any panel user reached everything — user management, the metadata API keys,
every delete action — because only the panel gate existed and the media
resources had no permissions at all.

Verified as an uploader: `/admin/bulk-upload` returns 200 and all eleven other
resources return **403 by direct URL**, not merely absent from the menu. The
only navigation link rendered is the upload page. An admin still reaches
everything.

Uploads land in the inbox and go through the same scanner as a file dropped
there by hand — one path, not two. An upload that bypassed it would skip
duplicate detection and land where the organizer never looks. Confirmed by
re-uploading a track already in the library: catalogued and correctly flagged
as a duplicate rather than filed twice.

Accepted extensions come from `config/library.php`, so the form cannot accept
something the scanner would silently ignore, and the size limit is read from
PHP's own so the two cannot disagree.

## Deferred

Resumable uploads. A 2 GB film over a home connection is a single long request;
worth revisiting if that proves fragile in practice.
