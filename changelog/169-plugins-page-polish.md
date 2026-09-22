# 169 — Plugins page: cleaner cards, and errors you can see

The installed-plugins list was cramped and hand-styled, and enabling a plugin
that fails to load did nothing visible — the loader logs-and-skips a broken
plugin, so the button looked dead.

## Changed

- **Each plugin is a standard Filament section card** now, using the framework's
  own heading/description/badge/footer slots and type scale — no custom pixel
  sizes or bespoke layout. Consistent spacing, name + version in the heading, a
  status badge in the header, the description and the "provides" badges in the
  body, and the Enable/Disable button in the footer.
- **Enabling surfaces failures.** When a plugin is enabled it is loaded there and
  then; if it can't load, a persistent error toast shows the reason and offers a
  **View logs** button instead of silently doing nothing. A successful enable
  says plainly that a reload shows the new features (admin pages appear at the
  next full page load).
- **A "View logs" action** on the page opens the recent plugin-related log lines
  in a modal, so a load failure can be diagnosed from the admin rather than by
  opening a file on the server.

## Testing

- The page renders through Livewire without error and lists the installed
  plugins; the toggle reports success or a specific failure.
