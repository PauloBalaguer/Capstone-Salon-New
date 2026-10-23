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

    // Cashier account management -- moved here from the Staff & Performance
    // Hub (Step 3): account/access administration belongs under Platform
    // Settings, not mixed into staff performance tracking.
    openCashierModal: false,
    editingCashierId: null,
    cashierForm: adminDefaultCashierForm(),
    savingCashier: false,

    init() {
      adminHighlightNav();
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
        alert(result.message || 'Failed to save cashier account.');
        return;
      }
      await store.refresh();
      this.openCashierModal = false;
    },

    async removeCashierMember(cashierId) {
      if (!confirm('Remove this cashier account? They will no longer be able to log in.')) return;
      const store = Alpine.store('admin');
      const result = await store.post('removeCashier.php', { id: cashierId });
      if (!result.success) {
        alert(result.message || 'Failed to remove cashier account.');
        return;
      }
      await store.refresh();
    },

    async saveBranch(branchId) {
      const store = Alpine.store('admin');
      const branch = store.getBranch(branchId);
      if (!branch) return;
      const result = await store.post('saveBranch.php', {
        id: branchId,
        location: branch.location,
        type: branch.type,
        openingTime: branch.openingTime,
        closingTime: branch.closingTime,
        sundayOpeningTime: branch.sundayOpeningTime || '',
        sundayClosingTime: branch.sundayClosingTime || '',
        slotLimit: branch.slotLimit
      });
      if (!result.success) {
        alert(result.message || 'Failed to update branch info.');
        return;
      }
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
        alert(result.message || 'Failed to update profile.');
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
        alert(result.message || 'Failed to update notification preferences.');
      }
    }
  };
}
