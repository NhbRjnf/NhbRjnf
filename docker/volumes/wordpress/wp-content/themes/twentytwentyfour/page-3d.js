(() => {
  'use strict';

  const CFG = window.VP_3D || {};
  const LOOKUP_URL = CFG.lookupUrl || '/wp-json/vp/v1/lookup';
  const AUTH_URL   = CFG.authUrl   || '/wp-json/vp/v1/3d/auth';
  const FILE_URL   = CFG.fileUrl   || '/wp-json/vp/v1/3d/file';

  const $ = (id) => document.getElementById(id);

  // IDs из твоего page-3d.php
  const elStatus = $('vp3d-status');

  const elMetaBlock = $('vp3d-meta');
  const elSceneTitle = $('vp3d-scene-title');
  const elKindBadge = $('vp3d-kind');

  const elCode = $('vp3d-code');
  const elSceneId = $('vp3d-scene-id');
  const elType = $('vp3d-type');
  const elAccess = $('vp3d-access');
  const elExpiry = $('vp3d-expiry');

  const elAuthWrap = $('vp3d-auth');
  const elAuthForm = $('vp3d-auth-form');
  const elPassword = $('vp3d-password');
  const elHint = $('vp3d-hint');

  const elViewer = $('vp3d-viewer');
  const elPlaceholder = $('vp3d-viewer-placeholder');

  const params = new URLSearchParams(window.location.search);
  const code = String(params.get('code') || '').trim().toUpperCase();

  const MODEL_VIEWER_BOOT_TIMEOUT_MS = 15000;
  const MODEL_LOAD_TIMEOUT_MS = 20000;

  let currentScene = null;

  const friendlyErrors = {
    code_required: 'В URL нет параметра code. Вернитесь на сканер и отсканируйте QR заново.',
    lookup_empty: 'По этому QR нет данных.',
    scene_not_linked: 'Для этого QR не привязана 3D-сцена.',
    scene_disabled: 'Сцена временно отключена.',
    scene_expired: 'Срок действия сцены истёк.',
    scene_model_missing: 'Для этой сцены не задан model_url.',
    invalid_password: 'Неверный пароль. Проверьте и попробуйте ещё раз.',
    token_invalid: 'Токен недействителен или устарел. Введите пароль снова.',
  };

  function setStatus(text) {
    if (elStatus) elStatus.textContent = text;
  }

  function showError(text) {
    setStatus('Ошибка');
    if (elPlaceholder) {
      elPlaceholder.hidden = false;
      elPlaceholder.textContent = text;
    }
    if (elViewer) elViewer.style.display = 'none';
  }

  function resolveError(err) {
    const key = err?.payload?.error || err?.message || 'unknown';
    return friendlyErrors[key] || 'Не удалось загрузить 3D-сцену.';
  }

  async function apiJson(url, method = 'GET', body) {
    const res = await fetch(url, {
      method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
      cache: 'no-store',
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const e = new Error(json.error || 'request_failed');
      e.status = res.status;
      e.payload = json;
      throw e;
    }
    return json;
  }

  async function waitForModelViewer() {
    // Если model-viewer не загрузился (SyntaxError export) — custom element не появится
    if (customElements.get('model-viewer')) return;

    setStatus('Инициализация 3D viewer…');

    await Promise.race([
      customElements.whenDefined('model-viewer'),
      new Promise((_, reject) =>
        setTimeout(() => reject(new Error('viewer_not_registered')), MODEL_VIEWER_BOOT_TIMEOUT_MS)
      ),
    ]);
  }

  async function attachViewer(src, posterUrl) {
    if (!elViewer) throw new Error('viewer_not_registered');
    if (!src) throw new Error('scene_model_missing');

    if (elPlaceholder) {
      elPlaceholder.hidden = false;
      elPlaceholder.textContent = 'Инициализация viewer…';
    }

    await waitForModelViewer();

    if (posterUrl) elViewer.setAttribute('poster', posterUrl);

    setStatus('Загружаем 3D модель…');
    if (elPlaceholder) {
      elPlaceholder.hidden = false;
      elPlaceholder.textContent = 'Загружаем 3D модель…';
    }

    elViewer.style.display = 'block';

    let done = false;
    const cleanup = (t) => {
      done = true;
      clearTimeout(t);
      elViewer.removeEventListener('load', onLoad);
      elViewer.removeEventListener('error', onError);
    };

    const onLoad = () => {
      cleanup(timeout);
      if (elPlaceholder) elPlaceholder.hidden = true;
      setStatus('Сцена загружена.');
    };

    const onError = () => {
      cleanup(timeout);
      showError('Не удалось загрузить 3D модель (ошибка viewer).');
    };

    const timeout = setTimeout(() => {
      if (done) return;
      cleanup(timeout);
      showError('Загрузка 3D модели слишком долго. Проверьте viewer/vendor и ссылку на модель.');
    }, MODEL_LOAD_TIMEOUT_MS);

    elViewer.addEventListener('load', onLoad, { once: true });
    elViewer.addEventListener('error', onError, { once: true });

    // перезапуск загрузки
    elViewer.removeAttribute('src');
    elViewer.setAttribute('src', src);
  }

  function fillMeta(item, scene) {
    if (elMetaBlock) elMetaBlock.hidden = false;

    if (elSceneTitle) elSceneTitle.textContent = scene?.title || item?.title || `QR ${code}`;

    const kind = scene?.kind || item?.type || '3d';
    if (elKindBadge) {
      elKindBadge.hidden = false;
      elKindBadge.textContent = kind;
    }

    if (elCode) elCode.textContent = code || '—';
    if (elSceneId) elSceneId.textContent = scene?.id ?? '—';
    if (elType) elType.textContent = item?.type || kind || '—';
    if (elAccess) elAccess.textContent = scene?.requires_password ? 'По паролю' : 'Открытый';
    if (elExpiry) elExpiry.textContent = scene?.expires_at || 'без ограничения';
  }

  function isExpired(scene) {
    if (!scene?.expires_at) return false;
    const t = Date.parse(scene.expires_at);
    return !Number.isNaN(t) && t <= Date.now();
  }

  async function loadPublic() {
    const modelUrl = String(currentScene?.model_url || '').trim();
    await attachViewer(modelUrl, currentScene?.poster_url || '');
    if (elAuthWrap) elAuthWrap.hidden = true;
  }

  async function loadProtected(password) {
    setStatus('Проверяем пароль…');
    const auth = await apiJson(AUTH_URL, 'POST', { code, password });

    const token = String(auth.token || '').trim();
    if (!token) throw new Error('token_invalid');

    const url =
      `${FILE_URL}?code=${encodeURIComponent(code)}&token=${encodeURIComponent(token)}`;

    await attachViewer(url, currentScene?.poster_url || '');
    if (elAuthWrap) elAuthWrap.hidden = true;
    setStatus('Пароль принят.');
  }

  async function init() {
    if (!code) {
      showError(friendlyErrors.code_required);
      return;
    }

    try {
      setStatus('Загружаем метаданные сцены…');

      const u = new URL(LOOKUP_URL, window.location.origin);
      u.searchParams.set('code', code);

      const lookup = await apiJson(u.toString());
      const item = Array.isArray(lookup.data) && lookup.data.length ? lookup.data[0] : null;
      if (!item) throw new Error('lookup_empty');

      currentScene = item.scene || null;
      if (!currentScene) throw new Error('scene_not_linked');
      if (!currentScene.is_active) throw new Error('scene_disabled');
      if (isExpired(currentScene)) throw new Error('scene_expired');

      fillMeta(item, currentScene);

      if (currentScene.requires_password) {
        if (elAuthWrap) elAuthWrap.hidden = false;
        if (elHint) {
          const hint = currentScene.password_hint ? `Подсказка: ${currentScene.password_hint}` : 'Введите пароль.';
          elHint.hidden = false;
          elHint.textContent = hint;
        }
        setStatus('Сцена защищена паролем.');
        if (elPlaceholder) {
          elPlaceholder.hidden = false;
          elPlaceholder.textContent = 'Введите пароль, чтобы открыть сцену.';
        }
        return;
      }

      await loadPublic();
    } catch (err) {
      console.error(err);
      if (err?.message === 'viewer_not_registered') {
        showError('3D viewer не загрузился. Сейчас model-viewer подключён неверно (ESM должен быть type="module").');
        return;
      }
      showError(resolveError(err));
    }
  }

  if (elAuthForm) {
    elAuthForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const password = String(elPassword?.value || '').trim();
      if (!password) {
        showError('Введите пароль.');
        return;
      }
      try {
        await loadProtected(password);
      } catch (err) {
        console.error(err);
        if (err?.message === 'viewer_not_registered') {
          showError('3D viewer не загрузился. Проверьте подключение model-viewer как type="module".');
          return;
        }
        showError(resolveError(err));
      }
    });
  }

  init();
})();
