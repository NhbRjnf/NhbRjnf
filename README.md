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