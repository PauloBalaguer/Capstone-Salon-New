function adminReportsApp() {
  return {
    reportType: 'appointments',
    reportFilterBranch: 'all',
    reportDateFrom: '',
    reportDateTo: '',

    collections: [],
    collectionsLoading: false,
    collectionsError: '',
    collectionsRequest: 0,
    init() {
      adminHighlightNav();
      for (const key of ['reportFilterBranch','reportDateFrom','reportDateTo']) this.$watch(key, () => {
        this.loadCollections();
        if (this.reportType === 'activity' || this.activityLogLoaded) this.loadActivityLog();
        if (this.reportType === 'payments' || this.paymentLogLoaded) this.loadPaymentLog();
        this.reconciliation = null;
      });
      this.loadCollections();
    },
    async loadCollections() {
      const request = ++this.collectionsRequest;
      this.collections = []; this.collectionsError = ''; this.collectionsLoading = true;
      if (this.reportDateFrom && this.reportDateTo && this.reportDateFrom > this.reportDateTo) {
        this.collectionsError = 'The start date must be on or before the end date.';
        this.collectionsLoading = false; return;
      }
      const params = new URLSearchParams({branch:this.reportFilterBranch,from:this.reportDateFrom,to:this.reportDateTo});
      try {
        const response = await fetch(ADMIN_API_BASE + 'getRevenueReport.php?' + params, {credentials:'same-origin'});
        const body = await response.json();
        if (request !== this.collectionsRequest) return;
        if (!body.success) throw new Error(body.message || 'Could not load collected payments.');
        this.collections = body.collections;
      } catch (error) { if (request === this.collectionsRequest) this.collectionsError = error.message; }
      finally { if (request === this.collectionsRequest) this.collectionsLoading = false; }
    },

    // Online Payment Log (backend/admin/getPaymentLog.php), loaded the first
    // time the report is opened and on each reference search.
    paymentLog: [],
    paymentLogLoaded: false,
    paymentLogRequest: 0,
    paymentLogSearch: '',
    paymentLogError: '',
    async loadPaymentLog() {
      const request = ++this.paymentLogRequest;
      this.paymentLog = []; this.paymentLogLoaded = false;
      this.paymentLogError = '';
      try {
        const params = new URLSearchParams({ reference: this.paymentLogSearch.trim(),branch:this.reportFilterBranch,from:this.reportDateFrom,to:this.reportDateTo });
        const response = await fetch(ADMIN_API_BASE + 'getPaymentLog.php?' + params, { credentials: 'same-origin' });
        const body = await response.json();
        if (request !== this.paymentLogRequest) return;
        if (!body.success) throw new Error(body.message || 'Could not load the payment log.');
        this.paymentLog = body.events;
      } catch (error) {
        if (request === this.paymentLogRequest) this.paymentLogError = error.message || 'Could not load the payment log.';
      } finally {
        if (request === this.paymentLogRequest) this.paymentLogLoaded = true;
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
    matchesReportBranch(reference) {
      if (this.reportFilterBranch === 'all') return true;
      const booking = Alpine.store('admin').bookings.find(b => b.id === reference);
      return booking?.branchId === this.reportFilterBranch;
    },
    getFilteredPaymentLog() {
      return this.paymentLog.filter(r => this.isWithinDateRange(r.createdAt) && this.matchesReportBranch(r.reference));
    },

    // Activity Log (backend/admin/getAuditLog.php): who did what, when.
    activityLog: [],
    activityLogLoaded: false,
    activityLogRequest: 0,
    activitySearch: '',
    activityRole: '',
    activityError: '',
    activityExpandedId: null,
    async loadActivityLog() {
      const request = ++this.activityLogRequest;
      this.activityLog = []; this.activityLogLoaded = false;
      this.activityError = '';
      try {
        const params = new URLSearchParams({ q: this.activitySearch.trim(), role: this.activityRole });
        if (this.reportDateFrom) params.set('from', this.reportDateFrom);
        if (this.reportDateTo) params.set('to', this.reportDateTo);
        const response = await fetch(ADMIN_API_BASE + 'getAuditLog.php?' + params, { credentials: 'same-origin' });
        const body = await response.json();
        if (request !== this.activityLogRequest) return;
        if (!body.success) throw new Error(body.message || 'Could not load the activity log.');
        this.activityLog = body.entries;
      } catch (error) {
        if (request === this.activityLogRequest) this.activityError = error.message || 'Could not load the activity log.';
      } finally {
        if (request === this.activityLogRequest) this.activityLogLoaded = true;
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
      return this.reconciliation.rows.filter(r => (!this.reconcileIssuesOnly || r.severity !== 'ok') && this.matchesReportBranch(r.reference));
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
      if (!dateStr) return !this.reportDateFrom && !this.reportDateTo;
      dateStr = String(dateStr).slice(0,10);
      if (this.reportDateFrom && this.reportDateTo && this.reportDateFrom > this.reportDateTo) return false;
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
      const collected = new Map();
      this.collections.forEach(row => collected.set(row.reference, (collected.get(row.reference) || 0) + Number(row.amount || 0)));
      this.getReportBookings().forEach(b => {
        if (!byService[b.serviceName]) byService[b.serviceName] = { name: b.serviceName, count: 0, revenue: 0 };
        byService[b.serviceName].count += 1;
        byService[b.serviceName].revenue += collected.get(b.id) || 0;
      });
      return Object.values(byService).sort((a, b) => b.count - a.count);
    },

    // Revenue/Sales report: computed from filtered bookings (date + branch
    // aware) rather than the dashboard's all-time branch.revenue totals, so
    // the Date Range filter actually changes the numbers shown here.
    getRevenueByBranch() {
      const groups = new Map();
      this.collections.forEach(row => {
        if (!groups.has(row.branchId)) groups.set(row.branchId, {id:row.branchId,name:row.branchName,revenue:0});
        groups.get(row.branchId).revenue += Number(row.amount || 0);
      });
      return [...groups.values()].map(row => ({...row,revenue:Math.round(row.revenue*100)/100}));
    },

    getReportTotalRevenue() {
      return this.getRevenueByBranch().reduce((sum, b) => sum + b.revenue, 0);
    },

    getReportMaxRevenue() {
      return Math.max(1, ...this.getRevenueByBranch().map(b => Math.abs(b.revenue)));
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
        if (this.collectionsLoading || this.collectionsError) return;
        const rows = this.collections.map(r => [r.reference,r.branchName,r.collectedAt || 'Not recorded (legacy)',r.kind,r.amount]);
        store.exportRowsToCSV('revenue-report.csv', ['Booking Reference','Branch','Collection Date','Transaction','Collected Amount (PHP)'], rows);
      } else if (this.reportType === 'staff') {
        const rows = this.getReportStaff().map(s => [s.name, store.getBranchName(s.branchId), s.role, s.completedCount, s.rating, s.attendance]);
        store.exportRowsToCSV('staff-performance-report.csv', ['Staff', 'Branch', 'Role', 'Completed Services', 'Rating', 'Attendance %'], rows);
      } else if (this.reportType === 'inventory') {
        const rows = this.getReportInventory().map(i => [i.name, store.getBranchName(i.branchId), i.stock, i.minQty, i.stock <= i.minQty ? 'Low Stock' : 'Good Stock']);
        store.exportRowsToCSV('inventory-report.csv', ['Item', 'Branch', 'Stock', 'Min Qty', 'Status'], rows);
      } else if (this.reportType === 'payments') {
        if (!this.paymentLogLoaded || this.paymentLogError) return;
        const rows = this.getFilteredPaymentLog().map(r => [r.reference,r.createdAt,r.event,r.amount,r.status]);
        store.exportRowsToCSV('payment-log.csv',['Booking Reference','Date','Event','Amount','Status'],rows);
      } else if (this.reportType === 'activity') {
        if (!this.activityLogLoaded || this.activityError) return;
        const rows = this.activityLog.map(r => [r.createdAt,r.actor,r.role,r.label,r.reference,r.outcome]);
        store.exportRowsToCSV('activity-log.csv',['Date','Actor','Role','Activity','Reference','Outcome'],rows);
      } else if (this.reportType === 'reconciliation') {
        if (!this.reconciliation || this.reconciling || this.reconcileError) return;
        const rows = this.reconcileRows().map(r => [r.reference,r.paymentId,r.paidAt,r.amount,r.refunded,r.severity,r.issue || r.message || '']);
        store.exportRowsToCSV('payment-reconciliation.csv',['Reference','Payment','Date','Amount','Refunded','Severity','Details'],rows);
      } else {
        const rows = this.getReportFeedback().map(f => [f.bookingReference || '', f.clientName, f.branchId ? store.getBranchName(f.branchId) : 'Off-Site', f.rating, f.date]);
        store.exportRowsToCSV('customer-feedback-report.csv', ['Booking Ref', 'Client', 'Branch', 'Rating', 'Date'], rows);
      }
    }
  };
}
