(() => {
  'use strict';

  function log(...args) {
    // eslint-disable-next-line no-console
    console.log('[VP Onboarding]', ...args);
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
    return String(s)
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

    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();
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
            </select>
          </label>

          <label class="vp-onboarding-search">
            Search
            <input id="vp-search" type="text" placeholder="email / phone / name" />
          </label>

          <label>
            Limit
            <select id="vp-limit">
              <option value="25">25</option>
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

      <div id="vp-notice" class="vp-onboarding-notice" style="display:none"></div>

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
          <tbody id="vp-tbody"></tbody>
        </table>
      </div>

      <div class="vp-onboarding-pager">
        <button class="button" id="vp-prev" type="button">← Prev</button>
        <span id="vp-page"></span>
        <button class="button" id="vp-next" type="button">Next →</button>
      </div>

      <div id="vp-modal" class="vp-modal" aria-hidden="true">
        <div class="vp-modal__backdrop" data-close="1"></div>
        <div class="vp-modal__dialog" role="dialog" aria-modal="true">
          <div class="vp-modal__header">
            <h2 id="vp-modal-title"></h2>
            <button class="vp-modal__close" type="button" data-close="1">×</button>
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

  function openModal(title, bodyHtml, actionsHtml) {
    qs('#vp-modal-title').textContent = title;
    qs('#vp-modal-body').innerHTML = bodyHtml;
    qs('#vp-modal-actions').innerHTML = actionsHtml || '';
    const m = qs('#vp-modal');
    m.setAttribute('aria-hidden', 'false');
    m.classList.add('is-open');
  }

  function closeModal() {
    const m = qs('#vp-modal');
    m.setAttribute('aria-hidden', 'true');
    m.classList.remove('is-open');
  }

  function renderRows(items) {
    const tb = qs('#vp-tbody');
    if (!tb) return;
    if (!items || items.length === 0) {
      tb.innerHTML = `<tr><td colspan="9" style="padding:14px">No items</td></tr>`;
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
            <button class="button button-small" data-act="details">Details</button>
            <button class="button button-primary button-small" data-act="approve">Approve</button>
            <button class="button button-secondary button-small" data-act="reject">Reject</button>
          </td>
        </tr>
      `;
    }).join('');
  }

  function ensureOnboardingPage() {
    // MU-плагины могут быть подключены где угодно, поэтому проверяем что корень существует
    return !!document.getElementById('vp-onboarding-root');
  }

  function main() {
    // 1) Конфиг
    const cfg = window.VPOnboardingAdmin;
    if (!cfg || !cfg.ajaxUrl || !cfg.nonce || !cfg.actions) {
      error('VPOnboardingAdmin is missing. Inline config was not printed.');
      return;
    }

    // 2) Страница
    if (!ensureOnboardingPage()) {
      warn('Not on onboarding page; skip init.');
      return;
    }

    const root = qs('#vp-onboarding-root');
    buildLayout(root);

    const elStatus = qs('#vp-status');
    const elType   = qs('#vp-user-type');
    const elSearch = qs('#vp-search');
    const elLimit  = qs('#vp-limit');
    const elPrev   = qs('#vp-prev');
    const elNext   = qs('#vp-next');
    const elReset  = qs('#vp-reset');

    const missing = { elStatus, elType, elSearch, elLimit, elPrev, elNext, elReset };
    for (const [k, v] of Object.entries(missing)) {
      if (!v) {
        warn('Missing DOM elements for binding', missing);
        return;
      }
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
        if (reqId !== activeReq) return; // устаревший запрос

        if (!json || !json.success) {
          showNotice(json?.data?.message || 'List failed', 'error');
          return;
        }

        const data = json.data || {};
        total = parseInt(data.total, 10) || 0;

        renderRows(data.items || []);
        qs('#vp-total').textContent = `Total: ${total}`;
        const page = total > 0 ? `${Math.floor(offset / limit) + 1} / ${Math.max(1, Math.ceil(total / limit))}` : '0 / 0';
        qs('#vp-page').textContent = page;

        elPrev.disabled = offset <= 0;
        elNext.disabled = (offset + limit) >= total;

      } catch (e) {
        showNotice(`List error: ${e.message}`, 'error');
      }
    }

    async function decide(id, decision, reason) {
      hideNotice();
      try {
        const json = await post(cfg, cfg.actions.decide, { id, decision, reason: reason || '' });
        if (!json || !json.success) {
          const msg = json?.data?.message || 'Action failed';
          showNotice(msg, 'error');
          return;
        }
        showNotice(`OK: ${decision}`, 'success');
        closeModal();
        await load();
      } catch (e) {
        showNotice(`${decision} error: ${e.message}`, 'error');
        error(`${decision} failed`, e);
      }
    }

    function openDetails(row) {
      const id = row.getAttribute('data-id');
      const tds = row.querySelectorAll('td');
      const html = `
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
      `;
      openModal('Request details', html, `<button class="button" type="button" data-close="1">Close</button>`);
    }

    function openReject(row) {
      const id = row.getAttribute('data-id');
      const email = row.querySelectorAll('td')[4]?.innerText || '';
      openModal(
        'Reject request',
        `
          <p>Request #${escHtml(id)} (${escHtml(email)})</p>
          <label>Reason (required)</label>
          <textarea id="vp-reason" style="width:100%; min-height:90px" placeholder="Why rejected?"></textarea>
        `,
        `
          <button class="button" type="button" data-close="1">Cancel</button>
          <button class="button button-secondary" type="button" id="vp-do-reject">Reject</button>
        `
      );

      const btn = qs('#vp-do-reject');
      btn.addEventListener('click', async () => {
        const reason = (qs('#vp-reason').value || '').trim();
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
          <p class="vp-hint">Важно: email должен быть с нормальным доменом и TLD (например name@example.com). Домены .local Directus у тебя отклоняет.</p>
        `,
        `
          <button class="button" type="button" data-close="1">Cancel</button>
          <button class="button button-primary" type="button" id="vp-do-approve">Approve</button>
        `
      );

      const btn = qs('#vp-do-approve');
      btn.addEventListener('click', async () => {
        await decide(id, 'approve', '');
      });
    }

    // Bind events
    qs('#vp-modal').addEventListener('click', (e) => {
      const t = e.target;
      if (t && t.getAttribute && t.getAttribute('data-close') === '1') closeModal();
    });

    qs('#vp-tbody').addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-act]');
      if (!btn) return;
      const row = btn.closest('tr[data-id]');
      if (!row) return;

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
      offset = offset + limit;
      load();
    });

    elLimit.addEventListener('change', () => {
      limit = parseInt(elLimit.value, 10) || 25;
      offset = 0;
      load();
    });

    elStatus.addEventListener('change', () => { offset = 0; load(); });
    elType.addEventListener('change', () => { offset = 0; load(); });

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
    log('Ready.');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', main);
  } else {
    main();
  }
})();
