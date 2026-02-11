# Маршрутизация QR: `/scan` → `/instruction` или `/3d`

## Поток входа
1. Пользователь открывает `/scan` (PWA-сканер).
2. Сканер/поиск отправляет запрос в WordPress proxy:  
   `GET /wp-json/vp/v1/lookup?code=...`
3. По `type` (или `kind`) найденной записи выбирается маршрут.

## Правила маршрутизации
- `instruction`, `product`, `service`, `manual` → `/instruction?code=<CODE>`
- `3d`, `3d/navigation`, `navigation`, `nav`, `location` → `/3d?code=<CODE>`
- Если тип не определён/не поддержан → остаёмся на `/scan` и показываем понятное сообщение пользователю.

## Что такое `/3d`
`/3d` — отдельная страница WordPress с шаблоном **VP 3D Navigation** (`page-3d.php`).

Подключения на `/3d`:
- `page-3d.css`
- `page-3d.js`

Все ассеты локальные (самохост), без CDN.

## Ожидаемые поля lookup для 3D/навигации
Фронт ожидает, что `lookup.data[0]` может содержать (частично):

- `type` (или `kind`) — тип QR сценария
- `title`, `code`
- `location_payload` (основной payload)
- `service_payload` (fallback payload)
- `product_id` (опционально: `title`, `brand`, `model`, `sku`)

В payload полезны поля (любое подмножество):
- `scene_url`
- `model_url`
- `route_id`
- `route_url`
- `map_url`
- `mall_id`
- `start_point` / `start`
- `end_point` / `finish_point` / `destination`
- `floor`
- `landmarks`

Ключевое требование: отсутствие части полей **не должно ломать фронт**. Вместо падения показывается fallback-сообщение и кнопка возврата на `/scan`.

## Команды для приёмки
Подставьте свой домен/код:

```bash
curl -I https://xn--b1awacccnl0jqa.xn--p1ai/3d/
curl "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/lookup?code=<REAL>"
```

Ручной чек `/scan`:
- камера стартует/останавливается;
- torch не ломает сканер;
- история и ручной поиск работают;
- QR типа instruction ведёт на `/instruction?code=...`;
- QR типа 3d/navigation ведёт на `/3d?code=...`.
