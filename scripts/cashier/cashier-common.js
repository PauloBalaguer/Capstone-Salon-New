/* Shared helpers for the Leo Mejillano Salon cashier hub -- loaded before
   each page's own script on every pages/cashier/*.html page (dashboard,
   appointments, inventory, staff, payment, receipt). Each page is a
   separate full HTML file with its own copy of the sidebar/header markup
   (matching pages/admin/*.html's convention), so this file carries
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
    case 'Pending': return 'bg-amber-100 text-amber-800';
    case 'Confirmed': return 'bg-purple-100 text-purple-800';
    case 'In Progress': return 'bg-indigo-100 text-indigo-800';
    case 'Completed': return 'bg-emerald-100 text-emerald-800';
    case 'Cancelled': return 'bg-rose-100 text-rose-800';
    default: return 'bg-slate-100 text-slate-700';
  }
}

const CashierApp = {
  async get(endpoint, params) {
    const query = new URLSearchParams();
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') query.append(key, value);
    });
    const qs = query.toString();
    const response = await fetch(CASHIER_API_BASE + endpoint + (qs ? '?' + qs : ''), { credentials: 'same-origin' });
    if (response.status === 401) {
      window.location.href = '../login/login.html';
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
      window.location.href = '../login/login.html';
      throw new Error('Not authenticated');
    }
    return response.json();
  },

  async fetchDashboard() {
    return this.get('getDashboardData.php');
  },

  formatCurrency(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  },

  startClock(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const tick = () => {
      const now = new Date();
      const datePart = now.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: '2-digit' });
      const timePart = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
      el.textContent = `${datePart} | ${timePart}`;
    };
    tick();
    setInterval(tick, 1000);
  },

  toast(message, type = 'info') {
    const container = document.getElementById('notificationContainer');
    if (!container) return;
    const palette = {
      success: 'bg-emerald-600 border-emerald-700',
      error: 'bg-rose-600 border-rose-700',
      info: 'bg-slate-800 border-slate-900'
    };
    const icon = {
      success: 'fa-circle-check',
      error: 'fa-triangle-exclamation',
      info: 'fa-circle-info'
    };
    const toastEl = document.createElement('div');
    toastEl.className = `pointer-events-auto text-white text-xs font-semibold px-4 py-3 rounded-xl shadow-lg border flex items-start space-x-2 transition-all duration-300 ease-[cubic-bezier(.16,1,.3,1)] opacity-0 translate-x-4 scale-95 ${palette[type] || palette.info}`;
    toastEl.innerHTML = `<i class="fa-solid ${icon[type] || icon.info} mt-0.5"></i><span>${message}</span>`;
    container.appendChild(toastEl);
    requestAnimationFrame(() => {
      toastEl.classList.remove('opacity-0', 'translate-x-4', 'scale-95');
    });
    setTimeout(() => {
      toastEl.classList.add('opacity-0', 'translate-x-4');
      setTimeout(() => toastEl.remove(), 300);
    }, 4000);
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
    const titleEl = document.getElementById('sidebarBranchTitle');
    const branchSubtitle = document.getElementById('cashierBranchSubtitle');
    if (branchSubtitle) {
      const branchNames = { daraga: 'Daraga Branch - Cashier', yashano: 'Yashano Branch - Cashier', cabangan: 'Cabangan Branch - Cashier' };
      branchSubtitle.textContent = branchNames[branchKey] || 'Branch unavailable';
    }
    const terminalEl = document.getElementById('sidebarTerminalId');
    const labelEl = document.getElementById('branchLabel');
    if (titleEl) titleEl.textContent = info.name;
    if (terminalEl) terminalEl.textContent = info.terminal;
    if (labelEl) labelEl.textContent = `🏬 ${info.name}`;
    return info;
  },

  /* Highlights the sidebar link matching the current page filename, same
     pattern as scripts/admin/shared-data.js's adminHighlightNav(). Every
     nav link carries data-nav-page="<filename>.html". */
  highlightNav() {
    const current = location.pathname.split('/').pop();
    document.querySelectorAll('#sidebarNav .nav-item').forEach(link => {
      const isActive = link.getAttribute('data-nav-page') === current;
      link.classList.toggle('bg-amber-500', isActive);
      link.classList.toggle('text-slate-950', isActive);
      link.classList.toggle('font-medium', isActive);
      link.classList.toggle('text-slate-300', !isActive);
      link.classList.toggle('hover:bg-slate-800', !isActive);
      link.classList.toggle('hover:text-white', !isActive);
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
