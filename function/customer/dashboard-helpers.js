// Shared pure helper functions used across the dashboard mixins.
// No `this` binding here safe to call standalone or as x-data shorthand methods.

function getLocalISODate(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function parseApptDateTime(dateStr, timeStr) {
  const [year, month, day] = dateStr.split('-').map(Number);
  if (!timeStr) return new Date(year, month - 1, day);
  const match = timeStr.match(/(\d+):(\d+)\s*([AP]M)/i);
  let hours = 0, minutes = 0;
  if (match) {
    hours = parseInt(match[1], 10) % 12;
    minutes = parseInt(match[2], 10);
    if (match[3].toUpperCase() === 'PM') hours += 12;
  }
  return new Date(year, month - 1, day, hours, minutes);
}

// True if the time span [startA, endA) overlaps [startB, endB) at all
// shared by the schedule-slot and stylist-availability checks so both agree
// on what "overlapping" means.
function windowsOverlap(startA, endA, startB, endB) {
  return startA < endB && startB < endA;
}

function formatPeso(amount) {
  return '₱' + Number(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Translates internal appointment status values into customer-friendly
// wording. Appointment status and payment status are two separate columns
// (appointments.status / appointments.payment_status see
// database/migrations/023_appointment_payment_status.sql) and are never
// combined into one label. 'Reviewed' isn't one of the customer-facing
// statuses on its own (it's set by submitReview.php right after a completed
// appointment gets its review) so it still reads as "Completed" to the
// customer. Keep this the single source of truth so every view describes
// the same status the same way.
function getCustomerStatusLabel(status) {
  const labels = {
    'Pending': 'Awaiting confirmation',
    'Confirmed': 'Confirmed',
    'In Progress': 'Service in Progress',
    'Completed': 'Completed',
    'Reviewed': 'Completed',
    'Cancelled': 'Cancelled',
    'Reschedule Requested': 'Reschedule Requested',
    'Reschedule Required': 'Schedule Change Needed',
    'No-Show': 'No-Show'
  };
  return labels[status] || status;
}

// Translates the appointments.payment_status / home_service_requests.payment_status
// column into customer-friendly wording. This status is set only by
// backend/admin/updateBookingStatus.php and backend/cashier/updateStatus.php
// (both role-gated, both computing the "good" outcomes from real business
// state rather than trusting client input) a customer can never write it
// directly.
function getPaymentStatusLabel(appt) {
  if (appt && appt.status === 'Cancelled' && ['Payment Required', 'Pending', 'Unpaid', 'Not Paid'].includes(appt.paymentStatus) && !Number(appt.amountPaid || 0)) {
    if (appt.paymentExpired) return 'Expired';
    if (!Number(appt.depositAmount || 0)) return 'No payment collected';
  }
  if (appt && appt.paymentStatus === 'Forfeited') return 'Deposit kept (reschedule to use it)';
  return PortalPresentation.paymentStatus(appt && appt.paymentStatus || 'Payment Required', appt && appt.remainingBalance);
}

// Appointments only store a start time; the visible end time is derived from
// the summed duration of the booked services (falls back to 30 min, matching
// the backend's Scheduling class default).
function formatApptTimeRange(appt) {
  if (!appt || !appt.time) return '';
  const start = parseApptDateTime(appt.date, appt.time);
  const minutes = Number(appt.durationMinutes) || 30;
  const end = new Date(start.getTime() + minutes * 60000);
  const fmt = (d) => d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  return fmt(start) + ' – ' + fmt(end);
}

// Customers may reschedule online only up to this many hours before the
// appointment (Reschedule::CUSTOMER_CUTOFF_HOURS on the server).
const RESCHEDULE_CUTOFF_HOURS = 24;

// True when a booking at this date/time could never be rescheduled online,
// because it already starts within the cut-off window.
function isInsideRescheduleCutoff(dateStr, timeStr) {
  if (!dateStr || !timeStr) return false;
  const start = parseApptDateTime(dateStr, timeStr);
  return !isNaN(start) && (start - Date.now()) < RESCHEDULE_CUTOFF_HOURS * 3600 * 1000;
}

function formatApptDateLong(dateStr) {
  if (!dateStr) return '';
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

// Integer minutes are authoritative; legacy text labels may be stale or ranges.
function formatServiceDuration(service) {
  return 'Estimated Duration: ' + ServiceDuration.format(service && service.durationMinutes);
}

// Translates the services.payment_requirement enum into the wording shown
// to customers on the Services & Promos page.
function getReservationLabel(paymentRequirement) {
  return paymentRequirement === 'Full Payment' ? 'Full Payment' : '50% Down Payment';
}

// "Total Services Availed" counts only appointments the customer actually
// completed not every booking they've ever made (Pending/Cancelled
// bookings aren't a service rendered).
function countCompletedServices(appointments) {
  return (appointments || []).filter(a => a.status === 'Completed' || a.status === 'Reviewed').length;
}

// Merges mixin objects into one x-data object while preserving getters/setters
// (Object.assign would invoke and flatten them into static values instead).
function mergeMixins(...sources) {
  const target = {};
  for (const source of sources) {
    Object.defineProperties(target, Object.getOwnPropertyDescriptors(source));
  }
  return target;
}

// Active navigation is marked by function/customer/customer-shell.js.
