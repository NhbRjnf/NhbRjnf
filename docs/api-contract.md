# API контракт WordPress ↔ Directus

Все запросы из браузера идут ТОЛЬКО в WordPress.
WordPress выступает proxy и обращается к Directus server-to-server.

Токены Directus в браузер не попадают.

---

## lookup

GET /wp-json/vp/v1/lookup?code=...

Назначение:
- найти QR-код по `code`
- определить тип сценария
- вернуть данные для /instruction

Возвращает:
- type (product | service | location | navigation)
- данные продукта (если есть)
- payload для универсальной страницы /instruction

Ошибки:
- 404 — код не найден
- 410 — код неактивен
- 500 — ошибка backend

---

## suggest

GET /wp-json/vp/v1/suggest?term=...

Назначение:
- автоподсказки при вводе кода
- поиск по:
  - qr_codes.code
  - qr_codes.title
  - product.title / model / sku

Используется в PWA /scan.