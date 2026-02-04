<?php
/* Template Name: VP Instruction */
get_header();
?>
<main id="vp-instruction">
  <div id="vp-inst-header">
    <div class="vp-inst-hero">
      <div class="vp-inst-hero-text">
        <div class="vp-inst-eyebrow" id="vp-inst-eyebrow">Инструкция</div>
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
  const elChips = document.getElementById('vp-inst-chips');
  const elBrand = document.getElementById('vp-inst-brand');
  const elModel = document.getElementById('vp-inst-model');
  const elSku = document.getElementById('vp-inst-sku');
  const elDescription = document.getElementById('vp-inst-description-text');
  const elInstruction = document.getElementById('vp-inst-instruction');
  const elOpenBtn = document.getElementById('vp-inst-open-btn');
  const elShareBtn = document.getElementById('vp-inst-share-btn');
  const elCopyBtn = document.getElementById('vp-inst-copy-btn');
  const elSticky = document.getElementById('vp-inst-sticky');
  const elOpenBtnSticky = document.getElementById('vp-inst-open-btn-sticky');
  const elShareBtnSticky = document.getElementById('vp-inst-share-btn-sticky');
  const elCopyBtnSticky = document.getElementById('vp-inst-copy-btn-sticky');

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

  function wireActions({ instructionUrl, shareText, copyText }) {
    const canShare = Boolean(navigator.share);
    const canCopy = Boolean(navigator.clipboard);

    if (elShareBtn) elShareBtn.disabled = !canShare;
    if (elShareBtnSticky) elShareBtnSticky.disabled = !canShare;
    if (elCopyBtn) elCopyBtn.disabled = !canCopy;
    if (elCopyBtnSticky) elCopyBtnSticky.disabled = !canCopy;

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
        elEyebrow.textContent = inst.type ? String(inst.type).toUpperCase() : 'Инструкция';
      }

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
      elDescription.textContent =
        inst.description || product.description || 'Описание пока не добавлено.';
      renderChips(product, code);

      const instructionUrl = inst.url || inst.instruction_url || product.instruction_url || '';
      const shareText = title ? `${title} (${code})` : `Инструкция (${code})`;
      wireActions({ instructionUrl, shareText, copyText: code });

      if (!steps.length) {
        const li = document.createElement('li');
        li.textContent = 'Шаги пока не добавлены.';
        elSteps.appendChild(li);
        return;
      }

      for (const s of steps) {
        const li = document.createElement('li');
        li.className = 'vp-inst-step';

        const h = document.createElement('div');
        h.className = 'vp-inst-step-title';
        h.textContent = (s.step_no ? `Шаг ${s.step_no}. ` : '') + (s.title || '');

        const body = document.createElement('div');
        body.className = 'vp-inst-step-body';
        body.textContent = s.body || '';

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
          imp.textContent = importantText.trim();
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

      controls.appendChild(btnPrev);
      controls.appendChild(progressText);
      controls.appendChild(btnNext);
      elInstruction.appendChild(controls);
      controls.style.display = 'flex';

      btnNext.addEventListener('click', () => {
        if (currentStepIndex < totalSteps - 1) {
          allSteps[currentStepIndex].style.display = 'none';
          currentStepIndex++;
          allSteps[currentStepIndex].style.display = 'block';
          progressText.textContent = `Шаг ${currentStepIndex + 1} из ${totalSteps}`;
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
          progressText.textContent = `Шаг ${currentStepIndex + 1} из ${totalSteps}`;
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