// Shared pure helper functions used across the dashboard mixins.
// No `this` binding here — safe to call standalone or as x-data shorthand methods.

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

// True if the time span [startA, endA) overlaps [startB, endB) at all —
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
// (appointments.status / appointments.payment_status — see
// database/migrations/023_appointment_payment_status.sql) and are never
// combined into one label. 'Reviewed' isn't one of the customer-facing
// statuses on its own (it's set by submitReview.php right after a completed
// appointment gets its review) so it still reads as "Completed" to the
// customer. Keep this the single source of truth so every view describes
// the same status the same way.
function getCustomerStatusLabel(status) {
  const labels = {
    'Pending': 'Waiting for Confirmation',
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
// state rather than trusting client input) — a customer can never write it
// directly.
function getPaymentStatusLabel(appt) {
  const labels = {
    'Payment Required': 'Payment Required',
    'Awaiting Verification': 'Payment Being Verified',
    'Down Payment Verified': 'Reservation Payment Received',
    'Fully Paid': 'Paid in Full',
    'Rejected': 'Payment Needs Attention',
    'Refund Due': 'Deposit Refund Pending',
    'Refund Processing': 'Refund Being Processed',
    'Refunded': 'Refunded',
    'Forfeited': 'Deposit Kept — Reschedule to Use'
  };
  const status = appt && appt.paymentStatus;
  return labels[status] || 'Payment Required';
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

function formatApptDateLong(dateStr) {
  if (!dateStr) return '';
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

// Services always have a real duration_minutes (NOT NULL in the database),
// but duration_label is sometimes blank (see database/sync_service_menu.php,
// which seeds it as an empty string). Derive a friendly duration string from
// the real minutes value whenever the label isn't already set, so every
// service card shows a duration instead of a blank one.
function formatServiceDuration(service) {
  if (service && service.duration && service.duration.trim()) return service.duration;
  const minutes = Number(service && service.durationMinutes) || 30;
  if (minutes < 60) return `About ${minutes} minutes`;
  const hours = minutes / 60;
  const hoursText = Number.isInteger(hours) ? hours : hours.toFixed(1);
  return `About ${hoursText} hour${hours === 1 ? '' : 's'}`;
}

// Translates the services.payment_requirement enum into the wording shown
// to customers on the Services & Promos page.
function getReservationLabel(paymentRequirement) {
  return paymentRequirement === 'Full Payment' ? 'Full Payment' : '50% Down Payment';
}

// "Total Services Availed" counts only appointments the customer actually
// completed — not every booking they've ever made (Pending/Cancelled
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

// Highlights the sidebar/mobile-nav links matching the current page filename.
// Mirrors scripts/admin/shared-data.js's adminHighlightNav(). Call from init().
function customerHighlightNav() {
  const current = location.pathname.split('/').pop();

  document.querySelectorAll('#customer-sidebar [data-nav-page]').forEach(link => {
    const isActive = link.getAttribute('data-nav-page') === current;
    link.classList.toggle('bg-luxury-900/60', isActive);
    link.classList.toggle('text-white', isActive);
    link.classList.toggle('font-medium', isActive);
    link.classList.toggle('border-l-4', isActive);
    link.classList.toggle('border-gold-400', isActive);
    link.classList.toggle('text-slate-400', !isActive);
    link.classList.toggle('hover:bg-slate-800/50', !isActive);
    link.classList.toggle('hover:text-slate-200', !isActive);
  });

  document.querySelectorAll('#customer-mobile-nav [data-nav-page]').forEach(link => {
    const isActive = link.getAttribute('data-nav-page') === current;
    link.classList.toggle('text-gold-400', isActive);
    link.classList.toggle('text-slate-400', !isActive);
  });
}
