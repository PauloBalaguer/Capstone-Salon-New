function staffAppointmentsApp() {
  return {
    filterTab: 'today',
    searchQuery: '',
    expandedId: null,
    supplyForm: { itemId: '', quantity: 1 },
    supplySearch: '',
    supplyOpen: false,
    page: 1,
    pageSize: 10,

    init() {
      staffHighlightNav();
      staffInitMobileNav();
      this.$watch('filterTab', () => { this.page = 1; });
      this.$watch('searchQuery', () => { this.page = 1; });
    },

    totalPages() {
      return Math.max(1, Math.ceil(this.filteredAppointments().length / this.pageSize));
    },

    // Clamped because a refresh can shrink the list while on a later page.
    currentPage() {
      return Math.min(this.page, this.totalPages());
    },

    pagedAppointments() {
      const start = (this.currentPage() - 1) * this.pageSize;
      return this.filteredAppointments().slice(start, start + this.pageSize);
    },

    pageLabel() {
      const total = this.filteredAppointments().length;
      const first = (this.currentPage() - 1) * this.pageSize + 1;
      return `Showing ${first}-${Math.min(first + this.pageSize - 1, total)} of ${total}`;
    },

    toggleExpand(id) {
      this.expandedId = this.expandedId === id ? null : id;
      this.supplyForm = { itemId: '', quantity: 1 };
      this.supplySearch = '';
      this.supplyOpen = false;
    },

    filteredAppointments() {
      const store = Alpine.store('staff');
      const today = store.todayISO();
      const q = this.searchQuery.trim().toLowerCase();
      // A search looks through every booking, whichever tab is selected.
      let list;
      switch (q ? 'all' : this.filterTab) {
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
      if (q) {
        list = list.filter(a => a.clientName.toLowerCase().includes(q) || a.id.toLowerCase().includes(q));
      }

      // Today and Upcoming read earliest first; history reads newest first.
      const direction = !q && ['today', 'upcoming'].includes(this.filterTab) ? 1 : -1;
      return [...list].sort((a, b) => direction * store.appointmentSortKey(a).localeCompare(store.appointmentSortKey(b)));
    },

    supplyOptions() {
      const q = this.supplySearch.trim().toLowerCase();
      const items = Alpine.store('staff').inventory;
      return q ? items.filter(item => item.name.toLowerCase().includes(q)) : items;
    },

    selectedSupply() {
      return Alpine.store('staff').inventory.find(item => item.id === this.supplyForm.itemId) || null;
    },

    // Same low-stock rule as the dashboard's Low Stock Alerts card.
    supplyIsLow(item) {
      return item.stock > 0 && item.stock <= item.minQty;
    },

    supplyStockLabel(item) {
      if (item.stock <= 0) return 'Out of stock';
      return this.supplyIsLow(item) ? `${item.stock} left, low stock` : `${item.stock} in stock`;
    },

    chooseSupply(item) {
      this.supplyForm.itemId = item.id;
      this.supplySearch = item.name;
      this.supplyOpen = false;
    },

    async logSupplyForAppointment(referenceCode) {
      const { itemId, quantity } = this.supplyForm;
      if (!itemId || !quantity) return;
      const res = await Alpine.store('staff').logSupplyUsage(itemId, quantity, referenceCode, '');
      if (res.success) {
        this.supplyForm = { itemId: '', quantity: 1 };
        this.supplySearch = '';
      }
    }
  };
}
