function adminDefaultStaffForm() {
  return {
    name: '',
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
    staffFilterBranch: 'all',
    staffSearchQuery: '',
    performanceSortBy: 'name',

    openStaffModal: false,
    editingStaffId: null,
    staffForm: adminDefaultStaffForm(),
    savingStaff: false,

    init() {
      adminHighlightNav();
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
      if (rated.length === 0) return '—';
      return (rated.reduce((sum, s) => sum + s.rating, 0) / rated.length).toFixed(1);
    },

    getStaffStatusLabel(status) {
      const labels = { 'On Duty': 'Available', 'With Client': 'Busy', 'Off Shift': 'Off Shift' };
      return labels[status] || status;
    },

    getStaffStatusBadgeClass(status) {
      switch (status) {
        case 'On Duty': return 'admin-badge admin-badge--success';
        case 'With Client': return 'admin-badge admin-badge--info';
        default: return 'admin-badge admin-badge--neutral';
      }
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
          label: b.isHomeService ? `Home Service — ${b.eventType || b.serviceName}` : b.serviceName,
          when: b.isHomeService ? b.date : `${b.date} @ ${b.time}`,
          status: b.status
        }));
    },

    getFilteredStaff() {
      const store = Alpine.store('admin');
      let list = store.staffList;
      if (this.staffFilterBranch !== 'all') list = list.filter(s => s.branchId === this.staffFilterBranch);
      if (this.staffSearchQuery.trim()) {
        const q = this.staffSearchQuery.trim().toLowerCase();
        list = list.filter(s => s.name.toLowerCase().includes(q));
      }
      return list;
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
      this.openStaffModal = true;
    },

    openEditStaff(staff) {
      this.editingStaffId = staff.id;
      this.staffForm = { name: staff.name, branchId: staff.branchId, role: staff.role, email: '', phone: '', serviceIds: staff.serviceIds || [] };
      this.openStaffModal = true;
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
        name: this.staffForm.name,
        branchId: this.staffForm.branchId,
        role: this.staffForm.role,
        email: this.staffForm.email,
        phone: this.staffForm.phone,
        services: this.staffForm.serviceIds
      });
      this.savingStaff = false;
      if (!result.success) {
        alert(result.message || 'Failed to save staff member.');
        return;
      }
      await store.refresh();
      this.openStaffModal = false;
    },

    async removeStaffMember(staffId) {
      const store = Alpine.store('admin');
      const result = await store.post('removeStaff.php', { id: staffId });
      if (!result.success) {
        alert(result.message || 'Failed to remove staff member.');
        return;
      }
      await store.refresh();
    }
  };
}
