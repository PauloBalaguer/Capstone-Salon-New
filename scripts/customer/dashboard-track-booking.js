// "Track a Booking" modal, shared by pages/customer/appointments.html and
// pages/customer/home-service.html. Reuses the public trackBooking.php
// endpoint (no login required there) so a logged-in customer can look up
// any booking by reference + phone -- including ones made as a guest
// before they had an account -- without needing a separate customer-only
// backend endpoint.

function customerTrackBookingMixin() {
  return {
    isTrackModalOpen: false,
    trackForm: { reference: '', phone: '' },
    trackLoading: false,
    trackError: '',
    trackResult: null,

    openTrackModal() {
      this.trackForm = { reference: '', phone: this.customerProfile.phone || '' };
      this.trackResult = null;
      this.trackError = '';
      this.isTrackModalOpen = true;
    },

    closeTrackModal() {
      this.isTrackModalOpen = false;
    },

    trackBooking() {
      if (!this.trackForm.reference.trim() || !this.trackForm.phone.trim()) {
        this.trackError = 'Enter both the booking reference and the mobile number used for it.';
        return;
      }
      this.trackLoading = true;
      this.trackError = '';
      this.trackResult = null;

      const params = new URLSearchParams({
        reference: this.trackForm.reference.trim(),
        phone: this.trackForm.phone.trim()
      });

      fetch('../../backend/public/trackBooking.php?' + params.toString())
        .then(response => response.json())
        .then(body => {
          this.trackLoading = false;
          if (!body.success) {
            this.trackError = body.message || 'No booking found for that reference and mobile number.';
            return;
          }
          this.trackResult = body;
        })
        .catch(() => {
          this.trackLoading = false;
          this.trackError = 'A network error occurred while looking up your booking.';
        });
    },

    getTrackStatusBadgeClass(status) {
      const classes = {
        'Pending': 'bg-amber-950 text-amber-400 border border-amber-900/60',
        'Pending Review': 'bg-amber-950 text-amber-400 border border-amber-900/60',
        'Confirmed': 'bg-indigo-950 text-indigo-400 border border-indigo-900/60',
        'In Progress': 'bg-purple-950 text-purple-300 border border-purple-900/60',
        'Completed': 'bg-emerald-950 text-emerald-400 border border-emerald-900/60',
        'Cancelled': 'bg-rose-950 text-rose-400 border border-rose-900/60',
        'Reviewed': 'bg-slate-800 text-slate-300 border border-slate-700'
      };
      return classes[status] || 'bg-slate-800 text-slate-300';
    }
  };
}
