<?php
/* Template Name: VP 3D Navigation */
get_header();
?>
<main id="vp-3d-page" class="vp-3d-page">
  <header class="vp-3d-top">
    <div>
      <div class="vp-3d-kicker">ВсёПонятно</div>
      <h1 class="vp-3d-title">3D и навигация</h1>
      <p class="vp-3d-sub">Безопасный просмотр 3D-сцены через WordPress proxy.</p>
    </div>
    <a class="vp-3d-btn vp-3d-btn--ghost" href="/scan">Назад на сканер</a>
  </header>

  <section class="vp-3d-card" id="vp3d-status-card" aria-live="polite">
    <div class="vp-3d-status" id="vp3d-status">Проверяем параметры запроса…</div>
    <div class="vp-3d-error" id="vp3d-error" hidden></div>
  </section>

  <section class="vp-3d-card" id="vp3d-result-card" hidden>
    <div class="vp-3d-result-head">
      <div>
        <h2 class="vp-3d-result-title" id="vp3d-title">Сцена</h2>
        <div class="vp-3d-result-sub" id="vp3d-sub"></div>
      </div>
      <span class="vp-3d-type" id="vp3d-type">navigation</span>
    </div>
    <div class="vp-3d-meta" id="vp3d-meta"></div>
  </section>

  <section class="vp-3d-card" id="vp3d-auth-card" hidden>
    <h2>Доступ к сцене</h2>
    <p class="vp-3d-auth-hint" id="vp3d-auth-hint"></p>
    <form id="vp3d-auth-form" class="vp-3d-auth-form" autocomplete="off">
      <label class="vp-3d-label" for="vp3d-password">Пароль</label>
      <input id="vp3d-password" class="vp-3d-input" type="password" name="password" required />
      <button type="submit" class="vp-3d-btn vp-3d-btn--primary">Открыть сцену</button>
    </form>
  </section>

  <section class="vp-3d-card vp-3d-view-card" id="vp3d-view-card" hidden>
    <h2>3D просмотр</h2>
    <div class="vp-3d-view" id="vp3d-view" role="region" aria-label="3D просмотрщик">
      <model-viewer id="vp3d-viewer" camera-controls touch-action="pan-y" loading="eager" reveal="auto"></model-viewer>
      <div class="vp-3d-view-placeholder" id="vp3d-view-placeholder">Подготовка сцены…</div>
    </div>
  </section>
</main>
<?php get_footer(); ?>