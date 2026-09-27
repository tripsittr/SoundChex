# The build says what it packaged

S-420.

The Windows server bundle could not be checked without downloading it. That is
222 MB, and on a domestic connection it times out more often than it completes
— three attempts here, all reset mid-transfer. So "did FrankenPHP actually get
in there?" had no cheap answer, and the honest reply was "the step didn't
error".

`package-runtime.sh` now prints every binary it bundled, with sizes, and then
refuses to archive if one the server cannot run without is missing or empty:

    ==> bundled binaries
        caddy.exe          38M
        frankenphp.exe     56M
        php.exe            41M

POSIX requires `php`, `php-fpm` and `caddy`; Windows requires `php` and
`frankenphp`, which is the whole point of S-418.

The empty check is not redundant. A moved or renamed release answers with an
HTML error page, `curl` writes it happily, and a zero-byte or 9 KB "binary"
ships — failing at first launch with nothing to explain it. That is precisely
how the FrankenPHP URL failed during S-418: the version I first wrote 404s.

Worth noting Windows skips the extension verification entirely (`if [ "$OS" !=
"windows" ]`, since it cannot run the built `php.exe` on the runner), so until
now the Windows bundle had no content check at all.

## Testing

The manifest block was extracted from the script and run directly, so what was
tested is the shipped code rather than a copy: a complete Windows bundle exits
0, a missing `frankenphp.exe` exits 1, an empty one exits 1, and a POSIX bundle
missing `caddy` exits 1.
