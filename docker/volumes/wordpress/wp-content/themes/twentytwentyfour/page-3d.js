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

  const MODEL_VIEWER_BOOT_TIMEOUT_MS = 15000;
  const MODEL_LOAD_TIMEOUT_MS = 20000;
  const ROUTE_STATE_KEY_PREFIX = 'vp3d:route:';

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

  let elLocationCard;
  let elLocationTitle;
  let elLevelTitle;
  let elAnchorTitle;
  let elPoiCount;
  let elPoiList;

  let elPreview;
  let elLaunchPanel;
  let elLaunchButton;
  let elViewer = null;
  let elLocationCanvas = null;
  let elPlanLayer = null;
  let elPlanImage = null;
  let elMapBadge = null;
  let elLocationToolbar = null;
  let elLocationSearch = null;
  let elLocationFilter = null;
  let elLocationAccessible = null;
  let elLocationClear = null;
  let elRoutePanel = null;

  let currentScene = null;
  let currentInteractiveSrc = '';
  let currentPosterUrl = '';
  let currentLocationPayload = null;
  let currentLocationData = null;
  let currentRoute = null;

  let modelViewerModulePromise = null;
  let viewerBroken = false;
  let viewerFailureHandlersBound = false;

  const params = new URLSearchParams(window.location.search);
  const code = String(params.get('code') || '').trim().toUpperCase();
  const jobId = Number.parseInt(String(params.get('job_id') || ''), 10) || 0;
  const targetParam = String(params.get('target') || '').trim();

  const uiState = {
    search: '',
    kind: 'all',
    accessibleOnly: false,
    selectedPoiId: null,
  };

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

  function showStatus(text) {
    setStatus(text);
  }

  function safeText(value, fallback = '—') {
    const text = String(value == null ? '' : value).trim();
    return text || fallback;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function slugify(value) {
    return String(value == null ? '' : value)
      .toLowerCase()
      .replace(/\s+/g, '-')
      .replace(/[^a-z0-9а-яё_-]+/gi, '-')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '');
  }

  function toNumber(value, fallback = NaN) {
    const num = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(num) ? num : fallback;
  }

  function hasPreview() {
    return !!(elPreview && !elPreview.hidden && elPreview.getAttribute('src'));
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

  function isDirectChild(parent, node) {
    return !!(parent && node && node.parentNode === parent);
  }

  function insertIntoViewerWrap(node, beforeNode = null) {
    if (!elViewerWrap || !node) return;

    if (beforeNode && beforeNode !== elViewerWrap && isDirectChild(elViewerWrap, beforeNode)) {
      elViewerWrap.insertBefore(node, beforeNode);
      return;
    }

    elViewerWrap.appendChild(node);
  }

  function setPlaceholder(text, overlay = hasPreview()) {
    if (!elPlaceholder) return;
    elPlaceholder.hidden = false;
    elPlaceholder.textContent = text;
    elPlaceholder.style.zIndex = overlay ? '8' : '6';
    elPlaceholder.style.background = overlay
      ? 'linear-gradient(to top, rgba(0, 0, 0, 0.72), rgba(0, 0, 0, 0.22))'
      : 'rgba(255,255,255,0.0)';
    elPlaceholder.style.color = overlay ? '#ffffff' : '';
    elPlaceholder.style.alignItems = overlay ? 'end' : 'center';
  }

  function hidePlaceholder() {
    if (!elPlaceholder) return;
    elPlaceholder.hidden = true;
  }

  function showError(text) {
    showStatus('Ошибка');
    setPlaceholder(text, hasPreview());
    hideLaunchPanel();
    hideViewer();
    hideLocationApp();
  }

  function resolveError(err) {
    const key = err?.payload?.error || err?.message || 'unknown';
    return friendlyErrors[key] || 'Не удалось загрузить 3D-сцену.';
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
    if (mb >= 1) return `${Math.round(mb)} МБ`;
    return `${Math.round(value / 1024)} КБ`;
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
    if (typeof payload?.message === 'string' && payload.message.trim()) return payload.message;
    return 'Не удалось создать job. Проверьте файл и попробуйте снова.';
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

  function showViewerFallback(text) {
    viewerBroken = true;
    showStatus('Ограниченный режим');
    setPlaceholder(text, hasPreview());
    showLaunchPanel();
    hideViewer();
  }

  function bindViewerFailureHandlers() {
    if (viewerFailureHandlersBound) return;
    viewerFailureHandlersBound = true;

    window.addEventListener(
      'error',
      (event) => {
        const msg = viewerFailureMessageFrom(event?.message || event?.error?.message || '');
        if (msg) showViewerFallback(msg);
      },
      true
    );

    window.addEventListener('unhandledrejection', (event) => {
      const reason = event?.reason;
      const msg = viewerFailureMessageFrom(reason?.message || reason || '');
      if (msg) showViewerFallback(msg);
    });
  }

  function ensurePreviewEl() {
    if (elPreview || !elViewerWrap) return elPreview;
    elPreview = document.createElement('img');
    elPreview.id = 'vp3d-preview-image';
    elPreview.alt = 'Превью 3D модели';
    elPreview.hidden = true;
    elPreview.decoding = 'async';
    elPreview.loading = 'eager';
    Object.assign(elPreview.style, {
      position: 'absolute',
      inset: '0',
      width: '100%',
      height: '100%',
      objectFit: 'contain',
      background: 'rgba(255,255,255,0.02)',
      zIndex: '1',
      pointerEvents: 'none',
    });
    const beforeNode = isDirectChild(elViewerWrap, elPlaceholder) ? elPlaceholder : null;
    insertIntoViewerWrap(elPreview, beforeNode);
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

  function hideViewer() {
    const viewer = ensureViewerEl();
    if (!viewer) return;
    viewer.style.display = 'none';
    viewer.removeAttribute('src');
  }

  function ensureLaunchPanel() {
    if (elLaunchPanel || !elViewerWrap) return elLaunchPanel;
    elLaunchPanel = document.createElement('div');
    elLaunchPanel.id = 'vp3d-launch-panel';
    elLaunchPanel.hidden = true;
    Object.assign(elLaunchPanel.style, {
      position: 'absolute',
      left: '16px',
      right: '16px',
      bottom: '16px',
      zIndex: '10',
      display: 'flex',
      justifyContent: 'center',
      pointerEvents: 'auto',
    });

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
    insertIntoViewerWrap(elLaunchPanel, null);
    return elLaunchPanel;
  }

  function showLaunchPanel() {
    const panel = ensureLaunchPanel();
    if (panel) panel.hidden = false;
  }

  function hideLaunchPanel() {
    if (elLaunchPanel) elLaunchPanel.hidden = true;
  }

  async function ensureModelViewerModule() {
    if (customElements.get('model-viewer')) return;
    if (modelViewerModulePromise) {
      await modelViewerModulePromise;
      return;
    }
    if (!MODEL_VIEWER_URL) throw new Error('viewer_not_registered');

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
      showStatus('Инициализация 3D viewer…');
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
    const existingViewer = document.getElementById('vp3d-viewer');
    if (existingViewer) {
      elViewer = existingViewer;
      return elViewer;
    }
    if (!elViewerWrap) return null;
    elViewer = document.createElement('model-viewer');
    elViewer.id = 'vp3d-viewer';
    elViewer.className = 'vp-3d-viewer';
    elViewer.style.display = 'none';
    elViewer.setAttribute('ar', '');
    elViewer.setAttribute('camera-controls', '');
    elViewer.setAttribute('touch-action', 'pan-y');
    elViewer.setAttribute('shadow-intensity', '1');
    const beforeNode = isDirectChild(elViewerWrap, elPlaceholder) ? elPlaceholder : null;
    insertIntoViewerWrap(elViewer, beforeNode);
    return elViewer;
  }

  async function attachViewer(src, posterUrl) {
    if (!src) throw new Error('scene_model_missing');

    const viewer = ensureViewerEl();
    if (!viewer) throw new Error('viewer_not_registered');

    currentInteractiveSrc = src;
    currentPosterUrl = posterUrl || '';

    if (posterUrl) showPreview(posterUrl);

    if (!hasWorkingWebGL()) {
      showViewerFallback('На этом устройстве недоступен WebGL. Остаёмся в режиме превью.');
      return;
    }

    viewerBroken = false;
    hideLaunchPanel();
    hideLocationApp();
    setPlaceholder('Инициализация viewer…', false);

    await waitForModelViewer();

    if (posterUrl) viewer.setAttribute('poster', posterUrl);
    else viewer.removeAttribute('poster');

    showStatus('Загружаем 3D модель…');
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
      showStatus('Сцена загружена.');
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
    if (currentPosterUrl) showPreview(currentPosterUrl);
    showStatus('Режим превью');
    setPlaceholder(message || 'Можно открыть интерактивный viewer вручную.', hasPreview());
    showLaunchPanel();
    hideViewer();
    hideLocationApp();
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
    if (elAccess) elAccess.textContent = 'Indoor runtime';
    if (elExpiry) elExpiry.textContent = '—';
  }

  function isExpired(scene) {
    if (!scene?.expires_at) return false;
    const expiresAt = Date.parse(scene.expires_at);
    return !Number.isNaN(expiresAt) && expiresAt <= Date.now();
  }

  async function loadProtected(password) {
    showStatus('Проверяем пароль…');
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

  function normalizePoi(rawPoi, anchor) {
    const id = rawPoi?.id != null ? String(rawPoi.id) : slugify(rawPoi?.slug || rawPoi?.title || rawPoi?.kind || 'poi');
    const title = safeText(rawPoi?.title, 'POI');
    const kind = safeText(rawPoi?.kind, 'point');
    const x = toNumber(rawPoi?.x, NaN);
    const y = toNumber(rawPoi?.y, NaN);
    const nodeId = rawPoi?.node_id?.id ?? rawPoi?.node_id ?? null;
    const distanceFromAnchor = Number.isFinite(x) && Number.isFinite(y) && Number.isFinite(anchor.x) && Number.isFinite(anchor.y)
      ? euclidean(anchor.x, anchor.y, x, y)
      : null;

    return {
      raw: rawPoi,
      id,
      slug: safeText(rawPoi?.slug, ''),
      title,
      kind,
      brand: safeText(rawPoi?.brand, ''),
      description: safeText(rawPoi?.description, ''),
      icon: safeText(rawPoi?.icon, ''),
      x,
      y,
      nodeId: nodeId == null ? null : String(nodeId),
      isActive: rawPoi?.is_active !== false,
      isPublic: rawPoi?.is_public !== false,
      keywords: Array.isArray(rawPoi?.keywords) ? rawPoi.keywords : [],
      openingHours: safeText(rawPoi?.opening_hours, ''),
      phone: safeText(rawPoi?.phone, ''),
      url: safeText(rawPoi?.url, ''),
      distanceFromAnchor,
    };
  }

  function normalizeNode(rawNode) {
    const id = rawNode?.id != null ? String(rawNode.id) : null;
    return {
      id,
      title: safeText(rawNode?.title, id || 'Узел'),
      kind: safeText(rawNode?.kind, 'walk'),
      x: toNumber(rawNode?.x, NaN),
      y: toNumber(rawNode?.y, NaN),
      z: toNumber(rawNode?.z, NaN),
      isActive: rawNode?.is_active !== false,
      isPublic: rawNode?.is_public !== false,
      accessibilityTags: Array.isArray(rawNode?.accessibility_tags) ? rawNode.accessibility_tags : [],
      raw: rawNode,
    };
  }

  function normalizeEdge(rawEdge) {
    const fromNodeId = rawEdge?.from_node_id?.id ?? rawEdge?.from_node_id ?? null;
    const toNodeId = rawEdge?.to_node_id?.id ?? rawEdge?.to_node_id ?? null;
    return {
      id: rawEdge?.id != null ? String(rawEdge.id) : `${fromNodeId}:${toNodeId}`,
      fromNodeId: fromNodeId == null ? null : String(fromNodeId),
      toNodeId: toNodeId == null ? null : String(toNodeId),
      kind: safeText(rawEdge?.kind, 'walk'),
      distanceM: toNumber(rawEdge?.distance_m, NaN),
      durationS: toNumber(rawEdge?.duration_s, NaN),
      isBidirectional: rawEdge?.is_bidirectional !== false,
      isActive: rawEdge?.is_active !== false,
      isAccessible: rawEdge?.is_accessible !== false,
      levelChange: toNumber(rawEdge?.level_change, 0),
      raw: rawEdge,
    };
  }

  function normalizeLocationPayload(payload) {
    const location = payload?.location || {};
    const level = payload?.level || {};
    const anchorRaw = payload?.anchor || {};
    const anchorNodeId = anchorRaw?.node_id?.id ?? anchorRaw?.node_id ?? null;

    const anchor = {
      id: anchorRaw?.id != null ? String(anchorRaw.id) : 'anchor',
      title: safeText(anchorRaw?.title, 'Вы здесь'),
      code: safeText(anchorRaw?.code, code || 'anchor'),
      kind: safeText(anchorRaw?.kind, 'anchor'),
      x: toNumber(anchorRaw?.x, 0),
      y: toNumber(anchorRaw?.y, 0),
      headingDeg: toNumber(anchorRaw?.heading_deg, 0),
      nodeId: anchorNodeId == null ? null : String(anchorNodeId),
      raw: anchorRaw,
    };

    const pois = (Array.isArray(payload?.pois) ? payload.pois : [])
      .map((poi) => normalizePoi(poi, anchor))
      .filter((poi) => poi.isActive && poi.isPublic);

    const rawNodes = Array.isArray(payload?.nodes)
      ? payload.nodes
      : Array.isArray(payload?.graph?.nodes)
      ? payload.graph.nodes
      : [];

    const rawEdges = Array.isArray(payload?.edges)
      ? payload.edges
      : Array.isArray(payload?.graph?.edges)
      ? payload.graph.edges
      : [];

    const nodes = rawNodes.map(normalizeNode).filter((node) => node.id && node.isActive);
    const edges = rawEdges.map(normalizeEdge).filter((edge) => edge.fromNodeId && edge.toNodeId && edge.isActive);

    return {
      location: {
        id: location?.id != null ? String(location.id) : 'location',
        title: safeText(location?.title, 'Локация'),
        kind: safeText(location?.kind, 'location'),
        description: safeText(location?.description, ''),
        address: safeText(location?.address, ''),
        raw: location,
      },
      level: {
        id: level?.id != null ? String(level.id) : 'level',
        code: safeText(level?.code, ''),
        title: safeText(level?.title, 'Этаж'),
        raw: level,
      },
      anchor,
      pois,
      nodes,
      edges,
    };
  }

  function formatMeters(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return '—';
    if (num >= 1000) return `${(num / 1000).toFixed(1)} км`;
    return `${Math.round(num)} м`;
  }

  function formatSeconds(value) {
    const num = Number(value);
    if (!Number.isFinite(num) || num <= 0) return '—';
    if (num < 60) return `${Math.round(num)} сек`;
    const mins = Math.round(num / 60);
    return `${mins} мин`;
  }

  function euclidean(x1, y1, x2, y2) {
    return Math.sqrt(Math.pow(x2 - x1, 2) + Math.pow(y2 - y1, 2));
  }

  function estimateWalkSeconds(distancePxOrM) {
    if (!Number.isFinite(distancePxOrM)) return null;
    const meters = distancePxOrM;
    return Math.max(15, Math.round((meters / 1.25)));
  }

  function buildGraph(nodes, edges, options = {}) {
    const accessibleOnly = !!options.accessibleOnly;
    const nodeMap = new Map(nodes.map((node) => [node.id, node]));
    const adjacency = new Map();
    nodes.forEach((node) => adjacency.set(node.id, []));

    edges.forEach((edge) => {
      if (accessibleOnly && edge.isAccessible === false) return;
      if (!nodeMap.has(edge.fromNodeId) || !nodeMap.has(edge.toNodeId)) return;

      const fromNode = nodeMap.get(edge.fromNodeId);
      const toNode = nodeMap.get(edge.toNodeId);
      const distance = Number.isFinite(edge.distanceM)
        ? edge.distanceM
        : Number.isFinite(fromNode.x) && Number.isFinite(fromNode.y) && Number.isFinite(toNode.x) && Number.isFinite(toNode.y)
        ? euclidean(fromNode.x, fromNode.y, toNode.x, toNode.y)
        : 1;

      const duration = Number.isFinite(edge.durationS) ? edge.durationS : estimateWalkSeconds(distance) || 1;
      const weight = duration > 0 ? duration : distance;

      adjacency.get(edge.fromNodeId).push({ edge, to: edge.toNodeId, weight, distance, duration });
      if (edge.isBidirectional) {
        adjacency.get(edge.toNodeId).push({ edge, to: edge.fromNodeId, weight, distance, duration });
      }
    });

    return { nodeMap, adjacency };
  }

  function dijkstra(graph, startId, endId) {
    const { adjacency, nodeMap } = graph;
    if (!adjacency.has(startId) || !adjacency.has(endId)) return null;

    const dist = new Map();
    const prev = new Map();
    const prevEdge = new Map();
    const visited = new Set();

    nodeMap.forEach((_, id) => dist.set(id, Number.POSITIVE_INFINITY));
    dist.set(startId, 0);

    while (visited.size < nodeMap.size) {
      let currentId = null;
      let currentDist = Number.POSITIVE_INFINITY;

      dist.forEach((value, id) => {
        if (!visited.has(id) && value < currentDist) {
          currentDist = value;
          currentId = id;
        }
      });

      if (currentId == null) break;
      if (currentId === endId) break;
      visited.add(currentId);

      const neighbors = adjacency.get(currentId) || [];
      neighbors.forEach((item) => {
        if (visited.has(item.to)) return;
        const alt = currentDist + item.weight;
        if (alt < dist.get(item.to)) {
          dist.set(item.to, alt);
          prev.set(item.to, currentId);
          prevEdge.set(item.to, item);
        }
      });
    }

    if (!prev.has(endId) && startId !== endId) return null;

    const nodeIds = [endId];
    const edgeItems = [];
    let cursor = endId;
    while (cursor !== startId) {
      const item = prevEdge.get(cursor);
      const previous = prev.get(cursor);
      if (!item || !previous) break;
      edgeItems.push(item);
      nodeIds.push(previous);
      cursor = previous;
    }
    nodeIds.reverse();
    edgeItems.reverse();

    let distanceM = 0;
    let durationS = 0;
    edgeItems.forEach((item) => {
      distanceM += item.distance || 0;
      durationS += item.duration || 0;
    });

    return { nodeIds, edgeItems, distanceM, durationS };
  }

  function computeRoute(locationData, poi, options = {}) {
    if (!locationData || !poi) return null;
    const anchor = locationData.anchor;

    if (
      anchor.nodeId &&
      poi.nodeId &&
      Array.isArray(locationData.nodes) &&
      locationData.nodes.length &&
      Array.isArray(locationData.edges) &&
      locationData.edges.length
    ) {
      const graph = buildGraph(locationData.nodes, locationData.edges, options);
      const path = dijkstra(graph, anchor.nodeId, poi.nodeId);
      if (path && path.nodeIds.length) {
        const points = path.nodeIds
          .map((id) => graph.nodeMap.get(id))
          .filter(Boolean)
          .map((node) => ({ x: node.x, y: node.y, title: node.title }));
        return {
          mode: 'graph',
          poi,
          points,
          distanceM: path.distanceM,
          durationS: path.durationS,
          steps: buildGraphSteps(path.edgeItems, graph.nodeMap, poi),
        };
      }
    }

    if (Number.isFinite(anchor.x) && Number.isFinite(anchor.y) && Number.isFinite(poi.x) && Number.isFinite(poi.y)) {
      const distanceM = euclidean(anchor.x, anchor.y, poi.x, poi.y);
      const durationS = estimateWalkSeconds(distanceM);
      return {
        mode: 'direct',
        poi,
        points: [
          { x: anchor.x, y: anchor.y, title: anchor.title },
          { x: poi.x, y: poi.y, title: poi.title },
        ],
        distanceM,
        durationS,
        steps: [
          `Стартуйте от точки «${anchor.title}».`,
          `Идите прямо к «${poi.title}» примерно ${formatMeters(distanceM)}.`,
          `Финиш: «${poi.title}».`,
        ],
      };
    }

    return {
      mode: 'info',
      poi,
      points: [],
      distanceM: poi.distanceFromAnchor,
      durationS: poi.distanceFromAnchor != null ? estimateWalkSeconds(poi.distanceFromAnchor) : null,
      steps: [`Выберите «${poi.title}». Координат для отрисовки маршрута пока недостаточно.`],
    };
  }

  function buildGraphSteps(edgeItems, nodeMap, poi) {
    if (!edgeItems.length) return [`Финиш: «${poi.title}».`];
    return edgeItems.map((item, index) => {
      const toNode = nodeMap.get(item.to);
      const targetTitle = toNode?.title || `узел ${item.to}`;
      const verbByKind = {
        walk: 'Идите',
        stairs: 'Поднимитесь/спуститесь по лестнице к',
        elevator: 'Используйте лифт до',
        escalator: 'Используйте эскалатор до',
        ramp: 'Идите по пандусу к',
        service: 'Пройдите по сервисному переходу к',
      };
      const verb = verbByKind[item.edge.kind] || 'Перейдите к';
      const suffix = item.distance ? ` (${formatMeters(item.distance)})` : '';
      if (index === edgeItems.length - 1) {
        return `${verb} «${targetTitle}»${suffix}. Затем цель: «${poi.title}».`;
      }
      return `${verb} «${targetTitle}»${suffix}.`;
    });
  }

  function resolveLevelPlanUrl(level) {
    if (!level) return '';
    const candidates = [
      level.floor_plan_svg_url,
      level.floor_plan_image_url,
      level.floor_plan_url,
      level.floor_plan_src,
      level.plan_url,
      typeof level.floor_plan_svg === 'string' ? level.floor_plan_svg : '',
      typeof level.floor_plan_image === 'string' ? level.floor_plan_image : '',
      level.raw?.floor_plan_svg_url,
      level.raw?.floor_plan_image_url,
      level.raw?.floor_plan_url,
    ];
    for (const candidate of candidates) {
      const value = String(candidate || '').trim();
      if (value && /^https?:\/\//i.test(value)) return value;
      if (value && value.startsWith('/')) return value;
    }
    return '';
  }

  function ensurePlanLayer() {
    if (elPlanLayer || !elViewerWrap) return elPlanLayer;
    elPlanLayer = document.createElement('div');
    elPlanLayer.id = 'vp3d-location-plan-layer';
    Object.assign(elPlanLayer.style, {
      position: 'absolute',
      inset: '0',
      zIndex: '2',
      pointerEvents: 'none',
      background: 'linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,250,252,0.96))',
    });
    elPlanImage = document.createElement('img');
    elPlanImage.alt = 'План этажа';
    Object.assign(elPlanImage.style, {
      width: '100%',
      height: '100%',
      objectFit: 'contain',
      opacity: '0.38',
      display: 'none',
    });
    elPlanLayer.appendChild(elPlanImage);
    insertIntoViewerWrap(elPlanLayer, null);
    return elPlanLayer;
  }

  function ensureLocationCanvas() {
    if (!elViewerWrap) return null;
    if (!elLocationCanvas) {
      elLocationCanvas = document.createElement('canvas');
      elLocationCanvas.id = 'vp3d-location-canvas';
      Object.assign(elLocationCanvas.style, {
        position: 'absolute',
        inset: '0',
        width: '100%',
        height: '100%',
        pointerEvents: 'none',
        zIndex: '4',
        display: 'none',
      });
      insertIntoViewerWrap(elLocationCanvas, null);
    }
    return elLocationCanvas;
  }

  function ensureMapBadge() {
    if (elMapBadge || !elViewerWrap) return elMapBadge;
    elMapBadge = document.createElement('div');
    elMapBadge.id = 'vp3d-location-map-badge';
    Object.assign(elMapBadge.style, {
      position: 'absolute',
      left: '12px',
      top: '12px',
      zIndex: '9',
      padding: '8px 10px',
      borderRadius: '999px',
      background: 'rgba(17,24,39,0.82)',
      color: '#fff',
      fontSize: '12px',
      fontWeight: '600',
      display: 'none',
    });
    insertIntoViewerWrap(elMapBadge, null);
    return elMapBadge;
  }

  function showLocationMap() {
    const planLayer = ensurePlanLayer();
    const canvas = ensureLocationCanvas();
    const badge = ensureMapBadge();
    if (planLayer) planLayer.style.display = 'block';
    if (canvas) canvas.style.display = 'block';
    if (badge) badge.style.display = 'block';
  }

  function hideLocationMap() {
    if (elPlanLayer) elPlanLayer.style.display = 'none';
    if (elLocationCanvas) elLocationCanvas.style.display = 'none';
    if (elMapBadge) elMapBadge.style.display = 'none';
  }

  function ensureRoutePanel() {
    if (elRoutePanel || !elLocationCard) return elRoutePanel;
    elRoutePanel = document.createElement('div');
    elRoutePanel.id = 'vp3d-route-panel';
    elRoutePanel.className = 'vp-3d-field';
    elRoutePanel.style.marginTop = '12px';
    elLocationCard.appendChild(elRoutePanel);
    return elRoutePanel;
  }

  function ensureLocationToolbar() {
    if (elLocationToolbar || !elLocationCard) return elLocationToolbar;
    elLocationToolbar = document.createElement('div');
    elLocationToolbar.id = 'vp3d-location-toolbar';
    elLocationToolbar.style.display = 'grid';
    elLocationToolbar.style.gap = '10px';
    elLocationToolbar.style.marginTop = '12px';

    const row = document.createElement('div');
    row.style.display = 'grid';
    row.style.gap = '10px';
    row.style.gridTemplateColumns = '1.5fr 1fr';

    elLocationSearch = document.createElement('input');
    elLocationSearch.type = 'search';
    elLocationSearch.className = 'vp-3d-input';
    elLocationSearch.placeholder = 'Найти POI';
    elLocationSearch.autocomplete = 'off';

    elLocationFilter = document.createElement('select');
    elLocationFilter.className = 'vp-3d-input';

    row.appendChild(elLocationSearch);
    row.appendChild(elLocationFilter);

    const row2 = document.createElement('div');
    row2.style.display = 'flex';
    row2.style.flexWrap = 'wrap';
    row2.style.gap = '10px';
    row2.style.alignItems = 'center';

    const accessibilityLabel = document.createElement('label');
    accessibilityLabel.style.display = 'inline-flex';
    accessibilityLabel.style.alignItems = 'center';
    accessibilityLabel.style.gap = '8px';
    accessibilityLabel.style.fontSize = '14px';
    accessibilityLabel.style.color = 'var(--vp-ui-text)';

    elLocationAccessible = document.createElement('input');
    elLocationAccessible.type = 'checkbox';
    accessibilityLabel.appendChild(elLocationAccessible);
    accessibilityLabel.appendChild(document.createTextNode('Безбарьерный маршрут'));

    elLocationClear = document.createElement('button');
    elLocationClear.type = 'button';
    elLocationClear.className = 'vp-3d-btn';
    elLocationClear.textContent = 'Сбросить маршрут';

    const shareBtn = document.createElement('button');
    shareBtn.type = 'button';
    shareBtn.className = 'vp-3d-btn';
    shareBtn.textContent = 'Скопировать ссылку';
    shareBtn.addEventListener('click', async () => {
      const url = new URL(window.location.href);
      if (uiState.selectedPoiId) url.searchParams.set('target', uiState.selectedPoiId);
      else url.searchParams.delete('target');
      try {
        await navigator.clipboard.writeText(url.toString());
        showStatus('Ссылка скопирована.');
      } catch (_) {
        showStatus(url.toString());
      }
    });

    row2.appendChild(accessibilityLabel);
    row2.appendChild(elLocationClear);
    row2.appendChild(shareBtn);

    elLocationToolbar.appendChild(row);
    elLocationToolbar.appendChild(row2);

    if (elPoiList && elPoiList.parentNode) {
      elPoiList.parentNode.insertBefore(elLocationToolbar, elPoiList);
    } else {
      elLocationCard.appendChild(elLocationToolbar);
    }

    elLocationSearch.addEventListener('input', () => {
      uiState.search = String(elLocationSearch.value || '').trim();
      renderLocationUI();
    });

    elLocationFilter.addEventListener('change', () => {
      uiState.kind = String(elLocationFilter.value || 'all');
      renderLocationUI();
    });

    elLocationAccessible.addEventListener('change', () => {
      uiState.accessibleOnly = !!elLocationAccessible.checked;
      computeAndRenderRoute();
    });

    elLocationClear.addEventListener('click', () => {
      uiState.selectedPoiId = null;
      currentRoute = null;
      syncTargetParam();
      persistRouteState();
      renderLocationUI();
    });

    return elLocationToolbar;
  }

  function ensurePasswordFormAccessibility() {
    if (!elAuthForm) return;
    if (elAuthForm.querySelector('input[autocomplete="username"]')) return;
    const username = document.createElement('input');
    username.type = 'text';
    username.name = 'username';
    username.autocomplete = 'username';
    username.hidden = true;
    username.tabIndex = -1;
    username.setAttribute('aria-hidden', 'true');
    username.value = code ? `qr:${code}` : 'vp-user';
    elAuthForm.prepend(username);
  }

  function hideLocationApp() {
    hideLocationMap();
    if (elLocationCard) elLocationCard.hidden = true;
    currentLocationPayload = null;
    currentLocationData = null;
    currentRoute = null;
  }

  function showLocationApp() {
    if (elLocationCard) elLocationCard.hidden = false;
    showLocationMap();
  }

  function getLocationStorageKey() {
    if (!currentLocationData) return '';
    return `${ROUTE_STATE_KEY_PREFIX}${currentLocationData.location.id}:${currentLocationData.level.id}`;
  }

  function persistRouteState() {
    const key = getLocationStorageKey();
    if (!key) return;
    try {
      window.localStorage.setItem(
        key,
        JSON.stringify({
          selectedPoiId: uiState.selectedPoiId,
          kind: uiState.kind,
          accessibleOnly: uiState.accessibleOnly,
        })
      );
    } catch (_) {}
  }

  function restoreRouteState() {
    if (!currentLocationData) return;
    if (targetParam) {
      uiState.selectedPoiId = targetParam;
      return;
    }
    const key = getLocationStorageKey();
    if (!key) return;
    try {
      const raw = window.localStorage.getItem(key);
      if (!raw) return;
      const state = JSON.parse(raw);
      if (state && typeof state === 'object') {
        uiState.selectedPoiId = state.selectedPoiId || null;
        uiState.kind = state.kind || 'all';
        uiState.accessibleOnly = !!state.accessibleOnly;
      }
    } catch (_) {}
  }

  function syncTargetParam() {
    if (!window.history || !window.history.replaceState) return;
    const url = new URL(window.location.href);
    if (uiState.selectedPoiId) url.searchParams.set('target', uiState.selectedPoiId);
    else url.searchParams.delete('target');
    window.history.replaceState({}, '', url.toString());
  }

  function getFilteredPois() {
    if (!currentLocationData) return [];
    const term = uiState.search.trim().toLowerCase();
    return currentLocationData.pois
      .filter((poi) => uiState.kind === 'all' || poi.kind === uiState.kind)
      .filter((poi) => {
        if (!term) return true;
        const haystack = [poi.title, poi.kind, poi.brand, poi.description]
          .concat(poi.keywords || [])
          .join(' ')
          .toLowerCase();
        return haystack.includes(term);
      })
      .sort((a, b) => {
        const da = a.distanceFromAnchor == null ? Number.POSITIVE_INFINITY : a.distanceFromAnchor;
        const db = b.distanceFromAnchor == null ? Number.POSITIVE_INFINITY : b.distanceFromAnchor;
        return da - db;
      });
  }

  function getSelectedPoi() {
    if (!currentLocationData || !uiState.selectedPoiId) return null;
    const selected = currentLocationData.pois.find(
      (poi) => poi.id === uiState.selectedPoiId || poi.slug === uiState.selectedPoiId || slugify(poi.title) === uiState.selectedPoiId
    );
    return selected || null;
  }

  function hydrateFilterOptions() {
    if (!elLocationFilter || !currentLocationData) return;
    const current = uiState.kind || 'all';
    const kinds = Array.from(new Set(currentLocationData.pois.map((poi) => poi.kind).filter(Boolean))).sort();
    elLocationFilter.innerHTML = '';
    const allOption = document.createElement('option');
    allOption.value = 'all';
    allOption.textContent = 'Все категории';
    elLocationFilter.appendChild(allOption);
    kinds.forEach((kind) => {
      const option = document.createElement('option');
      option.value = kind;
      option.textContent = kind;
      elLocationFilter.appendChild(option);
    });
    elLocationFilter.value = kinds.includes(current) ? current : 'all';
  }

  function renderLocationList(filteredPois) {
    if (!elPoiList) return;
    elPoiList.innerHTML = '';

    if (!filteredPois.length) {
      const empty = document.createElement('li');
      empty.className = 'vp-3d-location-empty';
      empty.textContent = 'По текущему фильтру POI не найдены.';
      elPoiList.appendChild(empty);
      return;
    }

    filteredPois.forEach((poi) => {
      const item = document.createElement('li');
      item.className = 'vp-3d-location-item';
      item.style.cursor = 'pointer';
      item.style.borderWidth = uiState.selectedPoiId === poi.id ? '2px' : '1px';
      item.style.borderColor = uiState.selectedPoiId === poi.id ? '#2563eb' : 'var(--vp-ui-line)';
      item.setAttribute('role', 'button');
      item.tabIndex = 0;

      const distanceText = poi.distanceFromAnchor != null ? formatMeters(poi.distanceFromAnchor) : 'без координат';
      item.innerHTML = `
        <div class="vp-3d-location-item-title">${escapeHtml(poi.title)}</div>
        <div class="vp-3d-location-item-meta">${escapeHtml(poi.kind)} · ${escapeHtml(distanceText)}</div>
        ${poi.brand ? `<div class="vp-3d-location-item-meta">${escapeHtml(poi.brand)}</div>` : ''}
        ${poi.description ? `<div class="vp-3d-location-item-meta">${escapeHtml(poi.description)}</div>` : ''}
      `;

      const activate = () => {
        uiState.selectedPoiId = poi.id;
        persistRouteState();
        syncTargetParam();
        computeAndRenderRoute();
      };

      item.addEventListener('click', activate);
      item.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          activate();
        }
      });

      elPoiList.appendChild(item);
    });
  }

  function renderRoutePanel() {
    const panel = ensureRoutePanel();
    if (!panel) return;

    const selectedPoi = getSelectedPoi();
    if (!selectedPoi) {
      panel.innerHTML = `
        <div class="vp-3d-label">Маршрут</div>
        <div class="vp-3d-value">Выберите точку назначения из списка ниже.</div>
      `;
      return;
    }

    const route = currentRoute || computeRoute(currentLocationData, selectedPoi, { accessibleOnly: uiState.accessibleOnly });
    currentRoute = route;

    const modeLabel = route?.mode === 'graph'
      ? 'По графу'
      : route?.mode === 'direct'
      ? 'Прямая линия'
      : 'Информационный режим';

    const stepsHtml = Array.isArray(route?.steps) && route.steps.length
      ? `<ol style="margin:10px 0 0 18px;padding:0;display:grid;gap:6px;">${route.steps
          .map((step) => `<li>${escapeHtml(step)}</li>`)
          .join('')}</ol>`
      : '<div class="vp-3d-value">Пошаговый маршрут пока недоступен.</div>';

    panel.innerHTML = `
      <div class="vp-3d-label">Маршрут до цели</div>
      <div class="vp-3d-value" style="margin-bottom:8px;">${escapeHtml(selectedPoi.title)}</div>
      <div class="vp-3d-grid" style="margin-top:0;">
        <div class="vp-3d-field">
          <div class="vp-3d-label">Режим</div>
          <div class="vp-3d-value">${escapeHtml(modeLabel)}</div>
        </div>
        <div class="vp-3d-field">
          <div class="vp-3d-label">Дистанция</div>
          <div class="vp-3d-value">${route?.distanceM != null ? formatMeters(route.distanceM) : '—'}</div>
        </div>
        <div class="vp-3d-field">
          <div class="vp-3d-label">Оценка времени</div>
          <div class="vp-3d-value">${route?.durationS != null ? formatSeconds(route.durationS) : '—'}</div>
        </div>
        <div class="vp-3d-field">
          <div class="vp-3d-label">Категория</div>
          <div class="vp-3d-value">${escapeHtml(selectedPoi.kind)}</div>
        </div>
      </div>
      <div style="margin-top:10px;">
        <div class="vp-3d-label">Шаги</div>
        ${stepsHtml}
      </div>
    `;
  }

  function computeAndRenderRoute() {
    const selectedPoi = getSelectedPoi();
    currentRoute = selectedPoi ? computeRoute(currentLocationData, selectedPoi, { accessibleOnly: uiState.accessibleOnly }) : null;
    renderLocationUI();
  }

  function renderLocationUI() {
    if (!currentLocationData) return;

    showLocationApp();

    if (elLocationTitle) elLocationTitle.textContent = currentLocationData.location.title;
    if (elLevelTitle) elLevelTitle.textContent = currentLocationData.level.title || currentLocationData.level.code || '—';
    if (elAnchorTitle) elAnchorTitle.textContent = currentLocationData.anchor.title;
    if (elPoiCount) elPoiCount.textContent = String(currentLocationData.pois.length);

    const toolbar = ensureLocationToolbar();
    if (toolbar) {
      hydrateFilterOptions();
      if (elLocationSearch) elLocationSearch.value = uiState.search;
      if (elLocationFilter) elLocationFilter.value = uiState.kind;
      if (elLocationAccessible) elLocationAccessible.checked = !!uiState.accessibleOnly;
    }

    const badge = ensureMapBadge();
    if (badge) {
      const selectedPoi = getSelectedPoi();
      badge.textContent = selectedPoi ? `Маршрут: ${selectedPoi.title}` : `${currentLocationData.level.title} · ${currentLocationData.anchor.title}`;
    }

    const filteredPois = getFilteredPois();
    renderLocationList(filteredPois);
    renderRoutePanel();
    renderLocationMap(filteredPois);
  }

  function renderLocationMap(filteredPois) {
    const canvas = ensureLocationCanvas();
    const planLayer = ensurePlanLayer();
    if (!canvas || !planLayer || !currentLocationData) return;

    const levelPlanUrl = resolveLevelPlanUrl(currentLocationData.level.raw || currentLocationData.level);
    if (elPlanImage) {
      if (levelPlanUrl) {
        elPlanImage.src = levelPlanUrl;
        elPlanImage.style.display = 'block';
      } else {
        elPlanImage.removeAttribute('src');
        elPlanImage.style.display = 'none';
      }
    }

    const width = Math.max(elViewerWrap.clientWidth || 320, 320);
    const height = Math.max(elViewerWrap.clientHeight || 320, 320);
    canvas.width = width;
    canvas.height = height;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.clearRect(0, 0, width, height);

    const anchor = currentLocationData.anchor;
    const pois = filteredPois && filteredPois.length ? filteredPois : currentLocationData.pois;
    const route = currentRoute;

    const coords = [];
    if (Number.isFinite(anchor.x) && Number.isFinite(anchor.y)) coords.push({ x: anchor.x, y: anchor.y });
    pois.forEach((poi) => {
      if (Number.isFinite(poi.x) && Number.isFinite(poi.y)) coords.push({ x: poi.x, y: poi.y });
    });
    if (route?.points?.length) {
      route.points.forEach((point) => {
        if (Number.isFinite(point.x) && Number.isFinite(point.y)) coords.push({ x: point.x, y: point.y });
      });
    }

    let minX = 0;
    let maxX = 1;
    let minY = 0;
    let maxY = 1;
    if (coords.length) {
      minX = Math.min(...coords.map((p) => p.x));
      maxX = Math.max(...coords.map((p) => p.x));
      minY = Math.min(...coords.map((p) => p.y));
      maxY = Math.max(...coords.map((p) => p.y));
      if (minX === maxX) maxX += 1;
      if (minY === maxY) maxY += 1;
    }

    const margin = 36;
    const scaleX = (width - margin * 2) / (maxX - minX || 1);
    const scaleY = (height - margin * 2) / (maxY - minY || 1);
    const scale = Math.min(scaleX, scaleY);

    function projectX(x) {
      return margin + (x - minX) * scale;
    }

    function projectY(y) {
      return height - margin - (y - minY) * scale;
    }

    ctx.fillStyle = 'rgba(15, 23, 42, 0.035)';
    ctx.fillRect(0, 0, width, height);

    ctx.strokeStyle = 'rgba(148, 163, 184, 0.35)';
    ctx.lineWidth = 1;
    const gridStep = 80;
    for (let x = gridStep; x < width; x += gridStep) {
      ctx.beginPath();
      ctx.moveTo(x, 0);
      ctx.lineTo(x, height);
      ctx.stroke();
    }
    for (let y = gridStep; y < height; y += gridStep) {
      ctx.beginPath();
      ctx.moveTo(0, y);
      ctx.lineTo(width, y);
      ctx.stroke();
    }

    if (route?.points?.length >= 2) {
      ctx.strokeStyle = '#2563eb';
      ctx.lineWidth = 4;
      ctx.beginPath();
      route.points.forEach((point, index) => {
        const px = projectX(point.x);
        const py = projectY(point.y);
        if (index === 0) ctx.moveTo(px, py);
        else ctx.lineTo(px, py);
      });
      ctx.stroke();
    }

    pois.forEach((poi, index) => {
      if (!Number.isFinite(poi.x) || !Number.isFinite(poi.y)) return;
      const selected = uiState.selectedPoiId === poi.id;
      const px = projectX(poi.x);
      const py = projectY(poi.y);
      ctx.fillStyle = selected ? '#2563eb' : '#dc2626';
      ctx.beginPath();
      ctx.arc(px, py, selected ? 7 : 5, 0, Math.PI * 2);
      ctx.fill();

      if (selected || index < 8) {
        ctx.fillStyle = '#0f172a';
        ctx.font = selected ? '600 13px sans-serif' : '12px sans-serif';
        ctx.fillText(poi.title, px + 8, py - 8);
      }
    });

    if (Number.isFinite(anchor.x) && Number.isFinite(anchor.y)) {
      const ax = projectX(anchor.x);
      const ay = projectY(anchor.y);
      ctx.fillStyle = '#16a34a';
      ctx.beginPath();
      ctx.arc(ax, ay, 8, 0, Math.PI * 2);
      ctx.fill();

      const heading = (Number.isFinite(anchor.headingDeg) ? anchor.headingDeg : 0) * (Math.PI / 180);
      const arrowLen = 18;
      ctx.strokeStyle = '#16a34a';
      ctx.lineWidth = 3;
      ctx.beginPath();
      ctx.moveTo(ax, ay);
      ctx.lineTo(ax + Math.cos(heading) * arrowLen, ay - Math.sin(heading) * arrowLen);
      ctx.stroke();

      ctx.fillStyle = '#166534';
      ctx.font = '600 13px sans-serif';
      ctx.fillText(anchor.title || 'Вы здесь', ax + 10, ay - 12);
    }
  }

  async function loadLocationBootstrap() {
    const url = new URL(LOCATION_BOOTSTRAP_URL, window.location.origin);
    url.searchParams.set('code', code);
    const payload = await apiJson(url.toString());
    if (!payload?.ok) throw new Error(payload?.error || 'location_not_found');
    return payload;
  }

  async function initLocationMode() {
    try {
      showStatus('Загружаем indoor-данные…');
      hideViewer();
      hideLaunchPanel();
      hidePreview();
      if (elAuthWrap) elAuthWrap.hidden = true;

      const payload = await loadLocationBootstrap();
      currentLocationPayload = payload;
      currentLocationData = normalizeLocationPayload(payload);

      fillLocationMeta(payload);
      restoreRouteState();
      if (!getSelectedPoi() && currentLocationData.pois.length) {
        const best = currentLocationData.pois[0];
        uiState.selectedPoiId = best ? best.id : null;
      }

      renderLocationUI();
      hidePlaceholder();
      showStatus('Навигация готова. Выберите точку назначения или используйте уже предложенный маршрут.');
      persistRouteState();
      syncTargetParam();
    } catch (err) {
      console.error(err);
      hideLocationApp();
      showError(resolveError(err));
    }
  }

  async function initCodeMode() {
    if (!code) {
      showError(friendlyErrors.code_required);
      return;
    }

    try {
      showStatus('Загружаем метаданные сцены…');
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

      hideLocationApp();
      fillMeta(item, currentScene);
      showPreview(currentScene?.poster_url || '');

      if (currentScene.requires_password) {
        if (elAuthWrap) elAuthWrap.hidden = false;
        if (elHint) {
          const hint = currentScene.password_hint ? `Подсказка: ${currentScene.password_hint}` : 'Введите пароль.';
          elHint.hidden = false;
          elHint.textContent = hint;
        }
        showStatus('Сцена защищена паролем.');
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

    hideLocationApp();
    fillJobMeta(job);
    if (elAuthWrap) elAuthWrap.hidden = true;

    if (job.viewer_preview_url) showPreview(job.viewer_preview_url);

    if (job.status !== 'completed') {
      showStatus(`Job ${job.status || 'pending'}`);
      setPlaceholder(friendlyErrors.job_pending, hasPreview());
      hideLaunchPanel();
      hideViewer();
      return;
    }

    if (!job.viewer_glb_url) {
      if (job.viewer_preview_url) {
        showStatus('Режим превью');
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

  function bindFabActions() {
    document.addEventListener('click', (event) => {
      const actionEl = event.target.closest('[data-vp-action]');
      if (!actionEl) return;
      const action = actionEl.getAttribute('data-vp-action');
      if (!action) return;

      if (action === 'reloadModel') {
        if (currentLocationData) {
          renderLocationUI();
          showStatus('Карта и маршрут обновлены.');
          return;
        }
        if (currentInteractiveSrc) {
          attachViewer(currentInteractiveSrc, currentPosterUrl).catch((err) => {
            console.error(err);
            showError(resolveError(err));
          });
        }
        return;
      }

      if (action === 'resetView') {
        if (currentLocationData) {
          uiState.selectedPoiId = null;
          currentRoute = null;
          syncTargetParam();
          persistRouteState();
          renderLocationUI();
          showStatus('Маршрут сброшен.');
          return;
        }
        hideViewer();
        if (currentPosterUrl) showPreview(currentPosterUrl);
        setPlaceholder('Вид сброшен. Можно открыть viewer заново.', hasPreview());
        showLaunchPanel();
      }
    });
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
    elViewerMount = $('vp3d-viewer-mount') || elViewerWrap;
    elPlaceholder = $('vp3d-viewer-placeholder');

    elUploadForm = $('vp3d-upload-form');
    elUploadFile = $('vp3d-upload-file');
    elUploadSubmit = $('vp3d-upload-submit');
    elUploadStatus = $('vp3d-upload-status');

    elLocationCard = $('vp3d-location-card');
    elLocationTitle = $('vp3d-location-title');
    elLevelTitle = $('vp3d-level-title');
    elAnchorTitle = $('vp3d-anchor-title');
    elPoiCount = $('vp3d-poi-count');
    elPoiList = $('vp3d-poi-list');
  }

  function bootstrap() {
    bindElements();
    bindViewerFailureHandlers();
    bindFabActions();
    ensurePasswordFormAccessibility();

    if (!elStatus || !elViewerWrap || !elPlaceholder) return;

    ensurePreviewEl();
    ensureLaunchPanel();
    ensurePlanLayer();
    ensureLocationCanvas();
    ensureMapBadge();
    ensureLocationToolbar();
    ensureRoutePanel();
    hideLocationApp();

    window.addEventListener('resize', () => {
      if (currentLocationData) renderLocationUI();
    });

    if (elUploadForm) {
      elUploadForm.addEventListener('submit', async (event) => {
        event.preventDefault();
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
          if (nextJobId <= 0) throw new Error('job_create_failed');
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
      elAuthForm.addEventListener('submit', async (event) => {
        event.preventDefault();
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