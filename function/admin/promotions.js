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
    returnTravelMinutes: '',
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

    // Simple groups (Hair Services, Nails & Spa...), the same 9 the guest,
    // walk-in and staff pages use, instead of every raw "Menu / Section"
    // category. Only groups this branch actually offers, in the usual order.
    branchCategories() {
      const ORDER = ['Hair Services', 'Nails & Spa', 'Lashes & Brows', 'Facial & Skin Care', 'Waxing & Laser',
        'Aesthetic Treatments', 'Massage & Body Care', 'Make-Up Services', 'Promos & Packages'];
      const counts = new Map();
      this.branchServices().forEach(s => {
        const name = s.group || 'Other';
        counts.set(name, (counts.get(name) || 0) + 1);
      });
      const rank = (name) => { const i = ORDER.indexOf(name); return i === -1 ? ORDER.length : i; };
      return [...counts].map(([name, count]) => ({ name, count }))
        .sort((a, b) => rank(a.name) - rank(b.name) || a.name.localeCompare(b.name));
    },

    // Branch + group + search, most frequently booked first (bookingCount
    // = rows in appointment_services for that service).
    getFilteredServices() {
      const q = this.serviceSearch.trim().toLowerCase();
      return this.branchServices().filter(s => {
        if (this.serviceCategory !== 'all' && (s.group || 'Other') !== this.serviceCategory) return false;
        return !q || [s.name, s.description, s.category].some(v => String(v || '').toLowerCase().includes(q));
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

    /* Row look: one colour + letter per category group. */
    groupKey(group) {
      return ({ 'Hair Services': 'hair', 'Nails & Spa': 'nails', 'Lashes & Brows': 'lash', 'Facial & Skin Care': 'facial',
        'Waxing & Laser': 'wax', 'Aesthetic Treatments': 'aesth', 'Massage & Body Care': 'body', 'Make-Up Services': 'makeup',
        'Promos & Packages': 'promo' })[group] || 'other';
    },
    groupIcon(group) {
      return ({ hair: '✂', nails: '💅', lash: '👁', facial: '✿', wax: '✦', aesth: '✚', body: '❋', makeup: '💄', promo: '%', other: '•' })[this.groupKey(group)];
    },
    /* Top 3 most-booked services of this branch (at least 3 bookings). */
    isPopular(service) {
      const top = [...this.branchServices()].sort((a, b) => (b.bookingCount || 0) - (a.bookingCount || 0)).slice(0, 3);
      return (service.bookingCount || 0) >= 3 && top.some(x => x.id === service.id);
    },

    /* Table helpers: short, one-line labels. */
    sectionOf(category) {
      const parts = String(category || '').split(' / ');
      return parts.length > 1 ? parts.slice(1).join(' / ') : (parts[0] || '');
    },
    paymentShort(rule) {
      return ({ '50% Down Payment': '50% down', 'Full Payment': 'Full payment', 'No Online Reservation': 'Pay at salon' })[rule] || rule || '';
    },
    durationShort(minutes) {
      const m = Number(minutes) || 0;
      if (!m) return '—';
      const h = Math.floor(m / 60), r = m % 60;
      return [h ? h + ' hr' : '', r ? r + ' min' : ''].filter(Boolean).join(' ');
    },
    /* Disable / Delete now live in the Edit dialog. */
    editingService() {
      return (Alpine.store('admin').services || []).find(s => s.id === this.editingServiceId) || null;
    },
    serviceIsActive() {
      const s = this.editingService();
      return s ? !!s.active : true;
    },
    async toggleFromEdit() {
      const s = this.editingService();
      if (!s) return;
      if (s.active && !(await LMConfirm({ title: `Disable "${s.name}"?`, message: 'It will be hidden from booking until you enable it again.', confirmLabel: 'Disable' }))) return;
      this.openServiceModal = false;
      await this.toggleServiceActive(s.id);
    },
    async deleteFromEdit() {
      const s = this.editingService();
      if (!s) return;
      this.openServiceModal = false;
      await this.removeService(s);
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
        returnTravelMinutes: pkg.returnTravelMinutes ?? '',
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
        returnTravelMinutes: this.packageForm.returnTravelMinutes,
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
