export const moduleId = 'base';
export const title = 'База';

export function getMenu() {
  return [
    { id: 'dashboard', label: 'Dashboard', route: '#/dashboard' },
    { id: 'profile', label: 'Profile', route: '#/profile' },
    { id: 'tenants', label: 'Tenants', route: '#/tenants' },
  ];
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
        const u = ctx.me.user || {};
        const p = ctx.me.profile || {};
        container.innerHTML = `
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
        `;
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
