## `docs/troubleshooting.md`

```md
# Troubleshooting (всёпонятно)

## 1. Docker compose: `yaml: invalid trailing UTF-8 octet`

Причина: `docker-compose.yml` сохранён с мусорными байтами, BOM или в неверной кодировке.

Решение:

```bash
cd /opt/vseponyatno/docker

cp -a docker-compose.yml docker-compose.yml.bak.$(date +%F_%H%M%S)

python3 - <<'PY'
p='docker-compose.yml'
b=open(p,'rb').read()
if b.startswith(b'\xef\xbb\xbf'):
    b=b[3:]
open(p,'wb').write(b)
print("OK: removed BOM if present, size=", len(b))
PY

docker compose config >/dev/null
echo "compose config OK"
2. VP-3D.md открыт кракозябрами

Причина: файл был сохранён в windows-1251, а не в UTF-8.

Решение:

пересохранить файл в UTF-8;

проверить file -bi VP-3D.md;

не коммитить cp1251 обратно в репозиторий.

3. 403 при обращении к Directus из WordPress proxy

Симптом:

WordPress endpoint возвращает 401 или 403;

Directus token формально валиден, но ответ запрещён.

Проверяем:

есть ли доступ у service policy к нужной коллекции;

есть ли доступ к каждому полю из fields=...;

не просит ли proxy скрытое поле или relation без ACL;

свежие ли runtime snapshots.

4. /lookup падает из-за relation expansion файлов QR

Симптом:

/wp-json/vp/v1/lookup?code=... падает;

Directus может отвечать ошибкой вида:
parentItem[parentRelationField].push is not a function

Причина:

proxy пытается делать relation-expansion qr_file.* или qr_file_png.*.

Решение:

не тянуть эти relation-expansions в lookup;

использовать safe fields и нормализованный ответ.

5. /instruction падает из-за ACL на instruction_sets.description

Симптом:

/wp-json/vp/v1/instruction?code=... начинает отдавать 401/403/500.

Причина:

endpoint зависит от поля, к которому нет стабильного runtime-доступа.

Решение:

убрать instruction_sets.description из safe query;

использовать fallback из product.description.

6. 3D converter получает не тот ID из Redis

Симптом:

worker не может найти job;

job остаётся в pending;

логи указывают на неверный идентификатор.

Причина:
в Redis должен попадать vp_3d_jobs.id как integer, а не UUID входного файла.

7. /3d или model-viewer падают с Unexpected token 'export'

Причина:
ESM-скрипт был загружен как обычный классический script.

Правильно:

<script type="module" src=".../assets/vendor/model-viewer.min.js"></script>
8. /3d на клиенте падает с ошибкой WebGL context

Симптом:

THREE.WebGLRenderer: A WebGL context could not be created

Error creating WebGL context

Cannot read properties of undefined (reading 'xr')

Что это значит:

это обычно клиентская проблема WebGL / GPU / драйвера / браузера;

это не обязательно серверная ошибка.

Что делать:

не ломать backend из-за этого симптома;

обеспечить graceful fallback:

оставить preview / poster;

не допускать hard crash страницы.

9. job-status отдаёт 401 / 403

Симптом:

/3d?job_id=... не получает viewer данные;

GET /wp-json/vp/v1/3d/job-status?job_id=... возвращает rest_forbidden.

Причина:

route ошибочно закрыт через permission callback.

Решение:

job-status должен быть публичным;

безопасность viewer обеспечивается signed /dl/... links, а не login-требованием к самому route.

10. job-status отдаёт или фронт использует raw private URL

Симптом:

/3d?job_id=... не показывает результат;

в Network видно raw private URL;

при открытии этого URL получается 403.

Причина:

фронт обходит WordPress bridge;

либо job-status возвращает не viewer-safe signed URL.

Правильное поведение:

viewer использует signed /dl/<token> ссылки;

raw protected URLs не являются публичным контрактом.

11. THREE.GLTFLoader: setMeshoptDecoder must be called before loading compressed files

Симптом:

current model-viewer runtime не открывает GLB;

в консоли появляется ошибка про setMeshoptDecoder.

Причина:

output GLB после gltf-transform optimize содержит meshopt compression;

текущая конфигурация viewer не инициализирует meshopt decoder.

Быстрый runtime fix:

в .env выставить:

VP_3D_GLTF_TRANSFORM_OPTIMIZE=0

VP_3D_USE_DRACO=0

VP_3D_USE_MESHOPT=0

пересоздать только converter

создать новый test job

Проверка:

в логах converter не должно быть:

Running glTF optimize

meshopt

12. Предупреждение про Draco в converter

Симптом:

в логах Blender есть строка:
Draco mesh compression is not available...

Текущее значение:

если VP_3D_USE_DRACO=0, это warning не блокирует pipeline;

job может успешно завершиться.

13. EGL / surfaceless rendering warnings при preview render

Симптом:

в логах preview render видны EGL warnings и сообщение про fallback to surfaceless EGL rendering.

Текущее значение:

если preview.png успешно создаётся и job завершается как DONE, эти warnings допустимы;

они сами по себе не считаются поломкой.

## 14. Upload endpoint принимает формат, который converter не поддерживает

Симптом:
- upload проходит;
- job создаётся;
- converter падает на import unsupported format;
- пользователь получает failed job вместо раннего понятного отказа.

Текущее решение:
- upload allowlist ограничен только реально подтверждёнными форматами:
  - `.stl`
  - `.obj`

На текущем runtime не считаются поддержанными upload input:
- `.glb`
- `.gltf`

Если поддержка этих форматов нужна:
- это отдельная задача на converter pipeline,
- а не просто изменение accept в форме.

## 15. file_type_not_allowed при upload

Симптом:
- API отвечает:
  - `error: file_type_not_allowed`

Текущее значение:
- это штатный отказ, а не серверная авария;
- endpoint режет неподдержанный формат до запуска тяжёлого pipeline.

Для текущего runtime это правильное поведение для:
- `.glb`
- `.gltf`
- других неразрешённых расширений

## 16. rate_limited при upload

Симптом:
- API отвечает:
  - `error: rate_limited`

Текущее значение:
- это штатная защита endpoint;
- повторять попытку нужно после `retry_after`.

Важно:
- фронт может показывать локализованный русский текст;
- серверный API message может оставаться ASCII/English, чтобы не зависеть от проблем кодировки среды.


17. Protected 3D сцена не открывается

Проверяем:

vp_3d_scenes.is_active = true;

expires_at не истёк;

пароль сверяется с password_hash;

model_file существует;

WordPress выдаёт short-lived token;

/wp-json/vp/v1/3d/file реально стримит файл.

18. Документация расходится со схемой

Правило:
при любом споре источником истины считается Data_Model_Directus_snapshot_06_03_26.json.

Важно:

runtime bridge поля и WordPress-safe URLs не нужно автоматически переносить в schema-docs;

сначала снимаем новый snapshot, потом меняем data-model.md и directus-schema.md