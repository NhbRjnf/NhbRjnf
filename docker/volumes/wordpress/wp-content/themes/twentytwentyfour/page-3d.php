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

    <a class="vp-3d-back" href="<?php echo esc_url(home_url('/scan/')); ?>">Назад на сканер</a>
  </header>

  <section class="vp-3d-card vp-3d-status" aria-live="polite">
    <div id="vp3d-status" class="vp-3d-status-text">Инициализация…</div>
  </section>

  <section class="vp-3d-card" id="vp3d-meta" hidden>
    <div class="vp-3d-meta-head">
      <h2 id="vp3d-scene-title" class="vp-3d-meta-title">—</h2>
      <span id="vp3d-kind" class="vp-3d-badge" hidden>—</span>
    </div>

    <div class="vp-3d-grid">
      <div class="vp-3d-field">
        <div class="vp-3d-label">Код</div>
        <div id="vp3d-code" class="vp-3d-value">—</div>
      </div>
      <div class="vp-3d-field">
        <div class="vp-3d-label">Сцена</div>
        <div id="vp3d-scene-id" class="vp-3d-value">—</div>
      </div>
      <div class="vp-3d-field">
        <div class="vp-3d-label">Тип QR</div>
        <div id="vp3d-type" class="vp-3d-value">—</div>
      </div>
      <div class="vp-3d-field">
        <div class="vp-3d-label">Доступ</div>
        <div id="vp3d-access" class="vp-3d-value">—</div>
      </div>
      <div class="vp-3d-field">
        <div class="vp-3d-label">Действует до</div>
        <div id="vp3d-expiry" class="vp-3d-value">—</div>
      </div>
    </div>

    <div id="vp3d-auth" class="vp-3d-auth" hidden>
      <form id="vp3d-auth-form" class="vp-3d-auth-form" autocomplete="on">
        <label class="vp-3d-label" for="vp3d-password">Пароль</label>
        <input
          id="vp3d-password"
          class="vp-3d-input"
          type="password"
          name="password"
          required
          autocomplete="current-password"
        />
        <div id="vp3d-hint" class="vp-3d-hint" hidden></div>
        <button class="vp-3d-btn" type="submit">Открыть</button>
      </form>
    </div>
  </section>

  <section class="vp-3d-card" id="vp3d-viewer-card">
    <h2 class="vp-3d-h2">3D просмотр</h2>

    <div id="vp3d-viewer-wrap" class="vp-3d-viewer-wrap">
      <div id="vp3d-viewer-placeholder" class="vp-3d-placeholder">
        Загружаем 3D модель…
      </div>

      <model-viewer
        id="vp3d-viewer"
        class="vp-3d-viewer"
        style="display:none"
        ar
        camera-controls
        touch-action="pan-y"
        shadow-intensity="1"
      ></model-viewer>
    </div>
  </section>
  <script type="module" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/vendor/model-viewer.min.js' ); ?>"></script>

</main>
<?php get_footer(); ?>
