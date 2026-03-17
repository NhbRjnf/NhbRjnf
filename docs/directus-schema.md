# Directus Schema — ПРОЕКТ «ВСЁ ПОНЯТНО»

Версия документа: 2.0  
Дата фиксации: 2026-03-06  
Источник истины: `Data_Model_Directus_snapshot_06_03_26.json`

## Назначение документа

Этот файл фиксирует фактическую схему Directus на 2026-03-06:
- какие коллекции реально существуют;
- какие поля реально есть;
- какие типы и связи используются;
- где integer ID, а где uuid-связи на users / files.

Правило:
если markdown и живой snapshot расходятся, верим snapshot.

## Общая картина

В snapshot присутствуют пользовательские коллекции:

- `products`
- `instruction_sets`
- `instruction_steps`
- `instruction_assets`
- `qr_codes`
- `vp_3d_scenes`
- `vp_3d_jobs`
- `vp_tenants`
- `vp_memberships`
- `vp_invites`
- `vp_user_profiles`
- `vp_onboarding_requests`
- `vp_allowlist_domains`
- `vp_clinics`
- `vp_patients`
- `vp_cases`
- `vp_case_scans`
- `vp_share_links`
- `vp_cards`

Также присутствуют:
- `directus_sync_id_map`
- `Existing_Table`

## Важные правила чтения схемы

- Почти все ID в бизнес-коллекциях — `integer`.
- Связи на `directus_users` — `uuid`.
- Связи на `directus_files` — `uuid`.
- `tenant_id` почти везде указывает на `vp_tenants.id`.
- `instruction_id`, `product_id`, `scene_id`, `case_id`, `clinic_id`, `patient_id` — integer m2o-связи.

## `products`
Описание: Товары/объекты, к которым привязаны QR-коды

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `title` | `string` | да |  |
| `brand` | `string` | нет |  |
| `model` | `string` | нет |  |
| `sku` | `string` | нет | Артикул / внутренний код (по желанию) |
| `instruction_url` | `string` | нет | Ссылка на 3D/инструкцию/страницу |
| `description` | `text` | нет |  |
| `instruction_id` | `integer` | нет | instruction_sets.id |
| `tenant_id` | `integer` | нет | vp_tenants.id |


## `instruction_sets`
Описание: Инструкции (LEVEL A/B/C). Корневые записи инструкций.

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `title` | `string` | да |  |
| `brand` | `string` | нет |  |
| `model` | `string` | нет |  |
| `level` | `string` | нет |  |
| `language` | `string` | нет | например: ru-RU |
| `source_file` | `uuid` | нет | directus_files.id |
| `source_url` | `string` | нет |  |
| `notes` | `text` | нет |  |
| `is_published` | `boolean` | нет |  |
| `tenant_id` | `integer` | нет | vp_tenants.id |


## `instruction_steps`
Описание: Шаги инструкции (LEVEL A: 2D интерактив).

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `instruction_id` | `integer` | да | instruction_sets.id |
| `step_no` | `integer` | да |  |
| `title` | `string` | нет |  |
| `body` | `text` | нет |  |
| `image_file` | `uuid` | нет | directus_files.id |
| `hotspots` | `json` | нет | Массив кликабельных областей на изображении |
| `tenant_id` | `integer` | нет | vp_tenants.id |


## `instruction_assets`
Описание: Файлы и производные ассеты инструкции (PDF/PNG/SVG/GLB).

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `instruction_id` | `integer` | да | instruction_sets.id |
| `title` | `string` | нет |  |
| `kind` | `string` | нет |  |
| `file` | `uuid` | да | directus_files.id |
| `meta` | `json` | нет |  |
| `tenant_id` | `integer` | нет | vp_tenants.id |


## `qr_codes`
Описание: QR-коды: product/location/service

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `code` | `string` | да | Например: VP-TEST-001 |
| `type` | `string` | да |  |
| `title` | `string` | нет | Опционально: заголовок (если не product) |
| `instruction_url` | `string` | нет | Override: если нужно переопределить ссылку продукта |
| `is_active` | `boolean` | да |  |
| `notes` | `text` | нет |  |
| `location_title` | `string` | нет |  |
| `location_payload` | `json` | нет |  |
| `service_payload` | `json` | нет |  |
| `product_id` | `integer` | нет | products.id |
| `qr_payload_url` | `string` | нет | Фактический URL, который кодируется в QR (заполняется автоматически) |
| `qr_file` | `uuid` | нет | directus_files.id |
| `qr_file_png` | `uuid` | нет | directus_files.id |
| `instruction_id` | `integer` | нет | instruction_sets.id |
| `scene_id` | `integer` | нет | vp_3d_scenes.id |
| `tenant_id` | `integer` | нет | vp_tenants.id |


## `vp_3d_scenes`
Описание: 3D сцены/модели для страницы /3d (навигация, стоматология и т.д.)

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `title` | `string` | да | Название сцены/модели |
| `kind` | `string` | да | Тип сцены/кейса (управляет UI и логикой просмотра) |
| `description` | `text` | нет | Краткое описание |
| `is_active` | `boolean` | да | Показывать/использовать сцену |
| `requires_password` | `boolean` | да | Требовать пароль перед показом |
| `password_hash` | `string` | нет | Хеш пароля (bcrypt/argon2). Пароль не хранить в чистом виде. |
| `password_hint` | `string` | нет | Подсказка к паролю (опционально) |
| `expires_at` | `timestamp` | нет | Дата/время, после которых доступ запрещён (опционально) |
| `model_file` | `uuid` | да | directus_files.id |
| `poster_file` | `uuid` | нет | directus_files.id |
| `viewer_config` | `json` | нет | Настройки viewer (камера, аннотации, точки интереса и т.п.) |
| `created_at` | `timestamp` | нет | Создано |
| `updated_at` | `timestamp` | нет | Обновлено |
| `tenant_id` | `integer` | нет | vp_tenants.id |
| `case_scan_id` | `integer` | нет | vp_case_scans.id |


## `vp_3d_jobs`
Описание: —

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `status` | `string` | нет |  |
| `progress` | `integer` | нет |  |
| `error` | `text` | нет |  |
| `meta` | `json` | нет |  |
| `completed_at` | `timestamp` | нет |  |
| `input_file` | `uuid` | да |  |
| `output_file` | `uuid` | нет |  |
| `preview_file` | `uuid` | нет |  |


## `vp_tenants`
Описание: Организации/клиенты (тенанты) SaaS

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `slug` | `string` | да | Короткий идентификатор для URL/поиска. Латиница/цифры/дефис. |
| `name` | `string` | да |  |
| `type` | `string` | да | Тип организации (для включения модулей). |
| `status` | `string` | да |  |
| `plan` | `string` | нет | Тариф/план (пока без биллинга). |
| `settings` | `json` | нет | JSON-настройки тенанта (брендинг, фичи, лимиты). |
| `created_at` | `timestamp` | нет |  |
| `updated_at` | `timestamp` | нет |  |


## `vp_memberships`
Описание: Связь пользователи ↔ тенанты (роли, доступ)

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `tenant_id` | `integer` | да | vp_tenants.id |
| `user_id` | `uuid` | да | directus_users.id |
| `role` | `string` | да |  |
| `status` | `string` | да |  |
| `created_at` | `timestamp` | нет |  |


## `vp_invites`
Описание: Приглашения в тенант (инвайты)

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `tenant_id` | `integer` | да | vp_tenants.id |
| `email` | `string` | да |  |
| `role` | `string` | да |  |
| `token` | `string` | да | Случайный токен инвайта. |
| `expires_at` | `timestamp` | нет |  |
| `accepted_at` | `timestamp` | нет |  |
| `created_by` | `uuid` | нет | directus_users.id |
| `created_at` | `timestamp` | нет |  |


## `vp_user_profiles`
Описание: Unified profiles for all user types

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `user_id` | `uuid` | да | directus_users.id |
| `user_type` | `string` | да |  |
| `status` | `string` | да |  |
| `phone` | `string` | нет |  |
| `locale` | `string` | нет | например: ru-RU |
| `timezone` | `string` | нет | например: Europe/Vienna |
| `notes` | `text` | нет |  |
| `dentist_license` | `string` | нет |  |
| `dentist_specialty` | `string` | нет |  |
| `dentist_clinic_name` | `string` | нет |  |
| `dentist_clinic_address` | `string` | нет |  |
| `dentist_clinic_phone` | `string` | нет |  |
| `dentist_bio` | `text` | нет |  |
| `clinic_role` | `string` | нет | владелец/админ/менеджер |
| `clinic_position` | `string` | нет |  |
| `car_make` | `string` | нет |  |
| `car_model` | `string` | нет |  |
| `car_year` | `integer` | нет |  |
| `car_plate` | `string` | нет |  |
| `car_vin_last4` | `string` | нет |  |
| `car_emergency_contact` | `string` | нет |  |
| `car_insurance_phone` | `string` | нет |  |
| `biz_name` | `string` | нет |  |
| `biz_type` | `string` | нет | автосервис/дилер/страховая/эвакуатор |
| `biz_tax_id` | `string` | нет |  |
| `biz_website` | `string` | нет |  |
| `biz_support_phone` | `string` | нет |  |
| `biz_address` | `string` | нет |  |
| `loc_name` | `string` | нет | ТЦ/склад/завод/офис/ЖК |
| `loc_type` | `string` | нет |  |
| `loc_address` | `string` | нет |  |
| `loc_city` | `string` | нет |  |
| `loc_country` | `string` | нет |  |
| `loc_contact_person` | `string` | нет |  |
| `partner_brand` | `string` | нет |  |
| `partner_categories` | `string` | нет | например: мебель, техника, мед, авто |
| `partner_website` | `string` | нет |  |
| `partner_about` | `text` | нет |  |
| `created_at` | `timestamp` | нет |  |
| `updated_at` | `timestamp` | нет |  |
| `avatar_file` | `uuid` | нет | directus_files.id |


## `vp_onboarding_requests`
Описание: Registration/onboarding requests for auto-approve workflow

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `email` | `string` | да |  |
| `phone` | `string` | нет |  |
| `first_name` | `string` | нет |  |
| `last_name` | `string` | нет |  |
| `user_type` | `string` | да |  |
| `status` | `string` | да |  |
| `auto_approve_method` | `string` | да |  |
| `invite_code` | `string` | нет | если используем авто‑утверждение по инвайту |
| `requested_tenant_name` | `string` | нет | для клиники/бизнеса/локации/партнёра |
| `requested_tenant_slug` | `string` | нет | если нужен конкретный slug |
| `evidence_note` | `text` | нет | Свободный текст/ссылки/описание подтверждения |
| `source_ip` | `string` | нет |  |
| `user_agent` | `text` | нет |  |
| `created_user_id` | `uuid` | нет | directus_users.id |
| `created_profile_id` | `integer` | нет | vp_user_profiles.id |
| `reviewed_by` | `uuid` | нет | directus_users.id |
| `decision_reason` | `text` | нет |  |
| `created_at` | `timestamp` | нет |  |
| `updated_at` | `timestamp` | нет |  |
| `reviewed_at` | `dateTime` | нет |  |
| `reviewed_by_email` | `string` | нет |  |
| `reviewed_by_wp_id` | `integer` | нет |  |
| `reviewed_by_wp_login` | `string` | нет |  |


## `vp_allowlist_domains`
Описание: Allowlist email domains for auto-approve onboarding

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `domain` | `string` | да | Example: clinic.com (без @) |
| `is_active` | `boolean` | да |  |
| `auto_approve_method` | `string` | нет | Для аудита/отладки, обычно 'allowlist' |
| `notes` | `text` | нет |  |
| `sort` | `integer` | нет |  |
| `created_at` | `timestamp` | нет |  |


## `vp_clinics`
Описание: Профили клиник (филиалы) внутри тенанта

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `tenant_id` | `integer` | да | vp_tenants.id |
| `name` | `string` | да |  |
| `city` | `string` | нет |  |
| `address` | `text` | нет |  |
| `phone` | `string` | нет |  |
| `website` | `string` | нет |  |
| `timezone` | `string` | нет |  |
| `branding` | `json` | нет | JSON (логотип, цвета, подпись). |
| `created_at` | `timestamp` | нет |  |


## `vp_patients`
Описание: Пациенты (минимальные данные, без ПДн по умолчанию)

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `tenant_id` | `integer` | да | vp_tenants.id |
| `clinic_id` | `integer` | нет | vp_clinics.id |
| `external_id` | `string` | нет | ID пациента в системе клиники (если есть). |
| `initials` | `string` | нет | Инициалы/код пациента, чтобы не хранить ФИО. |
| `birth_year` | `integer` | нет | Год рождения (опционально). |
| `notes` | `text` | нет |  |
| `consent` | `boolean` | нет |  |
| `created_at` | `timestamp` | нет |  |


## `vp_cases`
Описание: Кейсы/заказы (например, ортопедия/имплантация)

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `tenant_id` | `integer` | да | vp_tenants.id |
| `clinic_id` | `integer` | нет | vp_clinics.id |
| `patient_id` | `integer` | нет | vp_patients.id |
| `case_code` | `string` | да | Код кейса (может использоваться в QR/ссылках). |
| `title` | `string` | да |  |
| `status` | `string` | да |  |
| `description` | `text` | нет |  |
| `created_at` | `timestamp` | нет |  |
| `updated_at` | `timestamp` | нет |  |


## `vp_case_scans`
Описание: Сканы/3D модели для кейса (raw + конвертация)

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `tenant_id` | `integer` | да | vp_tenants.id |
| `case_id` | `integer` | да | vp_cases.id |
| `kind` | `string` | да |  |
| `source_format` | `string` | нет | STL/PLY/OBJ/GLB и т.п. |
| `source_file` | `uuid` | нет | directus_files.id |
| `converted_file` | `uuid` | нет | directus_files.id |
| `preview_image` | `uuid` | нет | directus_files.id |
| `metadata` | `json` | нет | JSON: измерения/единицы/комментарии. |
| `conversion_status` | `string` | да |  |
| `conversion_error` | `text` | нет |  |
| `created_at` | `timestamp` | нет |  |


## `vp_share_links`
Описание: Публичные/временные ссылки (шэринг)

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `tenant_id` | `integer` | да | vp_tenants.id |
| `token` | `string` | да |  |
| `resource_type` | `string` | да | Напр. instruction_sets / vp_3d_scenes / vp_cases |
| `resource_id` | `string` | да | ID ресурса (строкой для универсальности). |
| `permissions` | `json` | нет | JSON: разрешения доступа. |
| `expires_at` | `timestamp` | нет |  |
| `created_at` | `timestamp` | нет |  |


## `vp_cards`
Описание: —

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
| `title` | `string` | нет |  |
| `position` | `string` | нет |  |
| `avatar_url` | `uuid` | нет | directus_files.id |
| `description` | `text` | нет |  |
| `color` | `string` | нет |  |
| `links` | `string` | нет |  |
| `tenant_id` | `integer` | нет | vp_tenants.id |


## `directus_sync_id_map`
Описание: —

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|


## `Existing_Table`
Описание: —

| Поле | Тип | Обяз. | Связь / примечание |
|---|---|---:|---|
| `id` | `integer` | нет |  |
