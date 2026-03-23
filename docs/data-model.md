# Модель данных Directus (всёпонятно)

Источник истины для этого файла: `Data_Model_Directus_snapshot_ADD_LOCATION__17_03_26.json`.

## 1. Общий принцип

Directus в проекте используется как:
- backend и data layer;
- файловое хранилище через `directus_files`;
- multi-tenant ядро;
- административный слой для инструкций, QR, onboarding и 3D.

Важно:
- почти все пользовательские коллекции имеют `integer` primary key;
- связи на `directus_users` и `directus_files` идут через `uuid`.

## 2. Контент и инструкции

### `products`
Карточка товара.

Поля:
- `id` — integer
- `title`
- `brand`
- `model`
- `sku`
- `instruction_url`
- `description`
- `instruction_id` → `instruction_sets.id`
- `tenant_id` → `vp_tenants.id`

### `instruction_sets`
Корневая инструкция / набор инструкции.

Поля:
- `id` — integer
- `title`
- `brand`
- `model`
- `level`
- `language`
- `source_file` → `directus_files.id`
- `source_url`
- `notes`
- `is_published`
- `tenant_id` → `vp_tenants.id`

### `instruction_steps`
Шаги инструкции.

Поля:
- `id` — integer
- `instruction_id` → `instruction_sets.id`
- `step_no`
- `title`
- `body`
- `image_file` → `directus_files.id`
- `hotspots` — json
- `tenant_id` → `vp_tenants.id`

### `instruction_assets`
Связанные ассеты инструкции.

Поля:
- `id` — integer
- `instruction_id` → `instruction_sets.id`
- `title`
- `kind`
- `file` → `directus_files.id`
- `meta` — json
- `tenant_id` → `vp_tenants.id`

## 3. QR и маршрутизация

### `qr_codes`
Маршрутизатор сценариев по коду.

Поля:
- `id` — integer
- `code`
- `type`
- `title`
- `instruction_url`
- `is_active`
- `notes`
- `location_title`
- `location_payload` — json
- `service_payload` — json
- `product_id` → `products.id`
- `qr_payload_url`
- `qr_file` → `directus_files.id`
- `qr_file_png` → `directus_files.id`
- `instruction_id` → `instruction_sets.id`
- `scene_id` → `vp_3d_scenes.id`
- `tenant_id` → `vp_tenants.id`

Логика:
- код определяет сценарий;
- product / service / manual обычно ведут к `/instruction`;
- 3d / navigation / location могут вести к `/3d` по текущему routing.






## 4. Indoor navigation layer

### `vp_locations`
Корневой объект indoor-навигации: ТЦ, гипермаркет, аэропорт, вокзал, клиника, здание.

Поля:
- `id` — integer
- `title`
- `slug`
- `kind`
- `description`
- `is_active`
- `address`
- `city`
- `country`
- `timezone`
- `default_language`
- `cover_image` → `directus_files.id`
- `logo` → `directus_files.id`
- `tenant_id` → `vp_tenants.id`
- `scene_id` → `vp_3d_scenes.id`
- `meta_json` — json
- `sort`
- `created_at`
- `updated_at`

### `vp_location_levels`
Уровни / этажи внутри объекта.

Поля:
- `id` — integer
- `location_id` → `vp_locations.id`
- `tenant_id` → `vp_tenants.id`
- `code`
- `title`
- `sort`
- `is_active`
- `z_index`
- `floor_plan_image` → `directus_files.id`
- `floor_plan_svg` → `directus_files.id`
- `floor_plan_geojson` — json
- `meta_json` — json
- `created_at`
- `updated_at`

### `vp_location_zones`
Зоны внутри уровня или объекта.

Поля:
- `id` — integer
- `location_id` → `vp_locations.id`
- `level_id` → `vp_location_levels.id`
- `tenant_id` → `vp_tenants.id`
- `parent_zone_id` → `vp_location_zones.id`
- `title`
- `slug`
- `kind`
- `description`
- `polygon_json` — json
- `center_x`
- `center_y`
- `is_active`
- `sort`
- `meta_json` — json

### `vp_location_nodes`
Узлы графа маршрутизации.

Поля:
- `id` — integer
- `location_id` → `vp_locations.id`
- `level_id` → `vp_location_levels.id`
- `zone_id` → `vp_location_zones.id`
- `tenant_id` → `vp_tenants.id`
- `title`
- `kind`
- `x`
- `y`
- `z`
- `is_active`
- `is_public`
- `accessibility_tags` — json
- `meta_json` — json

### `vp_location_edges`
Рёбра графа маршрутизации между узлами.

Поля:
- `id` — integer
- `location_id` → `vp_locations.id`
- `tenant_id` → `vp_tenants.id`
- `from_node_id` → `vp_location_nodes.id`
- `to_node_id` → `vp_location_nodes.id`
- `kind`
- `distance_m`
- `duration_s`
- `is_bidirectional`
- `is_active`
- `is_accessible`
- `level_change`
- `restrictions_json` — json
- `meta_json` — json

### `vp_location_pois`
Точки интереса / цели маршрута.

Поля:
- `id` — integer
- `location_id` → `vp_locations.id`
- `level_id` → `vp_location_levels.id`
- `zone_id` → `vp_location_zones.id`
- `node_id` → `vp_location_nodes.id`
- `tenant_id` → `vp_tenants.id`
- `title`
- `slug`
- `kind`
- `brand`
- `is_active`
- `is_public`
- `x`
- `y`
- `description`
- `card_id` → `vp_cards.id`
- `scene_id` → `vp_3d_scenes.id`
- `instruction_id` → `instruction_sets.id`
- `icon`
- `sort`
- `keywords` — json
- `opening_hours`
- `phone`
- `url`
- `meta_json` — json

### `vp_location_anchors`
QR-якоря текущего положения пользователя.

Поля:
- `id` — integer
- `location_id` → `vp_locations.id`
- `level_id` → `vp_location_levels.id`
- `zone_id` → `vp_location_zones.id`
- `node_id` → `vp_location_nodes.id`
- `qr_code_id` → `qr_codes.id`
- `tenant_id` → `vp_tenants.id`
- `title`
- `code`
- `kind`
- `x`
- `y`
- `heading_deg`
- `is_active`
- `meta_json` — json

Логика:
- `qr_codes` по-прежнему остаётся маршрутизатором сценария;
- для indoor navigation QR может быть связан не только через `scene_id`, но и через `vp_location_anchors.qr_code_id`;
- объект навигации описывается через `vp_locations`;
- этажи, зоны, узлы, рёбра и POI составляют граф маршрута;
- `location` как тип сценария теперь должен восприниматься не как “синоним scene”, а как отдельный навигационный слой поверх данных.








## 5. 3D слой

### `vp_3d_scenes`
Универсальная 3D сцена.

### Текущее фактическое состояние по snapshot `Data_Model_Directus_snapshot_ADD_LOCATION__17_03_26.json`
Поля:
- `id` — integer
- `title`
- `kind`
- `description`
- `is_active`
- `requires_password`
- `password_hash`
- `password_hint`
- `expires_at`
- `model_file` → `directus_files.id`
- `poster_file` → `directus_files.id`
- `viewer_config` — json
- `created_at`
- `updated_at`
- `tenant_id` → `vp_tenants.id`
- `case_scan_id` → `vp_case_scans.id`

Важно:
- по текущему snapshot `model_file` остаётся Directus file reference;
- текущий schema-doc не должен делать вид, будто WordPress-поля уже существуют, пока не выполнена миграция и не снят новый snapshot.

### Планируемое расширение схемы для WordPress storage
После следующей миграции Directus-схемы и нового snapshot планируется добавить:

- `model_file_wp_id` — integer, `wp_posts.ID` вложения WordPress Media Library с `.glb/.gltf`
- `poster_file_wp_id` — integer, `wp_posts.ID` вложения WordPress Media Library с poster/preview

Планируемые правила:
- `model_file` и `poster_file` сохраняются для обратной совместимости;
- новые сцены по умолчанию используют `model_file_wp_id` / `poster_file_wp_id`;
- старые сцены продолжают работать через `model_file` / `poster_file`, пока не будут мигрированы;
- после фактической миграции `model_file` должен стать необязательным на уровне схемы.

Runtime-принцип:
- браузер не должен зависеть от того, где реально лежит файл;
- внешний контракт остаётся WordPress-first:
  - `/3d?code=...`
  - `/wp-json/vp/v1/lookup?code=...`
  - `/wp-json/vp/v1/3d/file?code=...`
  - `/wp-json/vp/v1/3d/poster?code=...`

### `vp_3d_jobs`
Очередь конвертации 3D.

Поля:
- `id` — integer
- `status`
- `progress`
- `error`
- `meta` — json
- `completed_at`
- `input_file` → `directus_files.id`
- `output_file` → `directus_files.id`
- `preview_file` → `directus_files.id`

Важно:
- в Redis кладём именно `vp_3d_jobs.id`, не UUID файла.

### `vp_case_scans`
Сканы / модели, связанные с кейсом.

Поля:
- `id` — integer
- `tenant_id` → `vp_tenants.id`
- `case_id` → `vp_cases.id`
- `kind`
- `source_format`
- `source_file` → `directus_files.id`
- `converted_file` → `directus_files.id`
- `preview_image` → `directus_files.id`
- `metadata` — json
- `conversion_status`
- `conversion_error`
- `created_at`

## 6. SaaS / tenant layer

### `vp_tenants`
Организация или tenant.

Поля:
- `id` — integer
- `slug`
- `name`
- `type`
- `status`
- `plan`
- `settings` — json
- `created_at`
- `updated_at`

### `vp_memberships`
Связь пользователь ↔ tenant.

Поля:
- `id` — integer
- `tenant_id` → `vp_tenants.id`
- `user_id` → `directus_users.id`
- `role`
- `status`
- `created_at`

### `vp_invites`
Инвайты в tenant.

Поля:
- `id` — integer
- `tenant_id` → `vp_tenants.id`
- `email`
- `role`
- `token`
- `expires_at`
- `accepted_at`
- `created_by` → `directus_users.id`
- `created_at`

### `vp_share_links`
Публичные или временные ссылки.

Поля:
- `id` — integer
- `tenant_id` → `vp_tenants.id`
- `token`
- `resource_type`
- `resource_id`
- `permissions` — json
- `expires_at`
- `created_at`

### `vp_user_profiles`
Единый профиль пользователя.

Базовые поля:
- `id` — integer
- `user_id` → `directus_users.id`
- `user_type`
- `status`
- `phone`
- `locale`
- `timezone`
- `notes`
- `created_at`
- `updated_at`
- `avatar_file` → `directus_files.id`

Поля для dentist:
- `dentist_license`
- `dentist_specialty`
- `dentist_clinic_name`
- `dentist_clinic_address`
- `dentist_clinic_phone`
- `dentist_bio`
- `clinic_role`
- `clinic_position`

Поля для auto / car owner:
- `car_make`
- `car_model`
- `car_year`
- `car_plate`
- `car_vin_last4`
- `car_emergency_contact`
- `car_insurance_phone`

Поля для business:
- `biz_name`
- `biz_type`
- `biz_tax_id`
- `biz_website`
- `biz_support_phone`
- `biz_address`

Поля для location:
- `loc_name`
- `loc_type`
- `loc_address`
- `loc_city`
- `loc_country`
- `loc_contact_person`

Поля для partner:
- `partner_brand`
- `partner_categories`
- `partner_website`
- `partner_about`

## 7. Onboarding / moderation

### `vp_onboarding_requests`
Заявка на регистрацию / onboarding.

Поля:
- `id` — integer
- `email`
- `phone`
- `first_name`
- `last_name`
- `user_type`
- `status`
- `auto_approve_method`
- `invite_code`
- `requested_tenant_name`
- `requested_tenant_slug`
- `evidence_note`
- `source_ip`
- `user_agent`
- `created_user_id` → `directus_users.id`
- `created_profile_id` → `vp_user_profiles.id`
- `reviewed_by` → `directus_users.id`
- `decision_reason`
- `created_at`
- `updated_at`
- `reviewed_at`
- `reviewed_by_email`
- `reviewed_by_wp_id`
- `reviewed_by_wp_login`

### `vp_allowlist_domains`
Allowlist доменов для auto-approve.

Поля:
- `id` — integer
- `domain`
- `is_active`
- `auto_approve_method`
- `notes`
- `sort`
- `created_at`

## 7. Доменный слой стоматологии и кейсов

### `vp_clinics`
- `id`
- `tenant_id`
- `name`
- `city`
- `address`
- `phone`
- `website`
- `timezone`
- `branding`
- `created_at`

### `vp_patients`
- `id`
- `tenant_id`
- `clinic_id`
- `external_id`
- `initials`
- `birth_year`
- `notes`
- `consent`
- `created_at`

### `vp_cases`
- `id`
- `tenant_id`
- `clinic_id`
- `patient_id`
- `case_code`
- `title`
- `status`
- `description`
- `created_at`
- `updated_at`

## 8. Прочие коллекции

### `vp_cards`
Карточки / визитки / информационные карточки.

Поля:
- `id`
- `title`
- `position`
- `avatar_url` → `directus_files.id`
- `description`
- `color`
- `links`
- `tenant_id`

### `directus_sync_id_map`
Служебная коллекция синхронизации ID.

### `Existing_Table`
Техническая или историческая таблица, не являющаяся частью ключевой бизнес-модели проекта.

## 9. Главное, что больше нельзя путать

- `products.id` — integer, не uuid;
- `instruction_sets.id` — integer;
- `qr_codes.id` — integer;
- `vp_3d_scenes.id` — integer;
- `vp_tenants.id` — integer;
- `vp_user_profiles.id` — integer;
- `user_id`, `created_user_id`, `reviewed_by`, `created_by` — это uuid Directus users;
- `source_file`, `image_file`, `model_file`, `poster_file`, `input_file` и подобные file-поля — это uuid Directus files.
