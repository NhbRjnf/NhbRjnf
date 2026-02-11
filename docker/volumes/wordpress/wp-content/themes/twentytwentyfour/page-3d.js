(() => {
  'use strict';

  const CFG = window.VP_3D || {};
  const LOOKUP_URL = CFG.lookupUrl || '/wp-json/vp/v1/lookup';
  const AUTH_URL = CFG.authUrl || '/wp-json/vp/v1/3d/auth';
  const FILE_URL = CFG.fileUrl || '/wp-json/vp/v1/3d/file';

  const $ = (id) => document.getElementById(id);
  const elStatus = $('vp3d-status');
  const elError = $('vp3d-error');
  const elResultCard = $('vp3d-result-card');
  const elAuthCard = $('vp3d-auth-card');
  const elViewCard = $('vp3d-view-card');
  const elTitle = $('vp3d-title');
  const elSub = $('vp3d-sub');
  const elType = $('vp3d-type');
  const elMeta = $('vp3d-meta');
  const elHint = $('vp3d-auth-hint');
  const elAuthForm = $('vp3d-auth-form');
  const elPassword = $('vp3d-password');
  const elViewer = $('vp3d-viewer');
  const elPlaceholder = $('vp3d-view-placeholder');

  const params = new URLSearchParams(window.location.search);
  const code = String(params.get('code') || '').trim().toUpperCase();
  let currentScene = null;

  const friendlyErrors = {
    scene_not_found: 'Код не найден.',
    scene_not_linked: 'Для этого QR не привязана 3D-сцена.',
    scene_disabled: 'Сцена временно отключена.',
    scene_expired: 'Срок действия сцены истёк.',
    invalid_password: 'Неверный пароль. Проверьте и попробуйте ещё раз.',
    token_invalid: 'Ссылка доступа устарела. Получите новый доступ через пароль.',
    token_required: 'Требуется токен доступа.',
  };

  function setStatus(text) {
    if (elStatus) elStatus.textContent = text;
  }

  function showError(text) {
    setStatus('Ошибка');
    if (elError) {
      elError.hidden = false;
      elError.textContent = text;
    }
  }

  function clearError() {
    if (elError) {
      elError.hidden = true;
      elError.textContent = '';
    }
  }

  function normalizeType(raw) {
    return String(raw || '').trim().toLowerCase();
  }

  function isNavigationType(rawType) {
    return ['3d', '3d/navigation', 'navigation', 'nav', 'location'].includes(normalizeType(rawType));
  }

  function renderMeta(rows) {
    if (!elMeta) return;
    elMeta.innerHTML = rows
      .filter((row) => row && row.value)
      .map((row) => `<div class="vp-3d-meta-item"><span class="vp-3d-meta-label">${row.label}</span><span class="vp-3d-meta-value">${row.value}</span></div>`)
      .join('');
  }

  async function apiGet(url, method = 'GET', body) {
    const response = await fetch(url, {
      method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
      cache: 'no-store',
    });

    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
      const err = new Error(json.error || 'request_failed');
      err.status = response.status;
      err.payload = json;
      throw err;
    }

    return json;
  }

  function resolveError(err) {
    const codeValue = err?.payload?.error || err?.message || 'unknown';
    return friendlyErrors[codeValue] || 'Не удалось получить доступ к 3D-сцене.';
  }

  function attachViewer(src, poster) {
    if (!elViewer) return;
    if (poster) {
      elViewer.setAttribute('poster', poster);
    }

    elViewer.addEventListener('load', () => {
      if (elPlaceholder) elPlaceholder.hidden = true;
      setStatus('Сцена загружена.');
    }, { once: true });

    elViewer.addEventListener('error', () => {
      showError('Файл сцены не удалось загрузить.');
    }, { once: true });

    elViewer.setAttribute('src', src);
    if (elPlaceholder) {
      elPlaceholder.hidden = false;
      elPlaceholder.textContent = 'Загружаем 3D модель…';
    }
  }

  function loadPublicScene() {
    const modelUrl = `${FILE_URL}?code=${encodeURIComponent(code)}`;
    attachViewer(modelUrl, currentScene?.poster_url || '');
    if (elViewCard) elViewCard.hidden = false;
    setStatus('Загружаем 3D сцену…');
  }

  async function loadProtectedScene(password) {
    setStatus('Проверяем пароль…');
    const auth = await apiGet(AUTH_URL, 'POST', { code, password });
    const token = String(auth.token || '');
    if (!token) throw new Error('token_missing');

    const modelUrl = `${FILE_URL}?code=${encodeURIComponent(code)}&token=${encodeURIComponent(token)}`;
    attachViewer(modelUrl, currentScene?.poster_url || '');
    if (elViewCard) elViewCard.hidden = false;
    if (elAuthCard) elAuthCard.hidden = true;
    setStatus('Пароль принят. Загружаем модель…');
  }

  async function init() {
    if (!code) {
      showError('В URL отсутствует параметр code.');
      return;
    }

    try {
      setStatus('Загружаем метаданные сцены…');
      const lookupUrl = new URL(LOOKUP_URL, window.location.origin);
      lookupUrl.searchParams.set('code', code);
      const lookup = await apiGet(lookupUrl.toString());

      const item = Array.isArray(lookup.data) && lookup.data.length ? lookup.data[0] : null;
      if (!item) throw new Error('scene_not_found');
      if (!isNavigationType(item.type || item.kind)) {
        showError('Этот QR относится к инструкции. Откройте страницу /instruction.');
        return;
      }

      currentScene = item.scene || null;
      if (!currentScene) throw new Error('scene_not_linked');
      if (!currentScene.is_active) throw new Error('scene_disabled');

      if (elResultCard) elResultCard.hidden = false;
      if (elTitle) elTitle.textContent = currentScene.title || item.title || `QR ${code}`;
      if (elSub) elSub.textContent = item.product_id?.title || '3D-сцена';
      if (elType) elType.textContent = currentScene.kind || item.type || 'navigation';

      renderMeta([
        { label: 'Код', value: code },
        { label: 'Сцена', value: currentScene.id },
        { label: 'Доступ', value: currentScene.requires_password ? 'По паролю' : 'Открытый' },
        { label: 'Действует до', value: currentScene.expires_at || 'без ограничения' },
      ]);

      if (currentScene.requires_password) {
        if (elAuthCard) elAuthCard.hidden = false;
        if (elHint) elHint.textContent = currentScene.password_hint ? `Подсказка: ${currentScene.password_hint}` : 'Введите пароль для доступа к сцене.';
        setStatus('Сцена защищена паролем.');
      } else {
        loadPublicScene();
      }
    } catch (err) {
      console.error(err);
      showError(resolveError(err));
    }
  }

  if (elAuthForm) {
    elAuthForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearError();
      const password = String(elPassword?.value || '').trim();
      if (!password) {
        showError('Введите пароль.');
        return;
      }

      try {
        await loadProtectedScene(password);
      } catch (err) {
        showError(resolveError(err));
      }
    });
  }

  init();
})();