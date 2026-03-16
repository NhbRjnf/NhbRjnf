## `docs/runtime-state.md`

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

рабочее дерево может быть грязным, поэтому staging нужно контролировать вручную;

перед docs-коммитом нельзя использовать git add ..

2. Docker-состояние
cd /opt/vseponyatno/docker
docker compose ps
docker compose logs --tail=100 wordpress
docker compose logs --tail=100 directus
docker compose logs --tail=100 redis
docker compose logs --tail=100 converter

Проверяем:

контейнеры wordpress, directus, redis, converter в статусе Up;

нет повторяющихся 401 / 403 / 5xx;

converter не зациклился на failed job.

3. Быстрый health-check API
curl -sS -i https://directus.xn--b1awacccnl0jqa.xn--p1ai/server/health
curl -sS -i "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/lookup?code=VP-PH-EP2231-START"
curl -sS -i "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/instruction?code=VP-PH-EP2231-START"
curl -sS "https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=15" | jq .

Проверяем:

Directus отвечает 200;

WordPress endpoint-ы не отдают 5xx;

job-status публично отдаёт нормализованный JSON, а не 401.

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

для completed job приходят viewer_glb_url и viewer_preview_url;

viewer использует signed /dl/..., а не raw protected URL.

7. Проверка signed links

Ручной принцип:

raw protected URL может вернуть 403 — это нормально;

signed /dl/<token> должен открываться там, где это задумано runtime bridge;

фронт не должен хранить или переиспользовать raw private URL как рабочий источник для viewer.

Проверка:

GLB_URL="$(curl -sS 'https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=15' | jq -r '.job.viewer_glb_url // empty')"
PNG_URL="$(curl -sS 'https://xn--b1awacccnl0jqa.xn--p1ai/wp-json/vp/v1/3d/job-status?job_id=15' | jq -r '.job.viewer_preview_url // empty')"

curl -I "$GLB_URL"
curl -I "$PNG_URL"

Ожидание:

200 OK для signed links.

8. Проверка текущего runtime-конфига converter

Для текущего рабочего model-viewer runtime на март 2026 должно быть:

cd /opt/vseponyatno/docker
docker compose config | grep -n "VP_3D_GLTF_TRANSFORM_OPTIMIZE\|VP_3D_USE_DRACO\|VP_3D_USE_MESHOPT"

Ожидание:

VP_3D_GLTF_TRANSFORM_OPTIMIZE: "0"

VP_3D_USE_DRACO: "0"

VP_3D_USE_MESHOPT: "0"

Причина:

gltf-transform optimize в текущем pipeline приводил к meshopt-compressed output;

этот output ломал текущий model-viewer runtime.

9. Проверка converter после нового job

После нового test upload:

cd /opt/vseponyatno/docker
docker compose logs -f converter

Что должно быть:

загрузка source файла;

Blender export GLB;

preview render;

upload результатов;

DONE job=<ID>

Что не должно быть:

Running glTF optimize

gltf-transform optimize

meshopt

Допустимо:

предупреждение про отсутствие Draco, если VP_3D_USE_DRACO=0;

EGL fallback warning, если preview успешно рендерится и job завершается.

10. Проверка graceful fallback для /3d

Обязательный ручной чек:

открыть /3d?job_id=<REAL_JOB_ID> на машине с нормальным WebGL;

открыть ту же страницу на клиенте со слабым GPU / проблемным браузером;

проверить, что при ошибке WebGL:

страница не падает полностью;

остаётся poster / preview;

нет бесконечного спиннера;

UI не уходит в hard crash.

11. Типовой безопасный push-flow
cd /opt/vseponyatno
git restore --staged .
git status --short

Перед коммитом docs:

staging должен содержать только docs/...;

нельзя коммитить uploads, runtime, backup-файлы и WordPress core.

12. Что считать подозрительным

Подозрительно, если:

/lookup снова тянет лишние relation-expansions;

/instruction зависит от ACL на instruction_sets.description;

/3d пытается открыть raw private URL;

job-status отдаёт 401;

converter снова запускает optimize / meshopt;

browser показывает setMeshoptDecoder must be called before loading compressed files;

/3d превращается в пустой экран без fallback