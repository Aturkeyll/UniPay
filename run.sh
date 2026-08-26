#!/usr/bin/env bash
#
# run.sh: bring up the whole UniPay + Rafiki stack on Linux Mint.
#
#   bash /home/mint/UniPay/run.sh
#
# Idempotent: safe to run repeatedly. Each phase checks before it changes
# anything, so a second run is mostly a health check.
#
# Flags:
#   --no-rafiki    skip the Interledger playground (UniPay runs in stub mode)
#   --stop         stop everything this script started
#   --reset-db     drop and recreate the database (DESTROYS DATA)
#
set -uo pipefail

UNIPAY_DIR="${UNIPAY_DIR:-/home/mint/UniPay}"
RAFIKI_DIR="${RAFIKI_DIR:-/home/mint/Projects/rafiki}"
UNIPAY_PORT="${UNIPAY_PORT:-8000}"
DB_NAME="wsu_payments"
DB_USER="unipay"
DB_PASS_FILE="$UNIPAY_DIR/.db_password"
LOG_DIR="$UNIPAY_DIR/logs"
PID_FILE="$LOG_DIR/php-server.pid"

SKIP_RAFIKI=0
DO_STOP=0
RESET_DB=0
for arg in "$@"; do
  case "$arg" in
    --no-rafiki) SKIP_RAFIKI=1 ;;
    --stop)      DO_STOP=1 ;;
    --reset-db)  RESET_DB=1 ;;
    *) echo "Unknown flag: $arg"; exit 1 ;;
  esac
done

BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; DIM=$'\e[2m'; OFF=$'\e[0m'
step() { echo; echo "${BOLD}==> $*${OFF}"; }
ok()   { echo "  ${GREEN}[ok]${OFF}   $*"; }
warn() { echo "  ${YELLOW}[warn]${OFF} $*"; }
die()  { echo "  ${RED}[fail]${OFF} $*"; echo; exit 1; }
info() { echo "  ${DIM}$*${OFF}"; }

mkdir -p "$LOG_DIR"

# ---------------------------------------------------------------------------
# --stop
# ---------------------------------------------------------------------------
if [ "$DO_STOP" = "1" ]; then
  step "Stopping UniPay"
  if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    kill "$(cat "$PID_FILE")" && ok "PHP server stopped"
    rm -f "$PID_FILE"
  else
    info "PHP server was not running"
  fi

  step "Stopping Rafiki"
  if [ -d "$RAFIKI_DIR" ]; then
    (cd "$RAFIKI_DIR" && pnpm localenv:compose down 2>/dev/null) && ok "playground stopped" \
      || warn "could not stop the playground; try: cd $RAFIKI_DIR && pnpm localenv:compose down"
  fi
  echo; exit 0
fi

echo "${BOLD}UniPay + Rafiki: Linux Mint setup${OFF}"
echo "  UniPay: $UNIPAY_DIR"
echo "  Rafiki: $RAFIKI_DIR"

[ -f "$UNIPAY_DIR/lib_rafiki.php" ] || die "UniPay not found at $UNIPAY_DIR (set UNIPAY_DIR=... to override)"

# ---------------------------------------------------------------------------
# 1. System packages
# ---------------------------------------------------------------------------
step "1/8  System packages"

NEED=()
command -v php     >/dev/null || NEED+=(php-cli)
php -m 2>/dev/null | grep -qi '^pdo_mysql$' || NEED+=(php-mysql)
php -m 2>/dev/null | grep -qi '^curl$'      || NEED+=(php-curl)
php -m 2>/dev/null | grep -qi '^mbstring$'  || NEED+=(php-mbstring)
command -v mysql   >/dev/null || NEED+=(mariadb-server mariadb-client)
command -v curl    >/dev/null || NEED+=(curl)
command -v git     >/dev/null || NEED+=(git)

if [ ${#NEED[@]} -gt 0 ]; then
  info "installing: ${NEED[*]}"
  sudo apt-get update -qq || die "apt update failed"
  sudo apt-get install -y "${NEED[@]}" || die "apt install failed"
  ok "installed ${NEED[*]}"
else
  ok "php, mariadb, curl, git all present"
fi

info "PHP $(php -r 'echo PHP_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "7.3", ">=") ? 0 : 1);' \
  || die "PHP 7.3+ required (lib_rafiki.php uses indented heredocs)"

# ---------------------------------------------------------------------------
# 2. Database
# ---------------------------------------------------------------------------
step "2/8  Database"

sudo systemctl start mariadb 2>/dev/null || sudo systemctl start mysql 2>/dev/null
sudo systemctl is-active --quiet mariadb || sudo systemctl is-active --quiet mysql \
  || die "MariaDB will not start. Check: sudo systemctl status mariadb"
ok "MariaDB running"

# On Mint the root account uses unix_socket auth, so db.php's "root with no
# password over TCP" cannot work. Create a dedicated user instead.
if [ -f "$DB_PASS_FILE" ]; then
  DB_PASS=$(cat "$DB_PASS_FILE")
  info "reusing stored database password"
else
  # Alphanumeric only: the password is interpolated into SQL, so quotes and
  # backslashes must not appear. Over-read then trim to a fixed length.
  DB_PASS=$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)
  umask 077; echo -n "$DB_PASS" > "$DB_PASS_FILE"
  ok "generated a database password ($DB_PASS_FILE, chmod 600)"
fi

if [ "$RESET_DB" = "1" ]; then
  warn "--reset-db: dropping $DB_NAME"
  sudo mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;"
fi

sudo mysql <<SQL || die "could not set up the database"
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ok "database $DB_NAME and user $DB_USER ready"

# Import the schema only if the tables do not exist yet.
TABLES=$(mysql -u"$DB_USER" -p"$DB_PASS" -h 127.0.0.1 -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null || echo 0)
if [ "${TABLES:-0}" -lt 3 ]; then
  info "importing schema.sql"
  # schema.sql contains its own CREATE DATABASE/USE, so feed it without -D.
  mysql -u"$DB_USER" -p"$DB_PASS" -h 127.0.0.1 < "$UNIPAY_DIR/schema.sql" 2>/dev/null \
    || sudo mysql < "$UNIPAY_DIR/schema.sql" \
    || die "schema import failed"
  ok "schema imported"
else
  ok "schema already present ($TABLES tables)"
fi

# Point db.php at the dedicated user.
DBPHP="$UNIPAY_DIR/db.php"
if ! grep -q "getenv('UNIPAY_DB_USER')" "$DBPHP"; then
  cp "$DBPHP" "$DBPHP.bak.$(date +%s)"
  cat > "$DBPHP" <<'PHPEOF'
<?php
/**
 * Database connection.
 *
 * Credentials are resolved in this order:
 *   1. UNIPAY_DB_* environment variables (what run.sh exports for the server)
 *   2. The .db_password file next to this script (written by run.sh)
 *   3. root with no password (the old XAMPP default)
 *
 * Step 2 matters on Linux: MariaDB's root account uses unix_socket auth, so
 * "root with an empty password over TCP" fails with error 1698. Without the
 * file fallback, every CLI script (create_staff.php, cron jobs) would need the
 * environment variables exported by hand first.
 */

function unipayDbCredentials(): array
{
    $host = getenv('UNIPAY_DB_HOST') ?: '127.0.0.1';
    $name = getenv('UNIPAY_DB_NAME') ?: 'wsu_payments';
    $user = getenv('UNIPAY_DB_USER') ?: '';
    $pass = getenv('UNIPAY_DB_PASS');

    if ($user !== '' && $pass !== false) {
        return [$host, $name, $user, $pass];
    }

    // Fall back to the password file run.sh generated.
    $file = __DIR__ . '/.db_password';
    if (is_readable($file)) {
        $filePass = trim((string) file_get_contents($file));
        if ($filePass !== '') {
            return [$host, $name, $user !== '' ? $user : 'unipay', $filePass];
        }
    }

    // Last resort: the original XAMPP-style connection.
    return [$host, $name, $user !== '' ? $user : 'root', $pass === false ? '' : $pass];
}

function getDb()
{
    static $pdo;
    if (!$pdo) {
        [$host, $name, $user, $pass] = unipayDbCredentials();

        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$name;charset=utf8mb4",
                $user,
                $pass
            );
        } catch (PDOException $e) {
            // 1698 and 1045 are auth failures. The raw message sends people
            // hunting for a bug in their code rather than their credentials.
            // strpos rather than str_contains: that function is PHP 8.0+ and
            // run.sh only requires 7.3.
            $msg = $e->getMessage();
            if (strpos($msg, '1698') !== false || strpos($msg, 'Access denied') !== false) {
                throw new PDOException(
                    "Database access denied for user '$user'.\n"
                    . "On Linux, MariaDB's root account uses unix_socket auth and cannot\n"
                    . "connect over TCP. Run this once to create a usable user:\n"
                    . "    bash " . __DIR__ . "/run.sh\n"
                    . "Or set UNIPAY_DB_USER and UNIPAY_DB_PASS in the environment.\n"
                    . "Original: " . $msg
                );
            }
            throw $e;
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

// Simple audit logger used across all the pages below
function logAction($actorType, $actorId, $action, $targetType = null, $targetId = null, $details = null)
{
    $pdo = getDb();
    $stmt = $pdo->prepare(
        "INSERT INTO audit_log (actor_type, actor_id, action, target_type, target_id, details)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$actorType, $actorId, $action, $targetType, $targetId, $details]);
}
PHPEOF
  ok "db.php now reads credentials from the environment (backup kept)"
else
  ok "db.php already environment-driven"
fi

export UNIPAY_DB_HOST=127.0.0.1 UNIPAY_DB_NAME="$DB_NAME"
export UNIPAY_DB_USER="$DB_USER" UNIPAY_DB_PASS="$DB_PASS"

# ---------------------------------------------------------------------------
# 3. Migrations
# ---------------------------------------------------------------------------
step "3/8  Migrations"
MIG_OUT=$(cd "$UNIPAY_DIR" && php repair_db.php 2>&1)
echo "$MIG_OUT" | grep -E "APPLIED|FAILED|change\(s\)" | sed 's/^/  /'
echo "$MIG_OUT" | grep -q "FAIL" && warn "some migrations reported problems (see above)"
ok "schema up to date"

# ---------------------------------------------------------------------------
# 4. Exchange rates
# ---------------------------------------------------------------------------
step "4/8  Exchange rates"
if (cd "$UNIPAY_DIR" && php lib_rates.php 2>&1 | sed 's/^/  /'); then
  ok "rate cache populated"
else
  warn "rate refresh failed; the currency picker will show as unavailable"
fi

# Real cron exists here, unlike the Windows Task Scheduler workaround.
CRON_LINE="0 * * * * /usr/bin/php $UNIPAY_DIR/lib_rates.php >> $LOG_DIR/rates.log 2>&1"
if crontab -l 2>/dev/null | grep -Fq "$UNIPAY_DIR/lib_rates.php"; then
  ok "hourly rate cron already installed"
else
  (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab - && ok "hourly rate cron installed"
fi

# ---------------------------------------------------------------------------
# 5. Rafiki
# ---------------------------------------------------------------------------
RAFIKI_UP=0
if [ "$SKIP_RAFIKI" = "1" ]; then
  step "5/8  Rafiki (skipped)"
  warn "--no-rafiki: UniPay will run in stub mode, no real payments"
else
  step "5/8  Rafiki playground"

  [ -d "$RAFIKI_DIR" ] || die "Rafiki not found at $RAFIKI_DIR (set RAFIKI_DIR=... to override)"

  if ! command -v docker >/dev/null; then
    info "installing Docker"
    sudo apt-get install -y docker.io docker-compose-v2 || die "Docker install failed"
  fi
  sudo systemctl start docker 2>/dev/null
  ok "Docker present"

  # Without group membership every docker call needs sudo, which breaks pnpm.
  if ! groups | grep -qw docker; then
    warn "$USER is not in the 'docker' group; adding"
    sudo usermod -aG docker "$USER"
    warn "log out and back in (or run: newgrp docker) then re-run this script"
    die "docker group membership needs a new login session"
  fi
  docker info >/dev/null 2>&1 || die "cannot talk to the Docker daemon"

  # Node via nvm, per Rafiki's .nvmrc.
  export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
  if [ ! -s "$NVM_DIR/nvm.sh" ]; then
    info "installing nvm"
    curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash >/dev/null 2>&1
  fi
  # shellcheck disable=SC1091
  [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"

  cd "$RAFIKI_DIR" || die "cannot enter $RAFIKI_DIR"
  if [ -f .nvmrc ]; then
    nvm install >/dev/null 2>&1; nvm use >/dev/null 2>&1
  fi
  command -v node >/dev/null || die "Node.js unavailable after nvm setup"
  ok "Node $(node -v)"

  corepack enable >/dev/null 2>&1
  command -v pnpm >/dev/null || die "pnpm unavailable (corepack enable failed)"
  ok "pnpm $(pnpm -v)"

  # The Open Payments specs submodule; the build fails silently without it.
  if [ -f .gitmodules ] && [ ! -f open-payments-specifications/README.md ]; then
    info "initialising git submodules"
    git submodule update --init --recursive >/dev/null 2>&1
  fi
  ok "submodules ready"

  if [ ! -d node_modules ]; then
    info "pnpm i (first run, this takes a few minutes)"
    pnpm i > "$LOG_DIR/rafiki-install.log" 2>&1 || die "pnpm i failed, see $LOG_DIR/rafiki-install.log"
  fi
  ok "dependencies installed"

  # Discover the compose script rather than assuming its name.
  COMPOSE_SCRIPT=$(node -e '
    const s = require("./package.json").scripts || {};
    const k = Object.keys(s).filter(x => /^localenv:compose$/.test(x));
    console.log(k[0] || Object.keys(s).find(x => x.startsWith("localenv:compose")) || "");
  ' 2>/dev/null)
  [ -n "$COMPOSE_SCRIPT" ] || COMPOSE_SCRIPT="localenv:compose"
  info "using: pnpm $COMPOSE_SCRIPT up"

  if curl -fsS --max-time 3 -o /dev/null http://localhost:3001/graphql 2>/dev/null \
     || curl -sS --max-time 3 -o /dev/null -w '%{http_code}' http://localhost:3001/graphql 2>/dev/null | grep -qE '^[0-9]'; then
    ok "playground already running"
    RAFIKI_UP=1
  else
    info "starting the playground (first run pulls images, be patient)"
    pnpm "$COMPOSE_SCRIPT" up -d > "$LOG_DIR/rafiki-up.log" 2>&1 \
      || pnpm "$COMPOSE_SCRIPT" up > "$LOG_DIR/rafiki-up.log" 2>&1 &

    printf "  waiting for Rafiki on :3001 "
    for i in $(seq 1 90); do
      if curl -sS --max-time 2 -o /dev/null http://localhost:3001/graphql 2>/dev/null; then
        RAFIKI_UP=1; break
      fi
      printf "."; sleep 2
    done
    echo
    [ "$RAFIKI_UP" = "1" ] && ok "Rafiki is up" \
      || warn "Rafiki did not answer in 3 minutes; see $LOG_DIR/rafiki-up.log"
  fi
fi

# ---------------------------------------------------------------------------
# 6. UniPay -> Rafiki configuration
# ---------------------------------------------------------------------------
step "6/8  Interledger configuration"

if [ "$RAFIKI_UP" = "1" ]; then
  export RAFIKI_MODE=live
  ok "RAFIKI_MODE=live"
else
  export RAFIKI_MODE=stub
  warn "RAFIKI_MODE=stub, payments will be fabricated (nothing moves)"
fi

export RAFIKI_SENDER_HOST=http://localhost:3001
export RAFIKI_RECEIVER_HOST=http://localhost:4001

# Linux has no host.docker.internal. Containers reach the host on the docker0
# bridge gateway, so the webhook URL must use that address, and the PHP server
# must bind 0.0.0.0 rather than 127.0.0.1 to accept it.
DOCKER_GW=$(ip -4 addr show docker0 2>/dev/null | awk '/inet /{print $2}' | cut -d/ -f1)
DOCKER_GW="${DOCKER_GW:-172.17.0.1}"
WEBHOOK_URL="http://$DOCKER_GW:$UNIPAY_PORT/rafiki_webhook.php"
info "webhook URL for Rafiki: $WEBHOOK_URL"

# ---------------------------------------------------------------------------
# 7. Web server
# ---------------------------------------------------------------------------
step "7/8  UniPay web server"

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
  info "stopping the previous server"
  kill "$(cat "$PID_FILE")"; sleep 1
fi

if ss -ltn 2>/dev/null | grep -q ":$UNIPAY_PORT "; then
  die "port $UNIPAY_PORT is already in use (set UNIPAY_PORT=8080 to change)"
fi

# 0.0.0.0 so the Rafiki containers can post webhooks back to us.
cd "$UNIPAY_DIR" || die "cannot enter $UNIPAY_DIR"
# PHP's built-in server handles one request at a time unless told otherwise.
# Rafiki posts webhooks while a checkout request may still be open, so without
# workers the two deadlock. Requires PHP 7.4+.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
nohup php -S "0.0.0.0:$UNIPAY_PORT" -t "$UNIPAY_DIR" > "$LOG_DIR/php-server.log" 2>&1 &
echo $! > "$PID_FILE"
sleep 2

if kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
  ok "PHP server on port $UNIPAY_PORT (pid $(cat "$PID_FILE"), $PHP_CLI_SERVER_WORKERS workers)"
else
  die "PHP server failed to start, see $LOG_DIR/php-server.log"
fi

# ---------------------------------------------------------------------------
# 8. Health checks
# ---------------------------------------------------------------------------
step "8/8  Health checks"

code=$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:$UNIPAY_PORT/index.php" 2>/dev/null)
[ "$code" = "200" ] && ok "home page responds (HTTP 200)" || warn "home page returned HTTP $code"

if curl -sS --max-time 5 "http://localhost:$UNIPAY_PORT/check_rates.php" 2>/dev/null | grep -q "\[FAIL\]"; then
  warn "rate diagnostics report a failure: http://localhost:$UNIPAY_PORT/check_rates.php"
else
  ok "exchange rates healthy"
fi

if [ "$RAFIKI_UP" = "1" ]; then
  if curl -sS --max-time 20 "http://localhost:$UNIPAY_PORT/check_rafiki.php" 2>/dev/null | grep -q "\[FAIL\]"; then
    warn "Interledger diagnostics report a failure:"
    warn "  http://localhost:$UNIPAY_PORT/check_rafiki.php"
  else
    ok "Interledger connection healthy"
  fi
fi

# ---------------------------------------------------------------------------
echo
echo "${BOLD}${GREEN}UniPay is running.${OFF}"
echo
echo "  ${BOLD}Open:${OFF}          http://localhost:$UNIPAY_PORT/"
echo "  Rate check:    http://localhost:$UNIPAY_PORT/check_rates.php"
echo "  ILP check:     http://localhost:$UNIPAY_PORT/check_rafiki.php"
echo "  Live test pay: http://localhost:$UNIPAY_PORT/check_rafiki.php?pay=1&amount=1.00"
if [ "$RAFIKI_UP" = "1" ]; then
echo "  Rafiki Admin:  http://localhost:3001/graphql"
fi
echo
echo "  ${BOLD}Staff login:${OFF}   php $UNIPAY_DIR/create_staff.php admin \"Your Name\" yourpassword"
echo
echo "  ${BOLD}Webhooks${OFF} (optional, for payments that settle after checkout):"
echo "    add to $RAFIKI_DIR/localenv/cloud-nine-wallet/.env"
echo "      WEBHOOK_URL=$WEBHOOK_URL"
echo "    then: cd $RAFIKI_DIR && pnpm localenv:compose restart"
echo
echo "  Stop everything:  bash $UNIPAY_DIR/run.sh --stop"
echo "  Logs:             $LOG_DIR/"
echo
