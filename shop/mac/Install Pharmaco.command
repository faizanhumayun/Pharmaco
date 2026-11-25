#!/usr/bin/env bash
#
# Installs Pharmaco on this Mac, or updates it. Double-click to run.
#
# It puts the application in ~/Pharmaco, creates its database, and sets it to
# start by itself whenever this Mac is on. Run it again with a newer kit to
# update: the books and the settings are kept.
#
# Needs PHP 8.2 or later and MySQL. If either is missing it installs them with
# Homebrew (brew.sh), which must be there first.
#
# Settings can be given ahead of time instead of typed (used for testing):
#   PHARMACO_TARGET  PHARMACO_PORT  PHARMACO_DB_HOST  PHARMACO_DB_PORT
#   PHARMACO_DB_NAME  PHARMACO_DB_USER  PHARMACO_DB_PASSWORD
#   PHARMACO_ADMIN_EMAIL  PHARMACO_ADMIN_PASSWORD   (instead of the built-in login)
#   PHARMACO_PUSH_URL  PHARMACO_PUSH_TOKEN  PHARMACO_NO_AUTOSTART=1
#   PHARMACO_DB_REUSE=1   (use a database of that name that already has tables)
#
# It never shares a database with another copy of Pharmaco on the same Mac
# (a developer's, say): its own is called pharmaco_shop, and one that already
# holds tables is used only when you say so.
#   PHARMACO_APP_DIR   (where Pharmaco.app is put; /Applications by default)
#
set -euo pipefail

KIT="$(cd "$(dirname "$0")" && pwd)"
TARGET="${PHARMACO_TARGET:-$HOME/Pharmaco}"
PORT="${PHARMACO_PORT:-8080}"
DB_HOST="${PHARMACO_DB_HOST:-127.0.0.1}"
DB_PORT="${PHARMACO_DB_PORT:-3306}"
DB_NAME="${PHARMACO_DB_NAME:-pharmaco_shop}"
DB_USER="${PHARMACO_DB_USER:-root}"
DB_PASSWORD="${PHARMACO_DB_PASSWORD-}"

say() { printf '\n==> %s\n' "$1"; }
fail() { printf '\nSTOPPED: %s\n' "$1" >&2; exit 1; }

[[ -d "$KIT/app" ]] || fail "The 'app' folder is missing beside this installer. Unzip the whole kit first."

# --- PHP and MySQL -----------------------------------------------------------
need_brew() { command -v brew >/dev/null || fail "$1 is not installed, and neither is Homebrew. Install Homebrew from https://brew.sh and run this again."; }

if ! command -v php >/dev/null || ! php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
  need_brew "PHP 8.2 or later"; say "Installing PHP"; brew install php
fi

MISSING="$(php -r 'echo implode(", ", array_filter(["pdo_mysql", "mbstring", "openssl", "bcmath", "curl", "fileinfo"], fn ($e) => ! extension_loaded($e)));')"
[[ -z "$MISSING" ]] || fail "This PHP is missing: $MISSING."

# A Mac that already runs MAMP has MySQL, on MAMP's own port and with its own
# client program: use that rather than installing a second one beside it.
MAMP_BIN="$(ls -d /Applications/MAMP/Library/bin/mysql*/bin 2>/dev/null | sort | tail -1)"

if ! command -v mysql >/dev/null && [[ -n "$MAMP_BIN" && -x "$MAMP_BIN/mysql" ]]; then
  export PATH="$MAMP_BIN:$PATH"

  if [[ -z "${PHARMACO_DB_PORT-}" ]]; then
    say "Using the MySQL that MAMP runs on this Mac"
    DB_PORT=8889
    [[ -n "${PHARMACO_DB_PASSWORD+x}" ]] || DB_PASSWORD=root
  fi
fi

if ! command -v mysql >/dev/null; then
  need_brew "MySQL"; say "Installing MySQL"; brew install mysql; brew services start mysql; sleep 8
fi

mysql_do() { MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --protocol=tcp "$@"; }
mysql_do -e 'select 1' >/dev/null 2>&1 || fail "MySQL could not be reached at $DB_HOST:$DB_PORT as '$DB_USER'. Start it (MAMP's Start button, or: brew services start mysql) and run this again."

# --- the application ---------------------------------------------------------
UPDATING=0; [[ -f "$TARGET/app/.env" ]] && UPDATING=1

say "$([[ $UPDATING == 1 ]] && echo "Updating Pharmaco in $TARGET" || echo "Installing Pharmaco into $TARGET")"
mkdir -p "$TARGET/app"
# The settings and everything the shop has stored stay as they are.
rsync -a --delete --exclude '.env' --exclude '/storage' "$KIT/app/" "$TARGET/app/"
[[ -d "$TARGET/app/storage" ]] || cp -R "$KIT/app/storage" "$TARGET/app/storage"
cp "$KIT/set-env.php" "$TARGET/set-env.php"

cd "$TARGET/app"

if [[ $UPDATING == 0 ]]; then
  ask() { local answer; read -r -p "$1: " answer; printf '%s' "$answer"; }

  # The first (back office) login is built in. It is not asked for and not
  # shown here, so it is never on the screen in front of the shop's staff.
  # (Another one can be given ahead of time, for testing.)
  ADMIN_SETTINGS=()
  [[ -z "${PHARMACO_ADMIN_EMAIL-}" ]] || ADMIN_SETTINGS+=("PLATFORM_ADMIN_EMAIL=$PHARMACO_ADMIN_EMAIL")
  [[ -z "${PHARMACO_ADMIN_PASSWORD-}" ]] || ADMIN_SETTINGS+=("PLATFORM_ADMIN_PASSWORD=$PHARMACO_ADMIN_PASSWORD")

  if [[ -z "${PHARMACO_PUSH_URL+x}" ]]; then
    echo; echo "To keep a read-only copy online, paste the two values from the platform's back office (Shop copies)."
    echo "Leave both empty to set that up later."
    PUSH_URL="$(ask 'MIRROR_PUSH_URL')"; PUSH_TOKEN="$(ask 'MIRROR_PUSH_TOKEN')"
  else
    PUSH_URL="${PHARMACO_PUSH_URL}"; PUSH_TOKEN="${PHARMACO_PUSH_TOKEN-}"
  fi

  # Its own database, never another copy's. One of this name that already
  # holds tables may be another Pharmaco's books: used only when you say so.
  TABLES="$(mysql_do -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME'")"

  if [[ "$TABLES" != "0" && -z "${PHARMACO_DB_REUSE-}" ]]; then
    echo
    echo "A database called '$DB_NAME' already exists on this Mac and holds $TABLES tables."
    echo "It may be another copy of Pharmaco (a development copy, or an earlier install)."
    REUSE="$(ask "Type yes to use it for this installation, or press Return to stop")"
    [[ "$REUSE" == "yes" ]] || fail "Nothing was changed. To install beside it, run again with another name: PHARMACO_DB_NAME=pharmaco_shop2"
  fi

  # The port must be free: another server on it would answer instead.
  if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    fail "Something on this Mac already uses port $PORT. Stop it, or run again with another port: PHARMACO_PORT=8090"
  fi

  say "Creating the database '$DB_NAME'"
  mysql_do -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

  # SESSION_COOKIE: its own sign-in cookie, so another Pharmaco on this
  # Mac's localhost (a development copy) does not sign it out, or the reverse.
  php "$TARGET/set-env.php" "$TARGET/app" \
    APP_ENV=production APP_DEBUG=false "APP_URL=http://localhost:$PORT" LOG_LEVEL=warning \
    "DB_HOST=$DB_HOST" "DB_PORT=$DB_PORT" "DB_DATABASE=$DB_NAME" "DB_USERNAME=$DB_USER" "DB_PASSWORD=$DB_PASSWORD" \
    "MIRROR_PUSH_URL=$PUSH_URL" "MIRROR_PUSH_TOKEN=$PUSH_TOKEN" \
    SESSION_COOKIE=pharmaco-shop-session ${ADMIN_SETTINGS[@]+"${ADMIN_SETTINGS[@]}"}

  php artisan key:generate --force --no-interaction >/dev/null
fi

# Marks this installation as a shop's own computer (also on an update from
# a kit made before the mark existed).
php "$TARGET/set-env.php" "$TARGET/app" PHARMACO_SHOP=true

say "Preparing the books"
php artisan migrate --force --no-interaction
# Quietly: what it would print includes the first login's email.
[[ $UPDATING == 1 ]] || php artisan db:seed --force --no-interaction --quiet
php artisan optimize:clear >/dev/null
# The routes and the screens, prepared once here rather than on every page.
# The settings are left uncached on purpose, so a change to .env takes effect.
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

# --- start by itself ---------------------------------------------------------
if [[ -z "${PHARMACO_NO_AUTOSTART-}" ]]; then
  say "Setting it to start with this Mac"
  AGENTS="$HOME/Library/LaunchAgents"; PHP_BIN="$(command -v php)"; mkdir -p "$AGENTS" "$TARGET/logs"

  cat > "$AGENTS/com.pharmaco.server.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.pharmaco.server</string>
  <key>WorkingDirectory</key><string>$TARGET/app</string>
  <key>ProgramArguments</key><array><string>$PHP_BIN</string><string>artisan</string><string>serve</string><string>--host=0.0.0.0</string><string>--port=$PORT</string></array>
  <key>EnvironmentVariables</key><dict><key>PHP_CLI_SERVER_WORKERS</key><string>4</string><key>PATH</key><string>$(dirname "$PHP_BIN"):/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin</string></dict>
  <key>RunAtLoad</key><true/><key>KeepAlive</key><true/>
  <key>StandardOutPath</key><string>$TARGET/logs/server.log</string><key>StandardErrorPath</key><string>$TARGET/logs/server.log</string>
</dict></plist>
PLIST

  cat > "$AGENTS/com.pharmaco.scheduler.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.pharmaco.scheduler</string>
  <key>WorkingDirectory</key><string>$TARGET/app</string>
  <key>ProgramArguments</key><array><string>$PHP_BIN</string><string>artisan</string><string>schedule:run</string></array>
  <key>StartInterval</key><integer>60</integer>
  <key>StandardOutPath</key><string>$TARGET/logs/scheduler.log</string><key>StandardErrorPath</key><string>$TARGET/logs/scheduler.log</string>
</dict></plist>
PLIST

  for job in server scheduler; do
    launchctl unload "$AGENTS/com.pharmaco.$job.plist" 2>/dev/null || true
    launchctl load "$AGENTS/com.pharmaco.$job.plist"
  done

  sleep 3
  open "http://localhost:$PORT" 2>/dev/null || true
fi

# --- an icon to open it with ------------------------------------------------
# A small application in the Applications folder: it makes sure Pharmaco is
# running, then opens it in the browser. It can be dragged to the Dock.
APP_DIR="${PHARMACO_APP_DIR:-/Applications}"
[[ -z "${PHARMACO_APP_DIR-}" ]] || mkdir -p "$APP_DIR" 2>/dev/null || true
[[ -w "$APP_DIR" ]] || { APP_DIR="$HOME/Applications"; mkdir -p "$APP_DIR"; }
APP="$APP_DIR/Pharmaco.app"

say "Adding the Pharmaco icon to $APP_DIR"
rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"
[[ -f "$KIT/AppIcon.icns" ]] && cp "$KIT/AppIcon.icns" "$APP/Contents/Resources/AppIcon.icns"

cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>CFBundleName</key><string>Pharmaco</string>
  <key>CFBundleDisplayName</key><string>Pharmaco</string>
  <key>CFBundleIdentifier</key><string>com.pharmaco.launcher</string>
  <key>CFBundleVersion</key><string>1</string>
  <key>CFBundleShortVersionString</key><string>1.0</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleExecutable</key><string>Pharmaco</string>
  <key>CFBundleIconFile</key><string>AppIcon</string>
  <key>LSUIElement</key><true/>
</dict></plist>
PLIST

cat > "$APP/Contents/MacOS/Pharmaco" <<LAUNCH
#!/bin/bash
# Opens Pharmaco in the browser, starting it first if it is not running.
URL="http://localhost:$PORT"
AGENT="\$HOME/Library/LaunchAgents/com.pharmaco.server.plist"

up() { curl -s -o /dev/null -m 2 "\$URL/up"; }

if ! up; then
  [[ -f "\$AGENT" ]] && { launchctl load "\$AGENT" 2>/dev/null; launchctl start com.pharmaco.server 2>/dev/null; }

  for try in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do up && break; sleep 1; done
fi

if up; then
  open "\$URL"
else
  osascript -e 'display dialog "Pharmaco could not be started. Check that the database (MySQL) is running, then try again." buttons {"OK"} default button 1 with title "Pharmaco" with icon caution'
fi
LAUNCH
chmod +x "$APP/Contents/MacOS/Pharmaco"
touch "$APP"

# --- the till, printing straight to the default printer ---------------------
# Chrome started with --kiosk-printing sends Print to the default printer
# with no preview. Only a fresh start with that switch does it, hence a
# browser profile of its own.
CHROME="/Applications/Google Chrome.app"
TILL="$APP_DIR/Pharmaco Till.app"
rm -rf "$TILL"

if [[ -d "$CHROME" ]]; then
  say "Adding the Pharmaco Till icon (prints directly)"
  mkdir -p "$TILL/Contents/MacOS" "$TILL/Contents/Resources"
  [[ -f "$KIT/AppIcon.icns" ]] && cp "$KIT/AppIcon.icns" "$TILL/Contents/Resources/AppIcon.icns"

  cat > "$TILL/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>CFBundleName</key><string>Pharmaco Till</string>
  <key>CFBundleDisplayName</key><string>Pharmaco Till</string>
  <key>CFBundleIdentifier</key><string>com.pharmaco.till</string>
  <key>CFBundleVersion</key><string>1</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleExecutable</key><string>Pharmaco Till</string>
  <key>CFBundleIconFile</key><string>AppIcon</string>
  <key>LSUIElement</key><true/>
</dict></plist>
PLIST

  cat > "$TILL/Contents/MacOS/Pharmaco Till" <<LAUNCH
#!/bin/bash
# Pharmaco at the counter: Print goes straight to the default printer.
open -na "Google Chrome" --args --kiosk-printing --user-data-dir="$TARGET/till-browser" --no-first-run --start-maximized "http://localhost:$PORT"
LAUNCH
  chmod +x "$TILL/Contents/MacOS/Pharmaco Till"
  touch "$TILL"
fi

IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo 'this-computer')"

cat <<DONE

Pharmaco is $([[ $UPDATING == 1 ]] && echo updated || echo installed).

  To open it:             the Pharmaco icon in $APP_DIR (drag it to the Dock)
$([[ -d "$TILL" ]] && echo "  To print with no preview: the Pharmaco Till icon; make the slip printer the default printer.")
  On this Mac:            http://localhost:$PORT
  From other computers:   http://$IP:$PORT   (on the same network)
  Sign in at:             http://localhost:$PORT/back-office/login

The books are in the MySQL database '$DB_NAME'. Backups are written to
$TARGET/app/storage/app/private/mirror/out
DONE
