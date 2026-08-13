#!/usr/bin/env bash
# Builds the isolated environment the browser tests run against.
#
# Nothing here touches the real library: a separate SQLite file, a separate
# storage root, and entirely generated media. Safe to re-run — it starts clean.
set -euo pipefail

cd "$(dirname "$0")/../.."

ROOT="$(grep '^LOCAL_DISK_ROOT=' .env.e2e | cut -d= -f2-)"
DB="$(grep '^DB_DATABASE=' .env.e2e | cut -d= -f2-)"

# The bootstrap deletes both of these, so refuse to run unless they are
# scratch paths. A misconfigured root here would wipe the real library.
if [[ "$ROOT" != /private/tmp/* && "$ROOT" != /tmp/* ]]; then
  echo "refusing to run: LOCAL_DISK_ROOT is not a scratch path ($ROOT)" >&2
  exit 1
fi
if [[ "$DB" != /private/tmp/* && "$DB" != /tmp/* ]]; then
  echo "refusing to run: DB_DATABASE is not a scratch path ($DB)" >&2
  exit 1
fi

rm -rf "$ROOT" "$DB"
mkdir -p "$ROOT"
touch "$DB"

php artisan --env=e2e migrate:fresh --force --quiet
php artisan --env=e2e db:seed --class=Database\\Seeders\\E2eSeeder --force
echo "e2e environment ready at $ROOT"
