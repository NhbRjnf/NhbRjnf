# API контракт WordPress ↔ Directus

Все запросы из браузера идут только в WordPress.
WordPress (mu-plugin proxy) ходит в Directus server-to-server с `DIRECTUS_API_TOKEN`.

## Основной endpoint

### `GET /wp-json/vp/v1/instruction?code=<QR_CODE>`

Назначение:
- получить сценарий по QR-коду
- нормализовать ответ Directus для универсальной страницы `/instruction`

Ожидаемая логика:
1. поиск записи в `qr_codes` по `code`
2. проверка `is_active`
3. загрузка нужных данных (`product` + `payload` по типу)
4. возврат унифицированного JSON

Типы сценариев:
- `product`
- `service`
- `location`
- `navigation`

Базовые ошибки:
- `404` — код не найден
- `410` — код неактивен
- `401/403` — ошибка доступа к Directus (часто ACL на поля)
- `500` — внутренняя ошибка proxy/Directus

---

## PWA `/scan`

Экран `/scan`:
- сканирует QR
- допускает ручной ввод кода
- вызывает `/wp-json/vp/v1/instruction?code=...`
- переводит пользователя на `/instruction`

---

## Критичный момент по ACL Directus

Даже при валидном токене можно получить 403, если:
- роль не имеет доступа к конкретному полю
- в `fields=` запрошено поле без разрешения (типичный пример: `payload`)

Поэтому при 403 сначала проверяются:
1. `runtime/directus/permissions*.json|yaml`
2. `runtime/logs/directus*`
3. фактический набор `fields` в запросе proxy