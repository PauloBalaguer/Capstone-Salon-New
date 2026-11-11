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
    inventoryFilterBranch: AdminUI.param('branch') || 'all',
    productSearch: '',
    // 'all' | 'low' (at/below minimum, incl. out) | 'out' | 'ok'. Dashboard links with ?stock=low.
    stockFilter: AdminUI.param('stock') || 'all',

    // Adjust Stock modal
    adjustItem: null,
    adjustDelta: '',
    adjustReasonPreset: 'Restock / delivery received',
    adjustReasonText: '',
    adjustSaving: false,
    adjustError: '',
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

    stockStatus(item) {
      if (item.stock <= 0) return 'out';
      return item.stock <= item.minQty ? 'low' : 'ok';
    },

    branchItems() {
      const list = Alpine.store('admin').inventory;
      return this.inventoryFilterBranch === 'all' ? list : list.filter(i => i.branchId === this.inventoryFilterBranch);
    },

    // Product search, branch and stock status all apply together.
    getFilteredInventory() {
      const q = this.productSearch.trim().toLowerCase();
      return this.branchItems().filter(i => {
        if (this.inventoryView === 'archived' ? i.isActive : !i.isActive) return false;
        if (q && ![i.name, i.category, i.supplier].some(v => String(v || '').toLowerCase().includes(q))) return false;
        if (this.inventoryView === 'active' && this.stockFilter !== 'all') {
          const status = this.stockStatus(i);
          if (this.stockFilter === 'low' && status === 'ok') return false;
          if (this.stockFilter === 'out' && status !== 'out') return false;
          if (this.stockFilter === 'ok' && status !== 'ok') return false;
        }
        return true;
      }).sort((a, b) => a.name.localeCompare(b.name));
    },

    openAdjust(item) {
      this.adjustItem = item;
      this.adjustDelta = '';
      this.adjustReasonPreset = 'Restock / delivery received';
      this.adjustReasonText = '';
      this.adjustError = '';
    },
    adjustNewQty() {
      const delta = Number(this.adjustDelta);
      if (!this.adjustItem || this.adjustDelta === '' || !Number.isInteger(delta)) return NaN;
      return this.adjustItem.stock + delta;
    },
    adjustReason() {
      return this.adjustReasonPreset === 'Other' ? this.adjustReasonText.trim() : this.adjustReasonPreset;
    },
    canSaveAdjustment() {
      const delta = Number(this.adjustDelta);
      return this.adjustDelta !== '' && Number.isInteger(delta) && delta !== 0 && this.adjustNewQty() >= 0 && this.adjustReason() !== '';
    },
    // Saved through backend/admin/adjustStock.php, which records the change
    // (with this reason) in inventory_adjustments and refuses negative stock.
    async saveAdjustment() {
      if (!this.canSaveAdjustment()) {
        this.adjustError = this.adjustNewQty() < 0 ? 'Stock cannot go below zero.' : 'Enter a whole-number change and a reason.';
        return;
      }
      const store = Alpine.store('admin');
      this.adjustSaving = true;
      this.adjustError = '';
      try {
        const result = await store.post('adjustStock.php', { id: this.adjustItem.id, delta: Number(this.adjustDelta), reason: this.adjustReason() });
        if (!result.success) { this.adjustError = result.message || 'Failed to adjust stock.'; return; }
        LMToast.show(`Stock updated: ${this.adjustItem.name} now has ${result.stock} units.`, 'success');
        this.adjustItem = null;
        await store.refresh();
      } catch (e) {
        this.adjustError = 'A network error occurred. Check the item history before trying again.';
      } finally {
        this.adjustSaving = false;
      }
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
        LMToast.show(result.message || 'Failed to save inventory item.', 'error');
        return;
      }
      await store.refresh();
      this.openItemModal = false;
    },

    async incrementStock(itemId, delta) {
      const store = Alpine.store('admin');
      const result = await store.post('adjustStock.php', { id: itemId, delta });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to adjust stock.', 'error');
        return;
      }
      await store.refresh();
    },

    // Archive = hide from active use (staff supply logs, checkout, stock
    // alerts) while keeping all its history; Restore brings it back.
    /* Branch cards (same names as the guest booking page). */
    branchCards() {
      const store = Alpine.store('admin');
      const items = (store.inventory || []).filter(i => i.isActive);
      const pick = id => items.filter(i => id === 'all' || i.branchId === id);
      const low = list => list.filter(i => this.stockStatus(i) !== 'ok').length;
      return [
        { id: 'all', title: 'All branches', sub: 'Every location', count: pick('all').length, low: low(pick('all')) },
        ...(store.branches || []).map(b => ({ id: b.id, title: this.shortBranch(b.id), sub: (this.BRANCH_SUB[b.id] || b.type || ''), count: pick(b.id).length, low: low(pick(b.id)) })),
      ];
    },
    BRANCH_SUB: { daraga: 'Salon & Make-Up', yashano: 'Skin & Brows', cabangan: 'Lash & Brows' },
    shortBranch(id) {
      return ({ daraga: 'Daraga Main', yashano: 'Yashano Mall', cabangan: 'Cabangan' })[id] || Alpine.store('admin').getBranchName(id);
    },
    /* Bar fill: stock against twice the minimum (full bar = comfortably stocked). */
    stockBarPct(item) {
      const target = Math.max(1, Number(item.minQty || 0) * 2);
      return Math.max(item.stock > 0 ? 4 : 0, Math.min(100, Math.round((Number(item.stock || 0) / target) * 100)));
    },
    async archiveFromEdit() {
      const item = (Alpine.store('admin').inventory || []).find(i => i.id === this.editingItemId);
      if (!item) return;
      this.openItemModal = false;
      await this.toggleItemActive(item);
    },

    async toggleItemActive(item) {
      const goingActive = !item.isActive;
      if (!goingActive && !(await LMConfirm({ title: `Archive "${item.name}"?`, message: 'It will be hidden from staff, checkout and stock alerts, but its history is kept. You can restore it from the Archived tab.', confirmLabel: 'Archive' }))) return;
      const store = Alpine.store('admin');
      const result = await store.post('toggleInventoryItemActive.php', { id: item.id, active: goingActive ? 1 : 0 });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to update item status.', 'error');
        return;
      }
      await store.refresh();
    },

  };
}
