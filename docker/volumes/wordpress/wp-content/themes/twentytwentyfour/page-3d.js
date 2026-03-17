(() => {
  'use strict';

  const CFG = window.VP_3D || {};
  const PREFETCH = window.VP_3D_PREFETCH || {};

  const LOOKUP_URL = CFG.lookupUrl || '/wp-json/vp/v1/lookup';
  const AUTH_URL = CFG.authUrl || '/wp-json/vp/v1/3d/auth';
  const FILE_URL = CFG.fileUrl || '/wp-json/vp/v1/3d/file';
  const JOB_CREATE_URL = CFG.jobCreateUrl || '/wp-json/vp/v1/3d/job';
  const LOCATION_BOOTSTRAP_URL = CFG.locationBootstrapUrl || '/wp-json/vp/v1/location/bootstrap';
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
  let elPlaceholder;

  let elUploadForm;
  let elUploadFile;
  let elUploadSubmit;
  let elUploadStatus;

  let elPreview;
  let elLaunchPanel;
  let elLaunchButton;
  let elViewer = null;
  let elLocationPanel = null;

  const params = new URLSearchParams(window.location.search);
  const code = String(params.get('code') || '').trim().toUpperCase();
  const jobId = Number.parseInt(String(params.get('job_id') || ''), 10) || 0;

  const MODEL_VIEWER_BOOT_TIMEOUT_MS = 15000;
  const MODEL_LOAD_TIMEOUT_MS = 20000;

  let currentScene = null;
  let currentInteractiveSrc = '';
  let currentPosterUrl = '';
  let modelViewerModulePromise = null;
  let viewerBroken = false;
  let viewerFailureHandlersBound = false;

  const friendlyErrors = {
    code_required: 'В URL нет параметра code. Вернитесь на сканер и отсканируйте QR заново.',
    lookup_empty: 'По этому QR нет данных.',
    scene_not_linked: 'Для этого QR не привязана 3D-сцена.',
    scene_disabled: 'Сцена временно отключена.',
    scene_expired: 'Срок действия сцены истёк.',
    scene_model_missing: 'Для этой сцены не задан model_url.',
    invalid_password: 'Неверный пароль. Проверьте и попробуйте ещё раз.',
    token_invalid: 'Токен недействителен или устарел. Введите пароль снова.',
    job_pending: 'Модель ещё обрабатывается.',
    job_model_missing: 'Для job не удалось получить ссылку на модель.',
    location_not_found: 'Локация по этому коду не найдена.',
    anchor_not_found: 'Для этого кода не найден indoor-якорь.',
  };

  function setStatus(text) {
    if (elStatus) elStatus.textContent = text;
  }

  function hasPreview() {
    return !!(elPreview && !elPreview.hidden && elPreview.getAttribute('src'));
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
    elPreview.style.background = 'rgba(255,255,255,0.02)';
    elPreview.style.zIndex = '1';
    elPreview.style.pointerEvents = 'none';

    elViewerWrap.insertBefore(elPreview, elViewerMount || elPlaceholder || null);
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

  function ensureLaunchPanel() {
    if (elLaunchPanel || !elViewerWrap) return elLaunchPanel;

    elLaunchPanel = document.createElement('div');
    elLaunchPanel.id = 'vp3d-launch-panel';
    elLaunchPanel.hidden = true;
    elLaunchPanel.style.position = 'absolute';
    elLaunchPanel.style.left = '16px';
    elLaunchPanel.style.right = '16px';
    elLaunchPanel.style.bottom = '16px';
    elLaunchPanel.style.zIndex = '4';
    elLaunchPanel.style.display = 'flex';
    elLaunchPanel.style.justifyContent = 'center';
    elLaunchPanel.style.pointerEvents = 'auto';

    elLaunchButton = document.createElement('button');
    elLaunchButton.type = 'button';
    elLaunchButton.className = 'vp-3d-btn';
    elLaunchButton.textContent = 'Открыть интерактивный 3D';
    elLaunchButton.style.pointerEvents = 'auto';

    elLaunchButton.addEventListener('click', async () => {
      if (!currentInteractiveSrc) return;

      try {
        await attachViewer(currentInteractiveSrc, currentPosterUrl);
      } catch (err) {
        console.error(err);
        showViewerFallback('Интерактивный viewer не запустился. Остаёмся в режиме превью.');
      }
    });

    elLaunchPanel.appendChild(elLaunchButton);
    elViewerWrap.appendChild(elLaunchPanel);

    return elLaunchPanel;
  }

  function showLaunchPanel() {
    const panel = ensureLaunchPanel();
    if (panel) panel.hidden = false;
  }

  function hideLaunchPanel() {
    if (elLaunchPanel) elLaunchPanel.hidden = true;
  }

  function setPlaceholder(text, overlay = hasPreview()) {
    if (!elPlaceholder) return;
    elPlaceholder.hidden = false;
    elPlaceholder.textContent = text;
    elPlaceholder.style.zIndex = overlay ? '3' : '2';
    elPlaceholder.style.background = overlay
      ? 'linear-gradient(to top, rgba(0,0,0,0.72), rgba(0,0,0,0.18))'
      : 'transparent';
    elPlaceholder.style.color = overlay ? '#ffffff' : '';
    elPlaceholder.style.alignItems = overlay ? 'end' : 'center';
  }

  function hidePlaceholder() {
    if (!elPlaceholder) return;
    elPlaceholder.hidden = true;
  }

  function showError(text) {
    setStatus('Ошибка');
    setPlaceholder(text, hasPreview());
    hideLaunchPanel();
    hideViewer();
    hideLocationPanel();
  }

  function setUploadStatus(text, isError = false) {
    if (!elUploadStatus) return;

    const value = String(text || '').trim();
    if (!value) {
      elUploadStatus.hidden = true;
      elUploadStatus.textContent = '';
      elUploadStatus.classList.remove('is-error');
      return;
    }

    elUploadStatus.hidden = false;
    elUploadStatus.textContent = value;
    elUploadStatus.classList.toggle('is-error', !!isError);
  }

  function formatMaxBytes(bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return '';
    const mb = value / (1024 * 1024);
    if (mb >= 1) {
      return `${Math.round(mb)} МБ`;
    }
    const kb = value / 1024;
    return `${Math.round(kb)} КБ`;
  }

  function resolveUploadError(err) {
    const payload = err?.payload || {};
    const key = payload?.error || err?.message || 'upload_failed';

    if (key === 'file_required') return 'Выберите 3D файл перед отправкой.';
    if (key === 'file_type_not_allowed') return 'Разрешены только .stl и .obj.';
    if (key === 'file_too_large') {
      return payload?.max_bytes
        ? `Файл слишком большой. Максимум: ${formatMaxBytes(payload.max_bytes)}.`
        : 'Файл слишком большой.';
    }
    if (key === 'rate_limited') {
      return payload?.retry_after
        ? `Слишком много попыток загрузки. Повторите через ${payload.retry_after} сек.`
        : 'Слишком много попыток загрузки. Попробуйте позже.';
    }
    if (key === 'queue_unavailable') return 'Сервис очереди временно недоступен. Попробуйте позже.';
    if (key === 'directus_request_failed') return 'Не удалось создать job в backend.';
    if (key === 'job_create_failed') return 'Не удалось создать job.';
    if (key === 'upload_incomplete') return 'Файл загрузился не полностью. Повторите попытку.';
    if (key === 'empty_file') return 'Файл пустой.';
    if (key === 'upload_failed') return 'Не удалось загрузить файл.';
    if (typeof payload?.message === 'string' && payload.message.trim()) {
      return payload.message;
    }
    return 'Не удалось создать job. Проверьте файл и попробуйте снова.';
  }

  async function upload3dJob(file) {
    const formData = new FormData();
    formData.append('file', file);

    const res = await fetch(JOB_CREATE_URL, {
      method: 'POST',
      body: formData,
      cache: 'no-store',
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json?.ok) {
      const err = new Error(json?.error || 'upload_failed');
      err.status = res.status;
      err.payload = json;
      throw err;
    }

    return json;
  }

  function showViewerFallback(text) {
    viewerBroken = true;
    setStatus('Ограниченный режим');
    setPlaceholder(text, hasPreview());
    showLaunchPanel();
    hideViewer();
  }

  function hideViewer() {
    if (!elViewer) return;
    elViewer.style.display = 'none';
    elViewer.removeAttribute('src');
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
      text.includes('THREE.WebGLRenderer')
    ) {
      return 'На этом устройстве интерактивный 3D viewer недоступен. Остаёмся в режиме превью.';
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

  async function apiJson(url, method = 'GET', body) {
    const res = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        ...(body ? { 'Content-Type': 'application/json' } : {}),
      },
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

  function hasWorkingWebGL() {
    try {
      const canvas = document.createElement('canvas');
      if (!window.WebGLRenderingContext) return false;

      const gl =
        canvas.getContext('webgl', { antialias: false, powerPreference: 'low-power' }) ||
        canvas.getContext('experimental-webgl', { antialias: false, powerPreference: 'low-power' });

      return !!gl;
    } catch (_) {
      return false;
    }
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

    if (!customElements.get('model-viewer')) {
      setStatus('Инициализация 3D viewer…');
      await ensureModelViewerModule();

      await Promise.race([
        customElements.whenDefined('model-viewer'),
        new Promise((_, reject) =>
          setTimeout(() => reject(new Error('viewer_not_registered')), MODEL_VIEWER_BOOT_TIMEOUT_MS)
        ),
      ]);
    }
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

  async function attachViewer(src, posterUrl) {
    if (!src) throw new Error('scene_model_missing');

    const viewer = ensureViewerEl();
    if (!viewer) throw new Error('viewer_not_registered');

    currentInteractiveSrc = src;
    currentPosterUrl = posterUrl || '';

    if (posterUrl) {
      showPreview(posterUrl);
    }

    if (!hasWorkingWebGL()) {
      showViewerFallback('На этом устройстве недоступен WebGL. Остаёмся в режиме превью.');
      return;
    }

    viewerBroken = false;
    hideLaunchPanel();
    hideLocationPanel();
    setPlaceholder('Инициализация viewer…', false);

    await waitForModelViewer();

    if (posterUrl) {
      viewer.setAttribute('poster', posterUrl);
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
      showViewerFallback('Интерактивный viewer не загрузился. Остаёмся в режиме превью.');
    };

    const timeout = setTimeout(() => {
      if (done) return;
      cleanup(timeout);
      showViewerFallback('Интерактивный viewer не ответил вовремя. Остаёмся в режиме превью.');
    }, MODEL_LOAD_TIMEOUT_MS);

    viewer.addEventListener('load', onLoad, { once: true });
    viewer.addEventListener('error', onError, { once: true });

    viewer.removeAttribute('src');
    viewer.setAttribute('src', src);
  }

  function offerInteractive(src, posterUrl, message) {
    currentInteractiveSrc = String(src || '').trim();
    currentPosterUrl = String(posterUrl || '').trim();

    if (currentPosterUrl) {
      showPreview(currentPosterUrl);
    }

    setStatus('Режим превью');
    setPlaceholder(message || 'Можно открыть интерактивный viewer вручную.', hasPreview());
    showLaunchPanel();
    hideViewer();
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

  function fillLocationMeta(payload) {
    if (elMetaBlock) elMetaBlock.hidden = false;

    const location = payload?.location || {};
    const level = payload?.level || {};
    const anchor = payload?.anchor || {};

    if (elSceneTitle) elSceneTitle.textContent = location.title || 'Indoor navigation';
    if (elKindBadge) {
      elKindBadge.hidden = false;
      elKindBadge.textContent = 'location';
    }

    if (elCode) elCode.textContent = code || anchor.code || '—';
    if (elSceneId) elSceneId.textContent = level.code || level.title || '—';
    if (elType) elType.textContent = 'location';
    if (elAccess) elAccess.textContent = 'Indoor bootstrap';
    if (elExpiry) elExpiry.textContent = '—';
  }

  function isExpired(scene) {
    if (!scene?.expires_at) return false;
    const expiresAt = Date.parse(scene.expires_at);
    return !Number.isNaN(expiresAt) && expiresAt <= Date.now();
  }

  async function loadProtected(password) {
    setStatus('Проверяем пароль…');
    const auth = await apiJson(AUTH_URL, 'POST', { code, password });

    const token = String(auth.token || '').trim();
    if (!token) throw new Error('token_invalid');

    const url = `${FILE_URL}?code=${encodeURIComponent(code)}&token=${encodeURIComponent(token)}`;
    offerInteractive(
      url,
      currentScene?.poster_url || '',
      'Пароль принят. Нажмите кнопку ниже, чтобы открыть интерактивный viewer.'
    );

    if (elAuthWrap) elAuthWrap.hidden = true;
  }

  function ensureLocationPanel() {
    if (elLocationPanel || !elViewerMount) return elLocationPanel;

    elLocationPanel = document.createElement('div');
    elLocationPanel.id = 'vp3d-location-panel';
    elLocationPanel.className = 'vp-3d-location-panel';
    elLocationPanel.hidden = true;
    elLocationPanel.style.display = 'none';
    elLocationPanel.style.width = '100%';
    elLocationPanel.style.boxSizing = 'border-box';
    elLocationPanel.style.padding = '20px';
    elLocationPanel.style.borderRadius = '18px';
    elLocationPanel.style.background = 'rgba(255,255,255,0.96)';
    elLocationPanel.style.color = '#111827';
    elLocationPanel.style.position = 'relative';
    elLocationPanel.style.zIndex = '2';
    elLocationPanel.style.overflow = 'auto';
    elLocationPanel.style.maxHeight = '100%';

    elViewerMount.appendChild(elLocationPanel);
    return elLocationPanel;
  }

  function hideLocationPanel() {
    if (!elLocationPanel) return;
    elLocationPanel.hidden = true;
    elLocationPanel.style.display = 'none';
    elLocationPanel.innerHTML = '';
  }

  function renderLocationPanel(payload) {
    const panel = ensureLocationPanel();
    if (!panel) return;

    const location = payload?.location || {};
    const level = payload?.level || {};
    const anchor = payload?.anchor || {};
    const pois = Array.isArray(payload?.pois) ? payload.pois : [];

    const poiItems = pois.length
      ? pois.map((poi) => {
          const title = escapeHtml(String(poi.title || 'POI'));
          const kind = escapeHtml(String(poi.kind || 'point'));
          const coord = formatPoiCoord(poi);
          return `
            <div class="vp-3d-field" style="margin-bottom:12px;">
              <div class="vp-3d-label">${kind}</div>
              <div class="vp-3d-value"><strong>${title}</strong>${coord ? ` · ${coord}` : ''}</div>
            </div>
          `;
        }).join('')
      : `<div class="vp-3d-field"><div class="vp-3d-value">На этом уровне пока нет доступных POI.</div></div>`;

    panel.innerHTML = `
      <div class="vp-3d-meta-head" style="margin-bottom:16px;">
        <h3 class="vp-3d-meta-title" style="margin:0;">${escapeHtml(String(location.title || 'Локация'))}</h3>
        <span class="vp-3d-badge">indoor</span>
      </div>

      <div class="vp-3d-grid" style="margin-bottom:16px;">
        <div class="vp-3d-field">
          <div class="vp-3d-label">Этаж</div>
          <div class="vp-3d-value">${escapeHtml(String(level.title || level.code || '—'))}</div>
        </div>
        <div class="vp-3d-field">
          <div class="vp-3d-label">Якорь</div>
          <div class="vp-3d-value">${escapeHtml(String(anchor.title || 'Вы здесь'))}</div>
        </div>
        <div class="vp-3d-field">
          <div class="vp-3d-label">Координаты</div>
          <div class="vp-3d-value">${formatAnchorCoord(anchor) || '—'}</div>
        </div>
      </div>

      <div class="vp-3d-field" style="margin-bottom:8px;">
        <div class="vp-3d-label">Вы находитесь здесь</div>
        <div class="vp-3d-value">${escapeHtml(String(anchor.title || 'Текущая точка'))}</div>
      </div>

      <div class="vp-3d-field">
        <div class="vp-3d-label">Цели на этом уровне</div>
        <div class="vp-3d-value" style="margin-top:8px;">${poiItems}</div>
      </div>
    `;

    panel.hidden = false;
    panel.style.display = 'block';
  }

  function formatAnchorCoord(anchor) {
    const x = anchor?.x;
    const y = anchor?.y;
    if (typeof x === 'number' && typeof y === 'number') return `${x}, ${y}`;
    if (x !== undefined && y !== undefined && x !== null && y !== null) return `${x}, ${y}`;
    return '';
  }

  function formatPoiCoord(poi) {
    const x = poi?.x;
    const y = poi?.y;
    if (typeof x === 'number' && typeof y === 'number') return `${x}, ${y}`;
    if (x !== undefined && y !== undefined && x !== null && y !== null) return `${x}, ${y}`;
    return '';
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  async function loadLocationBootstrap() {
    const url = new URL(LOCATION_BOOTSTRAP_URL, window.location.origin);
    url.searchParams.set('code', code);

    const payload = await apiJson(url.toString());

    if (!payload?.ok) {
      throw new Error(payload?.error || 'location_not_found');
    }

    return payload;
  }

  async function initLocationMode() {
    try {
      setStatus('Загружаем indoor-данные…');
      hideViewer();
      hideLaunchPanel();
      hidePreview();
      if (elAuthWrap) elAuthWrap.hidden = true;

      const payload = await loadLocationBootstrap();
      fillLocationMeta(payload);
      renderLocationPanel(payload);
      hidePlaceholder();
      setStatus('Indoor bootstrap загружен.');
    } catch (err) {
      console.error(err);
      hideLocationPanel();
      showError(resolveError(err));
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

      const itemType = String(item?.type || '').trim().toLowerCase();
      currentScene = item.scene || null;

      if (itemType === 'location' && !currentScene) {
        await initLocationMode();
        return;
      }

      if (!currentScene) throw new Error('scene_not_linked');
      if (!currentScene.is_active) throw new Error('scene_disabled');
      if (isExpired(currentScene)) throw new Error('scene_expired');

      hideLocationPanel();
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
        hideLaunchPanel();
        return;
      }

      offerInteractive(
        currentScene?.model_url || '',
        currentScene?.poster_url || '',
        'Показываем безопасное превью. Интерактивный viewer запускается только по нажатию.'
      );
    } catch (err) {
      console.error(err);
      showError(resolveError(err));
    }
  }

  async function initJobMode() {
    const job = PREFETCH?.job || null;

    if (!job || !job.id || Number(job.id) !== jobId) {
      showError('Статус 3D job недоступен. Откройте страницу из интерфейса загрузки.');
      return;
    }

    hideLocationPanel();
    fillJobMeta(job);
    if (elAuthWrap) elAuthWrap.hidden = true;

    if (job.viewer_preview_url) {
      showPreview(job.viewer_preview_url);
    }

    if (job.status !== 'completed') {
      setStatus(`Job ${job.status || 'pending'}`);
      setPlaceholder(friendlyErrors.job_pending, hasPreview());
      hideLaunchPanel();
      hideViewer();
      return;
    }

    if (!job.viewer_glb_url) {
      if (job.viewer_preview_url) {
        setStatus('Режим превью');
        setPlaceholder('GLB ещё недоступен. Показываем только превью.', true);
        hideLaunchPanel();
        hideViewer();
        return;
      }
      showError(friendlyErrors.job_model_missing);
      return;
    }

    offerInteractive(
      job.viewer_glb_url,
      job.viewer_preview_url || '',
      'Показываем безопасное превью. Нажмите кнопку ниже, если хотите попробовать интерактивный viewer.'
    );
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

    elUploadForm = $('vp3d-upload-form');
    elUploadFile = $('vp3d-upload-file');
    elUploadSubmit = $('vp3d-upload-submit');
    elUploadStatus = $('vp3d-upload-status');
  }

  function bootstrap() {
    bindElements();
    bindViewerFailureHandlers();

    if (!elStatus || !elViewerWrap || !elViewerMount || !elPlaceholder) return;

    ensurePreviewEl();
    ensureLaunchPanel();
    ensureLocationPanel();

    if (elUploadForm) {
      elUploadForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const file = elUploadFile?.files && elUploadFile.files.length ? elUploadFile.files[0] : null;
        if (!file) {
          setUploadStatus('Выберите 3D файл перед отправкой.', true);
          return;
        }

        setUploadStatus('Загружаем файл и создаём job…');
        if (elUploadSubmit) elUploadSubmit.disabled = true;

        try {
          const result = await upload3dJob(file);
          const nextJobId = Number.parseInt(String(result?.job_id || ''), 10) || 0;
          if (nextJobId <= 0) {
            throw new Error('job_create_failed');
          }

          setUploadStatus('Job создан. Переходим к просмотру…');

          const redirectUrl = new URL(window.location.href);
          redirectUrl.search = '';
          redirectUrl.searchParams.set('job_id', String(nextJobId));
          window.location.assign(redirectUrl.toString());
        } catch (err) {
          console.error(err);
          setUploadStatus(resolveUploadError(err), true);
          if (elUploadSubmit) elUploadSubmit.disabled = false;
        }
      });
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