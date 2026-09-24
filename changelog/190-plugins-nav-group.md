# 190 — Plugin pages have a nav group of their own

**Merged** 2026-09-24 · **Issues** S-364

Plugin screens scattered across the sidebar. The Activity Log filed itself
under **System**, next to the screens that administer the machine; the Playlist
Porter named no group at all and floated ungrouped above everything. Nothing
told an operator which parts of the panel a plugin had added.

## What changed

### A Plugins group, between Settings and System

Pages contributed through `Registry::adminPage()` are filed under **Plugins**
unless they asked for somewhere else.

A plugin that names a group means it — the Activity Log genuinely belongs with
the other System screens — so only pages that named none are moved. "Named
none" is not the same as "declares the property": every Filament page
redeclares it, so the *value* is what separates a deliberate choice from a
default left untouched.

### Manage Plugins heads the group it manages

The core Plugins page was in System while the pages it installs appeared
elsewhere. It now sits at the top of the Plugins group, relabelled **Manage
Plugins** so the group and the item inside it are not both called the same
thing.

## Worth knowing

- No migration, no config. Existing plugins need no change: one that named a
  group keeps it, one that did not is grouped for you.
- Plugin authors: this is documented in `docs/plugins/README.md` under
  *An admin page*, including how to opt out.
- Grouping is best-effort per page. A page that cannot be reflected keeps
  whatever group it had rather than costing the panel its whole plugin page
  list.

## Still wrong

Nothing found. The group is declared in the panel's `navigationGroups()`, so
it holds its position whether or not any plugin page is currently installed —
an empty group simply does not render.

## Tests

PHP · `tests/Feature/PluginNavigationGroupTest.php` 4/4, new: a page that named
no group is filed under Plugins, a page that named one keeps it, a bad class is
ignored rather than breaking the panel, and the panel declares the group.

This is the first test coverage the `adminPage` seam has had.

Full suite 949/954, 4 skipped. The one failure, `ListPageCostTest`, is
unrelated and reproduces on a clean tree — S-362.
