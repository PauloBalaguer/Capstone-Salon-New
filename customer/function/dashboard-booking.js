// Services & Promos browsing, service selection, and the booking form flow.

function customerBookingMixin() {
  return {
    bookingError: '',
    serviceSearch: '',
    serviceCategory: '',
    servicePage: 1,
    servicePageSize: 6,
    resetServiceFilters() {
      this.serviceSearch = '';
      this.serviceCategory = '';
      this.servicePage = 1;
    },
    serviceCategories() {
      return [...new Set((this.servicesCatalog[this.selectedBranchForServices] || []).map(s => s.category).filter(Boolean))].sort();
    },
    serviceCategoryLabel(category) {
      return (category || 'Service').split('/').pop().trim();
    },
    servicePageCount() {
      return Math.max(1, Math.ceil(this.getFilteredServices().length / this.servicePageSize));
    },
    pagedServices() {
      const page = Math.min(Math.max(1, this.servicePage), this.servicePageCount());
      return this.getFilteredServices().slice((page - 1) * this.servicePageSize, page * this.servicePageSize);
    },
    serviceResultLabel() {
      const total = this.getFilteredServices().length;
      if (!total) return 'No matching services';
      const start = (Math.min(this.servicePage, this.servicePageCount()) - 1) * this.servicePageSize;
      return 'Showing ' + (start + 1) + '-' + Math.min(start + this.servicePageSize, total) + ' of ' + total + ' services';
    },
    changeServicePage(direction) {
      this.servicePage = Math.min(this.servicePageCount(), Math.max(1, this.servicePage + direction));
      this.$nextTick(() => document.getElementById('service-catalog-heading')?.scrollIntoView({ block: 'start', behavior: 'smooth' }));
    },
    serviceReservationLabel(service) {
      if (service.paymentRequirement === 'No Online Reservation') return 'No online payment required';
      if (service.reservationAmount == null) return 'Calculated before confirmation';
      const amount = this.formatPeso ? this.formatPeso(service.reservationAmount) : formatPeso(service.reservationAmount);
      if (service.reservationLabel === 'Full Payment') return amount + ' (full payment)';
      if (service.reservationLabel === '50% Down Payment') return amount + ' (50%)';
      return amount + ' (minimum deposit)';
    },
    reservationQuote: null,
    reservationQuoteLoading: false,
    reservationQuoteRequest: 0,
    async loadReservationQuote() {
      const request = ++this.reservationQuoteRequest;
      this.reservationQuote = null;
      this.reservationQuoteLoading = true;
      this.depositLocked = false;
      const params = new URLSearchParams({ branch: this.bookingForm.branch, useLoyaltyPoints: this.bookingForm.useLoyaltyPoints ? '1' : '0', paymentPlan: this.bookingForm.paymentPlan || 'deposit' });
      this.selectedServicesForBooking.forEach(s => params.append('serviceIds[]', s.id));
      try {
        const response = await fetch('../../public/backend/getReservationQuote.php?' + params);
        const body = await response.json();
        if (request !== this.reservationQuoteRequest) return false;
        if (!body.success) throw new Error(body.message || 'Unable to calculate Pay Now.');
        this.reservationQuote = body.quote;
        // A service configured 'No Online Reservation' (Service Menu &
        // Promos -> Reservation Payment Rule) skips the deposit step --
        // amountDue is 0, so there's nothing to pay online; auto-lock with
        // method 'Cash' (already valid for a ₱0 amount, no reference
        // needed) instead of asking the customer to pick a payment method.
        if (body.quote.reservationRequirement === 'No Online Reservation') {
          this.bookingForm.paymentMethod = 'Cash';
          this.bookingForm.depositReference = '';
          this.depositLocked = true;
        }
        return true;
      } catch (error) {
        if (request === this.reservationQuoteRequest) this.pushToast('error', error.message || 'Unable to calculate Pay Now. Please try again.');
        return false;
      } finally {
        if (request === this.reservationQuoteRequest) this.reservationQuoteLoading = false;
      }
    },
    prefillBookingForm() {
      this.bookingForm.customerName = this.customerProfile.name;
      this.bookingForm.customerPhone = this.customerProfile.phone;
      this.bookingForm.branch = this.selectedBranchForServices;
    },

    // Live, duration-aware availability check against the same authoritative
    // Scheduling logic the backend uses to accept/reject the booking — not
    // just the client-side snapshot in allBookings (which can go stale the
    // moment another customer books). Called whenever the date or selected
    // services change so the schedule step only ever shows real openings.
    slotAvailabilityRequest: 0,
    slotsReady: false,
    slotsError: '',
    async refreshAvailableSlots() {
      const request = ++this.slotAvailabilityRequest;
      this.slotsReady = false;
      this.slotsError = '';
      this.slotAvailabilityMap = {};
      if (!this.bookingForm.date || !this.selectedBranchForServices || !this.selectedServicesForBooking.length) {
        this.slotsLoading = false;
        return false;
      }
      this.slotsLoading = true;
      const params = new URLSearchParams({ branchKey: this.selectedBranchForServices, date: this.bookingForm.date, assignment: 'single' });
      this.selectedServicesForBooking.forEach(s => params.append('services[]', s.id));
      try {
        const response = await fetch('../../public/backend/getAvailableSlots.php?' + params);
        const body = await response.json();
        if (request !== this.slotAvailabilityRequest) return false;
        if (response.ok === false || !body.success || !Array.isArray(body.slots)) throw new Error(body.message || 'Could not check available times.');
        const map = {};
        body.slots.forEach(s => { map[s.time] = s.available === true; });
        this.availableTimeSlots = body.slots.map(s => s.time);
        this.slotAvailabilityMap = map;
        this.slotsReady = true;
        return true;
      } catch (error) {
        if (request === this.slotAvailabilityRequest) this.slotsError = 'Could not check available times. Please try again.';
        return false;
      } finally {
        if (request === this.slotAvailabilityRequest) this.slotsLoading = false;
      }
    },
    getScheduleSlots() {
      if (!this.slotsReady || this.slotsLoading || this.slotsError) return [];
      return this.availableTimeSlots.filter(slot => this.slotAvailabilityMap[slot] === true);
    },
    isSlotAvailableNow(slot) {
      return this.slotsReady && !this.slotsLoading && !this.slotsError && this.slotAvailabilityMap[slot] === true;
    },
    canContinueSchedule() {
      return !!(this.bookingForm.date && this.bookingForm.time && this.selectedServicesForBooking.length && !this.staffAvailabilityLoading && !this.staffAvailabilityError && this.liveAvailableStaff.length && (!this.bookingForm.staffId || this.liveAvailableStaff.some(s => String(s.id) === String(this.bookingForm.staffId))) && this.isSlotAvailableNow(this.bookingForm.time));
    },
    scheduleContinueHint() {
      if (this.slotsLoading) return 'Checking available times...';
      if (this.slotsError) return 'Retry the time check to continue.';
      if (!this.bookingForm.time) return 'Select a time to see available stylists.';
      if (this.staffAvailabilityLoading) return 'Checking available stylists...';
      if (this.staffAvailabilityError) return 'Retry the stylist check to continue.';
      if (!this.liveAvailableStaff.length) return 'No stylist is free. Choose another time.';
      return '';
    },
    scrollBookingToTop() {
      this.$nextTick(() => { const main = document.querySelector('.services-page main'); if (main) main.scrollTop = 0; });
    },

    toggleServiceSelection(service) {
      const index = this.selectedServicesForBooking.findIndex(s => s.id === service.id && s.branch === this.selectedBranchForServices);
      if (index > -1) {
        this.selectedServicesForBooking.splice(index, 1);
      } else {
        this.selectedServicesForBooking.push({ ...service, branch: this.selectedBranchForServices });
      }
      // Total duration just changed, which shifts which slots still fit
      // before closing time — refresh the live availability once a date is
      // already chosen (e.g. customer adjusts services after reaching schedule).
      if (this.bookingForm.date) this.refreshAvailableSlots();
    },

    isSelectedForBooking(service) {
      return this.selectedServicesForBooking.some(s => s.id === service.id && s.branch === this.selectedBranchForServices);
    },

    openBookingSection(promo = null) {
      if (promo) {
        // The backend only accepts real services.id values (submitBooking.php
        // validates serviceIds against the services table), so a promo whose
        // linked service can't be found in the active catalog isn't bookable
        // right now — bail out instead of sending an id it will reject.
        const service = this.servicesCatalog[this.selectedBranchForServices]?.find(s => s.id === promo.serviceId);
        if (!service) {
          this.pushToast('error', 'This promotion is currently unavailable for booking. Please contact the salon directly.');
          return;
        }
        this.selectedServicesForBooking = [];
        this.selectedServicesForBooking.push({ ...service, branch: this.selectedBranchForServices, isPromo: true, promoDetails: promo });
      }
      this.showBookingForm = true;
      this.showBookingSuccess = false;
      this.bookingStep = 'schedule';
      this.scrollBookingToTop();
      this.prefillBookingForm();
      this.refreshAvailableSlots();
    },

    // "Book Now" on a service card carries just that one service straight
    // into the existing booking form -- no separate booking system, just a
    // shortcut into the same flow the multi-select "Proceed to Booking"
    // path (openBookingSection) uses.
    bookNowService(service) {
      this.selectedServicesForBooking = [{ ...service, branch: this.selectedBranchForServices }];
      this.showBookingForm = true;
      this.showBookingSuccess = false;
      this.bookingStep = 'schedule';
      this.scrollBookingToTop();
      this.prefillBookingForm();
      this.refreshAvailableSlots();
    },

    // --- Booking wizard navigation ---------------------------------------
    // Customer-facing step order: Schedule -> Stylist (skipped if nobody
    // qualifies to choose from) -> Review -> Payment -> Notifications ->
    // Terms -> Confirm. The schedule is always validated (client-side
    // preview of Scheduling::branchWindowIsFull()/staffHasConflict()) before
    // the customer ever reaches the payment step.
    bookingStepOrder() {
      return ['schedule', 'review', 'payment', 'notifications', 'terms'];
    },
    bookingStepTitle(step) {
      const titles = {
        schedule: 'Choose Your Schedule',
        stylist: 'Choose Your Stylist',
        review: 'Review Booking',
        payment: 'Payment',
        notifications: 'Choose How to Receive Updates',
        terms: 'Review & Confirm'
      };
      return titles[step] || '';
    },
    goToPreviousBookingStep() {
      const order = this.bookingStepOrder();
      const index = order.indexOf(this.bookingStep);
      if (index > 0) this.bookingStep = order[index - 1];
    },
    async proceedFromSchedule() {
      if (!this.bookingForm.date || !this.bookingForm.time) {
        return this.pushToast('error', 'Please select a date and time for your appointment.');
      }
      if (this.slotsLoading || this.staffAvailabilityLoading) return;
      const preferredStaff = this.bookingForm.staffId;
      if (!await this.refreshAvailableSlots()) return;
      if (!this.isSlotAvailableNow(this.bookingForm.time)) {
        this.bookingForm.time = '';
        return this.pushToast('error', 'Selected time slot is unavailable. Please choose another schedule.');
      }
      if (!await this.refreshAvailableStaff()) return this.pushToast('error', this.staffAvailabilityError);
      if (preferredStaff && !this.bookingForm.staffId) return this.pushToast('error', 'Your selected stylist is no longer available. Please choose another stylist.');
      if (!this.liveAvailableStaff.length) {
        this.bookingForm.time = '';
        return this.pushToast('error', 'No qualified stylist is available. Please choose another schedule.');
      }
      this.bookingStep = 'review';
      await this.loadReservationQuote();
    },
    async proceedFromReview() {
      if (!BookingFormValidation.fields([['[x-model="bookingForm.customerName"]', 'name'], ['[x-model="bookingForm.customerPhone"]', 'mobile']])) return;
      if (!this.bookingForm.customerName || !this.bookingForm.customerPhone) {
        return this.pushToast('error', 'Please provide your name and contact number.');
      }
      if (!/^09\d{9}$/.test(this.bookingForm.customerPhone)) {
        return this.pushToast('error', 'Please enter a valid mobile number (e.g. 09XXXXXXXXX).');
      }
      if (await this.loadReservationQuote()) this.bookingStep = 'payment';
    },
    proceedFromPayment() {
      if (!this.reservationQuote || this.reservationQuoteLoading) return this.pushToast('error', 'Please review your reservation payment first.');
      if (!this.bookingForm.paymentMethod) return this.pushToast('error', 'Please choose a payment method for your deposit.');
      this.depositLocked = true;
      this.bookingStep = 'notifications';
    },
    proceedFromNotifications() {
      this.bookingStep = 'terms';
    },

    getPromoForService(service) {
      return this.getFilteredPromotions().find(p => p.serviceId === service.id) || null;
    },

    // Maps the fine-grained wizard state onto the 6-label progress bar:
    // Branch > Service > Schedule > Details > Payment > Confirmation.
    bookingProgressLabels() {
      return ['Branch', 'Service', 'Schedule', 'Details', 'Payment', 'Confirmation'];
    },
    bookingProgressIndex() {
      if (this.showBookingSuccess) return 5;
      if (!this.showBookingForm) return this.selectedServicesForBooking.length > 0 ? 1 : 0;
      const map = { schedule: 2, stylist: 3, review: 3, payment: 4, notifications: 4, terms: 5 };
      return map[this.bookingStep] ?? 2;
    },

    closeBookingSection() {
      this.showBookingForm = false;
      this.showBookingSuccess = false;
      this.clearBookingSelection();
    },

    cancelBookingForm() {
      this.showBookingForm = false;
      this.showBookingSuccess = false;
      this.clearBookingSelection();
    },

    getFilteredServices() {
      const query = this.serviceSearch.trim().toLowerCase();
      return (this.servicesCatalog[this.selectedBranchForServices] || []).filter(service =>
        (!this.serviceCategory || service.category === this.serviceCategory) &&
        (!query || [service.name, service.category].some(value => String(value || '').toLowerCase().includes(query)))
      );
    },
    getFilteredPromotions() {
      return this.promotionsCatalog[this.selectedBranchForServices] || [];
    },

    bookingSubtotal() {
      return this.selectedServicesForBooking.reduce((sum, s) => sum + s.price, 0);
    },
    bookingDurationMinutes() {
      return ServiceDuration.total(this.selectedServicesForBooking);
    },
    bookingEstimatedEndTime() {
      return ServiceDuration.endTime(this.bookingForm.date, this.bookingForm.time, this.bookingDurationMinutes());
    },
    // Mirrors customer/backend/submitBooking.php's math exactly: floor the
    // subtotal down to a whole number of points first, then convert that
    // back to pesos — not the other way around. Converting a peso value
    // straight to points (subtotal capped, then treated as pesos) drifts
    // from what the backend actually charges whenever the subtotal isn't an
    // exact multiple of the point value.
    maxRedeemablePoints() {
      const subtotal = this.bookingSubtotal();
      const availablePoints = this.customerProfile.loyaltyPoints;
      const maxPointsForSubtotal = Math.floor(subtotal / this.loyaltyPointValue + 0.0000001);
      return Math.min(availablePoints, maxPointsForSubtotal);
    },
    maxRedeemableDiscount() {
      return Math.round(this.maxRedeemablePoints() * this.loyaltyPointValue * 100) / 100;
    },
    finalBookingTotal() {
      let total = this.bookingSubtotal();
      if (this.bookingForm.useLoyaltyPoints) total -= this.maxRedeemableDiscount();
      return total > 0 ? total : 0;
    },

    // Payment display uses the authoritative backend quote.
    requiredDeposit() {
      return this.reservationQuote?.amountDue ?? 0;
    },

    // Lets the customer revise their chosen payment method/reference without
    // losing the schedule they already validated (the schedule is chosen
    // before payment now, so there's nothing to re-pick here).
    changeDeposit() {
      this.depositLocked = false;
    },

    // Duration-aware: a slot is unavailable if starting the currently
    // selected services there would run past closing, would push more than
    // bookingSlotLimit branch appointments overlapping at once, or — when a
    // specific stylist is chosen — would double-book that stylist against
    // any appointment (of any customer) that overlaps the new one's window.
    // Mirrors Scheduling::branchWindowIsFull()/staffHasConflict()/
    // exceedsClosingTime() on the backend, which is the authoritative check;
    // this is only a client-side preview so the customer can't even select
    // a slot the backend would reject.
    isSlotAvailable(time) {
      if (!this.bookingForm.date) return true;
      const durationMinutes = this.selectedServicesForBooking.reduce((sum, s) => sum + (s.durationMinutes || 30), 0) || 30;
      const newStart = parseApptDateTime(this.bookingForm.date, time);
      const newEnd = new Date(newStart.getTime() + durationMinutes * 60000);

      const closing = parseApptDateTime(this.bookingForm.date, '07:30 PM');
      if (newEnd > closing) return false;

      const overlapsNewWindow = (booking) => {
        const bStart = parseApptDateTime(booking.date, booking.time);
        const bEnd = new Date(bStart.getTime() + (booking.durationMinutes || 30) * 60000);
        return windowsOverlap(newStart, newEnd, bStart, bEnd);
      };

      const sameDayBranchBookings = this.allBookings.filter(booking =>
        booking.date === this.bookingForm.date && booking.branchId === this.selectedBranchForServices && booking.status !== 'Cancelled'
      );

      const branchOverlapCount = sameDayBranchBookings.filter(overlapsNewWindow).length;
      if (branchOverlapCount >= this.bookingSlotLimit) return false;

      if (this.bookingForm.staffId) {
        const staffIsBusy = sameDayBranchBookings.some(booking =>
          String(booking.staffId) === String(this.bookingForm.staffId) && overlapsNewWindow(booking)
        );
        if (staffIsBusy) return false;
      }

      return true;
    },

    clearBookingSelection() {
      this.selectedServicesForBooking = [];
      this.bookingForm.date = getLocalISODate();
      this.bookingForm.time = '';
      this.bookingForm.staffId = '';
      this.bookingForm.paymentMethod = '';
      this.bookingForm.depositReference = '';
      this.bookingForm.paymentPlan = 'deposit';
      this.depositLocked = false;
      this.bookingForm.useLoyaltyPoints = false;
      this.bookingForm.agreedToTerms = false;
      this.bookingStep = 'schedule';
      this.slotAvailabilityMap = {};
      this.slotAvailabilityRequest++;
      this.staffAvailabilityRequest++;
      this.slotsReady = false;
      this.slotsLoading = false;
      this.slotsError = '';
      this.liveAvailableStaff = [];
      this.liveBusyStaff = [];
      this.staffAvailabilityLoading = false;
      this.staffAvailabilityError = '';
      this.prefillBookingForm();
    },

    // Only offers stylists who (a) work this branch, (b) qualify for every
    // selected service, and (c) — once a date/time is chosen — aren't
    // already booked across that window by any other appointment. This is
    // what stops a customer from picking a stylist who's actually busy at
    // the schedule they just chose.
    liveAvailableStaff: [],
    liveBusyStaff: [],
    staffAvailabilityLoading: false,
    staffAvailabilityError: '',
    staffAvailabilityRequest: 0,
    async refreshAvailableStaff() {
      const request = ++this.staffAvailabilityRequest;
      this.staffAvailabilityLoading = true;
      this.staffAvailabilityError = '';
      this.liveAvailableStaff = [];
      this.liveBusyStaff = [];
      const params = new URLSearchParams({branchKey:this.selectedBranchForServices, date:this.bookingForm.date, time:this.bookingForm.time});
      this.selectedServicesForBooking.forEach(s => params.append('services[]',s.id));
      try {
        const response = await fetch('../../public/backend/getAvailableStaff.php?' + params);
        const body = await response.json();
        if (request !== this.staffAvailabilityRequest) return false;
        if (response.ok === false || !body.success || !Array.isArray(body.staff)) throw new Error('Unavailable');
        this.liveAvailableStaff = body.staff;
        this.liveBusyStaff = body.busy || [];
        if (this.bookingForm.staffId && !body.staff.some(s => String(s.id) === String(this.bookingForm.staffId))) this.bookingForm.staffId = '';
        return true;
      } catch {
        if (request === this.staffAvailabilityRequest) this.staffAvailabilityError = 'Could not check available stylists. Please try again.';
        return false;
      } finally {
        if (request === this.staffAvailabilityRequest) this.staffAvailabilityLoading = false;
      }
    },
    clearScheduleStaff() {
      this.staffAvailabilityRequest++;
      this.bookingForm.staffId = '';
      this.waitlistStaffId = '';
      this.liveAvailableStaff = [];
      this.liveBusyStaff = [];
      this.staffAvailabilityLoading = false;
      this.staffAvailabilityError = '';
    },
    selectScheduleTime(slot) {
      this.clearScheduleStaff();
      this.bookingForm.time = slot;
      return this.refreshAvailableStaff();
    },
    getAvailableStaffForBranch() { return this.liveAvailableStaff; },
    getBusyStaffForBranch() { return this.liveBusyStaff; },
    getStylistOptions() {
      return [...this.liveAvailableStaff, ...this.liveBusyStaff].sort((a, b) => a.name.localeCompare(b.name));
    },
    notifyStylistOpening(staff) {
      if (staff.available || this.isJoiningWaitlist) return;
      this.waitlistStaffId = staff.id;
      return this.joinStylistWaitlist();
    },

    waitlistStaffId: '',
    isJoiningWaitlist: false,
    joinStylistWaitlist() {
      if (!this.waitlistStaffId) return this.pushToast('error', 'Choose the stylist you want to wait for.');
      this.isJoiningWaitlist = true;
      const formData = new FormData();
      formData.append('branch', this.selectedBranchForServices);
      formData.append('employeeId', this.waitlistStaffId);
      formData.append('date', this.bookingForm.date);
      formData.append('time', this.bookingForm.time);
      // Name/email come from the logged-in account on the backend.
      fetch('../../public/backend/joinWaitlist.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(body => this.pushToast(body.success ? 'success' : 'error', body.message || 'Could not join the waitlist.'))
        .catch(() => this.pushToast('error', 'A network error occurred. Please try again.'))
        .finally(() => { this.isJoiningWaitlist = false; });
    },

    submitBooking() {
      if (this.isSubmittingBooking) return;
      this.bookingError = '';
      if (!BookingFormValidation.fields([
        ['[x-model="bookingForm.customerName"]', 'name'],
        ['[x-model="bookingForm.customerPhone"]', 'mobile'],
        ['[x-model="bookingForm.date"]', 'date']
      ])) return;
      if (this.selectedServicesForBooking.length === 0) return this.pushToast('error', 'Please select at least one service to book.');
      if (!this.bookingForm.date || !this.bookingForm.time) return this.pushToast('error', 'Please select a date and time for your appointment.');
      if (!this.depositLocked) return this.pushToast('error', 'Please submit your reservation deposit before confirming.');
      if (!this.bookingForm.customerName || !this.bookingForm.customerPhone) return this.pushToast('error', 'Please provide your name and contact number.');
      if (!/^09\d{9}$/.test(this.bookingForm.customerPhone)) return this.pushToast('error', 'Please enter a valid mobile number (e.g. 09XXXXXXXXX).');
      // Client-side preview re-check — the authoritative check happens on
      // the backend inside a locking transaction right before it inserts
      // the appointment (see customer/backend/submitBooking.php), since the
      // slot could have been taken by someone else in the time it took the
      // customer to get through the rest of this form.
      if (!this.isSlotAvailableNow(this.bookingForm.time)) {
        this.bookingStep = 'schedule';
        return this.pushToast('error', 'That time was just booked. Please choose another available schedule.');
      }
      if (!this.bookingForm.agreedToTerms) return this.pushToast('error', 'Please agree to the Terms & Conditions to continue.');

      this.isSubmittingBooking = true;

      const formData = new FormData();
      formData.append('branch', this.selectedBranchForServices);
      this.selectedServicesForBooking.forEach(s => formData.append('serviceIds[]', s.id));
      formData.append('date', this.bookingForm.date);
      formData.append('time', this.bookingForm.time);
      formData.append('staffId', this.bookingForm.staffId);
      formData.append('customerName', this.bookingForm.customerName);
      formData.append('customerPhone', this.bookingForm.customerPhone);
      formData.append('paymentMethod', this.bookingForm.paymentMethod);
      if (!this.reservationQuote) {
        this.isSubmittingBooking = false;
        this.bookingStep = 'review';
        return this.pushToast('error', 'Please review your reservation payment before booking.');
      }
      formData.append('quoteToken', this.reservationQuote.quoteToken);
      formData.append('depositReference', this.bookingForm.depositReference);
      formData.append('useLoyaltyPoints', this.bookingForm.useLoyaltyPoints ? '1' : '0');
      formData.append('paymentPlan', this.bookingForm.paymentPlan || 'deposit');
      formData.append('agreedToTerms', this.bookingForm.agreedToTerms ? '1' : '0');

      fetch('../../customer/backend/submitBooking.php', { method: 'POST', body: formData })
        .then(response => response.json().then(body => ({ status: response.status, body })))
        .then(({ body }) => {
          this.isSubmittingBooking = false;

          if (!body.success) {
            this.bookingError = body.message || 'Something went wrong while booking your appointment.';
            this.pushToast('error', this.bookingError);
            if (body.errors?.customerName || body.errors?.customerPhone) this.bookingStep = 'review';
            // The backend found the slot taken (or the previously qualifying
            // stylist no longer free) in the moments between this form
            // loading and submitting — send the customer back to reschedule
            // instead of leaving them stuck on a dead time slot.
            if (body.quoteChanged) {
              this.reservationQuote = null;
              this.depositLocked = false;
              this.bookingStep = 'review';
            }
            if (body.conflict) {
              this.bookingStep = 'schedule';
              this.refreshAvailableSlots();
            }
            return;
          }

          // Online payment: hand off to PayMongo's hosted checkout, which
          // returns to public/frontend/payment-result.html when done.
          if (body.checkoutUrl) {
            this.isSubmittingBooking = true;
            window.location.href = body.checkoutUrl;
            return;
          }

          const appt = body.appointment;
          this.appointments.unshift(appt);
          this.allBookings.push({
            id: appt.id,
            branchId: appt.branchId,
            staffId: appt.staffId || null,
            date: appt.date,
            time: appt.time,
            status: appt.status,
            durationMinutes: appt.durationMinutes
          });
          this.customerProfile.loyaltyPoints = body.loyaltyPoints;
          this.animateCount('displayLoyaltyPoints', body.loyaltyPoints, 500);
          this.animateCount('displayServiceCount', countCompletedServices(this.appointments), 500);
          this.sendBookingConfirmation(appt);

          this.bookingForm.useLoyaltyPoints = false;
          this.bookingSuccessDetails = appt;
          this.showBookingForm = false;
          this.showBookingSuccess = true;
          this.pushToast('success', body.message || 'Your booking request has been sent!');
        })
        .catch(() => {
          this.isSubmittingBooking = false;
          this.bookingError = 'Could not send your booking. Your details are saved here. Please check your connection and try again.';
          this.pushToast('error', this.bookingError);
        });
    }
  };
}
