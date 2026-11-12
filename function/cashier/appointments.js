/* frontend/cashier/appointments.html Appointment History.
   Every booking this branch has had, newest first, with search and filters.
   Tapping one opens its details; the few actions a past or upcoming booking
   still allows (reschedule, cancel, assign stylist, receipt) live in that
   popup. Confirming and checking out happen on the Cashier Panel
   (dashboard.html), which this page links to.
   Branch is fixed by the logged-in Cashier's account (server-side); see
   database/migrations/007_cashier_branch_lock.sql. */

const PAGE_SIZE = 10; // 10 bookings per page, after sorting newest first

const state = {
  data: null,
  branch: null,
  page: 1,
  detailsRef: null,
  pendingReassignRef: null,
  pendingRescheduleRef: null,
  pendingRescheduleTime: ''
};

async function init() {
  CashierApp.initChrome();
  wireStaticEvents();
  const params = new URLSearchParams(window.location.search);
  // Older dashboard links used ?tab=<status>.
  const tab = params.get('tab');
  if (tab && [...document.getElementById('apptStatus').options].some(o => o.value === tab)) {
    document.getElementById('apptStatus').value = tab;
  }
  await refreshData();
  CashierApp.liveSync(refreshData, () => state.branch);
  const ref = params.get('ref');
  if (ref && state.data) {
    showDetails(ref);
    history.replaceState(null, '', 'appointments.html');
  }
}

async function refreshData() {
  try {
    const result = await CashierApp.fetchDashboard();
    if (!result.success) {
      CashierApp.toast(result.message || 'Failed to load appointments.', 'error');
      return;
    }
    state.data = result;
    state.branch = result.myBranch.key;
    CashierApp.applyBranchChrome(state.branch);
    renderAppointments();
    CashierApp.updateQueueBadge(result.bookings);
    if (state.detailsRef && !document.getElementById('detailsModal').classList.contains('hidden')) showDetails(state.detailsRef);
  } catch (e) {
    console.error(e);
  }
}

function localDateYmd(d) {
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
}

function cellOrDash(value) {
  return value === null || value === undefined || value === '' ? '' : escapeHtml(String(value));
}

function paymentStatusBadgeClass(status) {
  switch (status) {
    case 'Fully Paid':
    case 'Down Payment Verified': return 'bg-emerald-100 text-[color:var(--success)]';
    case 'Awaiting Verification':
    case 'Refund Due':
    case 'Refund Processing': return 'bg-amber-100 text-[color:var(--warn)]';
    case 'Rejected':
    case 'Refunded':
    case 'Forfeited': return 'bg-rose-100 text-[color:var(--danger)]';
    default: return 'bg-gray-100 text-gray-600';
  }
}

function friendlyDate(ymd) {
  if (!ymd) return 'No date';
  const today = localDateYmd(new Date());
  const yesterday = localDateYmd(new Date(Date.now() - 86400000));
  const tomorrow = localDateYmd(new Date(Date.now() + 86400000));
  const label = new Date(ymd + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  if (ymd === today) return 'Today · ' + label;
  if (ymd === yesterday) return 'Yesterday · ' + label;
  if (ymd === tomorrow) return 'Tomorrow · ' + label;
  return label;
}

/* List version of the date, easy to scan:
     today/yesterday/tomorrow -> "Today · Nov 11"
     this year                -> "Mon, Nov 16"
     another year             -> "Tue, Jan 5, 2027"
   The details popup keeps the long friendlyDate. */
function shortDate(ymd) {
  if (!ymd) return 'No date';
  const d = new Date(ymd + 'T00:00:00');
  if (isNaN(d)) return ymd;
  const sameYear = d.getFullYear() === new Date().getFullYear();
  const rel = { [localDateYmd(new Date())]: 'Today', [localDateYmd(new Date(Date.now() - 86400000))]: 'Yesterday', [localDateYmd(new Date(Date.now() + 86400000))]: 'Tomorrow' }[ymd];
  if (rel) return `${rel} · ${d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`;
  return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', ...(sameYear ? {} : { year: 'numeric' }) });
}

/* "08:00 AM" + "10:00 AM" -> "8:00 AM – 10:00 AM" (no leading zeros, AM/PM on both). */
function shortTimeRange(start, end) {
  const trim = t => String(t || '').trim().replace(/^0(\d)/, '$1');
  const s = trim(start), e = trim(end);
  return e ? `${s} – ${e}` : s;
}

function inPeriod(b, period) {
  const today = localDateYmd(new Date());
  const date = b.date || '';
  if (period === 'custom') {
    const from = document.getElementById('apptFrom').value;
    const to = document.getElementById('apptTo').value;
    return (!from || date >= from) && (!to || date <= to);
  }
  if (period === 'today') return date === today;
  if (period === 'upcoming') return date >= today && ['Pending', 'Confirmed', 'Reschedule Requested'].includes(b.status);
  if (period === 'month') return date.slice(0, 7) === today.slice(0, 7);
  if (period === '7' || period === '30') {
    return date <= today && date >= localDateYmd(new Date(Date.now() - (Number(period) - 1) * 86400000));
  }
  return true;
}

function filteredBookings() {
  const search = (document.getElementById('apptSearch').value || '').toLowerCase().trim();
  const status = document.getElementById('apptStatus').value;
  const period = document.getElementById('apptPeriod').value;
  return state.data.bookings
    .filter(b => (status === 'All' || b.status === status)
      && inPeriod(b, period)
      && (!search
        || b.clientName.toLowerCase().includes(search)
        || (b.clientPhone || '').includes(search)
        || b.id.toLowerCase().includes(search)))
    // Newest submission first (when the booking was made), not appointment date.
    .sort(newestBookingFirst);
}

/* "today, 4:58 PM" / "yesterday, 9:10 AM" / "Nov 11, 4:58 PM" (adds the year
   when it isn't this year). Falls back to the raw text if it can't be read. */
function bookedLabel(b) {
  const key = submittedKey(b);
  if (!key) return b.submittedAt || '';
  const [ymd, hm] = key.split(' ');
  const [y, mo, d] = ymd.split('-').map(Number);
  const [H, M] = hm.split(':').map(Number);
  const when = new Date(y, mo - 1, d, H, M);
  const time = when.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const day = new Date(y, mo - 1, d);
  const diff = Math.round((today - day) / 86400000);
  if (diff === 0) return `today, ${time}`;
  if (diff === 1) return `yesterday, ${time}`;
  const date = when.toLocaleDateString('en-US', { month: 'short', day: 'numeric', ...(y !== today.getFullYear() ? { year: 'numeric' } : {}) });
  return `${date}, ${time}`;
}

/* "today, <nowrap>4:08 PM</nowrap>" so a narrow column wraps between the day and the time. */
function bookedHtml(b) {
  const label = bookedLabel(b);
  if (!label) return '-';
  const i = label.lastIndexOf(', ');
  return i < 0 ? escapeHtml(label) : `${escapeHtml(label.slice(0, i + 1))} <span class="cp-nowrap">${escapeHtml(label.slice(i + 2))}</span>`;
}

/* Page buttons: ‹ 1 2 3 … 9 › (current page highlighted). */
function renderPager(pages) {
  const box = document.getElementById('apptPager');
  if (pages <= 1) { box.innerHTML = ''; return; }
  const cur = state.page;
  const nums = [...new Set([1, cur - 1, cur, cur + 1, pages].filter(n => n >= 1 && n <= pages))].sort((a, b) => a - b);
  let html = `<button type="button" class="cp-page-btn" data-page="${cur - 1}" ${cur === 1 ? 'disabled' : ''} aria-label="Previous page"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>`;
  nums.forEach((n, i) => {
    if (i && n - nums[i - 1] > 1) html += '<span class="cp-page-gap" aria-hidden="true">…</span>';
    html += `<button type="button" class="cp-page-btn${n === cur ? ' is-current' : ''}" data-page="${n}"${n === cur ? ' aria-current="page"' : ''} aria-label="Page ${n}">${n}</button>`;
  });
  html += `<button type="button" class="cp-page-btn" data-page="${cur + 1}" ${cur === pages ? 'disabled' : ''} aria-label="Next page"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>`;
  box.innerHTML = html;
}

function renderAppointments() {
  if (!state.data) return;
  const list = filteredBookings();
  const today = localDateYmd(new Date());

  document.getElementById('sumTotal').textContent = list.length;
  const completed = list.filter(b => b.status === 'Completed');
  document.getElementById('sumCompleted').textContent = completed.length;
  document.getElementById('sumCompletedValue').textContent = CashierApp.formatCurrency(completed.reduce((s, b) => s + Number(b.price || 0), 0)) + ' in services';
  document.getElementById('sumUpcoming').textContent = list.filter(b => (b.date || '') >= today && ['Pending', 'Confirmed', 'Reschedule Requested'].includes(b.status)).length;
  document.getElementById('sumCancelled').textContent = list.filter(b => ['Cancelled', 'No-Show'].includes(b.status)).length;

  const container = document.getElementById('appointmentsList');
  if (!list.length) {
    container.innerHTML = `
      <div class="py-14 text-center px-4">
        <i class="fa-solid fa-calendar-xmark text-3xl text-gray-300 mb-2 block"></i>
        <p class="text-sm text-gray-500">No appointments match these filters.</p>
      </div>`;
    document.getElementById('apptCountLabel').textContent = '';
    document.getElementById('apptPager').innerHTML = '';
    return;
  }

  const pages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
  state.page = Math.min(Math.max(1, state.page), pages);
  const first = (state.page - 1) * PAGE_SIZE;
  const visible = list.slice(first, first + PAGE_SIZE);
  container.innerHTML = visible.map(b => {
    return `
      <button type="button" data-ref="${escapeHtml(b.id)}" class="appt-row cp-appt-row" aria-label="Details for ${escapeHtml(b.clientName)}, ${escapeHtml(b.id)}">
        <span class="cp-cell" data-label="Reference"><span class="cp-mono">${escapeHtml(b.id)}</span><span class="cp-sub cp-booked" title="Booked ${escapeHtml(b.submittedAt || '')}">Booked ${bookedHtml(b)}</span></span>
        <span class="cp-cell cp-cell-strong" data-label="Customer">${escapeHtml(titleCase(b.clientName))}</span>
        <span class="cp-cell" data-label="Service">${cellOrDash(b.serviceName) || 'No service listed'}</span>
        <span class="cp-cell" data-label="Staff">${escapeHtml(titleCase(b.staffName || '')) || '<span class="cp-sub">No stylist yet</span>'}</span>
        <span class="cp-cell" data-label="Schedule" title="${escapeHtml(friendlyDate(b.date))}"><span class="cp-when">${escapeHtml(shortDate(b.date))}</span><span class="cp-sub cp-when">${escapeHtml(shortTimeRange(b.time, b.endTime)) || '-'}</span></span>
        <span class="cp-cell" data-label="Status"><span class="cp-badge ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span>${b.status === 'Pending' && b.hasConflict ? '<span class="cp-badge bg-rose-100 text-[color:var(--danger)]">Time clash</span>' : ''}</span>
        <span class="cp-cell" data-label="Payment"><span class="cp-badge ${paymentStatusBadgeClass(b.paymentStatus)}">${cellOrDash(PortalPresentation.paymentStatus(b.paymentStatus)) || 'Not set'}</span></span>
        <span class="cp-cell cp-cell-action"><span class="btn-row cp-btn-ghost">Details</span></span>
      </button>`;
  }).join('');

  document.getElementById('apptCountLabel').textContent = `Showing ${first + 1}–${first + visible.length} of ${list.length}`;
  renderPager(pages);
}

/* Cancelled, but the salon kept the (non-refundable) deposit -- the booking
   can be rescheduled to use it. Mirrors Reschedule::isReinstatable(). */
function hasKeptDeposit(b) {
  return b.status === 'Cancelled' && ['Forfeited', 'Refund Due'].includes(b.paymentStatus) && Number(b.depositAmount) > 0;
}

/* The actions a booking still allows, in the details popup footer. */
function detailsActionsFor(b) {
  const ref = encodeURIComponent(b.id);
  const primary = (label, attrs) => `<a ${attrs} class="btn-primary col-span-2 text-center">${label}</a>`;
  const action = (label, act, tone = '') => `<button type="button" data-detail-action="${act}" class="btn-secondary py-2.5 text-sm ${tone}">${label}</button>`;
  const isDue = (b.date || '') <= localDateYmd(new Date());
  const out = [];
  if (needsPaymentVerification(b)) out.push(primary('Verify payment on Cashier Panel', `href="dashboard.html?verify=${ref}"`));
  if (hasKeptDeposit(b)) {
    out.push(action('Reschedule', 'reschedule'));
  } else if (b.status === 'Pending') {
    out.push(primary('Confirm on Cashier Panel', `href="dashboard.html?confirm=${ref}"`));
    out.push(action('Reschedule', 'reschedule'), action('Cancel booking', 'cancel', 'text-[color:var(--danger)]'));
  } else if (b.status === 'Confirmed') {
    if (isDue) out.push(`<button type="button" data-detail-action="arrival" class="btn-primary col-span-2">Review arrival &amp; start service</button>`);
    out.push(action(b.staffName ? 'Change stylist' : 'Assign stylist', 'reassign'), action('Reschedule', 'reschedule'));
    if (b.canMarkNoShow) out.push(action('Mark no-show', 'no-show', 'text-[color:var(--danger)] col-span-2'));
  } else if (b.status === 'Reschedule Requested') {
    out.push(action('Reschedule', 'reschedule'), action('Cancel booking', 'cancel', 'text-[color:var(--danger)]'));
  } else if (b.status === 'In Progress' || (b.status === 'Completed' && !b.checkoutPaid)) {
    out.push(primary('Checkout on Cashier Panel', `href="dashboard.html?checkout=${ref}"`));
  }
  if (b.checkoutPaid || (b.status === 'Completed' && ['Fully Paid', 'Down Payment Verified'].includes(b.paymentStatus))) {
    out.push(`<a href="receipt.html?ref=${ref}" class="btn-secondary col-span-2 text-center"><i class="fa-solid fa-receipt mr-1"></i>View receipt</a>`);
  }
  return out.join('');
}

function showDetails(ref) {
  const b = state.data.bookings.find(x => x.id === ref);
  if (!b) return;
  state.detailsRef = ref;
  const item = (label, value, wide = false) => `<div class="${wide ? 'col-span-2' : ''}"><dt class="field-label mb-0.5">${label}</dt><dd class="text-slate-800 font-semibold">${value || '<span class="text-slate-400 font-normal">None</span>'}</dd></div>`;
  const requests = (b.arrivalRequests || []).map(kind => `<p class="rounded-xl bg-rose-50 border border-[#9C1E15]/30 text-[color:var(--danger)] text-sm p-3">${kind === 'no_show' ? 'The stylist asks to mark this booking as a no-show.' : 'The stylist asks to reassign or reschedule this booking.'}</p>`).join('');
  document.getElementById('detailsRef').textContent = b.id;
  document.getElementById('detailsModalBody').innerHTML = `
    <div class="space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="font-bold text-xl text-slate-900 capitalize">${escapeHtml(b.clientName)}</p>
          <p class="text-sm text-slate-500">${cellOrDash(b.clientPhone)}</p>
        </div>
        <div class="flex flex-col items-end gap-1">
          <span class="text-xs font-bold px-2.5 py-1 rounded-full ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span>
          <span class="text-xs font-bold px-2.5 py-1 rounded-full ${paymentStatusBadgeClass(b.paymentStatus)}">${cellOrDash(PortalPresentation.paymentStatus(b.paymentStatus))}</span>
        </div>
      </div>
      ${requests}
      <dl class="grid grid-cols-2 gap-3 text-sm rounded-xl border border-gray-200 bg-[color:var(--card)] p-4">
        ${item('Date', cellOrDash(b.date))}
        ${item('Time', cellOrDash(b.time) + (b.endTime ? ' – ' + cellOrDash(b.endTime) : ''))}
        ${item('Services', cellOrDash(b.serviceName), true)}
        ${item('Stylist', `<span class="capitalize">${cellOrDash(b.staffName)}</span>`)}
        ${item('Duration', b.durationMinutes ? b.durationMinutes + ' min' : '')}
        ${item('Booked on', cellOrDash(b.submittedAt))}
        ${item('Reference', `<span class="font-mono">${escapeHtml(b.id)}</span>`)}
        ${item('Total amount', CashierApp.formatCurrency(b.price))}
        ${item('Amount paid', CashierApp.formatCurrency(CashierApp.amountPaid(b)))}
        ${item('Balance', `<span class="${CashierApp.balanceOf(b) > 0 ? 'text-[color:var(--danger)]' : 'text-[color:var(--success)]'}">${CashierApp.formatCurrency(CashierApp.balanceOf(b))}</span>`)}
      </dl>
      ${b.depositAmount || b.depositReference ? `
      <div class="rounded-xl border p-4 text-sm ${b.depositVerified ? 'border-[#115C39]/30 bg-emerald-50 text-[color:var(--success)]' : 'border-[#774800]/30 bg-amber-50 text-[color:var(--warn)]'}">
        <p class="font-bold mb-1">${b.depositVerified ? 'Reservation payment (verified)' : 'Reservation payment (not checked yet)'}</p>
        <p>${b.depositAmount ? CashierApp.formatCurrency(b.depositAmount) : ''} by ${cellOrDash(PortalPresentation.methodLabel(b.depositMethod || b.paymentMethod)) || 'unknown method'}</p>
        ${b.depositReference ? `<p>Reference no. <span class="font-mono font-bold">${cellOrDash(b.depositReference)}</span></p>` : ''}
      </div>` : ''}
      ${hasKeptDeposit(b) ? `<p class="rounded-xl bg-rose-50 border border-[#9C1E15]/30 text-[color:var(--danger)] text-sm p-3">The ${CashierApp.formatCurrency(b.depositAmount || 0)} deposit is non-refundable. The customer can reschedule to use it.</p>` : ''}
    </div>`;
  const actions = detailsActionsFor(b);
  const actionsEl = document.getElementById('detailsActions');
  actionsEl.innerHTML = actions;
  actionsEl.classList.toggle('hidden', !actions);
  CashierApp.showModal('detailsModal');
}

function openReassignModal(ref) {
  state.pendingReassignRef = ref;
  const booking = state.data.bookings.find(b => b.id === ref);
  const staff = state.data.staffList;
  const select = document.getElementById('reassignStaffSelect');
  select.innerHTML = staff.length
    ? staff.map(s => `<option value="${s.id}" ${booking && booking.staffId === s.id ? 'selected' : ''}>${escapeHtml(titleCase(s.name))} (${escapeHtml(s.role || 'Staff')})</option>`).join('')
    : '<option value="">No staff available</option>';
  CashierApp.showModal('reassignModal');
}

async function confirmReassign() {
  const staffId = document.getElementById('reassignStaffSelect').value;
  if (!staffId) {
    CashierApp.toast('Please select a staff member.', 'error');
    return;
  }
  const result = await CashierApp.post('assignStaff.php', { id: state.pendingReassignRef, staffId });
  if (result.success) {
    CashierApp.toast('Staff assigned.', 'success');
    CashierApp.hideModal('reassignModal');
    state.pendingReassignRef = null;
    await refreshData();
  } else {
    CashierApp.toast(result.message || 'Failed to assign staff.', 'error');
  }
}

/* RESCHEDULE -- pick a new date + an open slot and move the booking
   (backend/cashier/rescheduleAppointment.php), or fall back to flagging it
   "Reschedule Requested" so the customer is asked to sort out a new time. */

function openRescheduleModal(ref) {
  const b = state.data.bookings.find(x => x.id === ref);
  if (!b) return;
  state.pendingRescheduleRef = ref;
  state.pendingRescheduleTime = '';
  const info = [
    ['Reference', `<span class="font-mono">${cellOrDash(b.id)}</span>`],
    ['Status', `<span class="text-xs font-bold px-2 py-0.5 rounded ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span>`],
    ['Client', `<span class="capitalize">${cellOrDash(b.clientName)}</span>`],
    ['Contact', cellOrDash(b.clientPhone)],
    ['Service(s)', cellOrDash(b.serviceName), true],
    ['Duration', b.durationMinutes ? b.durationMinutes + ' min' : ''],
    ['Total', CashierApp.formatCurrency(b.price)],
    ['Payment', `<span class="text-xs font-bold px-2 py-0.5 rounded ${paymentStatusBadgeClass(b.paymentStatus)}">${cellOrDash(PortalPresentation.paymentStatus(b.paymentStatus))}</span>`],
    ['Deposit', b.depositAmount ? CashierApp.formatCurrency(b.depositAmount) + (b.depositVerified ? ' (verified)' : ' (unverified)') : ''],
  ];
  document.getElementById('rescheduleInfo').innerHTML = info.map(([label, value, wide]) => `
    <div class="${wide ? 'col-span-2' : ''}"><span class="block text-xs text-gray-400 font-bold uppercase">${label}</span><span class="text-slate-800">${value}</span></div>
  `).join('');
  document.getElementById('rescheduleCurrent').textContent = b.date + ' · ' + b.time + ' – ' + (b.endTime || '') + (b.staffName ? ' with ' + titleCase(b.staffName) : '');
  const verified = ['Down Payment Verified', 'Fully Paid'].includes(b.paymentStatus);
  document.getElementById('rescheduleStatusNote').textContent = hasKeptDeposit(b)
    ? `Reinstates this cancelled booking its ${CashierApp.formatCurrency(b.depositAmount)} non-refundable deposit is applied to the new time (${b.depositVerified ? 'status becomes Confirmed' : 'status becomes Pending until the deposit is verified'}).`
    : b.status === 'Reschedule Requested'
      ? `Status changes to ${verified ? 'Confirmed (payment already verified)' : 'Pending (payment still needs verifying)'}.`
      : `Status stays ${b.status}.`;
  document.getElementById('rescheduleReason').value = '';
  document.getElementById('rescheduleSlotNote').textContent = '';
  const dateInput = document.getElementById('rescheduleDate');
  dateInput.min = localDateYmd(new Date());
  dateInput.value = b.date && b.date >= dateInput.min ? b.date : dateInput.min;
  // Only a booking that hasn't already been flagged can be flagged.
  document.getElementById('btnAskCustomerReschedule').classList.toggle('hidden', b.status === 'Reschedule Requested' || b.status === 'Cancelled');
  CashierApp.showModal('rescheduleModal');
  loadRescheduleSlots();
}

let rescheduleSlotsRequest = 0;
async function loadRescheduleSlots() {
  const box = document.getElementById('rescheduleSlots');
  const date = document.getElementById('rescheduleDate').value;
  state.pendingRescheduleTime = '';
  document.getElementById('btnConfirmReschedule').disabled = true;
  if (!date) {
    box.innerHTML = '<p class="col-span-4 text-gray-400 ">Pick a date to see open times.</p>';
    return;
  }
  box.innerHTML = '<p class="col-span-4 text-gray-400 ">Loading open times…</p>';
  const requestId = ++rescheduleSlotsRequest;
  const result = await CashierApp.get('rescheduleAppointment.php', { id: state.pendingRescheduleRef, date });
  if (requestId !== rescheduleSlotsRequest) return; // a newer date pick superseded this one
  if (!result.success) {
    box.innerHTML = `<p class="col-span-4 text-[color:var(--danger)]">${escapeHtml(result.message || 'Could not load times.')}</p>`;
    return;
  }
  const booking = state.data.bookings.find(x => x.id === state.pendingRescheduleRef);
  const open = result.slots.filter(s => s.available);
  document.getElementById('rescheduleSlotNote').textContent = open.length
    ? `${open.length} open time${open.length === 1 ? '' : 's'} · each start fits the full ${result.durationMinutes}-min service before closing, with a qualified stylist free.`
    : '';
  if (!open.length) {
    box.innerHTML = '<p class="col-span-4 text-gray-400 ">No open times on this date. Try another day.</p>';
    return;
  }
  box.innerHTML = result.slots.map(s => {
    const isCurrent = booking && booking.date === date && booking.time === s.time;
    return `<button type="button" data-time="${escapeHtml(s.time)}" ${s.available && !isCurrent ? '' : 'disabled'}
      class="reschedule-slot border rounded-lg py-1.5 font-semibold transition ${s.available && !isCurrent
        ? 'border-gray-300 hover:border-[color:var(--accent)] text-slate-700'
        : 'border-gray-100 text-gray-300 line-through cursor-not-allowed'}">${escapeHtml(s.time)}</button>`;
  }).join('');
}

function pickRescheduleSlot(btn) {
  document.querySelectorAll('#rescheduleSlots .reschedule-slot').forEach(b => b.classList.remove('bg-amber-500', 'border-[color:var(--accent)]', 'text-slate-950'));
  btn.classList.add('bg-amber-500', 'border-[color:var(--accent)]', 'text-slate-950');
  state.pendingRescheduleTime = btn.dataset.time;
  document.getElementById('btnConfirmReschedule').disabled = false;
}

async function confirmReschedule() {
  const date = document.getElementById('rescheduleDate').value;
  const time = state.pendingRescheduleTime;
  if (!date || !time) return;
  const btn = document.getElementById('btnConfirmReschedule');
  btn.disabled = true;
  const reason = document.getElementById('rescheduleReason').value.trim();
  const result = await CashierApp.post('rescheduleAppointment.php', { id: state.pendingRescheduleRef, date, time, reason });
  CashierApp.toast(result.message || (result.success ? 'Booking rescheduled.' : 'Failed to reschedule.'), result.success ? 'success' : 'error');
  if (result.success) {
    CashierApp.hideModal('rescheduleModal');
    state.pendingRescheduleRef = null;
    await refreshData();
  } else {
    await loadRescheduleSlots(); // the slot may have just been taken
  }
}

async function askCustomerToReschedule() {
  if (!(await LMConfirm({ title: 'Request a schedule change?', message: 'The customer will be notified to arrange a new time.', confirmLabel: 'Request Change' }))) return;
  const result = await CashierApp.post('updateStatus.php', { id: state.pendingRescheduleRef, status: 'Reschedule Requested' });
  CashierApp.toast(result.message || (result.success ? 'Reschedule requested.' : 'Failed to update booking.'), result.success ? 'success' : 'error');
  if (result.success) {
    CashierApp.hideModal('rescheduleModal');
    await refreshData();
  }
}

async function cancelBooking(ref) {
  const b = state.data.bookings.find(x => x.id === ref);
  const depositNote = b && b.depositAmount && ['Down Payment Verified', 'Fully Paid'].includes(b.paymentStatus)
    ? `\n\nThe ${CashierApp.formatCurrency(b.depositAmount)} deposit is NON-REFUNDABLE. Consider "Reschedule" instead the customer can also reschedule later to use it.`
    : '';
  if (!(await LMConfirm({ title: 'Cancel this booking?', message: ('This cannot be undone.' + depositNote), confirmLabel: 'Cancel Booking', cancelLabel: 'Keep Booking' }))) return;
  const result = await CashierApp.post('updateStatus.php', { id: ref, status: 'Cancelled' });
  CashierApp.toast(result.message || (result.success ? 'Booking cancelled.' : 'Failed to cancel booking.'), result.success ? 'success' : 'error');
  if (result.success) await refreshData();
}

async function markNoShow(ref) {
  const b = state.data.bookings.find(x => x.id === ref);
  const depositNote = b && b.depositAmount && ['Down Payment Verified', 'Fully Paid'].includes(b.paymentStatus)
    ? `\n\nThe ${CashierApp.formatCurrency(b.depositAmount)} deposit is NON-REFUNDABLE and will be kept.`
    : '';
  if (!(await LMConfirm({ title: 'Mark as No-Show?', message: ('Check first that the customer has not arrived.' + depositNote), confirmLabel: 'Mark No-Show' }))) return;
  const result = await CashierApp.post('updateStatus.php', { id: ref, status: 'No-Show' });
  CashierApp.toast(result.message || (result.success ? 'Marked as No-Show.' : 'Failed to update booking.'), result.success ? 'success' : 'error');
  if (result.success) await refreshData();
}

function wireStaticEvents() {
  document.getElementById('apptSearch').addEventListener('input', () => { state.page = 1; renderAppointments(); });
  document.getElementById('apptStatus').addEventListener('change', () => { state.page = 1; renderAppointments(); });
  document.getElementById('apptPeriod').addEventListener('change', (e) => {
    document.getElementById('apptRange').classList.toggle('hidden', e.target.value !== 'custom');
    state.page = 1; renderAppointments();
  });
  ['apptFrom', 'apptTo'].forEach(id => document.getElementById(id).addEventListener('change', () => { state.page = 1; renderAppointments(); }));
  document.getElementById('apptPager').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-page]');
    if (!btn || btn.disabled) return;
    state.page = Number(btn.dataset.page);
    renderAppointments();
    document.querySelector('.cp-appt-head')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });
  document.getElementById('appointmentsList').addEventListener('click', (e) => {
    const row = e.target.closest('.appt-row');
    if (row) showDetails(row.dataset.ref);
  });

  document.getElementById('detailsActions').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-detail-action]');
    if (!btn) return;
    const ref = state.detailsRef;
    switch (btn.dataset.detailAction) {
      case 'arrival': AppointmentArrival.review(ref, 'backend/cashier/updateStatus.php').then(changed => { if (changed) refreshData(); }); break;
      case 'reassign': openReassignModal(ref); break;
      case 'reschedule': openRescheduleModal(ref); break;
      case 'cancel': cancelBooking(ref); break;
      case 'no-show': markNoShow(ref); break;
    }
  });

  document.getElementById('btnCloseDetailsModal').addEventListener('click', () => { state.detailsRef = null; CashierApp.hideModal('detailsModal'); });
  document.getElementById('btnCloseReassignModal').addEventListener('click', () => CashierApp.hideModal('reassignModal'));
  document.getElementById('btnConfirmReassign').addEventListener('click', confirmReassign);

  document.getElementById('btnCloseRescheduleModal').addEventListener('click', () => CashierApp.hideModal('rescheduleModal'));
  document.getElementById('rescheduleDate').addEventListener('change', loadRescheduleSlots);
  document.getElementById('rescheduleSlots').addEventListener('click', (e) => {
    const btn = e.target.closest('.reschedule-slot');
    if (btn && !btn.disabled) pickRescheduleSlot(btn);
  });
  document.getElementById('btnConfirmReschedule').addEventListener('click', confirmReschedule);
  document.getElementById('btnAskCustomerReschedule').addEventListener('click', askCustomerToReschedule);
}

document.addEventListener('DOMContentLoaded', init);
