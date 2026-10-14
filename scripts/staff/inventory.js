function staffInventoryApp() {
  return {
    searchQuery: '',
    onlyLowStock: false,
    expandedId: null,
    usageForm: { referenceCode: '', quantity: '', note: '' },

    init() {
      staffHighlightNav();
      staffInitMobileNav();
    },

    toggleExpand(id) {
      this.expandedId = this.expandedId === id ? null : id;
      this.usageForm = { referenceCode: '', quantity: '', note: '' };
    },

    // Only appointments assigned to this staff member can be picked, and
    // only ones actually in progress (or done) -- matches what "started" on
    // My Assignments actually means for logging supplies used.
    assignableAppointments() {
      return Alpine.store('staff').appointments
        .filter(a => ['In Progress', 'Completed'].includes(a.status))
        .sort((a, b) => (b.date + b.time).localeCompare(a.date + a.time));
    },

    filteredItems() {
      let list = Alpine.store('staff').inventory;
      if (this.onlyLowStock) list = list.filter(i => i.stock <= i.minQty);

      const q = this.searchQuery.trim().toLowerCase();
      if (q) {
        list = list.filter(i => i.name.toLowerCase().includes(q) || (i.sku || '').toLowerCase().includes(q));
      }
      return list;
    },

    async logUsage(itemId) {
      const { referenceCode, quantity, note } = this.usageForm;
      if (!quantity || !referenceCode) return;
      const res = await Alpine.store('staff').logSupplyUsage(itemId, quantity, referenceCode, note);
      if (res.success) {
        this.usageForm = { referenceCode: '', quantity: '', note: '' };
        this.expandedId = null;
      }
    }
  };
}
