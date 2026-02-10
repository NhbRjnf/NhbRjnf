# Troubleshooting

## 1) Directus 403 при запросе `qr_codes`

### Симптом
- WordPress endpoint `/wp-json/vp/v1/instruction` отдаёт 500/403
- в Directus логах есть `FORBIDDEN`

### Частая причина
Токен валиден, но роли не хватает прав на отдельные поля/relations.
Особенно часто ломается запрос с `fields=payload` без права читать `payload`.

### Проверка
```bash
# Проверка, что токен вообще рабочий
curl -sS -H "Authorization: Bearer $DIRECTUS_API_TOKEN" \
  "https://directus.xn--b1awacccnl0jqa.xn--p1ai/items/qr_codes?limit=1"

# Срез прав и схемы из runtime snapshot
rg "qr_codes|payload|FORBIDDEN|403" runtime/directus runtime/logs
```

### Фикс
- дать роли право на нужные поля (`payload`, relations, nested fields)
- либо сузить `fields` в proxy до реально разрешённых
- обновить snapshot в `runtime/directus/**`

---

## 2) Directus 503 `/server/health`

### Причина
Обычно проблема прав на volume uploads.

### Фикс
```bash
chown -R 1000:1000 docker/volumes/directus_uploads
chmod -R 775 docker/volumes/directus_uploads
docker restart vse_directus
```

---

## 3) После изменения `.env` ничего не поменялось

### Причина
Изменён только файл окружения, но контейнеры не пересозданы.

### Фикс
```bash
cd docker
docker compose up -d --force-recreate
```

Проверить эффективные переменные:
```bash
docker compose config
```
