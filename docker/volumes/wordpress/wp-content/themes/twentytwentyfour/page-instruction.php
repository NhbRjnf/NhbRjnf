<?php
/* Template Name: VP Instruction */
get_header();
?>
<main id="vp-instruction" style="max-width: 900px; margin: 0 auto; padding: 16px;">
  <div id="vp-inst-header" style="margin-bottom: 16px;">
    <h1 style="margin: 0 0 8px 0;">Инструкция</h1>
    <div id="vp-inst-sub" style="opacity: .75;"></div>
  </div>

  <div id="vp-inst-loading">Загрузка…</div>
  <div id="vp-inst-error" style="display:none; color: #b00020;"></div>

  <ol id="vp-inst-steps" style="display:none;"></ol>
</main>

<link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/instruction.css" />

<script>
(function () {
  const params = new URLSearchParams(location.search);
  const code = params.get('code');

  const elLoading = document.getElementById('vp-inst-loading');
  const elError = document.getElementById('vp-inst-error');
  const elSteps = document.getElementById('vp-inst-steps');
  const elSub = document.getElementById('vp-inst-sub');

  function showError(msg) {
    elLoading.style.display = 'none';
    elSteps.style.display = 'none';
    elError.style.display = 'block';
    elError.textContent = msg;
  }

  if (!code) {
    showError('Нет параметра code в URL');
    return;
  }

  fetch(`/wp-json/vp/v1/instruction?code=${encodeURIComponent(code)}`)
    .then(r => r.json().then(j => ({ ok: r.ok, status: r.status, j })))
    .then(({ ok, status, j }) => {
      if (!ok) {
        showError(`Ошибка загрузки (${status})`);
        return;
      }

      const product = j.product || {};
      const inst = j.instruction || {};
      const steps = Array.isArray(inst.steps) ? inst.steps : [];

      const title = product.title || inst.title || `Инструкция (${code})`;
      document.title = title + ' — Всё Понятно';
      document.querySelector('#vp-inst-header h1').textContent = title;

      const subParts = [];
      if (product.brand) subParts.push(product.brand);
      if (product.model) subParts.push(product.model);
      if (inst.level) subParts.push('LEVEL ' + inst.level);
      elSub.textContent = subParts.join(' • ');

      elLoading.style.display = 'none';
      elError.style.display = 'none';
      elSteps.style.display = 'block';
      elSteps.innerHTML = '';

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
          const importantDiv = document.createElement('div');
          importantDiv.className = 'vp-inst-important';
          importantDiv.textContent = importantText;
          body.appendChild(importantDiv);
        }

        const mediaBlock = document.createElement('div');
        mediaBlock.className = 'vp-inst-media';

        if (s.model_url) {
          const modelViewer = document.createElement('model-viewer');
          modelViewer.src = s.model_url;
          modelViewer.alt = s.title || '3D model';
          modelViewer.setAttribute('camera-controls', 'true');
          modelViewer.setAttribute('auto-rotate', 'true');
          modelViewer.style.width = '100%';
          modelViewer.style.height = '300px';
          mediaBlock.appendChild(modelViewer);
        } else if (s.video_url) {
          const video = document.createElement('video');
          video.src = s.video_url;
          video.controls = true;
          video.className = 'vp-inst-media-video';
          mediaBlock.appendChild(video);
        }

        if (s.image_file_url) {
          const img = document.createElement('img');
          img.src = s.image_file_url;
          img.alt = s.title || '';
          img.className = 'vp-inst-media-img';
          mediaBlock.appendChild(img);
        }

        if (mediaBlock.children.length > 0) {
          li.appendChild(h);
          li.appendChild(mediaBlock);
        } else {
          li.appendChild(h);
        }
        li.appendChild(body);
        elSteps.appendChild(li);
      }

      const totalSteps = steps.length;
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
      elSteps.parentNode.appendChild(controls);
      controls.style.display = 'flex';

      btnNext.addEventListener('click', () => {
        if (currentStepIndex < totalSteps - 1) {
          allSteps[currentStepIndex].style.display = 'none';
          currentStepIndex++;
          allSteps[currentStepIndex].style.display = 'block';
          progressText.textContent = `Шаг ${currentStepIndex+1} из ${totalSteps}`;
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
          progressText.textContent = `Шаг ${currentStepIndex+1} из ${totalSteps}`;
          btnNext.disabled = false;
          if (currentStepIndex === 0) btnPrev.disabled = true;
          allSteps[currentStepIndex].scrollIntoView({ behavior: 'smooth' });
        }
      });
    })
    .catch(() => showError('Сеть/сервер недоступен'));
})();
</script>
<?php get_footer(); ?>
