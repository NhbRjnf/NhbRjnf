(function () {
  const endpoints = {
    login: '/wp-json/vp/v1/login',
    register: '/wp-json/vp/v1/register-request'
  };

  const dynamicFields = {
    dentist: [
      { name: 'clinic_name', label: 'Название клиники', required: true },
      { name: 'specialty', label: 'Специализация', required: true }
    ],
    car_owner: [
      { name: 'car_make', label: 'Марка авто', required: true },
      { name: 'car_model', label: 'Модель авто', required: true }
    ],
    business_owner: [
      { name: 'biz_name', label: 'Название бизнеса', required: true },
      { name: 'website', label: 'Сайт', required: true }
    ],
    location_owner: [
      { name: 'loc_name', label: 'Название локации', required: true },
      { name: 'address', label: 'Адрес', required: true }
    ],
    partner: [
      { name: 'partner_brand', label: 'Бренд/партнёр', required: true },
      { name: 'website', label: 'Сайт', required: true }
    ],
    client: [
      { name: 'client_phone', label: 'Телефон', required: true }
    ]
  };

  function setStatus(message) {
    const status = document.getElementById('vpLoginStatus');
    if (status) {
      status.textContent = message;
    }
  }

  async function request(url, options) {
    const res = await fetch(url, Object.assign({ credentials: 'include' }, options || {}));
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(json.error || json.message || 'Ошибка запроса');
    }
    return json;
  }

  function renderDynamicFields(type) {
    const wrap = document.getElementById('vpRegDynamicFields');
    if (!wrap) {
      return;
    }

    wrap.innerHTML = '';
    const fields = dynamicFields[type] || [];
    fields.forEach((field) => {
      const label = document.createElement('label');
      label.className = 'vp-d-label';
      label.setAttribute('for', 'vpDyn_' + field.name);
      label.textContent = field.label;

      const input = document.createElement('input');
      input.className = 'vp-d-input';
      input.id = 'vpDyn_' + field.name;
      input.dataset.field = field.name;
      input.required = !!field.required;

      wrap.appendChild(label);
      wrap.appendChild(input);
    });
  }

  function setupTabs() {
    const tabs = document.querySelectorAll('.vp-login-tab');
    const panels = document.querySelectorAll('.vp-login-panel');

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        const target = tab.dataset.tab;

        tabs.forEach((t) => {
          t.classList.toggle('is-active', t === tab);
          t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
        });

        panels.forEach((panel) => {
          const active = panel.dataset.panel === target;
          panel.classList.toggle('is-active', active);
          panel.hidden = !active;
        });

        setStatus('');
      });
    });
  }

  function initLogin() {
    const form = document.getElementById('vpLoginForm');
    if (!form) {
      return;
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setStatus('Выполняем вход...');

      try {
        const data = await request(endpoints.login, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            email: document.getElementById('vpLoginEmail').value.trim(),
            password: document.getElementById('vpLoginPassword').value
          })
        });

        window.location.href = data.redirect || '/cabinet/';
      } catch (error) {
        setStatus(error.message || 'Ошибка входа');
      }
    });
  }

  function initRegister() {
    const form = document.getElementById('vpRegisterForm');
    const type = document.getElementById('vpRegType');
    if (!form || !type) {
      return;
    }

    type.addEventListener('change', () => renderDynamicFields(type.value));

    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const userType = type.value;
      const email = document.getElementById('vpRegEmail').value.trim();
      if (!userType || !email) {
        setStatus('Укажите тип пользователя и email.');
        return;
      }

      const payload = {
        user_type: userType,
        email,
        password: document.getElementById('vpRegPassword').value,
        first_name: document.getElementById('vpRegFirstName').value.trim(),
        last_name: document.getElementById('vpRegLastName').value.trim(),
        phone: document.getElementById('vpRegPhone').value.trim(),
        invite_code: document.getElementById('vpRegInvite').value.trim(),
        requested_tenant_name: document.getElementById('vpRegTenantName').value.trim(),
        requested_tenant_slug: document.getElementById('vpRegTenantSlug').value.trim(),
        evidence_note: document.getElementById('vpRegEvidence').value.trim()
      };

      const dynamic = document.querySelectorAll('#vpRegDynamicFields [data-field]');
      for (const field of dynamic) {
        const key = field.dataset.field;
        payload[key] = field.value.trim();
        if (field.required && !payload[key]) {
          setStatus('Заполните поле: ' + key);
          return;
        }
      }

      if (userType === 'client' && !payload.phone && payload.client_phone) {
        payload.phone = payload.client_phone;
      }

      setStatus('Отправляем заявку...');
      try {
        const data = await request(endpoints.register, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        if (data.status === 'approved') {
          setStatus('Заявка одобрена. Теперь войдите в систему.');
        } else {
          setStatus(data.message || 'Заявка принята. Ожидайте подтверждения.');
        }
      } catch (error) {
        setStatus(error.message || 'Ошибка регистрации');
      }
    });
  }

  setupTabs();
  initLogin();
  initRegister();
})();
