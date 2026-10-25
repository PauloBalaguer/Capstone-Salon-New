// Shared display wording only. Stored statuses and role permissions remain authoritative.
window.PortalPresentation = {
  // Stored payment method -> display name. 'PayMongo' alone is the old combined checkout.
  methodLabel(method) {
    return {'PayMongo-GCash': 'GCash (PayMongo)', 'PayMongo-Maya': 'Maya (PayMongo)', 'PayMongo-Card': 'Card (PayMongo)'}[method] || method;
  },
  adminNotice(notification) {
    const message = notification.message || '';
    const booking = message.match(/^(?:New|Guest) booking (LM-(?:[A-Z]+-)+\d{4}-\d+) from (.+?) at (.+?) on (.+?)(?:\s+[—–]\s+(.+))?\.?$/i);
    if (booking) {
      const payment = (booking[5] || '').replace(/\.$/, '');
      return {title:'New booking', reference:booking[1], customer:booking[2], branch:booking[3].match(/\(([^)]+)\)$/)?.[1] || booking[3], schedule:booking[4].replace(/\.$/, ''), payment:/in progress/i.test(payment) ? 'In progress' : /awaiting verification/i.test(payment) ? 'Needs review' : '', summary:''};
    }
    const details = this.notificationDetails(notification);
    return {title:this.notificationTitle(notification.type), reference:message.match(/\bLM-(?:[A-Z]+-)+\d{4}-\d+\b/)?.[0] || '', customer:'', branch:details.branch, schedule:details.schedule, payment:'', summary:details.summary};
  },
  receivedTime(notification) {
    if (!notification.createdAt) return 'Received ' + (notification.time || '');
    const date = new Date(notification.createdAt);
    if (Number.isNaN(date.getTime())) return 'Received ' + (notification.time || '');
    const minutes = Math.floor((Date.now() - date.getTime()) / 60000);
    if (minutes >= 0 && minutes < 1) return 'Received just now';
    if (minutes > 0 && minutes < 60) return 'Received ' + minutes + ' min ago';
    return 'Received ' + new Intl.DateTimeFormat('en-US', {timeZone:'Asia/Manila',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(date);
  },
  legacyBookingDetails(message) {
    const match = message.trim().match(/^Your booking request has been received:\s*(.+?) at (.+?) on (?:Monday, |Tuesday, |Wednesday, |Thursday, |Friday, |Saturday, |Sunday, )?([A-Za-z]+) (\d{1,2}), (\d{4}) at (\d{1,2}):(\d{2})\s*(AM|PM)\.\s*Status:\s*(.+?)\.?$/i);
    if (!match) return null;
    const months = ['january','february','march','april','may','june','july','august','september','october','november','december'];
    const month = months.indexOf(match[3].toLowerCase());
    const hour = Number(match[6]);
    if (month < 0 || hour < 1 || hour > 12 || Number(match[7]) > 59) return null;
    const date = new Date(Date.UTC(Number(match[5]), month, Number(match[4]), (hour % 12) + (match[8].toUpperCase() === 'PM' ? 12 : 0) - 8, Number(match[7])));
    const schedule = new Intl.DateTimeFormat('en-US', {timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}).format(date) + ' (PH)';
    return {service:match[1],branch:match[2],schedule,status:match[9]};
  },
  notificationDetails(notification) {
    const message = notification.message || '';
    const field = label => message.split('\n').find(line => line.startsWith(label + ': '))?.slice(label.length + 2).trim() || '';
    const legacy = notification.type === 'BOOKING_SUBMITTED' ? this.legacyBookingDetails(message) : null;
    const service = field('Service / Event') || legacy?.service || '';
    const branch = field('Branch') || legacy?.branch || '';
    const scheduled = field('Scheduled time').match(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/);
    const date = scheduled ? new Date(scheduled[1] + 'T' + scheduled[2] + ':00+08:00') : null;
    const schedule = date && !Number.isNaN(date.getTime()) ? new Intl.DateTimeFormat('en-US', {timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}).format(date) + ' (PH)' : legacy?.schedule || '';
    const submitted = notification.type === 'BOOKING_SUBMITTED';
    const rawStatus = field('Booking status') || legacy?.status || '';
    const status = submitted ? (/^(Pending|Pending Review|Waiting for Confirmation)$/i.test(rawStatus) || !rawStatus ? 'Awaiting confirmation' : rawStatus) : '';
    // Only split messages with explicit structured metadata. Keep free-form operational alerts intact.
    const summary = legacy ? '' : service ? (submitted ? 'The booking request has been received.' : message.split(/\n\s*\n|\nBooking reference:/)[0]) : message;
    return {summary, service, branch, schedule, status};
  },
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
