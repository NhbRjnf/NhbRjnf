#!/usr/bin/env bash
set -euo pipefail

# ВсёПонятно — snapshot logs + directus snapshots for Codex
# Usage:
#   ./scripts/snapshot-logs.sh
# Env flags:
#   UPDATE_WORDPRESS=1   -> pull+restart wordpress service before collecting logs
#   WITH_DIRECTUS=1      -> also collect directus logs + directus snapshots (default: 1)
#   LINES=400            -> number of lines for tails (default: 400)
#   DIRECTUS_URL=...     -> override Directus public URL (default: from WP container env)
#   DIRECTUS_TOKEN=...   -> override token (default: from WP container env)

LINES="${LINES:-400}"
UPDATE_WORDPRESS="${UPDATE_WORDPRESS:-0}"
WITH_DIRECTUS="${WITH_DIRECTUS:-1}"

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$ROOT"

LOG_DIR="$ROOT/runtime/logs"
mkdir -p "$LOG_DIR"
touch "$LOG_DIR/.gitkeep"

DIRECTUS_DIR="$ROOT/runtime/directus"
mkdir -p "$DIRECTUS_DIR"
touch "$DIRECTUS_DIR/.gitkeep"

ts() { date +"%Y-%m-%d_%H-%M-%S"; }
say() { printf "\n==> %s\n" "$*"; }

# Sanitize secrets & PII in-place (best-effort).
sanitize_file() {
  local f="$1"
  [ -f "$f" ] || return 0

  perl -0777 -i -pe '
    s/(Authorization:\s*Bearer\s+)[A-Za-z0-9._\-~+/]+=*/$1[REDACTED]/gi;
    s/("authorization"\s*:\s*"Bearer\s+)[^"]+(")/$1[REDACTED]$2/gi;

    s/(\b(token|api[_-]?token|access[_-]?token|refresh[_-]?token|secret|password|passwd|key)\b\s*[:=]\s*)(")?[^"\s]+(")?/$1[REDACTED]/gi;
    s/(\bDIRECTUS_API_TOKEN=)[^\n]+/$1[REDACTED]/g;
    s/(\bDIRECTUS_SECRET=)[^\n]+/$1[REDACTED]/g;

    s/(Cookie:\s*)[^\n]+/$1[REDACTED]/gi;
    s/("cookie"\s*:\s*")[^"]+(")/$1[REDACTED]$2/gi;

    s/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/[REDACTED_EMAIL]/g;
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

# Read env vars from wordpress container (single source of truth for token/base url)
wp_env() {
  docker exec vse_wordpress sh -lc "$1" 2>/dev/null || true
}

directus_url_from_wp() {
  wp_env 'printf "%s" "${DIRECTUS_PUBLIC_URL:-${DIRECTUS_BASE_URL:-}}"' || true
}

directus_token_from_wp() {
  wp_env 'printf "%s" "${DIRECTUS_API_TOKEN:-}"' || true
}

curl_directus_json() {
  local base="$1"
  local token="$2"
  local path="$3"
  local out="$4"

  {
    echo "# time: $(date -Is)"
    echo "# url: ${base}${path}"
    echo
    curl -sS -i \
      -H "Authorization: Bearer ${token}" \
      -H "Accept: application/json" \
      "${base}${path}" 2>&1 || true
  } > "$out"

  sanitize_file "$out"
}

say "Snapshot target logs: $LOG_DIR"
say "Snapshot target directus: $DIRECTUS_DIR"
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
write_cmd_output "$LOG_DIR/docker-postgres.tail.txt" docker logs --tail "$LINES" vse_postgres
write_cmd_output "$LOG_DIR/docker-mariadb.tail.txt" docker logs --tail "$LINES" vse_mariadb

if [ "$WITH_DIRECTUS" = "1" ]; then
  write_cmd_output "$LOG_DIR/docker-directus.tail.txt" docker logs --tail "$LINES" vse_directus
fi

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
  curl -sS -i https://directus.xn--b1awacccnl0jqa.xn--p1ai/server/health | head -n 60
  echo
  curl -sS -i https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/ | head -n 60
"

# 7) Directus snapshots (schema / roles / permissions / flows / items metadata)
if [ "$WITH_DIRECTUS" = "1" ]; then
  say "Collecting Directus snapshots via API..."

  DIRECTUS_URL="${DIRECTUS_URL:-$(directus_url_from_wp)}"
  DIRECTUS_TOKEN="${DIRECTUS_TOKEN:-$(directus_token_from_wp)}"

  if [ -z "$DIRECTUS_URL" ] || [ -z "$DIRECTUS_TOKEN" ]; then
    echo "Missing DIRECTUS_URL or DIRECTUS_TOKEN; cannot export Directus snapshots." > "$DIRECTUS_DIR/README.missing-env.txt"
  else
    SNAP_TS="$(ts)"
    # Most important: schema snapshot (collections/fields/relations/permissions depending on Directus version)
    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/schema/snapshot" "$DIRECTUS_DIR/schema-snapshot.$SNAP_TS.http.txt"

    # Useful admin metadata (some endpoints may 404 depending on version/modules)
    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/collections" "$DIRECTUS_DIR/collections.$SNAP_TS.http.txt"
    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/fields" "$DIRECTUS_DIR/fields.$SNAP_TS.http.txt"
    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/relations" "$DIRECTUS_DIR/relations.$SNAP_TS.http.txt"

    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/roles" "$DIRECTUS_DIR/roles.$SNAP_TS.http.txt"
    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/permissions?limit=-1" "$DIRECTUS_DIR/permissions.$SNAP_TS.http.txt"

    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/flows?limit=-1" "$DIRECTUS_DIR/flows.$SNAP_TS.http.txt"
    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/operations?limit=-1" "$DIRECTUS_DIR/operations.$SNAP_TS.http.txt"

    # If Access Policies exist in your Directus build, this will succeed; otherwise will be 404 (still useful to see)
    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/policies?limit=-1" "$DIRECTUS_DIR/policies.$SNAP_TS.http.txt"

    # Quick “who am I” check (confirms token scope)
    curl_directus_json "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/users/me" "$DIRECTUS_DIR/users-me.$SNAP_TS.http.txt"
  fi
fi

say "Done. Logs:"
ls -la "$LOG_DIR"
say "Done. Directus:"
ls -la "$DIRECTUS_DIR"
