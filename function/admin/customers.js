function adminCustomersApp() {
  return {
    customerSearchQuery: '',
    customerFilterAccountType: ['Registered', 'Guest'].includes(AdminUI.param('type')) ? AdminUI.param('type') : 'all',
    openHistoryModalFlag: false,
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
        .sort((a, b) => String(b.date || '').localeCompare(String(a.date || '')));
    }
  };
}
