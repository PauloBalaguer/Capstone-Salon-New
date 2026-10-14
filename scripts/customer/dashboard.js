// Bootstrap: merges the customer dashboard mixin files into the single
// Alpine x-data object and runs first-load lifecycle logic.
// dashboard-helpers.js, dashboard-state.js, and dashboard-notifications.js
// are required on every page. The rest (dashboard-appointments.js,
// dashboard-booking.js, dashboard-reviews.js, dashboard-home-service.js,
// dashboard-profile.js, dashboard-track-booking.js) are optional — each
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
        customerHighlightNav();
        this.loadDashboardData();
      },

      // Pulls the logged-in customer's profile, appointments, reviews, home
      // service requests, and the shared catalogs from the real backend.
      // Requires an active PHP session, so this only works when the page is
      // served through Apache (http://localhost/CAPSTONE/...) — Live Server
      // can't execute PHP or hold that session cookie.
      loadDashboardData() {
        this.isBooting = true;
        this.dashboardLoadError = '';

        fetch('../../backend/customer/getDashboardData.php')
          .then(response => response.json().then(body => ({ status: response.status, body })))
          .then(({ status, body }) => {
            if (status === 401) {
              window.location.href = '../login/login.html?error=unauthenticated';
              return;
            }
            if (!body.success) {
              this.dashboardLoadError = body.message || 'Failed to load your dashboard.';
              this.pushToast('error', this.dashboardLoadError);
              this.isBooting = false;
              return;
            }

            this.customerProfile = body.profile;
            this.loadServerNotifications(body.notifications);
            this.appointments = body.appointments;
            this.allBookings = body.allBookings;
            this.myReviews = body.reviews;
            this.homeServiceRequests = body.homeServiceRequests;
            this.servicesCatalog = body.catalogs.services;
            this.promotionsCatalog = body.catalogs.promotions;
            this.staffList = body.catalogs.staff;

            // Only defined when dashboard-booking.js is loaded on this page
            // (see optionalMixin above).
            if (this.prefillBookingForm) this.prefillBookingForm();
            this.checkForReminders();

            this.animateCount('displayLoyaltyPoints', this.customerProfile.loyaltyPoints);
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
