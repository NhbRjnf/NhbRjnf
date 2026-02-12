(() => {
  'use strict';

  const cfg = window.VP_MENU || {};

  const getCode = () => {
    const params = new URLSearchParams(window.location.search);
    return String(params.get('code') || '').trim();
  };

  const actions = {
    share: async () => {
      if (navigator.share) {
        await navigator.share({ title: document.title, url: window.location.href });
        return;
      }
      if (navigator.clipboard) {
        await navigator.clipboard.writeText(window.location.href);
      }
    },
    copyCode: async () => {
      const code = getCode();
      if (!code || !navigator.clipboard) return;
      await navigator.clipboard.writeText(code);
    },
    backToScan: () => {
      window.location.href = cfg.scanUrl || '/scan/';
    },
    openInstruction: () => {
      const openBtn = document.getElementById('vp-inst-open-btn');
      if (openBtn) {
        openBtn.click();
      }
    },
    reloadModel: () => {
      window.location.reload();
    },
    resetView: () => {
      const viewer = document.getElementById('vp3d-viewer');
      if (!viewer) return;
      if (typeof viewer.resetTurntableRotation === 'function') {
        viewer.resetTurntableRotation();
      }
      viewer.cameraOrbit = 'auto auto auto';
    },
  };

  const bindFab = (fab) => {
    const toggle = fab.querySelector('[data-vp-fab-toggle]');
    const panel = fab.querySelector('[data-vp-fab-panel]');
    if (!toggle || !panel) return;

    const close = () => {
      fab.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', () => {
      const willOpen = !fab.classList.contains('is-open');
      fab.classList.toggle('is-open', willOpen);
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    panel.addEventListener('click', async (event) => {
      const target = event.target.closest('[data-vp-action]');
      if (!target) return;
      const actionName = target.getAttribute('data-vp-action');
      const action = actions[actionName];
      if (!action) return;

      try {
        await action();
      } catch (err) {
        console.error(err);
      }
      close();
    });

    document.addEventListener('click', (event) => {
      if (!fab.contains(event.target)) {
        close();
      }
    });
  };

  const applyContext = (fab) => {
    const pageType = fab.getAttribute('data-page-type') || '';
    const rows = fab.querySelectorAll('[data-vp-pages]');
    rows.forEach((row) => {
      const pages = (row.getAttribute('data-vp-pages') || '').split(',').map((it) => it.trim());
      row.hidden = pages.length > 0 && !pages.includes(pageType);
    });
  };

  const boot = () => {
    document.querySelectorAll('[data-vp-fab]').forEach((fab) => {
      applyContext(fab);
      bindFab(fab);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
