# Directus Schema — ПРОЕКТ «ВСЁ ПОНЯТНО»
Версия документа: 1.0 (SaaS)
Дата: 2026-02-12
Автор: ChatGPT (архитектурная фиксация)

## Назначение документа
Этот файл описывает структуру данных Directus проекта «Всё Понятно»:
- какие коллекции существуют,
- какие поля есть в каждой коллекции,
- для чего нужна каждая сущность и каждое поле,
- какие связи используются,
- как схема поддерживает SaaS (multi-tenant) и стоматологию.

Документ написан так, чтобы:
- разработчик понимал архитектуру;
- администратор Directus мог вручную создать/проверить поля;
- Codex мог использовать его как «источник истины» при генерации кода.

---

# 1. Общая архитектура данных

Directus используется как:
- **Data Layer** для продуктов, QR и инструкций;
- **File Storage Layer** (Directus Files) для моделей 3D и ассетов;
- **Access Layer** через роли/политики и вспомогательные таблицы SaaS;
- **API ядро** (REST) для WordPress proxy и внутренних сервисов (конвертация, генерация QR).

Схема делится на 4 слоя:
1) **Core SaaS Layer** — организации, участники, приглашения.
2) **Business Layer** — товары, QR, инструкции, карточки, 3D сцены.
3) **Dental Layer** — клиники, кейсы, сканы, пациенты.
4) **Service Layer** — временные публичные ссылки/токены (share links), аудит и пр.

Главный принцип SaaS:
> Почти все бизнес-данные должны иметь `tenant_id`, чтобы изолировать организации.

---

# 2. Core SaaS Layer

## 2.1 vp_tenants — Организации (тенанты)
**Описание:** организация-клиент платформы (стоматология, торговый центр, производитель, сервис и т.д.).
Это корневой контейнер изоляции данных. Все объекты (QR, 3D, инструкции) принадлежат какому-то tenant.

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ, используется во всех связях (tenant_id).
- `name` (string)
  - **Зачем:** человекочитаемое название организации (отображается в UI).
- `slug` (string, unique)
  - **Зачем:** удобный короткий идентификатор (можно использовать в URL/логике интеграций).
- `type` (enum: clinic | mall | brand | generic)
  - **Зачем:** тип организации для сценариев UI/аналитики и преднастроек.
- `status` (enum: active | suspended | archived)
  - **Зачем:** состояние tenant (например, блокировка за неуплату).
- `created_at` (datetime, date-created)
  - **Зачем:** аудит, сортировка, аналитика.
- `updated_at` (datetime, date-updated)
  - **Зачем:** аудит и контроль изменений.

---

## 2.2 vp_memberships — Участники организации (membership)
**Описание:** связь `directus_users` ↔ `vp_tenants` с ролью и статусом.
Нужна для многоорганизационного доступа: один пользователь может работать в нескольких организациях.

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ membership.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** к какой организации относится участник.
- `user_id` (m2o → directus_users.id)
  - **Зачем:** какой пользователь является участником.
- `role` (enum: owner | admin | editor | dentist | viewer)
  - **Зачем:** роль участника внутри tenant (для политик и UI).
- `status` (enum: active | invited | disabled)
  - **Зачем:** состояние membership. Например: приглашён, активен, отключён.
- `created_at` (datetime, date-created)
  - **Зачем:** аудит.

Примечание:
- `owner` — владелец организации (максимальные права).
- `admin` — администратор (управляет пользователями/контентом).
- `editor` — контент-менеджер (создаёт инструкции/QR/сцены).
- `dentist` — врач (создаёт и видит стоматологические кейсы/сканы).
- `viewer` — только просмотр.

---

## 2.3 vp_invites — Приглашения в организацию
**Описание:** инвайты по email для подключения сотрудников к tenant.
Позволяет добавлять новых пользователей без ручного создания membership.

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ инвайта.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** куда приглашаем.
- `email` (string)
  - **Зачем:** на какой email отправлено приглашение.
- `role` (enum: owner | admin | editor | dentist | viewer)
  - **Зачем:** какая роль будет выдана после принятия.
- `token` (string, unique)
  - **Зачем:** секретный токен приглашения (используется в ссылке/подтверждении).
- `expires_at` (datetime)
  - **Зачем:** срок действия приглашения.
- `accepted` (boolean)
  - **Зачем:** принято ли приглашение.
- `accepted_at` (datetime, optional)
  - **Зачем:** когда принято (аудит).
- `created_at` (datetime, date-created)
  - **Зачем:** аудит.

---

# 3. Business Layer

## 3.1 products — Товары
**Описание:** товары, к которым привязаны инструкции и/или QR.
Используется в B2C сценариях («сканируй QR на товаре»).

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ товара.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** изоляция товара по организации.
- `title` (string)
  - **Зачем:** название товара.
- `brand` (string)
  - **Зачем:** бренд для UI/фильтра.
- `model` (string)
  - **Зачем:** модель/серия товара.
- `sku` (string)
  - **Зачем:** артикул.
- `description` (text)
  - **Зачем:** описание товара (может выводиться на /instruction).
- `instruction_url` (string, optional)
  - **Зачем:** внешняя ссылка на инструкцию (fallback или основная).
- `instruction_id` (m2o → instruction_sets.id, optional)
  - **Зачем:** связь на внутренний набор шагов в Directus.
- `created_at` (datetime, date-created)
  - **Зачем:** аудит/аналитика.
- `updated_at` (datetime, date-updated)
  - **Зачем:** аудит.

---

## 3.2 instruction_sets — Наборы инструкций
**Описание:** «карточка инструкции» (title, тип, уровень, язык и т.д.), которая содержит список шагов.
Используется страницей /instruction.

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ инструкции.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** изоляция инструкций по организации.
- `title` (string)
  - **Зачем:** название инструкции.
- `type` (enum: product | service | location | navigation, optional)
  - **Зачем:** сценарий инструкции (влияет на UI и CTA).
- `language` (string, optional)
  - **Зачем:** язык инструкции (ru/en и т.п.).
- `level` (string/enum, optional)
  - **Зачем:** уровень сложности (A/B/C или числовой).
- `status` (enum: draft | published | archived)
  - **Зачем:** публикация инструкции.
- `description` (text, optional)
  - **Зачем:** описание/примечания.
- `instruction_url` (string, optional)
  - **Зачем:** внешняя ссылка на документ/видео/страницу (если шагов нет или нужен доп.ресурс).
- `location_payload` (json, optional)
  - **Зачем:** данные локации (например, адрес, карта, часы).
- `navigation_payload` (json, optional)
  - **Зачем:** маршрут/навигация (шаги, ориентиры, медиа).
- `created_at` (datetime, date-created)
  - **Зачем:** аудит.
- `updated_at` (datetime, date-updated)
  - **Зачем:** аудит.

---

## 3.3 instruction_steps — Шаги инструкций
**Описание:** шаги конкретного instruction_set (порядок, текст, медиа, подсказки).
Используется на /instruction для пошагового UI.

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ шага.
- `tenant_id` (m2o → vp_tenants.id) или наследование через instruction_set_id
  - **Зачем:** изоляция по tenant (если включаем напрямую).
- `instruction_set_id` (m2o → instruction_sets.id)
  - **Зачем:** к какой инструкции относится шаг.
- `step_no` (integer)
  - **Зачем:** порядок шага.
- `title` (string, optional)
  - **Зачем:** заголовок шага.
- `body` (text)
  - **Зачем:** описание шага (поддержка markdown ссылок).
- `media_type` (enum: image | video, optional)
  - **Зачем:** тип медиа.
- `media_url` (string, optional)
  - **Зачем:** ссылка на картинку/видео (или Directus file URL, или внешний URL).
- `hotspots` (json, optional)
  - **Зачем:** точки на медиа/3D (для будущего интерактива).
- `created_at` (datetime, date-created)
  - **Зачем:** аудит.
- `updated_at` (datetime, date-updated)
  - **Зачем:** аудит.

---

## 3.4 instruction_assets — Ассеты инструкций
**Описание:** файлы, связанные с инструкциями: pdf, изображения, дополнительные материалы.

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ ассета.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** изоляция по tenant.
- `instruction_set_id` (m2o → instruction_sets.id, optional)
  - **Зачем:** ассет инструкции.
- `file` (file → directus_files.id)
  - **Зачем:** загруженный файл.
- `type` (enum: pdf | image | video | doc | other)
  - **Зачем:** классификация в UI.
- `title` (string, optional)
  - **Зачем:** подпись/название.
- `created_at` (datetime, date-created)
  - **Зачем:** аудит.

---

## 3.5 qr_codes — QR коды
**Описание:** сущность QR. Хранит “что делать” по коду: открыть продукт, инструкцию, 3D, локацию.
Ядро маршрутизации /scan.

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** изоляция QR по организации.
- `code` (string, unique)
  - **Зачем:** внешний код (например, VP-XXXX), который вводит пользователь.
- `type` (enum: product | service | location | navigation | 3d)
  - **Зачем:** сценарий QR (маршрутизация).
- `product_id` (m2o → products.id, optional)
  - **Зачем:** связь QR → товар.
- `instruction_id` (m2o → instruction_sets.id, optional)
  - **Зачем:** связь QR → инструкция.
- `scene_id` (m2o → vp_3d_scenes.id, optional)
  - **Зачем:** связь QR → 3D сцена.
- `instruction_url` (string, optional)
  - **Зачем:** override URL (если надо открыть конкретную ссылку вместо дефолтной).
- `location_title` (string, optional)
  - **Зачем:** человекочитаемое название места/локации.
- `location_payload` (json, optional)
  - **Зачем:** данные локации (адрес, карта, etc).
- `service_payload` (json, optional)
  - **Зачем:** данные сервиса (заявка, контакты, часы).
- `navigation_payload` (json, optional)
  - **Зачем:** данные маршрута (для /instruction или другого UI).
- `is_active` (boolean)
  - **Зачем:** выключение QR без удаления.
- `qr_svg_file` (file, optional)
  - **Зачем:** хранение сгенерированного SVG QR.
- `qr_png_file` (file, optional)
  - **Зачем:** хранение сгенерированного PNG QR.
- `created_at` (datetime, date-created)
  - **Зачем:** аудит.

---

## 3.6 vp_3d_scenes — 3D сцены (универсальные)
**Описание:** универсальный контейнер 3D сцены (дентал/навигация/прочее).
Может быть публичным или защищённым паролем/сроком.
Используется страницей /3d и логикой protected file proxy.

### Поля
- `id` (uuid)
  - **Зачем:** первичный ключ сцены.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** изоляция по tenant.
- `title` (string)
  - **Зачем:** название сцены (для UI).
- `kind` (enum: dental | navigation | generic)
  - **Зачем:** тип сцены (влияет на viewer_config, UI и сценарии).
- `model_file` (file → directus_files.id)
  - **Зачем:** основной файл модели для просмотра (целевой формат — GLB).
- `poster_file` (file → directus_files.id, optional)
  - **Зачем:** картинка-постер для превью.
- `viewer_config` (json, optional)
  - **Зачем:** настройки viewer (cameraOrbit, exposure, toneMapping, hotspots, etc).
- `requires_password` (boolean)
  - **Зачем:** требовать пароль для просмотра.
- `password_hash` (string, optional)
  - **Зачем:** хранение хеша пароля (никогда не хранить пароль в чистом виде).
- `expires_at` (datetime, optional)
  - **Зачем:** срок действия доступа к сцене (например, по мед. кейсам).
- `is_active` (boolean)
  - **Зачем:** выключение сцены без удаления.
- `case_scan_id` (m2o → vp_case_scans.id, optional)
  - **Зачем:** связь с источником (стоматологический скан), если сцена создана из него.
- `created_at` (datetime, date-created)
  - **Зачем:** аудит.
- `updated_at` (datetime, date-updated)
  - **Зачем:** аудит.

---

## 3.7 vp_cards — Карточки контента (универсальные)
**Описание:** универсальная витрина карточек для UI (если используется в проекте).
Может применяться на /instruction, /scan, как будущий контентный слой.

### Поля (примерно)
- `id` (uuid)
  - **Зачем:** первичный ключ.
- `tenant_id` (m2o)
  - **Зачем:** изоляция.
- `title` (string)
  - **Зачем:** заголовок карточки.
- `body` (text)
  - **Зачем:** текст карточки.
- `media_file` (file, optional)
  - **Зачем:** медиа.
- `link_url` (string, optional)
  - **Зачем:** ссылка-CTA.
- `status` (enum, optional)
  - **Зачем:** публикация/архив.

---

# 4. Dental Layer (стоматология)

## 4.1 vp_clinics — Клиники/филиалы внутри tenant
**Описание:** у одной организации может быть несколько филиалов/точек.
Удобно для сети клиник.

### Поля
- `id` (uuid)
  - **Зачем:** PK.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** к какой организации относится филиал.
- `name` (string)
  - **Зачем:** название филиала.
- `address` (string, optional)
  - **Зачем:** адрес.
- `phone` (string, optional)
  - **Зачем:** контакт.
- `timezone` (string, optional)
  - **Зачем:** часовой пояс филиала (если понадобится).
- `created_at` / `updated_at`
  - **Зачем:** аудит.

---

## 4.2 vp_patients — Пациенты (минимально, без ПДн по умолчанию)
**Описание:** пациентские записи. Для SaaS важно не хранить лишние ПДн.
Рекомендуемый подход: хранить только `external_id` + заметки (без ФИО/дат рождения).
Если нужно ПДн — добавлять отдельным модулем и юридически закрывать.

### Поля
- `id` (uuid)
  - **Зачем:** PK.
- `tenant_id` (m2o)
  - **Зачем:** изоляция по организации.
- `clinic_id` (m2o → vp_clinics.id, optional)
  - **Зачем:** принадлежность пациентской записи филиалу.
- `external_id` (string, optional)
  - **Зачем:** ID пациента из внутренней системы клиники (без ПДн).
- `notes` (text, optional)
  - **Зачем:** общие заметки (не хранить медицинскую тайну без необходимости).
- `created_at` / `updated_at`
  - **Зачем:** аудит.

---

## 4.3 vp_cases — Кейсы (заказы/обращения)
**Описание:** кейс — единица работы (например, «Имплантация 2026-02-12»).
Кейс объединяет сканы, результаты конвертации и 3D сцены.

### Поля
- `id` (uuid)
  - **Зачем:** PK.
- `tenant_id` (m2o)
  - **Зачем:** изоляция по организации.
- `clinic_id` (m2o → vp_clinics.id, optional)
  - **Зачем:** где создан кейс.
- `patient_id` (m2o → vp_patients.id, optional)
  - **Зачем:** к какому пациенту относится кейс (минимально).
- `title` (string)
  - **Зачем:** человекочитаемое название.
- `status` (enum: draft | processing | ready | archived)
  - **Зачем:** статус обработки (конвертация/готово).
- `created_by` (m2o → directus_users.id, optional)
  - **Зачем:** кто создал.
- `created_at` / `updated_at`
  - **Зачем:** аудит.

---

## 4.4 vp_case_scans — Загруженные сканы + конвертация
**Описание:** сюда попадает исходный файл скана (STL/OBJ/PLY) и результат конвертации (GLB).
Это ядро пайплайна: загрузка → конвертация → 3D сцена.

### Поля
- `id` (uuid)
  - **Зачем:** PK.
- `tenant_id` (m2o → vp_tenants.id)
  - **Зачем:** изоляция.
- `case_id` (m2o → vp_cases.id)
  - **Зачем:** к какому кейсу относится скан.
- `raw_file` (file → directus_files.id)
  - **Зачем:** исходник (STL/OBJ/PLY).
- `raw_format` (enum: stl | obj | ply)
  - **Зачем:** тип исходника для выбора конвертера.
- `converted_file` (file → directus_files.id, optional)
  - **Зачем:** итоговый web-friendly файл (GLB).
- `conversion_status` (enum: pending | processing | done | failed)
  - **Зачем:** статус конвертации.
- `conversion_error` (text, optional)
  - **Зачем:** текст ошибки, если failed.
- `conversion_log` (text, optional)
  - **Зачем:** лог конвертации (короткий).
- `source_device` (string, optional)
  - **Зачем:** название сканера/ПО (если нужно для аналитики).
- `created_by` (m2o → directus_users.id, optional)
  - **Зачем:** кто загрузил.
- `created_at` / `updated_at`
  - **Зачем:** аудит.

---

# 5. Service Layer

## 5.1 vp_share_links — Публичные временные ссылки (share)
**Описание:** механизм «поделиться ссылкой на сцену» без раскрытия Directus.
Используется для B2B/B2C сценариев: отправить пациенту ссылку с временем жизни.

### Поля
- `id` (uuid)
  - **Зачем:** PK.
- `tenant_id` (m2o)
  - **Зачем:** изоляция.
- `scene_id` (m2o → vp_3d_scenes.id)
  - **Зачем:** на какую сцену выдаём share.
- `token` (string, unique)
  - **Зачем:** публичный токен ссылки.
- `expires_at` (datetime, optional)
  - **Зачем:** срок действия.
- `access_count` (integer, default 0)
  - **Зачем:** счетчик просмотров.
- `last_access_at` (datetime, optional)
  - **Зачем:** последнее использование.
- `created_by` (m2o → directus_users.id, optional)
  - **Зачем:** кто создал ссылку.
- `created_at` / `updated_at`
  - **Зачем:** аудит.

---

# 6. Связи (Relationship Map)

## Основные
- `vp_tenants` 1—N `products`
- `vp_tenants` 1—N `qr_codes`
- `vp_tenants` 1—N `instruction_sets`
- `instruction_sets` 1—N `instruction_steps`
- `vp_tenants` 1—N `vp_3d_scenes`
- `qr_codes` N—1 `vp_3d_scenes` (через scene_id)
- `qr_codes` N—1 `products` (через product_id)
- `qr_codes` N—1 `instruction_sets` (через instruction_id)

## Стоматология
- `vp_tenants` 1—N `vp_clinics`
- `vp_tenants` 1—N `vp_cases`
- `vp_cases` 1—N `vp_case_scans`
- `vp_case_scans` 0..1—1 `vp_3d_scenes` (через vp_3d_scenes.case_scan_id)
- `vp_clinics` 1—N `vp_patients` (optional)
- `vp_patients` 1—N `vp_cases` (optional)

## Пользователи
- `directus_users` 1—N `vp_memberships`
- `vp_tenants` 1—N `vp_memberships`
- `vp_tenants` 1—N `vp_invites`

---

# 7. Принципы безопасности и SaaS-изоляции

1) Все запросы к данным должны фильтроваться по `tenant_id`.
2) Пользователь видит только те tenants, где у него есть `vp_memberships.status = active`.
3) Для стоматологии:
   - 3D сцены могут быть защищены `requires_password/password_hash` и `expires_at`.
   - share links (`vp_share_links`) должны быть ограничены по сроку.

---

# 8. Пайплайн конвертации стоматологических файлов

Цель: врач загружает STL/OBJ/PLY, система автоматически готовит GLB.

Поток:
1) Врач загружает файл → создаётся запись `vp_case_scans` со `conversion_status = pending`.
2) Конвертер забирает pending → ставит `processing`.
3) После конвертации загружается GLB как `converted_file`.
4) Создаётся или обновляется `vp_3d_scenes` (kind=dental) с `model_file = converted_file` и `case_scan_id`.
5) QR может ссылаться на `scene_id`.

---

# 9. Примечания по персональным данным
Для минимального рискового MVP:
- не хранить ФИО/даты рождения/телефоны пациента в Directus.
- использовать `external_id` и обезличенные заметки.
Если нужно хранить ПДн — делать отдельный модуль с политиками и юридическими требованиями.

---

# 10. Что будет описано отдельно (следующие документы)
- RBAC и Directus Policies (кто что видит и может делать).
- API контракт WordPress proxy для SaaS (login, tenant selection, CRUD кейсов).
- Docker converter service (assimp/blender/gltfpack) и мониторинг очереди конвертации.
