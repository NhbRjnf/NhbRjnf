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

## Lookup для 3D сцен
Для 3D типов endpoint `lookup` возвращает `lookup.data[0].scene` (если в `qr_codes.scene_id` есть связь):

```json
{
  "scene": {
    "id": "...",
    "title": "...",
    "kind": "navigation|dental|generic",
    "requires_password": true,
    "password_hint": "...",
    "expires_at": "...",
    "is_active": true,
    "poster_url": "https://.../wp-json/vp/v1/3d/poster?code=...",
    "model_url": "https://.../wp-json/vp/v1/3d/file?code=..."
  }
}
```

Примечания:
- `model_url` отдается только для публичной сцены (`requires_password=false`).
- Для защищённой сцены загрузка модели идет только после `POST /wp-json/vp/v1/3d/auth`.
- Прямые ссылки и токен Directus в браузер не передаются.

## API защищённых 3D сцен (через WP proxy)
- `POST /wp-json/vp/v1/3d/auth`
  - body: `{ "code": "QR_CODE", "password": "..." }`
  - проверяет `is_active`, `expires_at` и пароль (`password_verify` по hash из Directus)
  - ответ при успехе: `{ "ok": true, "token": "...", "expires_in": 600 }`
- `GET /wp-json/vp/v1/3d/file?code=...&token=...`
  - стримит `model_file` server-to-server из Directus
  - для `requires_password=true` требует short-lived token
- `GET /wp-json/vp/v1/3d/poster?code=...`
  - стримит `poster_file` как placeholder для `/3d`

## Что такое `/3d`
`/3d` — отдельная страница WordPress с шаблоном **VP 3D Navigation** (`page-3d.php`).

Подключения на `/3d`:
- `page-3d.css`
- `assets/vendor/model-viewer.min.js` (самохост)
- `page-3d.js`

## Команды для приёмки
Подставьте свой домен/код:

```bash
curl -I https://xn--b1awacccnl0jqa.xn--p1ai/3d/
curl "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/lookup?code=<REAL>"
curl -X POST "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/auth" -H 'content-type: application/json' -d '{"code":"<REAL>","password":"<PASS>"}'
```

Ручной чек `/scan`:
- камера стартует/останавливается;
- torch не ломает сканер;
- история и ручной поиск работают;
- QR типа instruction ведёт на `/instruction?code=...`;
- QR типа 3d/navigation ведёт на `/3d?code=...`.

## 3D Engine Architecture
- `assets/vendor/model-viewer.min.js` — ESM. Его нельзя грузить обычным WordPress enqueue-тегом `type="text/javascript"`, иначе браузер падает на `export` с ошибкой `Unexpected token 'export'`.
- Мы не используем глобальный `script_loader_tag`, чтобы не вмешиваться в вывод скриптов на остальных страницах и не создавать риск 500 из-за фильтра на весь сайт.
- Поэтому подключение `model-viewer` выполнено локально в `page-3d.php` через `<script type="module" src=".../assets/vendor/model-viewer.min.js"></script>`. Это изолирует 3D-движок только на `/3d`.
- Логика загрузки сцены в `page-3d.js`:
  - ждём `customElements.whenDefined('model-viewer')` с таймаутом 15s;
  - если viewer не зарегистрирован — показываем ошибку `3D viewer не загрузился. Проверьте vendor ESM.`;
  - public сцена использует `scene.model_url`;
  - protected сцена требует `POST /wp-json/vp/v1/3d/auth` и затем `GET /wp-json/vp/v1/3d/file?code&token`.
