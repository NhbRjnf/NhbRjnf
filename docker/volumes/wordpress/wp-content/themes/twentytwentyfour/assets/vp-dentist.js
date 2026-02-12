(function () {
  const api = {
    login: '/wp-json/vp/v1/dentist/login',
    logout: '/wp-json/vp/v1/dentist/logout',
    me: '/wp-json/vp/v1/dentist/me',
    tenants: '/wp-json/vp/v1/dentist/tenants',
    setTenant: '/wp-json/vp/v1/dentist/tenant',
    clinics: '/wp-json/vp/v1/dentist/clinics',
    cases: '/wp-json/vp/v1/dentist/cases'
  };

  const state = {
    tenants: [],
    activeTenant: ''
  };

  function text(el, value) {
    if (el) {
      el.textContent = value;
    }
  }

  async function request(url, options) {
    const res = await fetch(url, Object.assign({
      credentials: 'include'
    }, options || {}));

    let data = {};
    try {
      data = await res.json();
    } catch (e) {
      data = {};
    }

    if (!res.ok) {
      const err = new Error(data.error || data.message || 'Ошибка запроса');
      err.status = res.status;
      throw err;
    }

    return data;
  }

  function renderCases(items) {
    const list = document.getElementById('vpDentistCasesList');
    const stateEl = document.getElementById('vpDentistCasesState');
    if (!list || !stateEl) {
      return;
    }

    list.innerHTML = '';

    if (!Array.isArray(items) || items.length === 0) {
      text(stateEl, 'Кейсов пока нет');
      return;
    }

    text(stateEl, '');

    items.forEach((item) => {
      const card = document.createElement('article');
      card.className = 'vp-d-case-card';

      const top = document.createElement('div');
      top.className = 'vp-d-case-top';

      const title = document.createElement('h3');
      title.className = 'vp-d-case-title';
      title.textContent = item.title || 'Без названия';

      const status = document.createElement('span');
      status.className = 'vp-d-badge';
      status.textContent = item.status || 'draft';

      top.appendChild(title);
      top.appendChild(status);

      const meta = document.createElement('div');
      meta.className = 'vp-d-case-meta';
      const created = item.created_at ? new Date(item.created_at).toLocaleString() : '—';
      meta.textContent = 'Создан: ' + created + (item.clinic_name ? ' • ' + item.clinic_name : '');

      const actions = document.createElement('div');
      actions.className = 'vp-d-case-actions';

      if (item.has_3d && item.open_url) {
        const openBtn = document.createElement('a');
        openBtn.className = 'vp-btn vp-primary';
        openBtn.href = item.open_url;
        openBtn.textContent = 'Открыть';
        actions.appendChild(openBtn);
      } else {
        const note = document.createElement('span');
        note.textContent = '3D не готово';
        actions.appendChild(note);

        const uploadBtn = document.createElement('button');
        uploadBtn.className = 'vp-btn';
        uploadBtn.type = 'button';
        uploadBtn.textContent = 'Загрузить скан';
        uploadBtn.addEventListener('click', () => openUploadDialog(item.id));
        actions.appendChild(uploadBtn);
      }

      card.appendChild(top);
      card.appendChild(meta);
      card.appendChild(actions);
      list.appendChild(card);
    });
  }

  async function loadCases() {
    const stateEl = document.getElementById('vpDentistCasesState');
    text(stateEl, 'Загрузка...');

    try {
      const data = await request(api.cases);
      renderCases(data.items || []);
    } catch (e) {
      text(stateEl, e.message || 'Ошибка загрузки кейсов');
    }
  }

  async function loadClinics() {
    const select = document.getElementById('vpCaseClinic');
    if (!select) {
      return;
    }

    select.innerHTML = '<option value="">Без клиники</option>';

    try {
      const data = await request(api.clinics);
      (data.items || []).forEach((clinic) => {
        const opt = document.createElement('option');
        opt.value = clinic.id;
        opt.textContent = clinic.name || clinic.id;
        select.appendChild(opt);
      });
    } catch (e) {
      // silent fallback
    }
  }

  async function loadTenants() {
    const tenantSelect = document.getElementById('vpDentistTenant');
    if (!tenantSelect) {
      return;
    }

    tenantSelect.innerHTML = '<option value="">Загрузка...</option>';

    const data = await request(api.tenants);
    state.tenants = data.tenants || [];
    state.activeTenant = data.active_tenant || '';

    tenantSelect.innerHTML = '';
    if (state.tenants.length === 0) {
      tenantSelect.innerHTML = '<option value="">Нет доступа к организациям</option>';
      return;
    }

    state.tenants.forEach((tenant) => {
      const opt = document.createElement('option');
      opt.value = tenant.id;
      opt.textContent = tenant.name || tenant.id;
      if (tenant.id === state.activeTenant) {
        opt.selected = true;
      }
      tenantSelect.appendChild(opt);
    });

    if (!state.activeTenant && state.tenants[0]) {
      await setTenant(state.tenants[0].id);
      tenantSelect.value = state.tenants[0].id;
    }
  }

  async function setTenant(tenantId) {
    await request(api.setTenant, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ tenant_id: tenantId })
    });
    state.activeTenant = tenantId;
    await loadClinics();
    await loadCases();
  }

  function openUploadDialog(caseId) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.stl,.obj,.ply';
    input.addEventListener('change', async () => {
      if (!input.files || !input.files[0]) {
        return;
      }

      const formData = new FormData();
      formData.append('scan_file', input.files[0]);

      try {
        await request('/wp-json/vp/v1/dentist/cases/' + encodeURIComponent(caseId) + '/upload-scan', {
          method: 'POST',
          body: formData
        });
        await loadCases();
      } catch (e) {
        alert(e.message || 'Ошибка загрузки скана');
      }
    });
    input.click();
  }

  async function initLoginPage() {
    const form = document.getElementById('vpDentistLoginForm');
    if (!form) {
      return;
    }

    const status = document.getElementById('vpDentistLoginStatus');
    const forgot = document.getElementById('vpDentistForgotBtn');

    forgot.addEventListener('click', () => {
      text(status, 'Восстановление пароля будет добавлено в следующем этапе.');
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const email = document.getElementById('vpDentistEmail');
      const password = document.getElementById('vpDentistPassword');
      text(status, 'Загрузка...');

      try {
        const data = await request(api.login, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            email: email.value.trim(),
            password: password.value
          })
        });

        window.location.href = data.redirect || '/dentist/';
      } catch (err) {
        text(status, err.message || 'Ошибка входа');
      }
    });
  }

  async function initCabinetPage() {
    const root = document.querySelector('.vp-dentist-cabinet');
    if (!root) {
      return;
    }

    try {
      const me = await request(api.me);
      const welcome = document.getElementById('vpDentistWelcomeText');
      const userName = [me.user.first_name, me.user.last_name].filter(Boolean).join(' ').trim();
      text(welcome, userName || me.user.email || 'Пользователь');
    } catch (e) {
      window.location.href = '/dentist-login/';
      return;
    }

    const tenantSelect = document.getElementById('vpDentistTenant');
    tenantSelect.addEventListener('change', async () => {
      if (!tenantSelect.value) {
        return;
      }
      await setTenant(tenantSelect.value);
    });

    document.getElementById('vpDentistReloadCasesBtn').addEventListener('click', loadCases);

    const createStatus = document.getElementById('vpDentistCreateStatus');
    const caseForm = document.getElementById('vpDentistCaseForm');
    caseForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const title = document.getElementById('vpCaseTitle').value.trim();
      const clinicId = document.getElementById('vpCaseClinic').value;
      const patientExternalId = document.getElementById('vpCasePatientExternal').value.trim();

      if (!title) {
        text(createStatus, 'Введите название кейса');
        return;
      }

      text(createStatus, 'Загрузка...');

      try {
        await request(api.cases, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            title,
            clinic_id: clinicId,
            patient_external_id: patientExternalId
          })
        });
        text(createStatus, 'Кейс создан');
        caseForm.reset();
        await loadCases();
      } catch (e2) {
        text(createStatus, e2.message || 'Ошибка создания кейса');
      }
    });

    const fab = root.querySelector('[data-vp-fab-panel]');
    if (fab) {
      fab.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-vp-action]');
        if (!btn) {
          return;
        }

        const action = btn.getAttribute('data-vp-action');
        if (action === 'goScan') {
          window.location.href = '/scan/';
        }
        if (action === 'goCreateCase') {
          document.getElementById('vpCaseTitle').focus();
        }
        if (action === 'reloadCases') {
          loadCases();
        }
        if (action === 'logout') {
          await request(api.logout, { method: 'POST' });
          window.location.href = '/dentist-login/';
        }
      });
    }

    await loadTenants();
    await loadClinics();
    await loadCases();
  }

  document.addEventListener('DOMContentLoaded', function () {
    initLoginPage();
    initCabinetPage();
  });
})();
