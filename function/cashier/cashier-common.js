/* Shared helpers for the Leo Mejillano Salon cashier hub -- loaded before
   each page's own script on every frontend/cashier/*.html page (dashboard,
   appointments, inventory, staff, payment, receipt). Each page is a
   separate full HTML file with its own copy of the sidebar/header markup
   (matching frontend/admin/*.html's convention), so this file carries
   everything that would otherwise be duplicated per page: the
   backend/cashier/*.php fetch wrappers, the toast notification system,
   the live clock, and the sidebar nav-highlighting.

   There is no client-side branch switcher here -- each Cashier account is
   locked to one branch server-side (users.branch_id, see
   database/migrations/007_cashier_branch_lock.sql), and
   backend/cashier/getDashboardData.php already returns only that branch's
   data plus a `myBranch: {key, name}` field. Every page's own script reads
   that once per fetch and calls CashierApp.applyBranchChrome(myBranch.key)
   to label the sidebar -- there's nothing left for the user to pick. */

const CASHIER_API_BASE = '../../backend/cashier/';

const CASHIER_BRANCH_LABELS = {
  daraga: { name: 'Leo Mejillano Salon & Makeup Studio', terminal: 'Terminal: #DAR-01' },
  yashano: { name: 'Skin Brows by Leo Mejillano', terminal: 'Terminal: #YAS-01' },
  cabangan: { name: 'Lash & Brows by Leo Mejillano Salon', terminal: 'Terminal: #CAB-01' }
};

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/* Title-cases a name for display inside <option> elements, where the CSS
   `capitalize` class isn't reliably applied to the native dropdown popup
   in every browser. Plain text nodes (h3/p/span/etc.) should just use the
   `capitalize` Tailwind class instead -- this is only for <select> options. */
function titleCase(value) {
  return String(value ?? '').replace(/\w\S*/g, (w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase());
}

function statusBadgeClass(status) {
  switch (status) {
    case 'Pending': return 'bg-amber-100 text-[color:var(--warn)]';
    case 'Confirmed': return 'bg-purple-100 text-[color:var(--info)]';
    case 'In Progress': return 'bg-indigo-100 text-[color:var(--info)]';
    case 'Completed': return 'bg-emerald-100 text-[color:var(--success)]';
    case 'Cancelled': return 'bg-rose-100 text-[color:var(--danger)]';
    default: return 'bg-[color:var(--bg)] text-slate-700';
  }
}

/* A payment still waiting for a cashier on a booking past Pending, e.g.
   Reschedule Requested. Pending bookings verify through Confirm booking;
   updateStatus.php refuses Cancelled and Completed ones. */
function needsPaymentVerification(b) {
  return b.paymentStatus === 'Awaiting Verification' && !b.depositVerified
    && !['Pending', 'Cancelled', 'Completed'].includes(b.status);
}

const CashierApp = {
  async get(endpoint, params) {
    const query = new URLSearchParams();
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') query.append(key, value);
    });
    const qs = query.toString();
    const response = await fetch(CASHIER_API_BASE + endpoint + (qs ? '?' + qs : ''), { credentials: 'same-origin', cache: 'no-store' });
    if (response.status === 401) {
      window.location.href = '../../frontend/auth/login.html';
      throw new Error('Not authenticated');
    }
    return response.json();
  },

  async post(endpoint, params) {
    const body = new URLSearchParams();
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value === null || value === undefined) return;
      if (Array.isArray(value)) {
        value.forEach(v => body.append(key + '[]', v));
      } else {
        body.append(key, value);
      }
    });
    const response = await fetch(CASHIER_API_BASE + endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      body
    });
    if (response.status === 401) {
      window.location.href = '../../frontend/auth/login.html';
      throw new Error('Not authenticated');
    }
    return response.json();
  },

  async fetchDashboard() {
    const result = await this.get('getDashboardData.php');
    if (result && result.success) this.applyProfileChrome(result.cashierProfile);
    return result;
  },

  /* Reload this page's data whenever anything at the branch changes on any
     device (staff shift/break, bookings, cancellations, assignments) -- see
     function/shared/live-sync.js. */
  liveSync(reload, branchKey) {
    if (!window.LiveSync) return null;
    return window.LiveSync.start({ branch: branchKey, onChange: reload });
  },

  formatCurrency(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  },

  /* Live date/time in the salon's time zone (Asia/Manila), whatever the
     device's own clock zone is. */
  startClock(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const opts = { timeZone: 'Asia/Manila' };
    const tick = () => {
      const now = new Date();
      let datePart, timePart;
      try {
        datePart = now.toLocaleDateString('en-US', { ...opts, weekday: 'short', year: 'numeric', month: 'short', day: '2-digit' });
        timePart = now.toLocaleTimeString('en-US', { ...opts, hour: '2-digit', minute: '2-digit', hour12: true });
      } catch (e) {
        datePart = now.toLocaleDateString('en-US');
        timePart = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
      }
      el.textContent = `${datePart} · ${timePart}`;
    };
    tick();
    setInterval(tick, 15000);
  },

  /* Display-only money helpers, using the same rule as backend/cashier/payment.php:
     a verified reservation deposit counts toward the service total; once a
     checkout payment exists the booking has no balance. The backend stays the
     source of truth for every amount actually charged. */
  amountPaid(b) {
    if (b.checkoutPaid) return Number(b.price || 0);
    return (b.depositPaid && b.depositVerified) ? Number(b.depositAmount || 0) : 0;
  },

  balanceOf(b) {
    if (b.checkoutPaid) return 0;
    return Math.max(0, Math.round((Number(b.price || 0) - this.amountPaid(b)) * 100) / 100);
  },

  initials(name) {
    return String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join('') || 'C';
  },

  /* Puts the signed-in cashier's name/initials in the sidebar and header. */
  applyProfileChrome(profile) {
    if (!profile) return;
    const name = titleCase(profile.name || 'Cashier');
    document.querySelectorAll('[data-cp-name]').forEach(el => { el.textContent = name; });
    document.querySelectorAll('[data-cp-initials]').forEach(el => { el.textContent = this.initials(name); });
  },

  toast(message, type = 'info') {
    LMToast.show(message, type, { duration: 3000 });
  },

  showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    requestAnimationFrame(() => {
      modal.classList.remove('opacity-0');
      const panel = modal.querySelector('div');
      if (panel) panel.classList.remove('scale-95');
    });
  },

  hideModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.add('opacity-0');
    const panel = modal.querySelector('div');
    if (panel) panel.classList.add('scale-95');
    setTimeout(() => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    }, 250);
  },

  /* Sets the sidebar's branch title/terminal id to match the cashier's own
     (server-assigned) branch key. Called once per page after the dashboard
     data fetch resolves -- there's no selector to change it from anymore. */
  applyBranchChrome(branchKey) {
    const info = CASHIER_BRANCH_LABELS[branchKey] || CASHIER_BRANCH_LABELS.daraga;
    const logoEl = document.getElementById('sidebarLogo');
    const branchLogos = {
      yashano: { src: '../shared/icon/skin-brows-logo.png', alt: 'Skin Brows by Leo Mejillano logo' },
      cabangan: { src: '../shared/icon/lash-brows-logo.png', alt: 'Lash and Brows by Leo Mejillano Salon logo' },
    };
    if (logoEl && branchLogos[branchKey]) {
      logoEl.src = branchLogos[branchKey].src;
      logoEl.alt = branchLogos[branchKey].alt;
    }
    const titleEl = document.getElementById('sidebarBranchTitle');
    const branchSubtitle = document.getElementById('cashierBranchSubtitle');
    if (branchSubtitle) {
      const branchNames = { daraga: 'Daraga Branch', yashano: 'Yashano Branch', cabangan: 'Cabangan Branch' };
      branchSubtitle.textContent = branchNames[branchKey] || 'Branch unavailable';
    }
    const terminalEl = document.getElementById('sidebarTerminalId');
    const labelEl = document.getElementById('branchLabel');
    if (titleEl) titleEl.textContent = info.name;
    if (terminalEl) terminalEl.textContent = info.terminal;
    if (labelEl) labelEl.innerHTML = `<i class="fa-solid fa-store" aria-hidden="true"></i> ${escapeHtml(info.name)}`;
    return info;
  },

  /* Highlights the sidebar link matching the current page filename, same
     pattern as function/admin/shared-data.js's adminHighlightNav(). Every
     nav link carries data-nav-page="<filename>.html". */
  highlightNav() {
    const current = location.pathname.split('/').pop() || 'dashboard.html';
    document.querySelectorAll('#sidebarNav .nav-item').forEach(link => {
      if (link.getAttribute('data-nav-page') === current) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  },

  /* Common page boot: starts the clock and highlights the active sidebar
     link. Branch labeling happens separately (see applyBranchChrome) once
     each page's own fetchDashboard() call resolves and myBranch is known. */
  initChrome() {
    this.startClock('liveClock');
    this.highlightNav();
  },

  /* Updates the "Appointments" sidebar badge with the active-queue count.
     bookings is already scoped to the cashier's own branch by the backend,
     so no branch filter is needed here. */
  updateQueueBadge(bookings) {
    const badge = document.getElementById('appointmentCountBadge');
    if (!badge) return;
    const count = bookings.filter(b => ['Pending', 'Confirmed', 'In Progress'].includes(b.status)).length;
    badge.textContent = count;
  }
};
