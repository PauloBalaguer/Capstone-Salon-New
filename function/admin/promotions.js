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
    serviceIcon(service) {
      const name = String(service.name || '').toLowerCase();
      let key = this.groupKey(service.group);
      if (/\b(lip|lips|blush)\b/.test(name)) key = 'lips';
      else if (/facial|diamond peel|face|aqua facial/.test(name)) key = 'facial';
      else if (/lash|brow|microblad/.test(name)) key = 'lash';
      else if (/nail|manicure|pedicure|gel polish|foot spa/.test(name)) key = 'nails';
      else if (/hair|rebond|keratin|blow\s?dry|brazilian/.test(name)) key = 'hair';
      else if (/make.?up/.test(name)) key = 'makeup';
      else if (/wax|laser/.test(name)) key = 'wax';
      else if (/massage|scrub/.test(name)) key = 'body';
      const paths = {
        hair: '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="m8 8 13 13M8 16 21 3M14 10l7-7"/>',
        nails: '<path d="M8 10V6a4 4 0 0 1 8 0v4M7 10h10l1 11H6L7 10Z"/><path d="M9 14h6"/>',
        lash: '<path d="M2 12s4-6 10-6 10 6 10 6-4 6-10 6S2 12 2 12ZM5 7 3 4m9 2V2m7 5 2-3"/><circle cx="12" cy="12" r="3"/>',
        facial: '<path d="M6 9c0-5 12-5 12 0v5c0 5-6 8-6 8s-6-3-6-8V9ZM8 4l4-2 4 2M8 11h2m4 0h2m-6 6h4"/>',
        lips: '<path d="M2 12s5-7 8-5l2 1 2-1c3-2 8 5 8 5s-4 7-10 7S2 12 2 12Z"/><path d="M2 12c6 2 14 2 20 0"/>',
        wax: '<path d="m14 3 1.5 5.5L21 10l-5.5 1.5L14 17l-1.5-5.5L7 10l5.5-1.5L14 3ZM5 15v6m-3-3h6"/>',
        aesth: '<path d="M12 3c-2 4-7 8-7 12a7 7 0 0 0 14 0c0-4-5-8-7-12Z"/><path d="M9 16a3 3 0 0 0 3 3"/>',
        body: '<path d="M12 20C4 20 2 14 2 10c5 0 8 3 10 10Zm0 0c8 0 10-6 10-10-5 0-8 3-10 10Zm0-3C7 12 9 6 12 3c3 3 5 9 0 14Z"/>',
        makeup: '<path d="M8 12h8v10H8V12Zm1 0V5l6-3v10M8 17h8"/>',
        promo: '<path d="M3 3h8l10 10-8 8L3 11V3Z"/><circle cx="7.5" cy="7.5" r="1"/>',
        other: '<path d="M5 5h14v16H5V5Zm3-3h8v6H8V2Zm0 10h8m-8 4h6"/>'
      };
      return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[key] || paths.other) + '</svg>';
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
    /* Most clients one stylist can take per day doing only this service:
       the stylist is busy for the service plus the cleaning buffer, the next
       client starts at the next bookable start time, and the last service
       must end by branch closing (same rules as Scheduling.php). */
    maxPerDay(service) {
      const store = Alpine.store('admin');
      const branch = (store.branches || []).find(b => b.id === service.branchId);
      const toMin = t => { const [h, m] = String(t || '').split(':').map(Number); return h * 60 + (m || 0); };
      if (!branch || !branch.openingTime || !branch.closingTime) return null;
      let open = toMin(branch.closingTime) - toMin(branch.openingTime);
      if (open <= 0) open += 24 * 60;
      const d = Number(service.durationMinutes) || 0;
      if (!d) return null;
      if (d > open) return 0;
      const rules = store.bookingRules || {};
      const interval = Number(rules.slotIntervalMinutes) || 30;
      const step = Math.ceil((d + (Number(rules.cleaningBufferMinutes) || 0)) / interval) * interval;
      return Math.floor((open - d) / step) + 1;
    },
    maxPerDayTitle(service) {
      const rules = Alpine.store('admin').bookingRules || {};
      return 'Most clients one stylist can take per day doing only this service, within branch hours, with a '
        + (rules.cleaningBufferMinutes ?? 15) + '-min cleaning buffer and start times every '
        + (rules.slotIntervalMinutes ?? 30) + ' min. Each branch also serves at most its booking capacity at the same time.';
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
