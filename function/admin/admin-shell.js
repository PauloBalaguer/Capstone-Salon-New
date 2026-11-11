/* Admin Panel shell: builds the same sidebar, header, page heading, phone
   bottom navigation, Menu drawer and notification bell on every
   frontend/admin/*.html page, so the nine panels never drift apart. Layout
   follows the Customer Portal (function/customer/customer-shell.js):
   >= 1024px sidebar + header with an "ADMIN PORTAL / Page" breadcrumb;
   <  1024px brand header + bottom bar (first four sections + Menu drawer). Must be a plain (non-deferred) script placed after the
   page markup and before Alpine starts -- the injected markup carries Alpine
   bindings to $store.admin (function/admin/shared-data.js). */
(function () {
  const ICONS = {
    dashboard: '<path d="M4 5a1 1 0 011-1h5v7H4V5zM14 4h5a1 1 0 011 1v4h-6V4zM14 13h6v6a1 1 0 01-1 1h-5v-7zM4 15h6v5H5a1 1 0 01-1-1v-4z"/>',
    bookings: '<path d="M8 3v4M16 3v4M4 9h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1zM8 13h3M8 16h6"/>',
    staff: '<path d="M16 19v-1a4 4 0 00-4-4H7a4 4 0 00-4 4v1M9.5 10a3 3 0 100-6 3 3 0 000 6zM21 19v-1a3.5 3.5 0 00-2.5-3.35M15.5 4.2a3 3 0 010 5.6"/>',
    inventory: '<path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
    services: '<path d="M7 7h.01M3 11.6V4a1 1 0 011-1h7.6a1 1 0 01.7.3l8.4 8.4a1 1 0 010 1.4l-7.6 7.6a1 1 0 01-1.4 0l-8.4-8.4a1 1 0 01-.3-.7z"/>',
    reports: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    customers: '<path d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 20a8 8 0 0116 0"/>',
    feedback: '<path d="M21 12a8 8 0 01-11.6 7.1L4 20l1-4.6A8 8 0 1121 12zM8.5 11h.01M12 11h.01M15.5 11h.01"/>',
    settings: '<path d="M12 15a3 3 0 100-6 3 3 0 000 6z"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    bell: '<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/>',
    signout: '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>'
  };
  const NAV = [
    ['dashboard.html', 'Dashboard', 'dashboard'],
    ['appointments.html', 'Bookings', 'bookings'],
    ['employees.html', 'Staff', 'staff'],
    ['inventory.html', 'Inventory', 'inventory'],
    ['promotions.html', 'Services & Promos', 'services'],
    ['reports.html', 'Reports', 'reports'],
    ['customers.html', 'Customers', 'customers'],
    ['feedback.html', 'Guest Feedback', 'feedback'],
    ['settings.html', 'Settings', 'settings']
  ];
  const BOTTOM_COUNT = 4; // phone bottom bar: first four sections + Menu (rest live in the drawer)
  const LOGOUT_URL = '../../backend/auth/logout.php?portal=admin';
  const icon = (name) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`;
  const current = location.pathname.split('/').pop() || 'dashboard.html';
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- Sidebar (desktop) / Menu drawer (below 1024px) ---------- */
  const sidebar = document.querySelector('[data-lm-sidebar]');
  if (sidebar) {
    sidebar.id = 'lm-sidebar';
    sidebar.setAttribute('aria-label', 'Admin navigation');
    sidebar.innerHTML = `
      <button type="button" class="lm-close lm-sidebar-close" data-lm-close aria-label="Close menu">&times;</button>
      <div class="lm-brand">
        <div class="lm-brand-mark" aria-hidden="true">LM</div>
        <div><p class="lm-brand-name">Leo Mejillano</p><p class="lm-brand-sub">Admin Panel</p></div>
      </div>
      <p class="lm-side-heading" id="lm-side-heading">ADMIN NAVIGATION</p>
      <nav class="lm-nav" aria-labelledby="lm-side-heading">
        ${NAV.map(([href, label, ic]) => `<a href="${href}" data-nav-page="${href}"${href === current ? ' aria-current="page"' : ''}>${icon(ic)}<span>${label}</span></a>`).join('')}
      </nav>
      <div class="lm-sidebar-foot">
        <p class="lm-side-heading lm-side-heading-sm">ADMIN ACCESS</p>
        <a class="lm-signout" href="${LOGOUT_URL}">${icon('signout')}<span>Sign out</span></a>
      </div>`;
  }

  /* ---------- Header + page heading ---------- */
  const topbar = document.querySelector('[data-lm-topbar]');
  let menu = null;
  if (topbar) {
    // The page's title, description and controls (branch pickers, Export,
    // Add buttons) move -- with their Alpine bindings -- into a page heading
    // at the top of the content, as on Customer Portal pages.
    const titleBox = topbar.querySelector('.lm-topbar-title');
    const pageActions = topbar.querySelector('.lm-topbar-actions');
    const h1 = titleBox && titleBox.querySelector('h1');
    const titleText = h1 ? h1.textContent.trim() : '';
    const content = document.querySelector('.lm-content');
    if (content && titleBox) {
      const intro = document.createElement('div');
      intro.className = 'lm-intro';
      titleBox.className = 'lm-intro-copy';
      titleBox.insertAdjacentHTML('afterbegin', '<span class="lm-eyebrow">Admin Portal</span>');
      intro.appendChild(titleBox);
      if (pageActions && pageActions.children.length) { pageActions.className = 'lm-intro-actions'; intro.appendChild(pageActions); }
      else if (pageActions) pageActions.remove();
      content.insertBefore(intro, content.firstChild);
    }

    topbar.classList.add('lm-header');
    topbar.innerHTML = `
      <a href="dashboard.html" class="lm-header-brand" aria-label="Leo Mejillano Admin Portal, Dashboard">
        <span class="lm-brand-mark" aria-hidden="true">LM</span><span class="lm-header-brand-name">LEO MEJILLANO</span>
      </a>
      <p class="lm-header-title"><span>ADMIN PORTAL</span><b aria-hidden="true">/</b><strong>${esc(titleText)}</strong></p>
      <div class="lm-header-actions"></div>`;
    const actions = topbar.querySelector('.lm-header-actions');

    const bell = document.createElement('div');
    bell.className = 'lm-bell';
    bell.setAttribute('x-data', '{ open: false }');
    bell.setAttribute('@keydown.escape', 'open = false');
    bell.innerHTML = `
      <button type="button" class="lm-btn lm-btn--icon" @click="open = !open" :aria-expanded="open.toString()" :aria-label="$store.admin.unreadNotifications() > 0 ? 'Notifications, ' + $store.admin.unreadNotifications() + ' unread' : 'Notifications'">${icon('bell')}
        <span class="lm-bell-dot" x-show="$store.admin.unreadNotifications() > 0" x-cloak></span></button>
      <div class="lm-bell-panel np-panel" x-show="open" @click.outside="open = false" x-cloak role="region" aria-label="Admin notifications">
        <div class="np-head"><h3 class="np-title">Notifications</h3>
          <button type="button" class="np-readall" x-show="$store.admin.notifications.length > 0" @click="$store.admin.markAllNotificationsAsRead()" :disabled="$store.admin.notificationsReadBusy || !$store.admin.unreadNotifications()" x-text="$store.admin.notificationsReadBusy ? 'Saving…' : 'Mark all as read'"></button>
          <button type="button" class="np-close" @click="open = false" aria-label="Close notifications"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
        <div class="np-list">
          <p class="np-empty" x-show="$store.admin.notifications.length === 0">You're all caught up. New bookings and updates will appear here.</p>
          <template x-for="item in $store.admin.notifications" :key="item.id">
            <article class="np-card" :class="{ 'np-unread': !item.isRead }" role="link" tabindex="0"
              :aria-label="PortalPresentation.adminNotice(item).title + (item.isRead ? '' : ', unread')"
              @click="$store.admin.openNotification(item)" @keydown.enter.prevent="$store.admin.openNotification(item)" @keydown.space.prevent="$store.admin.openNotification(item)">
              <span class="np-icon" :class="{ 'np-icon--bad': NotifUI.isBad(item.type, PortalPresentation.adminNotice(item).title) }" aria-hidden="true" x-html="NotifUI.icon(NotifUI.isBad(item.type, PortalPresentation.adminNotice(item).title))"></span>
              <div class="np-copy">
                <div class="np-row"><p class="np-card-title" x-text="PortalPresentation.adminNotice(item).title"></p><span x-show="!item.isRead" class="np-dot" aria-hidden="true"></span><time class="np-time" :title="item.time" x-text="NotifUI.ago(item.createdAt || item.time)"></time></div>
                <p class="np-summary" x-show="PortalPresentation.adminNotice(item).summary" x-text="PortalPresentation.adminNotice(item).summary"></p>
                <p class="np-details" x-text="PortalPresentation.noticeLine([PortalPresentation.adminNotice(item).reference, PortalPresentation.adminNotice(item).customer, PortalPresentation.adminNotice(item).branch, PortalPresentation.adminNotice(item).schedule, PortalPresentation.adminNotice(item).payment])"></p>
                <button type="button" class="np-action" tabindex="-1" @click.stop="$store.admin.openNotification(item)">View details</button>
              </div>
            </article>
          </template>
        </div>
        <p class="lm-alert lm-alert--error" style="margin:10px" role="alert" x-show="$store.admin.notificationReadError" x-text="$store.admin.notificationReadError"></p>
        <a href="appointments.html" class="np-footer">View all bookings <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
      </div>`;
    actions.appendChild(bell);
    // Phones/tablets only: on desktop Sign out lives in the sidebar.
    actions.insertAdjacentHTML('beforeend',
      `<a class="lm-btn lm-btn--icon lm-header-signout" href="${LOGOUT_URL}" aria-label="Sign out" title="Sign out">${icon('signout')}</a>` +
      `<a class="lm-profile-link" href="settings.html" aria-label="Open account settings">
         <span class="lm-account-avatar" aria-hidden="true" x-text="AdminUI.initials($store.admin.adminProfile.name || 'Admin')">A</span>
         <span class="lm-profile-text"><strong x-text="$store.admin.adminProfile.name || 'Administrator'">Administrator</strong><small>Platform Administrator</small></span>
       </a>`);

    /* ---------- Bottom navigation (below 1024px) ---------- */
    const shown = NAV.slice(0, BOTTOM_COUNT);
    const inMenu = !shown.some(([href]) => href === current);
    const bottom = document.createElement('nav');
    bottom.id = 'lm-bottom-nav';
    bottom.className = 'lm-bottom-nav';
    bottom.setAttribute('aria-label', 'Admin quick navigation');
    bottom.innerHTML = shown.map(([href, label, ic]) =>
      `<a href="${href}" class="lm-bottom-link${href === current ? ' is-active' : ''}"${href === current ? ' aria-current="page"' : ''}>${icon(ic)}<span>${label}</span></a>`).join('') +
      `<button type="button" class="lm-bottom-link${inMenu ? ' is-active' : ''}" data-lm-menu aria-controls="lm-sidebar" aria-expanded="false">${icon('menu')}<span>Menu</span></button>`;
    document.body.appendChild(bottom);
    menu = bottom.querySelector('[data-lm-menu]');
  }

  /* ---------- Menu drawer (below 1024px) ---------- */
  if (menu && sidebar) {
    const backdrop = document.createElement('div');
    backdrop.className = 'lm-backdrop';
    document.body.appendChild(backdrop);
    const narrow = window.matchMedia('(max-width: 1023.98px)');
    const isOpen = () => document.body.classList.contains('lm-nav-open');
    const setOpen = (open, restoreFocus) => {
      document.body.classList.toggle('lm-nav-open', open);
      menu.setAttribute('aria-expanded', String(open));
      sidebar.inert = narrow.matches && !open;
      if (open) sidebar.querySelector('[data-lm-close]').focus({ preventScroll: true });
      else if (restoreFocus) menu.focus({ preventScroll: true });
    };
    setOpen(false);
    (narrow.addEventListener ? narrow.addEventListener('change', () => setOpen(false)) : narrow.addListener(() => setOpen(false)));
    menu.addEventListener('click', () => setOpen(!isOpen(), true));
    backdrop.addEventListener('click', () => setOpen(false, true));
    sidebar.addEventListener('click', (e) => {
      if (e.target.closest('[data-lm-close]')) setOpen(false, true);
      else if (narrow.matches && e.target.closest('a[href]')) setOpen(false);
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && isOpen()) setOpen(false, true);
    });
  }
})();

/* Small display helpers shared by the admin pages. */
window.AdminUI = {
  initials(name) {
    return String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join('') || '?';
  },
  // Read ?key=value from the URL (used by the dashboard's clickable cards).
  param(key) {
    try { return new URLSearchParams(location.search).get(key) || ''; } catch (e) { return ''; }
  },
  // Today's date in the salon's time zone (Asia/Manila), as YYYY-MM-DD.
  today() {
    try { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); }
    catch (e) { return new Date().toISOString().slice(0, 10); }
  },
  // "2026-11-10" -> "Nov 10, 2026"; anything unparsable is returned as-is.
  date(value) {
    if (!value) return '';
    const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return String(value);
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  },
  dateTime(value) {
    if (!value) return '';
    const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) return this.date(value);
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]));
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  },
  // Locks page scroll while any x-show modal is open (call from x-effect).
  lockScroll(open) { document.body.classList.toggle('lm-modal-open', !!open); }
};
