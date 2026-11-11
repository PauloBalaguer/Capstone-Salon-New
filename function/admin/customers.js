function adminCustomersApp() {
  return {
    customerSearchQuery: '',
    customerFilterAccountType: ['Registered', 'Guest'].includes(AdminUI.param('type')) ? AdminUI.param('type') : 'all',
    openHistoryModalFlag: false,
    historyTab: 'all',
    selectedCustomer: null,

    init() {
      adminHighlightNav();
    },

    countType(type) {
      return Alpine.store('admin').customerDirectory.filter(c => c.accountType === type).length;
    },

    getFilteredCustomers() {
      const store = Alpine.store('admin');
      let list = store.customerDirectory;
      if (this.customerFilterAccountType !== 'all') list = list.filter(c => c.accountType === this.customerFilterAccountType);
      if (this.customerSearchQuery.trim()) {
        const q = this.customerSearchQuery.trim().toLowerCase();
        list = list.filter(c => [c.name, c.phone, c.email].some(v => String(v || '').toLowerCase().includes(q)));
      }
      return list;
    },

    getTotalLifetimeValue() {
      return Alpine.store('admin').customerDirectory.reduce((sum, c) => sum + c.totalSpent, 0);
    },

    openHistoryModal(customer) {
      this.selectedCustomer = customer;
      this.historyTab = 'all';
      this.openHistoryModalFlag = true;
    },

    // Matches bookings by the customer's database id (customers.id), so two
    // different people are never merged; falls back to the phone number only
    // if an older API response has no id.
    getCustomerBookings() {
      const store = Alpine.store('admin');
      const customer = this.selectedCustomer;
      if (!customer) return [];
      return store.bookings
        .filter(b => customer.id && b.customerId ? b.customerId === customer.id : b.clientPhone === customer.phone)
        .sort((a, b) => String(b.date || '').localeCompare(String(a.date || '')) || String(b.time || '').localeCompare(String(a.time || '')));
    },

    /* History: upcoming / completed / cancelled buckets, summary and rows. */
    historyKind(b) {
      if (['Completed', 'Reviewed'].includes(b.status)) return 'completed';
      if (['Cancelled', 'No-Show', 'Rejected'].includes(b.status)) return 'cancelled';
      return 'upcoming';
    },
    historyCounts() {
      const list = this.getCustomerBookings();
      const c = { all: list.length, upcoming: 0, completed: 0, cancelled: 0 };
      list.forEach(b => { c[this.historyKind(b)]++; });
      return c;
    },
    historyRows() {
      const list = this.getCustomerBookings();
      return this.historyTab === 'all' ? list : list.filter(b => this.historyKind(b) === this.historyTab);
    },
    /* Month heading only when the month changes from the row above. */
    historyMonth(rows, i) {
      const m = b => String(b.date || '').slice(0, 7);
      if (i > 0 && m(rows[i]) === m(rows[i - 1])) return '';
      const d = new Date((rows[i].date || '') + 'T00:00:00');
      return isNaN(d) ? 'No date' : d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    },
    historyDay(b) {
      const d = new Date((b.date || '') + 'T00:00:00');
      return isNaN(d) ? '—' : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    },
    historyBranch(b) {
      if (b.isHomeService) return 'Home service';
      return ({ daraga: 'Daraga Main', yashano: 'Yashano Mall', cabangan: 'Cabangan' })[b.branchId] || Alpine.store('admin').getBranchName(b.branchId);
    },
    historyPrice(b) {
      const store = Alpine.store('admin');
      return b.isHomeService ? (b.quotePrice ? store.formatCurrency(b.quotePrice) : 'Quote pending') : store.formatCurrency(b.price);
    }
  };
}
