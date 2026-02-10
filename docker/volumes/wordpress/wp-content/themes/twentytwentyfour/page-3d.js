(() => {
  'use strict';

  const LOOKUP_URL = (window.VP_3D && window.VP_3D.lookupUrl) || '/wp-json/vp/v1/lookup';

  const $ = (id) => document.getElementById(id);
  const elStatus = $('vp3d-status');
  const elError = $('vp3d-error');
  const elResultCard = $('vp3d-result-card');
  const elViewCard = $('vp3d-view-card');
  const elTitle = $('vp3d-title');
  const elSub = $('vp3d-sub');
  const elType = $('vp3d-type');
  const elMeta = $('vp3d-meta');
  const elLinks = $('vp3d-links');
  const elViewPlaceholder = $('vp3d-view-placeholder');

  const params = new URLSearchParams(window.location.search);
  const code = String(params.get('code') || '').trim().toUpperCase();

  function setStatus(text) {
    if (elStatus) elStatus.textContent = text;
  }

  function showError(text) {
    setStatus('Не удалось загрузить данные');
    if (elError) {
      elError.hidden = false;
      elError.textContent = text;
    }
    if (elResultCard) elResultCard.hidden = true;
    if (elViewCard) elViewCard.hidden = true;
  }

  function escapeHtml(value) {
    return String(value || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function normalizeType(raw) {
    return String(raw || '').trim().toLowerCase();
  }

  function isNavigationType(rawType) {
    const t = normalizeType(rawType);
    return ['3d', '3d/navigation', 'navigation', 'nav', 'location'].includes(t);
  }

  function getNavigationPayload(item) {
    // Ожидаем lookup.data[0] c полями от Directus (где часть полей может отсутствовать):
    // {
    //   type: '3d'|'navigation'|..., title, code,
    //   location_payload: { scene_url, model_url, route_id, mall_id, start_point, end_point, floor, landmarks, description },
    //   service_payload: { ...fallback payload... },
    //   product_id: { title, brand, model, sku }
    // }
    const locationPayload = item && typeof item.location_payload === 'object' ? item.location_payload : null;
    const servicePayload = item && typeof item.service_payload === 'object' ? item.service_payload : null;
    return locationPayload || servicePayload || {};
  }

  function renderMeta(rows) {
    if (!elMeta) return;
    elMeta.innerHTML = rows
      .filter((row) => row && row.value)
      .map(
        (row) =>
          `<div class="vp-3d-meta-item"><span class="vp-3d-meta-label">${escapeHtml(row.label)}</span><span class="vp-3d-meta-value">${escapeHtml(row.value)}</span></div>`
      )
      .join('');
  }

  function renderLinks(payload) {
    if (!elLinks) return;
    const linkRows = [
      { label: 'Открыть scene_url', url: payload.scene_url },
      { label: 'Открыть model_url', url: payload.model_url },
      { label: 'Открыть route_url', url: payload.route_url },
      { label: 'Открыть карту', url: payload.map_url },
    ].filter((row) => /^https?:\/\//i.test(String(row.url || '')));

    if (!linkRows.length) {
      elLinks.innerHTML = '';
      return;
    }

    elLinks.innerHTML = linkRows
      .map(
        (row) => `<a class="vp-3d-btn vp-3d-btn--primary" href="${escapeHtml(row.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.label)}</a>`
      )
      .join('');
  }

  async function apiLookup(codeValue) {
    const url = new URL(LOOKUP_URL, window.location.origin);
    url.searchParams.set('code', codeValue);

    const response = await fetch(url.toString(), {
      method: 'GET',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const err = new Error('lookup_failed');
      err.status = response.status;
      err.body = body;
      throw err;
    }

    return body;
  }

  async function init() {
    if (!code) {
      showError('В URL нет параметра code. Вернитесь на сканер и откройте QR повторно.');
      return;
    }

    try {
      setStatus('Загружаем 3D/навигационные данные…');
      const lookup = await apiLookup(code);
      const item = Array.isArray(lookup?.data) && lookup.data.length ? lookup.data[0] : null;

      if (!item) {
        showError('Код не найден в базе данных.');
        return;
      }

      if (!isNavigationType(item.type || item.kind)) {
        showError('Этот QR относится к другому типу. Для него используйте страницу инструкции.');
        return;
      }

      const payload = getNavigationPayload(item);
      const hasCoreData = Boolean(
        payload.scene_url || payload.model_url || payload.route_id || payload.route_url || payload.map_url
      );

      if (!hasCoreData) {
        showError('Для этого QR пока не заполнены 3D/навигационные поля (scene_url, model_url, route_id и т.д.).');
        return;
      }

      const title = item.title || item.product_id?.title || `QR ${code}`;
      const sub = [item.product_id?.brand, item.product_id?.model, item.product_id?.sku].filter(Boolean).join(' • ');

      if (elError) elError.hidden = true;
      if (elResultCard) elResultCard.hidden = false;
      if (elViewCard) elViewCard.hidden = false;

      if (elTitle) elTitle.textContent = title;
      if (elSub) elSub.textContent = sub || 'Навигационный сценарий';
      if (elType) elType.textContent = item.type || item.kind || 'navigation';
      if (elViewPlaceholder) {
        elViewPlaceholder.textContent =
          'Плейсхолдер готов. Подключите локальный 3D/viewer-движок и используйте scene_url/model_url/route_id из payload.';
      }

      renderMeta([
        { label: 'Код', value: code },
        { label: 'Маршрут (route_id)', value: payload.route_id },
        { label: 'ТЦ/объект (mall_id)', value: payload.mall_id },
        { label: 'Старт', value: payload.start_point || payload.start },
        { label: 'Финиш', value: payload.end_point || payload.finish_point || payload.destination },
        { label: 'Этаж', value: payload.floor },
      ]);

      renderLinks(payload);
      setStatus('Данные загружены.');
    } catch (error) {
      console.error(error);
      showError('Ошибка загрузки данных. Проверьте код и попробуйте снова.');
    }
  }

  init();
})();
