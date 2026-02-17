# VP Onboarding moderation workflow (REST-only critical path)

## Prerequisites

Inside the WordPress container/environment, ensure env vars are set:

- `DIRECTUS_PUBLIC_URL` (fallback: `DIRECTUS_BASE_URL`)
- `VP_SERVICE_USER_TOKEN`
- optional: `VP_ONBOARDING_DRY_RUN=1` (WP will not perform Directus writes, only read/check and report what would happen)

> Security: never print tokens to logs, Git history, or screenshots.

## 1) Create a test onboarding request

```bash
curl -sS -X POST "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test.onboarding@example.com",
    "phone": "+10000000000",
    "first_name": "Test",
    "last_name": "Onboarding",
    "user_type": "dentist",
    "status": "pending",
    "auto_approve_method": "manual"
  }'
```

## 2) Approve via WP admin UI

1. Open `wp-admin/admin.php?page=vp-onboarding-admin`.
2. Click **Approve** for the request.
3. Wait for success notice (`User created/reused`, `Profile created/reused`).

What WP does through Directus REST:

- `GET /users?filter[email][_eq]=...&limit=1`
- if missing user: `POST /users`
- `GET /items/vp_user_profiles?filter[user_id][_eq]=...&limit=1`
- if missing profile: `POST /items/vp_user_profiles`
- `PATCH /items/vp_onboarding_requests/{id}` with audit + linkage fields

## 3) Verify approve result with curl

```bash
# Onboarding request should now be approved and audited
curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests?filter[email][_eq]=test.onboarding@example.com&limit=1&fields=id,status,reviewed_at,reviewed_by_email,reviewed_by_wp_id,reviewed_by_wp_login,decision_reason,created_user_id,created_profile_id" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"

# Directus user should exist once by email
curl -sS "$DIRECTUS_PUBLIC_URL/users?filter[email][_eq]=test.onboarding@example.com&limit=5&fields=id,email,status,role" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"

# Replace <DIRECTUS_USER_ID> with created_user_id from previous response
curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_user_profiles?filter[user_id][_eq]=<DIRECTUS_USER_ID>&limit=5&fields=id,user_id,user_type,created_at" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"
```

## 4) Reject via WP admin UI

1. Click **Reject**.
2. Enter non-empty reason in prompt.
3. WP sends `PATCH /items/vp_onboarding_requests/{id}` with:
   - `status=rejected`
   - `reviewed_at` (ISO8601)
   - reviewer fields (`reviewed_by_email`, `reviewed_by_wp_id`, `reviewed_by_wp_login`)
   - `decision_reason`

No user/profile creation is performed on reject.

## 5) Verify reject result with curl

```bash
curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests?filter[email][_eq]=test.onboarding@example.com&limit=1&fields=id,status,reviewed_at,reviewed_by_email,decision_reason,created_user_id,created_profile_id" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"
```

Expected for reject:

- `status = rejected`
- non-empty `decision_reason`
- `created_user_id` / `created_profile_id` unchanged (no new linkage created by reject)

## 6) Optional: direct PATCH examples (manual moderation outside WP)

These examples are useful for diagnostics and emergency operations.

```bash
# Approve a request directly in Directus (replace values)
curl -sS -X PATCH "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests/<REQUEST_ID>" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "approved",
    "reviewed_at": "2026-02-17T12:00:00Z",
    "reviewed_by_email": "moderator@example.com",
    "reviewed_by_wp_id": 1,
    "reviewed_by_wp_login": "admin",
    "decision_reason": null,
    "created_user_id": "<DIRECTUS_USER_UUID>",
    "created_profile_id": 123
  }'

# Reject a request directly in Directus (replace values)
curl -sS -X PATCH "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests/<REQUEST_ID>" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "rejected",
    "reviewed_at": "2026-02-17T12:05:00Z",
    "reviewed_by_email": "moderator@example.com",
    "reviewed_by_wp_id": 1,
    "reviewed_by_wp_login": "admin",
    "decision_reason": "Not enough verification data"
  }'
```

## Troubleshooting checklist

- Confirm `admin.php?page=vp-onboarding-admin` loads `vp-onboarding-admin.js` and `.css` with `?ver=<filemtime>` in DevTools Network.
- Confirm `GET /items/vp_onboarding_requests` includes `reviewed_by_email` and `reviewed_at` fields.
- If approve/reject fails, inspect WordPress `error_log` for concise Directus diagnostics: endpoint, HTTP code, Directus message.
- If `VP_ONBOARDING_DRY_RUN=1`, writes are intentionally skipped.