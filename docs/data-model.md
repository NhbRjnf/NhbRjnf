# Модель данных Directus

Directus используется как backend и админка.

---

## products
Карточка товара (канонический объект).

- id
- title
- brand
- model
- sku
- description
- instruction_url
- cover

products — НЕ знает о QR-кодах.

---

## qr_codes
Маршрутизатор сценариев.

- id
- code (unique)
- title
- type (product | service | location | navigation)
- product_id (nullable)
- instruction_url (override)
- is_active
- notes

Payload (JSON, nullable):
- service_payload
- location_payload
- navigation_payload

Файлы:
- qr_file (svg/png)

---

## Принцип
- products = «что это»
- qr_codes = «куда вести и как показать»