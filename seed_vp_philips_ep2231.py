import os
import requests

DIRECTUS_URL = os.environ["DIRECTUS_URL"].rstrip("/")        # например https://directus.... (без /)
DIRECTUS_TOKEN = os.environ["DIRECTUS_TOKEN"]                # Static token
PUBLIC_SITE = os.environ.get("PUBLIC_SITE", "").rstrip("/")  # например https://.... (wordpress)

S = requests.Session()
S.headers.update({
    "Authorization": f"Bearer {DIRECTUS_TOKEN}",
    "Content-Type": "application/json",
    "Accept": "application/json",
})

def post(path: str, payload: dict) -> dict:
    r = S.post(f"{DIRECTUS_URL}{path}", json=payload, timeout=30)
    r.raise_for_status()
    return r.json()["data"]

def seed():
    # 1) instruction_set
    instruction = post("/items/instruction_sets", {
        "title": "Philips EP2231/40 — Быстрый старт (первый запуск)",
        "brand": "Philips",
        "model": "EP2231/40",
        "level": "A",
        "language": "ru-RU",
        "source_url": "https://www.philips.ua/ru/c-p/EP2231_40/series-2200-fully-automatic-espresso-machines/support",
        "notes": "MVP: первый запуск, промывка, LatteGo, первый кофе",
        "is_published": True,
    })
    instruction_id = instruction["id"]

    # 2) steps (короткий мобильный формат)
    steps = [
        (1, "Снимите защитные плёнки и проверьте комплектацию",
         "Убедитесь, что поддон и контейнер для жмыха установлены, а носик кофе свободен."),
        (2, "Заполните резервуар для воды",
         "Налейте питьевую воду в бак до отметки MAX."),
        (3, "Засыпьте кофейные зёрна",
         "Откройте крышку бункера и засыпьте зёрна."),
        (4, "Нажмите кнопку питания",
         "Машина начнёт нагрев и выполнит автоматическую промывку — это нормально."),
        (5, "Дождитесь окончания промывки",
         "Когда индикаторы напитков горят постоянно — кофемашина готова к работе."),
        (6, "Промойте LatteGo перед первым использованием",
         "Разберите LatteGo, промойте детали водой и соберите обратно."),
        (7, "Соберите LatteGo (щёлк до фиксации)",
         "Соедините две части LatteGo до чёткого «click»."),
        (8, "Поставьте чашку под носик кофе",
         "Отрегулируйте высоту носика под вашу чашку."),
        (9, "Выберите Espresso или Coffee и нажмите Start/Stop",
         "Для первой чашки оставьте настройки по умолчанию."),
        (10, "Сварите ещё несколько чашек",
         "Рекомендуется приготовить минимум ~5 чашек для автонастройки вкуса."),
        (11, "Очистите поддон/контейнер для жмыха при необходимости",
         "Если индикатор заполнения поднялся — слейте воду, промойте и установите обратно."),
        (12, "Активируйте фильтр AquaClean (опционально)",
         "Если вы используете AquaClean — запустите активацию по инструкции в меню/индикаторах."),
    ]

    for step_no, title, body in steps:
        post("/items/instruction_steps", {
            "instruction_id": instruction_id,
            "step_no": step_no,
            "title": title,
            "body": body,
            "hotspots": [],   # можно заполнить позже
            "image_file": None
        })

    # 3) product
    product = post("/items/products", {
        "title": "Кофемашина Philips EP2231/40 (Series 2200, LatteGo)",
        "brand": "Philips",
        "model": "EP2231/40",
        "sku": "EP2231/40",
        "description": "Полностью автоматическая эспрессо-кофемашина. MVP-инструкция: первый запуск и первый кофе.",
        "instruction_id": instruction_id,
        # можно оставить пустым — будет перезаписано/использовано qr_codes.instruction_url
        "instruction_url": ""
    })
    product_id = product["id"]

    # 4) qr_code (ВАЖНО: создаём запись — расширение Directus само добавит qr_payload_url + файлы)
    code = "VP-PH-EP2231-START"
    instruction_url = f"{PUBLIC_SITE}/instruction?code={code}" if PUBLIC_SITE else ""

    post("/items/qr_codes", {
        "code": code,
        "type": "instruction",
        "title": "Philips EP2231 — Быстрый старт",
        "is_active": True,
        "product_id": product_id,
        "instruction_id": instruction_id,
        "instruction_url": instruction_url,
        "notes": "QR ведёт на карточку инструкции в WordPress (/instruction)."
    })

    print("OK:", {"instruction_id": instruction_id, "product_id": product_id, "code": code})

if __name__ == "__main__":
    seed()
