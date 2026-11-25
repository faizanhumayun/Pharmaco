#!/usr/bin/env bash
#
# Builds the kit that is installed on a shop's computer.
#
#   shop/package.sh windows     ->  dist/pharmaco-shop-windows.zip
#   shop/package.sh mac         ->  dist/pharmaco-shop-mac.zip
#
# Run on a developer's machine, from the project folder. The kit holds the
# application with everything it needs already inside it (PHP packages, the
# built screens), so the shop's computer needs no composer, no Node and no
# git. The Windows kit also carries PHP and MariaDB themselves, so it installs
# with no internet at all.
#
set -euo pipefail

OS="${1:-}"
[[ "$OS" == "windows" || "$OS" == "mac" ]] || { echo "usage: shop/package.sh windows|mac" >&2; exit 1; }

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
KIT="$DIST/pharmaco-shop-$OS"
CACHE="$DIST/cache"

# The programs the Windows kit carries. Change the versions here.
PHP_ZIP_URL="https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip"
MARIADB_VERSION="11.4.5"
MARIADB_ZIP_URL="https://archive.mariadb.org/mariadb-$MARIADB_VERSION/winx64-packages/mariadb-$MARIADB_VERSION-winx64.zip"
VCREDIST_URL="https://aka.ms/vs/17/release/vc_redist.x64.exe"
# The certificates PHP checks a secure site against. PHP on Windows carries
# none of its own, and without them it cannot send the books to the platform.
CACERT_URL="https://curl.se/ca/cacert.pem"

echo "==> Building the screens"
(cd "$ROOT" && npm run build >/dev/null)

echo "==> Copying the application"
rm -rf "$KIT"
mkdir -p "$KIT/app" "$CACHE"

rsync -a "$ROOT/" "$KIT/app/" \
  --exclude '.git' --exclude '.github' --exclude '.idea' --exclude '.claude' --exclude 'node_modules' \
  --exclude 'dist' --exclude 'tests' --exclude 'vendor' --exclude 'shop' \
  --exclude '.env' --exclude '.env.*' --exclude '/storage' --exclude 'public/hot' --exclude 'public/__shots' \
  --exclude 'docs/mockups' --exclude '.phpunit*' --exclude '*.mdb' --exclude '.DS_Store'
cp "$ROOT/.env.example" "$KIT/app/.env.example"

# An empty storage folder in the shape the application expects.
for dir in app/private app/public framework/cache/data framework/sessions framework/views logs; do
  mkdir -p "$KIT/app/storage/$dir"
done

echo "==> Installing PHP packages (without the development ones)"
(cd "$KIT/app" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)

cp "$ROOT/shop/set-env.php" "$KIT/set-env.php"
# A short step-by-step guide for the kit's own system when there is one,
# with the full guide beside it.
if [[ -f "$ROOT/shop/$OS/READ ME FIRST.txt" ]]; then
  cp "$ROOT/docs/shop-computer.md" "$KIT/Full guide.md"
else
  cp "$ROOT/docs/shop-computer.md" "$KIT/READ ME FIRST.md"
fi
date -u +"%Y-%m-%d %H:%M UTC" > "$KIT/app/storage/app/kit-built.txt"

if [[ "$OS" == "windows" ]]; then
  fetch() { [[ -s "$CACHE/$2" ]] || { echo "==> Downloading $2"; curl -fL --retry 3 -A "Mozilla/5.0" -o "$CACHE/$2" "$1"; }; }

  fetch "$PHP_ZIP_URL" "php-win.zip"
  fetch "$MARIADB_ZIP_URL" "mariadb-$MARIADB_VERSION-win.zip"
  fetch "$VCREDIST_URL" "vc_redist.x64.exe"
  fetch "$CACERT_URL" "cacert.pem"

  echo "==> Adding PHP and MariaDB for Windows"
  mkdir -p "$KIT/runtime/php" "$KIT/runtime"
  unzip -q "$CACHE/php-win.zip" -d "$KIT/runtime/php"
  unzip -q "$CACHE/mariadb-$MARIADB_VERSION-win.zip" -d "$KIT/runtime"
  mv "$KIT/runtime/mariadb-$MARIADB_VERSION-winx64" "$KIT/runtime/mariadb"
  # Not needed to run it, and most of its size.
  rm -rf "$KIT/runtime/mariadb/mysql-test" "$KIT/runtime/mariadb/sql-bench" "$KIT/runtime/mariadb/include" "$KIT/runtime/mariadb/lib/"*.pdb "$KIT/runtime/mariadb/bin/"*.pdb 2>/dev/null || true
  cp "$CACHE/vc_redist.x64.exe" "$KIT/runtime/vc_redist.x64.exe"
  cp "$CACHE/cacert.pem" "$KIT/runtime/cacert.pem"

  cp "$ROOT/shop/windows/"* "$KIT/"
else
  cp "$ROOT/shop/mac/"* "$KIT/"
  chmod +x "$KIT/"*.command
fi

echo "==> Zipping"
(cd "$DIST" && rm -f "pharmaco-shop-$OS.zip" && zip -qr "pharmaco-shop-$OS.zip" "pharmaco-shop-$OS")

echo
echo "Done: $DIST/pharmaco-shop-$OS.zip ($(du -h "$DIST/pharmaco-shop-$OS.zip" | cut -f1))"
