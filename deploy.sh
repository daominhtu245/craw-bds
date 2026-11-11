#!/usr/bin/env bash
# Expat Lead Radar: deploy or update with Docker Compose. See docs/DEPLOY.md.
#
#   ./deploy.sh           first install or update (git pull is up to you)
#
# What it does:
#   1. creates .env from .env.example if missing (then stops so you can edit it)
#   2. fills empty DB_PASSWORD, DB_ROOT_PASSWORD, INGEST_TOKEN with random values
#   3. generates APP_KEY if empty, builds the images
#   4. starts the stack (migrations run automatically when the app container starts)
#   5. seeds the database the first time only (admin account, areas, keywords, demo listings)
#   6. waits until http://APP_PORT/up answers
set -euo pipefail

cd "$(dirname "$0")"

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[!] %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

command -v docker >/dev/null || die "Docker is not installed."
docker compose version >/dev/null 2>&1 || die "The Docker Compose plugin is missing (docker compose ...)."

# --------------------------------------------------------------------- .env
if [ ! -f .env ]; then
    cp .env.example .env
    chmod 600 .env
    say "Created .env from .env.example."
    echo "Edit at least APP_URL, APP_PORT and ADMIN_EMAIL in .env, then run ./deploy.sh again."
    exit 0
fi

# Value of KEY in .env (without quotes and trailing " # comment").
env_get() {
    local line
    line="$(grep -E "^$1=" .env | tail -n 1 || true)"
    line="${line#*=}"
    line="$(printf '%s' "$line" | sed -E 's/[[:space:]]+#.*$//; s/^[[:space:]]+//; s/[[:space:]]+$//')"
    line="${line%\"}"; line="${line#\"}"
    printf '%s' "$line"
}

# Replace (or append) KEY=VALUE in .env. VALUE must not contain "|".
env_set() {
    if grep -qE "^$1=" .env; then
        sed -i -E "s|^$1=.*|$1=$2|" .env
    else
        printf '%s=%s\n' "$1" "$2" >> .env
    fi
}

random() { openssl rand -hex 24 2>/dev/null || head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

for key in DB_PASSWORD DB_ROOT_PASSWORD INGEST_TOKEN; do
    if [ -z "$(env_get "$key")" ]; then
        env_set "$key" "$(random)"
        say "Generated $key in .env."
    fi
done

[ "$(env_get APP_DEBUG)" = "true" ] && warn "APP_DEBUG=true: set APP_DEBUG=false on a public server."
case "$(env_get APP_URL)" in
    https://*) ;;
    *) warn "APP_URL is not https://... Photos, links and the Telegram Mini App need the real HTTPS domain." ;;
esac

APP_PORT_VALUE="$(env_get APP_PORT)"
APP_PORT_VALUE="${APP_PORT_VALUE:-127.0.0.1:8080}"
case "$APP_PORT_VALUE" in
    *:*) LOCAL_URL="http://${APP_PORT_VALUE}" ;;
    *)   LOCAL_URL="http://127.0.0.1:${APP_PORT_VALUE}"
         warn "APP_PORT=${APP_PORT_VALUE} listens on every interface. Prefer APP_PORT=127.0.0.1:${APP_PORT_VALUE}." ;;
esac

# Telegram session + collector spool, owned by the collector user (uid 1000).
mkdir -p data
if [ "$(stat -c %u data 2>/dev/null || echo 1000)" != "1000" ]; then
    chown -R 1000:1000 data 2>/dev/null || sudo chown -R 1000:1000 data || warn "Could not chown ./data to 1000:1000."
fi

if [ -z "$(env_get APP_KEY)" ]; then
    # Same format as `php artisan key:generate`: base64 of 32 random bytes.
    key="base64:$(openssl rand -base64 32 2>/dev/null || head -c 32 /dev/urandom | base64)"
    [ "${#key}" -gt 40 ] || die "Could not generate APP_KEY."
    env_set APP_KEY "$key"
    say "Generated APP_KEY. Back up .env: without APP_KEY encrypted settings and contacts cannot be read."
fi

# -------------------------------------------------------------------- build
say "Building images (first time takes a few minutes)..."
docker compose build


# -------------------------------------------------------------------- start
say "Starting containers..."
docker compose up -d --remove-orphans

say "Waiting for the app (migrations run on start)..."
for i in $(seq 1 60); do
    if curl -fsS "${LOCAL_URL}/up" >/dev/null 2>&1; then
        break
    fi
    [ "$i" -eq 60 ] && { docker compose logs --tail=50 app; die "The app did not answer on ${LOCAL_URL}/up."; }
    sleep 3
done

# Seed only on an empty database: re-seeding would reset keywords edited on the dashboard.
users="$(docker compose exec -T app php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tr -d '\r' | tail -n 1)"
if [ "${users:-0}" = "0" ]; then
    say "First install: seeding (admin account, areas, keywords, demo listings)..."
    docker compose exec -T app php artisan db:seed --force
    if [ -z "$(env_get ADMIN_PASSWORD)" ]; then
        warn "ADMIN_PASSWORD was empty: the generated password is printed above. Change it after logging in (Profile)."
    fi
fi

say "Done."
docker compose ps
cat <<EOF

  Local check : ${LOCAL_URL}/up
  Public site : $(env_get APP_URL)/
  Dashboard   : $(env_get APP_URL)/admin
  Collectors  : COMPOSE_PROFILES=$(env_get COMPOSE_PROFILES) (edit .env and run ./deploy.sh again)

EOF
