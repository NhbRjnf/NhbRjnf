### `scan-routing.md`

```md
# Маршрутизация QR: `/scan` → `/instruction` или `/3d`

## 1. Входной поток

1. Пользователь открывает `/scan`.
2. Сканер или ручной ввод отправляет запрос в WordPress proxy:
   `GET /wp-json/vp/v1/lookup?code=...`
3. WordPress получает нормализованный ответ по коду.
4. По `type` или `kind` выбирается экран назначения.

Важно:
- `/scan` маршрутизирует только code-driven сценарии;
- job-driven `/3d?job_id=...` — это отдельный вход, не replacement для scan-flow.

## 2. Правила маршрутизации

### Ведём на `/instruction`
Следующие типы считаем instruction-ориентированными:
- `instruction`
- `product`
- `service`
- `manual`

Маршрут:
`/instruction?code=<CODE>`

### Ведём на `/3d`
Следующие типы считаем 3D / navigation-ориентированными:
- `3d`
- `3d/navigation`
- `navigation`
- `nav`
- `location`

Маршрут:
`/3d?code=<CODE>`

### Неподдержанный или пустой тип
Остаёмся на `/scan` и показываем пользователю понятное сообщение без client-side crash.

## 3. Lookup для 3D-сцен

Если `qr_codes.scene_id` заполнен, lookup должен вернуть информацию о сцене в нормализованном виде:

```json
{
  "scene": {
    "id": 123,
    "title": "Название сцены",
    "kind": "navigation|dental|generic",
    "requires_password": true,
    "password_hint": "Подсказка",
    "expires_at": "2026-03-31T23:59:59Z",
    "is_active": true,
    "poster_url": "https://.../wp-json/vp/v1/3d/poster?code=...",
    "model_url": "https://.../wp-json/vp/v1/3d/file?code=..."
  }
}

Примечания:

model_url можно отдавать сразу только для публичной сцены;

для защищённой сцены загрузка модели происходит после POST /wp-json/vp/v1/3d/auth;

прямые ссылки Directus и токены в браузер не передаются.

4. Отдельный viewer-режим /3d?job_id=...

Это не scan-routing, а прямой вход в viewer после upload/conversion flow.

Маршрут:

/3d?job_id=<JOB_ID>

Источник данных:

GET /wp-json/vp/v1/3d/job-status?job_id=...

Поведение:

страница не делает lookup по QR-коду;

страница читает runtime-статус job;

при status=completed получает viewer-safe ссылки:

viewer_glb_url

viewer_preview_url

страница не должна использовать raw private URLs напрямую.

5. Protected flow для 3D scene
Авторизация

POST /wp-json/vp/v1/3d/auth

Проверяем:

vp_3d_scenes.is_active

vp_3d_scenes.expires_at

vp_3d_scenes.password_hash через password_verify

Получение файла

GET /wp-json/vp/v1/3d/file?code=...&token=...

Поведение:

public сцена может работать без токена;

protected сцена требует short-lived token;

WordPress стримит файл server-to-server, не раскрывая внутренний assets URL.

Постер

GET /wp-json/vp/v1/3d/poster?code=...

Используется:

как placeholder;

как preview до успешной авторизации;

как fallback, если model viewer ещё не готов.

6. Job-status flow для viewer
Статус

GET /wp-json/vp/v1/3d/job-status?job_id=...

Назначение:

показать прогресс job;

при готовности отдать viewer-safe signed URLs;

не раскрывать внутренний storage path.

Viewer URLs

Правильно:

signed /dl/<token> ссылки

Неправильно:

raw vp-private URL в браузере;

прямые protected storage URLs как публичный контракт.

7. Что такое /3d

/3d — отдельная WordPress-страница с шаблоном VP 3D Navigation.

На этой странице подключаются:

page-3d.css

page-3d.js

assets/vendor/model-viewer.min.js через <script type="module">

Почему так:

ESM нельзя безопасно грузить обычным wp_enqueue_script() как классический script;

не хотим вешать глобальный script_loader_tag и рисковать всем сайтом;

3D-движок должен быть строго изолирован на /3d.

8. Graceful fallback

Если у клиента нет рабочего WebGL-контекста:

страница не должна падать;

должен остаться poster / preview;

viewer controls могут быть скрыты;

пользователь должен получить понятное сообщение, а не пустой экран.

Это относится и к:

/3d?code=...

/3d?job_id=...

9. Минимальные проверки после изменений
curl -I https://xn--b1awacccnl0jqa.xn--p1ai/scan/
curl -I https://xn--b1awacccnl0jqa.xn--p1ai/instruction/
curl -I https://xn--b1awacccnl0jqa.xn--p1ai/3d/
curl "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/lookup?code=<REAL>"
curl "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=<REAL_JOB_ID>"

Ручной чек:

instruction-type код ведёт на /instruction;

3d / navigation-type код ведёт на /3d?code=...;

/3d?job_id=... открывается без lookup;

неподдержанный тип не валит фронт;

при проблемном WebGL остаётся preview/fallback.