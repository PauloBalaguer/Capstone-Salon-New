function adminBookingsApp() {
  return {
    bookingFilterBranch: 'all',
    bookingFilterStatus: 'all',

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
      adminHighlightNav();
    },

    getFilteredBookings() {
      const store = Alpine.store('admin');
      let list = store.bookings;
      if (this.bookingFilterBranch !== 'all') list = list.filter(b => b.branchId === this.bookingFilterBranch);
      if (this.bookingFilterStatus === 'refunds') list = list.filter(b => ['Refund Due', 'Refund Processing'].includes(b.paymentStatus));
      else if (this.bookingFilterStatus !== 'all') list = list.filter(b => b.status === this.bookingFilterStatus);
      return list;
    },

    /* Each branch has its own configured concurrent-booking capacity (see
       backend/config/Scheduling.php's BOOKING_SLOT_LIMITS) -- never one
       flat number for every branch. */
    getCurrentSlotLimitLabel() {
      if (this.bookingFilterBranch === 'all') return 'Varies by branch';
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
        alert(result.message || 'Failed to assign staff.');
        return;
      }
      await store.refresh();
      this.openStaffAssignModal = false;
      if (isHome && result.message) alert(result.message);
    },

    // A cancelled booking whose deposit is 'Refund Due' (cancellation policy).
    // PayMongo deposits are refunded through PayMongo's API; Cash deposits are
    // returned in person and only recorded here (backend/admin/refundDeposit.php).
    isPaymongoDeposit(booking) {
      return booking.depositMethod === 'PayMongo' && String(booking.depositReference || '').startsWith('pay_');
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
      alert(result.message || (result.success ? 'Done.' : 'Failed to refund the deposit.'));
      if (result.success) await store.refresh();
    },

    async updateBookingStatus(id, newStatus) {
      const store = Alpine.store('admin');
      const result = await store.post('updateBookingStatus.php', { id, status: newStatus });
      if (!result.success) {
        alert(result.message || 'Failed to update booking status.');
        return;
      }
      await store.refresh();
    },

    openConfirmDeposit(booking) {
      const allowedDepositMethods = ['Cash', 'GCash', 'Maya', 'PayMongo'];
      this.depositBooking = booking;
      // Bookings made through the online deposit-first flow already carry a
      // self-reported deposit amount/method (unverified until this confirm
      // action) -- prefill from that. Older/off-flow bookings with no
      // deposit info fall back to the full price as a starting point.
      this.depositAmount = booking.depositAmount || booking.price || '';
      this.depositMethod = allowedDepositMethods.includes(booking.paymentMethod) ? booking.paymentMethod : '';
      this.openDepositModal = true;
    },

    async confirmWithDeposit() {
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
        alert(result.message || 'Failed to confirm booking.');
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
      const store = Alpine.store('admin');
      if (!this.quoteBooking || !this.quotePrice) return;
      const result = await store.post('setHomeServiceQuote.php', {
        id: this.quoteBooking.id,
        action: 'quote',
        quotePrice: this.quotePrice,
        reservationFee: this.reservationFee || 0
      });
      if (!result.success) {
        alert(result.message || 'Failed to save quote.');
        return;
      }
      await store.refresh();
      this.openHomeServiceQuoteModal = false;
      // Tells the admin whether a PayMongo link went out (or why not).
      if (result.message) alert(result.message);
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
      alert(result.message || (result.success ? 'Payment recorded.' : 'Failed to record the payment.'));
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
      alert(result.message || (result.success ? 'Link sent.' : 'Failed to send the link.'));
      if (result.success) await store.refresh();
    },
    async editHomeServiceQuote(booking) {
      const store = Alpine.store('admin');
      const value = prompt(`Correct the final quote for ${booking.id} (already paid: ${store.formatCurrency(booking.amountPaid)}):`, booking.quotePrice);
      if (value === null) return;
      const result = await store.post('homeServiceBalance.php', { id: booking.id, action: 'editQuote', quotePrice: value.replace(/[^0-9.]/g, '') });
      alert(result.message || (result.success ? 'Quote updated.' : 'Failed to update the quote.'));
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
        alert(result.message || 'Failed to notify the customer.');
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
        alert(result.message || 'Failed to complete checkout.');
        return;
      }
      await store.refresh();
      this.openCheckoutModalFlag = false;
    },

    exportBookingsToCSV() {
      const store = Alpine.store('admin');
      const rows = this.getFilteredBookings().map(b => [
        b.id, b.isHomeService ? 'Off-Site' : store.getBranchName(b.branchId), b.clientName, b.clientPhone,
        b.serviceName, b.staffName || 'Unassigned', b.date, b.time, b.endTime || '', b.durationMinutes || '',
        b.bookingType, b.status, b.paymentStatus || '', b.isHomeService ? '' : b.price
      ]);
      store.exportRowsToCSV('bookings-export.csv',
        ['Ref ID', 'Branch', 'Client', 'Phone', 'Service', 'Staff', 'Date', 'Start Time', 'End Time', 'Duration (min)', 'Type', 'Status', 'Payment Status', 'Price'],
        rows);
    }
  };
}
