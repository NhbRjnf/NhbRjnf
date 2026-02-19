function normalizeUserType(userType) {
  const type = String(userType || '').trim();
  if (type === 'location_owner') return 'location';
  if (type === 'car_owner') return 'car_owner';
  if (type === 'business_owner') return 'partner';
  if (type === 'client') return 'auto';
  return type;
}

function allowedFileTypesByRole(userType) {
  const map = {
    dentist: [
      'Аватар: JPG/PNG/WebP (до 5MB)',
      'Кейсы: STL/PLY/OBJ (через кейсы/сканы, не в профиле)',
      'Документы: PDF (только как вложение к кейсу/инструкции)',
    ],
    location: [
      'Аватар: JPG/PNG/WebP',
      'Сцены: GLB/GLTF',
      'Схемы: PDF/PNG',
    ],
    auto: [
      'Аватар: JPG/PNG/WebP',
      'Фото/видео для обращений: JPG/PNG/WebP, MP4 (лимит будет позже)',
    ],
    partner: [
      'Аватар: JPG/PNG/WebP',
      'Документы: PDF, изображения (для карточек/инструкций)',
    ],
    car_owner: [
      'Аватар: JPG/PNG/WebP',
      'Документы по продукту: PDF/изображения (через карточки/QR)',
    ],
  };

  return map[normalizeUserType(userType)] || ['Аватар: JPG/PNG/WebP'];
}

export const moduleId = 'base';
export const title = 'База';

export function getMenu() {
  return [
    { id: 'dashboard', label: 'Dashboard', route: '#/dashboard' },
    { id: 'profile', label: 'Profile', route: '#/profile' },
    { id: 'tenants', label: 'Tenants', route: '#/tenants' },
  ];
}

function renderProfileView(container, ctx) {
  const u = ctx.me.user || {};
  const p = ctx.me.profile || {};

  container.innerHTML = `
    <div id="vpProfileError"></div>
    <div class="vp-card is-soft">
      <div class="vp-toolbar">
        <div class="vp-toolbar-left"><div class="vp-badge">Профиль</div></div>
        <div class="vp-toolbar-right"><button id="vpProfileEdit" type="button" class="vp-btn">Редактировать</button></div>
      </div>
      <div class="vp-table-wrap">
        <table class="vp-table">
          <tbody>
            <tr><td>Email</td><td>${ctx.ui.escapeHTML(u.email || '')}</td></tr>
            <tr><td>Имя</td><td>${ctx.ui.escapeHTML(u.first_name || '')}</td></tr>
            <tr><td>Фамилия</td><td>${ctx.ui.escapeHTML(u.last_name || '')}</td></tr>
            <tr><td>User type</td><td>${ctx.ui.escapeHTML(p.user_type || '')}</td></tr>
            <tr><td>Статус</td><td>${ctx.ui.escapeHTML(p.status || '')}</td></tr>
            <tr><td>Телефон</td><td>${ctx.ui.escapeHTML(p.phone || '')}</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="vp-card">
      <div class="vp-toolbar">
        <div class="vp-toolbar-left"><div class="vp-badge">Аватар</div></div>
      </div>
      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        ${p.avatar_url ? `<img src="${ctx.ui.escapeHTML(p.avatar_url)}" alt="avatar" style="width:84px;height:84px;border-radius:999px;object-fit:cover;border:1px solid var(--line);"/>` : '<div class="vp-badge">Не загружен</div>'}
        <div>
          <input id="vpAvatarInput" type="file" accept="image/jpeg,image/png,image/webp" class="vp-input" style="max-width:280px;"/>
          <div class="vp-cell-muted">JPG/PNG/WEBP, до 5MB</div>
        </div>
      </div>
    </div>
    <div class="vp-card is-soft">
      <div class="vp-toolbar">
        <div class="vp-toolbar-left"><div class="vp-badge">Разрешённые типы файлов</div></div>
      </div>
      <ul class="vp-list" style="margin:0;padding-left:18px;display:grid;gap:6px;">
        ${allowedFileTypesByRole(p.user_type).map((item) => `<li>${ctx.ui.escapeHTML(item)}</li>`).join('')}
      </ul>
      <div class="vp-cell-muted" style="margin-top:8px;">В профиле нет общего файлового менеджера — файлы загружаются только в рамках рабочих сценариев.</div>
    </div>
  `;

  const editBtn = container.querySelector('#vpProfileEdit');
  editBtn?.addEventListener('click', () => renderProfileEdit(container, ctx));

  const avatarInput = container.querySelector('#vpAvatarInput');
  avatarInput?.addEventListener('change', async () => {
    const file = avatarInput.files?.[0];
    if (!file) return;

    try {
      avatarInput.disabled = true;
      const result = await ctx.api.uploadAvatar(file);
      const me = result.me || await ctx.api.me();
      ctx.setState({ me, activeTenant: me.active_tenant });
      ctx.ui.toast('Аватар обновлён');
      renderProfileView(container, {
        ...ctx,
        me,
      });
    } catch (err) {
      console.error('[vp-app] avatar upload error', err);
      const errHost = container.querySelector('#vpProfileError');
      if (errHost) {
        errHost.innerHTML = `<div class="vp-card"><span class="vp-badge is-danger">Ошибка</span> ${ctx.ui.escapeHTML(err.message || 'Не удалось загрузить аватар')}</div>`;
      }
    } finally {
      avatarInput.value = '';
      avatarInput.disabled = false;
    }
  });
}

function renderProfileEdit(container, ctx, errorText = '') {
  const u = ctx.me.user || {};
  const p = ctx.me.profile || {};

  container.innerHTML = `
    <div id="vpProfileError">${errorText ? `<div class="vp-card"><span class="vp-badge is-danger">Ошибка</span> ${ctx.ui.escapeHTML(errorText)}</div>` : ''}</div>
    <div class="vp-card is-soft">
      <div class="vp-toolbar">
        <div class="vp-toolbar-left"><div class="vp-badge">Редактирование профиля</div></div>
      </div>
      <div style="display:grid;gap:10px;max-width:480px;">
        <label>Email
          <input class="vp-input" type="text" id="vpProfileEmail" value="${ctx.ui.escapeHTML(u.email || '')}" readonly />
        </label>
        <label>Имя
          <input class="vp-input" type="text" id="vpProfileFirstName" maxlength="80" value="${ctx.ui.escapeHTML(u.first_name || '')}" />
        </label>
        <label>Фамилия
          <input class="vp-input" type="text" id="vpProfileLastName" maxlength="80" value="${ctx.ui.escapeHTML(u.last_name || '')}" />
        </label>
        <label>Телефон
          <input class="vp-input" type="text" id="vpProfilePhone" maxlength="64" value="${ctx.ui.escapeHTML(p.phone || '')}" />
        </label>
      </div>
      <div class="vp-toolbar" style="margin-top:12px;">
        <div class="vp-toolbar-left">
          <button id="vpProfileSave" class="vp-btn vp-primary" type="button">Сохранить</button>
          <button id="vpProfileCancel" class="vp-btn" type="button">Отмена</button>
        </div>
      </div>
    </div>
  `;

  container.querySelector('#vpProfileCancel')?.addEventListener('click', () => renderProfileView(container, ctx));
  container.querySelector('#vpProfileSave')?.addEventListener('click', async () => {
    const firstName = String(container.querySelector('#vpProfileFirstName')?.value || '').trim();
    const lastName = String(container.querySelector('#vpProfileLastName')?.value || '').trim();
    const phone = String(container.querySelector('#vpProfilePhone')?.value || '').trim();

    try {
      const saveBtn = container.querySelector('#vpProfileSave');
      if (saveBtn) saveBtn.disabled = true;
      const result = await ctx.api.updateProfile({
        first_name: firstName,
        last_name: lastName,
        phone,
      });
      const me = result.me || await ctx.api.me();
      ctx.setState({ me, activeTenant: me.active_tenant });
      ctx.ui.toast('Профиль обновлён');
      renderProfileView(container, { ...ctx, me });
    } catch (err) {
      console.error('[vp-app] profile save error', err);
      renderProfileEdit(container, ctx, err.message || 'Ошибка обновления профиля');
    }
  });
}

export function getRoutes() {
  return [
    {
      route: '#/dashboard',
      title: 'Dashboard',
      render(container, ctx) {
        const user = ctx.me.user || {};
        container.innerHTML = `
          <div class="vp-card is-soft">
            <div class="vp-badge is-success">Готово</div>
            <h3>Добро пожаловать, ${ctx.ui.escapeHTML(user.first_name || user.email || 'пользователь')}!</h3>
            <p>Это единый каркас кабинета. Текущий tenant: <b>${ctx.ui.escapeHTML(ctx.me.active_tenant?.tenant_title || 'не выбран')}</b>.</p>
          </div>
        `;
      },
    },
    {
      route: '#/profile',
      title: 'Profile',
      render(container, ctx) {
        renderProfileView(container, ctx);
      },
    },
    {
      route: '#/tenants',
      title: 'Tenants',
      render(container, ctx) {
        const tenants = Array.isArray(ctx.me.tenants) ? ctx.me.tenants : [];
        if (!tenants.length) {
          container.innerHTML = '<div class="vp-card is-soft">Нет доступных организаций</div>';
          return;
        }

        const current = ctx.me.active_tenant?.tenant_id || '';
        container.innerHTML = `
          <div class="vp-toolbar">
            <div class="vp-toolbar-left"><div class="vp-badge">Выберите организацию</div></div>
          </div>
          <div class="vp-suggest-list" id="vpTenantList"></div>
        `;

        const host = container.querySelector('#vpTenantList');
        tenants.forEach((tenant) => {
          const item = document.createElement('div');
          item.className = 'vp-card';
          item.innerHTML = `
            <div class="vp-toolbar">
              <div class="vp-toolbar-left">
                <div>
                  <div><b>${ctx.ui.escapeHTML(tenant.tenant_title)}</b></div>
                  <div class="vp-cell-muted">role: ${ctx.ui.escapeHTML(tenant.member_role || 'viewer')}</div>
                </div>
              </div>
              <div class="vp-toolbar-right">
                <button class="vp-btn ${tenant.tenant_id === current ? 'vp-primary' : ''}" data-tenant-id="${ctx.ui.escapeHTML(tenant.tenant_id)}" type="button">
                  ${tenant.tenant_id === current ? 'Текущий tenant' : 'Сделать активным'}
                </button>
              </div>
            </div>
          `;
          const btn = item.querySelector('button[data-tenant-id]');
          btn.addEventListener('click', async () => {
            try {
              await ctx.api.setTenant(tenant.tenant_id);
              const me = await ctx.api.me();
              ctx.setState({ me, activeTenant: me.active_tenant });
              ctx.ui.toast('Тенант переключён');
              ctx.navigate('#/dashboard');
            } catch (err) {
              console.error('[vp-app] tenant switch error', err);
              ctx.ui.toast(`Ошибка переключения tenant: ${err.message}`);
            }
          });
          host.appendChild(item);
        });
      },
    },
  ];
}

export async function onActivate() {}