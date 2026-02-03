# Архитектура проекта «ВсёПонятно»

---

## Общая схема

Frontend:
- WordPress
- PWA /scan
- универсальная страница /instruction

Backend:
- Directus
- PostgreSQL

Связь:
- browser → WordPress
- WordPress → Directus (server-to-server)

---

## Поток данных

1. Пользователь открывает /scan
2. Сканирует QR или вводит код
3. JS вызывает WordPress API:
   - /wp-json/vp/v1/lookup
4. WordPress обращается к Directus
5. Возвращает нормализованный JSON
6. /instruction отображает сценарий

---

## Почему одна /instruction

- не плодим страницы
- вся логика в данных
- новые сценарии без изменений структуры сайта