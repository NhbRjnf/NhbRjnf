## `docs/api-contract.md`

```md
# API контракт WordPress ↔ Directus

## 1. Базовый принцип

Все запросы из браузера идут только в WordPress. WordPress через MU-plugin proxy ходит в Directus server-to-server.

Из браузера не передаются:
- Directus static token;
- внутренние URL Directus;
- прямой доступ к protected 3D файлам;
- raw protected storage URLs.

## 2. Основной lookup flow

### `GET /wp-json/vp/v1/lookup?code=<QR_CODE>`

Назначение:
- найти запись `qr_codes` по `code`;
- проверить активность сценария;
- нормализовать ответ для фронтенда;
- определить, вести пользователя на `/instruction` или `/3d`.

Ожидаемая логика:
1. найти `qr_codes.code`;
2. проверить `is_active`;
3. подгрузить связанные сущности (`product`, `instruction`, `scene`) по необходимости;
4. вернуть унифицированный JSON без Directus internals.

Важно по текущему runtime:
- lookup не должен тянуть relation-expansion для `qr_file.*` и `qr_file_png.*`;
- proxy должен работать на безопасном наборе полей;
- цель — не падать на Directus relation edge-cases.

Типы, с которыми сейчас работает routing:
- `instruction`
- `product`
- `service`
- `manual`
- `3d`
- `3d/navigation`
- `navigation`
- `nav`
- `location`

## 3. Универсальная instruction-страница

### `GET /wp-json/vp/v1/instruction?code=<QR_CODE>`

Назначение:
- вернуть данные для `/instruction`;
- собрать товар, описание, ссылки, шаги и вложенные instruction-данные в одном ответе.

Используется там, где сценарий должен быть показан карточно, без viewer.

Важно по текущему runtime:
- endpoint не должен зависеть от ACL на `instruction_sets.description`;
- если описание недоступно или исключено из safe fields, используется fallback из `product.description`.

## 4. 3D endpoints через WordPress proxy

### Общий принцип
Внешний контракт 3D должен оставаться стабильным независимо от того, где физически лежит модель:
- в `directus_files`;
- или в WordPress Media Library.

Браузер не должен знать внутреннюю схему хранения сцены.

### `GET /wp-json/vp/v1/lookup?code=<QR_CODE>` для 3D scene
Если `qr_codes.scene_id` заполнен, lookup должен вернуть нормализованные данные сцены.

Минимально ожидаемый shape:

```json
{
  "ok": true,
  "code": "VP-EXAMPLE-001",
  "type": "3d",
  "scene": {
    "id": 123,
    "title": "Название сцены",
    "kind": "navigation|dental|generic",
    "requires_password": false,
    "password_hint": null,
    "expires_at": null,
    "is_active": true,
    "poster_url": "https://.../wp-json/vp/v1/3d/poster?code=VP-EXAMPLE-001",
    "model_url": "https://.../wp-json/vp/v1/3d/file?code=VP-EXAMPLE-001"
  }
}

Важно:

lookup не должен отдавать raw Directus URL;
lookup не должен отдавать raw wp-content/uploads/... URL как новый публичный контракт;
lookup отдаёт WordPress-safe proxy URL;
внутри WordPress endpoint уже сам решает, брать ли файл из Directus fields или из WordPress Media по model_file_wp_id / poster_file_wp_id.
POST /wp-json/vp/v1/3d/auth

Body:

{
  "code": "QR_CODE",
  "password": "..."
}

Назначение:

проверить is_active;
проверить expires_at;
проверить пароль по password_hash;
выдать short-lived token для защищённой сцены.

Успешный ответ:

{
  "ok": true,
  "token": "...",
  "expires_in": 600
}
GET /wp-json/vp/v1/3d/file?code=...&token=...

Назначение:

отдать модель для viewer через WordPress-controlled delivery;
для публичной сцены — без токена;
для protected сцены — с короткоживущим токеном;
не раскрывать browser-слою внутренний storage path.

Источник модели внутри WordPress:

если у сцены заполнен model_file_wp_id, WordPress берёт файл из Media Library;
если нет, используется legacy fallback через model_file (directus_files);
внешний URL при этом не меняется.
GET /wp-json/vp/v1/3d/poster?code=...

Назначение:

отдать poster / preview для /3d;
работать и для публичной, и для protected сцены;
скрывать внутренний storage path.

Источник постера внутри WordPress:

если у сцены заполнен poster_file_wp_id, WordPress берёт файл из Media Library;
если нет, используется legacy fallback через poster_file (directus_files);
внешний URL при этом не меняется.
POST /wp-json/vp/v1/3d/job

Назначение:

принять пользовательский upload для 3D pipeline;
создать запись job;
поставить job_id в Redis очередь;
вернуть идентификатор job.

Минимальный успешный ответ:

{
  "ok": true,
  "job_id": 15
}
GET /wp-json/vp/v1/3d/job-status?job_id=...

Назначение:

вернуть нормализованный runtime-статус job для страницы /3d?job_id=...;
скрыть внутренние пути хранения и raw protected URLs;
отдать только viewer-safe ссылки.

Минимально ожидаемый ответ:

{
  "ok": true,
  "job": {
    "id": 15,
    "status": "completed",
    "progress": 100,
    "viewer_glb_url": "https://.../dl/<token>",
    "viewer_preview_url": "https://.../dl/<token>"
  }
}

Важно:

viewer_glb_url и viewer_preview_url — это WordPress-safe viewer URLs;
raw private URLs не являются частью публичного контракта;
403 на raw protected URL вне bridge — ожидаемое поведение.
5. Входные режимы страницы /3d

Страница /3d поддерживает два режима.

5.1 Code-driven scene flow

Маршрут:

/3d?code=<QR_CODE>

Источник данных:

GET /wp-json/vp/v1/lookup?code=...
при необходимости POST /wp-json/vp/v1/3d/auth
GET /wp-json/vp/v1/3d/file?code=...
GET /wp-json/vp/v1/3d/poster?code=...

Используется для:

QR-driven navigation / scene flow;
public/protected scene flow;
библиотечного режима, когда разные сцены добавляются данными, а не переписыванием page-3d.php.

Важно:

/3d?code=... остаётся главным пользовательским входом для QR-сцен;
смена физического storage слоя не должна менять этот маршрут.
5.2 Job-driven viewer flow

Маршрут:

/3d?job_id=<JOB_ID>

Источник данных:

GET /wp-json/vp/v1/3d/job-status?job_id=...

Используется для:

просмотра результата конвертации после 3D upload pipeline;
выдачи signed viewer URLs через WordPress bridge.

Важно:

/3d?job_id=... живёт рядом с code-driven flow, а не вместо него.

6. Onboarding moderation contract

Критичный путь модерации проходит через WordPress admin UI и Directus REST.

Ожидаемые операции:

чтение vp_onboarding_requests;

создание или переиспользование Directus user;

создание или переиспользование vp_user_profiles;

обновление самой заявки полями аудита:

status

reviewed_at

reviewed_by_email

reviewed_by_wp_id

reviewed_by_wp_login

decision_reason

created_user_id

created_profile_id

7. Ошибки и правила диагностики

Типовые статусы:

404 — код, job или объект не найден;

410 — код неактивен или доступ истёк;

401/403 — ошибка доступа, ACL или protected resource;

500 — внутренняя ошибка proxy, viewer bridge или Directus.

При 401/403 сначала проверяем:

права service policy;

состав fields в запросе;

не запрашивает ли proxy поле, к которому нет доступа;

не пытается ли фронт использовать raw protected URL вместо signed bridge URL;

свежесть runtime snapshots.

8. Что считается контрактом, а что нет

Контрактом считаются:

WordPress endpoint-ы;

shape ответов для /lookup, /instruction, /3d/auth, /3d/file, /3d/poster, /3d/job-status, /3d/job;

отсутствие прямого Directus token в браузере;

использование signed viewer URLs для job-driven /3d.

Не считаются жёстким контрактом:

внутренние названия полей Directus, если proxy их скрывает;

внутренняя маппинг-логика job record → viewer URLs;

способ хранения производных файлов;

внутренняя организация Redis / worker, если фронт этого не видит