// Raw application state and seed data for the customer dashboard.
// Pure data only behavior lives in the dashboard-*.js mixin files.

function customerDashboardState() {
  return {
    appointmentView: 'upcoming',
    appointmentSearchQuery: '',
    selectedBranchForServices: 'daraga',
    isNotificationPanelOpen: false,
    // Sign Out confirmation (header icon on phones, sidebar on larger screens).
    isSignOutConfirmOpen: false,
    // Profile photo upload on My Profile.
    isUploadingPhoto: false,
    // Per-session token for state-changing customer requests (getDashboardData.php).
    csrfToken: '',
    // services-promos.html?book=1: the Book Appointment entry point.
    bookingEntry: new URLSearchParams(window.location.search).get('book') === '1',
    isReviewModalOpen: false,
    isEditingReview: false,
    isCancelModalOpen: false,
    appointmentToCancelId: null,
    viewingAppointment: null,
    notifications: [],
    isBooting: true,
    isSubmittingBooking: false,
    isSubmittingHomeService: false,
    displayLoyaltyPoints: 0,
    displayServiceCount: 0,

    // --- Customer data: populated from getDashboardData.php in init(), see
    // dashboard.js. Empty/default shells here so the template has something
    // safe to bind to during the initial fetch. ---
    dashboardLoadError: '',
    // Configured late-arrival rule (Scheduling::lateArrivalRule); null hides it.
    lateArrivalRule: null,

    customerProfile: {
      firstName: '', middleName: '', lastName: '',
      name: '',
      email: '',
      phone: '',
      address: '',
      structuredAddress: null,
      memberSince: '',
      loyaltyCard: { stamps: 0, totalStamps: 0, completedCards: 0, minimumSpend: 500, rewards: [] },
      loyaltyTier: { tier: 'Member', visits: 0, windowMonths: 12, nextTier: 'Silver', visitsToNext: 3, ladder: [] },
      profilePicture: '',
      notificationPrefs: {
        email: true,
        sms: true
      }
    },

    appointments: [],

    reviewForm: { id: null, appointmentId: null, serviceName: '', staffName: '', rating: 5, comment: '' },

    myReviews: [],

    passwordForm: { current: '', new: '', confirm: '' },
    passwordChangeStatus: { success: '', error: '' },
    passwordChangeStep: 'form',
    passwordChangeCode: '',

    servicesCatalog: {},
    promotionsCatalog: {},
    staffList: [],

    selectedServicesForBooking: [],
    bookingForm: { customerName: '', customerPhone: '', date: getLocalISODate(), time: '', staffId: '', branch: '', paymentMethod: '', paymentPlan: 'deposit', depositReference: '', agreedToTerms: false },
    // Booking wizard step, in customer-facing order: schedule -> stylist ->
    // review -> payment -> notifications -> terms. Schedule always comes
    // before payment so the customer validates an available slot first.
    bookingStep: 'schedule',
    depositLocked: false,
    showBookingForm: false,
    showBookingSuccess: false,

    homeServiceRequests: [],
    isHomeServiceModalOpen: false,
    isHomeServiceSuccess: false,
    homeServiceForm: { address: '', eventType: 'Standard Home Service', otherEventType: '', weddingPackage: '', clients: '', services: [], venueDetails: '', branchKey: '', preferredDate: '', preferredTime: '', requests: '', agreedToTerms: false },

    bookingSuccessDetails: null,
    bookingSlotLimit: 2,
    availableTimeSlots: [
      '09:00 AM', '09:30 AM', '10:00 AM', '10:30 AM', '11:00 AM', '11:30 AM',
      '01:00 PM', '01:30 PM', '02:00 PM', '02:30 PM', '03:00 PM', '03:30 PM',
      '04:00 PM', '04:30 PM', '05:00 PM', '05:30 PM', '06:00 PM', '06:30 PM', '07:00 PM'
    ],
    slotsClosedMessage: '',
    // Populated from backend/public/getAvailableSlots.php (the authoritative,
    // duration-aware check) whenever the schedule step's date/services
    // change. Keyed by slot label; a slot missing from this map falls back
    // to the client-side estimate (isSlotAvailable) until the live check
    // resolves. See customerBookingMixin.refreshAvailableSlots().
    slotAvailabilityMap: {},
    slotsLoading: false,

    allBookings: [],

    // Shorthand references so templates can call these as x-data methods.
    getLocalISODate,
    formatPeso,
    getCustomerStatusLabel,
    getPaymentStatusLabel,
    formatApptTimeRange,
    formatApptDateLong,
    formatServiceDuration,
    getReservationLabel,
    isInsideRescheduleCutoff
  };
}
