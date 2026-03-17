
### `CHANGELOG.MD`

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
- Добавлены runtime-проверки для `job-status` и `/3d?job_id=...`.


- Зафиксирован новый schema snapshot: `Data_Model_Directus_snapshot_ADD_LOCATION__17_03_26.json`.
- Зафиксирован indoor-navigation schema layer:
  - `vp_locations`
  - `vp_location_levels`
  - `vp_location_zones`
  - `vp_location_nodes`
  - `vp_location_edges`
  - `vp_location_pois`
  - `vp_location_anchors`

### Changed
- `CODEX_RULES.md` переведён на новый snapshot `17_03_26`.
- `architecture.md` дополнен indoor-navigation data block.
- `data-model.md` дополнен отдельным indoor-navigation разделом.
- `directus-schema.md` синхронизирован с location-коллекциями из нового snapshot.
- `decision-log.md` дополнен решением о том, что indoor-navigation теперь зафиксирован как реальный schema-layer.

### Notes
- `api-contract.md` не должен обещать жёсткий shape location-runtime до отдельной фиксации публичного lookup-контракта.
- routing `location -> /3d` остаётся актуальным, но schema за ним теперь шире, чем просто `scene_id`.

### Changed
- `api-contract.md` обновлён под реальный runtime:
  - добавлены `POST /wp-json/vp/v1/3d/job`;
  - добавлен `GET /wp-json/vp/v1/3d/job-status`;
  - зафиксировано, что viewer получает только WordPress-safe ссылки.
- `architecture.md` обновлён: теперь отдельно описаны scene mode и job mode для `/3d`.
- `scan-routing.md` обновлён: code-driven routing отделён от job-driven viewer entry.
- `runtime-state.md` обновлён: добавлены проверки `job-status`, signed delivery и graceful fallback.
- `troubleshooting.md` обновлён:
  - добавлен кейс lookup relation-expansion crash;
  - добавлен кейс instruction ACL/fallback;
  - добавлен кейс WebGL context failure на клиенте;
  - добавлен кейс raw private URL vs signed `/dl/...`.
- `VP-3D.md` переписан под текущее инженерное состояние scene/job flow.
- `decision-log.md` дополнен решениями по safe lookup, instruction fallback, job-status bridge и graceful fallback.
- Upload allowlist для `POST /wp-json/vp/v1/3d/job` сужен до реально подтверждённых форматов `.stl` и `.obj`.
- Документация выровнена под фактическую поддержку форматов: `.glb` и `.gltf` больше не описываются как рабочие upload input для текущего runtime.


### Fixed
- В документации больше не подразумевается, что `/3d` существует только в режиме `?code=...`.
- В документации больше не подразумевается, что raw protected URL допустим как viewer source.
- Зафиксирован факт, что `/lookup` и `/instruction` должны работать на safe fields и fallback-логике, а не ломаться на хрупких query-полях.
- Убран разрыв между UI accept и реальной поддержкой converter pipeline.
- Убран ложный сигнал, будто `.glb` и `.gltf` уже поддержаны как входные upload-форматы.


### Notes
- `data-model.md` и `directus-schema.md` не переписывались под runtime bridge без нового snapshot.
- `wp-engineering-audit-2026-03-11.md` оставлен как исторический аудит, а не как live runtime source of truth.