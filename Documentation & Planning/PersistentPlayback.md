# Persistent Playback

Music should keep playing while you browse the library.

## The problem

The now-playing bar is rendered on every page, so it *looks* persistent. It
isn't: every link is a full page load, which destroys the `<audio>` element and
recreates an empty one. Playback stops on every navigation.

An `<audio>` element cannot survive a real page load. There is no setting or
attribute that changes this — the fix has to keep the page from unloading.

## Approach: Livewire `wire:navigate` + `@persist`

Livewire 4 is **already installed** for Filament, and ships SPA-style
navigation that swaps the body rather than reloading. `@persist` marks a
subtree to carry across, untouched — implemented with Alpine's `x-persist`, and
Alpine is already a dependency too.

So this costs no new dependencies. The alternatives were worse:

- **Hand-rolled fetch-and-swap** — around 150 lines reimplementing what is
  already vendored, including history, scroll restoration and asset tracking.
- **Save position, resume after reload** — audible gap on every navigation.
  Honest, but a worse product.

## Scope

**In:**

- Livewire's navigate script loaded in the media center layout
- The now-playing bar wrapped in `@persist`, so its `<audio>` survives
- `wire:navigate` on internal links: nav, posters, rails, detail pages
- Page scripts re-initialising after a swap rather than only on
  `DOMContentLoaded`

**Out:**

- The reader, the watch player and the admin panel. Each deliberately owns the
  whole viewport and is entered from a link, not browsed through — a swap there
  buys nothing and risks breaking a mounted PDF or video.
- Livewire components. This uses the navigation layer only; the app stays
  Blade plus Alpine.

## The real risk

**Scripts that run once on load will silently stop working after a swap.**
Every entrypoint currently assumes a fresh document: `media-center.js`,
`download-button.js`, `now-playing.js` and the search page all bind at parse
time.

After a `wire:navigate` swap the DOM is new but the scripts are not re-run, so
anything bound to an element in the swapped region is dead. Each has to
re-initialise on `livewire:navigated`, which fires after every swap **and** on
first load — so a single handler covers both cases.

This is the part most likely to produce "it works, except this one button
stopped working on the second page".

## Verification

- Play a track, navigate between Home, Music, Books, and a detail page: audio
  continues without a gap, and the bar keeps its state.
- Back and forward behave, and audio survives both.
- Download button, search, watchlist toggle and profile switcher all still work
  **after** navigating rather than only on first load.
- Reader and watch pages still load normally.
- Playing a track, then opening the reader, stops nothing unexpectedly.

## Outcome

Built. **Needs a browser to confirm** — the behaviour only exists at runtime.

The plan's premise turned out to be wrong in an important way, and the fix
changed accordingly:

**`@persist` does nothing here.** The `<audio>` element is created in
JavaScript (`new Audio()`), not in markup, so there is no DOM node for
`@persist` to carry across. Wrapping the bar in it looked right and would have
achieved nothing.

What actually survives is the **player object on `window`**, because `window`
outlives the body swap. `now-playing.js` now reuses an existing player instead
of constructing a new one, and only re-binds the UI to the incoming markup. The
bar itself is re-rendered each navigation, which is fine — it is a view of
player state, not the state itself.

Two bugs that would have followed from that:

- **Accumulating listeners.** The player outlives the page, so each navigation
  added another set of handlers writing into elements that no longer existed.
  `resetListeners()` is called on rebind.
- **Double-bound buttons.** `bindPageScripts()` runs on load *and* on
  `livewire:navigated`, so a download button would have taken two handlers —
  one tap, two downloads. Guarded with `data-bound` on the element.

Also corrected: `@livewireScriptConfig` does not emit the script tag.
`@livewireScripts` does. Without it `wire:navigate` is inert and none of this
works — verified the asset returns 200 on the live site.

Links are opted in at runtime in `navigate.js` rather than tagged by hand in
every view, so a link added later is covered automatically. The reader, watch
player and admin are excluded: each owns the viewport and mounts a renderer
against the document.

## Still to verify — needs a browser

- Play a track, navigate Home → Music → a detail page: audio continues with no
  gap.
- Back and forward preserve playback.
- Download button, search and watchlist still work *after* navigating.
- The reader and watch pages still load normally.
