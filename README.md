# ВсёПонятно (vseponyatno)

Платформа для быстрого доступа к инструкциям, карточкам товаров и (в будущем) навигации по торговым центрам через QR-коды.

## Ключевая идея
Одна универсальная страница `/instruction/` отображает:
- инструкции по товарам
- карточки
- навигацию и сервисные сценарии (в будущем)

QR-код — это маршрутизатор сценария, а не просто ссылка.

## Архитектура
Frontend: WordPress (PWA /scan + /instruction)  
Backend: Directus + PostgreSQL  
Связь: WordPress → Directus (server-to-server proxy)

## Домены (punycode)
WordPress: xn--b1awacccnl0jqa.xn--p1ai  
Directus: directus.xn--b1awacccnl0jqa.xn--p1ai

## Документация
См. каталог `docs/`:
- architecture.md
- data-model.md
- api-contract.md
- runtime-state.md
- troubleshooting.md
- decision-log.md

## Новый маршрут 3D/навигации
В проект добавлена отдельная страница `/3d` (template: **VP 3D Navigation**, файл `page-3d.php`) для 3D/навигационных сценариев.

Маршрутизация из `/scan` после `lookup`:
- `instruction/product/service/manual` → `/instruction?code=...`
- `3d/navigation/nav/location` → `/3d?code=...`
- неизвестный тип → остаёмся на `/scan` с сообщением пользователю

Технически `/3d` использует локальные `page-3d.css`, `assets/vendor/model-viewer.min.js` и `page-3d.js` (без CDN).

## 3D scenes with password
Для коллекции Directus `vp_3d_scenes` реализован безопасный доступ через WordPress proxy:
- браузер работает только с WP REST (`/wp-json/vp/v1/...`)
- Directus API token хранится только на сервере WP
- для защищённых сцен используется краткоживущий токен после `POST /wp-json/vp/v1/3d/auth`
- сама модель отдается через `GET /wp-json/vp/v1/3d/file?code=...&token=...`

Дополнительно:
- `lookup` для 3D типов возвращает объект `scene` (`id`, `kind`, `requires_password`, `password_hint`, `expires_at`, `is_active`, `poster_url?`, `model_url?`)
- `model_url` публикуется только для сцен без пароля

Подробности: `docs/scan-routing.md`.

## 3D Engine Architecture
- `model-viewer.min.js` — это ESM-бандл. Если загрузить его как обычный script (без `type="module"`), браузер парсит `export` как синтаксическую ошибку (`Unexpected token "export"`).
- Глобальный `script_loader_tag` не используется, чтобы не менять поведение всех скриптов темы и не повторить прошлый 500-сценарий.
- Подключение сделано локально и изолировано внутри `page-3d.php`: только на шаблоне `/3d` вставляется `<script type="module" ...model-viewer.min.js>`, без CDN и без влияния на `/scan`, `/instruction` и PWA-цепочку.
- Flow сцен:
  - public: `lookup` → `scene.model_url` → загрузка viewer без пароля;
  - protected: `lookup` → форма пароля → `POST /wp-json/vp/v1/3d/auth` → `GET /wp-json/vp/v1/3d/file?code&token`.
