// One confirmation flow for every portal, including sidebar and phone actions.
(() => {
  const role = location.pathname.match(/\/frontend\/(customer|admin|cashier|staff)\//)?.[1];
  if (!role) return;
  let activeDialog = null;

  function openSignOut(trigger) {
    if (activeDialog) return;
    const dialog = document.createElement('dialog');
    activeDialog = dialog;
    dialog.id = 'portal-sign-out';
    dialog.setAttribute('aria-labelledby', 'portal-sign-out-title');
    dialog.setAttribute('aria-describedby', 'portal-sign-out-description');
    dialog.innerHTML = `
      <span class="sign-out-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 8l4 4-4 4M8 12h11"/></svg></span>
      <h2 id="portal-sign-out-title">Sign out?</h2>
      <p id="portal-sign-out-description"></p>
      <div class="sign-out-actions"><button type="button" data-cancel autofocus>Cancel</button><button type="button" data-confirm>Sign out</button></div>`;
    const messages = {
      customer: 'You will need to log in again to book or manage your appointments.',
      admin: 'You will need to log in again to manage your salon.',
      cashier: 'You will need to log in again to manage appointments and payments.',
      staff: 'You will need to log in again to access your staff portal.'
    };
    const staff = role === 'staff' ? window.Alpine?.store('staff') : null;
    if (staff?.shift && staff.shift.status !== 'off') {
      messages.staff += ' Signing out keeps your shift running; it will show again when you log back in.';
    }
    dialog.querySelector('p').textContent = messages[role];
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
      const bounds = dialog.getBoundingClientRect();
      if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
    });
    dialog.addEventListener('close', () => {
      document.body.style.overflow = previousOverflow;
      dialog.remove();
      activeDialog = null;
      trigger.focus();
    });
    dialog.querySelector('[data-confirm]').addEventListener('click', event => {
      event.currentTarget.disabled = true;
      event.currentTarget.textContent = 'Signing out…';
      const staffForm = role === 'staff' && document.getElementById('sign-out-form');
      if (staffForm) staffForm.submit();
      else window.location.assign('../../backend/auth/logout.php?portal=' + role);
    });
    document.body.append(dialog);
    dialog.showModal();
    dialog.querySelector('[data-cancel]').focus();
  }

  const style = document.createElement('style');
  style.textContent = `
    #portal-sign-out { box-sizing:border-box; width:calc(100% - 32px); max-width:435px; max-height:calc(100dvh - 32px); overflow:auto; margin:auto; padding:24px; border:0; border-radius:18px; background:#fff; color:#18261f; box-shadow:0 27px 80px rgba(0,0,0,.25); text-align:left; }
    #portal-sign-out::backdrop { background:rgba(24,38,31,.7); backdrop-filter:blur(4px); }
    #portal-sign-out .sign-out-icon { display:grid; place-items:center; width:48px; height:48px; margin-bottom:16px; border-radius:13px; background:#edf7f2; color:#16604b; }
    #portal-sign-out h2 { margin:0 0 8px; font-family:'DM Serif Display',Georgia,serif !important; font-size:26px !important; font-weight:400 !important; line-height:1.25; color:#18261f !important; }
    #portal-sign-out p { margin:0; font-size:16px !important; line-height:1.6; color:#4a5751 !important; }
    #portal-sign-out .sign-out-actions { display:flex; gap:10px; margin-top:24px; }
    #portal-sign-out button { flex:1; min-width:0; min-height:46px; padding:12px 16px; border:1px solid #e5e7eb !important; border-radius:10px; background:#fff !important; color:#16604b !important; font-size:14px !important; font-weight:700; line-height:1.4; cursor:pointer; }
    #portal-sign-out button[data-confirm] { background:#16604b !important; border-color:#16604b !important; color:#fff !important; box-shadow:0 8px 18px rgba(22,96,75,.12); }
    #portal-sign-out button:focus-visible { outline:2px solid #16604b; outline-offset:3px; }
  `;
  document.head.append(style);
  // Capture before legacy Alpine handlers so a click opens only one dialog.
  document.addEventListener('click', event => {
    const trigger = event.target.closest('a[href*="auth/logout.php"], .cp-signout-link, .cp-header-signout, .sp-side-signout, .sp-header-signout');
    if (!trigger) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openSignOut(trigger);
  }, true);
})();
