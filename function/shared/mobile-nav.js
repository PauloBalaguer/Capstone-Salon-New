// Phone menu for the admin and cashier portals (styles: frontend/shared/mobile-nav.css).
// Turns the page's sidebar <aside> into a slide-in drawer below 768px and adds
// a top bar with a menu button. Does nothing on pages without a sidebar.
(function () {
  function setup() {
    const drawer = document.querySelector('body.salon-portal aside, body aside');
    const main = document.querySelector('main');
    if (!drawer || !main || document.querySelector('.lm-mobilebar')) return;

    document.body.classList.add('lm-has-drawer');
    drawer.classList.add('lm-drawer');
    if (!drawer.id) drawer.id = 'lm-portal-menu';
    drawer.setAttribute('aria-label', drawer.getAttribute('aria-label') || 'Main navigation');

    const title = (document.title || '').split(/[|\-–]/)[0].trim() || 'Navigation';
    const bar = document.createElement('div');
    bar.className = 'lm-mobilebar';
    bar.innerHTML =
      '<button type="button" aria-expanded="false" aria-label="Open navigation">' +
      '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>' +
      '</button><span class="lm-mobilebar-title"></span>';
    bar.querySelector('.lm-mobilebar-title').textContent = title;
    const button = bar.querySelector('button');
    button.setAttribute('aria-controls', drawer.id);
    main.insertBefore(bar, main.firstChild);

    const backdrop = document.createElement('div');
    backdrop.className = 'lm-drawer-backdrop';
    document.body.appendChild(backdrop);

    const mobile = window.matchMedia('(max-width: 767.98px)');
    const setOpen = open => {
      document.body.classList.toggle('lm-drawer-open', open);
      button.setAttribute('aria-expanded', String(open));
      button.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
      // Keep the hidden drawer out of the tab order on phones.
      if (mobile.matches) drawer.inert = !open; else drawer.inert = false;
      if (open) (drawer.querySelector('a, button') || drawer).focus({ preventScroll: true });
    };
    const sync = () => setOpen(false);
    sync();
    if (mobile.addEventListener) mobile.addEventListener('change', sync); else mobile.addListener(sync);

    button.addEventListener('click', () => setOpen(!document.body.classList.contains('lm-drawer-open')));
    backdrop.addEventListener('click', () => { setOpen(false); button.focus(); });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && document.body.classList.contains('lm-drawer-open')) { setOpen(false); button.focus(); }
    });
    drawer.addEventListener('click', e => { if (mobile.matches && e.target.closest('a[href]')) setOpen(false); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup); else setup();
})();
