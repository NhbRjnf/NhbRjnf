(() => {
  "use strict";

  // ---------- Helpers
  const $ = (id) => document.getElementById(id);
  const elResQr = document.getElementById("vpResQr");
const elResQrImg = document.getElementById("vpResQrImg");
const elResQrLink = document.getElementById("vpResQrLink");


  const LOOKUP_URL =
    (window.VP_SCAN && window.VP_SCAN.lookupUrl) || "/wp-json/vp/v1/lookup";
  const SUGGEST_URL =
    (window.VP_SCAN && window.VP_SCAN.suggestUrl) || "/wp-json/vp/v1/suggest";

  const HISTORY_KEY = "vp_scan_history_v1";
  const MAX_HISTORY = 12;
  
  function directusAssetUrl(fileId) {
  const base = (VP_SCAN?.directusBaseUrl || "").replace(/\/$/, "");
  if (!base || !fileId) return null;
  return `${base}/assets/${fileId}`;
}


  function normalizeCode(s) {
    return String(s || "")
      .trim()
      .toUpperCase()
      .replace(/\s+/g, "");
  }

  function normalizeTerm(s) {
    return String(s || "").trim();
  }

  function escapeHtml(str) {
    return String(str || "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function haptic(ms = 10) {
    try {
      if (navigator.vibrate) navigator.vibrate(ms);
    } catch (_) {}
  }

  function toast(msg) {
    const t = $("vpToast");
    if (!t) return;
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(toast._tm);
    toast._tm = setTimeout(() => (t.hidden = true), 1800);
  }

  function setStatus(text, kind = "info") {
    const s = $("vpStatus");
    if (!s) return;
    s.textContent = text;
    s.dataset.kind = kind;
  }

  // ---------- Elements (MATCH page-scan.php)
  const elStartBtn = $("vpStartBtn");
  const elStopBtn = $("vpStopBtn");
  const elTorchBtn = $("vpTorchBtn");
  const elSwitchBtn = $("vpSwitchBtn");

  const elManualInput = $("vpCodeInput");
  const elManualBtn = $("vpSearchBtn");

  const elSuggestWrap = $("vpSuggestWrap");
  const elSuggestList = $("vpSuggestList");
  const elSuggestCloseBtn = $("vpSuggestCloseBtn");

  const elResultCard = $("vpResult");
  const elResTitle = $("vpResultTitle");
  const elResSub = $("vpResultSub");
  const elResBadge = $("vpResultBadge");
  const elResBody = $("vpResultBody");
  const elOpenBtn = $("vpOpenBtn");
  const elCopyBtn = $("vpCopyBtn");

  const elHistoryClear = $("vpHistoryClearBtn");
  const elHistoryList = $("vpHistoryChips");

  const elHardReload = $("vpHardReloadBtn");

  // ---------- History
  function loadHistory() {
    try {
      const arr = JSON.parse(localStorage.getItem(HISTORY_KEY) || "[]");
      return Array.isArray(arr) ? arr : [];
    } catch {
      return [];
    }
  }

  function saveHistory(arr) {
    try {
      localStorage.setItem(
        HISTORY_KEY,
        JSON.stringify(arr.slice(0, MAX_HISTORY))
      );
    } catch (_) {}
  }

  function pushHistory(code) {
    const c = normalizeCode(code);
    if (!c) return;

    const arr = loadHistory().filter((x) => x !== c);
    arr.unshift(c);
    saveHistory(arr.slice(0, MAX_HISTORY));
    renderHistory();
  }

  function renderHistory() {
    const arr = loadHistory();
    if (!elHistoryList) return;

    elHistoryList.innerHTML = arr
      .map(
        (c) =>
          `<button class="vp-chip" type="button" data-code="${escapeHtml(
            c
          )}">${escapeHtml(c)}</button>`
      )
      .join("");

    elHistoryList.querySelectorAll("button[data-code]").forEach((btn) => {
      btn.addEventListener("click", () => lookupAndRender(btn.dataset.code));
    });
  }

  elHistoryClear?.addEventListener("click", () => {
    if (!confirm("Очистить историю?")) return;
    try {
      localStorage.removeItem(HISTORY_KEY);
    } catch (_) {}
    renderHistory();
    toast("История очищена");
  });

  // ---------- API
  async function apiLookup(code) {
    const u = new URL(LOOKUP_URL, window.location.origin);
    u.searchParams.set("code", code);

    const r = await fetch(u.toString(), {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    const j = await r.json().catch(() => ({}));
    if (!r.ok) {
      const err = new Error("lookup_failed");
      err.status = r.status;
      err.body = j;
      throw err;
    }
    return j;
  }

  let suggestAbort = null;
  async function apiSuggest(term) {
    const t = normalizeTerm(term);
    if (t.length < 2) return [];

    if (suggestAbort) suggestAbort.abort();
    suggestAbort = new AbortController();

    const u = new URL(SUGGEST_URL, window.location.origin);
    u.searchParams.set("term", t);

    const r = await fetch(u.toString(), {
      headers: { Accept: "application/json" },
      cache: "no-store",
      signal: suggestAbort.signal,
    }).catch(() => null);

    if (!r || !r.ok) return [];
    const j = await r.json().catch(() => ({}));
    return Array.isArray(j?.data) ? j.data : [];
  }

  // ---------- Suggest UI
  function hideSuggest() {
    if (elSuggestWrap) elSuggestWrap.hidden = true;
    if (elSuggestList) elSuggestList.innerHTML = "";
  }

  function renderSuggest(list) {
    if (!elSuggestWrap || !elSuggestList) return;

    elSuggestWrap.hidden = !list || list.length === 0;

    elSuggestList.innerHTML = (list || [])
      .map((it) => {
        const code = it?.code || "";
        const main = it?.title || code;
        const sub = it?.sub || it?.kind || "";
        return `
          <div class="vp-suggest-item" role="button" tabindex="0" data-code="${escapeHtml(
            code
          )}">
            <div class="vp-suggest-main">${escapeHtml(main)}</div>
            <div class="vp-suggest-sub">${escapeHtml(sub)}</div>
          </div>
        `;
      })
      .join("");

    elSuggestList.querySelectorAll("[data-code]").forEach((row) => {
      const go = () => {
        const code = row.dataset.code || "";
        if (elManualInput) elManualInput.value = code;
        hideSuggest();
        lookupAndRender(code);
      };

      row.addEventListener("click", go);
      row.addEventListener("keydown", (e) => {
        if (e.key === "Enter") go();
      });
    });
  }

  elSuggestCloseBtn?.addEventListener("click", hideSuggest);

  // ---------- Result rendering
  function extractInstructionUrl(item) {
    return (
      item?.instruction_url ||
      item?.product_id?.instruction_url ||
      item?.instruction_id?.url ||
      ""
    );
  }

  function openInstruction(url) {
    const u = String(url || "").trim();
    if (!u) {
      toast("Нет ссылки на инструкцию");
      return;
    }
    // ✅ новая вкладка + защита
    window.open(u, "_blank", "noopener,noreferrer");
  }

  function hideResult() {
    if (elResultCard) elResultCard.hidden = true;
    if (elResTitle) elResTitle.textContent = "";
    if (elResSub) elResSub.textContent = "";
    if (elResBadge) elResBadge.textContent = "";
    if (elResBody) elResBody.textContent = "";
    if (elOpenBtn) elOpenBtn.hidden = true;
    if (elCopyBtn) elCopyBtn.hidden = true;
  }

  function showResult({ code, item }) {
    if (!elResultCard) return;

    const title = item?.product_id?.title || item?.title || code;
    // QR preview inside card
try {
  const fileObj = item?.qr_file_png || item?.qr_file;
  const fileId = typeof fileObj === "string" ? fileObj : fileObj?.id;
  const url = directusAssetUrl(fileId);

  if (url && elResQr && elResQrImg && elResQrLink) {
    elResQrImg.src = url;
    elResQrImg.alt = `QR ${item?.code || ""}`.trim();
    elResQrLink.href = url;
    elResQr.hidden = false;
  } else if (elResQr) {
    elResQr.hidden = true;
  }
} catch (e) {
  if (elResQr) elResQr.hidden = true;
}


    const subParts = [];
    if (item?.kind) subParts.push(item.kind);
    if (item?.product_id?.brand) subParts.push(item.product_id.brand);
    if (item?.product_id?.model) subParts.push(item.product_id.model);
    if (item?.product_id?.sku) subParts.push(`SKU: ${item.product_id.sku}`);

    const url = extractInstructionUrl(item);
    const descr =
      item?.product_id?.description || item?.description || item?.notes || "";

    elResultCard.hidden = false;

    if (elResTitle) elResTitle.textContent = title;
    if (elResSub) elResSub.textContent = subParts.filter(Boolean).join(" • ");

    if (elResBadge) {
      elResBadge.textContent = item?.kind ? String(item.kind) : "Найдено";
    }

    if (elResBody) {
      elResBody.textContent = descr ? String(descr) : "Описание отсутствует.";
    }

    // Кнопки (не накапливаем обработчики)
    if (elOpenBtn) {
      elOpenBtn.hidden = !url;
      elOpenBtn.onclick = () => openInstruction(url);
    }
    if (elCopyBtn) {
      elCopyBtn.hidden = !code;
      elCopyBtn.onclick = async () => {
        try {
          await navigator.clipboard.writeText(code);
          toast("Код скопирован");
          haptic(10);
        } catch (_) {
          toast("Не удалось скопировать");
        }
      };
    }
  }

  async function lookupAndRender(codeRaw) {
    const code = normalizeCode(codeRaw);
    if (!code) return;

    hideSuggest();
    setStatus("Ищем в базе…", "work");
    haptic(8);

    // прячем старый результат, чтобы не путал
    hideResult();

    try {
      const json = await apiLookup(code);

      const item =
        json && Array.isArray(json.data) && json.data.length ? json.data[0] : null;

      if (!item) {
        setStatus("Код не найден", "warn");
        toast("Не найдено");
        return;
      }

      pushHistory(code);
      showResult({ code, item });

      setStatus("Готово ✅", "ok");
      haptic(35);
      toast("Найдено");
    } catch (e) {
      console.error(e);
      setStatus("Ошибка запроса", "err");
      toast("Ошибка поиска");
    }
  }

  // ---------- Suggestions wiring (debounce)
  let suggestTm = null;
  elManualInput?.addEventListener("input", () => {
    clearTimeout(suggestTm);

    const val = elManualInput.value || "";
    if (normalizeTerm(val).length < 2) {
      hideSuggest();
      return;
    }

    suggestTm = setTimeout(async () => {
      const term = elManualInput.value || "";
      const list = await apiSuggest(term);
      renderSuggest(list);
    }, 180);
  });

  // ---------- Manual search wiring
  elManualBtn?.addEventListener("click", () => lookupAndRender(elManualInput?.value));
  elManualInput?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") lookupAndRender(elManualInput.value);
    if (e.key === "Escape") hideSuggest();
  });

  // ---------- Scanner (html5-qrcode)
  let html5 = null;
  let cameras = [];
  let currentCameraId = null;

  let torchOn = false;
  let canTorch = false;

  async function listCameras() {
    if (!window.Html5Qrcode) throw new Error("Html5Qrcode_not_loaded");
    cameras = await window.Html5Qrcode.getCameras();
    return cameras;
  }

  function updateTorchButton() {
    if (!elTorchBtn) return;
    elTorchBtn.hidden = false;
    elTorchBtn.disabled = !canTorch;
    elTorchBtn.style.opacity = canTorch ? "1" : "0.45";
    elTorchBtn.setAttribute("aria-pressed", torchOn ? "true" : "false");
  }

  async function detectTorchSupport() {
    canTorch = false;
    try {
      if (typeof html5?.getRunningTrackCapabilities === "function") {
        const caps = html5.getRunningTrackCapabilities();
        canTorch = !!caps?.torch;
      } else if (typeof html5?.getRunningTrack === "function") {
        const track = html5.getRunningTrack();
        const caps = track?.getCapabilities ? track.getCapabilities() : null;
        canTorch = !!caps?.torch;
      } else {
        canTorch = false;
      }
    } catch (_) {
      canTorch = false;
    }
    updateTorchButton();
  }

  async function toggleTorch() {
    if (!html5 || !html5.isScanning) {
      toast("Сначала запусти камеру");
      return;
    }
    if (!canTorch) {
      toast("Фонарик недоступен на этом устройстве");
      return;
    }

    torchOn = !torchOn;

    try {
      if (typeof html5.applyVideoConstraints === "function") {
        await html5.applyVideoConstraints({ advanced: [{ torch: torchOn }] });
      } else if (typeof html5.getRunningTrack === "function") {
        const track = html5.getRunningTrack();
        if (!track?.applyConstraints) throw new Error("no_applyConstraints");
        await track.applyConstraints({ advanced: [{ torch: torchOn }] });
      } else {
        throw new Error("no_torch_api");
      }

      updateTorchButton();
      toast(torchOn ? "Фонарик включён" : "Фонарик выключен");
      haptic(10);
    } catch (e) {
      console.error("toggleTorch error:", e);
      torchOn = false;
      updateTorchButton();
      toast("Не удалось включить подсветку");
    }
  }

  async function startScanner(preferredCameraId = null) {
    if (!window.Html5Qrcode) {
      setStatus("Сканер не загрузился (нет Html5Qrcode)", "err");
      return;
    }

    try {
      setStatus("Запрашиваем доступ к камере…", "work");

      if (!cameras.length) await listCameras();

      currentCameraId =
        preferredCameraId ||
        currentCameraId ||
        (cameras[cameras.length - 1] && cameras[cameras.length - 1].id) ||
        cameras[0].id;

      if (!html5) html5 = new window.Html5Qrcode("vpReader");

      const config = { fps: 12, qrbox: { width: 260, height: 260 } };

      let last = { code: "", ts: 0 };

      await html5.start(
        { deviceId: { exact: currentCameraId } },
        config,
        async (decodedText) => {
          const now = Date.now();
          const code = normalizeCode(decodedText);
          if (!code) return;

          if (code !== last.code || now - last.ts > 1500) {
            last = { code, ts: now };
            await stopScanner();
            lookupAndRender(code);
          }
        }
      );

      elStopBtn && (elStopBtn.hidden = false);
      elStartBtn && (elStartBtn.hidden = true);
      elSwitchBtn && (elSwitchBtn.hidden = cameras.length < 2);

      await detectTorchSupport();

      setStatus("Сканер запущен. Наведи камеру на QR-код.", "ok");
      toast("Камера включена");
      haptic(15);
    } catch (e) {
      console.error("startScanner error:", e);
      const msg = String(e?.name || e?.message || "");
      if (msg.includes("NotAllowed")) {
        setStatus("Нет доступа к камере. Разреши доступ и попробуй снова.", "warn");
        toast("Нет доступа к камере");
      } else {
        setStatus("Не удалось запустить камеру", "err");
        toast("Ошибка камеры");
      }
    }
  }

  async function stopScanner() {
    try {
      if (html5 && html5.isScanning) await html5.stop();
    } catch (_) {}
    try {
      if (html5) await html5.clear();
    } catch (_) {}

    torchOn = false;
    canTorch = false;
    updateTorchButton();

    elStopBtn && (elStopBtn.hidden = true);
    elSwitchBtn && (elSwitchBtn.hidden = true);
    elStartBtn && (elStartBtn.hidden = false);

    setStatus("Камера остановлена", "info");
  }

  async function switchCamera() {
    if (!cameras.length) {
      try {
        await listCameras();
      } catch (_) {
        return;
      }
    }
    if (cameras.length < 2) {
      toast("Доступна одна камера");
      return;
    }
    const idx = cameras.findIndex((c) => c.id === currentCameraId);
    const next = cameras[(idx + 1) % cameras.length];
    await stopScanner();
    await startScanner(next.id);
  }

  // ---------- Scanner UI wiring
  elStartBtn?.addEventListener("click", () => startScanner());
  elStopBtn?.addEventListener("click", () => stopScanner());
  elSwitchBtn?.addEventListener("click", () => switchCamera());
  elTorchBtn?.addEventListener("click", () => toggleTorch());

  // ---------- Cache reset button (SW + CacheStorage)
  elHardReload?.addEventListener("click", async () => {
    const ok = confirm(
      "Сбросить кэш (PWA) и обновить страницу? Это поможет получить новую версию интерфейса."
    );
    if (!ok) return;

    try {
      if ("caches" in window) {
        const keys = await caches.keys();
        await Promise.all(keys.map((k) => caches.delete(k)));
      }
    } catch (_) {}

    try {
      if (navigator.serviceWorker?.controller) {
        navigator.serviceWorker.controller.postMessage({ type: "VP_HARD_RELOAD" });
      }
    } catch (_) {}

    // принудительное обновление
    location.reload(true);
  });

  // ---------- Init
  renderHistory();
  
  // Auto-lookup by ?code=
try {
  const params = new URLSearchParams(window.location.search);
  const codeParam = params.get("code");
  const code = normalizeCode(codeParam);
  if (code) {
    if (elManualInput) elManualInput.value = code;
    lookupAndRender(code);
  }
} catch (e) {}

  
  updateTorchButton();
  hideResult();
})();
