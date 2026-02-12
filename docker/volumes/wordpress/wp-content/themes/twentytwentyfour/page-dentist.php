<?php
/* Template Name: VP Dentist Cabinet */
if (!isset($_COOKIE['vp_dx_at']) || trim((string)$_COOKIE['vp_dx_at']) === '') {
  wp_safe_redirect(home_url('/dentist-login/'));
  exit;
}
get_header();
?>
<main class="vp-dentist vp-dentist-cabinet">
  <header class="vp-d-header vp-d-card">
    <div>
      <div class="vp-d-kicker">Личный кабинет</div>
      <h1 class="vp-d-title">Добро пожаловать</h1>
      <p class="vp-d-sub" id="vpDentistWelcomeText">Загрузка...</p>
    </div>
    <div class="vp-d-tenant-select">
      <label class="vp-d-label" for="vpDentistTenant">Организация (tenant)</label>
      <select id="vpDentistTenant" class="vp-d-input">
        <option value="">Загрузка...</option>
      </select>
    </div>
  </header>

  <section class="vp-d-card vp-d-create-case">
    <div class="vp-d-panel-head">
      <h2>Создать кейс</h2>
    </div>
    <form id="vpDentistCaseForm" class="vp-d-form vp-d-grid" novalidate>
      <div>
        <label class="vp-d-label" for="vpCaseTitle">Название кейса *</label>
        <input id="vpCaseTitle" class="vp-d-input" type="text" required />
      </div>
      <div>
        <label class="vp-d-label" for="vpCaseClinic">Клиника</label>
        <select id="vpCaseClinic" class="vp-d-input">
          <option value="">Без клиники</option>
        </select>
      </div>
      <div>
        <label class="vp-d-label" for="vpCasePatientExternal">Patient external ID</label>
        <input id="vpCasePatientExternal" class="vp-d-input" type="text" />
      </div>
      <div class="vp-d-actions-inline">
        <button class="vp-btn vp-primary" type="submit">Создать кейс</button>
      </div>
    </form>
    <div id="vpDentistCreateStatus" class="vp-d-status" aria-live="polite"></div>
  </section>

  <section class="vp-d-card vp-d-cases">
    <div class="vp-d-panel-head">
      <h2>Мои кейсы</h2>
      <button class="vp-btn" id="vpDentistReloadCasesBtn" type="button">Обновить список</button>
    </div>
    <div id="vpDentistCasesState" class="vp-d-status">Загрузка...</div>
    <div id="vpDentistCasesList" class="vp-d-cases-list"></div>
  </section>

  <div class="vp-3d-fab" data-vp-fab data-page-type="dentist" aria-label="Контекстное меню">
    <button class="vp-fab-toggle" type="button" data-vp-fab-toggle aria-expanded="false" aria-controls="vp-fab-panel-dentist">⋯</button>
    <div class="vp-fab-panel" id="vp-fab-panel-dentist" data-vp-fab-panel>
      <button type="button" class="vp-fab-action" data-vp-action="goScan">Сканер /scan</button>
      <button type="button" class="vp-fab-action" data-vp-action="goCreateCase">Создать кейс</button>
      <button type="button" class="vp-fab-action" data-vp-action="reloadCases">Обновить список</button>
      <button type="button" class="vp-fab-action" data-vp-action="logout">Выйти</button>
    </div>
  </div>
</main>
<?php get_footer(); ?>
