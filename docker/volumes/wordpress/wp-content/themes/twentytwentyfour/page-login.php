<?php
/* Template Name: VP Universal Login */
get_header();
?>
<main class="vp-dentist vp-login-page">
  <section class="vp-d-card vp-login-card">
    <div class="vp-d-kicker">ВсёПонятно</div>
    <h1 class="vp-d-title">Вход и регистрация</h1>
    <p class="vp-d-sub">Единый вход для всех кабинетов: доктор, авто, бизнес, локация, партнёр и клиент.</p>

    <div class="vp-login-tabs" role="tablist" aria-label="Вход и регистрация">
      <button type="button" class="vp-login-tab is-active" data-tab="login" role="tab" aria-selected="true">Вход</button>
      <button type="button" class="vp-login-tab" data-tab="register" role="tab" aria-selected="false">Регистрация</button>
    </div>

    <div class="vp-login-panel is-active" data-panel="login" role="tabpanel">
      <form id="vpLoginForm" class="vp-d-form" novalidate>
        <label class="vp-d-label" for="vpLoginEmail">Email</label>
        <input id="vpLoginEmail" class="vp-d-input" type="email" required autocomplete="email">

        <label class="vp-d-label" for="vpLoginPassword">Пароль</label>
        <input id="vpLoginPassword" class="vp-d-input" type="password" required autocomplete="current-password">

        <button class="vp-btn vp-primary vp-d-full" type="submit">Войти</button>
      </form>
    </div>

    <div class="vp-login-panel" data-panel="register" role="tabpanel" hidden>
      <form id="vpRegisterForm" class="vp-d-form" novalidate>
        <label class="vp-d-label" for="vpRegType">Кто вы?</label>
        <select id="vpRegType" class="vp-d-input" required>
          <option value="">Выберите тип</option>
          <option value="dentist">Доктор / стоматолог</option>
          <option value="car_owner">Владелец авто</option>
          <option value="business_owner">Бизнес / бренд</option>
          <option value="location_owner">Локация (ТЦ/ЖК/объект)</option>
          <option value="partner">Партнёр / контент-провайдер</option>
          <option value="client">Клиент</option>
        </select>

        <label class="vp-d-label" for="vpRegEmail">Email</label>
        <input id="vpRegEmail" class="vp-d-input" type="email" required autocomplete="email">

        <label class="vp-d-label" for="vpRegPassword">Пароль</label>
        <input id="vpRegPassword" class="vp-d-input" type="password" autocomplete="new-password">

        <label class="vp-d-label" for="vpRegFirstName">Имя</label>
        <input id="vpRegFirstName" class="vp-d-input" type="text" autocomplete="given-name">

        <label class="vp-d-label" for="vpRegLastName">Фамилия</label>
        <input id="vpRegLastName" class="vp-d-input" type="text" autocomplete="family-name">

        <label class="vp-d-label" for="vpRegPhone">Телефон</label>
        <input id="vpRegPhone" class="vp-d-input" type="text" autocomplete="tel">

        <div id="vpRegDynamicFields" class="vp-login-dynamic"></div>

        <label class="vp-d-label" for="vpRegInvite">Invite code (опционально)</label>
        <input id="vpRegInvite" class="vp-d-input" type="text">

        <label class="vp-d-label" for="vpRegTenantName">Название организации (опционально)</label>
        <input id="vpRegTenantName" class="vp-d-input" type="text">

        <label class="vp-d-label" for="vpRegTenantSlug">Slug организации (опционально)</label>
        <input id="vpRegTenantSlug" class="vp-d-input" type="text">

        <label class="vp-d-label" for="vpRegEvidence">Комментарий / подтверждение</label>
        <textarea id="vpRegEvidence" class="vp-d-input" rows="3"></textarea>

        <p class="vp-login-note">Если заявка уйдёт на ручную модерацию, пароль нужно будет задать после подтверждения.</p>

        <button class="vp-btn vp-primary vp-d-full" type="submit">Отправить заявку</button>
      </form>
    </div>

    <div id="vpLoginStatus" class="vp-d-status" aria-live="polite"></div>
  </section>
</main>
<?php get_footer(); ?>
