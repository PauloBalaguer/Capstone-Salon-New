/* Shared live data + helpers for the Leo Mejillano Salon staff portal.
   Loaded on every pages/staff/*.html page before that page's own script.
   Populates an Alpine.store('staff') from the real backend
   (backend/employee/getDashboardData.php) and refreshes it after every
   mutation, so an update made on one page (e.g. starting a service on
   Assigned Appointments) is visible after navigating to another (e.g. the
   Dashboard's "today" list). Mirrors the pattern already used by
   scripts/admin/shared-data.js. */

const STAFF_API_BASE = '../../backend/';

document.addEventListener('alpine:init', () => {
  Alpine.store('staff', {
    loading: true,
    profile: { employeeId: null, name: '', firstName: '', lastName: '', phone: '', profilePicture: null, email: '', role: '', branchId: null, branchName: '', hireDate: null },
    notificationPrefs: { bookingAlerts: true, inventoryAlerts: true, orderAlerts: true, marketingAlerts: false },
    appointments: [],
    attendance: [],
    performance: { completedCount: 0, completedThisMonth: 0, avgRating: 0, totalReviews: 0 },
    recentFeedback: [],
    inventory: [],
    supplyUsage: [],
    notifications: [],
    isNotificationPanelOpen: false,
    now: new Date(),
    busy: false,
    toasts: [],
    toastSeq: 0,

    toast(message, type = 'success') {
      const id = ++this.toastSeq;
      this.toasts.push({ id, message, type });
      setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 3600);
    },

    async refresh() {
      try {
        const response = await fetch(STAFF_API_BASE + 'employee/getDashboardData.php', { credentials: 'same-origin' });
        if (response.status === 401) {
          window.location.href = '../login/login.html';
          return;
        }
        const data = await response.json();
        if (!data.success) {
          console.error('Failed to load staff dashboard data:', data.message);
          return;
        }
        this.profile = data.profile;
        this.notificationPrefs = data.notificationPrefs;
        this.appointments = data.appointments;
        this.attendance = data.attendance;
        this.performance = data.performance;
        this.recentFeedback = data.recentFeedback;
        this.inventory = data.inventory;
        this.supplyUsage = data.supplyUsage;
        this.notifications = data.notifications;
      } catch (e) {
        console.error('Network error while loading staff dashboard data:', e);
      } finally {
        this.loading = false;
      }
    },

    /* POSTs to any backend/*.php mutation endpoint (relative to backend/,
       e.g. "appointment/update.php") and returns the parsed JSON response.
       Callers refresh() afterward on success. */
    async post(endpoint, params) {
      const body = new URLSearchParams();
      Object.entries(params || {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined) body.append(key, value);
      });
      const response = await fetch(STAFF_API_BASE + endpoint, {
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

    async startService(referenceCode) {
      this.busy = true;
      try {
        const res = await this.post('appointment/update.php', { id: referenceCode, status: 'In Progress' });
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) await this.refresh();
        return res;
      } finally {
        this.busy = false;
      }
    },

    async completeService(referenceCode) {
      this.busy = true;
      try {
        const res = await this.post('appointment/update.php', { id: referenceCode, status: 'Completed' });
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) await this.refresh();
        return res;
      } finally {
        this.busy = false;
      }
    },

    async logSupplyUsage(itemId, quantity, referenceCode, note) {
      this.busy = true;
      try {
        const res = await this.post('inventory/update.php', { id: itemId, quantity, referenceCode, note });
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) await this.refresh();
        return res;
      } finally {
        this.busy = false;
      }
    },

    async saveProfile(fields) {
      this.busy = true;
      try {
        const res = await this.post('employee/saveProfile.php', fields);
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) await this.refresh();
        return res;
      } finally {
        this.busy = false;
      }
    },

    // Step 1 of 2: verifies the current password + new-password rules and,
    // on success, emails a confirmation code -- nothing is applied yet.
    async changePassword(fields) {
      this.busy = true;
      try {
        const res = await this.post('employee/changePassword.php', fields);
        this.toast(res.message, res.success ? 'success' : 'error');
        return res;
      } finally {
        this.busy = false;
      }
    },

    // Step 2 of 2: submits the emailed code alongside the same new password
    // to actually apply the change.
    async confirmPasswordChange(fields) {
      this.busy = true;
      try {
        const res = await this.post('employee/confirmPasswordChange.php', fields);
        this.toast(res.message, res.success ? 'success' : 'error');
        return res;
      } finally {
        this.busy = false;
      }
    },

    todayISO() {
      const d = this.now;
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    },

    getTodayAppointments() {
      const today = this.todayISO();
      return this.appointments
        .filter(a => a.date === today)
        .sort((a, b) => a.time.localeCompare(b.time));
    },

    getUpcomingAppointments() {
      const today = this.todayISO();
      return this.appointments
        .filter(a => a.date > today && !['Cancelled', 'Completed'].includes(a.status))
        .sort((a, b) => a.date.localeCompare(b.date));
    },

    getTodayAttendance() {
      const today = this.todayISO();
      return this.attendance.find(a => a.date === today) || null;
    },

    isClockedIn() {
      const today = this.getTodayAttendance();
      return !!(today && today.isOpen);
    },

    getShiftDurationLabel() {
      const today = this.getTodayAttendance();
      if (!today) return '--:--';
      const [time, meridiem] = today.clockIn.split(' ');
      let [h, m] = time.split(':').map(Number);
      if (meridiem === 'PM' && h !== 12) h += 12;
      if (meridiem === 'AM' && h === 12) h = 0;
      const start = new Date(this.now);
      start.setHours(h, m, 0, 0);
      let diffMs = this.now - start;
      if (diffMs < 0) diffMs = 0;
      const totalMinutes = Math.floor(diffMs / 60000);
      const hrs = Math.floor(totalMinutes / 60);
      const mins = totalMinutes % 60;
      return `${String(hrs).padStart(2, '0')}h ${String(mins).padStart(2, '0')}m`;
    },

    unreadNotificationCount() {
      return this.notifications.filter(n => !n.isRead).length;
    },

    async toggleNotificationPanel() {
      this.isNotificationPanelOpen = !this.isNotificationPanelOpen;
      if (this.isNotificationPanelOpen && this.unreadNotificationCount() > 0) {
        await this.post('employee/markNotificationsRead.php', {});
        this.notifications = this.notifications.map(n => ({ ...n, isRead: true }));
      }
    },

    getLowStockItems() {
      return this.inventory.filter(item => item.stock <= item.minQty);
    },

    getStatusBadgeClass(status) {
      switch (status) {
        // Light chips with dark text -- the staff theme paints cards cream,
        // so the old *-950 backgrounds rendered as dark blocks with
        // unreadable text. Arbitrary hex values dodge staff-theme.css's
        // emerald/slate remaps so each status keeps its own colour.
        case 'Pending': return 'bg-[#FEF3C7] text-[#92400E] border border-[#FCD34D]';
        case 'Confirmed': return 'bg-[#E0F2FE] text-[#075985] border border-[#7DD3FC]';
        case 'In Progress': return 'bg-[#CCFBF1] text-[#115E59] border border-[#5EEAD4]';
        case 'Completed': return 'bg-[#DCFCE7] text-[#166534] border border-[#86EFAC]';
        case 'Reviewed': return 'bg-[#EDE9FE] text-[#5B21B6] border border-[#C4B5FD]';
        case 'Reschedule Requested': return 'bg-[#FFEDD5] text-[#9A3412] border border-[#FDBA74]';
        case 'Cancelled': case 'No-Show': return 'bg-[#FFE4E6] text-[#9F1239] border border-[#FDA4AF]';
        default: return 'bg-[#F1F5F9] text-[#334155] border border-[#CBD5E1]';
      }
    },

    formatCurrency(n) {
      return 'PHP ' + Number(n || 0).toLocaleString();
    },

    firstName() {
      const name = this.profile.name || '';
      return name.split(' ')[0] || 'there';
    },

    initials() {
      const name = (this.profile.name || '').trim();
      if (!name) return '..';
      const parts = name.split(/\s+/).filter(Boolean);
      return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
    },

    startClock() {
      setInterval(() => { this.now = new Date(); }, 1000);
    }
  });

  Alpine.store('staff').refresh();
  Alpine.store('staff').startClock();
});

/* Highlights the sidebar nav link matching the current page filename. Call
   from each page's x-init alongside that page's own init logic. */
function staffHighlightNav() {
  const current = location.pathname.split('/').pop();
  document.querySelectorAll('[data-nav-page]').forEach(link => {
    const isActive = link.getAttribute('data-nav-page') === current;
    link.classList.toggle('bg-emerald-900/40', isActive);
    link.classList.toggle('text-white', isActive);
    link.classList.toggle('font-medium', isActive);
    link.classList.toggle('border-l-4', isActive);
    link.classList.toggle('border-emerald-400', isActive);
    link.classList.toggle('text-slate-400', !isActive);
  });
}

/* Slides the mobile-only floating bottom nav's highlight pill behind
   whichever [data-mobile-nav-page] link matches the current page. */
function staffPositionMobileNav() {
  const current = location.pathname.split('/').pop();
  const slider = document.getElementById('mobile-nav-slider');
  const links = document.querySelectorAll('[data-mobile-nav-page]');
  if (!slider || !links.length) return;

  let activeLink = null;
  links.forEach(link => {
    const isActive = link.getAttribute('data-mobile-nav-page') === current;
    link.classList.toggle('text-white', isActive);
    link.classList.toggle('text-slate-400', !isActive);
    if (isActive) activeLink = link;
  });

  if (activeLink) {
    slider.style.left = activeLink.offsetLeft + 'px';
    slider.style.width = activeLink.offsetWidth + 'px';
  }
}

/* Call once from each page's init(): positions the slider after layout
   settles, and keeps it aligned across resizes/orientation changes. */
function staffInitMobileNav() {
  requestAnimationFrame(staffPositionMobileNav);
  window.addEventListener('resize', staffPositionMobileNav);
}
