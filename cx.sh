#!/bin/bash
#================================================================
# LOCAL deploy assistant for the Doctor Memory Game — cx.sh
#
#   ./cx.sh            # interactive menu
#   ./cx.sh 20         # run an option directly
#   ./cx.sh 31 "migrate:status"   # …with a parameter
#
# Runs on your machine only. Its counterpart on the server is deploy.sh.
# Needs vars.sh next to it (copy vars.sh.example). vars.sh is gitignored.
#================================================================

cd "$(dirname "$0")" || exit 1

if [ ! -f ./vars.sh ]; then
  echo "vars.sh not found — copy vars.sh.example to vars.sh and fill it in."
  exit 1
fi
# shellcheck source=vars.sh.example
source ./vars.sh

BACKUP_DIR="${BACKUP_DIR:-./backups}"
LOG_DIR="${LOG_DIR:-./log}"

if [ "$(hostname -s 2>/dev/null)" = "${REMOTE_HOST%%.*}" ]; then
  echo "⚠ This looks like $REMOTE_HOST. cx.sh is the LOCAL script; use deploy.sh on the server."
  exit 1
fi

#---------------------------------------------------------------
# Helpers
#---------------------------------------------------------------

log_history() {
  mkdir -p "$LOG_DIR"
  printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M')" "$1" >> "$LOG_DIR/history.log"
}

# Run a command on the server, in the app directory.
remote() {
  $APP_SSH "$REMOTE" "cd ~/$REMOTE_APP && $*"
}

# Same, with a terminal attached, for anything interactive or long-running.
remote_tty() {
  $APP_SSH -t "$REMOTE" "cd ~/$REMOTE_APP && $*"
}

# The value from `php artisan config:show key`, which prints blank lines
# around a "key .. value" line.
config_value() {
  awk 'NF{print $NF}' | tail -n1 | tr -d '\r\n'
}

banner() {
  echo ===========================================================
  echo "$1"
  echo ===========================================================
}

need_param() {
  if [ -z "$PARAM2" ]; then
    read -r -p "$1: " PARAM2
  fi
}

confirm() {
  echo "⚠ $1"
  read -r -p "Type 'yes' to continue: " reply
  [ "$reply" = "yes" ] || { echo "Skipped."; exit 0; }
}

# The server deploys by pulling, so only pushed commits deploy. Built
# assets must be committed too, because the server has no Node.js.
preflight_local() {
  local problems=0

  if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
    echo "⚠ Uncommitted local changes — these will NOT deploy:"
    git status --short
    problems=1
  fi

  local unpushed
  unpushed=$(git log --oneline '@{u}..' 2>/dev/null)
  if [ -n "$unpushed" ]; then
    echo "⚠ Commits not pushed — these will NOT deploy:"
    echo "$unpushed"
    problems=1
  fi

  # Rebuild and see whether the committed bundle was stale.
  if command -v npm >/dev/null 2>&1; then
    echo "→ npm run build (checking committed assets are current)"
    npm run build --silent >/dev/null 2>&1 || { echo "✗ npm run build failed"; exit 1; }
    if [ -n "$(git status --porcelain public_html/build 2>/dev/null)" ]; then
      echo "⚠ public_html/build changed after rebuilding — commit and push it, or the server serves old assets:"
      git status --short public_html/build
      problems=1
    fi
  fi

  if [ "$problems" = "1" ]; then
    echo
    read -r -p "Deploy anyway? Type 'yes' to continue: " reply
    [ "$reply" = "yes" ] || { echo "Skipped."; exit 0; }
  fi
}

#---------------------------------------------------------------
# Menu
#---------------------------------------------------------------

clear
echo =============================================================
echo "Hi $USER — acting on $PROJECT ($REMOTE:~/$REMOTE_APP)"
echo -------------------------------------------------------------
echo "ROUTINE"
echo "10 : TEST: PHP, JavaScript and browser suites locally"
echo "20 : DEPLOY: push checks, then pull + migrate + caches on the server"
echo "21 : DEPLOY with maintenance mode during migrations"
echo "22 : PREFLIGHT: run deploy.sh --check on the server (changes nothing)"
echo "23 : WHAT'S LIVE: compare the server's commit with local"
echo "24 : MIGRATIONS: status on the server"
echo "25 : CACHES: rebuild config/routes/views"
echo "26 : CACHES: clear everything"
echo ----------------------------------------------
echo "DIAGNOSE"
echo "30 : HEALTH: $APP_URL/up"
echo "31 : ARTISAN: run a command on the server"
echo "32 : LOGS: tail the Laravel log"
echo "33 : LOGS: recent errors"
echo "34 : SCHEDULE: registered tasks and the cron entry"
echo "35 : MAIL: show mail settings (for verification emails)"
echo ----------------------------------------------
echo "DATA"
echo "40 : PRUNE: drop unfinished game rows now"
echo "41 : STATS: users, finished games and scores"
echo "50 : BACKUP: download a copy of the database"
echo ----------------------------------------------
echo "MAINTENANCE"
echo "60 : MAINTENANCE MODE: on  [site goes down]"
echo "61 : MAINTENANCE MODE: off"
echo ----------------------------------------------
echo "ACCESS & SETUP"
echo "00 : CONNECT: ssh to the app directory"
echo "90 : FIRST INSTALL: clone, install, .env and key on the server"
echo "qq : Quit"
echo =============================================================

if [ -n "$1" ]; then
  SELECTION=$1
else
  read -r -n 2 SELECTION
  echo
fi
PARAM2="${2:-}"
echo "Your selection is: $SELECTION"

case "$SELECTION" in

  "10" )
  banner "TEST: local suites"
  php artisan test --compact && npm run test:js --silent && npm run test:browser --silent
  ;;

  "20" | "21" )
  banner "DEPLOY: $PROJECT"
  preflight_local
  echo "Local HEAD : $(git log --oneline -1)"
  if [ "$SELECTION" = "21" ]; then
    remote_tty "./deploy.sh --down" && log_history "Deployed $PROJECT with maintenance mode: $(git log --oneline -1)"
  else
    remote_tty "./deploy.sh" && log_history "Deployed $PROJECT: $(git log --oneline -1)"
  fi
  ;;

  "22" )
  banner "PREFLIGHT on $PROJECT"
  remote_tty "./deploy.sh --check"
  ;;

  "23" )
  banner "WHAT'S LIVE on $PROJECT"
  LOCAL_HEAD=$(git rev-parse HEAD 2>/dev/null)
  SERVER_HEAD=$(remote "git rev-parse HEAD" 2>/dev/null | tr -d '\r')
  echo "server: $(remote "git log --oneline -1" 2>/dev/null)"
  echo "local : $(git log --oneline -1)"
  if [ "$LOCAL_HEAD" = "$SERVER_HEAD" ]; then
    echo "✓ server matches local HEAD"
  else
    echo "✗ server is NOT on your local HEAD"
  fi
  ;;

  "24" )
  banner "MIGRATIONS: status"
  remote "php artisan migrate:status"
  ;;

  "25" )
  banner "CACHES: rebuild"
  remote "php artisan optimize"
  log_history "Rebuilt caches on $PROJECT"
  ;;

  "26" )
  banner "CACHES: clear"
  # A stale config cache is why an .env change can look ignored.
  remote "php artisan optimize:clear"
  echo "Run 25 afterwards to rebuild them."
  log_history "Cleared caches on $PROJECT"
  ;;

  "30" )
  banner "HEALTH: $APP_URL/up"
  CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$APP_URL/up")
  if [ "$CODE" = "200" ]; then echo "✓ 200"; else echo "✗ $CODE"; exit 1; fi
  for path in / /leaderboard /login /register; do
    printf '  %-14s %s\n' "$path" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$APP_URL$path")"
  done
  ;;

  "31" )
  banner "ARTISAN"
  need_param "Command (e.g. migrate:status)"
  remote_tty "php artisan $PARAM2"
  ;;

  "32" )
  banner "LOGS: tail (Ctrl-C to stop)"
  remote_tty "tail -n 50 -f storage/logs/laravel.log"
  ;;

  "33" )
  banner "LOGS: recent errors"
  remote "grep -n 'production.ERROR' storage/logs/laravel.log | tail -n 20"
  ;;

  "34" )
  banner "SCHEDULE"
  remote "php artisan schedule:list"
  echo
  remote "crontab -l 2>/dev/null | grep schedule:run || echo '✗ no cron entry for schedule:run'"
  ;;

  "35" )
  banner "MAIL settings"
  # Shows non-secret mail settings only; passwords stay on the server.
  remote "php artisan config:show mail.default && php artisan config:show mail.from && grep -E '^MAIL_(HOST|PORT|SCHEME)=' .env"
  ;;

  "40" )
  banner "PRUNE: unfinished games"
  remote "php artisan model:prune --model='App\\Models\\Game'"
  log_history "Pruned games on $PROJECT"
  ;;

  "41" )
  banner "STATS"
  remote "php artisan tinker --execute 'echo \"Users: \", App\\Models\\User::count(), \" (verified \", App\\Models\\User::whereNotNull(\"email_verified_at\")->count(), \")\\n\", \"Finished games: \", App\\Models\\Game::finished()->count(), \"\\n\", \"Scores: \", App\\Models\\Score::count(), \"\\n\";'"
  ;;

  "50" )
  banner "BACKUP: database"
  mkdir -p "$BACKUP_DIR"
  STAMP=$(date '+%Y%m%d-%H%M')
  DRIVER=$(remote "php artisan config:show database.default" 2>/dev/null | config_value)
  echo "Database driver: $DRIVER"
  case "$DRIVER" in
    sqlite)
      FILE="$BACKUP_DIR/$PROJECT-$STAMP.sqlite"
      $APP_SCP "$REMOTE:~/$REMOTE_APP/database/database.sqlite" "$FILE" || { rm -f "$FILE"; echo '✗ backup failed'; exit 1; }
      if command -v sqlite3 >/dev/null 2>&1 && [ "$(sqlite3 "$FILE" 'pragma integrity_check' 2>&1)" != "ok" ]; then
        rm -f "$FILE"; echo '✗ the copied database failed its integrity check'; exit 1
      fi
      ;;
    mysql|mariadb)
      FILE="$BACKUP_DIR/$PROJECT-$STAMP.sql.gz"
      # Credentials are read on the server and passed through the
      # environment, so they never appear in the process list or locally.
      # pipefail on the server: a failed mysqldump must fail the pipeline,
      # not leave gzip's 20-byte empty archive looking like a backup.
      $APP_SSH "$REMOTE" "set -o pipefail; cd ~/$REMOTE_APP && \
        DB_NAME=\$(php artisan config:show database.connections.$DRIVER.database | awk 'NF{print \$NF}' | tail -n1) && \
        DB_USER=\$(php artisan config:show database.connections.$DRIVER.username | awk 'NF{print \$NF}' | tail -n1) && \
        DB_HOST=\$(php artisan config:show database.connections.$DRIVER.host | awk 'NF{print \$NF}' | tail -n1) && \
        MYSQL_PWD=\$(php -r 'require \"vendor/autoload.php\"; \$a = require \"bootstrap/app.php\"; \$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo config(\"database.connections.$DRIVER.password\");') \
        mysqldump --no-tablespaces --single-transaction -h \"\$DB_HOST\" -u \"\$DB_USER\" \"\$DB_NAME\" | gzip" > "$FILE" \
        || { rm -f "$FILE"; echo '✗ backup failed'; exit 1; }
      if ! gzip -t "$FILE" 2>/dev/null || ! gzip -dc "$FILE" | tail -n1 | grep -q 'Dump completed'; then
        rm -f "$FILE"; echo '✗ the dump is incomplete'; exit 1
      fi
      ;;
    *)
      echo "✗ Backup for '$DRIVER' is not supported by this script."
      exit 1
      ;;
  esac
  if [ -s "$FILE" ]; then
    echo "✓ saved $FILE ($(du -h "$FILE" | cut -f1))"
    log_history "Backed up $PROJECT database to $FILE"
  else
    echo "✗ backup is empty"
    rm -f "$FILE"
    exit 1
  fi
  ;;

  "60" )
  banner "MAINTENANCE MODE: on"
  confirm "The site will show a maintenance page until you run 61."
  remote "php artisan down --retry=60"
  log_history "Maintenance mode on for $PROJECT"
  ;;

  "61" )
  banner "MAINTENANCE MODE: off"
  remote "php artisan up"
  log_history "Maintenance mode off for $PROJECT"
  ;;

  "00" )
  banner "CONNECT: $REMOTE"
  $APP_SSH -t "$REMOTE" "cd ~/$REMOTE_APP 2>/dev/null; exec \$SHELL -l"
  ;;

  "90" )
  banner "FIRST INSTALL on $REMOTE:~/$REMOTE_APP"
  confirm "Clones $REPO_URL into ~/$REMOTE_APP if it is not there, installs dependencies and creates .env."
  $APP_SSH -t "$REMOTE" "
    set -e
    mkdir -p ~/$REMOTE_APP
    cd ~/$REMOTE_APP
    if [ ! -d .git ]; then
      # Shared hosts (e.g. SiteGround) pre-create the site folder with a
      # placeholder public_html, so clone in place rather than into it.
      if [ -d public_html ]; then
        MOVED=public_html.host-default.\$(date +%s)
        mv public_html \"\$MOVED\"
        echo \"→ moved the host's placeholder public_html to \$MOVED\"
      fi
      git init -q
      git remote add origin '$REPO_URL'
      git fetch -q origin
      git checkout -f -B main origin/main
    fi
    composer install --no-dev --optimize-autoloader --no-interaction
    if [ ! -f .env ]; then
      cp .env.example .env
      sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/' .env
      echo '→ created .env from .env.example'
    fi
    grep -qE '^SESSION_SECURE_COOKIE=true' .env || { sed -i '/^SESSION_SECURE_COOKIE=/d' .env; echo 'SESSION_SECURE_COOKIE=true' >> .env; }
    grep -qE '^APP_KEY=base64:.+' .env || php artisan key:generate --force
    if grep -qE '^DB_CONNECTION=sqlite' .env && [ ! -f database/database.sqlite ]; then
      touch database/database.sqlite
    fi
    chmod +x deploy.sh
    echo
    echo 'Next on the server:'
    echo '  1. Edit .env: APP_URL, database, MAIL_* (verification emails), GOOGLE_CLIENT_ID/SECRET'
    echo '  2. Point the domain at ~/$REMOTE_APP/public_html (the host placeholder, if any, was renamed public_html.host-default.*)'
    echo '  3. Add the scheduler to cron:'
    echo \"     * * * * * cd ~/$REMOTE_APP && php artisan schedule:run >> /dev/null 2>&1\"
    echo '  4. Run ./deploy.sh (or option 20 from here)'
  "
  log_history "First install of $PROJECT on $REMOTE"
  ;;

  "qq" | "q" )
  exit 0
  ;;

  * )
  echo "Unknown option: $SELECTION"
  exit 1
  ;;
esac
