# VP Onboarding moderation workflow

## 1. Назначение

Этот документ фиксирует критичный путь модерации onboarding в проекте «ВсёПонятно».

Текущий подход:
- пользователь создаёт заявку;
- заявка попадает в `vp_onboarding_requests`;
- WordPress admin UI модерирует заявку;
- WordPress server-to-server работает с Directus;
- при approve создаются или переиспользуются Directus user и `vp_user_profiles`.

## 2. Переменные окружения

Внутри WordPress container / environment должны быть доступны:

- `DIRECTUS_PUBLIC_URL`
- `DIRECTUS_BASE_URL` как fallback
- `VP_SERVICE_USER_TOKEN`
- `VP_ONBOARDING_DRY_RUN=1` — опционально, только для безопасной проверки без записи

Безопасность:
- токены не логировать;
- токены не показывать на скриншотах;
- токены не коммитить в git.

## 3. Коллекция `vp_onboarding_requests`

По актуальному snapshot заявка содержит, среди прочего:

- `id`
- `email`
- `phone`
- `first_name`
- `last_name`
- `user_type`
- `status`
- `auto_approve_method`
- `invite_code`
- `requested_tenant_name`
- `requested_tenant_slug`
- `evidence_note`
- `source_ip`
- `user_agent`
- `created_user_id`
- `created_profile_id`
- `reviewed_by`
- `decision_reason`
- `reviewed_at`
- `reviewed_by_email`
- `reviewed_by_wp_id`
- `reviewed_by_wp_login`
- `created_at`
- `updated_at`

## 4. Создание тестовой заявки

```bash
curl -sS -X POST "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests"   -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"   -H "Content-Type: application/json"   -d '{
    "email": "test.onboarding@example.com",
    "phone": "+10000000000",
    "first_name": "Test",
    "last_name": "Onboarding",
    "user_type": "dentist",
    "status": "pending",
    "auto_approve_method": "manual"
  }'
```

## 5. Approve через WordPress admin UI

1. Открыть `wp-admin/admin.php?page=vp-onboarding-admin`.
2. Найти заявку со статусом `pending`.
3. Нажать **Approve**.
4. Дождаться успешного результата:
   - user created / reused;
   - profile created / reused;
   - заявка обновлена полями аудита.

WordPress при этом делает через Directus REST:

- `GET /users?filter[email][_eq]=...&limit=1`
- `POST /users` при отсутствии пользователя
- `GET /items/vp_user_profiles?filter[user_id][_eq]=...&limit=1`
- `POST /items/vp_user_profiles` при отсутствии профиля
- `PATCH /items/vp_onboarding_requests/{id}`

## 6. Что должно записаться при approve

В самой заявке должны обновиться:
- `status = approved`
- `reviewed_at`
- `reviewed_by_email`
- `reviewed_by_wp_id`
- `reviewed_by_wp_login`
- `created_user_id`
- `created_profile_id`
- `decision_reason` может быть `null` или содержать комментарий

## 7. Проверка approve через curl

```bash
curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests?filter[email][_eq]=test.onboarding@example.com&limit=1&fields=id,status,reviewed_at,reviewed_by_email,reviewed_by_wp_id,reviewed_by_wp_login,decision_reason,created_user_id,created_profile_id"   -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"

curl -sS "$DIRECTUS_PUBLIC_URL/users?filter[email][_eq]=test.onboarding@example.com&limit=5&fields=id,email,status,role"   -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"

curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_user_profiles?filter[user_id][_eq]=<DIRECTUS_USER_ID>&limit=5&fields=id,user_id,user_type,status,phone,created_at"   -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"
```

## 8. Reject через WordPress admin UI

1. Нажать **Reject**.
2. Ввести непустую причину.
3. WordPress должен сделать `PATCH` в `vp_onboarding_requests/{id}` и записать:
   - `status = rejected`
   - `reviewed_at`
   - `reviewed_by_email`
   - `reviewed_by_wp_id`
   - `reviewed_by_wp_login`
   - `decision_reason`

При reject:
- новый user не создаётся;
- новый profile не создаётся.

## 9. Проверка reject

```bash
curl -sS "$DIRECTUS_PUBLIC_URL/items/vp_onboarding_requests?filter[email][_eq]=test.onboarding@example.com&limit=1&fields=id,status,reviewed_at,reviewed_by_email,decision_reason,created_user_id,created_profile_id"   -H "Authorization: Bearer $VP_SERVICE_USER_TOKEN"
```

Ожидание:
- `status = rejected`
- `decision_reason` заполнен
- `created_user_id` и `created_profile_id` не меняются из-за reject

## 10. Что ещё обязательно учитывать

- `VP_ONBOARDING_DRY_RUN=1` намеренно отключает запись;
- profile должен создаваться по актуальной схеме `vp_user_profiles`, а не по старым полям;
- при multi-tenant развитии следующим шагом обычно становится создание `vp_tenants` и `vp_memberships` после approve;
- если поля reviewer не появляются в API, значит ACL или `fields=` в запросе не соответствует схеме.
