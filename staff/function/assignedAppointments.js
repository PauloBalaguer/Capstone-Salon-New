function staffAppointmentsApp() {
  return {
    filterTab: 'today',
    searchQuery: '',
    expandedId: null,
    supplyForm: { itemId: '', quantity: 1 },

    init() {
      staffHighlightNav();
      staffInitMobileNav();
    },

    toggleExpand(id) {
      this.expandedId = this.expandedId === id ? null : id;
      this.supplyForm = { itemId: '', quantity: 1 };
    },

    filteredAppointments() {
      const store = Alpine.store('staff');
      const today = store.todayISO();
      let list;
      switch (this.filterTab) {
        case 'today':
          list = store.appointments.filter(a => a.date === today);
          break;
        case 'upcoming':
          list = store.appointments.filter(a => a.date > today && !['Cancelled', 'Completed'].includes(a.status));
          break;
        case 'completed':
          list = store.appointments.filter(a => a.status === 'Completed' || a.status === 'Reviewed');
          break;
        default:
          list = store.appointments;
      }

      const q = this.searchQuery.trim().toLowerCase();
      if (q) {
        list = list.filter(a => a.clientName.toLowerCase().includes(q) || a.id.toLowerCase().includes(q));
      }

      return [...list].sort((a, b) => (a.date + a.time).localeCompare(b.date + b.time));
    },

    async logSupplyForAppointment(referenceCode) {
      const { itemId, quantity } = this.supplyForm;
      if (!itemId || !quantity) return;
      const res = await Alpine.store('staff').logSupplyUsage(itemId, quantity, referenceCode, '');
      if (res.success) this.supplyForm = { itemId: '', quantity: 1 };
    }
  };
}
