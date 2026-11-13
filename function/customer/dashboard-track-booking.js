// Inline Track Booking page and the legacy modal shared with
// frontend/customer/home-service.html. Reuses the public trackBooking.php
// endpoint (no login required there) so a logged-in customer can look up
// any booking by reference + phone -- including ones made as a guest
// before they had an account -- without needing a separate customer-only
// backend endpoint.

function customerTrackBookingMixin() {
  return {
    isTrackModalOpen: false,
    trackingSelection: null,
    isTrackingView: new URLSearchParams(window.location.search).get('track') === '1',
    isGuestTracking: false,
    trackingLookup: null,
    guestReschedule: { email: '', date: '', time: '' },
    guestRescheduling: false,
    guestRescheduleMessage: '',
    async rescheduleTrackedHomeService() {
      if (this.guestRescheduling || !this.trackResult?.canReschedule) return;
      const reference = this.trackResult.reference;
      this.guestRescheduling = true;
      this.guestRescheduleMessage = '';
      try {
        const data = new FormData();
        data.append('reference', reference);
        for (const [key, value] of Object.entries(this.guestReschedule)) data.append(key, value.trim());
        const response = await fetch('../../backend/public/rescheduleHomeService.php', { method: 'POST', body: data });
        const body = await response.json();
        if (!response.ok || !body.success) throw new Error(body.message || 'Unable to reschedule.');
        if (this.trackingLookup?.reference.toUpperCase() === reference.toUpperCase()) {
          this.trackForm = { ...this.trackingLookup };
          await this.trackBooking();
        }
        this.guestRescheduleMessage = body.message || 'Booking rescheduled.';
      } catch (error) { this.guestRescheduleMessage = error.message || 'Unable to reschedule. Please try again.'; }
      finally { this.guestRescheduling = false; }
    },
    trackingRefreshLoading: false,
    trackingRefreshError: '',
    selectedTracking() {
      if (!this.trackingSelection) return this.trackResult;
      const source = this.trackingSelection.type === 'home_service' ? this.homeServiceRequests : this.appointments;
      const row = source.find(a => a.id === this.trackingSelection.id);
      if (!row) return null;
      return this.trackingSelection.type === 'home_service'
        ? { ...row, type: 'home_service', reference: row.id, services: this.homeServiceDetails(row).services || row.eventType,
            branch: row.branchName, date: row.preferredDate, time: this.homeServiceTime(row), venue: row.address,
            price: row.quotePrice, remainingBalance: row.balanceDue }
        : { ...row, type: 'appointment', reference: row.id, services: row.serviceName, branch: row.branchName };
    },
    viewTracking(row, type = 'appointment') {
      if (document.getElementById('find-booking') && !this.isTrackingView) {
        window.location.href = 'appointments.html?track=1&reference=' + encodeURIComponent(row.id);
        return;
      }
      this.guestRescheduleMessage = '';
      this.trackingSelection = { id: row.id, type };
      this.trackResult = null;
      this.trackError = '';
      this.$nextTick(() => {
        // A payment notification (…&focus=payment) lands on that booking's Payment Details.
        const params = new URLSearchParams(window.location.search);
        const payment = params.get('focus') === 'payment' && params.get('reference') === row.id && document.getElementById('tracking-payment-details');
        if (payment) {
          payment.scrollIntoView({ behavior: 'smooth', block: 'start' });
          payment.focus({ preventScroll: true });
          return;
        }
        const panel = document.getElementById('booking-tracking-details');
        panel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        panel?.focus({ preventScroll: true });
      });
    },
    trackingHomeRequests() {
      return this.homeServiceRequests.filter(r => {
        const a = { ...r, date: r.preferredDate, serviceName: r.eventType };
        return this.isPastBooking(a) === (this.appointmentView === 'past') && this.matchesAppointmentSearch(a);
      });
    },
    trackingSteps(booking) {
      const status = booking.status === 'Reviewed' ? 'Completed' : booking.status;
      if (['Cancelled', 'No-Show', 'Rejected', 'Reschedule Requested', 'Reschedule Required'].includes(status))
        return [{ label: status, current: true }];
      const steps = booking.type === 'home_service'
        ? ['Pending Review', 'Quote Ready', 'Payment Required', 'Payment Being Verified', 'Confirmed', 'Staff Assigned', 'Service in Progress', 'Completed']
        : ['Pending', 'Confirmed', 'In Progress', 'Completed'];
      if (!steps.includes(status)) return [{ label: status, current: true }];
      return steps.map(label => ({ label, current: label === status }));
    },
    trackingStatusNote(status) {
      return {
        'Cancelled': 'This booking has been cancelled. See payment details for any retained deposit.',
        'No-Show': 'The salon marked this appointment as a no-show. Contact the branch for assistance.',
        'Rejected': 'This request was rejected. Contact the branch for details.',
        'Reschedule Requested': 'The salon requested a schedule change. Open My Bookings to choose another time.',
        'Reschedule Required': 'A schedule change is required. Contact the branch for assistance.',
        'Completed': 'Your service is complete.',
        'Reviewed': 'Your service is complete and your review has been recorded.'
      }[status] || 'Latest status reported by the salon. Steps show the workflow, not a dated event history.';
    },
    async refreshTracking() {
      if (this.trackingRefreshLoading) return;
      if (this.isGuestTracking) { if (this.trackingLookup) { this.trackForm = { ...this.trackingLookup }; return this.trackBooking(); } return; }
      this.trackingRefreshLoading = true;
      this.trackingRefreshError = '';
      try {
        const response = await fetch('../../backend/customer/getDashboardData.php', { cache: 'no-store' });
        const body = await response.json();
        if (!response.ok || !body.success) throw new Error(body.message || 'Unable to refresh. Please sign in again if your session expired.');
        this.appointments = body.appointments;
        this.homeServiceRequests = body.homeServiceRequests;
        this.loadServerNotifications(body.notifications);
        if (!this.trackingSelection && this.trackResult && this.trackingLookup) { this.trackForm = { ...this.trackingLookup }; await this.trackBooking(); }
      } catch (error) { this.trackingRefreshError = error.message || 'Unable to refresh. Please try again.'; }
      finally { this.trackingRefreshLoading = false; }
    },
    trackForm: { reference: '', phone: '' },
    trackLoading: false,
    trackError: '',
    trackResult: null,

    openTrackModal() {
      if (document.getElementById('find-booking')) {
        document.getElementById('find-booking').scrollIntoView({ behavior: 'smooth' });
        document.getElementById('tracking-reference')?.focus();
        return;
      }
      this.trackForm = { reference: '', phone: this.customerProfile.phone || '' };
      this.trackResult = null;
      this.trackError = '';
      this.isTrackModalOpen = true;
    },

    closeTrackModal() {
      this.isTrackModalOpen = false;
    },

    trackBooking() {
      if (this.trackLoading) return;
      this.guestRescheduleMessage = '';
      if (document.getElementById('find-booking')) {
        const owned = [...this.appointments, ...this.homeServiceRequests].find(a => a.id.toUpperCase() === this.trackForm.reference.trim().toUpperCase());
        if (owned) { this.viewTracking(owned, owned.preferredDate !== undefined ? 'home_service' : 'appointment'); return; }
      }
      // Only the reference is asked for; the account's own mobile number (set in
      // trackForm.phone) is sent along so a guest booking made with it shows in full.
      if (!this.trackForm.reference.trim()) {
        this.trackResult = null;
        this.trackingSelection = null;
        this.trackError = 'Please enter your booking reference.';
        return;
      }
      this.trackingSelection = null;
      this.trackingLookup = { reference: this.trackForm.reference.trim(), phone: (this.trackForm.phone || '').trim() };
      this.trackLoading = true;
      this.trackError = '';
      this.trackResult = null;

      const params = new URLSearchParams({
        reference: this.trackingLookup.reference,
        phone: this.trackingLookup.phone
      });

      return fetch('../../backend/public/trackBooking.php?' + params.toString(), { cache: 'no-store' })
        .then(response => response.json())
        .then(body => {
          this.trackLoading = false;
          if (!body.success) {
            this.trackError = body.message || 'No booking found with that reference.';
            return;
          }
          if (this.trackingSelection) return;
          this.trackResult = body;
          this.$nextTick(() => document.getElementById('booking-tracking-details')?.focus());
        })
        .catch(() => {
          this.trackLoading = false;
          this.trackError = 'A network error occurred while looking up your booking.';
        });
    },

    getTrackStatusBadgeClass(status) {
      const classes = {
        'Pending': 'bg-amber-950 text-amber-400 border border-[#774800]/60',
        'Pending Review': 'bg-amber-950 text-amber-400 border border-[#774800]/60',
        'Confirmed': 'bg-indigo-950 text-indigo-400 border border-[#1A547A]/60',
        'In Progress': 'bg-purple-950 text-purple-300 border border-[#1A547A]/60',
        'Completed': 'bg-emerald-950 text-emerald-400 border border-[#115C39]/60',
        'Cancelled': 'bg-rose-950 text-rose-400 border border-[#9C1E15]/60',
        'Reviewed': 'bg-slate-800 text-slate-300 border border-slate-700'
      };
      return classes[status] || 'bg-slate-800 text-slate-300';
    }
  };
}
