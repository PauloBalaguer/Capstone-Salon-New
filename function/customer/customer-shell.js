// Customer portal shell: the one copy of the sidebar, top header, phone
// bottom navigation and Sign Out confirmation used by every customer page.
// Each page marks where the pieces go with placeholders:
//   <div data-customer-shell="sidebar"></div>
//   <div data-customer-shell="header"></div>       (first child of <main>)
//   <div data-customer-shell="bottom-nav"></div>
//   <div data-customer-shell="signout"></div>
//   <div data-customer-shell="intro" data-eyebrow=".." data-title=".." data-accent=".." data-text=".."></div>
//   <div data-customer-shell="footer"></div>
// and names itself on <body data-customer-page="..." data-page-title="...">.
// This file is loaded as a normal (non-deferred) script at the end of
// <body>, so the markup is in place before Alpine (loaded with `defer`)
// starts and binds the x-* attributes below to customerApp().
// Styles: frontend/customer/customer-portal.css.

(function () {
  // Line icons (24px grid, stroke 1.8) shared by the sidebar, header and bottom bar.
  const ICONS = {
    home: '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
    book: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
    bookings: '<path d="M3 6h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4zM13 6v2m0 3v2m0 3v2"/>',
    services: '<path d="m12 2 2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5z"/>',
    track: '<circle cx="10.8" cy="10.8" r="7"/><path d="m16 16 5 5"/>',
    feedback: '<path d="M20.5 5.5a5.5 5.5 0 0 0-7.8 0L12 6.2l-.7-.7a5.5 5.5 0 0 0-7.8 7.8L12 22l8.5-8.7a5.5 5.5 0 0 0 0-7.8z"/>',
    homeService: '<path d="m3 11 9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M12 18s-3-1.8-3-3.6a1.6 1.6 0 0 1 3-.8 1.6 1.6 0 0 1 3 .8C15 16.2 12 18 12 18z"/>',
    profile: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 8-3 8-3 10h18c0-2-3-2-3-10M10 21h4"/>',
    signout: '<path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 8l4 4-4 4M8 12h11"/>',
    close: '<path d="M6 6l12 12M18 6 6 18"/>',
    arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>'
  };
  const icon = (name, cls) =>
    '<svg class="' + (cls || 'cp-icon') + '" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">' + ICONS[name] + '</svg>';
  window.customerShellIcon = icon;

  // The one navigation list. The desktop sidebar and the phone menu drawer
  // both render all of it; the phone bottom bar shows the first
  // BOTTOM_COUNT items with the same labels, plus a Menu button for the rest,
  // so wording never differs between screen sizes.
  const NAV = [
    { key: 'dashboard', label: 'Dashboard', href: 'dashboard.html', icon: 'home' },
    { key: 'book', label: 'Book Appointment', href: 'services-promos.html?book=1', icon: 'book' },
    { key: 'bookings', label: 'My Bookings', href: 'appointments.html', icon: 'bookings' },
    { key: 'home', label: 'Home Service', href: 'home-service.html', icon: 'homeService' },
    { key: 'track', label: 'Track Booking', href: 'appointments.html?track=1', icon: 'track' },
    { key: 'feedback', label: 'My Feedback', href: 'reviews.html', icon: 'feedback' }
  ];
  const BOTTOM_COUNT = 4;

  const LOGOUT_URL = '../../backend/auth/logout.php?portal=customer';
  // LM monogram + wordmark, used in the sidebar and the phone header.
  const BRAND_MARK = '<span class="cp-monogram" aria-hidden="true">LM</span>';

  // Which item is "current". The booking entry point and the Track link
  // reuse existing pages, so the query string decides between them.
  function activeKeys() {
    const page = document.body.dataset.customerPage || '';
    const params = new URLSearchParams(location.search);
    const map = {
      dashboard: 'dashboard',
      appointments: params.get('track') === '1' ? 'track' : 'bookings',
      services: 'book', // the services page is the booking page (no separate Explore Services item)
      'home-service': 'home',
      reviews: 'feedback'
    };
    return map[page] || '';
  }

  const avatar = (size) =>
    '<span class="cp-avatar cp-avatar-' + size + '" aria-hidden="true">' +
      '<img x-show="customerProfile.profilePicture" :src="customerProfile.profilePicture" alt="" @error="customerProfile.profilePicture = \'\'">' +
      '<span x-show="!customerProfile.profilePicture" x-text="(customerProfile.name || \'\').split(\' \').filter(Boolean).map(n => n[0]).slice(0, 2).join(\'\').toUpperCase()"></span>' +
    '</span>';

  function sidebarHtml(active) {
    const items = NAV.map(item => {
      const current = item.key === active;
      // My Bookings shows how many upcoming bookings the customer has.
      const count = item.key === 'bookings'
        ? '<span class="cp-nav-count" x-show="upcomingBookingCount() > 0" x-text="upcomingBookingCount()" style="display: none;"></span>' : '';
      return '<a href="' + item.href + '" data-nav-key="' + item.key + '" class="cp-side-link' + (current ? ' is-active' : '') + '"' +
        (current ? ' aria-current="page"' : '') + '>' + icon(item.icon) + '<span>' + item.label + '</span>' + count + '</a>';
    }).join('');
    return '<aside id="customer-sidebar" class="cp-sidebar" aria-label="Customer navigation">' +
      // Phones/tablets only: closes the slide-in menu.
      '<button type="button" class="cp-drawer-close" data-cp-drawer-close aria-label="Close menu">' + icon('close') + '</button>' +
      '<a href="dashboard.html" class="cp-brand">' + BRAND_MARK +
        '<span class="cp-brand-text"><strong>LEO MEJILLANO</strong><small>SALON &amp; BEAUTY</small></span></a>' +
      '<p class="cp-side-heading" id="cp-side-heading">CUSTOMER NAVIGATION</p>' +
      '<nav class="cp-side-nav" aria-labelledby="cp-side-heading">' + items + '</nav>' +
      '<div class="cp-side-footer"><p class="cp-side-heading cp-side-heading-sm">CUSTOMER ACCESS</p>' +
        '<button type="button" class="cp-side-link cp-signout-link" @click="openSignOutConfirm()">' +
        icon('signout') + '<span>Sign out</span></button></div>' +
    '</aside>';
  }

  function notificationPanelHtml() {
    return '<div x-show="isNotificationPanelOpen" x-transition role="dialog" aria-label="Notifications" class="customer-notification-panel cp-notification-panel" style="display: none;">' +
      '<div class="cp-notification-head">' +
        '<h4>Notifications</h4>' +
        '<button type="button" x-show="notifications.length > 0" @click="markAllNotificationsAsRead()" :disabled="!notifications.some(n => !n.read)" class="notification-read-all font-semibold">Mark all as read</button>' +
        '<button type="button" @click="isNotificationPanelOpen = false" aria-label="Close notifications" class="notification-close">' + icon('close') + '</button>' +
      '</div>' +
      '<div class="notification-list" aria-live="polite">' +
        '<template x-if="isBooting && displayNotifications.length === 0"><p class="cp-notification-empty">Loading notifications…</p></template>' +
        '<template x-if="!isBooting && dashboardLoadError && displayNotifications.length === 0"><p class="cp-notification-empty" role="alert">We couldn\'t load your notifications. Please refresh the page.</p></template>' +
        '<template x-if="!isBooting && !dashboardLoadError && displayNotifications.length === 0"><p class="cp-notification-empty">You\'re all caught up. Booking updates will appear here.</p></template>' +
        '<template x-for="notif in displayNotifications" :key="notif.id || notif.time + notif.message">' +
          '<article class="notification-card notification-clickable" :class="{ \'notification-unread\': !notif.read }" role="link" tabindex="0" ' +
            ':aria-label="notificationDetails(notif).title + (notif.read ? \'\' : \', unread\') + \'. \' + notificationActionLabel(notif)" ' +
            '@click="openNotification(notif)" @keydown.enter.prevent="openNotification(notif)" @keydown.space.prevent="openNotification(notif)">' +
            '<div class="notification-status" :class="{ \'notification-status-cancelled\': notif.type === \'CANCELLED\' }" aria-hidden="true">' +
              '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="notif.icon"></path></svg>' +
            '</div>' +
            '<div class="notification-copy">' +
              '<div class="notification-title-row"><p class="notification-title" x-text="notificationDetails(notif).title"></p><span x-show="!notif.read" class="notification-dot" aria-label="Unread"></span><time class="notification-time" :datetime="notif.createdAt || \'\'" :title="notif.time" x-text="notificationTime(notif)"></time></div>' +
              '<p class="notification-summary" x-show="notificationDetails(notif).summary" x-text="notificationDetails(notif).summary"></p>' +
              '<span class="notification-state-badge" x-show="notificationDetails(notif).status" x-text="notificationDetails(notif).status"></span>' +
              '<p class="notification-service" x-show="notificationDetails(notif).service || notificationDetails(notif).schedule" x-text="[notificationDetails(notif).service, notificationDetails(notif).branch, notificationDetails(notif).schedule].filter(Boolean).join(\' · \')"></p>' +
              '<a class="notification-action" :href="notificationDestination(notif)" tabindex="-1" @click.stop="markNotificationRead(notif); isNotificationPanelOpen = false" x-text="notificationActionLabel(notif)"></a>' +
            '</div>' +
          '</article>' +
        '</template>' +
      '</div>' +
      '<a href="appointments.html" class="cp-notification-footer">Track Booking ' + icon('arrow') + '</a>' +
    '</div>';
  }

  function headerHtml(title) {
    return '<header class="cp-header">' +
      // Phones and tablets: monogram + brand name.
      '<a href="dashboard.html" class="cp-header-brand" aria-label="Leo Mejillano, ' + title + '">' + BRAND_MARK +
        '<span class="cp-brand-text"><strong>LEO MEJILLANO</strong></span></a>' +
      // Desktop: breadcrumb with the page title.
      '<p class="cp-header-title"><span>CUSTOMER PORTAL</span><b aria-hidden="true">/</b><strong>' + title + '</strong></p>' +
      '<div class="cp-header-actions">' +
        '<div class="cp-bell-wrap" @click.outside="isNotificationPanelOpen = false" @keydown.escape.window="isNotificationPanelOpen = false">' +
          '<button type="button" class="cp-icon-btn" @click="toggleNotificationPanel()" :aria-expanded="isNotificationPanelOpen.toString()" ' +
            ':aria-label="unreadNotificationCount > 0 ? \'Notifications, \' + unreadNotificationCount + \' unread\' : \'Notifications\'">' +
            icon('bell', 'cp-icon') +
            '<span x-show="unreadNotificationCount > 0" class="cp-badge" x-text="unreadNotificationCount > 9 ? \'9+\' : unreadNotificationCount" style="display: none;"></span>' +
          '</button>' +
          notificationPanelHtml() +
        '</div>' +
        // Phones only: the desktop Sign Out lives in the sidebar, so this never shows twice.
        '<button type="button" class="cp-icon-btn cp-header-signout" @click="openSignOutConfirm()" aria-label="Sign out" title="Sign out">' + icon('signout', 'cp-icon') + '</button>' +
        '<a href="profile.html" class="cp-profile-link" aria-label="Open My Profile">' + avatar('md') +
          '<span class="cp-profile-text"><strong class="cp-profile-name" x-text="customerProfile.name || \'My Profile\'"></strong><small>Customer account</small></span></a>' +
      '</div>' +
    '</header>';
  }

  function bottomNavHtml(active) {
    const shown = NAV.slice(0, BOTTOM_COUNT);
    const items = shown.map(item => {
      const current = item.key === active;
      return '<a href="' + item.href + '" data-nav-key="' + item.key + '" class="cp-bottom-link' + (current ? ' is-active' : '') + '"' +
        (current ? ' aria-current="page"' : '') + '>' + icon(item.icon, 'cp-bottom-icon') + '<span>' + item.label + '</span></a>';
    }).join('');
    // The remaining items (Track Booking, My Feedback) and Sign out live in the menu drawer.
    const inMenu = active && !shown.some(item => item.key === active);
    const menu = '<button type="button" class="cp-bottom-link' + (inMenu ? ' is-active' : '') + '" data-cp-drawer-open ' +
      'aria-controls="customer-sidebar" aria-expanded="false">' + icon('menu', 'cp-bottom-icon') + '<span>Menu</span></button>';
    return '<nav id="customer-mobile-nav" class="cp-bottom-nav" aria-label="Customer navigation">' + items + menu + '</nav>';
  }

  // Below 1024px the sidebar becomes a slide-in drawer opened from the bottom bar.
  function setupDrawer() {
    const drawer = document.getElementById('customer-sidebar');
    const opener = document.querySelector('[data-cp-drawer-open]');
    if (!drawer || !opener) return;
    const phone = window.matchMedia('(max-width: 1023.98px)');
    const backdrop = document.createElement('div');
    backdrop.className = 'cp-drawer-backdrop';
    document.body.appendChild(backdrop);
    const setOpen = (open, restoreFocus) => {
      document.body.classList.toggle('cp-drawer-open', open);
      opener.setAttribute('aria-expanded', String(open));
      drawer.inert = phone.matches && !open;
      if (open) (drawer.querySelector('[data-cp-drawer-close]') || drawer).focus({ preventScroll: true });
      else if (restoreFocus) opener.focus({ preventScroll: true });
    };
    setOpen(false);
    const sync = () => setOpen(false);
    if (phone.addEventListener) phone.addEventListener('change', sync); else phone.addListener(sync);
    opener.addEventListener('click', () => setOpen(!document.body.classList.contains('cp-drawer-open')));
    backdrop.addEventListener('click', () => setOpen(false, true));
    drawer.addEventListener('click', e => {
      if (e.target.closest('[data-cp-drawer-close]')) setOpen(false, true);
      else if (phone.matches && e.target.closest('a[href], .cp-signout-link')) setOpen(false);
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && document.body.classList.contains('cp-drawer-open')) setOpen(false, true);
    });
  }

  function signOutHtml() {
    return '<div x-show="isSignOutConfirmOpen" x-transition.opacity class="cp-modal-backdrop" style="display: none;" @keydown.escape.window="closeSignOutConfirm()">' +
      '<div class="cp-modal" role="alertdialog" aria-modal="true" aria-labelledby="cp-signout-title" aria-describedby="cp-signout-text" @click.outside="closeSignOutConfirm()">' +
        '<span class="cp-modal-icon" aria-hidden="true">' + icon('signout', 'cp-icon') + '</span>' +
        '<h3 id="cp-signout-title">Sign out?</h3>' +
        '<p id="cp-signout-text">You will need to log in again to book or manage your appointments.</p>' +
        '<div class="cp-modal-actions">' +
          '<button type="button" class="cp-btn cp-btn-secondary" x-ref="signOutCancel" @click="closeSignOutConfirm()">Cancel</button>' +
          // Full-page navigation to the existing PHP logout, which destroys the session and redirects to login.
          '<a href="' + LOGOUT_URL + '" class="cp-btn cp-btn-primary">Sign out</a>' +
        '</div>' +
      '</div>' +
    '</div>';
  }

  // Page heading: <div data-customer-shell="intro" data-eyebrow="..." data-title="My" data-accent="bookings" data-text="..."></div>
  // An optional data-action-href/data-action-label adds a button on the right.
  function introHtml(slot) {
    const d = slot.dataset;
    const action = d.actionHref
      ? '<a href="' + esc(d.actionHref) + '" class="cp-btn cp-btn-primary cp-intro-action">' + icon('book', 'cp-icon') + '<span>' + esc(d.actionLabel || '') + '</span></a>' : '';
    return '<div class="cp-intro"><div class="cp-intro-copy">' +
      '<span class="cp-eyebrow">' + esc(d.eyebrow || '') + '</span>' +
      '<h1>' + esc(d.title || '') + (d.accent ? ' <em>' + esc(d.accent) + '</em>' : '') + '</h1>' +
      (d.text ? '<p>' + esc(d.text) + '</p>' : '') +
    '</div>' + action + '</div>';
  }

  function esc(text) {
    return String(text).replace(/[<>&"]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));
  }

  function render() {
    const body = document.body;
    const booking = body.dataset.customerPage === 'services' && new URLSearchParams(location.search).get('book') === '1';
    const myBookings = body.dataset.customerPage === 'appointments' && new URLSearchParams(location.search).get('track') !== '1';
    if (myBookings) {
      body.dataset.pageTitle = 'My Bookings';
      document.title = 'Leo Mejillano Salon - My Bookings';
      const intro = document.querySelector('[data-customer-shell=intro]');
      if (intro) { intro.dataset.title = 'My'; intro.dataset.accent = 'Bookings'; intro.dataset.text = 'View and manage your upcoming appointments and booking history.'; intro.dataset.actionHref = 'services-promos.html?book=1'; intro.dataset.actionLabel = 'New booking'; }
    }
    const title = esc(booking ? 'Book Appointment' : (body.dataset.pageTitle || ''));
    const active = activeKeys();
    const parts = {
      sidebar: () => sidebarHtml(active),
      header: () => headerHtml(title),
      'bottom-nav': () => bottomNavHtml(active),
      signout: () => signOutHtml(),
      footer: () => ''
    };
    document.querySelectorAll('[data-customer-shell]').forEach(slot => {
      const kind = slot.dataset.customerShell;
      if (kind === 'intro') { slot.outerHTML = introHtml(slot); return; }
      const build = parts[kind];
      if (build) slot.outerHTML = build();
    });
    body.classList.add('cp-portal');
    setupDrawer();
  }

  render();
})();
