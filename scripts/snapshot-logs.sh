#!/usr/bin/env bash
set -euo pipefail

# ВсёПонятно — snapshot logs for Codex
# Usage:
#   ./scripts/snapshot-logs.sh
# Env flags:
#   UPDATE_WORDPRESS=1   -> pull+restart wordpress service before collecting logs
#   WITH_DIRECTUS=1      -> also collect directus logs (default: 1)
#   LINES=400            -> number of lines for tails (default: 400)

LINES="${LINES:-400}"
UPDATE_WORDPRESS="${UPDATE_WORDPRESS:-0}"
WITH_DIRECTUS="${WITH_DIRECTUS:-1}"

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"

LOG_DIR="$ROOT/runtime/logs"
mkdir -p "$LOG_DIR"
touch "$LOG_DIR/.gitkeep"

ts() { date +"%Y-%m-%d_%H-%M-%S"; }

say() { printf "\n==> %s\n" "$*"; }

have_cmd() { command -v "$1" >/dev/null 2>&1; }

# Sanitize secrets & PII in-place (best-effort).
sanitize_file() {
  local f="$1"
  [ -f "$f" ] || return 0

  # Use perl for robust regex
  perl -0777 -i -pe '
    # Redact Authorization headers
    s/(Authorization:\s*Bearer\s+)[A-Za-z0-9._\-~+/]+=*/$1[REDACTED]/gi;
    s/("authorization"\s*:\s*"Bearer\s+)[^"]+(")/$1[REDACTED]$2/gi;

    # Redact tokens / secrets in common patterns
    s/(\b(token|api[_-]?token|access[_-]?token|refresh[_-]?token|secret|password|passwd|key)\b\s*[:=]\s*)(")?[^"\s]+(")?/$1[REDACTED]/gi;
    s/(\bDIRECTUS_API_TOKEN=)[^\n]+/$1[REDACTED]/g;
    s/(\bDIRECTUS_SECRET=)[^\n]+/$1[REDACTED]/g;
    s/(\bSECRET=)[^\n]+/$1[REDACTED]/g;
    s/(\bKEY=)[^\n]+/$1[REDACTED]/g;

    # Redact cookies (very noisy + sensitive)
    s/(Cookie:\s*)[^\n]+/$1[REDACTED]/gi;
    s/("cookie"\s*:\s*")[^"]+(")/$1[REDACTED]$2/gi;

    # Redact email addresses (optional)
    s/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/[REDACTED_EMAIL]/g;

    # Redact IPv4 addresses (optional)
    s/\b(\d{1,3}\.){3}\d{1,3}\b/[REDACTED_IP]/g;
  ' "$f" || true
}

write_cmd_output() {
  local out="$1"; shift
  {
    echo "# cmd: $*"
    echo "# time: $(date -Is)"
    echo
    "$@" 2>&1 || true
  } > "$out"
  sanitize_file "$out"
}

say "Snapshot target: $LOG_DIR"
say "Options: LINES=$LINES UPDATE_WORDPRESS=$UPDATE_WORDPRESS WITH_DIRECTUS=$WITH_DIRECTUS"

# 1) (Optional) update wordpress container image
if [ "$UPDATE_WORDPRESS" = "1" ]; then
  say "Updating WordPress container (docker compose pull + up -d)..."
  if [ -f "$ROOT/docker/docker-compose.yml" ]; then
    ( cd "$ROOT/docker" && docker compose pull wordpress && docker compose up -d wordpress ) || true
  else
    echo "WARN: docker/docker-compose.yml not found, skipping UPDATE_WORDPRESS"
  fi
fi

# 2) Compose state + versions
say "Collecting runtime state..."
if [ -f "$ROOT/docker/docker-compose.yml" ]; then
  write_cmd_output "$LOG_DIR/docker-compose.ps.$(ts).txt" bash -lc "cd '$ROOT/docker' && docker compose ps"
  write_cmd_output "$LOG_DIR/docker-compose.config.$(ts).txt" bash -lc "cd '$ROOT/docker' && docker compose config"
else
  write_cmd_output "$LOG_DIR/docker.ps.$(ts).txt" docker ps
fi

# 3) Nginx logs (host)
say "Collecting Nginx logs (host)..."
if [ -r /var/log/nginx/access.log ]; then
  tail -n "$LINES" /var/log/nginx/access.log > "$LOG_DIR/nginx-access.tail.txt"
  sanitize_file "$LOG_DIR/nginx-access.tail.txt"
else
  echo "No access.log (or no permissions) at /var/log/nginx/access.log" > "$LOG_DIR/nginx-access.tail.txt"
fi

if [ -r /var/log/nginx/error.log ]; then
  tail -n "$LINES" /var/log/nginx/error.log > "$LOG_DIR/nginx-error.tail.txt"
  sanitize_file "$LOG_DIR/nginx-error.tail.txt"
else
  echo "No error.log (or no permissions) at /var/log/nginx/error.log" > "$LOG_DIR/nginx-error.tail.txt"
fi

# 4) Docker logs (containers)
say "Collecting Docker logs..."
write_cmd_output "$LOG_DIR/docker-wordpress.tail.txt" docker logs --tail "$LINES" vse_wordpress

if [ "$WITH_DIRECTUS" = "1" ]; then
  write_cmd_output "$LOG_DIR/docker-directus.tail.txt" docker logs --tail "$LINES" vse_directus
fi

write_cmd_output "$LOG_DIR/docker-postgres.tail.txt" docker logs --tail "$LINES" vse_postgres
write_cmd_output "$LOG_DIR/docker-mariadb.tail.txt" docker logs --tail "$LINES" vse_mariadb

# 5) WordPress debug.log in volume (if enabled)
say "Collecting WordPress debug.log..."
WP_DEBUG_SRC="$ROOT/docker/volumes/wordpress/wp-content/debug.log"
WP_DEBUG_DST="$LOG_DIR/wp-debug.tail.txt"

if [ -f "$WP_DEBUG_SRC" ]; then
  tail -n "$LINES" "$WP_DEBUG_SRC" > "$WP_DEBUG_DST"
  sanitize_file "$WP_DEBUG_DST"
else
  echo "No debug.log found at $WP_DEBUG_SRC (enable WP_DEBUG_LOG in wp-config.php if needed)" > "$WP_DEBUG_DST"
fi

# 6) Health checks (HTTP)
say "Collecting HTTP health checks..."
write_cmd_output "$LOG_DIR/http-health.$(ts).txt" bash -lc "
  set -e
  curl -sS -i https://directus.xn--b1awacccnl0jqa.xn--p1ai/server/health | head -n 40
  echo
  curl -sS -i https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/ | head -n 40
"

say "Done. Files:"
ls -la "$LOG_DIR"
