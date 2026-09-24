# 196 — The installer picks the right manifest

**Merged** 2026-09-24 · **Issues** S-368

Raised by the S-367 cleanup as a possibly-inaccurate comment. It was a real
bug: a plugin archive containing more than one `plugin.json` could install the
wrong plugin.

## What changed

`manifestFromZip()` said it took "the shallowest match" but used
`locateName('plugin.json', ZipArchive::FL_NODIR)`, which returns the first
match in **archive order**. Those are only the same thing when the archive
happens to be ordered shallowest-first.

A plugin shipping an example or fixture plugin of its own has two manifests. If
the nested one is listed first, the installer read *that* — so installing
`acme.porter` gave you `acme.demo`, under the example's id and entry point.

The lookup now walks every entry and keeps the one with the fewest path
separators.

## Worth knowing

- The nested-fallback loop is gone: a single pass finds root and nested
  manifests alike, so the fallback had nothing left to do.
- Behaviour is unchanged for the ordinary single-manifest archive, which is
  every plugin currently published.

## Still wrong

Nothing found here. Two manifests at the same depth still resolve by archive
order — there is no better answer, and an archive like that is malformed.

## Tests

PHP · `tests/Feature/PluginCatalogTest.php` 9/9, one new:
`test_it_installs_the_shallowest_manifest_not_the_first_one`, with the nested
manifest deliberately ordered first. Verified against the old implementation —
it returns `acme.demo` instead of `acme.porter`, exactly the failure described.
