#!/usr/bin/env bash
set -euo pipefail

BASE="https://xn--b1awacccnl0jqa.xn--p1ai"
COOKIE_JAR="/tmp/vp.cookies.txt"
OUT_DIR="/tmp/vp-smoke-$(date +%F_%H%M%S)"
mkdir -p "$OUT_DIR"

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dependency: $1"; exit 1; }; }
need curl
need sed
need grep

echo "== VP App smoke test =="
read -r -p "Email: " VP_EMAIL
read -r -s -p "Password (hidden): " VP_PASS
echo ""
echo ""

json_escape() {
  # minimal JSON string escape for bash
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="${s//$'\n'/\\n}"
  printf "%s" "$s"
}

save() {
  local name="$1"
  cat > "$OUT_DIR/$name"
  echo "Saved: $OUT_DIR/$name"
}

req() {
  # usage: req METHOD PATH [DATA_JSON]
  local method="$1"
  local path="$2"
  local data="${3:-}"
  local url="${BASE}${path}"

  if [[ -n "$data" ]]; then
    curl -sS -i \
      -b "$COOKIE_JAR" \
      -c "$COOKIE_JAR" \
      -H 'Accept: application/json' \
      -H 'Content-Type: application/json' \
      -X "$method" "$url" \
      --data "$data"
  else
    curl -sS -i \
      -b "$COOKIE_JAR" \
      -c "$COOKIE_JAR" \
      -H 'Accept: application/json' \
      -X "$method" "$url"
  fi
}

echo "== 1) Login =="
LOGIN_BODY=$(printf '{"email":"%s","password":"%s"}' "$(json_escape "$VP_EMAIL")" "$(json_escape "$VP_PASS")")

req POST "/wp-json/vp/v1/login" "$LOGIN_BODY" | save "01_login.http"

if ! grep -qiE '^set-cookie: vp_dx_at=' "$OUT_DIR/01_login.http"; then
  echo "ERROR: vp_dx_at cookie not set. Login likely failed."
  echo "Hint: check $OUT_DIR/01_login.http"
  exit 1
fi
echo "OK: cookies set"

echo ""
echo "== 2) /app/me =="
req GET "/wp-json/vp/v1/app/me" | save "02_me.http"
ME_BODY=$(sed -n '/^\r*$/,$p' "$OUT_DIR/02_me.http" | sed '1d')
echo "$ME_BODY" | head -c 500; echo ""

echo ""
echo "== 3) /app/tenants =="
req GET "/wp-json/vp/v1/app/tenants" | save "03_tenants.http"
TENANTS_BODY=$(sed -n '/^\r*$/,$p' "$OUT_DIR/03_tenants.http" | sed '1d')
echo "$TENANTS_BODY" | head -c 800; echo ""

echo ""
echo "== 4) Optional tenant switch =="
read -r -p "Tenant ID to set (enter to skip): " TENANT_ID
if [[ -n "$TENANT_ID" ]]; then
  TENANT_BODY=$(printf '{"tenant_id":"%s"}' "$(json_escape "$TENANT_ID")")
  req POST "/wp-json/vp/v1/app/tenant" "$TENANT_BODY" | save "04_tenant_set.http"
  echo "Tenant set attempted. See 04_tenant_set.http"
else
  echo "Skipped tenant switch."
fi

echo ""
echo "== 5) Optional /diag/runtime (if exists) =="
DIAG=$(req GET "/wp-json/vp/v1/app/diag/runtime" || true)
echo "$DIAG" | save "05_diag_runtime.http"
if echo "$DIAG" | grep -q "404"; then
  echo "Diag endpoint not found (404) — ok if patch not applied."
else
  echo "Diag response saved."
fi

echo ""
echo "== 6) Optional profile update (if exists) =="
read -r -p "Update profile now? (y/N): " DO_PROFILE
DO_PROFILE="${DO_PROFILE:-N}"
if [[ "$DO_PROFILE" =~ ^[Yy]$ ]]; then
  read -r -p "first_name: " FN
  read -r -p "last_name: " LN
  read -r -p "phone: " PH
  PROF_BODY=$(printf '{"first_name":"%s","last_name":"%s","phone":"%s"}' "$(json_escape "$FN")" "$(json_escape "$LN")" "$(json_escape "$PH")")
  req PATCH "/wp-json/vp/v1/app/profile" "$PROF_BODY" | save "06_profile_patch.http"
  echo "Profile patch attempted. See 06_profile_patch.http"
else
  echo "Skipped profile patch."
fi

echo ""
echo "== 7) Optional avatar upload (if exists) =="
read -r -p "Path to avatar image (png/jpg/webp) (enter to skip): " AVATAR_PATH
if [[ -n "$AVATAR_PATH" ]]; then
  if [[ ! -f "$AVATAR_PATH" ]]; then
    echo "ERROR: file not found: $AVATAR_PATH"
  else
    curl -sS -i \
      -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
      -H 'Accept: application/json' \
      -F "file=@${AVATAR_PATH}" \
      "${BASE}/wp-json/vp/v1/app/profile/avatar" | save "07_avatar_upload.http"
    echo "Avatar upload attempted. See 07_avatar_upload.http"
  fi
else
  echo "Skipped avatar upload."
fi

echo ""
echo "== 8) Dentist cases endpoints (may be forbidden if not dentist) =="
req GET "/wp-json/vp/v1/app/dentist/cases" | save "08_dentist_cases_get.http"
echo "Saved GET dentist/cases."

read -r -p "Create dentist case now? (y/N): " DO_CASE
DO_CASE="${DO_CASE:-N}"
if [[ "$DO_CASE" =~ ^[Yy]$ ]]; then
  read -r -p "Case title: " CASE_TITLE
  CASE_BODY=$(printf '{"title":"%s"}' "$(json_escape "$CASE_TITLE")")
  req POST "/wp-json/vp/v1/app/dentist/cases" "$CASE_BODY" | save "09_dentist_case_create.http"
  echo "Saved POST dentist/cases."
else
  echo "Skipped case create."
fi

echo ""
echo "DONE. Reports in: $OUT_DIR"
echo "Cookie jar: $COOKIE_JAR"
