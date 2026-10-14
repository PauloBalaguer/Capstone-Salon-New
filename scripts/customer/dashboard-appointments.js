// Appointment list views, cancellation flow, and status badge styling.

function customerAppointmentsMixin() {
  return {
    matchesAppointmentSearch(appt) {
      const query = (this.appointmentSearchQuery || '').toLowerCase().trim();
      return [appt.id, appt.serviceName, appt.branchName, appt.staffName]
        .some(value => String(value || '').toLowerCase().includes(query));
    },
    getUpcomingAppointments() {
      const now = new Date();
      return this.appointments.filter(appt => {
        const apptDateTime = parseApptDateTime(appt.date, appt.time);
        // Reschedule Requested bookings are still future and still active --
        // leaving them out hid them from both Upcoming and History.
        return apptDateTime >= now && ['Pending', 'Confirmed', 'In Progress', 'Reschedule Requested', 'Reschedule Required'].includes(appt.status)
          && this.matchesAppointmentSearch(appt);
      }).sort((a, b) => parseApptDateTime(a.date, a.time) - parseApptDateTime(b.date, b.time));
    },

    getPastAppointments() {
      const now = new Date();
      let past = this.appointments.filter(appt => {
        const apptDateTime = parseApptDateTime(appt.date, appt.time);
        return apptDateTime < now || ['Completed', 'Cancelled', 'Reviewed'].includes(appt.status);
      });

      if (this.appointmentSearchQuery.trim() !== '') {
        past = past.filter(appt => this.matchesAppointmentSearch(appt));
      }

      return past.sort((a, b) => parseApptDateTime(b.date, b.time) - parseApptDateTime(a.date, a.time));
    },

    getNextAppointment() {
      return this.getUpcomingAppointments()[0] || null;
    },

    // Asks the backend what the cancellation policy would do to this
    // booking's deposit (backend/customer/cancelAppointment.php preview=1)
    // so the dialog can warn before the customer confirms.
    cancelPreview: null,
    openCancelModal(appointmentId) {
      this.appointmentToCancelId = appointmentId;
      this.cancelPreview = null;
      this.isCancelModalOpen = true;
      const formData = new FormData();
      formData.append('appointment_id', appointmentId);
      formData.append('preview', '1');
      fetch('../../backend/customer/cancelAppointment.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(body => { if (body.success && this.appointmentToCancelId === appointmentId) this.cancelPreview = body; })
        .catch(() => {});
    },
    closeCancelModal() {
      this.isCancelModalOpen = false;
      this.appointmentToCancelId = null;
      this.cancelPreview = null;
    },
    cancelDepositWarning() {
      const p = this.cancelPreview;
      if (!p || !p.depositOutcome) return '';
      const amount = '₱' + Number(p.depositAmount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });
      return 'Deposits are non-refundable — your ' + amount + ' deposit will NOT be refunded if you cancel. '
        + 'Reschedule instead to keep using it (you can also reschedule a cancelled booking later).';
    },
    // "Reschedule Instead" from the cancel dialog: swap straight into the
    // reschedule picker for the same booking.
    cancelAppointmentForReschedule() {
      return this.appointments.find(a => a.id === this.appointmentToCancelId) || null;
    },
    rescheduleInsteadOfCancel() {
      const appt = this.cancelAppointmentForReschedule();
      this.closeCancelModal();
      if (appt) this.openRescheduleModal(appt);
    },
    confirmCancelAppointment() {
      if (!this.appointmentToCancelId) return;
      const cancelId = this.appointmentToCancelId;

      const formData = new FormData();
      formData.append('appointment_id', cancelId);

      fetch('../../backend/customer/cancelAppointment.php', { method: 'POST', body: formData })
        .then(response => response.json().then(body => ({ status: response.status, body })))
        .then(({ body }) => {
          if (!body.success) {
            this.pushToast('error', body.message || 'Could not cancel this appointment.');
            return;
          }
          const appt = this.appointments.find(a => a.id === cancelId);
          if (appt) { appt.status = 'Cancelled'; if (body.paymentStatus) appt.paymentStatus = body.paymentStatus; }
          const booking = this.allBookings.find(b => b.id === cancelId);
          if (booking) booking.status = 'Cancelled';
          this.pushToast('success', body.message || 'Your appointment has been cancelled.');
        })
        .catch(() => this.pushToast('error', 'A network error occurred while cancelling your appointment.'))
        .finally(() => this.closeCancelModal());
    },

    // RESCHEDULE -- same open-slot rules as the cashier's reschedule
    // (backend/config/Reschedule.php); the backend also enforces the
    // 24-hour cut-off unless the salon itself asked for the change.
    rescheduleAppt: null,
    // null until the first slot load tells us the booking's current stylist;
    // '' = any available stylist, otherwise an employee id.
    rescheduleStaffId: null,
    rescheduleStylists: [],
    rescheduleCurrentStaffId: null,
    rescheduleDate: '',
    rescheduleTime: '',
    rescheduleSlots: [],
    rescheduleDuration: 0,
    rescheduleLoading: false,
    rescheduleError: '',
    isRescheduling: false,
    rescheduleRequest: 0,
    canReschedule(appt) {
      return ['Pending', 'Confirmed', 'Reschedule Requested'].includes(appt.status) || this.hasKeptDeposit(appt);
    },
    // Cancelled, but the non-refundable deposit was kept -- rescheduling
    // reinstates the booking with it (backend/config/Reschedule.php).
    hasKeptDeposit(appt) {
      return appt.status === 'Cancelled' && ['Forfeited', 'Refund Due'].includes(appt.paymentStatus) && Number(appt.depositAmount) > 0;
    },
    rescheduleMinDate() {
      const d = new Date();
      return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
    },
    openRescheduleModal(appt) {
      this.rescheduleAppt = appt;
      this.rescheduleStaffId = null;
      this.rescheduleStylists = [];
      this.rescheduleCurrentStaffId = null;
      this.rescheduleDate = appt.date >= this.rescheduleMinDate() ? appt.date : this.rescheduleMinDate();
      this.loadRescheduleSlots();
    },
    rescheduleStylistName() {
      const s = this.rescheduleStylists.find(x => x.id === this.rescheduleStaffId);
      return s ? s.name : '';
    },
    // What a greyed-out time means, shown under it.
    slotReasonLabel(slot) {
      if (this.isCurrentSlot(slot.time)) return 'Current';
      return { busy: this.rescheduleStaffId ? 'Stylist busy' : 'All busy', full: 'Full', closed: 'Too late', past: 'Passed' }[slot.reason] || '';
    },
    closeRescheduleModal() {
      this.rescheduleAppt = null;
      this.rescheduleSlots = [];
      this.rescheduleTime = '';
      this.rescheduleError = '';
    },
    rescheduleOpenCount() {
      return this.rescheduleSlots.filter(s => s.available).length;
    },
    // Same date, time and stylist as now -- nothing would change. Picking a
    // different stylist makes the current time selectable again.
    isCurrentSlot(time) {
      const a = this.rescheduleAppt;
      if (!a || this.hasKeptDeposit(a)) return false;
      const sameStylist = !this.rescheduleStaffId || this.rescheduleStaffId === this.rescheduleCurrentStaffId;
      return a.date === this.rescheduleDate && a.time === time && sameStylist;
    },
    loadRescheduleSlots() {
      this.rescheduleTime = '';
      this.rescheduleSlots = [];
      this.rescheduleError = '';
      if (!this.rescheduleAppt || !this.rescheduleDate) return;
      this.rescheduleLoading = true;
      const requestId = ++this.rescheduleRequest;
      const params = new URLSearchParams({ id: this.rescheduleAppt.id, date: this.rescheduleDate });
      // First load defaults to the current stylist, so their busy times show right away.
      const staffId = this.rescheduleStaffId === null ? '' : this.rescheduleStaffId;
      if (staffId) params.set('staffId', staffId);
      fetch('../../backend/customer/rescheduleAppointment.php?' + params.toString())
        .then(r => r.json())
        .then(body => {
          if (requestId !== this.rescheduleRequest) return; // superseded by a newer date/stylist pick
          if (!body.success) { this.rescheduleError = body.message || 'Could not load open times.'; return; }
          this.rescheduleStylists = body.stylists || [];
          this.rescheduleCurrentStaffId = body.currentStaffId;
          if (this.rescheduleStaffId === null) {
            const current = this.rescheduleStylists.some(s => s.id === body.currentStaffId);
            this.rescheduleStaffId = current ? body.currentStaffId : '';
            if (current) { this.loadRescheduleSlots(); return; } // reload for that stylist's own schedule
          }
          this.rescheduleSlots = body.slots;
          this.rescheduleDuration = body.durationMinutes;
        })
        .catch(() => { if (requestId === this.rescheduleRequest) this.rescheduleError = 'A network error occurred. Please try again.'; })
        .finally(() => { if (requestId === this.rescheduleRequest) this.rescheduleLoading = false; });
    },
    rescheduleStatusNote() {
      const a = this.rescheduleAppt;
      if (!a) return '';
      if (this.hasKeptDeposit(a)) {
        return 'This brings your cancelled booking back, and your ' + this.formatPeso(a.depositAmount) + ' deposit is applied to the new time'
          + (a.depositVerified ? ' (status: Confirmed).' : ' (status: Pending until the salon verifies it).');
      }
      if (a.status !== 'Reschedule Requested') return 'Your booking status stays ' + a.status + '.';
      return ['Down Payment Verified', 'Fully Paid'].includes(a.paymentStatus)
        ? 'Your booking goes back to Confirmed, since your payment is already verified.'
        : 'Your booking goes back to Pending until the salon verifies your payment.';
    },
    confirmReschedule() {
      if (!this.rescheduleAppt || !this.rescheduleDate || !this.rescheduleTime) return;
      this.isRescheduling = true;
      const id = this.rescheduleAppt.id;
      const formData = new FormData();
      formData.append('id', id);
      formData.append('date', this.rescheduleDate);
      formData.append('time', this.rescheduleTime);
      if (this.rescheduleStaffId) formData.append('staffId', this.rescheduleStaffId);
      fetch('../../backend/customer/rescheduleAppointment.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(body => {
          if (!body.success) {
            this.pushToast('error', body.message || 'Could not reschedule this appointment.');
            if (body.conflict) this.loadRescheduleSlots(); // the slot was just taken
            return;
          }
          const updated = body.appointment;
          const appt = this.appointments.find(a => a.id === id);
          if (appt) Object.assign(appt, { date: updated.date, time: updated.time, status: updated.status, paymentStatus: updated.paymentStatus, reminderSent: false },
            updated.staffName ? { staffName: updated.staffName } : {});
          const booking = this.allBookings.find(b => b.id === id);
          if (booking) Object.assign(booking, { date: updated.date, time: updated.time, status: updated.status });
          this.pushToast('success', body.message || 'Your appointment has been rescheduled.');
          this.closeRescheduleModal();
        })
        .catch(() => this.pushToast('error', 'A network error occurred while rescheduling.'))
        .finally(() => { this.isRescheduling = false; });
    },

    // Uses the same .admin-badge chips as the admin/cashier workspaces
    // (assets/css/admin-theme.css) instead of raw Tailwind color pairs —
    // those chips are already tuned for readable contrast on the portal's
    // light cream background; the old bg-indigo-950/text-indigo-400 pairing
    // in particular rendered near-invisible light text on a light chip.
    getStatusBadgeClass(status) {
      const classes = {
        'Pending': 'admin-badge admin-badge--pending',
        'Reschedule Requested': 'admin-badge admin-badge--pending',
        'Confirmed': 'admin-badge admin-badge--confirmed',
        'In Progress': 'admin-badge admin-badge--info',
        'Completed': 'admin-badge admin-badge--success',
        'Reviewed': 'admin-badge admin-badge--success',
        'Cancelled': 'admin-badge admin-badge--danger',
        'No-Show': 'admin-badge admin-badge--danger'
      };
      return classes[status] || 'admin-badge admin-badge--neutral';
    },

    openAppointmentDetails(appt) {
      this.viewingAppointment = appt;
    },
    closeAppointmentDetails() {
      this.viewingAppointment = null;
    }
  };
}
