# ПРОЕКТ «ВСЁПОНЯТНО» — 3D слой и converter pipeline

## 1. Назначение

Этот документ фиксирует текущее состояние 3D-части проекта:
- какие коллекции участвуют;
- как работает `/3d`;
- как устроен protected file flow;
- как связаны WordPress, Directus, Redis и converter worker;
- как работает job-driven viewer flow;
- как выглядит текущий рабочий runtime viewer;
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
- viewer изолирован на `/3d`;
- frontend не получает Directus token;
- frontend не должен использовать raw private URL как рабочий источник модели;
- job-driven runtime использует только signed `/dl/<token>` ссылки;
- current `model-viewer` runtime считается рабочим, но стратегически временным.

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

Текущее состояние:

route публичный;

server-side prefetch в page-3d.php работает;

подтверждённый рабочий пример: job_id=15.

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

Текущее рабочее runtime-состояние:

VP_3D_GLTF_TRANSFORM_OPTIMIZE=0

VP_3D_USE_DRACO=0

VP_3D_USE_MESHOPT=0

Текущая подтверждённая поддержка входных upload-форматов для pipeline:
- `.stl` — работает end-to-end
- `.obj` — работает end-to-end
- `.glb` — не поддержан текущим converter import path
- `.gltf` — не поддержан текущим converter import path

Поэтому публичный upload allowlist на текущем runtime ограничен:
- `.stl`
- `.obj`

Почему:

gltf-transform optimize приводил к meshopt-compressed output;

этот output ломал текущий model-viewer runtime ошибкой про setMeshoptDecoder;

для быстрого стабильного запуска viewer optimize временно отключён.

10. Текущий runtime viewer

На март 2026 текущий рабочий viewer для job_id-режима:

preview-first;

кнопка явного открытия интерактивного viewer;

signed /dl/... links;

обычный GLB без optimize / meshopt;

рабочие подтверждённые примеры: job_id=15, job_id=19, job_id=20.

Это временно стабилизированный runtime, но не финальная стратегическая реализация indoor viewer.

11. Graceful fallback

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

12. Что считается готовностью 3D flow

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

новый test job после отключения optimize реально грузится во viewer;

при отсутствии WebGL остаётся graceful fallback.

- upload endpoint принимает только реально поддержанные форматы;
- `.glb` и `.gltf` не проходят как upload input, пока converter не получит отдельную ветку поддержки.

13. Обязательные проверки
curl -I https://xn--b1awacccnl0jqa.xn--p1ai/3d/
curl "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/lookup?code=<REAL>"
curl -X POST "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/auth" -H 'content-type: application/json' -d '{"code":"<REAL>","password":"<PASS>"}'
curl "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=15" | jq .

GLB_URL="$(curl -sS 'https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=15' | jq -r '.job.viewer_glb_url // empty')"
PNG_URL="$(curl -sS 'https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=15' | jq -r '.job.viewer_preview_url // empty')"

curl -I "$GLB_URL"
curl -I "$PNG_URL"

cd /opt/vseponyatno/docker
docker compose logs --tail=100 converter
docker compose exec -T redis redis-cli LRANGE vp:3d:jobs 0 10
docker compose exec -T redis redis-cli LRANGE vp:3d:jobs:processing 0 10
14. Стратегический следующий шаг

После стабилизации текущего viewer runtime:

не ломаем текущий backend contract;

отдельным этапом проектируем новый Three.js renderer для более гибкой indoor navigation, этажей, POI и крупных сцен.

15. Что больше нельзя путать

job-status должен быть публичным;

signed /dl/... link — это delivery contract;

raw protected URL — это не публичный контракт;

optimize / meshopt — это часть runtime converter strategy, а не API contract;

runtime bridge поля — не schema truth, пока нет нового snapshot.


---

## `docs/CHANGELOG.MD`

```md
# Changelog

Все заметные изменения по проекту «ВсёПонятно» фиксируются здесь.

Формат ориентирован на Keep a Changelog, но записи адаптированы под реальный инженерный workflow проекта.

## [Unreleased]

### Added
- Зафиксирован отдельный job-driven viewer flow: `/3d?job_id=...`.
- Зафиксирован WordPress bridge endpoint `GET /wp-json/vp/v1/3d/job-status?job_id=...`.
- Зафиксирован signed delivery flow для viewer через `/dl/<token>`.
- Добавлен отдельный runtime-документированный принцип graceful fallback для `/3d` при отсутствии рабочего WebGL.
- Обновлена документация по безопасному 3D delivery без raw private URLs.
- Добавлены runtime-проверки для `job-status`, signed delivery и current viewer runtime config.
- Зафиксирован подтверждённый рабочий пример job-driven viewer: `job_id=15`.

### Changed
- `api-contract.md` обновлён под реальный runtime:
  - `job-status` теперь явно описан как публичный viewer bridge;
  - подтверждён shape ответа с `job.viewer_glb_url` и `job.viewer_preview_url`;
  - зафиксировано, что viewer получает только WordPress-safe ссылки.
- `architecture.md` обновлён:
  - подтверждён рабочий job-driven viewer flow;
  - зафиксировано, что signed `/dl/...` — viewer delivery layer.
- `runtime-state.md` обновлён:
  - добавлены проверки `job-status`, signed delivery и converter runtime config;
  - добавлен контроль `VP_3D_GLTF_TRANSFORM_OPTIMIZE=0`.
- `scan-routing.md` обновлён:
  - job-driven viewer entry отделён от scan-routing;
  - подтверждён публичный `job-status`.
- `troubleshooting.md` обновлён:
  - добавлен кейс `job-status` 401/403;
  - добавлен кейс meshopt-compressed GLB;
  - добавлены пояснения по Draco warning и EGL fallback.
- `VP-3D.md` обновлён:
  - зафиксирован текущий рабочий runtime viewer;
  - добавлен обход через отключение `gltf-transform optimize`;
  - добавлен стратегический следующий шаг с будущим Three.js renderer.
- `decision-log.md` дополнен решениями по public `job-status` и временному отключению optimize.

### Fixed
- Исправлен viewer bridge для `/3d?job_id=...` через публичный `job-status`.
- Исправлена выдача viewer-safe signed URLs через `/dl/<token>`.
- Исправлен текущий runtime `model-viewer` за счёт отключения `gltf-transform optimize`.
- Подтверждено, что новый job после отключения optimize успешно загружается во viewer.

### Notes
- `data-model.md` и `directus-schema.md` не переписывались, потому что схема Directus не менялась.
- `wp-engineering-audit-2026-03-11.md` остаётся историческим аудитом, а не live runtime source of truth.
- Текущий `model-viewer` runtime считается рабочим, но стратегически временным до будущей миграции на более гибкий renderer.