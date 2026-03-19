(() => {
  'use strict';

  const CFG = window.VP_3D || {};
  const PREFETCH = window.VP_3D_PREFETCH || {};

  const LOOKUP_URL = CFG.lookupUrl || '/wp-json/vp/v1/lookup';
  const AUTH_URL = CFG.authUrl || '/wp-json/vp/v1/3d/auth';
  const FILE_URL = CFG.fileUrl || '/wp-json/vp/v1/3d/file';
  const JOB_STATUS_URL = CFG.jobStatusUrl || '/wp-json/vp/v1/3d/job-status';
  const LOCATION_BOOTSTRAP_URL = CFG.locationBootstrapUrl || '/wp-json/vp/v1/location/viewer-bootstrap';
  const THREE_RUNTIME_URL = String(CFG.threeRuntimeUrl || '').trim();

  const params = new URLSearchParams(window.location.search);
  const code = String(params.get('code') || '').trim().toUpperCase();
  const jobId = Number.parseInt(String(params.get('job_id') || ''), 10) || 0;
  const targetParam = String(params.get('target') || '').trim();

  const state = {
    mode: 'unknown',
    scene: null,
    job: null,
    location: null,
    selectedPoiId: targetParam || null,
    currentRoute: null,
    currentInteractiveSrc: '',
    currentPosterUrl: '',
    threeRuntime: null,
    threeRuntimeReady: false,
    threeRuntimeFailed: false,
  };

  const ROUTE_STATE_KEY_PREFIX = 'vp3d:route:';

  const ui = {
    status: null,
    meta: null,
    sceneTitle: null,
    kind: null,
    code: null,
    sceneId: null,
    type: null,
    access: null,
    expiry: null,

    authWrap: null,
    authForm: null,
    password: null,
    hint: null,

    locationCard: null,
    locationTitle: null,
    levelTitle: null,
    anchorTitle: null,
    poiCount: null,
    poiList: null,

    viewerWrap: null,
    viewer: null,
    placeholder: null,
    preview: null,
    launchPanel: null,
    launchButton: null,
    routePanel: null,
    toolbar: null,
    searchInput: null,
    clearButton: null,
    mapCanvas: null,
  };

  const friendlyErrors = {
    code_required: 'В URL нет параметра code. Вернитесь на сканер и повторите вход.',
    lookup_empty: 'По этому коду ничего не найдено.',
    qr_inactive: 'Этот QR сейчас неактивен.',
    location_type_required: 'Этот код не относится к indoor navigation.',
    location_not_linked: 'У кода нет корректной привязки к location graph.',
    anchor_not_found: 'Для этого кода не найден anchor.',
    scene_not_linked: 'Для этого QR не привязана 3D-сцена.',
    scene_disabled: 'Сцена сейчас отключена.',
    scene_expired: 'Срок действия сцены истёк.',
    invalid_password: 'Неверный пароль.',
    token_invalid: 'Токен сцены недействителен или истёк.',
    job_pending: 'Модель ещё обрабатывается.',
    job_model_missing: 'Для job не удалось получить GLB.',
    request_failed: 'Не удалось получить данные.',
    viewer_not_supported: 'На этом устройстве не удалось запустить интерактивный viewer.',
  };

  function $(id) {
    return document.getElementById(id);
  }

  function bindElements() {
    ui.status = $('vp3d-status');
    ui.meta = $('vp3d-meta');
    ui.sceneTitle = $('vp3d-scene-title');
    ui.kind = $('vp3d-kind');
    ui.code = $('vp3d-code');
    ui.sceneId = $('vp3d-scene-id');
    ui.type = $('vp3d-type');
    ui.access = $('vp3d-access');
    ui.expiry = $('vp3d-expiry');

    ui.authWrap = $('vp3d-auth');
    ui.authForm = $('vp3d-auth-form');
    ui.password = $('vp3d-password');
    ui.hint = $('vp3d-hint');

    ui.locationCard = $('vp3d-location-card');
    ui.locationTitle = $('vp3d-location-title');
    ui.levelTitle = $('vp3d-level-title');
    ui.anchorTitle = $('vp3d-anchor-title');
    ui.poiCount = $('vp3d-poi-count');
    ui.poiList = $('vp3d-poi-list');

    ui.viewerWrap = $('vp3d-viewer-wrap');
    ui.viewer = $('vp3d-viewer');
    ui.placeholder = $('vp3d-viewer-placeholder');
  }

  function setStatus(text) {
    if (ui.status) ui.status.textContent = text;
  }

  function showMeta() {
    if (ui.meta) ui.meta.hidden = false;
  }

  function hideMeta() {
    if (ui.meta) ui.meta.hidden = true;
  }

  function showLocationCard() {
    if (ui.locationCard) ui.locationCard.hidden = false;
  }

  function hideLocationCard() {
    if (ui.locationCard) ui.locationCard.hidden = true;
  }

  function safeText(value, fallback = '—') {
    const text = String(value == null ? '' : value).trim();
    return text || fallback;
  }

  function toNumber(value, fallback = NaN) {
    const num = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(num) ? num : fallback;
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
      const err = new Error(json.error || 'request_failed');
      err.status = res.status;
      err.payload = json;
      throw err;
    }

    return json;
  }

  function resolveError(err) {
    const key = err?.payload?.error || err?.message || 'request_failed';
    return friendlyErrors[key] || friendlyErrors.request_failed;
  }

  function hasWebGL() {
    try {
      const canvas = document.createElement('canvas');
      if (!window.WebGLRenderingContext) return false;
      const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
      return !!gl;
    } catch (_) {
      return false;
    }
  }

  function ensurePreview() {
    if (ui.preview || !ui.viewerWrap) return ui.preview;

    const img = document.createElement('img');
    img.id = 'vp3d-preview-image';
    img.alt = 'Превью';
    img.hidden = true;
    img.decoding = 'async';
    img.loading = 'eager';

    Object.assign(img.style, {
      position: 'absolute',
      inset: '0',
      width: '100%',
      height: '100%',
      objectFit: 'contain',
      background: 'rgba(255,255,255,0.02)',
      zIndex: '1',
      pointerEvents: 'none',
    });

    ui.viewerWrap.appendChild(img);
    ui.preview = img;
    return ui.preview;
  }

  function showPreview(url) {
    const safeUrl = String(url || '').trim();
    if (!safeUrl) return;
    const img = ensurePreview();
    img.src = safeUrl;
    img.hidden = false;
  }

  function hidePreview() {
    if (ui.preview) ui.preview.hidden = true;
  }

  function setPlaceholder(text) {
    if (!ui.placeholder) return;
    ui.placeholder.hidden = false;
    ui.placeholder.textContent = String(text || '');
  }

  function hidePlaceholder() {
    if (!ui.placeholder) return;
    ui.placeholder.hidden = true;
  }

  function hideViewer() {
    if (!ui.viewer) return;
    ui.viewer.style.display = 'none';
    ui.viewer.removeAttribute('src');
  }

  function ensureLaunchPanel() {
    if (ui.launchPanel || !ui.viewerWrap) return ui.launchPanel;

    const panel = document.createElement('div');
    panel.id = 'vp3d-launch-panel';
    panel.hidden = true;

    Object.assign(panel.style, {
      position: 'absolute',
      left: '16px',
      right: '16px',
      bottom: '16px',
      zIndex: '10',
      display: 'flex',
      justifyContent: 'center',
      pointerEvents: 'auto',
    });

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'vp-3d-btn';
    button.textContent = 'Открыть интерактивный viewer';
    button.addEventListener('click', async () => {
      if (!state.currentInteractiveSrc) return;
      try {
        await attachViewer(state.currentInteractiveSrc, state.currentPosterUrl);
      } catch (err) {
        console.error(err);
        setStatus(friendlyErrors.viewer_not_supported);
        setPlaceholder(friendlyErrors.viewer_not_supported);
      }
    });

    panel.appendChild(button);
    ui.viewerWrap.appendChild(panel);

    ui.launchPanel = panel;
    ui.launchButton = button;
    return ui.launchPanel;
  }

  function showLaunchPanel() {
    const panel = ensureLaunchPanel();
    panel.hidden = false;
  }

  function hideLaunchPanel() {
    if (ui.launchPanel) ui.launchPanel.hidden = true;
  }

  async function attachViewer(src, posterUrl) {
    if (!ui.viewer) return;

    if (!hasWebGL()) {
      setStatus(friendlyErrors.viewer_not_supported);
      setPlaceholder(friendlyErrors.viewer_not_supported);
      showLaunchPanel();
      return;
    }

    state.currentInteractiveSrc = String(src || '').trim();
    state.currentPosterUrl = String(posterUrl || '').trim();

    if (state.currentPosterUrl) showPreview(state.currentPosterUrl);

    setStatus('Загружаем 3D…');
    setPlaceholder('Загружаем 3D…');
    hideLocationMap();
    hideLaunchPanel();

    if (state.currentPosterUrl) ui.viewer.setAttribute('poster', state.currentPosterUrl);
    else ui.viewer.removeAttribute('poster');

    ui.viewer.style.display = 'block';

    await new Promise((resolve, reject) => {
      let done = false;

      const cleanup = () => {
        if (done) return;
        done = true;
        ui.viewer.removeEventListener('load', onLoad);
        ui.viewer.removeEventListener('error', onError);
      };

      const onLoad = () => {
        cleanup();
        hidePlaceholder();
        hidePreview();
        setStatus('3D-сцена загружена.');
        resolve();
      };

      const onError = () => {
        cleanup();
        setStatus(friendlyErrors.viewer_not_supported);
        setPlaceholder(friendlyErrors.viewer_not_supported);
        showLaunchPanel();
        reject(new Error('viewer_not_supported'));
      };

      ui.viewer.addEventListener('load', onLoad, { once: true });
      ui.viewer.addEventListener('error', onError, { once: true });

      setTimeout(() => {
        if (done) return;
        cleanup();
        setStatus(friendlyErrors.viewer_not_supported);
        setPlaceholder(friendlyErrors.viewer_not_supported);
        showLaunchPanel();
        reject(new Error('viewer_not_supported'));
      }, 20000);

      ui.viewer.removeAttribute('src');
      ui.viewer.setAttribute('src', state.currentInteractiveSrc);
    });
  }

  function fillMeta({ title, kind, codeValue, sceneId, type, access, expiry }) {
    showMeta();

    if (ui.sceneTitle) ui.sceneTitle.textContent = safeText(title);
    if (ui.kind) {
      ui.kind.hidden = false;
      ui.kind.textContent = safeText(kind);
    }
    if (ui.code) ui.code.textContent = safeText(codeValue);
    if (ui.sceneId) ui.sceneId.textContent = safeText(sceneId);
    if (ui.type) ui.type.textContent = safeText(type);
    if (ui.access) ui.access.textContent = safeText(access);
    if (ui.expiry) ui.expiry.textContent = safeText(expiry);
  }

  function offerInteractive(src, posterUrl, message) {
    state.currentInteractiveSrc = String(src || '').trim();
    state.currentPosterUrl = String(posterUrl || '').trim();

    if (state.currentPosterUrl) showPreview(state.currentPosterUrl);
    hideViewer();
    setPlaceholder(message || 'Можно открыть интерактивный viewer.');
    showLaunchPanel();
  }

  function normalizeLocationPayload(payload) {
    const location = payload.location || {};
    const level = payload.level || {};
    const anchor = payload.anchor || {};
    const nodes = Array.isArray(payload.nodes) ? payload.nodes : [];
    const edges = Array.isArray(payload.edges) ? payload.edges : [];
    const pois = Array.isArray(payload.pois) ? payload.pois : [];

    return {
      qr: payload.qr || {},
      scene: payload.scene || null,
      location: {
        id: String(location.id || ''),
        title: safeText(location.title, 'Локация'),
        slug: safeText(location.slug, ''),
        kind: safeText(location.kind, 'location'),
        description: safeText(location.description, ''),
        address: safeText(location.address, ''),
      },
      level: {
        id: String(level.id || ''),
        code: safeText(level.code, ''),
        title: safeText(level.title, 'Этаж'),
        zIndex: toNumber(level.z_index, 0),
      },
      anchor: {
        id: String(anchor.id || ''),
        title: safeText(anchor.title, 'Вы здесь'),
        code: safeText(anchor.code, code || ''),
        kind: safeText(anchor.kind, 'anchor'),
        x: toNumber(anchor.x, 0),
        y: toNumber(anchor.y, 0),
        headingDeg: toNumber(anchor.heading_deg, 0),
        nodeId: String(anchor.node_id || ''),
      },
      nodes: nodes
        .map((node) => ({
          id: String(node.id || ''),
          title: safeText(node.title, 'Узел'),
          kind: safeText(node.kind, ''),
          x: toNumber(node.x, NaN),
          y: toNumber(node.y, NaN),
          z: toNumber(node.z, NaN),
          isActive: node.is_active !== false,
          isPublic: node.is_public !== false,
          accessibilityTags: Array.isArray(node.accessibility_tags) ? node.accessibility_tags : [],
        }))
        .filter((node) => node.id && node.isActive),
      edges: edges
        .map((edge) => ({
          id: String(edge.id || ''),
          fromNodeId: String(edge.from_node_id || ''),
          toNodeId: String(edge.to_node_id || ''),
          kind: safeText(edge.kind, 'walk'),
          distanceM: toNumber(edge.distance_m, NaN),
          durationS: toNumber(edge.duration_s, NaN),
          isBidirectional: edge.is_bidirectional !== false,
          isAccessible: edge.is_accessible !== false,
          isActive: edge.is_active !== false,
          levelChange: toNumber(edge.level_change, 0),
        }))
        .filter((edge) => edge.fromNodeId && edge.toNodeId && edge.isActive),
      pois: pois
        .map((poi) => ({
          id: String(poi.id || ''),
          title: safeText(poi.title, 'POI'),
          slug: safeText(poi.slug, ''),
          kind: safeText(poi.kind, ''),
          brand: safeText(poi.brand, ''),
          description: safeText(poi.description, ''),
          icon: safeText(poi.icon, ''),
          x: toNumber(poi.x, NaN),
          y: toNumber(poi.y, NaN),
          url: safeText(poi.url, ''),
          phone: safeText(poi.phone, ''),
          openingHours: safeText(poi.opening_hours, ''),
          sort: toNumber(poi.sort, 0),
          keywords: Array.isArray(poi.keywords) ? poi.keywords : [],
          isPublic: poi.is_public !== false,
          nodeId: String(poi.node_id || ''),
          sceneId: poi.scene_id == null ? null : String(poi.scene_id),
          instructionId: poi.instruction_id == null ? null : String(poi.instruction_id),
        }))
        .filter((poi) => poi.isPublic),
    };
  }

  function getLocationStorageKey() {
    if (!state.location) return '';
    return `${ROUTE_STATE_KEY_PREFIX}${state.location.location.id}:${state.location.level.id}`;
  }

  function persistRouteState() {
    const key = getLocationStorageKey();
    if (!key) return;

    try {
      window.localStorage.setItem(
        key,
        JSON.stringify({
          selectedPoiId: state.selectedPoiId,
        })
      );
    } catch (_) {}
  }

  function restoreRouteState() {
    if (!state.location) return;

    if (targetParam) {
      state.selectedPoiId = targetParam;
      return;
    }

    const key = getLocationStorageKey();
    if (!key) return;

    try {
      const raw = window.localStorage.getItem(key);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object' && parsed.selectedPoiId) {
        state.selectedPoiId = String(parsed.selectedPoiId);
      }
    } catch (_) {}
  }

  function syncTargetParam() {
    if (!window.history || !window.history.replaceState) return;
    const url = new URL(window.location.href);
    if (state.selectedPoiId) url.searchParams.set('target', state.selectedPoiId);
    else url.searchParams.delete('target');
    window.history.replaceState({}, '', url.toString());
  }

  function getSelectedPoi() {
    if (!state.location || !state.selectedPoiId) return null;

    return state.location.pois.find((poi) => {
      return (
        poi.id === state.selectedPoiId ||
        poi.slug === state.selectedPoiId ||
        slugify(poi.title) === state.selectedPoiId
      );
    }) || null;
  }

  function euclidean(x1, y1, x2, y2) {
    return Math.sqrt(Math.pow(x2 - x1, 2) + Math.pow(y2 - y1, 2));
  }

  function estimateWalkSeconds(distanceM) {
    if (!Number.isFinite(distanceM)) return null;
    return Math.max(15, Math.round(distanceM / 1.25));
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
    return `${Math.round(num / 60)} мин`;
  }

  function buildGraph(nodes, edges, accessibleOnly = false) {
    const nodeMap = new Map(nodes.map((node) => [node.id, node]));
    const adjacency = new Map();

    nodes.forEach((node) => adjacency.set(node.id, []));

    edges.forEach((edge) => {
      if (!nodeMap.has(edge.fromNodeId) || !nodeMap.has(edge.toNodeId)) return;
      if (accessibleOnly && edge.isAccessible === false) return;

      const from = nodeMap.get(edge.fromNodeId);
      const to = nodeMap.get(edge.toNodeId);

      const distance = Number.isFinite(edge.distanceM)
        ? edge.distanceM
        : (
            Number.isFinite(from.x) &&
            Number.isFinite(from.y) &&
            Number.isFinite(to.x) &&
            Number.isFinite(to.y)
          )
          ? euclidean(from.x, from.y, to.x, to.y)
          : 1;

      const duration = Number.isFinite(edge.durationS) ? edge.durationS : (estimateWalkSeconds(distance) || 1);

      adjacency.get(edge.fromNodeId).push({
        to: edge.toNodeId,
        edge,
        distance,
        duration,
        weight: duration,
      });

      if (edge.isBidirectional) {
        adjacency.get(edge.toNodeId).push({
          to: edge.fromNodeId,
          edge,
          distance,
          duration,
          weight: duration,
        });
      }
    });

    return { nodeMap, adjacency };
  }

  function dijkstra(graph, startId, endId) {
    const { nodeMap, adjacency } = graph;
    if (!nodeMap.has(startId) || !nodeMap.has(endId)) return null;

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

    if (startId !== endId && !prev.has(endId)) return null;

    const nodeIds = [endId];
    const edgeItems = [];
    let cursor = endId;

    while (cursor !== startId) {
      const previous = prev.get(cursor);
      const item = prevEdge.get(cursor);
      if (!previous || !item) break;
      nodeIds.push(previous);
      edgeItems.push(item);
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

  function buildRouteSteps(edgeItems, nodeMap, poiTitle) {
    if (!edgeItems.length) return [`Финиш: «${poiTitle}».`];

    return edgeItems.map((item, index) => {
      const node = nodeMap.get(item.to);
      const targetTitle = node?.title || `узел ${item.to}`;

      const verbByKind = {
        walk: 'Идите',
        stairs: 'Поднимитесь/спуститесь по лестнице к',
        elevator: 'Используйте лифт до',
        escalator: 'Используйте эскалатор до',
        ramp: 'Идите по пандусу к',
      };

      const verb = verbByKind[item.edge.kind] || 'Перейдите к';
      const suffix = item.distance ? ` (${formatMeters(item.distance)})` : '';

      if (index === edgeItems.length - 1) {
        return `${verb} «${targetTitle}»${suffix}. Затем цель: «${poiTitle}».`;
      }

      return `${verb} «${targetTitle}»${suffix}.`;
    });
  }

  function computeRoute() {
    if (!state.location) return null;

    const anchor = state.location.anchor;
    const poi = getSelectedPoi();
    if (!poi) return null;

    if (
      anchor.nodeId &&
      poi.nodeId &&
      Array.isArray(state.location.nodes) &&
      state.location.nodes.length &&
      Array.isArray(state.location.edges) &&
      state.location.edges.length
    ) {
      const graph = buildGraph(state.location.nodes, state.location.edges, false);
      const result = dijkstra(graph, anchor.nodeId, poi.nodeId);

      if (result) {
        return {
          mode: 'graph',
          poi,
          points: result.nodeIds
            .map((id) => graph.nodeMap.get(id))
            .filter(Boolean)
            .map((node) => ({ x: node.x, y: node.y, title: node.title })),
          distanceM: result.distanceM,
          durationS: result.durationS,
          steps: buildRouteSteps(result.edgeItems, graph.nodeMap, poi.title),
        };
      }
    }

    if (
      Number.isFinite(anchor.x) &&
      Number.isFinite(anchor.y) &&
      Number.isFinite(poi.x) &&
      Number.isFinite(poi.y)
    ) {
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
          `Идите к «${poi.title}» примерно ${formatMeters(distanceM)}.`,
          `Финиш: «${poi.title}».`,
        ],
      };
    }

    return {
      mode: 'info',
      poi,
      points: [],
      distanceM: null,
      durationS: null,
      steps: [`Выберите «${poi.title}». Для точного маршрута пока недостаточно координат.`],
    };
  }

  function ensureRoutePanel() {
    if (ui.routePanel || !ui.locationCard) return ui.routePanel;

    const panel = document.createElement('div');
    panel.id = 'vp3d-route-panel';
    panel.className = 'vp-3d-field';
    panel.style.marginTop = '12px';
    ui.locationCard.appendChild(panel);

    ui.routePanel = panel;
    return ui.routePanel;
  }

  function ensureToolbar() {
    if (ui.toolbar || !ui.locationCard) return ui.toolbar;

    const wrap = document.createElement('div');
    wrap.id = 'vp3d-location-toolbar';
    wrap.style.display = 'grid';
    wrap.style.gap = '10px';
    wrap.style.marginTop = '12px';

    const row = document.createElement('div');
    row.style.display = 'flex';
    row.style.gap = '10px';
    row.style.flexWrap = 'wrap';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'vp-3d-input';
    search.placeholder = 'Найти POI';

    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'vp-3d-btn';
    clear.textContent = 'Сбросить маршрут';

    row.appendChild(search);
    row.appendChild(clear);
    wrap.appendChild(row);

    if (ui.poiList && ui.poiList.parentNode) {
      ui.poiList.parentNode.insertBefore(wrap, ui.poiList);
    } else {
      ui.locationCard.appendChild(wrap);
    }

    search.addEventListener('input', () => renderLocationUI());
    clear.addEventListener('click', () => {
      state.selectedPoiId = null;
      state.currentRoute = null;
      syncTargetParam();
      persistRouteState();
      renderLocationUI();
      setStatus('Маршрут сброшен.');
    });

    ui.toolbar = wrap;
    ui.searchInput = search;
    ui.clearButton = clear;

    return ui.toolbar;
  }

  function getFilteredPois() {
    if (!state.location) return [];
    const term = String(ui.searchInput?.value || '').trim().toLowerCase();

    return state.location.pois
      .filter((poi) => {
        if (!term) return true;
        const haystack = [poi.title, poi.kind, poi.brand, poi.description]
          .concat(poi.keywords || [])
          .join(' ')
          .toLowerCase();
        return haystack.includes(term);
      })
      .sort((a, b) => a.sort - b.sort || a.title.localeCompare(b.title, 'ru'));
  }

  function renderPoiList(filteredPois) {
    if (!ui.poiList) return;

    ui.poiList.innerHTML = '';

    if (!filteredPois.length) {
      const li = document.createElement('li');
      li.className = 'vp-3d-location-empty';
      li.textContent = 'POI не найдены.';
      ui.poiList.appendChild(li);
      return;
    }

    filteredPois.forEach((poi) => {
      const li = document.createElement('li');
      li.className = 'vp-3d-location-item';
      li.style.cursor = 'pointer';
      li.style.borderWidth = state.selectedPoiId === poi.id ? '2px' : '1px';
      li.style.borderColor = state.selectedPoiId === poi.id ? '#2563eb' : 'var(--vp-ui-line)';
      li.tabIndex = 0;
      li.setAttribute('role', 'button');

      li.innerHTML = `
        <div class="vp-3d-location-item-title">${escapeHtml(poi.title)}</div>
        <div class="vp-3d-location-item-meta">${escapeHtml(poi.kind)}</div>
        ${poi.description ? `<div class="vp-3d-location-item-meta">${escapeHtml(poi.description)}</div>` : ''}
      `;

      const activate = () => {
        state.selectedPoiId = poi.id;
        state.currentRoute = computeRoute();
        syncTargetParam();
        persistRouteState();
        renderLocationUI();
      };

      li.addEventListener('click', activate);
      li.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          activate();
        }
      });

      ui.poiList.appendChild(li);
    });
  }

  function renderRoutePanel() {
    const panel = ensureRoutePanel();
    const poi = getSelectedPoi();

    if (!poi) {
      panel.innerHTML = `
        <div class="vp-3d-label">Маршрут</div>
        <div class="vp-3d-value">Выберите точку назначения из списка ниже.</div>
      `;
      return;
    }

    state.currentRoute = computeRoute();
    const route = state.currentRoute;

    const stepsHtml = Array.isArray(route?.steps) && route.steps.length
      ? `<ol style="margin:10px 0 0 18px;padding:0;display:grid;gap:6px;">${route.steps
          .map((step) => `<li>${escapeHtml(step)}</li>`)
          .join('')}</ol>`
      : '<div class="vp-3d-value">Шаги недоступны.</div>';

    panel.innerHTML = `
      <div class="vp-3d-label">Маршрут до цели</div>
      <div class="vp-3d-value" style="margin-bottom:8px;">${escapeHtml(poi.title)}</div>
      <div class="vp-3d-grid" style="margin-top:0;">
        <div class="vp-3d-field">
          <div class="vp-3d-label">Режим</div>
          <div class="vp-3d-value">${escapeHtml(route?.mode || 'info')}</div>
        </div>
        <div class="vp-3d-field">
          <div class="vp-3d-label">Дистанция</div>
          <div class="vp-3d-value">${formatMeters(route?.distanceM)}</div>
        </div>
        <div class="vp-3d-field">
          <div class="vp-3d-label">Время</div>
          <div class="vp-3d-value">${formatSeconds(route?.durationS)}</div>
        </div>
        <div class="vp-3d-field">
          <div class="vp-3d-label">Категория</div>
          <div class="vp-3d-value">${escapeHtml(poi.kind)}</div>
        </div>
      </div>
      <div style="margin-top:10px;">
        <div class="vp-3d-label">Шаги</div>
        ${stepsHtml}
      </div>
    `;
  }

  function ensureMapCanvas() {
    if (ui.mapCanvas || !ui.viewerWrap) return ui.mapCanvas;

    const canvas = document.createElement('canvas');
    canvas.id = 'vp3d-location-canvas';

    Object.assign(canvas.style, {
      position: 'absolute',
      inset: '0',
      width: '100%',
      height: '100%',
      zIndex: '4',
      pointerEvents: 'none',
      display: 'none',
    });

    ui.viewerWrap.appendChild(canvas);
    ui.mapCanvas = canvas;
    return ui.mapCanvas;
  }

  function showLocationMap() {
    const canvas = ensureMapCanvas();
    canvas.style.display = 'block';
  }

  function hideLocationMap() {
    if (ui.mapCanvas) ui.mapCanvas.style.display = 'none';
  }
  
  function buildThreeLocationPayload() {
    if (!state.location) return null;

    return {
      location: state.location.location,
      level: state.location.level,
      anchor: state.location.anchor,
      nodes: state.location.nodes,
      edges: state.location.edges,
      pois: state.location.pois,
      selectedPoiId: state.selectedPoiId,
      route: state.currentRoute,
    };
  }

  async function tryInitThreeRuntime() {
    if (state.threeRuntimeReady || state.threeRuntimeFailed) return;
    if (!THREE_RUNTIME_URL || !ui.viewerWrap) {
      state.threeRuntimeFailed = true;
      return;
    }

    try {
      const mod = await import(THREE_RUNTIME_URL);
      if (!mod || typeof mod.createVpThreeRuntime !== 'function') {
        throw new Error('three_runtime_invalid');
      }

      const runtime = mod.createVpThreeRuntime({
        container: ui.viewerWrap,
        onPoiSelect: (poiId) => {
          if (!poiId) return;
          state.selectedPoiId = String(poiId);
          state.currentRoute = computeRoute();
          syncTargetParam();
          persistRouteState();
          renderLocationUI();
          setStatus('POI выбран в 3D-режиме.');
        },
      });

      const payload = buildThreeLocationPayload();
      const mounted = runtime.mount(payload);
      if (!mounted) {
        throw new Error('three_runtime_mount_failed');
      }

      state.threeRuntime = runtime;
      state.threeRuntimeReady = true;
      state.threeRuntimeFailed = false;
      if (state.threeRuntime && typeof state.threeRuntime.resize === 'function') {
        state.threeRuntime.resize();
      }
      hideLocationMap();
    } catch (err) {
      console.warn('VP three runtime init failed', err);
      state.threeRuntimeFailed = true;
      state.threeRuntimeReady = false;
      state.threeRuntime = null;
    }
  }

  function updateThreeRuntime() {
    if (!state.threeRuntimeReady || !state.threeRuntime) return false;
    try {
      const payload = buildThreeLocationPayload();
      const updated = state.threeRuntime.update(payload);
      if (!updated) return false;
      hideLocationMap();
      return true;
    } catch (err) {
      console.warn('VP three runtime update failed', err);
      state.threeRuntimeFailed = true;
      state.threeRuntimeReady = false;
      state.threeRuntime = null;
      return false;
    }
  }

  function resetThreeRuntimeCamera() {
    if (!state.threeRuntimeReady || !state.threeRuntime || typeof state.threeRuntime.resetCamera !== 'function') {
      return false;
    }

    try {
      return !!state.threeRuntime.resetCamera();
    } catch (err) {
      console.warn('VP three runtime camera reset failed', err);
      return false;
    }
  }

  function destroyThreeRuntime() {
    if (!state.threeRuntime) return;

    try {
      if (typeof state.threeRuntime.destroy === 'function') {
        state.threeRuntime.destroy();
      }
    } catch (err) {
      console.warn('VP three runtime destroy failed', err);
    }

    state.threeRuntime = null;
    state.threeRuntimeReady = false;
  }

  

  function renderLocationMap(filteredPois) {
    const canvas = ensureMapCanvas();
    if (!canvas || !state.location || !ui.viewerWrap) return;

    const width = Math.max(ui.viewerWrap.clientWidth || 320, 320);
    const height = Math.max(ui.viewerWrap.clientHeight || 320, 320);

    canvas.width = width;
    canvas.height = height;

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    ctx.clearRect(0, 0, width, height);

    const anchor = state.location.anchor;
    const route = state.currentRoute;
    const points = [];

    if (Number.isFinite(anchor.x) && Number.isFinite(anchor.y)) {
      points.push({ x: anchor.x, y: anchor.y });
    }

    filteredPois.forEach((poi) => {
      if (Number.isFinite(poi.x) && Number.isFinite(poi.y)) {
        points.push({ x: poi.x, y: poi.y });
      }
    });

    if (route?.points?.length) {
      route.points.forEach((point) => {
        if (Number.isFinite(point.x) && Number.isFinite(point.y)) {
          points.push({ x: point.x, y: point.y });
        }
      });
    }

    let minX = 0;
    let minY = 0;
    let maxX = 1;
    let maxY = 1;

    if (points.length) {
      minX = Math.min(...points.map((p) => p.x));
      minY = Math.min(...points.map((p) => p.y));
      maxX = Math.max(...points.map((p) => p.x));
      maxY = Math.max(...points.map((p) => p.y));
      if (minX === maxX) maxX += 1;
      if (minY === maxY) maxY += 1;
    }

    const margin = 36;
    const scaleX = (width - margin * 2) / (maxX - minX || 1);
    const scaleY = (height - margin * 2) / (maxY - minY || 1);
    const scale = Math.min(scaleX, scaleY);

    const px = (x) => margin + (x - minX) * scale;
    const py = (y) => height - margin - (y - minY) * scale;

    ctx.fillStyle = 'rgba(15, 23, 42, 0.035)';
    ctx.fillRect(0, 0, width, height);

    if (route?.points?.length >= 2) {
      ctx.strokeStyle = '#2563eb';
      ctx.lineWidth = 4;
      ctx.beginPath();
      route.points.forEach((point, index) => {
        const x = px(point.x);
        const y = py(point.y);
        if (index === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
      });
      ctx.stroke();
    }

    filteredPois.forEach((poi, index) => {
      if (!Number.isFinite(poi.x) || !Number.isFinite(poi.y)) return;

      const selected = state.selectedPoiId === poi.id;
      const x = px(poi.x);
      const y = py(poi.y);

      ctx.fillStyle = selected ? '#2563eb' : '#dc2626';
      ctx.beginPath();
      ctx.arc(x, y, selected ? 7 : 5, 0, Math.PI * 2);
      ctx.fill();

      if (selected || index < 8) {
        ctx.fillStyle = '#0f172a';
        ctx.font = selected ? '600 13px sans-serif' : '12px sans-serif';
        ctx.fillText(poi.title, x + 8, y - 8);
      }
    });

    if (Number.isFinite(anchor.x) && Number.isFinite(anchor.y)) {
      const x = px(anchor.x);
      const y = py(anchor.y);

      ctx.fillStyle = '#16a34a';
      ctx.beginPath();
      ctx.arc(x, y, 8, 0, Math.PI * 2);
      ctx.fill();

      ctx.fillStyle = '#166534';
      ctx.font = '600 13px sans-serif';
      ctx.fillText(anchor.title || 'Вы здесь', x + 10, y - 12);
    }

    showLocationMap();
  }

  function renderLocationUI() {
    if (!state.location) return;

    showLocationCard();
    ensureToolbar();

    if (ui.locationTitle) ui.locationTitle.textContent = state.location.location.title;
    if (ui.levelTitle) ui.levelTitle.textContent = state.location.level.title || state.location.level.code;
    if (ui.anchorTitle) ui.anchorTitle.textContent = state.location.anchor.title;
    if (ui.poiCount) ui.poiCount.textContent = String(state.location.pois.length);

    const filteredPois = getFilteredPois();
    renderPoiList(filteredPois);
    renderRoutePanel();
    
    if (!updateThreeRuntime()) {
      renderLocationMap(filteredPois);
    }

  }

  function bindAuthForm() {
    if (!ui.authForm) return;

    ui.authForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      const password = String(ui.password?.value || '').trim();
      if (!password || !code) {
        setStatus('Введите пароль.');
        return;
      }

      try {
        setStatus('Проверяем пароль…');

        const auth = await apiJson(AUTH_URL, 'POST', {
          code,
          password,
        });

        const token = String(auth.token || '').trim();
        if (!token) throw new Error('token_invalid');

        const url = `${FILE_URL}?code=${encodeURIComponent(code)}&token=${encodeURIComponent(token)}`;

        if (ui.authWrap) ui.authWrap.hidden = true;

        offerInteractive(
          url,
          state.scene?.poster_url || '',
          'Пароль принят. Нажмите кнопку ниже, чтобы открыть 3D.'
        );
      } catch (err) {
        console.error(err);
        const message = resolveError(err);
        setStatus(message);
        setPlaceholder(message);
      }
    });
  }

  function bindFabActions() {
    document.addEventListener('click', async (event) => {
      const actionEl = event.target.closest('[data-vp-action]');
      if (!actionEl) return;

      const action = actionEl.getAttribute('data-vp-action');
      if (!action) return;

      if (action === 'backToScan') {
        window.location.assign('/scan/');
        return;
      }

      if (action === 'share') {
        try {
          await navigator.clipboard.writeText(window.location.href);
          setStatus('Ссылка скопирована.');
        } catch (_) {
          setStatus(window.location.href);
        }
        return;
      }

      if (action === 'copyCode') {
        const value = code || (jobId > 0 ? `job:${jobId}` : '');
        if (!value) return;

        try {
          await navigator.clipboard.writeText(value);
          setStatus('Код скопирован.');
        } catch (_) {
          setStatus(value);
        }
        return;
      }

      if (action === 'reloadModel') {
        if (state.mode === 'location') {
          renderLocationUI();
          setStatus('Карта и маршрут обновлены.');
          return;
        }

        if (state.currentInteractiveSrc) {
          try {
            await attachViewer(state.currentInteractiveSrc, state.currentPosterUrl);
          } catch (err) {
            console.error(err);
            setStatus(resolveError(err));
          }
        }
        return;
      }

      if (action === 'resetView') {
        if (state.mode === 'location') {
          const cameraReset = resetThreeRuntimeCamera();
          state.selectedPoiId = null;
          state.currentRoute = null;
          syncTargetParam();
          persistRouteState();
          renderLocationUI();
          setStatus(cameraReset ? 'Камера и маршрут сброшены.' : 'Маршрут сброшен.');
          return;
        }

        hideViewer();
        if (state.currentPosterUrl) showPreview(state.currentPosterUrl);
        setPlaceholder('Вид сброшен. Можно открыть viewer заново.');
        showLaunchPanel();
      }
    });
  }

  async function initSceneMode(item) {
    destroyThreeRuntime();
    state.mode = 'scene';
    state.scene = item.scene || null;

    if (!state.scene) {
      setStatus(friendlyErrors.scene_not_linked);
      setPlaceholder(friendlyErrors.scene_not_linked);
      return;
    }

    fillMeta({
      title: state.scene.title || item.title || `QR ${code}`,
      kind: state.scene.kind || '3d',
      codeValue: code,
      sceneId: state.scene.id || '—',
      type: item.type || '3d',
      access: state.scene.requires_password ? 'По паролю' : 'Открытый',
      expiry: state.scene.expires_at || 'без ограничения',
    });

    hideLocationCard();

    if (state.scene.poster_url) showPreview(state.scene.poster_url);

    if (state.scene.requires_password) {
      if (ui.authWrap) ui.authWrap.hidden = false;
      if (ui.hint) {
        ui.hint.hidden = false;
        ui.hint.textContent = state.scene.password_hint
          ? `Подсказка: ${state.scene.password_hint}`
          : 'Введите пароль.';
      }

      setStatus('Сцена защищена паролем.');
      setPlaceholder('Введите пароль, чтобы открыть сцену.');
      hideLaunchPanel();
      return;
    }

    offerInteractive(
      state.scene.model_url || '',
      state.scene.poster_url || '',
      'Показываем безопасное превью. Нажмите кнопку ниже, чтобы открыть 3D.'
    );

    setStatus('Сцена готова к открытию.');
  }

  async function initLocationMode() {
    state.mode = 'location';

    const url = new URL(LOCATION_BOOTSTRAP_URL, window.location.origin);
    url.searchParams.set('code', code);

    const payload = await apiJson(url.toString());
    if (!payload.ok) {
      throw new Error(payload.error || 'request_failed');
    }

    state.location = normalizeLocationPayload(payload);

    restoreRouteState();

    if (!getSelectedPoi() && state.location.pois.length) {
      state.selectedPoiId = state.location.pois[0].id;
    }

    fillMeta({
      title: state.location.location.title,
      kind: 'location',
      codeValue: code,
      sceneId: state.location.level.code || state.location.level.title,
      type: 'location',
      access: 'Indoor runtime',
      expiry: '—',
    });

    hideViewer();
    hideLaunchPanel();
    hidePlaceholder();
    hidePreview();
    
    await tryInitThreeRuntime();
    renderLocationUI();
    persistRouteState();
    syncTargetParam();

    setStatus('Навигация готова.');
  }

  async function initJobMode() {
    destroyThreeRuntime();
    state.mode = 'job';

    let job = PREFETCH.job || null;

    if (!job && jobId > 0) {
      const url = new URL(JOB_STATUS_URL, window.location.origin);
      url.searchParams.set('job_id', String(jobId));
      const payload = await apiJson(url.toString());
      job = payload.job || null;
    }

    if (!job || !job.id) {
      setStatus('Статус job недоступен.');
      setPlaceholder('Статус job недоступен.');
      return;
    }

    state.job = job;

    fillMeta({
      title: `3D Job #${job.id}`,
      kind: 'job',
      codeValue: `job:${job.id}`,
      sceneId: job.result_glb_wp_id || '—',
      type: 'converter',
      access: 'Signed /dl link',
      expiry: 'runtime',
    });

    hideLocationCard();
    if (ui.authWrap) ui.authWrap.hidden = true;

    if (job.viewer_preview_url) {
      showPreview(job.viewer_preview_url);
      state.currentPosterUrl = job.viewer_preview_url;
    }

    if (job.status !== 'completed') {
      setStatus(`Job ${job.status || 'pending'}`);
      setPlaceholder(friendlyErrors.job_pending);
      hideLaunchPanel();
      return;
    }

    if (!job.viewer_glb_url) {
      setStatus(friendlyErrors.job_model_missing);
      setPlaceholder(friendlyErrors.job_model_missing);
      return;
    }

    offerInteractive(
      job.viewer_glb_url,
      job.viewer_preview_url || '',
      'Показываем безопасное превью. Нажмите кнопку ниже, чтобы открыть 3D.'
    );

    setStatus('Job готов к просмотру.');
  }

  async function initCodeMode() {
    if (!code) {
      setStatus(friendlyErrors.code_required);
      setPlaceholder(friendlyErrors.code_required);
      return;
    }

    const url = new URL(LOOKUP_URL, window.location.origin);
    url.searchParams.set('code', code);

    const lookup = await apiJson(url.toString());
    const item = Array.isArray(lookup.data) && lookup.data.length ? lookup.data[0] : null;

    if (!item) {
      throw new Error('lookup_empty');
    }

    const type = String(item.type || '').trim().toLowerCase();

    if (type === 'location') {
      await initLocationMode();
      return;
    }

    await initSceneMode(item);
  }

  async function bootstrap() {
    bindElements();
    bindAuthForm();
    bindFabActions();
    ensurePreview();
    ensureLaunchPanel();
    ensureRoutePanel();
    ensureToolbar();
    ensureMapCanvas();

    if (!ui.viewerWrap || !ui.placeholder || !ui.status) return;

    try {
      setStatus('Инициализация…');

      if (jobId > 0) {
        await initJobMode();
      } else {
        await initCodeMode();
      }
    } catch (err) {
      console.error(err);
      const message = resolveError(err);
      setStatus(message);
      setPlaceholder(message);
      hideViewer();
      hideLocationCard();
    }

    window.addEventListener('resize', () => {
      if (state.mode === 'location' && state.location) {
        if (state.threeRuntimeReady && state.threeRuntime && typeof state.threeRuntime.resize === 'function') {
          state.threeRuntime.resize();
        }
        renderLocationUI();
      }
    });

    window.addEventListener('beforeunload', () => {
      destroyThreeRuntime();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
  } else {
    bootstrap();
  }
})();
