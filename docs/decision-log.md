
---

## `docs/decision-log.md`

```md
# Decision log

## 2026-02-03 — Остаёмся на Directus
Причина: инфраструктура уже поднята и стабилизирована, а риск миграции выше ожидаемой пользы.

## 2026-02-03 — Одна универсальная страница `/instruction`
Причина: не плодим шаблоны WordPress. Сценарии product / service / location / navigation должны ехать через данные и единый UI.

## 2026-02-17 — Moderation onboarding остаётся REST-only через WordPress
Причина: критичный путь модерации должен работать через WordPress admin UI и server-to-server вызовы в Directus без токенов в браузере.

## 2026-02-18 — Multi-tenant слой фиксируем в Directus
Причина: tenant-изоляция нужна для SaaS-модели проекта. Базовые сущности: `vp_tenants`, `vp_memberships`, `vp_invites`, `vp_user_profiles`.

## 2026-02-26 — Добавляем отдельную страницу `/3d`
Причина: 3D / navigation-сценарии не должны перегружать `/instruction`. Для них нужен изолированный viewer, отдельные ассеты и свой UX.

## 2026-02-26 — Маршрутизация из `/scan` зависит от типа QR
Причина: `instruction/product/service/manual` ведём на `/instruction`, а `3d/navigation/nav/location` ведём на `/3d`, чтобы сценарий определялся данными, а не отдельными страницами.

## 2026-03-06 — Snapshot Directus `Data_Model_Directus_snapshot_06_03_26.json` считаем источником истины по схеме
Причина: часть markdown-описаний отставала от реальной схеме. Для документации и генерации кода используем фактический snapshot от 2026-03-06.

## 2026-03-06 — Первичные ключи бизнес-коллекций фиксируем как integer
Причина: актуальный snapshot показывает integer ID почти во всех пользовательских коллекциях. Документация больше не должна описывать их как uuid, кроме связей на `directus_users` и `directus_files`.

## 2026-03-11 — WordPress engineering audit считаем историческим аудитом, а не live runtime truth
Причина: аудит полезен для cleanup и карты зависимостей, но новые runtime endpoint-ы и flow могут появляться позже него.

## 2026-03-13 — Lookup переводим на safe fields без file relation-expansion
Причина: relation-expansion для `qr_file.*` и `qr_file_png.*` может валить Directus на runtime edge-case. Lookup должен быть стабильным и не зависеть от этих expansion.

## 2026-03-13 — `/instruction` не зависит жёстко от `instruction_sets.description`
Причина: поле может ломать endpoint из-за ACL / fields mismatch. Runtime должен переживать это через fallback на `product.description`.

## 2026-03-13 — Для job-driven viewer вводим WordPress bridge `GET /wp-json/vp/v1/3d/job-status`
Причина: `/3d?job_id=...` не должен читать raw private URLs. Viewer получает только safe runtime URLs через WordPress bridge.

## 2026-03-13 — Signed `/dl/...` links считаем допустимым delivery-слоем для viewer
Причина: raw protected storage URL может отдавать `403` и не является публичным контрактом. Viewer должен работать через подписанные WordPress-safe ссылки.

## 2026-03-13 — Graceful fallback на `/3d` обязателен
Причина: отсутствие WebGL на клиенте — реальный runtime-кейс. Ошибка GPU/браузера не должна превращать страницу в hard crash. Poster / preview остаётся рабочим fallback.

## 2026-03-13 — Schema-docs не переписываем по runtime bridge без нового snapshot
Причина: runtime может эволюционировать быстрее, чем snapshot. Сначала снимаем новый snapshot, потом меняем `data-model.md` и `directus-schema.md`.

## 2026-03-16 — `job-status` route делаем публичным
Причина: `/3d?job_id=...` является публичным viewer entry. Без публичного `job-status` page template и viewer bridge расходятся с реальным контрактом.

## 2026-03-16 — Для текущего `model-viewer` runtime отключаем `gltf-transform optimize`
Причина: optimize-прогон приводил к meshopt-compressed GLB, который ломал текущий viewer ошибкой про `setMeshoptDecoder`. Для быстрого рабочего runtime принимаем:
- `VP_3D_GLTF_TRANSFORM_OPTIMIZE=0`
- `VP_3D_USE_DRACO=0`
- `VP_3D_USE_MESHOPT=0`

## 2026-03-16 — Текущий viewer считаем временно стабилизированным, но стратегически планируем Three.js
Причина: текущий `model-viewer` уже доведён до рабочего состояния для job-driven flow, но долгосрочно проекту нужен более гибкий renderer для indoor navigation, этажей, POI и крупных сцен.