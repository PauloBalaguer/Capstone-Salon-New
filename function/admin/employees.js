function adminDefaultStaffForm() {
  return {
    firstName: '',
    middleName: '',
    noMiddleName: false,
    lastName: '',
    branchId: '',
    role: '',
    email: '',
    phone: '',
    serviceIds: []
  };
}

function adminStaffApp() {
  return {
    activeTab: 'directory',
    staffFilterBranch: AdminUI.param('branch') || 'all',
    // 'on' = clocked in (available, busy or on break); the Dashboard links here with ?shift=on.
    staffFilterShift: AdminUI.param('shift') || 'all',
    staffSearchQuery: '',
    performanceSortBy: 'completed',
    detailStaff: null,

    openStaffModal: false,
    editingStaffId: null,
    staffForm: adminDefaultStaffForm(),
    savingStaff: false,

    init() {
      adminHighlightNav();
      // Keep an open details modal in sync with live refreshes.
      this.$watch('$store.admin.staffList', (list) => {
        if (this.detailStaff) this.detailStaff = list.find(s => s.id === this.detailStaff.id) || null;
      });
    },

    openDetails(staff) { this.detailStaff = staff; this.loadSchedule(staff.id); },

    /* ---------- Weekly days off and date-specific leave (backend/admin/staffSchedule.php) ---------- */
    scheduleDays: [],
    leaveList: [],
    leaveForm: { startDate: '', endDate: '', reason: '' },
    leaveAffected: null, // null = not previewed yet; [] = nothing affected
    scheduleLoading: false,
    leaveReady: true, // false until migration 062 (staff_leave) is applied
    async loadSchedule(employeeId) {
      this.scheduleLoading = true;
      this.scheduleDays = [];
      this.leaveList = [];
      this.leaveAffected = null;
      this.leaveForm = { startDate: '', endDate: '', reason: '' };
      this.weekAffected = null;
      try {
        const r = await Alpine.store('admin').get('staffSchedule.php', { employeeId });
        if (!r.success) { LMToast.show(r.message || 'Could not load the schedule.', 'error'); return; }
        if (!this.detailStaff || String(this.detailStaff.id) !== String(employeeId)) return;
        this.scheduleDays = r.days;
        this.leaveList = r.leave;
        this.leaveReady = r.leaveReady !== false;
      } finally { this.scheduleLoading = false; }
    },
    // Like leave: check affected confirmed bookings first, then save and flag them.
    weekAffected: null, // null = not checked since the last edit; [] = nothing affected
    async previewWeek() {
      const r = await Alpine.store('admin').post('staffSchedule.php', { action: 'previewWeek', employeeId: this.detailStaff.id, days: JSON.stringify(this.scheduleDays) });
      if (!r.success) { LMToast.show(r.message || 'Could not check affected bookings.', 'error'); return; }
      this.weekAffected = r.affected;
    },
    async saveWeek() {
      const r = await Alpine.store('admin').post('staffSchedule.php', { action: 'saveWeek', employeeId: this.detailStaff.id, days: JSON.stringify(this.scheduleDays), confirmed: 1 });
      if (!r.success) {
        if (r.affected) this.weekAffected = r.affected;
        LMToast.show(r.message || 'Could not save the schedule.', 'error');
        return;
      }
      LMToast.show(r.message, 'success');
      this.weekAffected = null;
      Alpine.store('admin').refresh();
    },
    async previewLeave() {
      const f = this.leaveForm;
      if (!f.startDate) { LMToast.show('Choose the first day of leave.', 'error'); return; }
      if (!f.endDate) f.endDate = f.startDate;
      const r = await Alpine.store('admin').post('staffSchedule.php', { action: 'previewLeave', employeeId: this.detailStaff.id, startDate: f.startDate, endDate: f.endDate });
      if (!r.success) { LMToast.show(r.message || 'Could not check affected bookings.', 'error'); return; }
      this.leaveAffected = r.affected;
    },
    async addLeave() {
      const f = this.leaveForm;
      const r = await Alpine.store('admin').post('staffSchedule.php', { action: 'addLeave', employeeId: this.detailStaff.id, startDate: f.startDate, endDate: f.endDate || f.startDate, reason: f.reason, confirmed: 1 });
      if (!r.success) {
        if (r.affected) this.leaveAffected = r.affected;
        LMToast.show(r.message || 'Could not save the leave.', 'error');
        return;
      }
      LMToast.show(r.message, 'success');
      await this.loadSchedule(this.detailStaff.id);
      Alpine.store('admin').refresh();
    },
    async deleteLeave(leave) {
      if (!confirm('Remove this leave? Bookings already flagged for follow-up stay flagged.')) return;
      const r = await Alpine.store('admin').post('staffSchedule.php', { action: 'deleteLeave', leaveId: leave.id });
      LMToast.show(r.message || (r.success ? 'Removed.' : 'Could not remove the leave.'), r.success ? 'success' : 'error');
      if (r.success) this.loadSchedule(this.detailStaff.id);
    },

    // Position in the current Performance ranking (same metric as that tab).
    rankLabel(staff) {
      const list = this.getPerformanceStaff();
      const index = list.findIndex(s => s.id === staff.id);
      if (index === -1 || this.performanceSortBy === 'name') return 'See Performance tab';
      const metric = { completed: 'completed services', rating: 'rating', attendance: 'attendance' }[this.performanceSortBy];
      return '#' + (index + 1) + ' of ' + list.length + ' by ' + metric;
    },

    // Role is free text (see Add/Edit Staff), so this is a light heuristic
    // to keep non-stylist roles (Stock Clerk, etc.) out of service-based
    // ranking -- there's no dedicated role-type column to check instead.
    // Cashiers are excluded from staffList entirely already (see
    // getDashboardData.php: staffList is built from `employees`, cashiers
    // are separate `users` rows with no employee profile).
    isServiceRole(staff) {
      return !/stock\s*clerk|inventory\s*clerk/i.test(staff.role || '');
    },

    getAverageStaffRating() {
      const store = Alpine.store('admin');
      const rated = store.staffList.filter(s => s.rating > 0);
      if (rated.length === 0) return '';
      return (rated.reduce((sum, s) => sum + s.rating, 0) / rated.length).toFixed(1);
    },

    getStaffStatusLabel(status) {
      // Same five statuses as every panel (StaffShift::liveStatusSql).
      return liveStatusLabel(status);
    },

    /* Today's load from Scheduling::staffDayLoad(): clients and booked time. */
    minutesLabel(minutes) {
      const m = Math.max(0, Math.round(Number(minutes) || 0));
      const h = Math.floor(m / 60), r = m % 60;
      return [h ? h + ' hr' : '', r || !h ? r + ' min' : ''].filter(Boolean).join(' ');
    },
    todayLoadMain(staff) {
      const d = staff.todayLoad;
      if (!d) return '—';
      if (d.offReason) return d.offReason;
      return d.clients + ' client' + (d.clients === 1 ? '' : 's');
    },
    todayLoadSub(staff) {
      const d = staff.todayLoad;
      if (!d || d.offReason) return '';
      return this.minutesLabel(d.bookedMinutes) + ' of ' + this.minutesLabel(d.workMinutes) + ' booked';
    },
    todayLoadPercent(staff) {
      const d = staff.todayLoad;
      if (!d || !d.workMinutes) return 0;
      return Math.min(100, Math.round(d.bookedMinutes / d.workMinutes * 100));
    },

    getStaffStatusBadgeClass(status) {
      return liveStatusClass(status);
    },

    // "Assigned Tasks" = this staff member's active, not-yet-finished
    // bookings (in-salon appointments and home service jobs alike) --
    // reused straight from the same bookings list the Booking Desk uses,
    // no new backend/table needed.
    getStaffTasks(staffId) {
      const activeStatuses = ['Confirmed', 'In Progress', 'Staff Assigned', 'Service in Progress'];
      return Alpine.store('admin').bookings
        .filter(b => b.staffId === staffId && activeStatuses.includes(b.status))
        .map(b => ({
          id: b.id,
          label: b.isHomeService ? `Home Service ${b.eventType || b.serviceName}` : b.serviceName,
          when: b.isHomeService ? b.date : `${b.date} @ ${b.time}`,
          status: b.status
        }));
    },

    getFilteredStaff() {
      const store = Alpine.store('admin');
      let list = store.staffList;
      if (this.staffFilterBranch !== 'all') list = list.filter(s => s.branchId === this.staffFilterBranch);
      if (this.staffFilterShift === 'on') list = list.filter(s => LIVE_STATUS_CLOCKED_IN.includes(s.status));
      else if (this.staffFilterShift !== 'all') list = list.filter(s => (s.status || 'off_shift') === this.staffFilterShift);
      if (this.staffSearchQuery.trim()) {
        const q = this.staffSearchQuery.trim().toLowerCase();
        list = list.filter(s => [s.name, s.role, s.email].some(v => String(v || '').toLowerCase().includes(q)));
      }
      return list;
    },

    /* Branch picker cards (same names as the guest booking page). */
    branchCards() {
      const store = Alpine.store('admin');
      const LABELS = {
        daraga: ['Daraga Main', 'Salon & Make-Up'],
        yashano: ['Yashano Mall', 'Skin & Brows'],
        cabangan: ['Cabangan', 'Lash & Brows'],
      };
      const staff = store.staffList || [];
      const count = id => staff.filter(x => id === 'all' || x.branchId === id).length;
      return [
        { id: 'all', title: 'All branches', sub: 'Every location', count: count('all') },
        ...(store.branches || []).map(b => ({
          id: b.id,
          title: (LABELS[b.id] || [b.name])[0],
          sub: (LABELS[b.id] || [, b.type || b.location || ''])[1],
          count: count(b.id),
        })),
      ];
    },

    getDirectoryStaff() {
      return [...this.getFilteredStaff()].sort((a, b) => a.name.localeCompare(b.name));
    },

    // Ranks by exactly one real, named metric at a time -- never a blended
    // score, since no approved weighting formula exists for this project.
    getPerformanceStaff() {
      const list = [...this.getFilteredStaff()];
      switch (this.performanceSortBy) {
        case 'completed': return list.sort((a, b) => b.completedCount - a.completedCount);
        case 'rating': return list.sort((a, b) => b.rating - a.rating);
        case 'attendance': return list.sort((a, b) => b.attendance - a.attendance);
        default: return list.sort((a, b) => a.name.localeCompare(b.name));
      }
    },

    openAddStaffModal() {
      this.editingStaffId = null;
      this.staffForm = adminDefaultStaffForm();
      this.svcSearch = ''; this.svcGroup = 'all';
      this.openStaffModal = true;
    },

    openEditStaff(staff) {
      this.editingStaffId = staff.id;
      // Older rows only have the full name: split it if the parts are missing.
      const [first = '', ...rest] = String(staff.name || '').split(' ');
      const firstName = staff.firstName || first;
      const lastName = staff.lastName || rest.join(' ');
      const middleName = staff.middleName || '';
      this.staffForm = { firstName, middleName, noMiddleName: !middleName, lastName, branchId: staff.branchId, role: staff.role, email: '', phone: '', serviceIds: [...(staff.serviceIds || [])] };
      this.svcSearch = ''; this.svcGroup = 'all';
      this.openStaffModal = true;
    },

    /* Services picker (search + category + select all + chips). */
    svcSearch: '',
    svcGroup: 'all',
    staffSvcGroups() {
      const ORDER = ['Hair Services', 'Nails & Spa', 'Lashes & Brows', 'Facial & Skin Care', 'Waxing & Laser',
        'Aesthetic Treatments', 'Massage & Body Care', 'Make-Up Services', 'Promos & Packages'];
      const present = new Set(this.getServicesForStaffBranch().map(s => s.group || 'Other'));
      return [...ORDER.filter(g => present.has(g)), ...[...present].filter(g => !ORDER.includes(g)).sort()];
    },
    staffSvcVisible() {
      const q = this.svcSearch.trim().toLowerCase();
      return this.getServicesForStaffBranch().filter(s =>
        (q ? s.name.toLowerCase().includes(q) : (this.svcGroup === 'all' || (s.group || 'Other') === this.svcGroup)));
    },
    staffSvcAllVisibleChecked() {
      const list = this.staffSvcVisible();
      return list.length > 0 && list.every(s => this.staffForm.serviceIds.includes(s.id));
    },
    toggleAllVisibleServices() {
      const ids = this.staffSvcVisible().map(s => s.id);
      if (this.staffSvcAllVisibleChecked()) {
        this.staffForm.serviceIds = this.staffForm.serviceIds.filter(id => !ids.includes(id));
      } else {
        this.staffForm.serviceIds = [...new Set([...this.staffForm.serviceIds, ...ids])];
      }
    },
    selectedStaffServices() {
      const byId = new Map(this.getServicesForStaffBranch().map(s => [s.id, s]));
      return this.staffForm.serviceIds.map(id => byId.get(id)).filter(Boolean);
    },

    getServicesForStaffBranch() {
      const store = Alpine.store('admin');
      return store.servicesByBranch[this.staffForm.branchId] || [];
    },

    toggleStaffService(serviceId) {
      const idx = this.staffForm.serviceIds.indexOf(serviceId);
      if (idx === -1) {
        this.staffForm.serviceIds.push(serviceId);
      } else {
        this.staffForm.serviceIds.splice(idx, 1);
      }
    },

    async saveStaff() {
      const store = Alpine.store('admin');
      this.savingStaff = true;
      const result = await store.post('saveStaff.php', {
        id: this.editingStaffId || '',
        firstName: this.staffForm.firstName.trim(),
        middleName: this.staffForm.noMiddleName ? '' : this.staffForm.middleName.trim(),
        lastName: this.staffForm.lastName.trim(),
        branchId: this.staffForm.branchId,
        role: this.staffForm.role,
        email: this.staffForm.email,
        phone: this.staffForm.phone,
        services: this.staffForm.serviceIds
      });
      this.savingStaff = false;
      if (!result.success) {
        LMToast.show(result.message || 'Failed to save staff member.', 'error');
        return;
      }
      await store.refresh();
      this.openStaffModal = false;
    },

    async removeStaffMember(staff) {
      if (!(await LMConfirm({ title: `Remove ${staff.name}?`, message: 'They will be removed from the active roster and can no longer be assigned.', confirmLabel: 'Remove' }))) return;
      const store = Alpine.store('admin');
      const result = await store.post('removeStaff.php', { id: staff.id });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to remove staff member.', 'error');
        return;
      }
      this.openStaffModal = false;
      LMToast.show(`${staff.name} was removed.`, 'success');
      await store.refresh();
    }
  };
}
