function adminCustomersApp() {
  return {
    customerSearchQuery: '',
    customerFilterAccountType: 'all',
    openHistoryModalFlag: false,
    selectedCustomer: null,

    init() {
      adminHighlightNav();
    },

    getFilteredCustomers() {
      const store = Alpine.store('admin');
      let list = store.customerDirectory;
      if (this.customerFilterAccountType !== 'all') list = list.filter(c => c.accountType === this.customerFilterAccountType);
      if (this.customerSearchQuery.trim()) {
        const q = this.customerSearchQuery.trim().toLowerCase();
        list = list.filter(c => c.name.toLowerCase().includes(q) || c.phone.includes(q));
      }
      return list;
    },

    getTotalLifetimeValue() {
      return Alpine.store('admin').customerDirectory.reduce((sum, c) => sum + c.totalSpent, 0);
    },

    openHistoryModal(customer) {
      this.selectedCustomer = customer;
      this.openHistoryModalFlag = true;
    },

    getCustomerBookings() {
      const store = Alpine.store('admin');
      if (!this.selectedCustomer) return [];
      return store.bookings
        .filter(b => b.clientPhone === this.selectedCustomer.phone)
        .sort((a, b) => (a.date < b.date ? 1 : -1));
    }
  };
}
