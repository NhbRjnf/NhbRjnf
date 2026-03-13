### `troubleshooting.md`

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

Симптом:

в терминале и редакторе видны нечитаемые символы;

git и markdown preview показывают мусор.

Решение:

пересохранить файл в UTF-8;

проверить file -bi VP-3D.md;

не коммитить cp1251 обратно в репозиторий.

Безопасная перекодировка:

cd /opt/vseponyatno
iconv -f cp1251 -t utf-8 VP-3D.md > VP-3D.md.utf8
mv VP-3D.md.utf8 VP-3D.md
file -bi VP-3D.md
3. 403 при обращении к Directus из WordPress proxy

Симптом:

WordPress endpoint возвращает 401 или 403;

Directus token формально валиден, но ответ запрещён.

Проверяем:

есть ли доступ у service policy к нужной коллекции;

есть ли доступ к каждому полю из fields=...;

не просит ли proxy скрытое поле или relation без ACL;

свежие ли runtime snapshots.

Типовые проблемные коллекции:

qr_codes

instruction_sets

instruction_steps

vp_3d_scenes

vp_3d_jobs

vp_onboarding_requests

vp_user_profiles

4. /lookup падает из-за relation expansion файлов QR

Симптом:

/wp-json/vp/v1/lookup?code=... падает;

Directus может отвечать ошибкой вида:
parentItem[parentRelationField].push is not a function

Причина:

proxy пытается делать relation-expansion qr_file.* или qr_file_png.*.

Решение:

не тянуть эти relation-expansions в lookup;

использовать safe fields и нормализованный ответ;

если нужен URL ассета, добирать его отдельным безопасным способом, а не через опасный relation-expansion в основном lookup.

5. /instruction падает из-за ACL на instruction_sets.description

Симптом:

/wp-json/vp/v1/instruction?code=... начинает отдавать 401/403/500;

ошибка возникает после добавления instruction_sets.description в query fields.

Причина:

endpoint зависит от поля, к которому нет стабильного runtime-доступа.

Решение:

убрать instruction_sets.description из safe query;

использовать fallback из product.description;

не делать description обязательным условием успешного ответа instruction endpoint.

6. 3D converter получает не тот ID из Redis

Симптом:

worker не может найти job;

job остаётся в pending;

логи указывают на неверный идентификатор.

Причина:
в Redis должен попадать vp_3d_jobs.id как integer, а не UUID входного файла.

Проверка:

cd /opt/vseponyatno/docker
docker compose exec -T redis redis-cli LRANGE vp:3d:jobs 0 10

Исправление:

в очередь кладём только integer job_id;

проверяем соответствие записи в vp_3d_jobs.

7. /3d или model-viewer падают с Unexpected token 'export'

Причина:
ESM-скрипт был загружен как обычный классический script.

Правильно:

<script type="module" src=".../assets/vendor/model-viewer.min.js"></script>

Неправильно:

обычный wp_enqueue_script() без type="module";

глобальный фильтр, который вмешивается во все script tags сайта.

8. /3d на клиенте падает с ошибкой WebGL context

Симптом:

THREE.WebGLRenderer: A WebGL context could not be created

Error creating WebGL context

Cannot read properties of undefined (reading 'xr')

Что это значит:

это обычно клиентская проблема WebGL / GPU / драйвера / браузера;

это не обязательно серверная ошибка;

job может быть completed, а файл и signed URL — корректными.

Что делать:

не ломать backend из-за этого симптома;

не считать raw protected URLs причиной автоматически;

обеспечить graceful fallback:

оставить preview / poster;

не допускать hard crash страницы;

скрыть или деактивировать viewer-only controls;

показать понятное сообщение пользователю.

Проверка:

открыть ту же страницу на другой машине / браузере;

проверить наличие preview через viewer_preview_url;

сравнить поведение на клиенте с нормальным WebGL.

9. job-status отдаёт или фронт использует raw private URL

Симптом:

/3d?job_id=... не показывает результат;

в Network видно raw private URL;

при открытии этого URL получается 403.

Причина:

фронт обходит WordPress bridge;

либо job-status возвращает не viewer-safe signed URL.

Правильное поведение:

viewer использует signed /dl/<token> ссылки;

raw protected URLs не являются публичным контрактом;

403 на raw private URL сам по себе не баг.

10. Protected 3D сцена не открывается

Проверяем:

vp_3d_scenes.is_active = true;

expires_at не истёк;

пароль сверяется с password_hash;

model_file существует;

WordPress выдаёт short-lived token;

/wp-json/vp/v1/3d/file реально стримит файл.

11. Onboarding approve / reject не записывается

Проверяем:

VP_SERVICE_USER_TOKEN;

DIRECTUS_PUBLIC_URL;

не включён ли VP_ONBOARDING_DRY_RUN=1;

есть ли права на:

vp_onboarding_requests

vp_user_profiles

directus_users

грузятся ли vp-onboarding-admin.js и CSS с корректным ?ver=.

12. Документация расходится со схемой

Симптом:

markdown описывает поля, которых нет;

типы ID указаны как uuid, хотя в snapshot они integer;

кодогенерация едет не туда.

Правило:
при любом споре источником истины считается Data_Model_Directus_snapshot_06_03_26.json.

Важно:

runtime bridge поля и WordPress-safe URLs не нужно автоматически переносить в schema-docs;

сначала снимаем новый snapshot, потом меняем data-model.md и directus-schema.md.