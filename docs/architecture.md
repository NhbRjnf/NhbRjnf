# Архитектура проекта «ВсёПонятно»

## 1. Назначение проекта

«ВсёПонятно» — это платформа доступа к инструкциям, карточкам, навигации и 3D-сценариям по QR-коду или короткому коду.

Базовая пользовательская логика:
1. пользователь открывает `/scan`;
2. сканирует QR или вводит код вручную;
3. WordPress делает server-to-server lookup в Directus;
4. по типу сценария пользователь попадает на `/instruction` или `/3d`.

Дополнительная логика 3D pipeline:
1. пользователь загружает 3D-файл через WordPress;
2. создаётся `vp_3d_jobs`;
3. job ставится в Redis;
4. converter обрабатывает задачу;
5. `/3d?job_id=...` показывает статус и результат через WordPress bridge.

Главный принцип проекта: не плодить страницы и сценарии вручную, а вести поведение через данные и proxy-слой WordPress.

## 2. Среды и источник истины

### main
- WordPress: публичный frontend
- Directus: основной backend
- Redis + converter: async runtime
- домены:
  - сайт: `https://xn--b1awacccnl0jqa.xn--p1ai`
  - Directus: `https://directus.xn--b1awacccnl0jqa.xn--p1ai`

### stage
- отдельный Directus runtime для безопасной проверки схемы, прав и интеграций;
- не является публичным frontend-источником для браузера;
- используется для инженерной валидации.

### Источник истины
- по схеме Directus источником истины считается `Data_Model_Directus_snapshot_ADD_LOCATION__17_03_26.json`;
- по текущему runtime-поведению источником истины являются актуальные WordPress proxy endpoints и рабочие flows;
- если schema-docs и runtime-docs расходятся, schema-docs не переписываются без нового snapshot.### Источник истины
- по схеме Directus источником истины считается `Data_Model_Directus_snapshot_ADD_LOCATION__17_03_26.json`;
- по текущему runtime-поведению источником истины являются актуальные WordPress proxy endpoints и рабочие flows;
- если schema-docs и runtime-docs расходятся, schema-docs не переписываются без нового snapshot.

## 3. Слои системы

### Frontend layer
- WordPress
- PWA-страница `/scan`
- универсальная страница `/instruction`
- отдельная страница `/3d`
- login / app / dentist flows
- MU-plugins для server-to-server proxy

### Data / admin layer
- Directus
- PostgreSQL
- Directus Files для контента, инструкций и части 3D-метаданных

### Async / conversion layer
- Redis
- Python converter worker
- 3D conversion pipeline
- runtime snapshots / logs / диагностика

### Protected delivery layer
- WordPress signed links
- WordPress `/dl/<token>` bridge
- защищённая выдача viewer-ready файлов без раскрытия raw private URLs

## 4. Почему WordPress стоит перед Directus

Все запросы из браузера должны идти в WordPress. Это нужно для того, чтобы:
- не светить Directus token в браузере;
- централизованно контролировать ACL и форму ответа API;
- изолировать protected file flow;
- скрывать внутренние URL хранилища;
- выдавать только safe URLs для viewer;
- переживать внутренние изменения схемы без поломки фронта.

## 5. Core product flows

### 5.1 Scan → instruction
Используется для product / service / manual и части instruction-oriented сценариев.

Текущий runtime-принцип:
- `lookup` работает на safe fields;
- relation-expansion `qr_file.*` и `qr_file_png.*` не используется;
- `/instruction` не должен зависеть от ACL на `instruction_sets.description`;
- при необходимости описание берётся из `product.description`.

### 5.2 Scan → 3D scene
Используется для:
- `3d`
- `3d/navigation`
- `navigation`
- `nav`
- `location`

Маршрут:
- `/3d?code=...`

Поддерживает:
- public scene
- protected scene
- poster
- short-lived token flow

### 5.3 Upload → converter → viewer
Используется для нового 3D upload pipeline.

Маршрут:
- загрузка через `POST /wp-json/vp/v1/3d/job`
- просмотр через `/3d?job_id=...`
- статус и viewer URLs через `GET /wp-json/vp/v1/3d/job-status?job_id=...`

Ключевая идея:
- viewer не читает raw private URL;
- viewer получает только signed `/dl/...` ссылки через WordPress bridge.

## 6. Базовый поток данных

### Общий путь
```text
browser
  -> WordPress
  -> WordPress REST / MU-plugin proxy
  -> Directus
  -> PostgreSQL / Directus Files
3D code-driven scene flow
browser
  -> /scan
  -> WordPress lookup
  -> /3d?code=...
  -> /3d/auth (optional)
  -> /3d/file or /3d/poster
3D job-driven flow
browser
  -> POST /wp-json/vp/v1/3d/job
  -> Directus job
  -> Redis queue
  -> converter worker
  -> runtime result
  -> /3d?job_id=...
  -> GET /wp-json/vp/v1/3d/job-status
  -> signed /dl/<token> viewer links
  
  
## 7. Core data blocks

### Catalog / content
- `products`
- `instruction_sets`
- `instruction_steps`
- `instruction_assets`
- `qr_codes`
- `vp_cards`

### 3D
- `vp_3d_scenes`
- `vp_3d_jobs`
- `vp_case_scans`

### Indoor navigation
- `vp_locations`
- `vp_location_levels`
- `vp_location_zones`
- `vp_location_nodes`
- `vp_location_edges`
- `vp_location_pois`
- `vp_location_anchors`

### SaaS / tenant
- `vp_tenants`
- `vp_memberships`
- `vp_invites`
- `vp_user_profiles`
- `vp_share_links`

### Dental / domain
- `vp_clinics`
- `vp_patients`
- `vp_cases`
- `vp_case_scans`

### Onboarding / moderation
- `vp_onboarding_requests`
- `vp_allowlist_domains`

8. Архитектурные правила

browser ходит только в WordPress;

WordPress ходит в Directus server-to-server;

/scan, /instruction, /3d остаются основными пользовательскими экранами;

старый /3d?code=... flow нельзя ломать;

новый /3d?job_id=... flow живёт рядом, не вместо него;

raw private URLs не являются публичным контрактом;

signed /dl/... links — допустимый viewer delivery слой;

graceful fallback для /3d обязателен: отсутствие WebGL на клиенте не должно ломать страницу;

schema-docs меняются только вместе с новым snapshot.

## 9. Текущий статус

На март 2026 проект включает:

- Docker stack;
- Nginx reverse proxy;
- SSL;
- WordPress frontend;
- Directus backend;
- `/scan`, `/instruction`, `/3d`;
- onboarding moderation;
- multi-tenant ядро;
- 3D collections и routing;
- Redis + converter pipeline;
- job-driven viewer bridge через signed URLs;
- indoor-navigation schema layer:
  - locations
  - levels
  - zones
  - nodes
  - edges
  - POI
  - anchors;
- рабочий текущий runtime viewer для job_id-режима.

Важно:
- наличие indoor-navigation слоя в схеме не означает, что весь frontend-контракт уже должен быть жёстко описан в `api-contract.md`;
- но архитектурно этот слой уже часть проекта и должен быть отражён в docs.



10. Что считать поломкой

Поломкой считаются:

прямой токен или private URL в браузере;

падение /lookup на relation expansion;

падение /instruction из-за поля instruction_sets.description;

hard crash /3d на клиенте без graceful fallback;

зависимость viewer от raw protected URL вместо WordPress bridge;

закрытый job-status route, если /3d?job_id=... должен быть публичным;

meshopt-compressed GLB в текущем model-viewer runtime, если viewer из-за этого не загружается.