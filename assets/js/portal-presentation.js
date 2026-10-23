// Shared display wording only. Stored statuses and role permissions remain authoritative.
window.PortalPresentation = {
  paymentStatus(status) { return status === 'Down Payment Verified' ? 'Deposit verified' : status; },
  notificationTitle(type) {
    const titles = { BOOKING_SUBMITTED:'Booking request received', NEW_BOOKING:'New booking', CONFIRMED:'Booking confirmed', CANCELLED:'Booking cancelled', RESCHEDULE:'Schedule updated', COMPLETED:'Service completed', PAYMENT_RECEIVED:'Payment received', PAYMENT_VERIFIED:'Payment verified', PAYMENT_ATTENTION:'Payment needs attention', REMINDER:'Appointment reminder', PAYMENT_REMINDER:'Payment reminder', ASSIGNED:'Appointment assigned', APPOINTMENT_ASSIGNED:'Appointment assigned', REASSIGNED:'Staff assignment updated', INVENTORY:'Inventory update', SYSTEM:'System update', SMS:'SMS update', EMAIL:'Email update' };
    return titles[type] || String(type || 'Notification').replace(/_/g, ' ').toLowerCase().replace(/^./, c=>c.toUpperCase());
  },
  reservationLabel(service, money) {
    if (service.paymentRequirement === 'No Online Reservation') return 'No online payment required';
    if (service.reservationAmount == null) return 'Calculated before confirmation';
    const amount = money(service.reservationAmount);
    const suffix = service.reservationLabel === 'Full Payment' ? 'full payment' : service.reservationLabel === '50% Down Payment' ? '50%' : 'minimum deposit';
    return amount + ' (' + suffix + ')';
  }
};
