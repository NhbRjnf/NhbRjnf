<?php
/* Template Name: VP Dentist Login */
get_header();
?>
<main class="vp-dentist vp-dentist-login">
  <section class="vp-d-card vp-d-login-card">
    <div class="vp-d-kicker">ВсёПонятно</div>
    <h1 class="vp-d-title">Вход в кабинет стоматолога</h1>
    <p class="vp-d-sub">Войдите по email и паролю Directus.</p>

    <form id="vpDentistLoginForm" class="vp-d-form" novalidate>
      <label class="vp-d-label" for="vpDentistEmail">Email</label>
      <input id="vpDentistEmail" class="vp-d-input" type="email" required autocomplete="email" />

      <label class="vp-d-label" for="vpDentistPassword">Пароль</label>
      <input id="vpDentistPassword" class="vp-d-input" type="password" required autocomplete="current-password" />

      <button class="vp-btn vp-primary vp-d-full" id="vpDentistLoginBtn" type="submit">Войти</button>
    </form>

    <div class="vp-d-links">
      <button class="vp-linkbtn" id="vpDentistForgotBtn" type="button">Забыли пароль?</button>
      <a class="vp-linkbtn" href="<?php echo esc_url(home_url('/scan/')); ?>">Вернуться на /scan</a>
    </div>

    <div id="vpDentistLoginStatus" class="vp-d-status" aria-live="polite"></div>
  </section>
</main>
<?php get_footer(); ?>
