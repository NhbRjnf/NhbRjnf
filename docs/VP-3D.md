# ПРОЕКТ «ВСЁПОНЯТНО» — 3D слой и converter pipeline

## 1. Назначение

Этот документ фиксирует текущее состояние 3D-части проекта:
- какие коллекции участвуют;
- как работает `/3d`;
- как устроен protected file flow;
- как связаны WordPress, Directus, Redis и converter worker;
- как работает job-driven viewer flow;
- как должен выглядеть graceful fallback при проблемах WebGL.

Важно:
- этот документ описывает runtime и delivery flow;
- schema-истиной по Directus остаётся `Data_Model_Directus_snapshot_06_03_26.json`;
- runtime bridge поля и signed URLs не должны автоматически считаться schema-полями без нового snapshot.

## 2. Базовые сущности Directus

### `vp_3d_scenes`
Публичная или защищённая сцена для `/3d`.

Базовые поля:
- `id`
- `title`
- `kind`
- `description`
- `is_active`
- `requires_password`
- `password_hash`
- `password_hint`
- `expires_at`
- `model_file`
- `poster_file`
- `viewer_config`
- `tenant_id`
- `case_scan_id`

### `vp_3d_jobs`
Очередь конвертации 3D.

Базовые поля по snapshot:
- `id`
- `status`
- `progress`
- `error`
- `meta`
- `completed_at`
- `input_file`
- `output_file`
- `preview_file`

Важно:
- schema-doc фиксируем по snapshot;
- viewer bridge может использовать дополнительные runtime-сопоставления, но это отдельный слой.

### `vp_case_scans`
Кейсовые сканы / модели.

Используются там, где 3D связан с кейсом, стоматологией или иными доменными сценариями.

## 3. UI слой `/3d`

`/3d` — отдельная WordPress-страница для 3D и navigation-сценариев.

На странице используются:
- `page-3d.css`
- `page-3d.js`
- `assets/vendor/model-viewer.min.js`

Правила:
- viewer подключается только как `<script type="module">`;
- никаких CDN;
- никакого глобального вмешательства в script loader на всём сайте;
- фронт не получает Directus token;
- фронт не должен использовать raw private URL как рабочий источник модели.

## 4. Режимы `/3d`

### 4.1 Scene mode
Маршрут:
- `/3d?code=<QR_CODE>`

Источник данных:
- `GET /wp-json/vp/v1/lookup?code=...`
- `POST /wp-json/vp/v1/3d/auth` при protected scene
- `GET /wp-json/vp/v1/3d/file?code=...&token=...`
- `GET /wp-json/vp/v1/3d/poster?code=...`

Используется для:
- QR-driven navigation
- public / protected 3D scene flow

### 4.2 Job mode
Маршрут:
- `/3d?job_id=<JOB_ID>`

Источник данных:
- `GET /wp-json/vp/v1/3d/job-status?job_id=...`

Используется для:
- результата 3D upload pipeline;
- просмотра готового GLB и preview через WordPress bridge.

## 5. Protected scene flow

### Публичная сцена
- WordPress может отдать `model_url`;
- `/wp-json/vp/v1/3d/file?code=...` стримит файл server-to-server.

### Защищённая сцена
1. Пользователь отправляет пароль в `POST /wp-json/vp/v1/3d/auth`.
2. WordPress проверяет:
   - `is_active`
   - `expires_at`
   - `password_hash`
3. WordPress выдаёт short-lived token.
4. Файл забирается через `GET /wp-json/vp/v1/3d/file?code=...&token=...`.

### Постер
`GET /wp-json/vp/v1/3d/poster?code=...` отдаёт preview / placeholder.

## 6. Job-driven viewer flow

Асинхронный pipeline выглядит так:

```text
upload file
  -> POST /wp-json/vp/v1/3d/job
  -> запись в vp_3d_jobs
  -> Redis queue vp:3d:jobs
  -> Python converter worker
  -> result generation
  -> WordPress-safe delivery mapping
  -> GET /wp-json/vp/v1/3d/job-status?job_id=...
  -> /3d?job_id=...

job-status должен:

возвращать нормализованный runtime-статус;

скрывать внутренние пути хранения;

отдавать только viewer-safe ссылки;

не заставлять браузер знать внутреннюю схему хранения результатов.

7. Signed delivery bridge

Для job-driven viewer используются signed WordPress-safe ссылки вида:

/dl/<token>

Важно:

raw private URLs могут отдавать 403;

это нормально и не считается багом само по себе;

viewer должен использовать именно signed bridge URLs.

8. Redis и jobs

Ключи:

pending: vp:3d:jobs

processing: vp:3d:jobs:processing

Критично:

в очередь кладётся именно integer vp_3d_jobs.id;

в очередь нельзя класть UUID входного файла.

9. Converter runtime

Converter должен уметь:

брать job_id из Redis;

читать job;

конвертировать исходный формат в web-формат;

делать preview;

обновлять job;

не терять связь между job и viewer delivery.

10. Graceful fallback

Это обязательная часть 3D runtime.

Если у клиента:

не создаётся WebGL context;

падает THREE.WebGLRenderer;

браузер или драйвер ломает viewer,

то:

backend не считается автоматически сломанным;

/3d не должен превращаться в пустой экран;

должен остаться preview / poster;

viewer-only controls должны быть деактивированы или скрыты;

пользователь должен получить понятное сообщение.

Минимальная целевая деградация:

visible preview;

отсутствие hard crash;

usable layout;

возможность понять, что модель существует, даже если интерактивный viewer недоступен.

11. Что считается готовностью 3D флоу

Минимальный критерий:

lookup находит сцену;

/scan ведёт на /3d;

/3d?code=... работает для scene flow;

/3d?job_id=... работает для job flow;

job-status отдаёт viewer-safe ссылки;

viewer не использует raw private URL;

public scene открывается;

protected scene требует пароль;

poster работает;

при отсутствии WebGL остаётся graceful fallback.

12. Обязательные проверки
curl -I https://xn--b1awacccnl0jqa.xn--p1ai/3d/
curl "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/lookup?code=<REAL>"
curl -X POST "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/auth" -H 'content-type: application/json' -d '{"code":"<REAL>","password":"<PASS>"}'
curl "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=<REAL_JOB_ID>"

cd /opt/vseponyatno/docker
docker compose logs --tail=100 converter
docker compose exec -T redis redis-cli LRANGE vp:3d:jobs 0 10
docker compose exec -T redis redis-cli LRANGE vp:3d:jobs:processing 0 10
13. Что больше нельзя путать

vp_3d_scenes.id — integer;

vp_3d_jobs.id — integer;

model_file, poster_file, input_file, output_file, preview_file — file references, не viewer contract;

signed /dl/... link — это delivery contract;

raw protected URL — это не публичный контракт;

runtime bridge поля — не schema truth, пока нет нового snapshot.