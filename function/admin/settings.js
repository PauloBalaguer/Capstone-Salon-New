function adminDefaultCashierForm() {
  return {
    name: '',
    branchId: '',
    email: '', newPassword: '', confirmPassword: ''
  };
}

function adminSettingsApp() {
  return {
    profileSaved: false,

    // Branch settings: one branch at a time, edited on a local copy so the
    // 30-second live refresh never overwrites what is being typed.
    branchKey: AdminUI.param('branch') || '',
    settingsTab: ['branch', 'durations', 'account', 'access'].includes(AdminUI.param('tab')) ? AdminUI.param('tab') : 'branch',
    sundayDifferent: false,
    accessBranch: 'all',
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

    /* "08:00" -> "8:00 AM"; "Open daily · 8:00 AM – 8:00 PM". */
    timeText(v) {
      const [H, M] = String(v || '').split(':').map(Number);
      if (Number.isNaN(H)) return '';
      return `${((H + 11) % 12) + 1}:${String(M || 0).padStart(2, '0')} ${H < 12 ? 'AM' : 'PM'}`;
    },
    hoursText(open, close) {
      return open && close ? `${this.timeText(open)} – ${this.timeText(close)}` : 'Hours not set';
    },
    settingsSummary() {
      const f = this.branchForm;
      if (!f) return '';
      const parts = [this.hoursText(f.openingTime, f.closingTime)];
      if (f.sundayOpeningTime && f.sundayClosingTime) parts.push('Sunday ' + this.hoursText(f.sundayOpeningTime, f.sundayClosingTime));
      parts.push((f.slotLimit || 0) + ' booking' + (f.slotLimit === 1 ? '' : 's') + ' at a time');
      parts.push(f.offersHomeService ? 'Home service on' : 'No home service');
      return parts.join(' · ');
    },

    /* Staff access tab: branch cards + rows grouped per branch. */
    BRANCH_SHORT: { daraga: 'Daraga Main', yashano: 'Yashano Mall', cabangan: 'Cabangan' },
    accessCards() {
      const store = Alpine.store('admin');
      const staff = store.staffList || [], cashiers = store.cashierList || [];
      const n = (list, id) => list.filter(x => id === 'all' || x.branchId === id).length;
      return [
        { id: 'all', title: 'All branches', sub: 'Every location', staff: staff.length, cashiers: cashiers.length },
        ...(store.branches || []).map(b => ({ id: b.id, title: this.BRANCH_SHORT[b.id] || b.name, sub: b.type || '', staff: n(staff, b.id), cashiers: n(cashiers, b.id) })),
      ];
    },
    accessGroups(list) {
      const store = Alpine.store('admin');
      return (store.branches || [])
        .filter(b => this.accessBranch === 'all' || b.id === this.accessBranch)
        .map(b => ({ id: b.id, label: (this.BRANCH_SHORT[b.id] || b.name) + ' · ' + b.name, rows: (list || []).filter(x => x.branchId === b.id).sort((x, y) => String(x.name).localeCompare(String(y.name))) }))
        .filter(g => g.rows.length);
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
        slotLimit: b.slotLimit,
        offersHomeService: b.offersHomeService !== false
      };
      this.branchFormOriginal = JSON.stringify(this.branchForm);
      this.sundayDifferent = !!(this.branchForm.sundayOpeningTime || this.branchForm.sundayClosingTime);
      this.loadHolidays();
    },

    /* ---------- Branch holidays (backend/admin/branchHolidays.php) ---------- */
    holidays: [],
    holidayForm: { date: '', type: 'closed', openTime: '', closeTime: '', name: '' },
    holidayAffected: null, // null = not previewed; [] = nothing affected
    async loadHolidays() {
      const key = this.branchKey;
      this.holidays = [];
      this.holidayAffected = null;
      if (!key) return;
      const r = await Alpine.store('admin').get('branchHolidays.php', { branchKey: key });
      if (key !== this.branchKey) return;
      if (r.success) this.holidays = r.holidays;
    },
    holidayParams(action) {
      const f = this.holidayForm;
      return { action, branchKey: this.branchKey, date: f.date, type: f.type, openTime: f.openTime, closeTime: f.closeTime, name: f.name };
    },
    async previewHoliday() {
      if (!this.holidayForm.date) { LMToast.show('Choose the holiday date.', 'error'); return; }
      const r = await Alpine.store('admin').post('branchHolidays.php', this.holidayParams('preview'));
      if (!r.success) { LMToast.show(r.message || 'Could not check affected bookings.', 'error'); return; }
      this.holidayAffected = r.affected;
    },
    async saveHoliday() {
      const r = await Alpine.store('admin').post('branchHolidays.php', { ...this.holidayParams('save'), confirmed: 1 });
      if (!r.success) {
        if (r.affected) this.holidayAffected = r.affected;
        LMToast.show(r.message || 'Could not save the holiday.', 'error');
        return;
      }
      LMToast.show(r.message, 'success');
      this.holidayForm = { date: '', type: 'closed', openTime: '', closeTime: '', name: '' };
      await this.loadHolidays();
      Alpine.store('admin').refresh();
    },
    async deleteHoliday(h) {
      if (!confirm('Remove this holiday? The branch goes back to its normal hours that day.')) return;
      const r = await Alpine.store('admin').post('branchHolidays.php', { action: 'delete', holidayId: h.id });
      LMToast.show(r.message || (r.success ? 'Removed.' : 'Could not remove the holiday.'), r.success ? 'success' : 'error');
      if (r.success) this.loadHolidays();
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
      this.cashierForm = { ...adminDefaultCashierForm(), name: cashier.name, branchId: cashier.branchId };
      this.openCashierModal = true;
    },

    async saveCashier() {
      if (this.cashierForm.newPassword !== this.cashierForm.confirmPassword) {
        LMToast.show('New password and confirmation do not match.', 'error');
        return;
      }
      const store = Alpine.store('admin');
      this.savingCashier = true;
      const result = await store.post('saveCashier.php', {
        id: this.editingCashierId || '',
        name: this.cashierForm.name,
        branchId: this.cashierForm.branchId,
        email: this.cashierForm.email,
        newPassword: this.cashierForm.newPassword,
        confirmPassword: this.cashierForm.confirmPassword
      });
      this.savingCashier = false;
      if (!result.success) {
        LMToast.show(result.message || 'Failed to save cashier account.', 'error');
        return;
      }
      this.cashierForm.newPassword = '';
      this.cashierForm.confirmPassword = '';
      LMToast.show(result.message || 'Cashier account updated.', 'success');
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
        slotLimit: branch.slotLimit,
        offersHomeService: branch.offersHomeService ? 1 : 0
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
        returnTravelMinutes: row.returnTravelMinutes ?? '',
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
