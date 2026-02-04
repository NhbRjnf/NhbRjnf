<?php
/* Template Name: VP Instruction */
get_header();
?>
<main id="vp-instruction">
  <div id="vp-inst-header">
    <div class="vp-inst-hero">
      <div class="vp-inst-hero-text">
        <div class="vp-inst-hero-topline">
          <div class="vp-inst-eyebrow" id="vp-inst-eyebrow">Инструкция</div>
          <span class="vp-inst-type" id="vp-inst-type-badge">product</span>
        </div>
        <h1>Инструкция</h1>
        <div id="vp-inst-sub"></div>
        <div class="vp-inst-chips" id="vp-inst-chips" aria-label="Параметры товара"></div>
      </div>
      <div class="vp-inst-hero-actions" aria-label="Действия">
        <button class="vp-inst-btn vp-inst-btn--primary" id="vp-inst-open-btn" type="button">
          Открыть инструкцию
        </button>
        <button class="vp-inst-btn" id="vp-inst-share-btn" type="button">Поделиться</button>
        <button class="vp-inst-btn" id="vp-inst-copy-btn" type="button">Скопировать код</button>
        <a class="vp-inst-btn vp-inst-btn--ghost" id="vp-inst-back-btn" href="/scan">Назад к сканеру</a>
      </div>
    </div>
  </div>

  <div id="vp-inst-loading" class="vp-inst-loading">
    <div class="vp-inst-skeleton vp-inst-skeleton--hero"></div>
    <div class="vp-inst-grid">
      <section class="vp-inst-card vp-inst-skeleton-card"></section>
      <section class="vp-inst-card vp-inst-skeleton-card"></section>
      <section class="vp-inst-card vp-inst-card--full vp-inst-skeleton-card"></section>
    </div>
  </div>
  <div id="vp-inst-error" style="display:none; color: #b00020;"></div>

  <div id="vp-inst-grid" class="vp-inst-grid" style="display:none;">
    <section class="vp-inst-card" id="vp-inst-product">
      <div class="vp-inst-card-head">
        <span class="vp-inst-anchor"></span>
        <h2>Товар</h2>
      </div>
      <dl class="vp-inst-meta">
        <div>
          <dt>Бренд</dt>
          <dd id="vp-inst-brand">—</dd>
        </div>
        <div>
          <dt>Модель</dt>
          <dd id="vp-inst-model">—</dd>
        </div>
        <div>
          <dt>SKU</dt>
          <dd id="vp-inst-sku">—</dd>
        </div>
      </dl>
    </section>

    <section class="vp-inst-card" id="vp-inst-description">
      <div class="vp-inst-card-head">
        <span class="vp-inst-anchor"></span>
        <h2>Описание</h2>
      </div>
      <p id="vp-inst-description-text">Описание загружается…</p>
    </section>

    <section class="vp-inst-card vp-inst-card--full" id="vp-inst-navigation" style="display:none;">
      <div class="vp-inst-card-head">
        <span class="vp-inst-anchor"></span>
        <h2>Навигация</h2>
      </div>
      <div class="vp-inst-nav-card">
        <div class="vp-inst-nav-meta">
          <div>
            <div class="vp-inst-nav-label">Старт</div>
            <div class="vp-inst-nav-value" id="vp-inst-nav-start">—</div>
          </div>
          <div>
            <div class="vp-inst-nav-label">Финиш</div>
            <div class="vp-inst-nav-value" id="vp-inst-nav-end">—</div>
          </div>
          <div>
            <div class="vp-inst-nav-label">Ориентиры</div>
            <div class="vp-inst-nav-value" id="vp-inst-nav-landmarks">—</div>
          </div>
        </div>
        <div class="vp-inst-nav-media" id="vp-inst-nav-media"></div>
        <div class="vp-inst-nav-actions" id="vp-inst-nav-actions"></div>
        <div class="vp-inst-nav-steps" id="vp-inst-nav-steps"></div>
        <div class="vp-inst-nav-fallback" id="vp-inst-nav-fallback" style="display:none;">
          <div class="vp-inst-nav-fallback-text">Навигационные данные пока недоступны.</div>
          <button class="vp-inst-btn vp-inst-btn--ghost" id="vp-inst-nav-report" type="button">
            Сообщить о проблеме
          </button>
        </div>
      </div>
    </section>

    <section class="vp-inst-card vp-inst-card--full" id="vp-inst-instruction">
      <div class="vp-inst-card-head">
        <span class="vp-inst-anchor"></span>
        <h2>Инструкция</h2>
      </div>
      <ol id="vp-inst-steps" style="display:none;"></ol>
    </section>
  </div>

  <div class="vp-inst-sticky" id="vp-inst-sticky" aria-label="Быстрые действия">
    <button class="vp-inst-btn vp-inst-btn--primary" id="vp-inst-open-btn-sticky" type="button">
      Открыть
    </button>
    <button class="vp-inst-btn" id="vp-inst-share-btn-sticky" type="button">Поделиться</button>
    <button class="vp-inst-btn" id="vp-inst-copy-btn-sticky" type="button">Код</button>
    <a class="vp-inst-btn vp-inst-btn--ghost" id="vp-inst-back-btn-sticky" href="/scan">Сканер</a>
  </div>
</main>

<script>
(function () {
  const params = new URLSearchParams(location.search);
  const code = params.get('code');

  const elLoading = document.getElementById('vp-inst-loading');
  const elError = document.getElementById('vp-inst-error');
  const elGrid = document.getElementById('vp-inst-grid');
  const elSteps = document.getElementById('vp-inst-steps');
  const elSub = document.getElementById('vp-inst-sub');
  const elEyebrow = document.getElementById('vp-inst-eyebrow');
  const elTypeBadge = document.getElementById('vp-inst-type-badge');
  const elChips = document.getElementById('vp-inst-chips');
  const elBrand = document.getElementById('vp-inst-brand');
  const elModel = document.getElementById('vp-inst-model');
  const elSku = document.getElementById('vp-inst-sku');
  const elDescription = document.getElementById('vp-inst-description-text');
  const elNavigation = document.getElementById('vp-inst-navigation');
  const elNavStart = document.getElementById('vp-inst-nav-start');
  const elNavEnd = document.getElementById('vp-inst-nav-end');
  const elNavLandmarks = document.getElementById('vp-inst-nav-landmarks');
  const elNavMedia = document.getElementById('vp-inst-nav-media');
  const elNavActions = document.getElementById('vp-inst-nav-actions');
  const elNavSteps = document.getElementById('vp-inst-nav-steps');
  const elNavFallback = document.getElementById('vp-inst-nav-fallback');
  const elNavReport = document.getElementById('vp-inst-nav-report');
  const elInstruction = document.getElementById('vp-inst-instruction');
  const elOpenBtn = document.getElementById('vp-inst-open-btn');
  const elShareBtn = document.getElementById('vp-inst-share-btn');
  const elCopyBtn = document.getElementById('vp-inst-copy-btn');
  const elSticky = document.getElementById('vp-inst-sticky');
  const elOpenBtnSticky = document.getElementById('vp-inst-open-btn-sticky');
  const elShareBtnSticky = document.getElementById('vp-inst-share-btn-sticky');
  const elCopyBtnSticky = document.getElementById('vp-inst-copy-btn-sticky');
  const elBackBtn = document.getElementById('vp-inst-back-btn');
  const elBackBtnSticky = document.getElementById('vp-inst-back-btn-sticky');

  function showError(msg) {
    elLoading.style.display = 'none';
    elGrid.style.display = 'none';
    elError.style.display = 'block';
    elError.textContent = msg;
  }

  if (!code) {
    showError('Нет параметра code в URL');
    return;
  }

  function setActionState(enabled) {
    const hasActions = Boolean(enabled);
    if (elOpenBtn) elOpenBtn.disabled = !hasActions;
    if (elShareBtn) elShareBtn.disabled = !hasActions;
    if (elCopyBtn) elCopyBtn.disabled = !hasActions;
    if (elOpenBtnSticky) elOpenBtnSticky.disabled = !hasActions;
    if (elShareBtnSticky) elShareBtnSticky.disabled = !hasActions;
    if (elCopyBtnSticky) elCopyBtnSticky.disabled = !hasActions;
  }

  function setStickyVisibility(visible) {
    if (!elSticky) return;
    elSticky.classList.toggle('vp-inst-sticky--visible', Boolean(visible));
  }

  function renderChips(product, codeValue) {
    if (!elChips) return;
    const chips = [];
    if (product.brand) chips.push({ label: 'Бренд', value: product.brand });
    if (product.model) chips.push({ label: 'Модель', value: product.model });
    if (product.sku) chips.push({ label: 'SKU', value: product.sku });
    if (codeValue) chips.push({ label: 'Код', value: codeValue });
    elChips.innerHTML = chips
      .map(
        (chip) =>
          `<span class="vp-inst-chip"><span>${chip.label}</span><strong>${chip.value}</strong></span>`
      )
      .join('');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function renderMarkdownLinks(value) {
    const safe = escapeHtml(value);
    return safe.replace(
      /\[([^\]]+)]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>'
    );
  }

  function renderMediaCard(media) {
    if (!media) return '';
    if (media.type === 'image') {
      return `<img src="${escapeHtml(media.url)}" alt="${escapeHtml(media.alt || 'map')}" />`;
    }
    if (media.type === 'video') {
      return `<video src="${escapeHtml(media.url)}" controls></video>`;
    }
    return '';
  }

  function renderNavSteps(steps) {
    if (!Array.isArray(steps) || !steps.length) return '';
    return steps
      .map((step, index) => {
        const title = escapeHtml(step.title || `Шаг ${index + 1}`);
        const body = renderMarkdownLinks(step.body || '');
        const media = step.media
          ? renderMediaCard(step.media)
          : step.media_url
            ? renderMediaCard({ type: step.media_type || 'image', url: step.media_url })
            : '';
        const floor = step.floor ? `<span class="vp-inst-nav-floor">Этаж ${escapeHtml(step.floor)}</span>` : '';
        return `
          <div class="vp-inst-nav-step">
            <div class="vp-inst-nav-step-head">
              <div class="vp-inst-nav-step-title">${title}</div>
              ${floor}
            </div>
            ${media ? `<div class="vp-inst-nav-step-media">${media}</div>` : ''}
            <div class="vp-inst-nav-step-body">${body}</div>
          </div>
        `;
      })
      .join('');
  }

  function renderNavActions(type, payload, codeValue) {
    if (!elNavActions) return;
    const buttons = [];
    const mapUrl = payload?.map_url || payload?.mapUrl || payload?.route_url || payload?.routeUrl;

    if (type === 'navigation') {
      buttons.push({ label: 'Открыть маршрут', url: mapUrl });
      buttons.push({ label: 'Поделиться маршрутом', action: 'share-route' });
      buttons.push({ label: 'Скопировать точку', action: 'copy-point' });
    }

    if (type === 'location') {
      buttons.push({ label: 'Показать на карте', url: mapUrl });
      buttons.push({ label: 'Как пройти', action: 'how-to' });
      buttons.push({ label: 'Часы работы', action: 'hours' });
    }

    if (!buttons.length) {
      elNavActions.innerHTML = '';
      return;
    }

    elNavActions.innerHTML = buttons
      .map((btn, index) => {
        if (btn.url) {
          return `<a class="vp-inst-btn ${index === 0 ? 'vp-inst-btn--primary' : ''}" href="${escapeHtml(btn.url)}" target="_blank" rel="noopener noreferrer">${btn.label}</a>`;
        }
        return `<button class="vp-inst-btn ${index === 0 ? 'vp-inst-btn--primary' : ''}" type="button" data-action="${btn.action}">${btn.label}</button>`;
      })
      .join('');

    elNavActions.querySelectorAll('button[data-action]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const action = btn.getAttribute('data-action');
        if (action === 'share-route' && navigator.share) {
          await navigator.share({
            title: document.title,
            text: `Маршрут для кода ${codeValue}`,
            url: mapUrl || location.href,
          });
        }
        if (action === 'copy-point' && navigator.clipboard) {
          await navigator.clipboard.writeText(payload?.point || payload?.location || codeValue);
        }
        if (action === 'how-to') {
          if (payload?.how_to) {
            alert(payload.how_to);
          }
        }
        if (action === 'hours') {
          if (payload?.hours) {
            alert(payload.hours);
          }
        }
      });
    });
  }

  function renderNavigationBlock(type, payload, codeValue) {
    if (!elNavigation) return;
    const isNav = type === 'navigation' || type === 'location';
    elNavigation.style.display = isNav ? 'block' : 'none';
    if (!isNav) return;

    const start = payload?.start || payload?.from || '—';
    const end = payload?.end || payload?.to || '—';
    const landmarks = Array.isArray(payload?.landmarks) ? payload.landmarks.join(' • ') : payload?.landmarks || '—';
    const media = payload?.map_image
      ? { type: 'image', url: payload.map_image }
      : payload?.map_video
        ? { type: 'video', url: payload.map_video }
        : null;

    if (elNavStart) elNavStart.textContent = start;
    if (elNavEnd) elNavEnd.textContent = end;
    if (elNavLandmarks) elNavLandmarks.textContent = landmarks;
    if (elNavMedia) {
      elNavMedia.innerHTML = media ? renderMediaCard(media) : '';
    }
    if (elNavSteps) {
      elNavSteps.innerHTML = renderNavSteps(payload?.steps || payload?.route_steps || []);
    }

    const hasPayload = Boolean(payload && (payload.start || payload.end || payload.steps || payload.route_steps));
    if (elNavFallback) {
      elNavFallback.style.display = hasPayload ? 'none' : 'block';
    }
    if (elNavReport) {
      elNavReport.onclick = () => {
        const subject = encodeURIComponent(`Проблема с навигацией (${codeValue})`);
        const body = encodeURIComponent('Опишите, что не так с навигацией.');
        window.location.href = `mailto:support@xn--b1awacccnl0jqa.xn--p1ai?subject=${subject}&body=${body}`;
      };
    }

    renderNavActions(type, payload || {}, codeValue);
  }

  function getPrimaryCtaByType(type, instructionUrl, payload) {
    const mapUrl = payload?.map_url || payload?.mapUrl || payload?.route_url || payload?.routeUrl;
    if (type === 'navigation') {
      return { label: 'Открыть маршрут', url: mapUrl || instructionUrl };
    }
    if (type === 'location') {
      return { label: 'Показать на карте', url: mapUrl || instructionUrl };
    }
    if (type === 'service') {
      return { label: 'Открыть сервис', url: instructionUrl };
    }
    return { label: 'Открыть инструкцию', url: instructionUrl };
  }

  function setTypeBadge(typeValue) {
    if (!elTypeBadge) return;
    const raw = String(typeValue || '').trim().toLowerCase();
    const type = raw || 'product';
    const typeLabels = {
      product: 'Товар',
      service: 'Сервис',
      location: 'Локация',
      navigation: 'Навигация',
    };
    elTypeBadge.textContent = typeLabels[type] || type;
    elTypeBadge.className = `vp-inst-type vp-inst-type--${type}`;
  }

  function wireActions({ instructionUrl, shareText, copyText }) {
    const canShare = Boolean(navigator.share);
    const canCopy = Boolean(navigator.clipboard);
    const canOpen = Boolean(instructionUrl);

    if (elShareBtn) elShareBtn.disabled = !canShare;
    if (elShareBtnSticky) elShareBtnSticky.disabled = !canShare;
    if (elCopyBtn) elCopyBtn.disabled = !canCopy;
    if (elCopyBtnSticky) elCopyBtnSticky.disabled = !canCopy;
    if (elOpenBtn) elOpenBtn.disabled = !canOpen;
    if (elOpenBtnSticky) elOpenBtnSticky.disabled = !canOpen;

    const openHandler = () => {
      if (!instructionUrl) return;
      window.open(instructionUrl, '_blank', 'noopener,noreferrer');
    };

    const shareHandler = async () => {
      if (!canShare) return;
      await navigator.share({
        title: document.title,
        text: shareText,
        url: instructionUrl || location.href,
      });
    };

    const copyHandler = async () => {
      if (!canCopy) return;
      await navigator.clipboard.writeText(copyText);
    };

    if (elOpenBtn) elOpenBtn.onclick = openHandler;
    if (elOpenBtnSticky) elOpenBtnSticky.onclick = openHandler;
    if (elShareBtn) elShareBtn.onclick = shareHandler;
    if (elShareBtnSticky) elShareBtnSticky.onclick = shareHandler;
    if (elCopyBtn) elCopyBtn.onclick = copyHandler;
    if (elCopyBtnSticky) elCopyBtnSticky.onclick = copyHandler;
  }

  function setHeroCta(label, url) {
    if (elOpenBtn) elOpenBtn.textContent = label;
    if (elOpenBtnSticky) elOpenBtnSticky.textContent = label;
    const canOpen = Boolean(url);
    if (elOpenBtn) elOpenBtn.disabled = !canOpen;
    if (elOpenBtnSticky) elOpenBtnSticky.disabled = !canOpen;
    if (elOpenBtn) elOpenBtn.onclick = () => {
      if (!url) return;
      window.open(url, '_blank', 'noopener,noreferrer');
    };
    if (elOpenBtnSticky) elOpenBtnSticky.onclick = () => {
      if (!url) return;
      window.open(url, '_blank', 'noopener,noreferrer');
    };
  }

  setActionState(false);

  fetch(`/wp-json/vp/v1/instruction?code=${encodeURIComponent(code)}`)
    .then((r) => r.json().then((j) => ({ ok: r.ok, status: r.status, j })))
    .then(({ ok, status, j }) => {
      if (!ok) {
        showError(`Ошибка загрузки (${status})`);
        return;
      }

      const product = j.product || {};
      const inst = j.instruction || {};
      const payload = inst.navigation_payload || inst.location_payload || inst.payload || j.payload || {};
      const scenarioType = inst.type || j.type || 'product';
      const steps = Array.isArray(inst.steps) ? inst.steps : [];

      const title = product.title || inst.title || `Инструкция (${code})`;
      document.title = `${title} — Всё Понятно`;
      document.querySelector('#vp-inst-header h1').textContent = title;

      const subParts = [];
      if (product.brand) subParts.push(product.brand);
      if (product.model) subParts.push(product.model);
      if (inst.level) subParts.push(`LEVEL ${inst.level}`);
      elSub.textContent = subParts.join(' • ');
      if (elEyebrow) {
        elEyebrow.textContent = 'Инструкция';
      }
      setTypeBadge(scenarioType);

      elLoading.style.display = 'none';
      elError.style.display = 'none';
      elGrid.style.display = 'grid';
      elSteps.style.display = 'block';
      elSteps.innerHTML = '';
      setStickyVisibility(true);
      setActionState(true);

      elBrand.textContent = product.brand || '—';
      elModel.textContent = product.model || '—';
      elSku.textContent = product.sku || '—';
      elDescription.innerHTML = renderMarkdownLinks(
        inst.description || product.description || 'Описание пока не добавлено.'
      );
      renderChips(product, code);
      renderNavigationBlock(scenarioType, payload, code);

      const instructionUrl = inst.url || inst.instruction_url || product.instruction_url || '';
      const primaryCta = getPrimaryCtaByType(scenarioType, instructionUrl, payload);
      setHeroCta(primaryCta.label, primaryCta.url);
      const shareText = title ? `${title} (${code})` : `Инструкция (${code})`;
      wireActions({ instructionUrl, shareText, copyText: code });

      if (!steps.length) {
        const li = document.createElement('li');
        li.className = 'vp-inst-step';
        const hint = document.createElement('div');
        hint.className = 'vp-inst-step-body';
        hint.innerHTML = instructionUrl
          ? `Шаги пока не добавлены. <a href="${instructionUrl}" target="_blank" rel="noopener noreferrer">Открыть инструкцию</a>`
          : 'Шаги пока не добавлены.';
        li.appendChild(hint);
        elSteps.appendChild(li);
      }

      for (const s of steps) {
        const li = document.createElement('li');
        li.className = 'vp-inst-step';

        const h = document.createElement('div');
        h.className = 'vp-inst-step-title';
        h.textContent = (s.step_no ? `Шаг ${s.step_no}. ` : '') + (s.title || '');

        const body = document.createElement('div');
        body.className = 'vp-inst-step-body';
        body.innerHTML = renderMarkdownLinks(s.body || '');

        if (s.body && s.body.includes('Важно:')) {
          const idx = s.body.indexOf('Важно:');
          const beforeText = s.body.substring(0, idx);
          const importantText = s.body.substring(idx);
          body.innerHTML = '';
          if (beforeText.trim()) {
            const span = document.createElement('span');
            span.textContent = beforeText.trim();
            body.appendChild(span);
          }
          const imp = document.createElement('div');
          imp.className = 'vp-inst-important';
          imp.innerHTML = renderMarkdownLinks(importantText.trim());
          body.appendChild(imp);
        }

        if (s.media_url) {
          const mediaWrap = document.createElement('div');
          mediaWrap.className = 'vp-inst-media';
          if (s.media_type === 'video') {
            const v = document.createElement('video');
            v.className = 'vp-inst-media-video';
            v.src = s.media_url;
            v.controls = true;
            mediaWrap.appendChild(v);
          } else {
            const img = document.createElement('img');
            img.src = s.media_url;
            img.alt = s.title || 'media';
            mediaWrap.appendChild(img);
          }
          li.appendChild(mediaWrap);
        }

        li.appendChild(h);
        li.appendChild(body);
        elSteps.appendChild(li);
      }

      const totalSteps = elSteps.children.length;
      let currentStepIndex = 0;

      const allSteps = elSteps.querySelectorAll('li');
      allSteps.forEach((step, index) => {
        if (index !== 0) step.style.display = 'none';
      });

      const controls = document.createElement('div');
      controls.id = 'vp-inst-controls';
      controls.style.display = 'none';

      const btnPrev = document.createElement('button');
      btnPrev.id = 'vp-inst-prev';
      btnPrev.textContent = 'Назад';
      btnPrev.disabled = true;
      btnPrev.className = 'vp-inst-nav-button';

      const btnNext = document.createElement('button');
      btnNext.id = 'vp-inst-next';
      btnNext.textContent = 'Вперёд';
      btnNext.className = 'vp-inst-nav-button';
      if (totalSteps <= 1) btnNext.disabled = true;

      const progressText = document.createElement('span');
      progressText.id = 'vp-inst-progress-text';
      progressText.textContent = `Шаг 1 из ${totalSteps}`;

      const progressDots = document.createElement('div');
      progressDots.id = 'vp-inst-progress-dots';
      progressDots.setAttribute('aria-hidden', 'true');
      progressDots.innerHTML = Array.from({ length: totalSteps })
        .map((_, idx) => `<span class="vp-inst-progress-dot ${idx === 0 ? 'is-active' : ''}"></span>`)
        .join('');

      controls.appendChild(btnPrev);
      controls.appendChild(progressText);
      controls.appendChild(progressDots);
      controls.appendChild(btnNext);
      elInstruction.appendChild(controls);
      controls.style.display = 'flex';

      const updateProgress = () => {
        progressText.textContent = `Шаг ${currentStepIndex + 1} из ${totalSteps}`;
        progressDots.querySelectorAll('.vp-inst-progress-dot').forEach((dot, idx) => {
          dot.classList.toggle('is-active', idx === currentStepIndex);
        });
      };

      btnNext.addEventListener('click', () => {
        if (currentStepIndex < totalSteps - 1) {
          allSteps[currentStepIndex].style.display = 'none';
          currentStepIndex++;
          allSteps[currentStepIndex].style.display = 'block';
          updateProgress();
          btnPrev.disabled = false;
          if (currentStepIndex === totalSteps - 1) btnNext.disabled = true;
          allSteps[currentStepIndex].scrollIntoView({ behavior: 'smooth' });
        }
      });

      btnPrev.addEventListener('click', () => {
        if (currentStepIndex > 0) {
          allSteps[currentStepIndex].style.display = 'none';
          currentStepIndex--;
          allSteps[currentStepIndex].style.display = 'block';
          updateProgress();
          btnNext.disabled = false;
          if (currentStepIndex === 0) btnPrev.disabled = true;
          allSteps[currentStepIndex].scrollIntoView({ behavior: 'smooth' });
        }
      });
    })
    .catch(() => {
      showError('Ошибка загрузки данных');
    });
})();
</script>
<?php get_footer(); ?>