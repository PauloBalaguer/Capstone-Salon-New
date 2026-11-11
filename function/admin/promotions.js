function adminDefaultServiceForm() {
  return {
    branchId: '',
    name: '',
    description: '',
    category: '',
    durationMinutes: '',
    price: '',
    loyaltyMultiplier: 1,
    loyaltyTreatmentEligible: false,
    paymentRequirement: '50% Down Payment',
    active: true
  };
}

function adminDefaultPromoForm() {
  return {
    title: '',
    description: '',
    branchId: '',
    serviceId: '',
    discountType: 'Percentage',
    discountValue: '',
    startDate: '',
    endDate: '',
    active: true
  };
}

function adminDefaultPackageForm() {
  return {
    packageName: '',
    price: '',
    reservationFee: '',
    features: '',
    style: 'Plain',
    displayOrder: 0,
    active: true,
    travelMinutes: '',
    setupMinutes: '',
    serviceMinutes: '',
    durationsConfirmed: false
  };
}

function adminPromotionsApp() {
  return {
    // One branch at a time (defaults to the first branch once data loads).
    serviceFilterBranch: AdminUI.param('branch') || '',
    serviceCategory: 'all',
    serviceSearch: '',
    openServiceModal: false,
    editingServiceId: null,
    serviceForm: adminDefaultServiceForm(),
    savingService: false,

    openPromoModal: false,
    editingPromoId: null,
    promoForm: adminDefaultPromoForm(),

    openPackageModal: false,
    editingPackageId: null,
    packageForm: adminDefaultPackageForm(),

    init() {
      adminHighlightNav();
      const pickBranch = (branches) => {
        if (branches.length && !branches.some(b => b.id === this.serviceFilterBranch)) this.serviceFilterBranch = branches[0].id;
      };
      pickBranch(Alpine.store('admin').branches);
      this.$watch('$store.admin.branches', pickBranch);
      this.$watch('serviceFilterBranch', () => { this.serviceCategory = 'all'; });
    },

    branchServices() {
      return Alpine.store('admin').services.filter(s => s.branchId === this.serviceFilterBranch);
    },

    // Only the categories this branch actually offers; the five core salon
    // categories are listed first, any other category after them.
    branchCategories() {
      const order = ['hair', 'nails', 'facial', 'lashes', 'brows'];
      const counts = new Map();
      this.branchServices().forEach(s => {
        const name = (s.category || '').trim() || 'Uncategorized';
        counts.set(name, (counts.get(name) || 0) + 1);
      });
      const rank = (name) => { const i = order.indexOf(name.toLowerCase()); return i === -1 ? order.length : i; };
      return [...counts].map(([name, count]) => ({ name, count }))
        .sort((a, b) => rank(a.name) - rank(b.name) || a.name.localeCompare(b.name));
    },

    // Branch + category + search, most frequently booked first (bookingCount
    // = rows in appointment_services for that service).
    getFilteredServices() {
      const q = this.serviceSearch.trim().toLowerCase();
      return this.branchServices().filter(s => {
        const cat = (s.category || '').trim() || 'Uncategorized';
        if (this.serviceCategory !== 'all' && cat !== this.serviceCategory) return false;
        return !q || [s.name, s.description].some(v => String(v || '').toLowerCase().includes(q));
      }).sort((a, b) => (b.bookingCount || 0) - (a.bookingCount || 0) || a.name.localeCompare(b.name));
    },

    branchPromotions() {
      return Alpine.store('admin').promotions.filter(p => p.branchId === this.serviceFilterBranch);
    },

    serviceName(serviceId) {
      const service = Alpine.store('admin').services.find(s => s.id === serviceId);
      return service ? service.name : '';
    },

    openNewServiceModal() {
      this.editingServiceId = null;
      this.serviceForm = { ...adminDefaultServiceForm(), branchId: this.serviceFilterBranch };
      this.openServiceModal = true;
    },

    openEditService(service) {
      this.editingServiceId = service.id;
      this.serviceForm = {
        branchId: service.branchId,
        name: service.name,
        description: service.description || '',
        category: service.category || '',
        durationMinutes: service.durationMinutes,
        price: service.price,
        loyaltyMultiplier: service.loyaltyMultiplier,
        loyaltyTreatmentEligible: !!service.loyaltyTreatmentEligible,
        paymentRequirement: service.paymentRequirement || '50% Down Payment',
        active: service.active
      };
      this.openServiceModal = true;
    },

    async saveService() {
      if (!Number.isInteger(Number(this.serviceForm.durationMinutes)) || Number(this.serviceForm.durationMinutes) <= 0 || Number(this.serviceForm.durationMinutes) > 2147483647) {
        LMToast.show('Estimated duration must be a positive whole number of minutes.', 'error');
        return;
      }
      const store = Alpine.store('admin');
      this.savingService = true;
      const result = await store.post('saveService.php', {
        id: this.editingServiceId || '',
        branchId: this.serviceForm.branchId,
        name: this.serviceForm.name,
        description: this.serviceForm.description,
        category: this.serviceForm.category,
        durationMinutes: this.serviceForm.durationMinutes,
        price: this.serviceForm.price,
        loyaltyMultiplier: this.serviceForm.loyaltyMultiplier,
        loyaltyTreatmentEligible: this.serviceForm.loyaltyTreatmentEligible,
        paymentRequirement: this.serviceForm.paymentRequirement,
        active: this.serviceForm.active
      });
      this.savingService = false;
      if (!result.success) {
        LMToast.show(result.message || 'Failed to save service.', 'error');
        return;
      }
      await store.refresh();
      this.openServiceModal = false;
    },

    async toggleServiceActive(serviceId) {
      const store = Alpine.store('admin');
      const result = await store.post('toggleService.php', { id: serviceId });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to update service.', 'error');
        return;
      }
      await store.refresh();
    },

    async removeService(service) {
      if (!(await LMConfirm({ title: `Delete "${service.name}"?`, message: "This can't be undone.", confirmLabel: 'Delete' }))) return;
      const store = Alpine.store('admin');
      const result = await store.post('deleteService.php', { id: service.id });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to delete service.', 'error');
        return;
      }
      await store.refresh();
    },

    openNewPromoModal() {
      this.editingPromoId = null;
      this.promoForm = { ...adminDefaultPromoForm(), branchId: this.serviceFilterBranch };
      this.openPromoModal = true;
    },

    openEditPromo(promo) {
      this.editingPromoId = promo.id;
      this.promoForm = {
        title: promo.title,
        description: promo.description,
        branchId: promo.branchId,
        serviceId: promo.serviceId || '',
        discountType: promo.discountType,
        discountValue: promo.discountValue,
        startDate: promo.startDate,
        endDate: promo.endDate,
        active: promo.active
      };
      this.openPromoModal = true;
    },

    getServicesForPromoBranch() {
      return Alpine.store('admin').servicesByBranch[this.promoForm.branchId] || [];
    },

    async savePromo() {
      const store = Alpine.store('admin');
      const result = await store.post('savePromotion.php', {
        id: this.editingPromoId || '',
        title: this.promoForm.title,
        description: this.promoForm.description,
        branchId: this.promoForm.branchId,
        serviceId: this.promoForm.serviceId,
        discountType: this.promoForm.discountType,
        discountValue: this.promoForm.discountValue,
        startDate: this.promoForm.startDate,
        endDate: this.promoForm.endDate,
        active: this.promoForm.active
      });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to save promotion.', 'error');
        return;
      }
      await store.refresh();
      this.openPromoModal = false;
    },

    async togglePromoActive(promoId) {
      const store = Alpine.store('admin');
      const result = await store.post('togglePromotion.php', { id: promoId });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to update promotion.', 'error');
        return;
      }
      await store.refresh();
    },

    async removePromo(promo) {
      if (!(await LMConfirm({ title: `Delete "${promo.title}"?`, message: "This can't be undone.", confirmLabel: 'Delete' }))) return;
      const store = Alpine.store('admin');
      const result = await store.post('deletePromotion.php', { id: promo.id });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to delete promotion.', 'error');
        return;
      }
      await store.refresh();
    },

    openNewPackageModal() {
      this.editingPackageId = null;
      const store = Alpine.store('admin');
      const nextOrder = store.weddingPackages.reduce((max, p) => Math.max(max, p.displayOrder), 0) + 1;
      this.packageForm = { ...adminDefaultPackageForm(), displayOrder: nextOrder };
      this.openPackageModal = true;
    },

    openEditPackage(pkg) {
      this.editingPackageId = pkg.id;
      this.packageForm = {
        packageName: pkg.packageName,
        price: pkg.price,
        reservationFee: pkg.reservationFee !== null ? pkg.reservationFee : '',
        features: pkg.features,
        style: pkg.style,
        displayOrder: pkg.displayOrder,
        active: pkg.active,
        travelMinutes: pkg.travelMinutes ?? '',
        setupMinutes: pkg.setupMinutes ?? '',
        serviceMinutes: pkg.serviceMinutes ?? '',
        durationsConfirmed: pkg.durationsConfirmed
      };
      this.openPackageModal = true;
    },

    async savePackage() {
      const store = Alpine.store('admin');
      const result = await store.post('saveWeddingPackage.php', {
        id: this.editingPackageId || '',
        packageName: this.packageForm.packageName,
        price: this.packageForm.price,
        reservationFee: this.packageForm.reservationFee,
        features: this.packageForm.features,
        style: this.packageForm.style,
        displayOrder: this.packageForm.displayOrder,
        active: this.packageForm.active,
        travelMinutes: this.packageForm.travelMinutes,
        setupMinutes: this.packageForm.setupMinutes,
        serviceMinutes: this.packageForm.serviceMinutes,
        durationsConfirmed: this.packageForm.durationsConfirmed
      });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to save wedding package.', 'error');
        return;
      }
      await store.refresh();
      this.openPackageModal = false;
    },

    async togglePackageActive(packageId) {
      const store = Alpine.store('admin');
      const result = await store.post('toggleWeddingPackage.php', { id: packageId });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to update wedding package.', 'error');
        return;
      }
      await store.refresh();
    },

    async removePackage(pkg) {
      if (!(await LMConfirm({ title: `Delete "${pkg.packageName}"?`, message: "This can't be undone.", confirmLabel: 'Delete' }))) return;
      const store = Alpine.store('admin');
      const result = await store.post('deleteWeddingPackage.php', { id: pkg.id });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to delete wedding package.', 'error');
        return;
      }
      await store.refresh();
    }
  };
}
