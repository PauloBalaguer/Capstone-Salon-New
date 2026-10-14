function adminReportsApp() {
  return {
    reportType: 'appointments',
    reportFilterBranch: 'all',
    reportDateFrom: '',
    reportDateTo: '',

    init() {
      adminHighlightNav();
    },

    // Online Payment Log (backend/admin/getPaymentLog.php), loaded the first
    // time the report is opened and on each reference search.
    paymentLog: [],
    paymentLogLoaded: false,
    paymentLogSearch: '',
    paymentLogError: '',
    async loadPaymentLog() {
      this.paymentLogError = '';
      try {
        const params = new URLSearchParams({ reference: this.paymentLogSearch.trim() });
        const response = await fetch(ADMIN_API_BASE + 'getPaymentLog.php?' + params, { credentials: 'same-origin' });
        const body = await response.json();
        if (!body.success) throw new Error(body.message || 'Could not load the payment log.');
        this.paymentLog = body.events;
      } catch (error) {
        this.paymentLogError = error.message || 'Could not load the payment log.';
      } finally {
        this.paymentLogLoaded = true;
      }
    },
    paymentEventLabel(event) {
      const labels = {
        checkout_created: 'Checkout created', payment_paid: 'Paid', checkout_expired: 'Expired (unpaid)',
        webhook_received: 'Webhook received', webhook_rejected: 'Webhook rejected', api_error: 'Error',
        deposit_forfeited: 'Deposit forfeited', refund_due: 'Refund due', refunded: 'Refunded',
        refund_requested: 'Refund sent to PayMongo', refund_failed: 'Refund failed'
      };
      return labels[event] || event;
    },
    paymentEventBadge(event) {
      if (['payment_paid', 'refunded'].includes(event)) return 'admin-badge--success';
      if (['webhook_rejected', 'api_error', 'deposit_forfeited', 'refund_failed'].includes(event)) return 'admin-badge--danger';
      if (['refund_due', 'checkout_expired', 'refund_requested'].includes(event)) return 'admin-badge--pending';
      return 'admin-badge--neutral';
    },

    // Activity Log (backend/admin/getAuditLog.php): who did what, when.
    activityLog: [],
    activityLogLoaded: false,
    activitySearch: '',
    activityRole: '',
    activityError: '',
    activityExpandedId: null,
    async loadActivityLog() {
      this.activityError = '';
      try {
        const params = new URLSearchParams({ q: this.activitySearch.trim(), role: this.activityRole });
        if (this.reportDateFrom) params.set('from', this.reportDateFrom);
        if (this.reportDateTo) params.set('to', this.reportDateTo);
        const response = await fetch(ADMIN_API_BASE + 'getAuditLog.php?' + params, { credentials: 'same-origin' });
        const body = await response.json();
        if (!body.success) throw new Error(body.message || 'Could not load the activity log.');
        this.activityLog = body.entries;
      } catch (error) {
        this.activityError = error.message || 'Could not load the activity log.';
      } finally {
        this.activityLogLoaded = true;
      }
    },
    // Request fields as "key: value" lines for the expanded row.
    activityDetails(entry) {
      try {
        return Object.entries(JSON.parse(entry.details || '{}')).map(([k, v]) => k + ': ' + v).join('\n');
      } catch (e) {
        return entry.details || '';
      }
    },

    // PayMongo Reconciliation (backend/admin/reconcilePaymongo.php).
    reconciliation: null,
    reconciling: false,
    reconcileError: '',
    reconcileIssuesOnly: false,
    async runReconciliation() {
      this.reconciling = true;
      this.reconcileError = '';
      try {
        const params = new URLSearchParams();
        if (this.reportDateFrom) params.set('from', this.reportDateFrom);
        if (this.reportDateTo) params.set('to', this.reportDateTo);
        const response = await fetch(ADMIN_API_BASE + 'reconcilePaymongo.php?' + params, { credentials: 'same-origin' });
        const body = await response.json();
        if (!body.success) throw new Error(body.message || 'Could not reconcile payments.');
        this.reconciliation = body;
      } catch (error) {
        this.reconcileError = error.message || 'Could not reconcile payments.';
      } finally {
        this.reconciling = false;
      }
    },
    reconcileRows() {
      if (!this.reconciliation) return [];
      return this.reconcileIssuesOnly ? this.reconciliation.rows.filter(r => r.severity !== 'ok') : this.reconciliation.rows;
    },
    reconcileBadge(severity) {
      return { ok: 'admin-badge--success', warning: 'admin-badge--pending', danger: 'admin-badge--danger' }[severity] || 'admin-badge--neutral';
    },
    async syncPayment(row) {
      const result = await Alpine.store('admin').post('reconcilePaymongo.php', { action: 'sync', reference: row.reference });
      alert(result.message || (result.success ? 'Synced.' : 'Could not sync this payment.'));
      if (result.success) await this.runReconciliation();
    },

    isWithinDateRange(dateStr) {
      if (!dateStr) return true;
      if (this.reportDateFrom && dateStr < this.reportDateFrom) return false;
      if (this.reportDateTo && dateStr > this.reportDateTo) return false;
      return true;
    },

    // Never divide by zero and call it a percentage -- returns 0 instead of NaN.
    safePct(part, whole) {
      if (!whole) return 0;
      return Math.round((Number(part) / Number(whole)) * 100);
    },

    getReportBookings() {
      const store = Alpine.store('admin');
      return store.bookings.filter(b => {
        if (this.reportFilterBranch !== 'all' && b.branchId !== this.reportFilterBranch) return false;
        return this.isWithinDateRange(b.date);
      });
    },

    getBookingStatusCounts() {
      const counts = {};
      this.getReportBookings().forEach(b => {
        counts[b.status] = (counts[b.status] || 0) + 1;
      });
      return counts;
    },

    getUniqueGuestCount() {
      return new Set(this.getReportBookings().map(b => b.clientPhone)).size;
    },

    getServicePopularity() {
      const byService = {};
      this.getReportBookings().forEach(b => {
        if (!byService[b.serviceName]) byService[b.serviceName] = { name: b.serviceName, count: 0, revenue: 0 };
        byService[b.serviceName].count += 1;
        if (b.status === 'Completed') byService[b.serviceName].revenue += Number(b.price || 0);
      });
      return Object.values(byService).sort((a, b) => b.count - a.count);
    },

    // Revenue/Sales report: computed from filtered bookings (date + branch
    // aware) rather than the dashboard's all-time branch.revenue totals, so
    // the Date Range filter actually changes the numbers shown here.
    getRevenueByBranch() {
      const store = Alpine.store('admin');
      const branches = this.reportFilterBranch === 'all' ? store.branches : store.branches.filter(b => b.id === this.reportFilterBranch);
      const rows = branches.map(branch => {
        const revenue = this.getReportBookings()
          .filter(b => b.branchId === branch.id && b.status === 'Completed')
          .reduce((sum, b) => sum + Number(b.price || 0), 0);
        return { id: branch.id, name: branch.name, revenue };
      });
      // Home services aren't tied to a branch: count every peso received on
      // them (reservation fee + remaining balance, home_service_payments).
      if (this.reportFilterBranch === 'all') {
        const homeRevenue = this.getReportBookings()
          .filter(b => b.isHomeService && b.status !== 'Cancelled')
          .reduce((sum, b) => sum + Number(b.amountPaid || 0), 0);
        rows.push({ id: 'home-service', name: 'Home Service (off-site)', revenue: homeRevenue });
      }
      return rows;
    },

    getReportTotalRevenue() {
      return this.getRevenueByBranch().reduce((sum, b) => sum + b.revenue, 0);
    },

    getReportMaxRevenue() {
      return Math.max(1, ...this.getRevenueByBranch().map(b => b.revenue));
    },

    // Staff Performance report: real, separate metrics only (see Step 3) --
    // no fabricated combined score. Attendance/completed/rating are
    // all-time totals (the API has no date-ranged per-staff aggregates),
    // so only the Branch filter narrows this list, not the date range.
    getReportStaff() {
      const store = Alpine.store('admin');
      if (this.reportFilterBranch === 'all') return store.staffList;
      return store.staffList.filter(s => s.branchId === this.reportFilterBranch);
    },

    getReportInventory() {
      const store = Alpine.store('admin');
      let list = store.inventory.filter(i => i.isActive);
      if (this.reportFilterBranch !== 'all') list = list.filter(i => i.branchId === this.reportFilterBranch);
      return list;
    },

    getReportFeedback() {
      const store = Alpine.store('admin');
      return store.feedback.filter(f => {
        if (this.reportFilterBranch !== 'all' && f.branchId !== this.reportFilterBranch) return false;
        return this.isWithinDateRange(f.date);
      });
    },

    exportReportToCSV() {
      const store = Alpine.store('admin');
      if (this.reportType === 'appointments') {
        const rows = this.getReportBookings().map(b => [b.id, store.getBranchName(b.branchId), b.clientName, b.serviceName, b.date, b.status]);
        store.exportRowsToCSV('appointments-report.csv', ['Ref ID', 'Branch', 'Client', 'Service', 'Date', 'Status'], rows);
      } else if (this.reportType === 'revenue') {
        const rows = this.getRevenueByBranch().map(b => [b.name, b.revenue]);
        store.exportRowsToCSV('revenue-report.csv', ['Branch', 'Completed Revenue (PHP)'], rows);
      } else if (this.reportType === 'staff') {
        const rows = this.getReportStaff().map(s => [s.name, store.getBranchName(s.branchId), s.role, s.completedCount, s.rating, s.attendance]);
        store.exportRowsToCSV('staff-performance-report.csv', ['Staff', 'Branch', 'Role', 'Completed Services', 'Rating', 'Attendance %'], rows);
      } else if (this.reportType === 'inventory') {
        const rows = this.getReportInventory().map(i => [i.name, store.getBranchName(i.branchId), i.stock, i.minQty, i.stock <= i.minQty ? 'Low Stock' : 'Good Stock']);
        store.exportRowsToCSV('inventory-report.csv', ['Item', 'Branch', 'Stock', 'Min Qty', 'Status'], rows);
      } else {
        const rows = this.getReportFeedback().map(f => [f.bookingReference || '', f.clientName, f.branchId ? store.getBranchName(f.branchId) : 'Off-Site', f.rating, f.date]);
        store.exportRowsToCSV('customer-feedback-report.csv', ['Booking Ref', 'Client', 'Branch', 'Rating', 'Date'], rows);
      }
    }
  };
}
