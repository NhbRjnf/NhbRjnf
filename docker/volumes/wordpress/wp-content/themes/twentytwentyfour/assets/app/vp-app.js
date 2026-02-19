const root = document.getElementById('vpAppRoot');

if (!root) {
  console.warn('[vp-app] root not found');
} else {
  const globalCfg = window.VP_APP_CONFIG || {};
  const assetVersion = root.dataset.assetVersion || globalCfg.assetVersion || '1.0.0';

  const state = {
    me: null,
    activeTenant: null,
    currentRoute: window.location.hash || '#/dashboard',
    error: null,
    routes: [],
    menu: [],
    moduleInfo: null,
    assetVersion,
  };

  const ui = {
    createEl(tag, attrs = {}, text = '') {
      const node = document.createElement(tag);
      Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
      if (text) node.textContent = text;
      return node;
    },
    escapeHTML(text = '') {
      return String(text)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
    },
    toast(message) {
      let toast = document.querySelector('.vp-toast');
      if (!toast) {
        toast = document.createElement('div');
        toast.className = 'vp-toast';
        document.body.appendChild(toast);
      }
      toast.textContent = message;
      toast.classList.add('is-show');
      window.setTimeout(() => toast.classList.remove('is-show'), 2200);
    },
  };

  const api = {
    async request(path, options = {}) {
      const fetchOptions = {
        credentials: 'include',
        ...options,
      };

      const hasFormDataBody = typeof FormData !== 'undefined' && fetchOptions.body instanceof FormData;
      if (!hasFormDataBody) {
        fetchOptions.headers = {
          'Content-Type': 'application/json',
          ...(options.headers || {}),
        };
      } else if (options.headers) {
        fetchOptions.headers = { ...options.headers };
      }

      const response = await fetch(`/wp-json/vp/v1/app${path}`, fetchOptions);
      const json = await response.json().catch(() => ({}));
      if (!response.ok) {
        const err = new Error(json.error || `HTTP ${response.status}`);
        err.status = response.status;
        err.body = json;
        throw err;
      }
      return json;
    },
    me() {
      return this.request('/me');
    },
    tenants() {
      return this.request('/tenants');
    },
    setTenant(tenantId) {
      return this.request('/tenant', {
        method: 'POST',
        body: JSON.stringify({ tenant_id: tenantId }),
      });
    },
    updateProfile(payload) {
      return this.request('/profile', {
        method: 'PATCH',
        body: JSON.stringify(payload),
      });
    },
    uploadAvatar(file) {
      const formData = new FormData();
      formData.append('file', file);
      return this.request('/profile/avatar', {
        method: 'POST',
        body: formData,
      });
    },
  };

  function setState(partial) {
    Object.assign(state, partial);
  }

  function getState() {
    return { ...state };
  }

  function navigate(route) {
    window.location.hash = route.startsWith('#') ? route : `#${route}`;
  }

  function applyTheme(theme) {
    if (!theme) {
      document.documentElement.removeAttribute('data-theme');
      localStorage.removeItem('vp_theme');
      return;
    }
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('vp_theme', theme);
  }

  function initTheme() {
    const saved = localStorage.getItem('vp_theme');
    if (saved === 'dark' || saved === 'light') {
      applyTheme(saved);
    }
  }

  function moduleContext() {
    return {
      api,
      me: state.me,
      tenant: state.activeTenant,
      setState,
      getState,
      navigate,
      ui,
      assetVersion: state.assetVersion,
    };
  }

  function uniqBy(list, keyBuilder) {
    const out = [];
    const seen = new Set();
    list.forEach((item) => {
      if (!item || typeof item !== 'object') return;
      const key = keyBuilder(item);
      if (seen.has(key)) return;
      seen.add(key);
      out.push(item);
    });
    return out;
  }

  async function loadModules(userType) {
    const baseModule = await import(`/wp-content/themes/twentytwentyfour/assets/app/modules/base.js?ver=${assetVersion}`);
    let userModule = null;

    const normalizedUserType = String(userType || '').trim();
    if (normalizedUserType && normalizedUserType !== 'base') {
      try {
        userModule = await import(`/wp-content/themes/twentytwentyfour/assets/app/modules/${normalizedUserType}.js?ver=${assetVersion}`);
      } catch (err) {
        console.warn('[vp-app] user module fallback to base only', err);
      }
    }

    const ctx = moduleContext();
    const menu = uniqBy(
      [...(baseModule.getMenu(ctx) || []), ...(userModule?.getMenu?.(ctx) || [])],
      (item) => `${item.id || ''}::${item.route || ''}`,
    );
    const routes = uniqBy(
      [...(baseModule.getRoutes(ctx) || []), ...(userModule?.getRoutes?.(ctx) || [])],
      (item) => item.route || '',
    );

    if (typeof baseModule.onActivate === 'function') {
      await baseModule.onActivate(ctx);
    }
    if (typeof userModule?.onActivate === 'function') {
      await userModule.onActivate(ctx);
    }

    setState({
      menu,
      routes,
      moduleInfo: {
        moduleId: userModule?.moduleId || 'base',
        title: userModule?.title || 'Базовый модуль',
      },
    });
  }

  function currentRoute() {
    return window.location.hash || '#/dashboard';
  }

  function findRoute(route) {
    return state.routes.find((item) => item.route === route);
  }

  function renderErrorCard(text) {
    return `<section class="vp-card"><div class="vp-badge is-danger">Ошибка</div><p>${ui.escapeHTML(text)}</p></section>`;
  }

  function renderLayout() {
    const me = state.me;
    const userName = [me?.user?.first_name, me?.user?.last_name].filter(Boolean).join(' ').trim() || me?.user?.email || 'Пользователь';
    const userType = me?.profile?.user_type || 'unknown';
    const activeTenant = me?.active_tenant?.tenant_title || 'Не выбран';

    const menuHtml = state.menu.map((item) => {
      const active = state.currentRoute === item.route ? ' is-active' : '';
      return `<button class="vp-btn${active}" data-route="${ui.escapeHTML(item.route)}">${ui.escapeHTML(item.label)}</button>`;
    }).join('');

    root.innerHTML = `
      <section class="vp-card vp-app-header">
        <div class="vp-brand">
          <div class="vp-logo">VP</div>
          <div>
            <div class="vp-title">ВсёПонятно</div>
            <div class="vp-sub">Единый кабинет /app</div>
          </div>
        </div>
        <div class="vp-app-user">
          <span class="vp-badge">${ui.escapeHTML(userName)}</span>
          <span class="vp-badge">${ui.escapeHTML(me?.user?.email || '')}</span>
          <span class="vp-badge">type: ${ui.escapeHTML(userType)}</span>
          <span class="vp-badge">tenant: ${ui.escapeHTML(activeTenant)}</span>
          <button id="vpThemeToggle" class="vp-iconbtn" type="button" title="Переключить тему">☀/🌙</button>
        </div>
      </section>
      <section class="vp-app-grid">
        <aside class="vp-card vp-app-sidebar">
          <h3 class="vp-app-route-title">Меню</h3>
          <nav class="vp-app-menu">${menuHtml}</nav>
          <div class="vp-app-footer">VP App v${ui.escapeHTML(String(me?.asset_version || state.assetVersion))}</div>
        </aside>
        <section class="vp-app-content" id="vpAppContent"></section>
      </section>
    `;

    root.querySelectorAll('[data-route]').forEach((btn) => {
      btn.addEventListener('click', () => navigate(btn.getAttribute('data-route')));
    });

    const toggle = document.getElementById('vpThemeToggle');
    if (toggle) {
      toggle.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme');
        applyTheme(current === 'dark' ? 'light' : 'dark');
      });
    }
  }

  function renderRoute() {
    const content = document.getElementById('vpAppContent');
    if (!content) return;

    const route = findRoute(state.currentRoute);
    if (!route) {
      content.innerHTML = renderErrorCard(`Маршрут не найден: ${state.currentRoute}`);
      return;
    }

    const wrapper = document.createElement('section');
    wrapper.className = 'vp-card';
    const title = document.createElement('h2');
    title.className = 'vp-app-title';
    title.textContent = route.title;
    wrapper.appendChild(title);

    const slot = document.createElement('div');
    slot.className = 'vp-app-slot';
    wrapper.appendChild(slot);

    content.innerHTML = '';
    content.appendChild(wrapper);

    try {
      route.render(slot, moduleContext());
    } catch (err) {
      console.error('[vp-app] route render error', err);
      slot.innerHTML = renderErrorCard(err.message || 'Ошибка рендера');
    }
  }

  async function boot() {
    initTheme();
    try {
      const me = await api.me();
      setState({ me, activeTenant: me.active_tenant, currentRoute: currentRoute() });
      const userType = me?.profile?.user_type || 'base';
      await loadModules(userType);
      renderLayout();
      renderRoute();
    } catch (err) {
      console.error('[vp-app] boot error', err);
      if (err.status === 401) {
        window.location.href = '/login/';
        return;
      }
      root.innerHTML = renderErrorCard(err.message || 'Ошибка загрузки кабинета');
    }
  }

  window.addEventListener('hashchange', () => {
    setState({ currentRoute: currentRoute() });
    renderLayout();
    renderRoute();
  });

  boot();
}