// Bootstrap: merges the customer dashboard mixin files into the single
// Alpine x-data object and runs first-load lifecycle logic.
// dashboard-helpers.js, dashboard-state.js, and dashboard-notifications.js
// are required on every page. The rest (dashboard-appointments.js,
// dashboard-booking.js, dashboard-reviews.js, dashboard-home-service.js,
// dashboard-profile.js, dashboard-track-booking.js) are optional each
// page's <script> list only includes the ones its own markup actually
// uses, and the ones left out are simply skipped here instead of merged
// in as empty.

function customerApp() {
  // Only merges a mixin if its file was actually loaded on this page,
  // so pages that omit a mixin's <script> tag don't blow up init().
  const optionalMixin = (factoryName) =>
    typeof window[factoryName] === 'function' ? window[factoryName]() : {};

  return mergeMixins(
    customerDashboardState(),
    customerNotificationsMixin(),
    optionalMixin('customerAppointmentsMixin'),
    optionalMixin('customerBookingMixin'),
    optionalMixin('customerReviewsMixin'),
    optionalMixin('customerHomeServiceMixin'),
    optionalMixin('customerProfileMixin'),
    optionalMixin('customerTrackBookingMixin'),
    {
      init() {
        this.loadDashboardData();
        // Live sync: bookings, cancellations, staff assignments and stylist
        // availability changed on any other device show up here on their
        // own (function/shared/live-sync.js).
        if (window.LiveSync) window.LiveSync.start({ branch: 'all', onChange: () => this.liveRefresh() });
      },

      // Quiet reload for live sync: no boot screen, no prefill or reminder
      // pop-ups -- just the server's current bookings, notifications and
      // staff, plus a fresh check of an open booking form's times/stylists.
      async liveRefresh() {
        if (this.isBooting || this.isGuestTracking) return;
        try {
          const response = await fetch('../../backend/customer/getDashboardData.php', { cache: 'no-store' });
          if (response.status === 401) return;
          const body = await response.json();
          if (!body.success) return;
          this.loadServerNotifications(body.notifications);
          this.appointments = body.appointments;
          this.allBookings = body.allBookings;
          this.myReviews = body.reviews;
          this.homeServiceRequests = body.homeServiceRequests;
          this.staffList = body.catalogs.staff;
        } catch (_) { return; }
        if (this.refreshAvailableSlots && this.bookingForm && this.bookingForm.date && this.selectedServicesForBooking?.length) {
          await this.refreshAvailableSlots({ quiet: true });
          if (this.bookingForm.time) await this.refreshAvailableStaff({ quiet: true });
        }
      },

      openSignOutConfirm() {
        this.isNotificationPanelOpen = false;
        this.isSignOutConfirmOpen = true;
        this.$nextTick(() => this.$refs.signOutCancel?.focus());
      },

      closeSignOutConfirm() {
        this.isSignOutConfirmOpen = false;
      },

      // Sidebar badge on Track Booking: upcoming active bookings.
      upcomingBookingCount() {
        const active = ['Pending', 'Confirmed', 'In Progress', 'Reschedule Requested', 'Reschedule Required'];
        const today = getLocalISODate();
        return (this.appointments || []).filter(a => active.includes(a.status) && String(a.date || '') >= today).length;
      },

      customerFirstName() {
        return String(this.customerProfile.name || '').trim().split(/\s+/)[0] || 'there';
      },

      todayLabel() {
        return new Date().toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
      },

      // Dashboard "Services you'll love": the first four service categories
      // from the live catalogs, with their duration range.
      serviceTeasers() {
        const byCategory = new Map();
        Object.values(this.servicesCatalog || {}).forEach(list => (list || []).forEach(service => {
          const name = service.category || 'Salon Services';
          const minutes = Number(service.durationMinutes) || 0;
          const entry = byCategory.get(name) || { name, min: Infinity, max: 0, count: 0 };
          if (minutes) { entry.min = Math.min(entry.min, minutes); entry.max = Math.max(entry.max, minutes); }
          entry.count += 1;
          byCategory.set(name, entry);
        }));
        return [...byCategory.values()].slice(0, 4).map(entry => ({
          name: entry.name,
          detail: entry.max ? (entry.min === entry.max ? entry.min + ' min' : entry.min + '–' + entry.max + ' min') : entry.count + ' services'
        }));
      },

      // Pulls the logged-in customer's profile, appointments, reviews, home
      // service requests, and the shared catalogs from the real backend.
      // Requires an active PHP session, so this only works when the page is
      // served through Apache (http://localhost/CAPSTONE/...) Live Server
      // can't execute PHP or hold that session cookie.
      loadDashboardData() {
        this.isBooting = true;
        this.dashboardLoadError = '';

        fetch('../../backend/customer/getDashboardData.php', { cache: 'no-store' })
          .then(response => response.json().then(body => ({ status: response.status, body })))
          .then(({ status, body }) => {
            if (status === 401) {
              if (document.getElementById('find-booking')) {
                this.isGuestTracking = true;
                this.isBooting = false;
                return;
              }
              window.location.href = '../../frontend/auth/login.html?error=unauthenticated';
              return;
            }
            if (!body.success) {
              this.dashboardLoadError = body.message || 'Failed to load your dashboard.';
              this.pushToast('error', this.dashboardLoadError);
              this.isBooting = false;
              return;
            }

            if (this.isGuestTracking !== undefined) this.isGuestTracking = false;
            this.customerProfile = body.profile;
            this.csrfToken = body.csrfToken || '';
            if (window.AddressForms) AddressForms.setSaved(body.profile);
            this.loadServerNotifications(body.notifications);
            this.appointments = body.appointments;
            const notificationReference = new URLSearchParams(window.location.search).get('reference');
            if (notificationReference && /^LM-(?:[A-Z]+-)+\d{4}-\d+$/.test(notificationReference)) {
              this.appointmentSearchQuery = notificationReference;
              const linkedBooking = this.appointments.find(a => a.id === notificationReference);
              if (linkedBooking && (['Cancelled', 'Completed', 'Reviewed'].includes(linkedBooking.status) || new Date(linkedBooking.date + 'T23:59:59+08:00') < new Date())) this.appointmentView = 'past';
            }
            this.allBookings = body.allBookings;
            this.myReviews = body.reviews;
            this.homeServiceRequests = body.homeServiceRequests;
            if (notificationReference && this.viewTracking && document.getElementById('find-booking')) {
              const linked = this.appointments.find(a => a.id === notificationReference) || this.homeServiceRequests.find(a => a.id === notificationReference);
              if (linked) {
                const home = linked.preferredDate !== undefined;
                this.appointmentView = this.isPastBooking({ ...linked, date: linked.date || linked.preferredDate }) ? 'past' : 'upcoming';
                if (new URLSearchParams(window.location.search).get('manage') !== '1') this.viewTracking(linked, home ? 'home_service' : 'appointment');
              }
            }
            // My Feedback, opened from a "Service completed" notification: offer
            // the review for that booking. openReviewModal only accepts
            // Completed (or already Reviewed, to edit) appointments.
            if (notificationReference && document.body.dataset.customerPage === 'reviews' && this.openReviewModal) {
              const reviewed = this.appointments.find(a => a.id === notificationReference);
              this.appointmentSearchQuery = '';
              if (reviewed) this.$nextTick(() => this.openReviewModal(reviewed));
              else this.pushToast('error', 'That appointment could not be found in your account.');
            }
            this.lateArrivalRule = body.lateArrivalRule || null;
            if (notificationReference?.startsWith('LM-HOM-')) {
              this.$nextTick(() => document.getElementById('request-' + notificationReference)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
            }
            this.servicesCatalog = body.catalogs.services;
            this.promotionsCatalog = body.catalogs.promotions;
            this.staffList = body.catalogs.staff;

            // Only defined when dashboard-booking.js is loaded on this page
            // (see optionalMixin above).
            if (this.prefillBookingForm) this.prefillBookingForm();
            this.checkForReminders();
            // Sidebar "Track Booking" from another page lands on appointments.html?track=1.
            if (this.openTrackModal && !notificationReference && new URLSearchParams(window.location.search).get('track') === '1') this.openTrackModal();

            this.animateCount('displayServiceCount', countCompletedServices(this.appointments));
            setTimeout(() => { this.isBooting = false; }, 450);
          })
          .catch(() => {
            this.dashboardLoadError = 'A network error occurred while loading your dashboard.';
            this.pushToast('error', this.dashboardLoadError);
            this.isBooting = false;
          });
      }
    }
  );
}
