<?php
/* Template Name: VP 3D Navigation */

$vp3d_job_id = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
$vp3d_prefetched_job = null;

if ($vp3d_job_id > 0 && function_exists('vp_3d_job_status')) {
  $vp3d_req = new WP_REST_Request('GET', '/vp/v1/3d/job-status');
  $vp3d_req->set_param('job_id', $vp3d_job_id);

  $vp3d_res = vp_3d_job_status($vp3d_req);
  if ($vp3d_res instanceof WP_REST_Response) {
    $vp3d_data = $vp3d_res->get_data();
    if (!empty($vp3d_data['ok']) && !empty($vp3d_data['job']) && is_array($vp3d_data['job'])) {
      $vp3d_prefetched_job = $vp3d_data['job'];
    }
  }
}

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
      <div id="vp3d-viewer-mount"></div>
      <div id="vp3d-viewer-placeholder" class="vp-3d-placeholder">
        Загружаем 3D модель…
      </div>
    </div>
  </section>

  <div class="vp-3d-fab" data-vp-fab data-page-type="3d" aria-label="Контекстное меню">
    <button class="vp-fab-toggle" type="button" data-vp-fab-toggle aria-expanded="false" aria-controls="vp-fab-panel-3d">⋯</button>
    <div class="vp-fab-panel" id="vp-fab-panel-3d" data-vp-fab-panel>
      <button type="button" class="vp-fab-action" data-vp-action="backToScan" data-vp-pages="3d,instruction,scan">Назад к сканеру</button>
      <button type="button" class="vp-fab-action" data-vp-action="share" data-vp-pages="3d,instruction,scan">Поделиться</button>
      <button type="button" class="vp-fab-action" data-vp-action="copyCode" data-vp-pages="3d,instruction,scan">Скопировать код</button>
      <button type="button" class="vp-fab-action" data-vp-action="reloadModel" data-vp-pages="3d">Перезагрузить модель</button>
      <button type="button" class="vp-fab-action" data-vp-action="resetView" data-vp-pages="3d">Сбросить вид</button>
    </div>
  </div>

  <script>
    window.VP_3D_PREFETCH = <?php echo wp_json_encode([
      'job_id' => $vp3d_job_id > 0 ? $vp3d_job_id : null,
      'job' => $vp3d_prefetched_job,
      'modelViewerUrl' => get_stylesheet_directory_uri() . '/assets/vendor/model-viewer.min.js',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  </script>
</main>
<?php get_footer(); ?>