# Admin Panel Theme

Make `/admin` look like it belongs to the same product as `/app`.

## The problem

The media center is dark and cinematic: near-black surfaces, a red accent, and
Figtree throughout. The admin panel is stock Filament — light by default, with
a blue primary that appears nowhere else in the product, and a thin CSS veneer
using a third red (`#d75a5a`) that matches neither.

Three specific mismatches, all verified in the code:

| | Media center | Admin panel |
|---|---|---|
| Accent | `#e11d3a` | `#5A98D7` primary, `#d75a5a` in theme.css |
| Surface | `#08080b` near-black | Filament default white/slate |
| Type | Figtree | Filament default |

The existing `resources/css/filament/admin/theme.css` is 295 bytes of sidebar
borders. It does not import Tailwind and does not define a palette.

Practical consequence: moving between the two surfaces reads as leaving one
application and entering another.

## Scope

**In:** palette, surfaces, typography, sidebar/topbar, tables, forms, buttons,
badges, login screen, dark mode as the default.

**Out:** restructuring navigation, changing what any resource does, or touching
media center styling. This is presentation only.

## Approach

1. **Share the palette.** Lift the media center's `@theme` tokens into the
   Filament theme so one definition drives both. Filament 4 exposes its
   surfaces as CSS custom properties, so most of this is remapping rather than
   overriding.

2. **Dark by default**, matching the app. Light mode stays available — reading
   a long table in a bright room is a real use.

3. **Register the red as Filament's primary** so buttons, links, focus rings
   and active states inherit it, rather than being patched selector by
   selector.

4. **Figtree**, already loaded by the media center.

5. **Login screen** to match the restyled auth pages, which are already dark
   with the logo and accent focus rings.

## Constraints

- Filament's own class names (`fi-*`) are internal. Prefer its CSS variables
  and its published theme hooks; use `fi-*` selectors only where there is no
  variable, and say why in a comment.
- The theme file is a separate Vite entry from `media-center.css`. Both must
  build; `npm run build` after any change.
- Do not touch `resources/css/media-center.css`. If a token needs to be shared,
  it moves to a file both import — the media center's appearance must not
  change as a side effect.

## Verification

- Every admin route renders 200: dashboard, each media resource, duplicates,
  profiles, both settings pages, login.
- Dark and light both legible — no invisible text, no white-on-white inputs.
- Media center pages unchanged (compare before/after).
- `npm run build` clean; `php artisan test` still 9/9.

## Outcome

Built and verified.

- `resources/css/tokens.css` holds the palette; both stylesheets import it, so
  the two surfaces can no longer drift.
- The Filament theme imports Filament's own stylesheet, which is what makes its
  variables available — the file grew from 295 bytes of sidebar borders to a
  real theme.
- The accent is registered as Filament's `primary`, so buttons, links, focus
  rings and active states inherit it. Verified: primary/600 resolves to
  `oklch(… 21.727)`, the accent's hue.
- Dark is the default; light remains legible.

Verified: 12 admin routes plus login render 200; the media center still emits
identical colour values (only the indirection changed) and all its routes
render; `npm run build` clean; tests 9/9.

Deliberately unchanged: navigation structure, resource behaviour, and
`media-center.css` beyond pointing it at the shared tokens.
