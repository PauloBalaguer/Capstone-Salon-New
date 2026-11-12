/* Shared live data + helpers for the Leo Mejillano Salon admin panel.
   Loaded on every frontend/admin/*.html page before that page's own script.
   Populates an Alpine.store('admin') from the real backend
   (backend/admin/getDashboardData.php) and refreshes it after every
   mutation, so edits on one page are visible after navigating to another. */

/* The five live staff statuses (StaffShift::STATUS_META). Compare on the key;
   show the label. Badges use the shared .live-status styles (theme-tokens.css). */
const LIVE_STATUS = {
  available: { label: 'Available now', color: 'green' },
  busy: { label: 'Busy', color: 'blue' },
  busy_home_service: { label: 'Busy · Home service', color: 'blue' },
  on_break: { label: 'On break', color: 'amber' },
  off_shift: { label: 'Off shift', color: 'gray' }
};
const LIVE_STATUS_CLOCKED_IN = ['available', 'busy', 'on_break'];
function liveStatusLabel(key) { return (LIVE_STATUS[key] || LIVE_STATUS.off_shift).label; }
function liveStatusClass(key) { return 'live-status live-status--' + (LIVE_STATUS[key] || LIVE_STATUS.off_shift).color; }

const ADMIN_API_BASE = '../../backend/admin/';

document.addEventListener('alpine:init', () => {
  Alpine.store('admin', {
    loading: true,
    error: '',
    branches: [],
    collectedRevenue: null,
    bookings: [],
    bookingQuery: location.pathname.endsWith('/appointments.html') ? { bookingPage: 1, pageSize: 10 } : null,
    bookingPagination: null,
    staffList: [],
    cashierList: [],
    inventory: [],
    promotions: [],
    weddingPackages: [],
    eventDurations: [],
    homeServiceDefaults: { fallbackServiceMinutes: 120, cleaningBufferMinutes: 15 },
    feedback: [],
    notifications: [],
    notificationsReadBusy: false,
    notificationReadError: '',
    unreadNotifications() { return this.notifications.filter(n => !n.isRead).length; },
    async markAllNotificationsAsRead() {
      if (this.notificationsReadBusy || !this.unreadNotifications()) return;
      const through = Math.max(...this.notifications.map(n => Number(n.id)));
      this.notificationsReadBusy = true;
      this.notificationReadError = '';
      try {
        const result = await this.post('markNotificationsRead.php', {through});
        if (!result.success) throw new Error('Read update failed');
        this.notifications.forEach(n => { if (Number(n.id) <= through) n.isRead = true; });
      } catch {
        this.notificationReadError = 'Could not update. Try again.';
      } finally { this.notificationsReadBusy = false; }
    },
    // Marks one notification read (saved per admin) and updates the bell at once.
    async markNotificationRead(item) {
      if (!item || item.isRead) return;
      item.isRead = true;
      try {
        const result = await this.post('markNotificationsRead.php', {id: item.id});
        if (!result.success) throw new Error('Read update failed');
      } catch {
        item.isRead = false;
        this.notificationReadError = 'Could not update. Try again.';
      }
    },
    // The admin page that handles this notification, filtered to its booking when it has one.
    notificationDestination(item) {
      const reference = (item.message || '').match(/\bLM-(?:[A-Z]+-)+\d{4}-\d+\b/)?.[0] || '';
      const pages = {INVENTORY: 'inventory.html', SERVICE: 'promotions.html', PACKAGE: 'promotions.html', PROMO: 'promotions.html',
        STAFF: 'employees.html', ATTENDANCE: 'employees.html', SETTINGS: 'settings.html', EOD: 'reports.html'};
      if (reference) return 'appointments.html?search=' + encodeURIComponent(reference);
      return pages[item.type] || 'appointments.html';
    },
    openNotification(item) {
      const href = this.notificationDestination(item);
      this.markNotificationRead(item);
      window.location.href = href;
    },
    notificationPrefs: { bookingAlerts: true, inventoryAlerts: true, orderAlerts: true, marketingAlerts: false },
    customerDirectory: [],
    adminProfile: { name: '', email: '', role: '' },
    servicesByBranch: {},
    services: [],

    async refresh() {
      if (this.error) this.loading = true;
      this.error = '';
      try {
        const bookingQuery = this.bookingQuery ? new URLSearchParams(this.bookingQuery).toString() : '';
        const response = await fetch(ADMIN_API_BASE + 'getDashboardData.php' + (bookingQuery ? '?' + bookingQuery : ''), { credentials: 'same-origin', cache: 'no-store' });
        if (response.status === 401) {
          window.location.href = '../../frontend/auth/login.html';
          return;
        }
        const data = await response.json();
        if (!data.success) {
          this.error = data.message || 'Could not load booking management data. Please retry.';
          console.error('Failed to load admin dashboard data:', data.message);
          return;
        }
        this.branches = data.branches;
        this.collectedRevenue = data.collectedRevenue ?? null;
        if (bookingQuery === (this.bookingQuery ? new URLSearchParams(this.bookingQuery).toString() : '')) {
          this.bookings = data.bookings;
          this.bookingPagination = data.bookingPagination;
          if (this.bookingQuery && data.bookingPagination) this.bookingQuery = {...this.bookingQuery, bookingPage: data.bookingPagination.page};
        }
        this.staffList = data.staffList;
        this.cashierList = data.cashierList;
        this.inventory = data.inventory;
        this.promotions = data.promotions;
        this.weddingPackages = data.weddingPackages;
        this.eventDurations = data.eventDurations || [];
        this.homeServiceDefaults = data.homeServiceDefaults || this.homeServiceDefaults;
        this.feedback = data.feedback;
        this.notifications = data.notifications;
        this.notificationPrefs = data.notificationPrefs;
        this.customerDirectory = data.customerDirectory;
        this.adminProfile = data.adminProfile;
        this.servicesByBranch = data.servicesByBranch;
        this.services = data.services;
      } catch (e) {
        this.error = 'Could not load admin data. Check your connection and retry.';
        console.error('Network error while loading admin dashboard data:', e);
      } finally {
        this.loading = false;
      }
    },

    /* GETs a backend/admin/*.php read endpoint and returns the parsed JSON. */
    async get(endpoint, params) {
      const response = await fetch(ADMIN_API_BASE + endpoint + '?' + new URLSearchParams(params || {}), { credentials: 'same-origin', cache: 'no-store' });
      if (response.status === 401) {
        window.location.href = '../../frontend/auth/login.html';
        throw new Error('Not authenticated');
      }
      return response.json();
    },

    /* POSTs to a backend/admin/*.php mutation endpoint and returns the
       parsed JSON response. Callers refresh() afterward on success. */
    async post(endpoint, params) {
      const body = new URLSearchParams();
      Object.entries(params || {}).forEach(([key, value]) => {
        if (value === null || value === undefined) return;
        if (Array.isArray(value)) {
          value.forEach(item => body.append(key + '[]', item));
        } else {
          body.append(key, value);
        }
      });
      const response = await fetch(ADMIN_API_BASE + endpoint, {
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

    getBranch(branchId) {
      return this.branches.find(b => b.id === branchId) || null;
    },

    getBranchName(branchId) {
      if (branchId === 'home-service') return 'Home Service (off-site)';
      const b = this.getBranch(branchId);
      return b ? b.name : 'Unknown Branch';
    },

    getStaffMember(staffId) {
      return this.staffList.find(s => s.id === staffId) || null;
    },

    formatCurrency(n) {
      return 'PHP ' + Number(n || 0).toLocaleString();
    },

    /* Total staff time one event blocks -- mirrors Scheduling::homeServiceWindow()
       (blank travel/setup = 0, blank service = the 120-minute fallback,
       blank return travel = the same as travel). */
    eventWindowLabel(d) {
      const defaults = this.homeServiceDefaults;
      const service = Number(d.serviceMinutes) || defaults.fallbackServiceMinutes;
      const travel = Number(d.travelMinutes) || 0;
      const back = d.returnTravelMinutes === '' || d.returnTravelMinutes === null || d.returnTravelMinutes === undefined ? travel : (Number(d.returnTravelMinutes) || 0);
      const total = travel + (Number(d.setupMinutes) || 0) + service + back + defaults.cleaningBufferMinutes;
      const hours = Math.floor(total / 60), mins = total % 60;
      return `${hours ? hours + ' h ' : ''}${mins ? mins + ' min' : ''}`.trim() + ` incl. ${defaults.cleaningBufferMinutes}-min buffer`;
    },

    getStatusBadgeClass(status) {
      switch (status) {
        case 'Pending':
        case 'Pending Review':
        case 'Quote Ready':
        case 'Payment Required': return 'admin-badge admin-badge--pending';
        case 'Payment Being Verified': return 'admin-badge admin-badge--info';
        case 'Confirmed':
        case 'Staff Assigned': return 'admin-badge admin-badge--confirmed';
        case 'In Progress':
        case 'Service in Progress': return 'admin-badge admin-badge--info';
        case 'Completed': return 'admin-badge admin-badge--success';
        case 'Cancelled': return 'admin-badge admin-badge--danger';
        default: return 'admin-badge admin-badge--neutral';
      }
    },

    getLowStockItems() {
      return this.inventory.filter(item => item.isActive && item.stock <= item.minQty);
    },

    getTotalRevenue() {
      return this.collectedRevenue ?? this.branches.reduce((sum, b) => sum + Number(b.revenue), 0);
    },

    getMaxBranchRevenue() {
      return Math.max(...this.branches.map(b => Number(b.revenue)), 1);
    },

    /* Revenue's share of the branch total -- 0% (never NaN) when nothing
       has been earned yet across any branch. */
    getRevenueSharePct(branchRevenue) {
      const total = this.getTotalRevenue();
      if (!total) return 0;
      return Math.round((Number(branchRevenue) / total) * 100);
    },

    /* "No ratings yet" instead of a bare 0 (which reads as a real 0-star
       rating rather than "nobody has rated this branch yet"). */
    getBranchRatingLabel(branch) {
      if (!branch || !branch.ratingCount) return 'No ratings yet';
      return String(branch.rating);
    },

    getStaffStatusSummary() {
      return {
        onDuty: this.staffList.filter(s => s.status === 'available').length,
        withClient: this.staffList.filter(s => s.status === 'busy').length,
        onHomeService: this.staffList.filter(s => s.status === 'busy_home_service').length,
        onBreak: this.staffList.filter(s => s.status === 'on_break').length,
        offShift: this.staffList.filter(s => s.status === 'off_shift').length
      };
    },

    calculatePerformanceScore(staff) {
      const attendanceScore = staff.attendance * 0.4;
      const volumeScore = Math.min(staff.completedCount, 150) / 150 * 100 * 0.35;
      const ratingScore = (staff.rating / 5) * 100 * 0.25;
      return attendanceScore + volumeScore + ratingScore;
    },

    getTopPerformer() {
      if (!this.staffList.length) return { name: 'Not enough data yet' };
      // Nobody has any completed services, ratings, or attendance yet --
      // ranking by performance score would just be arbitrarily picking
      // whoever sorts first among a tie of zeroes.
      const hasData = this.staffList.some(s => s.completedCount > 0 || s.rating > 0 || s.attendance > 0);
      if (!hasData) return { name: 'Not enough data yet' };
      return [...this.staffList].sort((a, b) => this.calculatePerformanceScore(b) - this.calculatePerformanceScore(a))[0];
    },

    /* Customer loyalty tier (VIP / Gold / Silver / Member) comes from the
       backend -- completed visits in the last 12 months, see
       backend/shared/config/helpers/LoyaltyTier.php. This only picks the badge style. */
    getCustomerTierBadgeClass(tier) {
      switch (tier) {
        case 'VIP': return 'admin-badge admin-tier--vip';
        case 'Gold': return 'admin-badge admin-tier--gold';
        case 'Silver': return 'admin-badge admin-tier--silver';
        default: return 'admin-badge admin-badge--neutral';
      }
    },

    getAverageRating() {
      if (!this.feedback.length) return '0.0';
      const total = this.feedback.reduce((sum, f) => sum + f.rating, 0);
      return (total / this.feedback.length).toFixed(1);
    },

    exportRowsToCSV(filename, headers, rows) {
      const escapeCell = (val) => {
        const s = String(val === null || val === undefined ? '' : val);
        return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
      };
      const lines = [headers.map(escapeCell).join(',')]
        .concat(rows.map(row => row.map(escapeCell).join(',')));
      const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    }
  });

  Alpine.store('admin').refresh();
  // Keep staff status and bookings live across every branch: reload the
  // moment a shift, break, booking or assignment changes anywhere
  // (function/shared/live-sync.js); the 30 s timer stays as a safety net.
  if (window.LiveSync) window.LiveSync.start({ branch: 'all', onChange: () => Alpine.store('admin').refresh() });
  setInterval(() => { if (!document.hidden) Alpine.store('admin').refresh(); }, 30000);
});

/* Highlights the sidebar nav link matching the current page filename. Call from
   each page's x-init alongside that page's own init logic. */
function adminHighlightNav() {
  const current = location.pathname.split('/').pop();
  // Styling lives in frontend/admin/admin-panel.css ([aria-current="page"]).
  document.querySelectorAll('[data-nav-page]').forEach(link => {
    if (link.getAttribute('data-nav-page') === current) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
}
