function adminBookingsApp() {
  return {
    bookingFilterBranch: 'all',
    bookingFilterStatus: 'all',
    bookingSearch: '',
    bookingFilterPayment: 'all',
    bookingDateFrom: '',
    bookingDateTo: '',
    bookingLoading: false,
    bookingError: '',
    bookingRequest: 0,
    detailsBooking: null,
    exporting: false,
    initialized: false,
    filterTimer: null,

    openStaffAssignModal: false,
    staffAssignBooking: null,
    selectedStaffId: '',
    selectedStaffIds: [],

    openCheckoutModalFlag: false,
    checkoutBooking: null,

    openDepositModal: false,
    depositBooking: null,
    depositAmount: '',
    depositMethod: '',

    openHomeServiceQuoteModal: false,
    quoteBooking: null,
    quotePrice: '',
    reservationFee: '',

    init() {
      if (this.initialized) return;
      this.initialized = true;
      adminHighlightNav();
      for (const field of ['bookingFilterBranch','bookingFilterStatus','bookingFilterPayment','bookingSearch','bookingDateFrom','bookingDateTo']) {
        this.$watch(field, () => { clearTimeout(this.filterTimer); this.filterTimer = setTimeout(() => this.loadBookings(1), 0); });
      }
      this.$watch('$store.admin.bookings', () => {
        if (this.detailsBooking) {
          const updated = Alpine.store('admin').bookings.find(booking => booking.id === this.detailsBooking.id);
          if (updated) this.detailsBooking = updated;
          else this.$refs.bookingDetails.close();
        }
      });
    },

    getFilteredBookings() {
      return Alpine.store('admin').bookings;
    },

    get pagination() { return Alpine.store('admin').bookingPagination || { page: 1, pageSize: 10, total: 0, totalPages: 1 }; },
    get pageNumbers() {
      const { page, totalPages } = this.pagination;
      const first = Math.max(1, Math.min(page - 2, totalPages - 4));
      return Array.from({length: Math.min(5, totalPages)}, (_, index) => first + index);
    },
    filterQuery(page = 1, pageSize = 10) {
      return { bookingPage: page, pageSize, search: this.bookingSearch.trim(), branch: this.bookingFilterBranch,
        status: this.bookingFilterStatus, paymentStatus: this.bookingFilterPayment, dateFrom: this.bookingDateFrom, dateTo: this.bookingDateTo };
    },
    async loadBookings(page = 1) {
      const request = ++this.bookingRequest;
      if (this.bookingDateFrom && this.bookingDateTo && this.bookingDateFrom > this.bookingDateTo) {
        this.bookingLoading = false;
        this.bookingError = 'The end date must be on or after the start date.'; return;
      }
      const store = Alpine.store('admin');
      const query = this.filterQuery(page);
      store.bookingQuery = query;
      this.bookingLoading = true; this.bookingError = '';
      try {
        const response = await fetch(ADMIN_API_BASE + 'getDashboardData.php?' + new URLSearchParams({...query, bookingsOnly: 1}), {credentials:'same-origin'});
        if (response.status === 401) { window.location.href = '../../frontend/auth/login.html'; return; }
        const data = await response.json();
        if (request !== this.bookingRequest) return;
        if (!data.success) throw new Error(data.message || 'Could not load bookings.');
        store.bookings = data.bookings;
        store.bookingPagination = data.bookingPagination;
        store.bookingQuery = {...query, bookingPage: data.bookingPagination.page};
      } catch (error) { if (request === this.bookingRequest) this.bookingError = error.message || 'Could not load bookings. Please retry.'; }
      finally { if (request === this.bookingRequest) this.bookingLoading = false; }
    },
    resetFilters() {
      this.bookingSearch = ''; this.bookingFilterBranch = 'all'; this.bookingFilterStatus = 'all';
      this.bookingFilterPayment = 'all'; this.bookingDateFrom = ''; this.bookingDateTo = '';
      clearTimeout(this.filterTimer);
      this.filterTimer = setTimeout(() => this.loadBookings(1), 0);
    },
    openBookingDetails(booking) {
      this.detailsBooking = booking;
      this.$nextTick(() => this.$refs.bookingDetails.showModal());
    },

    /* Each branch has its own configured concurrent-booking capacity (see
       backend/shared/config/booking/Scheduling.php's BOOKING_SLOT_LIMITS) -- never one
       flat number for every branch. */
    getCurrentSlotLimitLabel() {
      if (this.bookingFilterBranch === 'all') return 'Varies by branch';
      if (this.bookingFilterBranch === 'off-site') return 'Not applicable (off-site)';
      const branch = Alpine.store('admin').branches.find(b => b.id === this.bookingFilterBranch);
      return branch ? branch.slotLimit + ' concurrent' : '—';
    },

    getServicesForBranch(branchId) {
      return Alpine.store('admin').servicesByBranch[branchId] || [];
    },

    getAssignableStaff() {
      const store = Alpine.store('admin');
      if (!this.staffAssignBooking) return [];
      // Home service jobs are off-site, not tied to one branch's roster --
      // any active employee can be sent, so skip the branch filter.
      if (this.staffAssignBooking.isHomeService) return store.staffList;
      return store.staffList.filter(s => s.branchId === this.staffAssignBooking.branchId);
    },

    openStaffAssign(booking) {
      this.staffAssignBooking = booking;
      this.selectedStaffId = booking.staffId || '';
      // Home service: a whole team can be picked (checkboxes).
      this.selectedStaffIds = booking.isHomeService ? [...(booking.staffIds || [])] : [];
      this.openStaffAssignModal = true;
    },

    // Client count parsed from the request ("Number of clients: 7") -- used
    // to suggest how many stylists to send.
    homeServiceClientCount(booking) {
      const match = String(booking?.requests || '').match(/Number of clients:\s*(\d+)/i);
      return match ? Number(match[1]) : null;
    },

    canConfirmStaffAssignment() {
      if (!this.staffAssignBooking) return false;
      return this.staffAssignBooking.isHomeService ? this.selectedStaffIds.length > 0 : !!this.selectedStaffId;
    },

    async assignStaff() {
      const store = Alpine.store('admin');
      if (!this.canConfirmStaffAssignment()) return;
      const isHome = this.staffAssignBooking.isHomeService;
      const result = await store.post('assignStaff.php', isHome
        ? { id: this.staffAssignBooking.id, staffIds: this.selectedStaffIds }
        : { id: this.staffAssignBooking.id, staffId: this.selectedStaffId });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to assign staff.', 'error');
        return;
      }
      await store.refresh();
      this.openStaffAssignModal = false;
      if (isHome && result.message) LMToast.show(result.message, 'success');
    },

    // A cancelled booking whose deposit is 'Refund Due' (cancellation policy).
    // PayMongo deposits are refunded through PayMongo's API; Cash deposits are
    // returned in person and only recorded here (backend/admin/refundDeposit.php).
    isPaymongoDeposit(booking) {
      return String(booking.depositMethod || '').startsWith('PayMongo') && String(booking.depositReference || '').startsWith('pay_');
    },

    refundButtonLabel(booking) {
      if (booking.paymentStatus === 'Refund Processing') return 'Check refund status';
      return this.isPaymongoDeposit(booking) ? 'Refund via PayMongo' : 'Mark deposit refunded';
    },

    async refundDeposit(booking) {
      if (booking.paymentStatus === 'Refund Due') {
        const amount = Alpine.store('admin').formatCurrency(booking.depositAmount);
        const question = this.isPaymongoDeposit(booking)
          ? 'Refund ' + amount + ' for ' + booking.id + ' through PayMongo? The money goes back to the customer\'s GCash, Maya or card. This cannot be undone.'
          : 'Mark the ' + amount + ' deposit for ' + booking.id + ' as refunded? Only do this after the cash has been returned to the customer.';
        if (!confirm(question)) return;
      }
      const store = Alpine.store('admin');
      const result = await store.post('refundDeposit.php', { reference: booking.id });
      LMToast.show(result.message || (result.success ? 'Done.' : 'Failed to refund the deposit.'), result.success ? 'success' : 'error');
      if (result.success) await store.refresh();
    },

    async updateBookingStatus(id, newStatus) {
      const store = Alpine.store('admin');
      if (newStatus === 'In Progress') {
        if (await AppointmentArrival.review(id, 'backend/admin/updateBookingStatus.php')) await store.refresh();
        return;
      }
      if (['Cancelled', 'No-Show'].includes(newStatus) && !confirm(
        `Mark this booking ${newStatus}? Confirm the customer is absent before choosing No-Show; an arrived-but-late customer should use Review Arrival instead. The existing non-refundable deposit policy applies only if you confirm this closure.`)) return;
      const result = await store.post('updateBookingStatus.php', { id, status: newStatus });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to update booking status.', 'error');
        return;
      }
      await store.refresh();
    },

    openConfirmDeposit(booking) {
      const allowedDepositMethods = ['Cash', 'GCash', 'Maya', 'PayMongo', 'PayMongo-GCash', 'PayMongo-Maya', 'PayMongo-Card'];
      this.depositBooking = booking;
      // Bookings made through the online deposit-first flow already carry a
      // self-reported deposit amount/method (unverified until this confirm
      // action) -- prefill from that. Older/off-flow bookings with no
      // deposit info fall back to the full price as a starting point.
      this.depositAmount = booking.depositAmount || booking.price || '';
      const method = booking.depositMethod || booking.paymentMethod; // The recorded online method wins over the booked one.
      this.depositMethod = allowedDepositMethods.includes(method) ? method : '';
      this.openDepositModal = true;
    },

    async confirmWithDeposit() {
      if (!BookingFormValidation.fields([['[x-model="depositAmount"]', 'money']])) return;
      const store = Alpine.store('admin');
      if (!this.depositBooking || !this.depositAmount || !this.depositMethod) return;

      // Home service has no online payment step -- this modal is reused to
      // record a reservation payment self-reported by phone/in person
      // (backend/admin/setHomeServiceQuote.php's recordPayment action),
      // rather than confirming the request outright like a salon booking.
      const result = this.depositBooking.isHomeService
        ? await store.post('setHomeServiceQuote.php', {
            id: this.depositBooking.id,
            action: 'recordPayment',
            depositAmount: this.depositAmount,
            depositMethod: this.depositMethod
          })
        : await store.post('updateBookingStatus.php', {
            id: this.depositBooking.id,
            status: 'Confirmed',
            depositAmount: this.depositAmount,
            depositMethod: this.depositMethod
          });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to confirm booking.', 'error');
        return;
      }
      await store.refresh();
      this.openDepositModal = false;
    },

    openHomeServiceQuote(booking) {
      this.quoteBooking = booking;
      this.quotePrice = booking.quotePrice || '';
      this.reservationFee = booking.depositAmount || '';
      this.openHomeServiceQuoteModal = true;
    },

    async submitHomeServiceQuote() {
      if (!BookingFormValidation.fields([['[x-model="quotePrice"]', 'money'], ['[x-model="reservationFee"]', 'money', false]])) return;
      const store = Alpine.store('admin');
      if (!this.quoteBooking || !this.quotePrice) return;
      const result = await store.post('setHomeServiceQuote.php', {
        id: this.quoteBooking.id,
        action: 'quote',
        quotePrice: this.quotePrice,
        reservationFee: this.reservationFee || 0
      });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to save quote.', 'error');
        return;
      }
      await store.refresh();
      this.openHomeServiceQuoteModal = false;
      // Tells the admin whether a PayMongo link went out (or why not).
      if (result.message) LMToast.show(result.message, 'info');
    },

    /* ---- Home service remaining balance (backend/admin/homeServiceBalance.php) ---- */

    // Shown once the quote is set and the DP is settled (or none was needed).
    homeServiceBalanceOpen(booking) {
      return booking.isHomeService && booking.quotePrice && booking.status !== 'Cancelled'
        && ['Quote Ready', 'Confirmed', 'Staff Assigned', 'Service in Progress', 'Completed'].includes(booking.status);
    },

    collectBalanceBooking: null,
    collectBalanceAmount: '',
    collectBalanceMethod: 'Cash',
    collectBalanceReference: '',
    openCollectBalance(booking) {
      this.collectBalanceBooking = booking;
      this.collectBalanceAmount = booking.remainingBalance;
      this.collectBalanceMethod = 'Cash';
      this.collectBalanceReference = '';
    },
    async submitCollectBalance() {
      const booking = this.collectBalanceBooking;
      if (!booking) return;
      const store = Alpine.store('admin');
      const result = await store.post('homeServiceBalance.php', {
        id: booking.id, action: 'collect', amount: this.collectBalanceAmount,
        method: this.collectBalanceMethod, reference: this.collectBalanceReference
      });
      LMToast.show(result.message || (result.success ? 'Payment recorded.' : 'Failed to record the payment.'), result.success ? 'success' : 'error');
      if (!result.success) return;
      this.collectBalanceBooking = null;
      await store.refresh();
    },
    // Don't let a home service close quietly with money still owed.
    async completeHomeService(booking) {
      if (booking.quotePrice && booking.remainingBalance > 0) {
        const amount = Alpine.store('admin').formatCurrency(booking.remainingBalance);
        if (!confirm(`${amount} is still unpaid on ${booking.id}.\n\nOK = mark it Completed anyway (you can still collect the balance afterwards)\nCancel = go back and use "Collect Balance" first`)) return;
      }
      await this.updateBookingStatus(booking.id, 'Completed');
    },

    async sendBalanceLink(booking) {
      const amount = Alpine.store('admin').formatCurrency(booking.remainingBalance);
      if (!confirm(`Send ${booking.clientName} a PayMongo link to pay the remaining ${amount} online?`)) return;
      const store = Alpine.store('admin');
      const result = await store.post('homeServiceBalance.php', { id: booking.id, action: 'sendLink' });
      LMToast.show(result.message || (result.success ? 'Link sent.' : 'Failed to send the link.'), result.success ? 'success' : 'error');
      if (result.success) await store.refresh();
    },
    async editHomeServiceQuote(booking) {
      const store = Alpine.store('admin');
      const value = prompt(`Correct the final quote for ${booking.id} (already paid: ${store.formatCurrency(booking.amountPaid)}):`, booking.quotePrice);
      if (value === null) return;
      const result = await store.post('homeServiceBalance.php', { id: booking.id, action: 'editQuote', quotePrice: value.trim() });
      LMToast.show(result.message || (result.success ? 'Quote updated.' : 'Failed to update the quote.'), result.success ? 'success' : 'error');
      if (result.success) await store.refresh();
    },

    async rejectHomeService(booking) {
      if (!confirm(`Reject home service request ${booking.id}? The customer will be notified.`)) return;
      await this.updateBookingStatus(booking.id, 'Cancelled');
    },

    async requestHomeServiceScheduleChange(booking) {
      const note = prompt('Note to the customer about the schedule change (optional):', '');
      if (note === null) return;
      const store = Alpine.store('admin');
      const result = await store.post('setHomeServiceQuote.php', { id: booking.id, action: 'requestScheduleChange', note });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to notify the customer.', 'error');
        return;
      }
      await store.refresh();
    },

    openCheckoutModal(booking) {
      this.checkoutBooking = booking;
      this.openCheckoutModalFlag = true;
    },

    async completeCheckout() {
      const store = Alpine.store('admin');
      if (!this.checkoutBooking) return;
      const result = await store.post('completeCheckout.php', { id: this.checkoutBooking.id });
      if (!result.success) {
        LMToast.show(result.message || 'Failed to complete checkout.', 'error');
        return;
      }
      await store.refresh();
      this.openCheckoutModalFlag = false;
    },

    async exportBookingsToCSV() {
      const store = Alpine.store('admin');
      if (this.exporting || this.bookingError) return;
      this.exporting = true;
      try {
      const query = this.filterQuery(1, 100);
      const bookings = [];
      let totalPages = 1;
      for (let page = 1; page <= totalPages; page++) {
        const response = await fetch(ADMIN_API_BASE + 'getDashboardData.php?' + new URLSearchParams({...query, bookingPage: page, bookingsOnly: 1}), {credentials:'same-origin'});
        if (response.status === 401) { window.location.href = '../../frontend/auth/login.html'; return; }
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Export failed.');
        bookings.push(...data.bookings);
        totalPages = data.bookingPagination.totalPages;
      }
      const rows = bookings.map(b => [
        b.id, b.isHomeService ? 'Off-Site' : store.getBranchName(b.branchId), b.clientName, b.clientPhone,
        b.serviceName, b.staffName || 'Unassigned', b.date, b.time, b.endTime || '', b.durationMinutes || '',
        b.bookingType, b.status, b.paymentStatus || '', b.isHomeService ? '' : b.price
      ]);
      store.exportRowsToCSV('bookings-export.csv',
        ['Ref ID', 'Branch', 'Client', 'Phone', 'Service', 'Staff', 'Date', 'Start Time', 'End Time', 'Duration (min)', 'Type', 'Status', 'Payment Status', 'Price'],
        rows);
      } catch (error) { LMToast.show(error.message || 'Could not export bookings.', 'error'); }
      finally { this.exporting = false; }
    }
  };
}
