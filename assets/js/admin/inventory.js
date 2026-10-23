function adminDefaultItemForm() {
  return { name: '', branchId: '', stock: 0, minQty: 5, costPrice: 0, salePrice: null, supplier: '' };
}

/* Fetches backend/admin/getInventoryHistory.php (every stock movement from
   inventory_adjustments) with the given filters. */
async function adminFetchInventoryHistory(params) {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '' && value !== 'all') query.append(key, value);
  });
  const response = await fetch('../../backend/admin/getInventoryHistory.php?' + query.toString(), { credentials: 'same-origin' });
  return response.json();
}

function adminInventoryApp() {
  return {
    inventoryFilterBranch: 'all',
    // 'active' = the stock registry, 'archived' = archived items (restorable),
    // 'history' = the usage & movement log.
    inventoryView: 'active',

    openItemModal: false,
    editingItemId: null,
    itemForm: adminDefaultItemForm(),

    // Usage & History tab
    historyKind: 'usage',
    historySearch: '',
    historyFrom: '',
    historyTo: '',
    historyRows: [],
    historyLoading: false,
    historyError: '',
    historyRequest: 0,

    // One item's history (the "History" button)
    itemHistoryFor: null,
    itemHistoryRows: [],
    itemHistoryLoading: false,

    init() {
      adminHighlightNav();
      this.$watch('inventoryFilterBranch', () => { if (this.inventoryView === 'history') this.loadHistory(); });
    },

    getFilteredInventory() {
      const store = Alpine.store('admin');
      let list = store.inventory;
      if (this.inventoryFilterBranch !== 'all') list = list.filter(i => i.branchId === this.inventoryFilterBranch);
      return list.filter(i => this.inventoryView === 'archived' ? !i.isActive : i.isActive);
    },

    archivedCount() {
      return Alpine.store('admin').inventory.filter(i => !i.isActive).length;
    },

    setInventoryView(view) {
      this.inventoryView = view;
      if (view === 'history') this.loadHistory();
    },

    async loadHistory() {
      this.historyLoading = true;
      this.historyError = '';
      const requestId = ++this.historyRequest;
      try {
        const result = await adminFetchInventoryHistory({
          branchId: this.inventoryFilterBranch, kind: this.historyKind, q: this.historySearch.trim(),
          from: this.historyFrom, to: this.historyTo
        });
        if (requestId !== this.historyRequest) return; // superseded by a newer filter change
        if (!result.success) { this.historyError = result.message || 'Could not load history.'; this.historyRows = []; return; }
        this.historyRows = result.history;
      } catch (e) {
        if (requestId === this.historyRequest) this.historyError = 'A network error occurred while loading history.';
      } finally {
        if (requestId === this.historyRequest) this.historyLoading = false;
      }
    },

    historyTotalUsed() {
      return this.historyRows.filter(r => r.kind === 'usage').reduce((sum, r) => sum + r.quantity, 0);
    },

    historyBadgeClass(row) {
      return {
        usage: 'admin-badge admin-badge--info',
        sale: 'admin-badge admin-badge--success',
        adjustment: row.change >= 0 ? 'admin-badge admin-badge--confirmed' : 'admin-badge admin-badge--pending',
        item: row.type === 'deactivate' ? 'admin-badge admin-badge--neutral' : 'admin-badge admin-badge--confirmed'
      }[row.kind] || 'admin-badge admin-badge--neutral';
    },

    async openItemHistory(item) {
      this.itemHistoryFor = item;
      this.itemHistoryRows = [];
      this.itemHistoryLoading = true;
      try {
        const result = await adminFetchInventoryHistory({ itemId: item.id });
        if (this.itemHistoryFor !== item) return;
        this.itemHistoryRows = result.success ? result.history : [];
      } finally {
        if (this.itemHistoryFor === item) this.itemHistoryLoading = false;
      }
    },

    openAddItemModal() {
      this.editingItemId = null;
      this.itemForm = adminDefaultItemForm();
      this.openItemModal = true;
    },

    openEditItem(item) {
      this.editingItemId = item.id;
      this.itemForm = { ...item, supplier: item.supplier || '' };
      this.openItemModal = true;
    },

    async saveItem() {
      const store = Alpine.store('admin');
      const result = await store.post('saveInventoryItem.php', {
        id: this.editingItemId || '',
        name: this.itemForm.name,
        branchId: this.itemForm.branchId,
        stock: this.itemForm.stock,
        minQty: this.itemForm.minQty,
        costPrice: this.itemForm.costPrice,
        salePrice: this.itemForm.salePrice === null || this.itemForm.salePrice === '' ? '' : this.itemForm.salePrice,
        supplier: this.itemForm.supplier
      });
      if (!result.success) {
        alert(result.message || 'Failed to save inventory item.');
        return;
      }
      await store.refresh();
      this.openItemModal = false;
    },

    async incrementStock(itemId, delta) {
      const store = Alpine.store('admin');
      const result = await store.post('adjustStock.php', { id: itemId, delta });
      if (!result.success) {
        alert(result.message || 'Failed to adjust stock.');
        return;
      }
      await store.refresh();
    },

    // Archive = hide from active use (staff supply logs, checkout, stock
    // alerts) while keeping all its history; Restore brings it back.
    async toggleItemActive(item) {
      const goingActive = !item.isActive;
      if (!goingActive && !confirm(`Archive "${item.name}"? It will be hidden from staff, checkout and stock alerts, but its history is kept. You can restore it from the Archived tab.`)) return;
      const store = Alpine.store('admin');
      const result = await store.post('toggleInventoryItemActive.php', { id: item.id, active: goingActive ? 1 : 0 });
      if (!result.success) {
        alert(result.message || 'Failed to update item status.');
        return;
      }
      await store.refresh();
    },

  };
}
