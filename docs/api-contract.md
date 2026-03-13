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

Используется там, где сценарий должен быть показан карточно, без model viewer.

Важно по текущему runtime:
- endpoint не должен зависеть от ACL на `instruction_sets.description`;
- если описание недоступно или исключено из safe fields, используется fallback из `product.description`.

## 4. 3D endpoints через WordPress proxy

### `POST /wp-json/vp/v1/3d/auth`
Body:
```json
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

стримить model_file server-to-server из Directus;

для защищённой сцены требовать короткоживущий токен;

не выдавать прямой Directus URL в браузер.

GET /wp-json/vp/v1/3d/poster?code=...

Назначение:

отдать placeholder / poster для /3d;

использоваться как до авторизации, так и для публичной сцены.

POST /wp-json/vp/v1/3d/job

Назначение:

принять пользовательский upload для 3D pipeline;

создать запись job;

поставить job_id в Redis очередь;

вернуть идентификатор job.

Текущее состояние:

endpoint существует и работает;

его безопасность должна быть усилена отдельной задачей;

этот endpoint не должен раскрывать внутренние Directus или private file URLs.

Минимальный успешный ответ:

{
  "ok": true,
  "job_id": 14
}
GET /wp-json/vp/v1/3d/job-status?job_id=...

Назначение:

вернуть нормализованный runtime-статус job для страницы /3d?job_id=...;

скрыть внутренние пути хранения и raw protected URLs;

отдать только viewer-safe ссылки.

Минимально ожидаемые поля ответа:

{
  "ok": true,
  "job_id": 14,
  "status": "completed",
  "progress": 100,
  "viewer_glb_url": "https://.../dl/<token>",
  "viewer_preview_url": "https://.../dl/<token>"
}

Допустимы также:

error

message

дополнительные safe runtime-поля для UI

Важно:

viewer_glb_url и viewer_preview_url — это WordPress-safe viewer URLs;

raw private URLs не являются частью публичного контракта;

403 на raw protected URL вне bridge — ожидаемое поведение.

5. Входные режимы страницы /3d

Страница /3d поддерживает два режима:

5.1 Code-driven scene flow

Маршрут:

/3d?code=<QR_CODE>

Источник данных:

lookup

при необходимости /3d/auth, /3d/file, /3d/poster

Используется для:

QR-driven navigation / scene flow

public/protected scene flow

5.2 Job-driven viewer flow

Маршрут:

/3d?job_id=<JOB_ID>

Источник данных:

GET /wp-json/vp/v1/3d/job-status?job_id=...

Используется для:

просмотра результата конвертации после 3D upload pipeline;

выдачи signed viewer URLs через WordPress bridge.

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

внутренняя организация Redis / worker, если фронт этого не видит.