function adminDefaultServiceForm() {
  return {
    branchId: '',
    name: '',
    description: '',
    category: '',
    durationMinutes: '',
    price: '',
    loyaltyMultiplier: 1,
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
    active: true
  };
}

function adminPromotionsApp() {
  return {
    serviceFilterBranch: 'all',
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
    },

    getFilteredServices() {
      const store = Alpine.store('admin');
      if (this.serviceFilterBranch === 'all') return store.services;
      return store.services.filter(s => s.branchId === this.serviceFilterBranch);
    },

    openNewServiceModal() {
      this.editingServiceId = null;
      this.serviceForm = adminDefaultServiceForm();
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
        paymentRequirement: service.paymentRequirement || '50% Down Payment',
        active: service.active
      };
      this.openServiceModal = true;
    },

    async saveService() {
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
        paymentRequirement: this.serviceForm.paymentRequirement,
        active: this.serviceForm.active
      });
      this.savingService = false;
      if (!result.success) {
        alert(result.message || 'Failed to save service.');
        return;
      }
      await store.refresh();
      this.openServiceModal = false;
    },

    async toggleServiceActive(serviceId) {
      const store = Alpine.store('admin');
      const result = await store.post('toggleService.php', { id: serviceId });
      if (!result.success) {
        alert(result.message || 'Failed to update service.');
        return;
      }
      await store.refresh();
    },

    async removeService(service) {
      if (!confirm(`Delete "${service.name}"? This can't be undone.`)) return;
      const store = Alpine.store('admin');
      const result = await store.post('deleteService.php', { id: service.id });
      if (!result.success) {
        alert(result.message || 'Failed to delete service.');
        return;
      }
      await store.refresh();
    },

    openNewPromoModal() {
      this.editingPromoId = null;
      this.promoForm = adminDefaultPromoForm();
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
        alert(result.message || 'Failed to save promotion.');
        return;
      }
      await store.refresh();
      this.openPromoModal = false;
    },

    async togglePromoActive(promoId) {
      const store = Alpine.store('admin');
      const result = await store.post('togglePromotion.php', { id: promoId });
      if (!result.success) {
        alert(result.message || 'Failed to update promotion.');
        return;
      }
      await store.refresh();
    },

    async removePromo(promoId) {
      const store = Alpine.store('admin');
      const result = await store.post('deletePromotion.php', { id: promoId });
      if (!result.success) {
        alert(result.message || 'Failed to delete promotion.');
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
        active: pkg.active
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
        active: this.packageForm.active
      });
      if (!result.success) {
        alert(result.message || 'Failed to save wedding package.');
        return;
      }
      await store.refresh();
      this.openPackageModal = false;
    },

    async togglePackageActive(packageId) {
      const store = Alpine.store('admin');
      const result = await store.post('toggleWeddingPackage.php', { id: packageId });
      if (!result.success) {
        alert(result.message || 'Failed to update wedding package.');
        return;
      }
      await store.refresh();
    },

    async removePackage(packageId) {
      const store = Alpine.store('admin');
      const result = await store.post('deleteWeddingPackage.php', { id: packageId });
      if (!result.success) {
        alert(result.message || 'Failed to delete wedding package.');
        return;
      }
      await store.refresh();
    }
  };
}
