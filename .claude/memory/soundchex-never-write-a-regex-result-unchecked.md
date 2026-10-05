# preg_replace returns null, and null overwrites the file

A patch script did this:

```php
$body = preg_replace('~^use App\\Models\\MediaItem;$~m', $replacement, $body, 1);
file_put_contents($path, $body);
```

The pattern had a bad escape, `preg_replace` returned `null`, and `null` was
written straight over `DuplicateDetector.php` — 826 lines gone. `php -l`
reported no syntax errors, because an empty file has none.

Restored with `git checkout --`, which only worked because the file was
committed.

Two rules for any script that rewrites a source file:

1. **Prefer `str_replace` with an exact anchor** and assert
   `substr_count($body, $from) === 1` before replacing. A unique-match check
   catches a drifted anchor; a regex silently matches nothing or everything.
2. **Refuse to write a short result.** Check the length before
   `file_put_contents` and exit non-zero instead:

```php
if (! is_string($body) || strlen($body) < $expectedMinimum) {
    fwrite(STDERR, "result looks wrong; not writing\n");
    exit(1);
}
```

Also: "no syntax errors" from `php -l` is not evidence the file still has
content. Check the byte count.
