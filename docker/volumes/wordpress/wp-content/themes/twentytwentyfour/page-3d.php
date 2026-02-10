<?php
/* Template Name: VP 3D Navigation */
get_header();
?>
<main id="vp-3d-page" class="vp-3d-page">
  <header class="vp-3d-top">
    <div>
      <div class="vp-3d-kicker">ВсёПонятно</div>
      <h1 class="vp-3d-title">3D и навигация</h1>
      <p class="vp-3d-sub">Просмотр сцены, маршрута и связанных данных по QR-коду.</p>
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

  <section class="vp-3d-card vp-3d-view-card" id="vp3d-view-card" hidden>
    <h2>Зона 3D/карты</h2>
    <div class="vp-3d-view" id="vp3d-view" role="region" aria-label="Плейсхолдер 3D просмотра">
      <div class="vp-3d-view-placeholder" id="vp3d-view-placeholder">
        Подготовлено место под интеграцию движка (Three.js/Babylon/встроенный viewer).
      </div>
    </div>
    <div class="vp-3d-links" id="vp3d-links"></div>
  </section>
</main>
<?php get_footer(); ?>
