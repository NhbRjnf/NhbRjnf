<?php
/* Template Name: VP Scan */
get_header();
?>

<main class="vp-scan">
  <header class="vp-top">
    <div class="vp-brand">
      <div class="vp-logo">VP</div>
      <div class="vp-brand-text">
        <div class="vp-title">ВсёПонятно</div>
        <div class="vp-sub">Универсальный сканер: инструкции • сервис • навигация</div>
      </div>
    </div>

    <div class="vp-top-actions">
      <button class="vp-iconbtn" id="vpHardReloadBtn" type="button" title="Обновить (сбросить кэш)">
        ⟲
      </button>
    </div>
  </header>

  <section class="vp-card vp-input-card">
    <label class="vp-label" for="vpCodeInput">Введите код или отсканируйте QR</label>

    <div class="vp-input-row">
      <input
        id="vpCodeInput"
        class="vp-input"
        inputmode="text"
        autocomplete="off"
        placeholder="Например: VP-TEST-001 или “инструкция стул”"
      />
      <button class="vp-btn vp-primary" id="vpSearchBtn" type="button">Найти</button>
    </div>

    <!-- Кликабельные подсказки -->
    <div class="vp-suggest" id="vpSuggestWrap" hidden>
      <div class="vp-suggest-head">
        <span>Подсказки</span>
        <button class="vp-linkbtn" id="vpSuggestCloseBtn" type="button">Скрыть</button>
      </div>
      <div class="vp-suggest-list" id="vpSuggestList"></div>
    </div>

    <div class="vp-hint">
      Поддержка: <span class="vp-pill">инструкция</span> <span class="vp-pill">сервис</span> <span class="vp-pill">локация</span>
    </div>
  </section>

  <section class="vp-card vp-scan-card">
    <div class="vp-scan-head">
      <div class="vp-scan-title">Сканер</div>
      <div class="vp-scan-actions">
        <button class="vp-iconbtn" id="vpTorchBtn" type="button" title="Подсветка" disabled>🔦</button>
        <button class="vp-iconbtn" id="vpSwitchBtn" type="button" title="Сменить камеру">↺</button>
      </div>
    </div>

    <div class="vp-reader" id="vpReader"></div>

    <div class="vp-scan-cta">
      <button class="vp-btn" id="vpStartBtn" type="button">Включить камеру</button>
      <button class="vp-btn vp-danger" id="vpStopBtn" type="button" hidden>Остановить</button>
    </div>

    <div class="vp-status" id="vpStatus" aria-live="polite"></div>
  </section>

  <section class="vp-card vp-result-card" id="vpResult" hidden>
    <div class="vp-result-head">
      <div>
        <div class="vp-qrwrap" id="vpResQr" hidden>
  <a class="vp-qrlink" id="vpResQrLink" href="#" target="_blank" rel="noopener">
    <img class="vp-qrimg" id="vpResQrImg" alt="QR code" />
  </a>
</div>  
        <div class="vp-result-title" id="vpResultTitle"></div>
        <div class="vp-result-sub" id="vpResultSub"></div>
      </div>
      <span class="vp-badge" id="vpResultBadge"></span>
    </div>

    <div class="vp-result-body" id="vpResultBody"></div>

    <div class="vp-result-actions">
      <button class="vp-btn vp-primary" id="vpOpenBtn" type="button" hidden>Открыть</button>
      <button class="vp-btn" id="vpCopyBtn" type="button" hidden>Скопировать код</button>
    </div>
  </section>

  <section class="vp-card vp-history-card">
    <div class="vp-history-head">
      <div class="vp-history-title">История</div>
      <button class="vp-linkbtn" id="vpHistoryClearBtn" type="button">Очистить</button>
    </div>
    <div class="vp-history-chips" id="vpHistoryChips"></div>
  </section>

  <div class="vp-toast" id="vpToast"></div>

  <!-- Модалка подтверждения действия -->
  <div class="vp-modal" id="vpConfirmModal" hidden>
    <div class="vp-modal-backdrop" id="vpConfirmBackdrop"></div>
    <div class="vp-modal-card" role="dialog" aria-modal="true" aria-labelledby="vpConfirmTitle">
      <div class="vp-modal-title" id="vpConfirmTitle">Подтвердить</div>
      <div class="vp-modal-text" id="vpConfirmText"></div>
      <div class="vp-modal-actions">
        <button class="vp-btn" id="vpConfirmCancelBtn" type="button">Отмена</button>
        <button class="vp-btn vp-primary" id="vpConfirmOkBtn" type="button">Продолжить</button>
      </div>
    </div>
  </div>
</main>

<?php get_footer(); ?>
