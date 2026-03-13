(() => {
  'use strict';

  const CFG = window.VP_3D || {};
  const PREFETCH = window.VP_3D_PREFETCH || {};

  const LOOKUP_URL = CFG.lookupUrl || '/wp-json/vp/v1/lookup';
  const AUTH_URL = CFG.authUrl || '/wp-json/vp/v1/3d/auth';
  const FILE_URL = CFG.fileUrl || '/wp-json/vp/v1/3d/file';
  const MODEL_VIEWER_URL = String(PREFETCH.modelViewerUrl || '').trim();

  const $ = (id) => document.getElementById(id);

  let elStatus;
  let elMetaBlock;
  let elSceneTitle;
  let elKindBadge;
  let elCode;
  let elSceneId;
  let elType;
  let elAccess;
  let elExpiry;
  let elAuthWrap;
  let elAuthForm;
  let elPassword;
  let elHint;
  let elViewerWrap;
  let elViewerMount;
  let elViewer;
  let elPlaceholder;
  let elPreview;

  const params = new URLSearchParams(window.location.search);
  const code = String(params.get('code') || '').trim().toUpperCase();
  const jobId = Number.parseInt(String(params.get('job_id') || ''), 10) || 0;

  const MODEL_VIEWER_BOOT_TIMEOUT_MS = 15000;
  const MODEL_LOAD_TIMEOUT_MS = 20000;

  let currentScene = null;
  let viewerFailureHandlersBound = false;
  let modelViewerModulePromise = null;
  let viewerBroken = false;

  const friendlyErrors = {
    code_required: 'В URL нет параметра code. Вернитесь на сканер и отсканируйте QR заново.',
    lookup_empty: 'По этому QR нет данных.',
    scene_not_linked: 'Для этого QR не привязана 3D-сцена.',
    scene_disabled: 'Сцена временно отключена.',
    scene_expired: 'Срок действия сцены истёк.',
    scene_model_missing: 'Для этой сцены не задан model_url.',
    invalid_password: 'Неверный пароль. Проверьте и попробуйте ещё раз.',
    token_invalid: 'Токен недействителен или устарел. Введите пароль снова.',
    job_missing: 'Для этого job пока нет viewer-данных.',
    job_pending: 'Модель ещё обрабатывается.',
    job_model_missing: 'Для job не удалось получить ссылку на модель.',
  };

  function setStatus(text) {
    if (elStatus) elStatus.textContent = text;
  }

  function hasPreview() {
    return !!(elPreview && !elPreview.hidden && elPreview.getAttribute('src'));
  }

  function applyPlaceholderMode(useOverlay) {
    if (!elPlaceholder) return;

    elPlaceholder.style.zIndex = useOverlay ? '3' : '2';
    elPlaceholder.style.background = useOverlay
      ? 'linear-gradient(to top, rgba(0, 0, 0, 0.72), rgba(0, 0, 0, 0.18))'
      : 'transparent';
    elPlaceholder.style.color = useOverlay ? '#ffffff' : '';
    elPlaceholder.style.alignItems = useOverlay ? 'end' : 'center';
  }

  function setPlaceholder(text, useOverlay = hasPreview()) {
    if (!elPlaceholder) return;
    elPlaceholder.hidden = false;
    elPlaceholder.textContent = text;
    applyPlaceholderMode(useOverlay);
  }

  function hidePlaceholder() {
    if (!elPlaceholder) return;
    elPlaceholder.hidden = true;
  }

  function ensurePreviewEl() {
    if (elPreview || !elViewerWrap) return elPreview;

    elPreview = document.createElement('img');
    elPreview.id = 'vp3d-preview-image';
    elPreview.alt = 'Превью 3D модели';
    elPreview.hidden = true;
    elPreview.decoding = 'async';
    elPreview.loading = 'eager';
    elPreview.style.position = 'absolute';
    elPreview.style.inset = '0';
    elPreview.style.width = '100%';
    elPreview.style.height = '100%';
    elPreview.style.objectFit = 'contain';
    elPreview.style.background = 'rgba(255, 255, 255, 0.02)';
    elPreview.style.zIndex = '1';
    elPreview.style.display = 'block';
    elPreview.style.pointerEvents = 'none';

    elViewerWrap.insertBefore(elPreview, elPlaceholder || null);
    return elPreview;
  }

  function showPreview(url) {
    const safeUrl = String(url || '').trim();
    if (!safeUrl) return;

    const img = ensurePreviewEl();
    if (!img) return;

    img.src = safeUrl;
    img.hidden = false;
  }

  function hidePreview() {
    if (!elPreview) return;
    elPreview.hidden = true;
  }

  function ensureViewerEl() {
    if (elViewer) return elViewer;
    if (!elViewerMount) return null;

    elViewer = document.createElement('model-viewer');
    elViewer.id = 'vp3d-viewer';
    elViewer.className = 'vp-3d-viewer';
    elViewer.style.display = 'none';
    elViewer.setAttribute('ar', '');
    elViewer.setAttribute('camera-controls', '');
    elViewer.setAttribute('touch-action', 'pan-y');
    elViewer.setAttribute('shadow-intensity', '1');

    elViewerMount.appendChild(elViewer);
    return elViewer;
  }

  function hideViewer() {
    if (!elViewer) return;
    elViewer.style.display = 'none';
    elViewer.removeAttribute('src');
  }

  function showError(text) {
    setStatus('Ошибка');
    setPlaceholder(text, hasPreview());
    hideViewer();
  }

  function showViewerFallback(text) {
    viewerBroken = true;
    setStatus('Ограниченный режим');
    setPlaceholder(text, hasPreview());
    hideViewer();
  }

  function resolveError(err) {
    const key = err?.payload?.error || err?.message || 'unknown';
    return friendlyErrors[key] || 'Не удалось загрузить 3D-сцену.';
  }

  function viewerFailureMessageFrom(value) {
    const text = String(value || '');
    if (!text) return '';

    if (
      text.includes('WebGL context could not be created') ||
      text.includes('Error creating WebGL context') ||
      text.includes("reading 'xr'") ||
      text.includes('THREE.WebGLRenderer') ||
      text.includes('Failed to execute')
    ) {
      return 'На этом устройстве интерактивный 3D viewer недоступен. Показываем превью модели.';
    }

    return '';
  }

  function bindViewerFailureHandlers() {
    if (viewerFailureHandlersBound) return;
    viewerFailureHandlersBound = true;

    window.addEventListener(
      'error',
      (event) => {
        const msg = viewerFailureMessageFrom(event?.message || event?.error?.message || '');
        if (msg) {
          showViewerFallback(msg);
        }
      },
      true
    );

    window.addEventListener('unhandledrejection', (event) => {
      const reason = event?.reason;
      const msg = viewerFailureMessageFrom(reason?.message || reason || '');
      if (msg) {
        showViewerFallback(msg);
      }
    });
  }

  function hasWorkingWebGL() {
    try {
      const canvas = document.createElement('canvas');
      if (!window.WebGLRenderingContext) return false;

      const gl =
        canvas.getContext('webgl', { antialias: false, powerPreference: 'low-power' }) ||
        canvas.getContext('experimental-webgl', { antialias: false, powerPreference: 'low-power' });

      if (!gl) return false;

      try {
        gl.getParameter(gl.VERSION);
      } catch (_) {
        return false;
      }

      return true;
    } catch (_) {
      return false;
    }
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

  async function ensureModelViewerModule() {
    if (customElements.get('model-viewer')) return;

    if (modelViewerModulePromise) {
      await modelViewerModulePromise;
      return;
    }

    if (!MODEL_VIEWER_URL) {
      throw new Error('viewer_not_registered');
    }

    modelViewerModulePromise = new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-vp-model-viewer="1"]');
      if (existing) {
        existing.addEventListener('load', resolve, { once: true });
        existing.addEventListener('error', () => reject(new Error('viewer_not_registered')), { once: true });
        return;
      }

      const script = document.createElement('script');
      script.type = 'module';
      script.src = MODEL_VIEWER_URL;
      script.async = true;
      script.dataset.vpModelViewer = '1';
      script.addEventListener('load', resolve, { once: true });
      script.addEventListener('error', () => reject(new Error('viewer_not_registered')), { once: true });
      document.head.appendChild(script);
    });

    await modelViewerModulePromise;
  }

  async function waitForModelViewer() {
    if (!window.customElements || typeof customElements.whenDefined !== 'function') {
      throw new Error('viewer_not_registered');
    }

    if (customElements.get('model-viewer')) return;

    setStatus('Инициализация 3D viewer…');
    await ensureModelViewerModule();

    await Promise.race([
      customElements.whenDefined('model-viewer'),
      new Promise((_, reject) =>
        setTimeout(() => reject(new Error('viewer_not_registered')), MODEL_VIEWER_BOOT_TIMEOUT_MS)
      ),
    ]);
  }

  async function attachViewer(src, posterUrl) {
    if (!src) throw new Error('scene_model_missing');

    const safePosterUrl = String(posterUrl || '').trim();
    if (safePosterUrl) {
      showPreview(safePosterUrl);
    }

    if (!hasWorkingWebGL()) {
      showViewerFallback('На этом устройстве недоступен WebGL. Показываем превью модели.');
      return;
    }

    const viewer = ensureViewerEl();
    if (!viewer) {
      throw new Error('viewer_not_registered');
    }

    viewerBroken = false;
    setPlaceholder('Инициализация viewer…', false);

    await waitForModelViewer();

    if (safePosterUrl) {
      viewer.setAttribute('poster', safePosterUrl);
    } else {
      viewer.removeAttribute('poster');
    }

    setStatus('Загружаем 3D модель…');
    setPlaceholder('Загружаем 3D модель…', false);
    viewer.style.display = 'block';

    let done = false;
    const cleanup = (timeoutHandle) => {
      done = true;
      clearTimeout(timeoutHandle);
      viewer.removeEventListener('load', onLoad);
      viewer.removeEventListener('error', onError);
    };

    const onLoad = () => {
      cleanup(timeout);
      if (viewerBroken) return;
      hidePreview();
      hidePlaceholder();
      setStatus('Сцена загружена.');
    };

    const onError = () => {
      cleanup(timeout);
      showViewerFallback('Не удалось загрузить интерактивный viewer. Показываем превью модели.');
    };

    const timeout = setTimeout(() => {
      if (done) return;
      cleanup(timeout);
      showViewerFallback('Интерактивный viewer не ответил вовремя. Показываем превью модели.');
    }, MODEL_LOAD_TIMEOUT_MS);

    viewer.addEventListener('load', onLoad, { once: true });
    viewer.addEventListener('error', onError, { once: true });

    viewer.removeAttribute('src');
    viewer.setAttribute('src', src);
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

  function fillJobMeta(job) {
    if (elMetaBlock) elMetaBlock.hidden = false;

    if (elSceneTitle) elSceneTitle.textContent = `3D Job #${job.id}`;
    if (elKindBadge) {
      elKindBadge.hidden = false;
      elKindBadge.textContent = 'job';
    }

    if (elCode) elCode.textContent = `job:${job.id}`;
    if (elSceneId) elSceneId.textContent = job.result_glb_wp_id ? String(job.result_glb_wp_id) : '—';
    if (elType) elType.textContent = 'converter';
    if (elAccess) elAccess.textContent = 'Signed /dl link';
    if (elExpiry) elExpiry.textContent = 'примерно 1 час';
  }

  function isExpired(scene) {
    if (!scene?.expires_at) return false;
    const expiresAt = Date.parse(scene.expires_at);
    return !Number.isNaN(expiresAt) && expiresAt <= Date.now();
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

    const url = `${FILE_URL}?code=${encodeURIComponent(code)}&token=${encodeURIComponent(token)}`;
    await attachViewer(url, currentScene?.poster_url || '');
    if (elAuthWrap) elAuthWrap.hidden = true;
    if (!viewerBroken) {
      setStatus('Пароль принят.');
    }
  }

  async function initCodeMode() {
    if (!code) {
      showError(friendlyErrors.code_required);
      return;
    }

    try {
      setStatus('Загружаем метаданные сцены…');

      const lookupUrl = new URL(LOOKUP_URL, window.location.origin);
      lookupUrl.searchParams.set('code', code);

      const lookup = await apiJson(lookupUrl.toString());
      const item = Array.isArray(lookup.data) && lookup.data.length ? lookup.data[0] : null;
      if (!item) throw new Error('lookup_empty');

      currentScene = item.scene || null;
      if (!currentScene) throw new Error('scene_not_linked');
      if (!currentScene.is_active) throw new Error('scene_disabled');
      if (isExpired(currentScene)) throw new Error('scene_expired');

      fillMeta(item, currentScene);
      showPreview(currentScene?.poster_url || '');

      if (currentScene.requires_password) {
        if (elAuthWrap) elAuthWrap.hidden = false;
        if (elHint) {
          const hint = currentScene.password_hint ? `Подсказка: ${currentScene.password_hint}` : 'Введите пароль.';
          elHint.hidden = false;
          elHint.textContent = hint;
        }
        setStatus('Сцена защищена паролем.');
        setPlaceholder('Введите пароль, чтобы открыть сцену.', hasPreview());
        return;
      }

      await loadPublic();
    } catch (err) {
      console.error(err);
      if (err?.message === 'viewer_not_registered') {
        showViewerFallback('Интерактивный 3D viewer не загрузился. Показываем превью модели.');
        return;
      }
      showError(resolveError(err));
    }
  }

  async function initJobMode() {
    const job = PREFETCH?.job || null;

    if (!job || !job.id || Number(job.id) !== jobId) {
      showError('Статус 3D job недоступен. Откройте страницу из интерфейса загрузки.');
      return;
    }

    fillJobMeta(job);

    if (elAuthWrap) elAuthWrap.hidden = true;

    if (job.viewer_preview_url) {
      showPreview(job.viewer_preview_url);
    }

    if (job.status !== 'completed') {
      setStatus(`Job ${job.status || 'pending'}`);
      setPlaceholder(friendlyErrors.job_pending, hasPreview());
      hideViewer();
      return;
    }

    if (!job.viewer_glb_url) {
      if (job.viewer_preview_url) {
        showViewerFallback('GLB ещё недоступен. Показываем превью модели.');
        return;
      }
      showError(friendlyErrors.job_model_missing);
      return;
    }

    try {
      await attachViewer(job.viewer_glb_url, job.viewer_preview_url || '');
    } catch (err) {
      console.error(err);
      if (err?.message === 'viewer_not_registered') {
        showViewerFallback('Интерактивный 3D viewer не загрузился. Показываем превью модели.');
        return;
      }
      showError(resolveError(err));
    }
  }

  function bindElements() {
    elStatus = $('vp3d-status');
    elMetaBlock = $('vp3d-meta');
    elSceneTitle = $('vp3d-scene-title');
    elKindBadge = $('vp3d-kind');
    elCode = $('vp3d-code');
    elSceneId = $('vp3d-scene-id');
    elType = $('vp3d-type');
    elAccess = $('vp3d-access');
    elExpiry = $('vp3d-expiry');
    elAuthWrap = $('vp3d-auth');
    elAuthForm = $('vp3d-auth-form');
    elPassword = $('vp3d-password');
    elHint = $('vp3d-hint');
    elViewerWrap = $('vp3d-viewer-wrap');
    elViewerMount = $('vp3d-viewer-mount');
    elPlaceholder = $('vp3d-viewer-placeholder');
  }

  function bootstrap() {
    bindElements();
    bindViewerFailureHandlers();

    if (!elStatus || !elViewerWrap || !elViewerMount || !elPlaceholder) return;

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
            showViewerFallback('Интерактивный 3D viewer не загрузился. Показываем превью модели.');
            return;
          }
          showError(resolveError(err));
        }
      });
    }

    if (jobId > 0) {
      initJobMode();
      return;
    }

    initCodeMode();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
  } else {
    bootstrap();
  }
})();