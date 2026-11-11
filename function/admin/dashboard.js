/* Dashboard: every figure is computed from the live admin store
   (backend/admin/getDashboardData.php) for the selected branch. */
// Clocked in at the salon (StaffShift::CLOCKED_IN_STATUSES).
const ADMIN_ON_SHIFT_STATUSES = ['available', 'busy', 'on_break'];

function adminDashboardApp() {
  return {
    branchSel: AdminUI.param('branch') || 'all',
    today: AdminUI.today(),

    init() {
      adminHighlightNav();
      // Keep the chosen branch in the address bar so refresh/back keeps it.
      this.$watch('branchSel', (value) => {
        try { history.replaceState(null, '', value === 'all' ? location.pathname : location.pathname + '?branch=' + encodeURIComponent(value)); } catch (e) {}
      });
    },

    selectBranch(branchId) {
      this.branchSel = this.branchSel === branchId ? 'all' : branchId;
      if (window.matchMedia('(max-width: 1023.98px)').matches) {
        this.$nextTick(() => {
          this.$refs.branchResults?.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'start'
          });
        });
      }
    },

    inBranch(branchId) { return this.branchSel === 'all' || branchId === this.branchSel; },

    // Builds a link to another panel carrying the selected branch as a filter.
    link(page, params) {
      const query = new URLSearchParams(params || {});
      if (this.branchSel !== 'all') query.set('branch', this.branchSel);
      const text = query.toString();
      return page + (text ? '?' + text : '');
    },

    branchBookings() {
      return Alpine.store('admin').bookings.filter(b => this.branchSel === 'all' || (!b.isHomeService && b.branchId === this.branchSel));
    },
    todaysAppointments() {
      return this.branchBookings().filter(b => b.date === this.today && !['Cancelled', 'No-Show'].includes(b.status));
    },
    pendingBookings() {
      return this.branchBookings().filter(b => b.status === 'Pending');
    },
    recentBookings() {
      return this.branchBookings().slice(0, 6); // already newest submission first
    },
    branchStaff() {
      return Alpine.store('admin').staffList.filter(s => this.inBranch(s.branchId));
    },
    staffOnShift() {
      return this.branchStaff().filter(s => ADMIN_ON_SHIFT_STATUSES.includes(s.status));
    },
    staffSummary() {
      const list = this.branchStaff();
      const count = (status) => list.filter(s => s.status === status).length;
      return `${count('available')} available · ${count('busy')} busy · ${count('busy_home_service')} on home service · ${count('on_break')} on break`;
    },
    collections() {
      const store = Alpine.store('admin');
      if (this.branchSel === 'all') return store.getTotalRevenue();
      const branch = store.getBranch(this.branchSel);
      return branch ? branch.revenue : 0;
    },
    lowStock() {
      return Alpine.store('admin').getLowStockItems().filter(i => this.inBranch(i.branchId));
    }
  };
}
