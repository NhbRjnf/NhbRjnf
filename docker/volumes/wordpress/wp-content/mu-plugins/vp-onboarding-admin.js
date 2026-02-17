(function () {
  const state = {
    offset: 0,
    limit: Number(VPOnboardingAdmin.defaultLimit || 20),
    total: 0,
    loading: false,
  };

  const el = {
    notice: document.getElementById('vp-onboarding-notice'),
    status: document.getElementById('vp-status-filter'),
    userType: document.getElementById('vp-user-type-filter'),
    search: document.getElementById('vp-search-filter'),
    apply: document.getElementById('vp-apply-filters'),
    tableBody: document.querySelector('#vp-onboarding-table tbody'),
    prev: document.getElementById('vp-prev-page'),
    next: document.getElementById('vp-next-page'),
    pageInfo: document.getElementById('vp-page-info'),
  };

  function selectedStatuses() {
    return Array.from(el.status.selectedOptions).map((opt) => opt.value);
  }

  function showNotice(message, type) {
    el.notice.className = `notice ${type === 'error' ? 'notice-error' : 'notice-success'}`;
    el.notice.textContent = message;
    el.notice.style.display = 'block';
  }

  function clearNotice() {
    el.notice.style.display = 'none';
    el.notice.textContent = '';
  }

  function updatePagination() {
    const page = Math.floor(state.offset / state.limit) + 1;
    const maxPage = Math.max(1, Math.ceil(state.total / state.limit));

    el.pageInfo.textContent = `Page ${page} of ${maxPage} (${state.total} total)`;
    el.prev.disabled = state.offset === 0 || state.loading;
    el.next.disabled = state.loading || (state.offset + state.limit >= state.total);
  }

  function rowActions(item) {
    if (item.status === 'approved' || item.status === 'rejected') {
      return '<span class="vp-muted">Reviewed</span>';
    }

    return `
      <button class="button button-small vp-approve" data-id="${item.id}">Approve</button>
      <button class="button button-small vp-reject" data-id="${item.id}">Reject</button>
    `;
  }

  function escapeHtml(text) {
    const node = document.createElement('div');
    node.textContent = text || '';
    return node.innerHTML;
  }

  function formatReviewed(item) {
    if (!item.reviewed_at && !item.reviewed_by_email) {
      return '';
    }

    const parts = [];
    if (item.reviewed_by_email) {
      parts.push(item.reviewed_by_email);
    }
    if (item.reviewed_at) {
      parts.push(item.reviewed_at);
    }

    return parts.join(' · ');
  }

  function renderRows(rows) {
    if (!rows.length) {
      el.tableBody.innerHTML = '<tr><td colspan="10">No requests found.</td></tr>';
      return;
    }

    el.tableBody.innerHTML = rows.map((item) => {
      const fullName = [item.first_name || '', item.last_name || ''].join(' ').trim();
      return `
        <tr>
          <td>${item.id}</td>
          <td>${escapeHtml(item.created_at || '')}</td>
          <td>${escapeHtml(item.status || '')}</td>
          <td>${escapeHtml(item.user_type || '')}</td>
          <td>${escapeHtml(item.email || '')}</td>
          <td>${escapeHtml(item.phone || '')}</td>
          <td>${escapeHtml(fullName)}</td>
          <td>${escapeHtml(item.decision_reason || '')}</td>
          <td>${escapeHtml(formatReviewed(item))}</td>
          <td>${rowActions(item)}</td>
        </tr>
      `;
    }).join('');
  }

  async function post(params) {
    const res = await fetch(VPOnboardingAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: params.toString(),
    });

    return res.json();
  }

  async function fetchRows() {
    state.loading = true;
    updatePagination();

    const params = new URLSearchParams();
    params.append('action', 'vp_onboarding_fetch_requests');
    params.append('nonce', VPOnboardingAdmin.nonce);
    params.append('limit', String(state.limit));
    params.append('offset', String(state.offset));
    params.append('user_type', el.userType.value.trim());
    params.append('search', el.search.value.trim());

    selectedStatuses().forEach((status) => params.append('statuses[]', status));

    try {
      clearNotice();
      el.tableBody.innerHTML = '<tr><td colspan="10">Loading...</td></tr>';

      const json = await post(params);
      if (!json.success) {
        throw new Error(json.data?.message || 'Failed to fetch requests.');
      }

      state.total = Number(json.data.filter_count || 0);
      renderRows(json.data.rows || []);
      updatePagination();
    } catch (error) {
      showNotice(error.message, 'error');
      el.tableBody.innerHTML = '<tr><td colspan="10">Failed to load requests.</td></tr>';
    } finally {
      state.loading = false;
      updatePagination();
    }
  }

  async function decide(id, decision) {
    let reason = '';
    if (decision === 'reject') {
      reason = window.prompt('Enter rejection reason (required):', '');
      if (reason === null) {
        return;
      }

      if (!reason.trim()) {
        showNotice('Rejection reason is required.', 'error');
        return;
      }
    }

    const params = new URLSearchParams();
    params.append('action', 'vp_onboarding_decide_request');
    params.append('nonce', VPOnboardingAdmin.nonce);
    params.append('id', String(id));
    params.append('decision', decision);
    params.append('reason', reason.trim());

    try {
      const json = await post(params);

      if (!json.success) {
        throw new Error(json.data?.message || 'Action failed.');
      }

      showNotice(json.data?.message || 'Updated.', 'success');
      fetchRows();
    } catch (error) {
      showNotice(error.message, 'error');
    }
  }

  el.apply.addEventListener('click', () => {
    state.offset = 0;
    fetchRows();
  });

  el.prev.addEventListener('click', () => {
    state.offset = Math.max(0, state.offset - state.limit);
    fetchRows();
  });

  el.next.addEventListener('click', () => {
    state.offset = state.offset + state.limit;
    fetchRows();
  });

  el.tableBody.addEventListener('click', (event) => {
    const approveBtn = event.target.closest('.vp-approve');
    if (approveBtn) {
      decide(Number(approveBtn.dataset.id), 'approve');
      return;
    }

    const rejectBtn = event.target.closest('.vp-reject');
    if (rejectBtn) {
      decide(Number(rejectBtn.dataset.id), 'reject');
    }
  });

  fetchRows();
})();