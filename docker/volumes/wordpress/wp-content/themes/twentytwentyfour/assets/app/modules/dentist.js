export const moduleId = 'dentist';
export const title = 'Стоматолог';

const ROUTE = '#/dentist/cases';

function esc(text = '') {
  return String(text)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function statusClass(status) {
  if (status === 'new' || status === 'in_progress') return 'is-active';
  if (status === 'ready') return 'is-paused';
  return 'is-offline';
}

function statusText(status) {
  const map = {
    new: 'New',
    in_progress: 'In Progress',
    ready: 'Ready',
    closed: 'Closed',
  };
  return map[status] || status || 'Unknown';
}

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('ru-RU');
}

function renderTable(items = []) {
  if (!items.length) {
    return '<div class="vp-card"><p>Кейсы не найдены.</p></div>';
  }

  const rows = items.map((item) => `
    <tr>
      <td>${esc(item.case_code || '')}</td>
      <td>${esc(item.title || '')}</td>
      <td><span class="vp-statuspill ${statusClass(item.status)}">${esc(statusText(item.status))}</span></td>
      <td>${esc(formatDate(item.created_at))}</td>
    </tr>
  `).join('');

  return `
    <div class="vp-table-wrap">
      <table class="vp-table">
        <thead>
          <tr>
            <th>Case Code</th>
            <th>Title</th>
            <th>Status</th>
            <th>Created At</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
}

function openModal(contentEl) {
  const modal = document.createElement('div');
  modal.className = 'vp-modal';
  modal.innerHTML = `
    <div class="vp-modal-backdrop" data-close-modal="1"></div>
    <div class="vp-modal-dialog vp-card">
      <div class="vp-toolbar">
        <div class="vp-toolbar-left"><h3>Создать кейс</h3></div>
        <div class="vp-toolbar-right"><button class="vp-btn" type="button" data-close-modal="1">Закрыть</button></div>
      </div>
      <form id="vpDentistCaseForm" class="vp-form">
        <label>Title
          <input type="text" name="title" maxlength="200" required />
        </label>
        <div class="vp-form-actions">
          <button class="vp-btn is-primary" type="submit">Создать</button>
        </div>
      </form>
    </div>
  `;

  const close = () => modal.remove();
  modal.querySelectorAll('[data-close-modal="1"]').forEach((el) => {
    el.addEventListener('click', close);
  });

  contentEl.appendChild(modal);
  return { modal, close };
}

function showToast(ctx, message) {
  if (ctx?.ui?.toast) {
    ctx.ui.toast(message);
    return;
  }

  let toast = document.querySelector('.vp-toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.className = 'vp-toast';
    document.body.appendChild(toast);
  }
  toast.textContent = message;
  toast.classList.add('is-show');
  window.setTimeout(() => toast.classList.remove('is-show'), 2200);
}

async function loadCases(ctx) {
  const response = await ctx.api.request('/dentist/cases');
  return Array.isArray(response.items) ? response.items : [];
}

export function getMenu() {
  return [{ id: 'dentist-cases', label: 'Cases', route: ROUTE }];
}

export function getRoutes() {
  return [{
    route: ROUTE,
    title: 'Cases',
    render(container, ctx) {
      container.innerHTML = `
        <div class="vp-card">
          <div class="vp-toolbar">
            <div class="vp-toolbar-left"><span class="vp-badge">dentist</span></div>
            <div class="vp-toolbar-right"><button id="vpDentistCreateCase" type="button" class="vp-btn is-primary">Создать кейс</button></div>
          </div>
          <div id="vpDentistCasesState">Загрузка...</div>
        </div>
      `;

      const stateEl = container.querySelector('#vpDentistCasesState');
      const createBtn = container.querySelector('#vpDentistCreateCase');

      const refresh = async () => {
        try {
          stateEl.innerHTML = '<p>Загрузка...</p>';
          const items = await loadCases(ctx);
          stateEl.innerHTML = renderTable(items);
        } catch (err) {
          stateEl.innerHTML = `<div class="vp-card"><p>Ошибка загрузки: ${esc(err?.message || 'unknown')}</p></div>`;
        }
      };

      createBtn?.addEventListener('click', () => {
        const { modal, close } = openModal(container);
        const form = modal.querySelector('#vpDentistCaseForm');

        form?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const formData = new FormData(form);
          const title = String(formData.get('title') || '').trim();
          if (!title || title.length > 200) {
            showToast(ctx, 'Проверьте поле Title');
            return;
          }

          const submitBtn = form.querySelector('button[type="submit"]');
          if (submitBtn) submitBtn.disabled = true;

          try {
            await ctx.api.request('/dentist/cases', {
              method: 'POST',
              body: JSON.stringify({
                title,
                patient_id: null,
                clinic_id: null,
              }),
            });
            close();
            await refresh();
            showToast(ctx, 'Кейс создан');
          } catch (err) {
            showToast(ctx, `Ошибка создания: ${err?.message || 'unknown'}`);
          } finally {
            if (submitBtn) submitBtn.disabled = false;
          }
        });
      });

      refresh();
    },
  }];
}

export async function onActivate() {}