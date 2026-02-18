(() => {
  'use strict';

  function debug(...args) {
    // eslint-disable-next-line no-console
    console.debug('[VP Onboarding]', ...args);
  }

  function warn(...args) {
    // eslint-disable-next-line no-console
    console.warn('[VP Onboarding]', ...args);
  }

  function error(...args) {
    // eslint-disable-next-line no-console
    console.error('[VP Onboarding]', ...args);
  }

  function qs(sel, root = document) {
    return root.querySelector(sel);
  }

  function escHtml(s) {
    return String(s ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function debounce(fn, ms) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  function isOnboardingPage() {
    const params = new URLSearchParams(window.location.search || '');
    const page = params.get('page');
    const hasRoot = !!document.getElementById('vp-onboarding-root');
    return page === 'vp-onboarding' || hasRoot;
  }

  async function post(cfg, action, payload) {
    const fd = new URLSearchParams();
    fd.set('action', action);
    fd.set('nonce', cfg.nonce);

    Object.entries(payload || {}).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      fd.set(k, String(v));
    });

    const res = await fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: fd.toString(),
      credentials: 'same-origin',
    });

    let json = null;
    const contentType = res.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
      json = await res.json();
    } else {
      const text = await res.text();
      throw new Error(`HTTP ${res.status}: ${text.slice(0, 250) || 'non-json response'}`);
    }

    if (!res.ok) {
      const serverMessage = json?.data?.message || json?.message || `HTTP ${res.status}`;
      const err = new Error(serverMessage);
      err.status = res.status;
      err.response = json;
      throw err;
    }

    return json;
  }

  function buildLayout(root) {
    root.innerHTML = `
      <div class="vp-onboarding-toolbar">
        <div class="vp-onboarding-filters">
          <label>
            Status
            <select id="vp-status">
              <option value="pending">pending</option>
              <option value="approved">approved</option>
              <option value="rejected">rejected</option>
              <option value="all">all</option>
            </select>
          </label>

          <label>
            User type
            <select id="vp-user-type">
              <option value="all">all</option>
              <option value="dentist">dentist</option>
              <option value="auto">auto</option>
              <option value="location">location</option>
              <option value="partner">partner</option>
              <option value="moderator">moderator</option>
              <option value="admin">admin</option>
            </select>
          </label>

          <label class="vp-onboarding-search">
            Search
            <input id="vp-search" type="text" placeholder="email / phone / name" />
          </label>

          <label>
            Limit
            <select id="vp-limit">
              <option value="10">10</option>
              <option value="25" selected>25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </label>

          <button class="button" id="vp-reset" type="button">Reset</button>
        </div>
        <div class="vp-onboarding-meta">
          <span id="vp-total"></span>
        </div>
      </div>

      <div id="vp-notice" class="vp-onboarding-notice" style="display:none" role="status" aria-live="polite"></div>

      <div class="vp-onboarding-table-wrap">
        <table class="widefat fixed striped vp-onboarding-table">
          <thead>
            <tr>
              <th style="width:70px">ID</th>
              <th style="width:110px">Status</th>
              <th style="width:120px">Type</th>
              <th>Name</th>
              <th>Email</th>
              <th style="width:160px">Phone</th>
              <th style="width:170px">Created</th>
              <th style="width:170px">Reviewed</th>
              <th style="width:220px">Actions</th>
            </tr>
          </thead>
          <tbody id="vp-tbody">
            <tr><td colspan="9" style="padding:14px">Загрузка…</td></tr>
          </tbody>
        </table>
      </div>

      <div class="vp-onboarding-pager">
        <button class="button" id="vp-prev" type="button">← Prev</button>
        <span id="vp-page"></span>
        <button class="button" id="vp-next" type="button">Next →</button>
      </div>

      <div id="vp-modal" class="vp-modal" aria-hidden="true">
        <div class="vp-modal__backdrop" data-close="1"></div>
        <div class="vp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="vp-modal-title" tabindex="-1">
          <div class="vp-modal__header">
            <h2 id="vp-modal-title"></h2>
            <button class="vp-modal__close" type="button" data-close="1" aria-label="Close">×</button>
          </div>
          <div id="vp-modal-body" class="vp-modal__body"></div>
          <div id="vp-modal-actions" class="vp-modal__actions"></div>
        </div>
      </div>
    `;
  }

  function showNotice(text, kind = 'info') {
    const el = qs('#vp-notice');
    if (!el) return;
    el.className = `vp-onboarding-notice vp-onboarding-notice--${kind}`;
    el.textContent = text;
    el.style.display = 'block';
  }

  function hideNotice() {
    const el = qs('#vp-notice');
    if (!el) return;
    el.style.display = 'none';
  }

  let lastModalTrigger = null;

  function openModal(title, bodyHtml, actionsHtml) {
    const modal = qs('#vp-modal');
    const dialog = qs('.vp-modal__dialog', modal);

    qs('#vp-modal-title').textContent = title;
    qs('#vp-modal-body').innerHTML = bodyHtml;
    qs('#vp-modal-actions').innerHTML = actionsHtml || '';

    modal.removeAttribute('inert');
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('is-open');

    setTimeout(() => {
      dialog?.focus();
    }, 0);
  }

  function closeModal() {
    const modal = qs('#vp-modal');
    modal.setAttribute('aria-hidden', 'true');
    modal.setAttribute('inert', '');
    modal.classList.remove('is-open');

    if (lastModalTrigger && typeof lastModalTrigger.focus === 'function') {
      setTimeout(() => lastModalTrigger.focus(), 0);
    }
  }

  function renderRows(items) {
    const tb = qs('#vp-tbody');
    if (!tb) return;

    if (!items || items.length === 0) {
      tb.innerHTML = '<tr><td colspan="9" style="padding:14px">No items</td></tr>';
      return;
    }

    tb.innerHTML = items.map((r) => {
      const fullName = `${r.first_name || ''} ${r.last_name || ''}`.trim();
      const status = r.status || '';
      const ut = r.user_type || '';
      const created = r.created_at ? new Date(r.created_at).toLocaleString() : '';
      const reviewed = r.reviewed_at ? new Date(r.reviewed_at).toLocaleString() : '';

      return `
        <tr data-id="${escHtml(r.id)}">
          <td>${escHtml(r.id)}</td>
          <td><span class="vp-badge vp-badge--${escHtml(status)}">${escHtml(status)}</span></td>
          <td>${escHtml(ut)}</td>
          <td>${escHtml(fullName)}</td>
          <td>${escHtml(r.email || '')}</td>
          <td>${escHtml(r.phone || '')}</td>
          <td>${escHtml(created)}</td>
          <td>${escHtml(reviewed)}</td>
          <td class="vp-actions">
            <button class="button button-small" data-act="details" type="button">Details</button>
            <button class="button button-primary button-small" data-act="approve" type="button">Approve</button>
            <button class="button button-secondary button-small" data-act="reject" type="button">Reject</button>
          </td>
        </tr>
      `;
    }).join('');
  }

  function safeServerMessage(json, fallback) {
    return json?.data?.message || json?.message || fallback;
  }

  function bootstrapError(root, message) {
    if (!root) return;
    root.innerHTML = `<div class="vp-onboarding-notice vp-onboarding-notice--error" role="alert">${escHtml(message)}</div>`;
  }

  function main() {
    if (!isOnboardingPage()) {
      return;
    }

    const root = qs('#vp-onboarding-root');
    if (!root) {
      warn('Onboarding page detected but #vp-onboarding-root not found.');
      return;
    }

    const cfg = window.VPOnboardingAdmin;
    if (!cfg || !cfg.ajaxUrl || !cfg.nonce || !cfg.actions) {
      const msg = 'Ошибка инициализации: объект VPOnboardingAdmin не найден.';
      error(msg);
      bootstrapError(root, msg);
      return;
    }

    buildLayout(root);

    const elStatus = qs('#vp-status');
    const elType = qs('#vp-user-type');
    const elSearch = qs('#vp-search');
    const elLimit = qs('#vp-limit');
    const elPrev = qs('#vp-prev');
    const elNext = qs('#vp-next');
    const elReset = qs('#vp-reset');

    if (!elStatus || !elType || !elSearch || !elLimit || !elPrev || !elNext || !elReset) {
      error('Missing required UI elements for onboarding admin.');
      bootstrapError(root, 'Ошибка отрисовки интерфейса onboarding.');
      return;
    }

    let offset = 0;
    let limit = parseInt(elLimit.value, 10) || 25;
    let total = 0;
    let activeReq = 0;

    async function load() {
      hideNotice();
      const reqId = ++activeReq;

      const payload = {
        status: elStatus.value,
        user_type: elType.value,
        search: elSearch.value.trim(),
        offset,
        limit,
      };

      try {
        const json = await post(cfg, cfg.actions.list, payload);
        if (reqId !== activeReq) return;

        if (!json || !json.success) {
          showNotice(safeServerMessage(json, 'List failed'), 'error');
          return;
        }

        const data = json.data || {};
        const meta = data.meta || {};
        total = Number(meta.filter_count ?? meta.total_count ?? 0) || 0;

        renderRows(data.items || []);
        qs('#vp-total').textContent = `Total: ${total}`;

        const totalPages = Math.max(1, Math.ceil(total / limit));
        const currentPage = total > 0 ? Math.floor(offset / limit) + 1 : 1;
        qs('#vp-page').textContent = `${currentPage} / ${totalPages}`;

        elPrev.disabled = offset <= 0;
        elNext.disabled = offset + limit >= total;
      } catch (e) {
        showNotice(`List error: ${e.message}`, 'error');
        error('list failed', e);
      }
    }

    async function decide(id, decision, reason) {
      hideNotice();
      try {
        const json = await post(cfg, cfg.actions.decide, { id, decision, reason: reason || '' });
        if (!json || !json.success) {
          showNotice(safeServerMessage(json, 'Action failed'), 'error');
          return;
        }

        showNotice(`OK: ${decision}`, 'success');
        closeModal();
        await load();
      } catch (e) {
        const msg = e?.response?.data?.message || e.message || `${decision} failed`;
        showNotice(msg, 'error');
        error(`${decision} failed`, e);
      }
    }

    function openDetails(row) {
      const id = row.getAttribute('data-id');
      const tds = row.querySelectorAll('td');

      openModal(
        'Request details',
        `
        <div class="vp-details">
          <div><b>ID:</b> ${escHtml(id)}</div>
          <div><b>Status:</b> ${escHtml(tds[1]?.innerText || '')}</div>
          <div><b>User type:</b> ${escHtml(tds[2]?.innerText || '')}</div>
          <div><b>Name:</b> ${escHtml(tds[3]?.innerText || '')}</div>
          <div><b>Email:</b> ${escHtml(tds[4]?.innerText || '')}</div>
          <div><b>Phone:</b> ${escHtml(tds[5]?.innerText || '')}</div>
          <div><b>Created:</b> ${escHtml(tds[6]?.innerText || '')}</div>
          <div><b>Reviewed:</b> ${escHtml(tds[7]?.innerText || '')}</div>
        </div>
      `,
        '<button class="button" type="button" data-close="1">Close</button>'
      );
    }

    function openReject(row) {
      const id = row.getAttribute('data-id');
      const email = row.querySelectorAll('td')[4]?.innerText || '';

      openModal(
        'Reject request',
        `
          <p>Request #${escHtml(id)} (${escHtml(email)})</p>
          <label for="vp-reason">Reason (required)</label>
          <textarea id="vp-reason" style="width:100%; min-height:90px" placeholder="Why rejected?"></textarea>
        `,
        `
          <button class="button" type="button" data-close="1">Cancel</button>
          <button class="button button-secondary" type="button" id="vp-do-reject">Reject</button>
        `
      );

      const btn = qs('#vp-do-reject');
      btn?.addEventListener('click', async () => {
        const reason = (qs('#vp-reason')?.value || '').trim();
        if (!reason) {
          showNotice('Reason is required for reject.', 'error');
          return;
        }
        await decide(id, 'reject', reason);
      });
    }

    function openApprove(row) {
      const id = row.getAttribute('data-id');
      const email = row.querySelectorAll('td')[4]?.innerText || '';

      openModal(
        'Approve request',
        `
          <p>Approve request #${escHtml(id)} (${escHtml(email)})</p>
          <p class="vp-hint">Email должен быть валидным для Directus (например user@example.com).</p>
        `,
        `
          <button class="button" type="button" data-close="1">Cancel</button>
          <button class="button button-primary" type="button" id="vp-do-approve">Approve</button>
        `
      );

      const btn = qs('#vp-do-approve');
      btn?.addEventListener('click', async () => {
        await decide(id, 'approve', '');
      });
    }

    qs('#vp-modal')?.addEventListener('click', (e) => {
      const t = e.target;
      if (t && t.getAttribute && t.getAttribute('data-close') === '1') {
        closeModal();
      }
    });

    qs('#vp-tbody')?.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-act]');
      if (!btn) return;

      const row = btn.closest('tr[data-id]');
      if (!row) return;

      lastModalTrigger = btn;

      const act = btn.getAttribute('data-act');
      if (act === 'details') openDetails(row);
      if (act === 'reject') openReject(row);
      if (act === 'approve') openApprove(row);
    });

    elPrev.addEventListener('click', () => {
      offset = Math.max(0, offset - limit);
      load();
    });

    elNext.addEventListener('click', () => {
      offset += limit;
      load();
    });

    elLimit.addEventListener('change', () => {
      limit = parseInt(elLimit.value, 10) || 25;
      offset = 0;
      load();
    });

    elStatus.addEventListener('change', () => {
      offset = 0;
      load();
    });

    elType.addEventListener('change', () => {
      offset = 0;
      load();
    });

    elReset.addEventListener('click', () => {
      elStatus.value = 'pending';
      elType.value = 'all';
      elSearch.value = '';
      elLimit.value = '25';
      limit = 25;
      offset = 0;
      load();
    });

    elSearch.addEventListener('input', debounce(() => {
      offset = 0;
      load();
    }, 300));

    load();
    debug('Ready.');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', main);
  } else {
    main();
  }
})();