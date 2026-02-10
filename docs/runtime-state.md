# Runtime state / диагностика

Короткий чеклист перед любыми правками и перед разбором ошибок.

## 1) Git и рабочая ветка

```bash
git branch --show-current
git status --short
```

Ожидание:
- активная ветка: `work`
- рабочее дерево чистое или понятны локальные изменения

---

## 2) Docker-состояние

```bash
cd docker
docker compose ps
docker compose logs --tail=100 wordpress
docker compose logs --tail=100 directus
```

Проверяем:
- контейнеры `wordpress`, `directus`, `db` в статусе `Up`
- нет повторяющихся 401/403/5xx в логах

---

## 3) Быстрый health-check API

```bash
curl -sS -i https://directus.xn--b1awacccnl0jqa.xn--p1ai/server/health
curl -sS -i "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/instruction?code=VP-PH-EP2231-START"
```

Проверяем:
- Directus отвечает `200`
- WordPress endpoint `/instruction` отвечает без 5xx

---

## 4) Runtime snapshots (обязательно для диагностики)

В репозитории осознанно хранятся:
- `runtime/logs/**`
- `runtime/directus/**`

Минимум для анализа проблем:

```bash
scripts/snapshot-logs.sh
git status --short runtime/
```

Если snapshot не свежий — выводы по ошибкам считаются ненадёжными.

---

## 5) Типовой безопасный push-flow

```bash
scripts/push-work.sh
```

Скрипт выполняет стандартный поток для ветки `work`:
1. checkout `work`
2. `git pull --ff-only`
3. (опционально) snapshot logs + directus
4. `git add -A`
5. `git commit`
6. `git push origin work`