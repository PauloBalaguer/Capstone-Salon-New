// Customer portal shell: the one copy of the sidebar, top header, phone
// bottom navigation and Sign Out confirmation used by every customer page.
//
// Each page marks where the pieces go with placeholders:
//   <div data-customer-shell="sidebar"></div>
//   <div data-customer-shell="header"></div>       (first child of <main>)
//   <div data-customer-shell="bottom-nav"></div>
//   <div data-customer-shell="signout"></div>
// and names itself on <body data-customer-page="..." data-page-title="...">.
//
// This file is loaded as a normal (non-deferred) script at the end of
// <body>, so the markup is in place before Alpine (loaded with `defer`)
// starts and binds the x-* attributes below to customerApp().
// Styles: frontend/customer/customer-portal.css.

(function () {
  const ICONS = {
    dashboard: 'M4 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2v-4z',
    home: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    book: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zM12 13v4m-2-2h4',
    bookings: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    services: 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z',
    track: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
    feedback: 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
    profile: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    bell: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
    signout: 'M9 5H5v14h4M13 8l4 4-4 4M9 12h12'
  };
  const icon = (name, cls) =>
    '<svg class="' + (cls || 'cp-icon') + '" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="' + ICONS[name] + '"/></svg>';

  // Desktop/tablet sidebar, in the order the customer asked for.
  const SIDEBAR = [
    { key: 'dashboard', label: 'Dashboard', href: 'dashboard.html', icon: 'dashboard' },
    { key: 'book', label: 'Book Appointment', href: 'services-promos.html?book=1', icon: 'book' },
    { key: 'bookings', label: 'My Bookings', href: 'appointments.html', icon: 'bookings' },
    { key: 'services', label: 'Explore Services', href: 'services-promos.html', icon: 'services' },
    { key: 'track', label: 'Track Booking', href: 'appointments.html?track=1', icon: 'track', track: true },
    { key: 'feedback', label: 'My Feedback', href: 'reviews.html', icon: 'feedback' }
  ];
  // Phone bottom bar: exactly five equal items.
  const BOTTOM = [
    { key: 'dashboard', label: 'Home', href: 'dashboard.html', icon: 'home' },
    { key: 'services', label: 'Services', href: 'services-promos.html', icon: 'services' },
    { key: 'book', label: 'Book', href: 'services-promos.html?book=1', icon: 'book' },
    { key: 'bookings', label: 'Bookings', href: 'appointments.html', icon: 'bookings' },
    { key: 'profile', label: 'Profile', href: 'profile.html', icon: 'profile' }
  ];

  const LOGOUT_URL = '../../backend/auth/logout.php?portal=customer';
  const LOGO_URL = '../../frontend/shared/icon/salon-logo.png';

  // Which item is "current". The booking entry point and the Track link
  // reuse existing pages, so the query string decides between them.
  function activeKeys() {
    const page = document.body.dataset.customerPage || '';
    const params = new URLSearchParams(location.search);
    const map = {
      dashboard: { side: 'dashboard', bottom: 'dashboard' },
      appointments: params.get('track') === '1' ? { side: 'track', bottom: 'bookings' } : { side: 'bookings', bottom: 'bookings' },
      services: params.get('book') === '1' ? { side: 'book', bottom: 'book' } : { side: 'services', bottom: 'services' },
      'home-service': { side: 'book', bottom: 'book' },
      reviews: { side: 'feedback', bottom: '' },
      profile: { side: '', bottom: 'profile' }
    };
    return map[page] || { side: '', bottom: '' };
  }

  const avatar = (size) =>
    '<span class="cp-avatar cp-avatar-' + size + '" aria-hidden="true">' +
      '<img x-show="customerProfile.profilePicture" :src="customerProfile.profilePicture" alt="" @error="customerProfile.profilePicture = \'\'">' +
      '<span x-show="!customerProfile.profilePicture" x-text="(customerProfile.name || \'\').split(\' \').filter(Boolean).map(n => n[0]).slice(0, 2).join(\'\').toUpperCase()"></span>' +
    '</span>';

  function sidebarHtml(active) {
    const items = SIDEBAR.map(item => {
      const current = item.key === active;
      // On a page that already has the Track modal, open it in place.
      const track = item.track ? ' @click="if ($data.openTrackModal) { $event.preventDefault(); $data.openTrackModal(); }"' : '';
      return '<a href="' + item.href + '" data-nav-key="' + item.key + '" class="cp-side-link' + (current ? ' is-active' : '') + '"' +
        (current ? ' aria-current="page"' : '') + track + '>' + icon(item.icon) + '<span>' + item.label + '</span></a>';
    }).join('');
    return '<aside id="customer-sidebar" class="cp-sidebar" aria-label="Customer menu">' +
      '<a href="dashboard.html" class="cp-brand"><img src="' + LOGO_URL + '" alt="" class="cp-brand-logo">' +
        '<span class="cp-brand-text"><strong>Leo Mejillano</strong><small>Salon &amp; Beauty</small></span></a>' +
      '<p class="cp-side-heading" id="cp-side-heading">CUSTOMER MENU</p>' +
      '<nav class="cp-side-nav" aria-labelledby="cp-side-heading">' + items + '</nav>' +
      '<div class="cp-side-footer"><button type="button" class="cp-side-link cp-signout-link" @click="openSignOutConfirm()">' +
        icon('signout') + '<span>Sign Out</span></button></div>' +
    '</aside>';
  }

  function notificationPanelHtml() {
    return '<div x-show="isNotificationPanelOpen" x-transition role="dialog" aria-label="Notifications" class="customer-notification-panel cp-notification-panel" style="display: none;">' +
      '<div class="cp-notification-head">' +
        '<h4>Notifications</h4>' +
        '<button type="button" x-show="notifications.length > 0" @click="markAllNotificationsAsRead()" :disabled="!notifications.some(n => !n.read)" class="notification-read-all font-semibold">Mark all as read</button>' +
        '<button type="button" @click="isNotificationPanelOpen = false" aria-label="Close notifications" class="notification-close">&times;</button>' +
      '</div>' +
      '<div class="notification-list" aria-live="polite">' +
        '<template x-if="displayNotifications.length === 0"><p class="cp-notification-empty">You\'re all caught up. Booking updates will appear here.</p></template>' +
        '<template x-for="notif in displayNotifications" :key="notif.id || notif.time + notif.message">' +
          '<article class="notification-card" :class="{ \'notification-unread\': !notif.read }">' +
            '<div class="notification-status" :class="{ \'notification-status-cancelled\': notif.type === \'CANCELLED\' }" aria-hidden="true">' +
              '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="notif.icon"></path></svg>' +
            '</div>' +
            '<div class="notification-copy">' +
              '<div class="notification-title-row"><p class="notification-title" x-text="notificationDetails(notif).title"></p><span x-show="!notif.read" class="notification-dot" aria-label="Unread"></span><time class="notification-time" :datetime="notif.createdAt || \'\'" :title="notif.time" x-text="notificationTime(notif)"></time></div>' +
              '<p class="notification-summary" x-show="notificationDetails(notif).summary" x-text="notificationDetails(notif).summary"></p>' +
              '<span class="notification-state-badge" x-show="notificationDetails(notif).status" x-text="notificationDetails(notif).status"></span>' +
              '<p class="notification-service" x-show="notificationDetails(notif).service || notificationDetails(notif).schedule" x-text="[notificationDetails(notif).service, notificationDetails(notif).branch, notificationDetails(notif).schedule].filter(Boolean).join(\' · \')"></p>' +
              '<a class="notification-action" x-show="notif.actionUrl" :href="notif.actionUrl" x-text="notif.actionLabel"></a>' +
            '</div>' +
          '</article>' +
        '</template>' +
      '</div>' +
    '</div>';
  }

  function headerHtml(title) {
    return '<header class="cp-header">' +
      // Phones: logo + brand name, with the page name underneath.
      '<a href="dashboard.html" class="cp-header-brand"><img src="' + LOGO_URL + '" alt="" class="cp-brand-logo">' +
        '<span class="cp-brand-text"><strong>Leo Mejillano</strong><small>' + title + '</small></span></a>' +
      // Tablet/desktop: page title.
      '<h2 class="cp-header-title">' + title + '</h2>' +
      '<div class="cp-header-actions">' +
        '<div class="cp-bell-wrap" @click.outside="isNotificationPanelOpen = false" @keydown.escape.window="isNotificationPanelOpen = false">' +
          '<button type="button" class="cp-icon-btn" @click="toggleNotificationPanel()" :aria-expanded="isNotificationPanelOpen.toString()" ' +
            ':aria-label="unreadNotificationCount > 0 ? \'Notifications, \' + unreadNotificationCount + \' unread\' : \'Notifications\'">' +
            icon('bell', 'cp-icon-lg') +
            '<span x-show="unreadNotificationCount > 0" class="cp-badge" x-text="unreadNotificationCount > 9 ? \'9+\' : unreadNotificationCount" style="display: none;"></span>' +
          '</button>' +
          notificationPanelHtml() +
        '</div>' +
        // Phones only: the desktop Sign Out lives in the sidebar, so this never shows twice.
        '<button type="button" class="cp-icon-btn cp-header-signout" @click="openSignOutConfirm()" aria-label="Sign out">' + icon('signout', 'cp-icon-lg') + '</button>' +
        '<a href="profile.html" class="cp-profile-link" aria-label="Open My Profile">' + avatar('md') +
          '<span class="cp-profile-name" x-text="customerProfile.name || \'My Profile\'"></span></a>' +
      '</div>' +
    '</header>';
  }

  function bottomNavHtml(active) {
    const items = BOTTOM.map(item => {
      const current = item.key === active;
      return '<a href="' + item.href + '" data-nav-key="' + item.key + '" class="cp-bottom-link' + (current ? ' is-active' : '') + '"' +
        (current ? ' aria-current="page"' : '') + '>' + icon(item.icon, 'cp-bottom-icon') + '<span>' + item.label + '</span></a>';
    }).join('');
    return '<nav id="customer-mobile-nav" class="cp-bottom-nav" aria-label="Customer navigation">' + items + '</nav>';
  }

  function signOutHtml() {
    return '<div x-show="isSignOutConfirmOpen" x-transition.opacity class="cp-modal-backdrop" style="display: none;" @keydown.escape.window="closeSignOutConfirm()">' +
      '<div class="cp-modal" role="alertdialog" aria-modal="true" aria-labelledby="cp-signout-title" aria-describedby="cp-signout-text" @click.outside="closeSignOutConfirm()">' +
        '<span class="cp-modal-icon" aria-hidden="true">' + icon('signout', 'cp-icon-lg') + '</span>' +
        '<h3 id="cp-signout-title">Sign out?</h3>' +
        '<p id="cp-signout-text">You will need to log in again to book or manage your appointments.</p>' +
        '<div class="cp-modal-actions">' +
          '<button type="button" class="cp-btn cp-btn-secondary" x-ref="signOutCancel" @click="closeSignOutConfirm()">Cancel</button>' +
          // Full-page navigation to the existing PHP logout, which destroys the session and redirects to login.
          '<a href="' + LOGOUT_URL + '" class="cp-btn cp-btn-danger">Sign Out</a>' +
        '</div>' +
      '</div>' +
    '</div>';
  }

  function render() {
    const body = document.body;
    const booking = body.dataset.customerPage === 'services' && new URLSearchParams(location.search).get('book') === '1';
    const title = (booking ? 'Book Appointment' : (body.dataset.pageTitle || '')).replace(/[<>&"]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));
    const active = activeKeys();
    const parts = {
      sidebar: () => sidebarHtml(active.side),
      header: () => headerHtml(title),
      'bottom-nav': () => bottomNavHtml(active.bottom),
      signout: () => signOutHtml()
    };
    document.querySelectorAll('[data-customer-shell]').forEach(slot => {
      const build = parts[slot.dataset.customerShell];
      if (build) slot.outerHTML = build();
    });
    body.classList.add('cp-portal');
  }

  render();
})();
