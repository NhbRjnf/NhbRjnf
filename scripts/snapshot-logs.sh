#!/usr/bin/env bash
set -euo pipefail

# ВсёПонятно — snapshot logs + directus snapshots for Codex
#
# Usage:
#   ./scripts/snapshot-logs.sh
#
# Env flags:
#   UPDATE_WORDPRESS=1   -> pull+restart wordpress service before collecting logs
#   WITH_DIRECTUS=1      -> also collect directus logs + directus snapshots (default: 1)
#   LINES=400            -> number of lines for tails (default: 400)
#   DIRECTUS_URL=...     -> override Directus public URL (default: from WP container env)
#   DIRECTUS_TOKEN=...   -> override token (default: from WP container env)
#
# Retention / pruning:
#   PRUNE_DAYS=7         -> delete runtime snapshots older than N days (default: 7)
#   KEEP_LOG_SETS=10     -> keep last N docker-compose/http-health timestamped logs (default: 10)
#   KEEP_DIRECTUS_HTTP=10-> keep last N directus *.http.txt (default: 10)
#   KEEP_DIRECTUS_YAML=10-> keep last N directus schema.*.yaml (default: 10)

LINES="${LINES:-400}"
UPDATE_WORDPRESS="${UPDATE_WORDPRESS:-0}"
WITH_DIRECTUS="${WITH_DIRECTUS:-1}"

PRUNE_DAYS="${PRUNE_DAYS:-7}"
KEEP_LOG_SETS="${KEEP_LOG_SETS:-10}"
KEEP_DIRECTUS_HTTP="${KEEP_DIRECTUS_HTTP:-10}"
KEEP_DIRECTUS_YAML="${KEEP_DIRECTUS_YAML:-10}"

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

# --- helpers ---
have_cmd() { command -v "$1" >/dev/null 2>&1; }

# Sanitize secrets & PII in-place (best-effort).
sanitize_file() {
  local f="$1"
  [ -f "$f" ] || return 0

  # NOTE: regex fixed (no broken charclass with /)
  perl -0777 -i -pe '
    # Authorization Bearer <token>
    s/(Authorization:\s*Bearer\s+)[A-Za-z0-9._~+\/-]+/$1[REDACTED]/gi;
    s/("authorization"\s*:\s*"Bearer\s+)[^"]+(")/$1[REDACTED]$2/gi;

    # Common token/secret patterns
    s/(\b(token|api[_-]?token|access[_-]?token|refresh[_-]?token|secret|password|passwd|key)\b\s*[:=]\s*)(")?[^"\s]+(")?/$1[REDACTED]/gi;
    s/(\bDIRECTUS_API_TOKEN=)[^\n]+/$1[REDACTED]/g;
    s/(\bDIRECTUS_SECRET=)[^\n]+/$1[REDACTED]/g;
    s/(\bDIRECTUS_KEY=)[^\n]+/$1[REDACTED]/g;

    # Cookies
    s/(Cookie:\s*)[^\n]+/$1[REDACTED]/gi;
    s/("cookie"\s*:\s*")[^"]+(")/$1[REDACTED]$2/gi;

    # Emails
    s/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/[REDACTED_EMAIL]/g;

    # IPv4
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

curl_directus_http() {
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

# --- pruning ---
# Delete files older than PRUNE_DAYS (but keep .gitkeep)
prune_by_age() {
  local dir="$1"
  local days="$2"
  [ -d "$dir" ] || return 0
  find "$dir" -type f -name ".gitkeep" -prune -o -type f -mtime +"$days" -print -delete 2>/dev/null || true
}

# Keep only last N files matching glob (by mtime); delete the rest
keep_last_n() {
  local dir="$1"
  local glob="$2"
  local keep="$3"
  [ -d "$dir" ] || return 0

  # shellcheck disable=SC2012
  local files
  files="$(ls -1t "$dir"/$glob 2>/dev/null || true)"
  [ -n "$files" ] || return 0

  local count=0
  while IFS= read -r f; do
    count=$((count + 1))
    if [ "$count" -le "$keep" ]; then
      continue
    fi
    # safety
    [ -f "$f" ] && rm -f "$f" || true
  done <<< "$files"
}

prune_runtime() {
  say "Pruning old snapshots (PRUNE_DAYS=$PRUNE_DAYS)..."
  prune_by_age "$LOG_DIR" "$PRUNE_DAYS"
  prune_by_age "$DIRECTUS_DIR" "$PRUNE_DAYS"

  # Keep caps for timestamped snapshots (even if they are fresh but too many)
  say "Capping snapshot counts..."
  keep_last_n "$LOG_DIR" "docker-compose.ps.*.txt" "$KEEP_LOG_SETS"
  keep_last_n "$LOG_DIR" "docker-compose.config.*.txt" "$KEEP_LOG_SETS"
  keep_last_n "$LOG_DIR" "http-health.*.txt" "$KEEP_LOG_SETS"

  keep_last_n "$DIRECTUS_DIR" "schema.*.yaml" "$KEEP_DIRECTUS_YAML"
  keep_last_n "$DIRECTUS_DIR" "*.http.txt" "$KEEP_DIRECTUS_HTTP"
  keep_last_n "$DIRECTUS_DIR" "config.*.txt" "$KEEP_LOG_SETS"
}

say "Snapshot target logs: $LOG_DIR"
say "Snapshot target directus: $DIRECTUS_DIR"
say "Options: LINES=$LINES UPDATE_WORDPRESS=$UPDATE_WORDPRESS WITH_DIRECTUS=$WITH_DIRECTUS"

# 0) prune before writing new files (so folder doesn't grow forever)
prune_runtime

# 1) (Optional) update wordpress container image
if [ "$UPDATE_WORDPRESS" = "1" ]; then
  say "Updating WordPress container (docker compose pull + up -d)..."
  if [ -f "$ROOT/docker/docker-compose.yml" ]; then
    ( cd "$ROOT/docker" && docker compose pull wordpress && docker compose up -d --force-recreate --no-deps wordpress ) || true
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

# 5) Directus schema snapshot (CLI inside container) + API snapshots
if [ "$WITH_DIRECTUS" = "1" ]; then
  say "Collecting Directus schema snapshot (CLI)..."
  DIRECTUS_TS="$(ts)"
  DIRECTUS_TMP="/tmp/directus-schema-${DIRECTUS_TS}.yaml"
  DIRECTUS_OUT="$DIRECTUS_DIR/schema.${DIRECTUS_TS}.yaml"

  # Snapshot inside Directus container (uses container env)
  docker exec vse_directus sh -lc "npx --yes directus schema snapshot '$DIRECTUS_TMP'" || true
  docker cp "vse_directus:$DIRECTUS_TMP" "$DIRECTUS_OUT" 2>/dev/null || true
  docker exec vse_directus sh -lc "rm -f '$DIRECTUS_TMP'" || true
  sanitize_file "$DIRECTUS_OUT"

  say "Collecting Directus snapshots via API..."
  DIRECTUS_URL="${DIRECTUS_URL:-$(directus_url_from_wp)}"
  DIRECTUS_TOKEN="${DIRECTUS_TOKEN:-$(directus_token_from_wp)}"

  if [ -z "$DIRECTUS_URL" ] || [ -z "$DIRECTUS_TOKEN" ]; then
    echo "Missing DIRECTUS_URL or DIRECTUS_TOKEN (from WP env). Cannot export Directus API snapshots." > "$DIRECTUS_DIR/README.missing-env.txt"
  else
    SNAP_TS="$(ts)"

    # token scope check
    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/users/me" "$DIRECTUS_DIR/users-me.${SNAP_TS}.http.txt"

    # schema & metadata endpoints
    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/schema/snapshot" "$DIRECTUS_DIR/schema-snapshot.${SNAP_TS}.http.txt"
    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/collections" "$DIRECTUS_DIR/collections.${SNAP_TS}.http.txt"
    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/fields" "$DIRECTUS_DIR/fields.${SNAP_TS}.http.txt"
    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/relations" "$DIRECTUS_DIR/relations.${SNAP_TS}.http.txt"

    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/roles" "$DIRECTUS_DIR/roles.${SNAP_TS}.http.txt"
    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/permissions?limit=-1" "$DIRECTUS_DIR/permissions.${SNAP_TS}.http.txt"
    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/policies?limit=-1" "$DIRECTUS_DIR/policies.${SNAP_TS}.http.txt"

    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/flows?limit=-1" "$DIRECTUS_DIR/flows.${SNAP_TS}.http.txt"
    curl_directus_http "$DIRECTUS_URL" "$DIRECTUS_TOKEN" "/operations?limit=-1" "$DIRECTUS_DIR/operations.${SNAP_TS}.http.txt"
  fi

  # Optional: small "config" report (best-effort)
  say "Collecting Directus config (best-effort)..."
  CFG_OUT="$DIRECTUS_DIR/config.${DIRECTUS_TS}.txt"
  write_cmd_output "$CFG_OUT" bash -lc "
    TOK=\$(docker exec vse_wordpress sh -lc 'printf \"%s\" \"\$DIRECTUS_API_TOKEN\"' || true)
    BASE=\$(docker exec vse_wordpress sh -lc 'printf \"%s\" \"\${DIRECTUS_PUBLIC_URL:-\${DIRECTUS_BASE_URL:-}}\"' || true)
    if [ -z \"\$TOK\" ] || [ -z \"\$BASE\" ]; then
      echo \"No DIRECTUS_API_TOKEN or DIRECTUS_PUBLIC_URL/DIRECTUS_BASE_URL in wordpress container env\"
      exit 0
    fi
    echo \"BASE=\$BASE\"
    echo \"WHOAMI:\"
    curl -sS -i -H \"Authorization: Bearer \$TOK\" \"\$BASE/users/me\" | head -n 120
  "
fi

# 6) WordPress debug.log in volume (if enabled)
say "Collecting WordPress debug.log..."
WP_DEBUG_SRC="$ROOT/docker/volumes/wordpress/wp-content/debug.log"
WP_DEBUG_DST="$LOG_DIR/wp-debug.tail.txt"

if [ -f "$WP_DEBUG_SRC" ]; then
  tail -n "$LINES" "$WP_DEBUG_SRC" > "$WP_DEBUG_DST"
  sanitize_file "$WP_DEBUG_DST"
else
  echo "No debug.log found at $WP_DEBUG_SRC (enable WP_DEBUG_LOG in wp-config.php if needed)" > "$WP_DEBUG_DST"
fi

# 7) Health checks (HTTP)
say "Collecting HTTP health checks..."
write_cmd_output "$LOG_DIR/http-health.$(ts).txt" bash -lc "
  set -e
  curl -sS -i https://directus.xn--b1awacccnl0jqa.xn--p1ai/server/health | head -n 80
  echo
  curl -sS -i https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/ | head -n 80
"

say "Done. Logs:"
ls -la "$LOG_DIR" || true
say "Done. Directus:"
ls -la "$DIRECTUS_DIR" || true
