# Инженерный аудит WordPress-части («ВсёПонятно»)

Дата: 2026-03-11  
Область: `twentytwentyfour` theme + `mu-plugins`  
Режим: без функциональных правок, только аудит и безопасный cleanup-plan.

## A) Краткий план

1. Зафиксировать фактические страницы/шаблоны и их ассеты в теме.
2. Зафиксировать фактические REST routes в MU-плагинах и сопоставить с фронтом.
3. Разобрать ключевые продуктовые flow: `/scan`, `/instruction`, `/3d`, `/login`, `/app`, `/dentist-login`, `/dentist`.
4. Отдельно разобрать 3D token/proxy flow и зависимости (`model-viewer`, `/lookup`, `/3d/auth`, `/3d/file`, `/3d/poster`).
5. Выделить дубли/legacy/следы неудачных patch, а также потенциальные конфликты.
6. Предложить минимальный, обратимый cleanup-план без поломки прода.

## B) Инвентаризация файлов и flows

### Theme (`wp-content/themes/twentytwentyfour`)

#### Кастомные page templates
- `page-scan.php` — UI сканера и ручного поиска кода.
- `page-instruction.php` — универсальная карточка инструкции/навигации (inline JS).
- `page-3d.php` — 3D viewer + auth form + FAB меню.
- `page-login.php` — универсальный вход/регистрация.
- `page-app.php` — shell для SPA `/app`.
- `page-dentist.php` — кабинет стоматолога.
- `page-dentist-login.php` — отдельная login-страница стоматолога (см. конфликт ниже).

#### Ассеты и подключение через `functions.php`
- Общие для `scan/instruction/3d`: `assets/vp-ui.css`, `assets/vp-menu.js`, `VP_MENU` config.
- `/scan`: `assets/vp-scan.css`, `assets/vendor/html5-qrcode.min.js`, `assets/vp-scan.js`, `VP_SCAN` config.
- `/instruction`: `instruction.css` (файл подключается, но в дереве темы на момент аудита не найден).
- `/3d`: `page-3d.css`, `page-3d.js`, `VP_3D` config.
- `/login`: `assets/vp-ui.css`, `assets/vp-dentist.css`, `assets/login.css`, `assets/vp-login.js`.
- `/dentist*`: `assets/vp-ui.css`, `assets/vp-menu.js`, `assets/vp-dentist.css`, `assets/vp-dentist.js`.
- `/app`: `assets/vp-app.css`, `assets/app/vp-app.js`, inline `VP_APP_CONFIG`.

#### Vendor assets
- `assets/vendor/html5-qrcode.min.js` используется в `/scan`.
- `assets/vendor/model-viewer.min.js` подключается в `page-3d.php` как ESM модуль.

#### Legacy / временные следы
- `page-instruction.php.orig`
- `page-instruction.php.rej`

### MU-plugins (`wp-content/mu-plugins`)

#### По маршрутам (REST)
- `vp-proxy-directus.php`:
  - `/vp/v1/lookup`
  - `/vp/v1/suggest`
  - `/vp/v1/instruction`
  - `/vp/v1/3d/auth`
  - `/vp/v1/3d/file`
  - `/vp/v1/3d/poster`
  - `/vp/v1/3d/job`
- `vp-login.php`:
  - `/vp/v1/login`
  - `/vp/v1/logout`
  - `/vp/v1/me`
  - `/vp/v1/register-request`
  - `/vp/v1/register-status`
- `vp-dentist-cabinet.php`:
  - `/vp/v1/dentist/login`
  - `/vp/v1/dentist/logout`
  - `/vp/v1/dentist/me`
  - `/vp/v1/dentist/tenants`
  - `/vp/v1/dentist/tenant`
  - `/vp/v1/dentist/cases` (GET/POST)
  - `/vp/v1/dentist/cases/{id}/upload-scan`
  - `/vp/v1/dentist/clinics`
- `vp-app.php`:
  - `/vp/v1/app/me`
  - `/vp/v1/app/tenants`
  - `/vp/v1/app/tenant`
  - `/vp/v1/app/profile`
  - `/vp/v1/app/profile/avatar` (POST + GET proxy)
  - `/vp/v1/app/diag/runtime`
  - `/vp/v1/app/dentist/cases` (GET/POST)
- `vp-files.php`:
  - `/vp/v1/upload`
  - `/vp/v1/links`
  - `/vp/v1/file/register`
- `vp-3d-job.php` — route не регистрирует, но содержит callback `vp_3d_job_create` (подхватывается из `vp-proxy-directus.php`).

#### Прочее
- `vp-upload-mimes.php` — MIME support.
- `vp-onboarding-admin.php` + `*.js/*.css` — админский onboarding.
- `vp-onboarding-admin.php.bak.*` — backup-файл в продовом дереве.

## C) Таблица зависимостей

| Page/slug | Template | CSS | JS | Config object | REST used | MU dependency | External dependency | Статус |
|---|---|---|---|---|---|---|---|---|
| `/scan` | `page-scan.php` | `vp-ui.css`, `vp-scan.css` | `vp-menu.js`, `html5-qrcode.min.js`, `vp-scan.js` | `VP_MENU`, `VP_SCAN` | `/vp/v1/lookup`, `/vp/v1/suggest` | `vp-proxy-directus.php` | html5-qrcode, Directus assets URL (опц.) | используется |
| `/instruction` | `page-instruction.php` | `vp-ui.css`, `instruction.css`* | `vp-menu.js`, inline script in template | `VP_MENU` | `/vp/v1/instruction` | `vp-proxy-directus.php` | Directus data | используется, но `instruction.css` подозрителен |
| `/3d` | `page-3d.php` | `vp-ui.css`, `page-3d.css` | `vp-menu.js`, `page-3d.js`, `model-viewer.min.js` | `VP_MENU`, `VP_3D` | `/vp/v1/lookup`, `/vp/v1/3d/auth`, `/vp/v1/3d/file`, `/vp/v1/3d/poster` | `vp-proxy-directus.php` (+ `vp-3d-job.php` для job route) | model-viewer ESM, Directus assets | используется (критично) |
| `/login` | `page-login.php` | `vp-ui.css`, `vp-dentist.css`, `login.css` | `vp-login.js` | нет явного отдельного | `/vp/v1/login`, `/vp/v1/register-request` | `vp-login.php` | Directus auth/users/onboarding | используется |
| `/app` | `page-app.php` | `vp-app.css` | `assets/app/vp-app.js` + dynamic modules | `VP_APP_CONFIG` | `/vp/v1/app/*`, `/vp/v1/logout` | `vp-app.php`, `vp-login.php` | Directus | используется |
| `/dentist-login` | `page-dentist-login.php` | `vp-ui.css`, `vp-dentist.css` | `vp-menu.js`, `vp-dentist.js` | `VP_MENU` | `/vp/v1/dentist/login` | `vp-dentist-cabinet.php` | Directus auth | конфликтный (см. редирект) |
| `/dentist` | `page-dentist.php` | `vp-ui.css`, `vp-dentist.css` | `vp-menu.js`, `vp-dentist.js` | `VP_MENU` | `/vp/v1/dentist/*` | `vp-dentist-cabinet.php` | Directus | используется |

\* `functions.php` подключает `/instruction.css`, но в теме на момент аудита виден только inline JS в `page-instruction.php`; файл `instruction.css` должен быть отдельно проверен в продовом FS.

## D) Что реально используется

1. **Core product flow scan → instruction/3d** реализован связкой:
   - `page-scan.php` + `assets/vp-scan.js`
   - `page-instruction.php` (inline JS)
   - `page-3d.php` + `page-3d.js`
   - `vp-proxy-directus.php` endpoints.
2. **Token flow для 3D** используется:
   - lookup возвращает scene payload,
   - при protected scene: `/3d/auth` выдаёт transient token,
   - `/3d/file` проверяет token + scene/code match,
   - `/3d/poster` отдаёт постер отдельно.
3. **Universal login/app flow** используется:
   - `/login` + `vp-login.js` + `vp-login.php`;
   - `/app` + `vp-app.js` + `/vp/v1/app/*` в `vp-app.php`.
4. **Dentist flow** используется через `vp-dentist.js` + `vp-dentist-cabinet.php`, но имеет архитектурный конфликт с универсальным login (см. ниже).

## E) Что выглядит лишним / конфликтным / устаревшим

1. **Явные следы неудачного patch/merge в теме**:
   - `page-instruction.php.orig`, `page-instruction.php.rej`.
2. **Backup-файл в MU-плагинах**:
   - `vp-onboarding-admin.php.bak.2026-02-18_112037`.
3. **Конфликт маршрута `/dentist-login`**:
   - есть отдельный template `page-dentist-login.php`,
   - но `vp-login.php` делает `template_redirect` 301 с `/dentist-login` на `/login`.
   - Итог: вероятно страница `page-dentist-login.php` фактически недостижима в runtime.
4. **Потенциальный asset-mismatch**:
   - `functions.php` enqueue `instruction.css`, но в текущем списке файлов темы этот файл не зафиксирован.
5. **Дублирование доменной логики dentist**:
   - есть отдельный стек `vp-dentist-cabinet.php` (`/vp/v1/dentist/*`) и частично dentist-route в `vp-app.php` (`/vp/v1/app/dentist/cases`), что усложняет поддержку и диагностику.
6. **Риск от «грязного серверного зеркала»**:
   - наличие `.orig/.rej/.bak` в дереве повышает шанс случайного деплоя неканоничных артефактов и рассинхрона между окружениями.

## F) Минимальный безопасный cleanup plan (без ломки prod)

### Этап 0 — Freeze + snapshot (обязательно)
1. Снять runtime snapshot (`runtime/logs`, `runtime/directus`) перед любыми изменениями.
2. Зафиксировать список WP pages + assigned templates из БД (`wp_posts`, `_wp_page_template`) только read-only выборками.
3. Проверить фактическую доступность slug-ов `/scan`, `/instruction`, `/3d`, `/login`, `/app`, `/dentist-login`, `/dentist` на staging.

### Этап 1 — Нерисковые cleanup-артефакты
1. Убрать из репозитория/production tree только явный мусор: `.orig`, `.rej`, `.bak`.
2. Никаких изменений runtime-кода на этом этапе.

### Этап 2 — Верификация конфликтов без функциональных изменений
1. Подтвердить, что редирект `/dentist-login -> /login` действительно активен и ожидаем продуктово.
2. Если редирект нужен — пометить `page-dentist-login.php` как legacy/deprecated (без удаления в этот же релиз).
3. Если редирект не нужен — отдельным малым PR согласовать один канонический путь.

### Этап 3 — Asset hygiene
1. Проверить наличие и использование `instruction.css` в целевом окружении.
2. Если файла нет и стили не требуются — отдельным PR удалить enqueue.
3. Если файл есть только на сервере и отсутствует в git — вернуть файл в репозиторий (или перейти на `assets/...` путь).

### Этап 4 — Маршруты и дубли
1. Зафиксировать decision-log: канонический API для dentist (либо `/vp/v1/dentist/*`, либо `/vp/v1/app/dentist/*` как migration target).
2. До миграции — ничего не удалять, только добавить observability (логи использования endpoint'ов).

### Этап 5 — Финальный стабилизационный проход
1. Smoke-test все ключевые flow:
   - scan lookup/suggest,
   - instruction open/share/copy,
   - 3d public/protected + token expiry,
   - login/register,
   - app bootstrap + me/profile,
   - dentist login/cases/upload.
2. Только после успешного smoke — удалять legacy templates/assets по одному за релиз.

## G) Примечания по изменениям

В рамках данного аудита **функциональные изменения в код WP/MU не вносились**.  
Добавлен только текстовый артефакт аудита, чтобы зафиксировать карту зависимостей и безопасный план cleanup.

