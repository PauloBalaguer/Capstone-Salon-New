function adminDefaultCashierForm() {
  return {
    name: '',
    branchId: '',
    email: ''
  };
}

function adminSettingsApp() {
  return {
    profileSaved: false,

    // Branch settings: one branch at a time, edited on a local copy so the
    // 30-second live refresh never overwrites what is being typed.
    branchKey: AdminUI.param('branch') || '',
    branchForm: null,
    branchFormOriginal: '',
    savingBranch: false,
    revertingBranch: false,

    // Cashier account management -- moved here from the Staff & Performance
    // Hub (Step 3): account/access administration belongs under Platform
    // Settings, not mixed into staff performance tracking.
    openCashierModal: false,
    editingCashierId: null,
    cashierForm: adminDefaultCashierForm(),
    savingCashier: false,

    init() {
      adminHighlightNav();
      const pick = (branches) => {
        if (!branches.length) return;
        if (!branches.some(b => b.id === this.branchKey)) this.branchKey = branches[0].id;
        if (!this.branchForm || (this.branchForm.id !== this.branchKey) || !this.branchDirty()) this.loadBranchForm();
      };
      pick(Alpine.store('admin').branches);
      this.$watch('$store.admin.branches', pick);
      this.$watch('branchKey', async (next, previous) => {
        if (this.revertingBranch) { this.revertingBranch = false; return; }
        if (this.branchForm && this.branchForm.id === previous && this.branchDirty()
            && !(await LMConfirm({ title: 'Discard unsaved changes?', message: `Your changes to ${this.branchForm.name} have not been saved.`, confirmLabel: 'Discard', cancelLabel: 'Keep editing' }))) {
          this.revertingBranch = true;
          this.branchKey = previous;
          return;
        }
        this.loadBranchForm();
      });
    },

    currentBranch() {
      return Alpine.store('admin').getBranch(this.branchKey);
    },

    loadBranchForm() {
      const b = this.currentBranch();
      if (!b) { this.branchForm = null; return; }
      this.branchForm = {
        id: b.id, name: b.name, location: b.location || '', type: b.type || '',
        openingTime: b.openingTime || '', closingTime: b.closingTime || '',
        sundayOpeningTime: b.sundayOpeningTime || '', sundayClosingTime: b.sundayClosingTime || '',
        slotLimit: b.slotLimit
      };
      this.branchFormOriginal = JSON.stringify(this.branchForm);
    },

    branchDirty() {
      return !!this.branchForm && JSON.stringify(this.branchForm) !== this.branchFormOriginal;
    },

    openAddCashierModal() {
      this.editingCashierId = null;
      this.cashierForm = adminDefaultCashierForm();
      this.openCashierModal = true;
    },

    openEditCashier(cashier) {
      this.editingCashierId = cashier.id;
      this.cashierForm = { name: cashier.name, branchId: cashier.branchId, email: '' };
      this.openCashierModal = true;
    },

    async saveCashier() {
      const store = Alpine.store('admin');
      this.savingCashier = true;
      const result = await store.post('saveCashier.php', {
        id: this.editingCashierId || '',
        name: this.cashierForm.name,
        branchId: this.cashierForm.branchId,
        email: this.cashierForm.email
      });
      this.savingCashier = false;
      if (!result.success) {
        LMToast.show(result.message || 'Failed to save cashier account.', 'error');
        return;
      }
      await store.refresh();
      this.openCashierModal = false;
    },

    async removeCashierMember(cashierId) {
      if (!(await LMConfirm({ title: 'Remove this cashier?', message: 'They will no longer be able to log in.', confirmLabel: 'Remove Cashier' }))) return;
      const store = Alpine.store('admin');
      const result = await store.post('removeCashier.php', { id: cashierId });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to remove cashier account.', 'error');
        return;
      }
      await store.refresh();
    },

    // backend/admin/saveBranch.php updates exactly the branch whose key is sent.
    async saveBranch() {
      const store = Alpine.store('admin');
      const branch = this.branchForm;
      if (!branch || this.savingBranch) return;
      if ((branch.sundayOpeningTime === '') !== (branch.sundayClosingTime === '')) {
        LMToast.show('Enter both Sunday times, or leave both blank.', 'error');
        return;
      }
      if (!Number.isInteger(Number(branch.slotLimit)) || Number(branch.slotLimit) < 1) {
        LMToast.show('Booking capacity must be a whole number of at least 1.', 'error');
        return;
      }
      this.savingBranch = true;
      let result;
      try {
        result = await store.post('saveBranch.php', {
        id: branch.id,
        location: branch.location,
        type: branch.type,
        openingTime: branch.openingTime,
        closingTime: branch.closingTime,
        sundayOpeningTime: branch.sundayOpeningTime || '',
        sundayClosingTime: branch.sundayClosingTime || '',
        slotLimit: branch.slotLimit
        });
      } catch (e) {
        result = { success: false, message: 'A network error occurred. Please try again.' };
      }
      this.savingBranch = false;
      if (!result.success) {
        LMToast.show(result.message || 'Failed to update branch info.', 'error');
        return;
      }
      LMToast.show(`${branch.name} settings saved.`, 'success');
      this.branchFormOriginal = JSON.stringify(this.branchForm);
      await store.refresh();
      this.loadBranchForm();
    },

    async saveEventDuration(row) {
      const store = Alpine.store('admin');
      const result = await store.post('saveEventDuration.php', {
        eventType: row.eventType,
        travelMinutes: row.travelMinutes ?? '',
        setupMinutes: row.setupMinutes ?? '',
        serviceMinutes: row.serviceMinutes ?? '',
        durationsConfirmed: row.durationsConfirmed
      });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to save event durations.', 'error');
        return;
      }
      LMToast.show(result.message || 'Event durations saved.', 'success');
      await store.refresh();
    },

    async saveProfile() {
      const store = Alpine.store('admin');
      const result = await store.post('saveAdminProfile.php', {
        name: store.adminProfile.name,
        email: store.adminProfile.email,
        bookingAlerts: store.notificationPrefs.bookingAlerts,
        inventoryAlerts: store.notificationPrefs.inventoryAlerts,
        orderAlerts: store.notificationPrefs.orderAlerts,
        marketingAlerts: store.notificationPrefs.marketingAlerts,
        paymentAlerts: store.notificationPrefs.paymentAlerts,
        homeServiceAlerts: store.notificationPrefs.homeServiceAlerts,
        staffConflictAlerts: store.notificationPrefs.staffConflictAlerts
      });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to update profile.', 'error');
        return;
      }
      await store.refresh();
      this.profileSaved = true;
      setTimeout(() => { this.profileSaved = false; }, 2500);
    },

    async saveNotificationPrefs() {
      const store = Alpine.store('admin');
      const result = await store.post('saveAdminProfile.php', {
        name: store.adminProfile.name,
        email: store.adminProfile.email,
        bookingAlerts: store.notificationPrefs.bookingAlerts,
        inventoryAlerts: store.notificationPrefs.inventoryAlerts,
        orderAlerts: store.notificationPrefs.orderAlerts,
        marketingAlerts: store.notificationPrefs.marketingAlerts,
        paymentAlerts: store.notificationPrefs.paymentAlerts,
        homeServiceAlerts: store.notificationPrefs.homeServiceAlerts,
        staffConflictAlerts: store.notificationPrefs.staffConflictAlerts
      });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to update notification preferences.', 'error');
      }
    }
  };
}
