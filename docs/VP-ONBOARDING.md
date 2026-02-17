# VP Onboarding moderation workflow

## Prerequisites

Inside the WordPress container, ensure env vars are set:

- `DIRECTUS_PUBLIC_URL` (fallback: `DIRECTUS_BASE_URL`)
- `VP_SERVICE_USER_TOKEN`
- optional: `VP_ONBOARDING_DRY_RUN=1` (disables POST/PATCH/DELETE writes from WP)

## Create a test onboarding request (Directus REST)

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
    "status": "pending"
  }'
```

## Approve in WordPress

1. Open WP admin: `wp-admin/admin.php?page=vp-onboarding-admin`
2. Find request and click **Approve**.
3. Wait for success notice (`User created: <id>` or `User reused: <id>` wording).

## Verify user/profile/request linkage in Directus

```bash
# Request should be approved with reviewer audit and created IDs
curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests?filter[email][_eq]=test.onboarding@example.com&limit=1&fields=id,status,reviewed_at,reviewed_by_email,decision_reason,created_user_id,created_profile_id" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"

# User must exist exactly once by email
curl -sS "$DIRECTUS_PUBLIC_URL/users?filter[email][_eq]=test.onboarding@example.com&limit=5&fields=id,email,status,role" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"

# Profile for that user (replace <DIRECTUS_USER_ID>)
curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_user_profiles?filter[user_id][_eq]=<DIRECTUS_USER_ID>&limit=5&fields=id,user_id,user_type,phone,status" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"
```

## Reject flow quick check

From WP admin click **Reject** and provide reason.

Then verify:

```bash
curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests?filter[email][_eq]=test.onboarding@example.com&limit=1&fields=id,status,reviewed_at,reviewed_by_email,decision_reason" \
  -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"
```

Expected: `status=rejected` and non-empty `decision_reason`.
