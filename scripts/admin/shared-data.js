/* Shared live data + helpers for the Leo Mejillano Salon admin panel.
   Loaded on every pages/admin/*.html page before that page's own script.
   Populates an Alpine.store('admin') from the real backend
   (backend/admin/getDashboardData.php) and refreshes it after every
   mutation, so edits on one page are visible after navigating to another. */

const ADMIN_API_BASE = '../../backend/admin/';

document.addEventListener('alpine:init', () => {
  Alpine.store('admin', {
    loading: true,
    branches: [],
    bookings: [],
    staffList: [],
    cashierList: [],
    inventory: [],
    promotions: [],
    weddingPackages: [],
    feedback: [],
    notifications: [],
    notificationPrefs: { bookingAlerts: true, inventoryAlerts: true, orderAlerts: true, marketingAlerts: false },
    customerDirectory: [],
    adminProfile: { name: '', email: '', role: '' },
    servicesByBranch: {},
    services: [],

    async refresh() {
      try {
        const response = await fetch(ADMIN_API_BASE + 'getDashboardData.php', { credentials: 'same-origin' });
        if (response.status === 401) {
          window.location.href = '../login/login.html';
          return;
        }
        const data = await response.json();
        if (!data.success) {
          console.error('Failed to load admin dashboard data:', data.message);
          return;
        }
        this.branches = data.branches;
        this.bookings = data.bookings;
        this.staffList = data.staffList;
        this.cashierList = data.cashierList;
        this.inventory = data.inventory;
        this.promotions = data.promotions;
        this.weddingPackages = data.weddingPackages;
        this.feedback = data.feedback;
        this.notifications = data.notifications;
        this.notificationPrefs = data.notificationPrefs;
        this.customerDirectory = data.customerDirectory;
        this.adminProfile = data.adminProfile;
        this.servicesByBranch = data.servicesByBranch;
        this.services = data.services;
      } catch (e) {
        console.error('Network error while loading admin dashboard data:', e);
      } finally {
        this.loading = false;
      }
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
        window.location.href = '../login/login.html';
        throw new Error('Not authenticated');
      }
      return response.json();
    },

    getBranch(branchId) {
      return this.branches.find(b => b.id === branchId) || null;
    },

    getBranchName(branchId) {
      const b = this.getBranch(branchId);
      return b ? b.name : 'Unknown Branch';
    },

    getStaffMember(staffId) {
      return this.staffList.find(s => s.id === staffId) || null;
    },

    formatCurrency(n) {
      return 'PHP ' + Number(n || 0).toLocaleString();
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
      return this.branches.reduce((sum, b) => sum + Number(b.revenue), 0);
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
        onDuty: this.staffList.filter(s => s.status === 'On Duty').length,
        withClient: this.staffList.filter(s => s.status === 'With Client').length,
        offShift: this.staffList.filter(s => s.status === 'Off Shift').length
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
       backend/config/LoyaltyTier.php. This only picks the badge style. */
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
});

/* Highlights the sidebar nav link matching the current page filename. Call from
   each page's x-init alongside that page's own init logic. */
function adminHighlightNav() {
  const current = location.pathname.split('/').pop();
  document.querySelectorAll('[data-nav-page]').forEach(link => {
    const isActive = link.getAttribute('data-nav-page') === current;
    link.classList.toggle('admin-nav-active', isActive);
    link.classList.toggle('font-medium', isActive);
    link.classList.toggle('border-l-4', isActive);
    link.classList.toggle('border-gold-400', isActive);
    link.classList.toggle('text-slate-400', !isActive);
  });
}
