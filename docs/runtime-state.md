### `runtime-state.md`

```md
# Runtime state / диагностика

Короткий чеклист перед любыми правками и перед разбором ошибок.

## 1. Git и рабочая ветка

```bash
cd /opt/vseponyatno
git branch --show-current
git status --short

Ожидание:

активная ветка work;

рабочее дерево чистое или понятны локальные изменения.

2. Docker-состояние
cd /opt/vseponyatno/docker
docker compose ps
docker compose logs --tail=100 wordpress
docker compose logs --tail=100 directus
docker compose logs --tail=100 directus_stage
docker compose logs --tail=100 redis
docker compose logs --tail=100 converter

Проверяем:

контейнеры wordpress, directus, directus_stage, redis, converter в статусе Up;

нет повторяющихся 401 / 403 / 5xx;

converter не зациклился на failed job.

3. Быстрый health-check API
curl -sS -i https://directus.xn--b1awacccnl0jqa.xn--p1ai/server/health
curl -sS -i "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/lookup?code=VP-PH-EP2231-START"
curl -sS -i "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/instruction?code=VP-PH-EP2231-START"
curl -sS -i "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=14"

Проверяем:

Directus отвечает 200;

WordPress endpoint-ы не отдают 5xx;

job-status отдаёт нормализованный JSON, а не raw storage path.

4. Runtime snapshots

В репозитории осознанно хранятся:

runtime/logs/**

runtime/directus/**

Минимум для диагностики:

cd /opt/vseponyatno
scripts/snapshot-logs.sh
git status --short runtime/

Если snapshot не свежий, выводы по ошибкам считаются ненадёжными.

5. Проверка Redis-очереди 3D
cd /opt/vseponyatno/docker
docker compose exec -T redis redis-cli LLEN vp:3d:jobs
docker compose exec -T redis redis-cli LRANGE vp:3d:jobs 0 10
docker compose exec -T redis redis-cli LRANGE vp:3d:jobs:processing 0 10

Проверяем:

в pending очереди лежат именно job_id из vp_3d_jobs, а не UUID файлов;

processing очередь не застряла навсегда;

worker подтверждает задачи после обработки.

6. Проверка viewer delivery
6.1 Code-driven /3d

Проверить в браузере:

/3d?code=<REAL_CODE>

Ожидание:

public scene открывается;

protected scene сначала показывает poster / auth flow;

browser не видит raw private URLs.

6.2 Job-driven /3d

Проверить в браузере:

/3d?job_id=<REAL_JOB_ID>

Ожидание:

страница получает данные через job-status;

для completed job приходят viewer_glb_url и/или viewer_preview_url;

viewer использует signed /dl/..., а не raw protected URL.

7. Проверка signed links

Ручной принцип:

raw protected URL может вернуть 403 — это нормально;

signed /dl/<token> должен открываться там, где это задумано runtime bridge;

фронт не должен хранить или переиспользовать raw private URL как рабочий источник для viewer.

8. Проверка graceful fallback для /3d

Обязательный ручной чек:

открыть /3d?job_id=<REAL_JOB_ID> на машине с нормальным WebGL;

открыть ту же страницу на клиенте со слабым GPU / проблемным браузером;

проверить, что при ошибке WebGL:

страница не падает полностью;

остаётся poster / preview;

нет бесконечного спиннера;

UI не уходит в hard crash.

Признаки целевой деградации:

preview остаётся видимым;

сообщение пользователю понятное;

optional viewer controls скрыты или деактивированы;

страница остаётся usable.

9. Проверка актуальности схемы
cd /opt/vseponyatno
ls -lah Data_Model_Directus_snapshot_06_03_26.json

Документацию и генерацию кода сверяем именно с этим snapshot, пока не снят новый.

10. Типовой безопасный push-flow
cd /opt/vseponyatno
git status --short
git diff --stat

Перед коммитом обязательно проверить:

/scan

/instruction?code=<REAL>

/3d?code=<REAL>

/3d?job_id=<REAL_JOB_ID>

DevTools Console без JS-ошибок

DevTools Network для /lookup, /instruction, /3d/auth, /3d/file, /3d/poster, /3d/job-status

11. Что считать подозрительным

Подозрительно, если:

/lookup снова тянет лишние relation-expansions;

/instruction зависит от ACL на instruction_sets.description;

/3d пытается открыть raw private URL;

job-status отдаёт внутренний путь хранилища вместо viewer-safe URL;

WebGL ошибка на клиенте полностью убивает страницу.