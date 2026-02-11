# ВсёПонятно (vseponyatno)

Платформа для быстрого доступа к инструкциям, карточкам товаров и (в будущем) навигации по торговым центрам через QR-коды.

## Ключевая идея
Одна универсальная страница `/instruction/` отображает:
- инструкции по товарам
- карточки
- навигацию и сервисные сценарии (в будущем)

QR-код — это маршрутизатор сценария, а не просто ссылка.

## Архитектура
Frontend: WordPress (PWA /scan + /instruction)  
Backend: Directus + PostgreSQL  
Связь: WordPress → Directus (server-to-server proxy)

## Домены (punycode)
WordPress: xn--b1awacccnl0jqa.xn--p1ai  
Directus: directus.xn--b1awacccnl0jqa.xn--p1ai

## Документация
См. каталог `docs/`:
- architecture.md
- data-model.md
- api-contract.md
- runtime-state.md
- troubleshooting.md
- decision-log.md
## Новый маршрут 3D/навигации
В проект добавлена отдельная страница `/3d` (template: **VP 3D Navigation**, файл `page-3d.php`) для 3D/навигационных сценариев.

Маршрутизация из `/scan` после `lookup`:
- `instruction/product/service/manual` → `/instruction?code=...`
- `3d/navigation/nav/location` → `/3d?code=...`
- неизвестный тип → остаёмся на `/scan` с сообщением пользователю

Технически `/3d` использует локальные `page-3d.css` и `page-3d.js` (без CDN).

Подробности: `docs/scan-routing.md`.
