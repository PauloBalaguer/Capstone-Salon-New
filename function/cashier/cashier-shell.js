/* Cashier Panel shell: rebuilds the sidebar, header and phone navigation the
   same way on every frontend/cashier/*.html page, using the Customer Portal's
   layout (function/customer/customer-shell.js) with the cashier's own colours:

     >= 1024px  dark teal sidebar + sticky header with a
                "CASHIER PORTAL / Page" breadcrumb, clock, page actions, profile
     <  1024px  brand header, bottom navigation (first four sections + Menu);
                Menu slides the sidebar in as a drawer

   - Keeps every element id the page scripts and cashier-common.js rely on
     (sidebarLogo, cashierBranchSubtitle, sidebarBranchTitle,
     sidebarTerminalId, sidebarNav/.nav-item, appointmentCountBadge,
     btnSignOut, branchLabel, liveClock) and moves -- never recreates -- the
     page's own header actions (New walk-in, Download CSV), so their
     listeners stay intact.
   - The branch is display-only. A cashier's branch is fixed server-side
     (users.branch_id, enforced in every backend/cashier/*.php endpoint);
     there is deliberately no selector here.
   Must load after the page markup and before cashier-common.js. */
(function () {
  const sidebar = document.querySelector('body > aside');
  const main = document.querySelector('body > main');
  if (!sidebar || !main) {
    // receipt.html (print page): no navigation, just the shared look.
    if (document.getElementById('receiptCard')) document.body.classList.add('cp-cashier', 'cp-receipt');
    return;
  }

  document.body.classList.add('cp-cashier');

  // The one navigation list: the sidebar and the Menu drawer show all of it,
  // the bottom bar the first BOTTOM_COUNT with the same labels, plus Menu.
  const NAV = [
    ['dashboard.html', 'Cashier Panel', 'fa-cash-register'],
    ['appointments.html', 'Appointments', 'fa-calendar-check'],
    ['payment.html', 'Payment Records', 'fa-money-check-dollar'],
    ['inventory.html', 'Inventory Tracking', 'fa-boxes-stacked'],
    ['staff.html', 'Staff Assignments', 'fa-users-gear']
  ];
  const BOTTOM_COUNT = 4;
  const LOGOUT_URL = '../../backend/auth/logout.php?portal=cashier';
  const current = location.pathname.split('/').pop() || 'dashboard.html';
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- Sidebar ---------- */
  sidebar.className = 'cp-sidebar';
  sidebar.id = 'cp-sidebar';
  sidebar.setAttribute('aria-label', 'Cashier navigation');
  sidebar.innerHTML = `
    <button type="button" class="cp-close" data-cp-close aria-label="Close menu"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    <div class="cp-brand">
      <img id="sidebarLogo" src="../shared/icon/salon-logo.png" alt="Leo Mejillano Salon &amp; Make Up Studio logo" class="cp-brand-logo">
      <div class="cp-brand-text">
        <p class="cp-brand-name">Leo Mejillano</p>
        <p class="cp-brand-sub">Salon &amp; Beauty</p>
      </div>
    </div>
    <div class="cp-branch-card" aria-label="Current branch">
      <p class="cp-branch-kicker"><span class="cp-live-dot" aria-hidden="true"></span>Current branch</p>
      <p class="cp-branch-name" id="sidebarBranchTitle">Loading branch…</p>
      <p class="cp-branch-meta"><span id="cashierBranchSubtitle">Cashier</span><span id="sidebarTerminalId"></span></p>
    </div>
    <p class="cp-side-heading" id="cp-side-heading">CASHIER NAVIGATION</p>
    <nav class="cp-nav" id="sidebarNav" aria-labelledby="cp-side-heading">
      ${NAV.map(([href, label, icon]) => `
        <a href="${href}" data-nav-page="${href}" class="nav-item"${href === current ? ' aria-current="page"' : ''}>
          <i class="fa-solid ${icon}" aria-hidden="true"></i><span class="cp-nav-text">${esc(label)}</span>
          ${href === 'appointments.html' ? '<span id="appointmentCountBadge" class="cp-nav-badge" title="Open bookings">0</span>' : ''}
        </a>`).join('')}
    </nav>
    <div class="cp-sidebar-foot">
      <p class="cp-side-heading cp-side-heading-sm">CASHIER ACCESS</p>
      <a href="${LOGOUT_URL}" id="btnSignOut" class="cp-signout"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Sign Out</span></a>
    </div>`;

  /* ---------- Header + page heading ---------- */
  const header = main.querySelector(':scope > header');
  const section = main.querySelector(':scope > section');
  if (header) {
    const title = header.querySelector('h2');
    const titleText = title ? title.textContent.trim() : '';
    const titleWrap = title ? title.parentElement : null;

    // Page actions (New walk-in, Download CSV): moved, so ids/listeners survive.
    const pageActions = Array.from(header.querySelectorAll('button, a')).filter(el => !el.closest('h2'));
    let clock = document.getElementById('liveClock');
    if (!clock) { clock = document.createElement('p'); clock.id = 'liveClock'; }
    let chip = document.getElementById('branchLabel');
    if (!chip) { chip = document.createElement('span'); chip.id = 'branchLabel'; }

    header.className = 'cp-topbar';
    header.innerHTML = `
      <a href="dashboard.html" class="cp-header-brand" aria-label="Leo Mejillano Cashier Portal, Cashier Panel">
        <img src="../shared/icon/salon-logo.png" alt=""><span>LEO MEJILLANO</span>
      </a>
      <p class="cp-header-title"><span>CASHIER PORTAL</span><b aria-hidden="true">/</b><strong>${esc(titleText)}</strong></p>
      <div class="cp-topbar-actions"></div>`;
    const actions = header.querySelector('.cp-topbar-actions');

    chip.className = 'cp-branch-chip';
    chip.textContent = 'Loading branch…';
    clock.className = 'cp-clock';
    clock.setAttribute('aria-live', 'off');
    clock.title = 'Philippine time (Asia/Manila)';
    actions.append(chip, clock);
    pageActions.forEach(el => {
      el.classList.add('cp-page-action');
      // Icon-only on phones, so keep the visible label as the accessible name.
      if (!el.getAttribute('aria-label')) el.setAttribute('aria-label', el.textContent.trim());
      actions.appendChild(el);
    });

    const signout = document.createElement('a');
    signout.href = LOGOUT_URL;
    signout.className = 'cp-icon-btn cp-header-signout';
    signout.setAttribute('aria-label', 'Sign out');
    signout.title = 'Sign out';
    signout.innerHTML = '<i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>';
    actions.appendChild(signout);

    const profile = document.createElement('a');
    profile.href = 'profile.html';
    profile.className = 'cp-profile-btn';
    if (current === 'profile.html') profile.setAttribute('aria-current', 'page');
    profile.setAttribute('aria-label', 'Open Profile & Security');
    profile.innerHTML = '<span class="cp-avatar cp-avatar--sm" data-cp-initials aria-hidden="true">C</span>' +
      '<span class="cp-profile-text"><span class="cp-profile-name" data-cp-name>Cashier</span><small>Cashier account</small></span>';
    actions.appendChild(profile);

    // Eyebrow + serif title at the top of the content, as on customer pages.
    if (title && section) {
      title.className = 'cp-page-title';
      const intro = document.createElement('div');
      intro.className = 'cp-intro';
      intro.innerHTML = '<span class="cp-eyebrow">Cashier Portal</span>';
      intro.appendChild(title);
      section.insertBefore(intro, section.firstChild);
    }
    if (titleWrap && titleWrap.parentElement) titleWrap.remove();
  }
  if (section) section.classList.add('cp-page');

  /* ---------- Bottom navigation (below 1024px) ---------- */
  const shown = NAV.slice(0, BOTTOM_COUNT);
  const inMenu = !shown.some(([href]) => href === current);
  const bottom = document.createElement('nav');
  bottom.id = 'cp-bottom-nav';
  bottom.className = 'cp-bottom-nav';
  bottom.setAttribute('aria-label', 'Cashier quick navigation');
  bottom.innerHTML = shown.map(([href, label, icon]) =>
    `<a href="${href}" class="cp-bottom-link${href === current ? ' is-active' : ''}"${href === current ? ' aria-current="page"' : ''}>` +
    `<i class="fa-solid ${icon}" aria-hidden="true"></i><span>${esc(label)}</span></a>`).join('') +
    `<button type="button" class="cp-bottom-link${inMenu ? ' is-active' : ''}" data-cp-menu aria-controls="cp-sidebar" aria-expanded="false">` +
    '<i class="fa-solid fa-bars" aria-hidden="true"></i><span>Menu</span></button>';
  document.body.appendChild(bottom);
  const menuBtn = bottom.querySelector('[data-cp-menu]');

  /* ---------- Menu drawer (below 1024px) ---------- */
  const backdrop = document.createElement('div');
  backdrop.className = 'cp-backdrop';
  document.body.appendChild(backdrop);
  const narrow = window.matchMedia('(max-width: 1023.98px)');
  const isOpen = () => document.body.classList.contains('cp-nav-open');
  const setOpen = (open, restoreFocus) => {
    document.body.classList.toggle('cp-nav-open', open);
    menuBtn.setAttribute('aria-expanded', String(open));
    sidebar.inert = narrow.matches && !open;
    if (open) sidebar.querySelector('[data-cp-close]').focus({ preventScroll: true });
    else if (restoreFocus) menuBtn.focus({ preventScroll: true });
  };
  setOpen(false);
  if (narrow.addEventListener) narrow.addEventListener('change', () => setOpen(false)); else narrow.addListener(() => setOpen(false));
  menuBtn.addEventListener('click', () => setOpen(!isOpen(), true));
  backdrop.addEventListener('click', () => setOpen(false, true));
  sidebar.addEventListener('click', (e) => {
    if (e.target.closest('[data-cp-close]')) setOpen(false, true);
    else if (narrow.matches && e.target.closest('a[href]')) setOpen(false);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen()) setOpen(false, true);
  });
})();
