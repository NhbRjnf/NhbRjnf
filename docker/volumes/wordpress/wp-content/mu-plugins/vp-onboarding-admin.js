(function () {
  const DEFAULT_LIMIT = Number(VPOnboardingAdmin.defaultLimit || 20);
  const SEARCH_DEBOUNCE_MS = 350;

  const state = {
    offset: 0,
    limit: [20, 50, 100].includes(DEFAULT_LIMIT) ? DEFAULT_LIMIT : 20,
    total: 0,
    loading: false,
    rows: [],
    activeRequestId: 0,
    currentModalClose: null,
  };

  const el = {
    notice: document.getElementById('vp-onboarding-notice'),
    status: document.getElementById('vp-status-filter'),
    userType: document.getElementById('vp-user-type-filter'),
    search: document.getElementById('vp-search-filter'),
    limit: document.getElementById('vp-limit-select'),
    reset: document.getElementById('vp-reset-filters'),
    tableBody: document.querySelector('#vp-onboarding-table tbody'),
    tableLoading: document.getElementById('vp-table-loading'),
    prev: document.getElementById('vp-prev-page'),
    next: document.getElementById('vp-next-page'),
    pageInfo: document.getElementById('vp-page-info'),
    modalRoot: document.getElementById('vp-modal-root'),
  };

  let searchTimer = null;

  function escapeHtml(text) {
    const node = document.createElement('div');
    node.textContent = text == null ? '' : String(text);
    return node.innerHTML;
  }

  function formatDate(iso) {
    if (!iso) {
      return { label: '—', iso: '' };
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
      return { label: String(iso), iso: String(iso) };
    }

    return {
      label: new Intl.DateTimeFormat('ru-RU', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
      }).format(date),
      iso: date.toISOString(),
    };
  }

  function fullName(row) {
    return [row.first_name || '', row.last_name || ''].join(' ').trim() || '—';
  }

  function statusBadge(status) {
    const normalized = status || 'unknown';
    return `<span class="vp-badge vp-badge--status vp-badge--${escapeHtml(normalized)}">${escapeHtml(normalized)}</span>`;
  }

  function userTypeBadge(value) {
    const label = value || '—';
    return `<span class="vp-badge vp-badge--user-type">${escapeHtml(label)}</span>`;
  }

  function isReviewed(row) {
    return row.status === 'approved' || row.status === 'rejected';
  }

  function reviewedLabel(row) {
    if (!row.reviewed_at && !row.reviewed_by_email && !row.reviewed_by_wp_login) {
      return '—';
    }

    const date = formatDate(row.reviewed_at);
    const actor = row.reviewed_by_email || row.reviewed_by_wp_login || 'moderator';
    return `${escapeHtml(actor)} · ${escapeHtml(date.label)}`;
  }

  function showNotice(message, type = 'success') {
    const cssClass = type === 'error' ? 'notice notice-error' : 'notice notice-success';
    el.notice.className = cssClass;
    el.notice.innerHTML = `<p>${escapeHtml(message)}</p>`;
    el.notice.style.display = 'block';
  }

  function clearNotice() {
    el.notice.className = '';
    el.notice.innerHTML = '';
    el.notice.style.display = 'none';
  }

  function setLoading(flag) {
    state.loading = flag;
    el.tableLoading.hidden = !flag;
    el.prev.disabled = flag || state.offset === 0;
    el.next.disabled = flag || state.offset + state.limit >= state.total;
  }

  function renderPagination() {
    const from = state.total === 0 ? 0 : state.offset + 1;
    const to = Math.min(state.offset + state.limit, state.total);
    el.pageInfo.textContent = `Показано ${from}–${to} из ${state.total}`;
    el.prev.disabled = state.loading || state.offset === 0;
    el.next.disabled = state.loading || state.offset + state.limit >= state.total;
  }

  function renderEmpty(text = 'Заявки по текущим фильтрам не найдены.') {
    el.tableBody.innerHTML = `<tr><td colspan="9" class="vp-empty">${escapeHtml(text)}</td></tr>`;
  }

  function rowActionButtons(row) {
    const reviewed = isReviewed(row);
    const title = reviewed ? 'Заявка уже рассмотрена' : '';

    return `
      <div class="vp-row-actions">
        <button class="button button-small vp-action" data-action="approve" data-id="${Number(row.id)}" ${reviewed ? 'disabled' : ''} title="${escapeHtml(title)}">Approve</button>
        <button class="button button-small vp-action" data-action="reject" data-id="${Number(row.id)}" ${reviewed ? 'disabled' : ''} title="${escapeHtml(title)}">Reject</button>
        <button class="button button-small vp-action" data-action="details" data-id="${Number(row.id)}">Details</button>
      </div>
    `;
  }

  function renderTable(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
      renderEmpty();
      return;
    }

    el.tableBody.innerHTML = rows
      .map((row) => {
        const created = formatDate(row.created_at);
        return `
          <tr class="vp-row" data-id="${Number(row.id)}" tabindex="0" role="button" aria-label="Открыть детали заявки #${Number(row.id)}">
            <td title="${escapeHtml(created.iso)}">${escapeHtml(created.label)}</td>
            <td>${statusBadge(row.status)}</td>
            <td>${userTypeBadge(row.user_type)}</td>
            <td>${escapeHtml(fullName(row))}</td>
            <td>${escapeHtml(row.email || '—')}</td>
            <td>${escapeHtml(row.phone || '—')}</td>
            <td>${escapeHtml(row.auto_approve_method || '—')}</td>
            <td>${reviewedLabel(row)}</td>
            <td>${rowActionButtons(row)}</td>
          </tr>
        `;
      })
      .join('');
  }

  function currentFilters() {
    return {
      status: el.status.value.trim(),
      user_type: el.userType.value.trim(),
      search: el.search.value.trim(),
      offset: String(state.offset),
      limit: String(state.limit),
    };
  }

  async function post(params) {
    const response = await fetch(VPOnboardingAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: params.toString(),
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return response.json();
  }

  async function fetchRequests() {
    const requestId = ++state.activeRequestId;
    setLoading(true);

    const params = new URLSearchParams();
    params.append('action', 'vp_onboarding_fetch_requests');
    params.append('nonce', VPOnboardingAdmin.nonce);

    const filters = currentFilters();
    Object.keys(filters).forEach((key) => params.append(key, filters[key]));

    try {
      const json = await post(params);

      if (requestId !== state.activeRequestId) {
        return;
      }

      if (!json.success) {
        throw new Error(json.data?.message || 'Не удалось загрузить заявки.');
      }

      state.rows = Array.isArray(json.data?.rows) ? json.data.rows : [];
      state.total = Number(json.data?.filter_count || 0);
      clearNotice();
      renderTable(state.rows);
      renderPagination();
    } catch (error) {
      if (requestId !== state.activeRequestId) {
        return;
      }

      console.error('[VP Onboarding] fetch failed', error);
      state.rows = [];
      state.total = 0;
      renderEmpty('Ошибка загрузки заявок. Попробуйте обновить список.');
      renderPagination();
      showNotice(error.message || 'Ошибка загрузки заявок.', 'error');
    } finally {
      if (requestId === state.activeRequestId) {
        setLoading(false);
        renderPagination();
      }
    }
  }

  function findRowById(id) {
    return state.rows.find((row) => Number(row.id) === Number(id));
  }

  function closeModal() {
    if (typeof state.currentModalClose === 'function') {
      state.currentModalClose();
    }
  }

  function openModal(contentHtml, onOpen) {
    const overlay = document.createElement('div');
    overlay.className = 'vp-modal-overlay';
    overlay.innerHTML = `
      <div class="vp-modal" role="dialog" aria-modal="true">
        ${contentHtml}
      </div>
    `;

    const remove = () => {
      el.modalRoot.hidden = true;
      el.modalRoot.innerHTML = '';
      state.currentModalClose = null;
      document.removeEventListener('keydown', handleEsc);
    };

    const handleEsc = (event) => {
      if (event.key === 'Escape') {
        remove();
      }
    };

    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        remove();
      }
    });

    el.modalRoot.hidden = false;
    el.modalRoot.innerHTML = '';
    el.modalRoot.appendChild(overlay);
    document.addEventListener('keydown', handleEsc);

    state.currentModalClose = remove;

    if (typeof onOpen === 'function') {
      onOpen(overlay, remove);
    }
  }

  async function decideRequest(id, decision, reason) {
    const params = new URLSearchParams();
    params.append('action', 'vp_onboarding_decide_request');
    params.append('nonce', VPOnboardingAdmin.nonce);
    params.append('id', String(id));
    params.append('decision', decision);
    params.append('reason', reason || '');

    const json = await post(params);
    if (!json.success) {
      throw new Error(json.data?.message || 'Не удалось обновить заявку.');
    }

    return json.data?.result || {};
  }

  function openDetails(row) {
    const created = formatDate(row.created_at);
    const reviewed = formatDate(row.reviewed_at);
    const decisionReason = row.decision_reason || '—';

    openModal(`
      <div class="vp-modal__header">
        <h2>Заявка #${Number(row.id)}</h2>
        <button type="button" class="button-link vp-modal-close" data-close>Закрыть</button>
      </div>
      <div class="vp-modal__content">
        <dl class="vp-details-grid">
          <dt>Status</dt><dd>${escapeHtml(row.status || '—')}</dd>
          <dt>User type</dt><dd>${escapeHtml(row.user_type || '—')}</dd>
          <dt>Full name</dt><dd>${escapeHtml(fullName(row))}</dd>
          <dt>Email</dt><dd>${escapeHtml(row.email || '—')}</dd>
          <dt>Phone</dt><dd>${escapeHtml(row.phone || '—')}</dd>
          <dt>Created at</dt><dd title="${escapeHtml(created.iso)}">${escapeHtml(created.label)}</dd>
          <dt>Auto-approve method</dt><dd>${escapeHtml(row.auto_approve_method || '—')}</dd>
          <dt>Reviewed by</dt><dd>${escapeHtml(row.reviewed_by_email || row.reviewed_by_wp_login || '—')}</dd>
          <dt>Reviewed at</dt><dd title="${escapeHtml(reviewed.iso)}">${escapeHtml(reviewed.label)}</dd>
          <dt>Decision reason</dt><dd>${escapeHtml(decisionReason)}</dd>
        </dl>
      </div>
    `, (overlay, close) => {
      overlay.querySelector('[data-close]')?.addEventListener('click', close);
    });
  }

  function openApproveModal(row) {
    openModal(`
      <div class="vp-modal__header">
        <h2>Подтвердить approve</h2>
        <button type="button" class="button-link vp-modal-close" data-close>Закрыть</button>
      </div>
      <div class="vp-modal__content">
        <p>Будет создан или привязан Directus user + profile, после чего заявка перейдёт в статус <strong>approved</strong>.</p>
        <p><strong>${escapeHtml(fullName(row))}</strong> · ${escapeHtml(row.email || 'без email')}</p>
      </div>
      <div class="vp-modal__footer">
        <button type="button" class="button" data-close>Отмена</button>
        <button type="button" class="button button-primary" data-confirm>Подтвердить approve</button>
      </div>
    `, (overlay, close) => {
      overlay.querySelectorAll('[data-close]').forEach((btn) => btn.addEventListener('click', close));
      const confirmButton = overlay.querySelector('[data-confirm]');

      confirmButton?.addEventListener('click', async () => {
        confirmButton.disabled = true;
        confirmButton.textContent = 'Сохраняем...';

        try {
          const result = await decideRequest(row.id, 'approve', '');
          close();
          const suffix = result.dry_run ? ' (dry-run)' : '';
          showNotice((result.message || 'Заявка одобрена.') + suffix, 'success');
          fetchRequests();
        } catch (error) {
          console.error('[VP Onboarding] approve failed', error);
          showNotice(error.message || 'Не удалось одобрить заявку.', 'error');
          confirmButton.disabled = false;
          confirmButton.textContent = 'Подтвердить approve';
        }
      });
    });
  }

  function openRejectModal(row) {
    openModal(`
      <div class="vp-modal__header">
        <h2>Отклонить заявку #${Number(row.id)}</h2>
        <button type="button" class="button-link vp-modal-close" data-close>Закрыть</button>
      </div>
      <div class="vp-modal__content">
        <label class="vp-filter-control" for="vp-reject-reason">
          <span class="vp-filter-label">Причина отказа <span class="description">(обязательно)</span></span>
          <textarea id="vp-reject-reason" rows="4" placeholder="Укажите причину отказа"></textarea>
        </label>
      </div>
      <div class="vp-modal__footer">
        <button type="button" class="button" data-close>Отмена</button>
        <button type="button" class="button button-primary" data-confirm disabled>Подтвердить reject</button>
      </div>
    `, (overlay, close) => {
      overlay.querySelectorAll('[data-close]').forEach((btn) => btn.addEventListener('click', close));
      const textarea = overlay.querySelector('#vp-reject-reason');
      const confirmButton = overlay.querySelector('[data-confirm]');

      const syncState = () => {
        const value = textarea.value.trim();
        confirmButton.disabled = value.length === 0;
      };

      textarea.addEventListener('input', syncState);
      textarea.focus();

      confirmButton?.addEventListener('click', async () => {
        const reason = textarea.value.trim();
        if (!reason) {
          syncState();
          return;
        }

        confirmButton.disabled = true;
        confirmButton.textContent = 'Сохраняем...';

        try {
          const result = await decideRequest(row.id, 'reject', reason);
          close();
          const suffix = result.dry_run ? ' (dry-run)' : '';
          showNotice((result.message || 'Заявка отклонена.') + suffix, 'success');
          fetchRequests();
        } catch (error) {
          console.error('[VP Onboarding] reject failed', error);
          showNotice(error.message || 'Не удалось отклонить заявку.', 'error');
          confirmButton.disabled = false;
          confirmButton.textContent = 'Подтвердить reject';
        }
      });
    });
  }

  function onRowAction(rowId, action) {
    const row = findRowById(rowId);
    if (!row) {
      showNotice('Заявка не найдена в текущем списке.', 'error');
      return;
    }

    if (action === 'details') {
      openDetails(row);
      return;
    }

    if (action === 'approve') {
      openApproveModal(row);
      return;
    }

    if (action === 'reject') {
      openRejectModal(row);
    }
  }

  function applyFilters() {
    state.offset = 0;
    fetchRequests();
  }

  function debounceSearch() {
    if (searchTimer) {
      clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
      applyFilters();
    }, SEARCH_DEBOUNCE_MS);
  }

  function resetFilters() {
    el.status.value = 'pending';
    el.userType.value = '';
    el.search.value = '';
    el.limit.value = String(state.limit);
    clearNotice();
    applyFilters();
  }

  function bindEvents() {
    el.status.addEventListener('change', applyFilters);
    el.userType.addEventListener('change', applyFilters);
    el.search.addEventListener('input', debounceSearch);

    el.limit.value = String(state.limit);
    el.limit.addEventListener('change', () => {
      state.limit = Number(el.limit.value || 20);
      applyFilters();
    });

    el.reset.addEventListener('click', resetFilters);

    el.prev.addEventListener('click', () => {
      if (state.loading || state.offset === 0) {
        return;
      }
      state.offset = Math.max(0, state.offset - state.limit);
      fetchRequests();
    });

    el.next.addEventListener('click', () => {
      if (state.loading || state.offset + state.limit >= state.total) {
        return;
      }
      state.offset += state.limit;
      fetchRequests();
    });

    el.tableBody.addEventListener('click', (event) => {
      const actionButton = event.target.closest('.vp-action');
      if (actionButton) {
        event.stopPropagation();
        onRowAction(Number(actionButton.dataset.id), actionButton.dataset.action);
        return;
      }

      const row = event.target.closest('.vp-row');
      if (row) {
        onRowAction(Number(row.dataset.id), 'details');
      }
    });

    el.tableBody.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }

      const row = event.target.closest('.vp-row');
      if (row) {
        event.preventDefault();
        onRowAction(Number(row.dataset.id), 'details');
      }
    });
  }

  bindEvents();
  renderPagination();

  if (VPOnboardingAdmin.isDryRun) {
    showNotice('Dry-run включён: Directus-записи не изменяются.', 'success');
  }

  fetchRequests();

  window.VPOnboardingAdminUI = {
    fetchRequests,
    closeModal,
  };
})();