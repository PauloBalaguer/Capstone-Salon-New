/* pages/cashier/appointments.html — Branch Appointment Pipeline.
   Branch is fixed by the logged-in Cashier's account (server-side); see
   database/migrations/007_cashier_branch_lock.sql. bookings is already
   scoped to that one branch by getDashboardData.php. */

const state = {
  data: null,
  branch: null,
  pendingAdmitRef: null,
  pendingReassignRef: null,
  pendingRescheduleRef: null,
  pendingRescheduleTime: '',
  activeTab: 'Pending'
};

async function init() {
  CashierApp.initChrome();
  wireStaticEvents();
  setActiveTab('Pending');
  await refreshData();
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
  } catch (e) {
    console.error(e);
  }
}

function setActiveTab(tab) {
  state.activeTab = tab;
  document.querySelectorAll('#statusTabs .tab-btn').forEach(btn => {
    const active = btn.dataset.tab === tab;
    btn.classList.toggle('bg-amber-500', active);
    btn.classList.toggle('text-slate-950', active);
    btn.classList.toggle('text-gray-500', !active);
    btn.classList.toggle('hover:text-gray-800', !active);
  });
  renderAppointments();
}

/* Real duration/price/service values sometimes come back empty (a booking
   with no linked appointment_services row, for example) -- show "—" rather
   than a blank cell. */
function cellOrDash(value) {
  return value === null || value === undefined || value === '' ? '—' : escapeHtml(String(value));
}

function paymentStatusBadgeClass(status) {
  switch (status) {
    case 'Fully Paid':
    case 'Down Payment Verified': return 'bg-emerald-100 text-emerald-700';
    case 'Awaiting Verification':
    case 'Refund Due':
    case 'Refund Processing': return 'bg-amber-100 text-amber-800';
    case 'Rejected':
    case 'Refunded':
    case 'Forfeited': return 'bg-rose-100 text-rose-700';
    default: return 'bg-gray-100 text-gray-500';
  }
}

function renderAppointments() {
  if (!state.data) return;
  const bookings = state.data.bookings;
  const tbody = document.getElementById('appointmentsTableBody');

  const search = (document.getElementById('apptSearch').value || '').toLowerCase().trim();
  const filtered = bookings.filter(b => {
    const matchesTab = state.activeTab === 'All'
      || b.status === state.activeTab;
    const matchesSearch = !search
      || b.clientName.toLowerCase().includes(search)
      || (b.clientPhone || '').includes(search)
      || b.id.toLowerCase().includes(search);
    return matchesTab && matchesSearch;
  });

  if (!filtered.length) {
    tbody.innerHTML = `
      <tr><td colspan="12" class="py-12 text-center">
        <i class="fa-solid fa-calendar-xmark text-3xl text-gray-200 mb-2 block"></i>
        <span class="text-xs text-gray-400 italic">No ${state.activeTab === 'All' ? '' : state.activeTab.toLowerCase() + ' '}bookings found for this branch.</span>
      </td></tr>
    `;
    return;
  }

  tbody.innerHTML = filtered.map(b => `
    <tr class="hover:bg-amber-50/40 transition-colors duration-150">
      <td class="p-2.5 font-mono text-[10px]">${escapeHtml(b.id)}</td>
      <td class="p-2.5">${cellOrDash(b.date)}</td>
      <td class="p-2.5">${cellOrDash(b.time)}</td>
      <td class="p-2.5">${cellOrDash(b.endTime)}</td>
      <td class="p-2.5 font-semibold text-slate-800 capitalize">${escapeHtml(b.clientName)}</td>
      <td class="p-2.5">${cellOrDash(b.clientPhone)}</td>
      <td class="p-2.5">${cellOrDash(b.serviceName)}</td>
      <td class="p-2.5">${b.durationMinutes ? b.durationMinutes + ' min' : '—'}</td>
      <td class="p-2.5 capitalize">${cellOrDash(b.staffName)}</td>
      <td class="p-2.5">
        <span class="text-[10px] font-bold px-2 py-0.5 rounded ${statusBadgeClass(b.status)}${b.status === 'In Progress' ? ' badge-live' : ''}">${escapeHtml(b.status)}</span>
        ${b.status === 'Pending' && b.hasConflict ? '<span class="ml-1 text-[9px] font-bold text-rose-600">⚠ Conflict</span>' : ''}
      </td>
      <td class="p-2.5"><span class="text-[10px] font-bold px-2 py-0.5 rounded ${paymentStatusBadgeClass(b.paymentStatus)}">${cellOrDash(b.paymentStatus)}</span></td>
      <td class="p-2.5 space-x-1.5 whitespace-nowrap">${actionsFor(b)}</td>
    </tr>
  `).join('');
}

/* Only actions valid for the booking's current status. */
function actionsFor(b) {
  const ref = escapeHtml(b.id);
  const details = `<button data-action="details" data-ref="${ref}" class="text-slate-500 hover:text-slate-800 hover:underline">View</button>`;

  // Deposits are non-refundable (backend/config/CancellationPolicy.php). A
  // cancelled booking whose deposit was kept can be rescheduled instead,
  // which reinstates it with that deposit (backend/config/Reschedule.php).
  if (hasKeptDeposit(b)) {
    return `${details}<button data-action="reschedule" data-ref="${ref}" class="text-amber-700 font-bold hover:underline">Reschedule</button>
      <span class="block mt-1 text-[10px] leading-snug text-rose-700 font-semibold whitespace-normal max-w-[14rem]">No refund — ${CashierApp.formatCurrency(b.depositAmount || 0)} deposit is non-refundable. The customer can reschedule to use it.</span>`;
  }

  if (b.status === 'Pending') {
    const verify = (b.depositAmount || b.depositReference)
      ? `<button data-action="verify" data-ref="${ref}" class="text-sky-700 font-bold hover:underline">Verify Payment</button>`
      : '';
    return `
      ${details}
      ${verify}
      <button data-action="confirm" data-ref="${ref}" class="text-emerald-700 font-bold hover:underline">Confirm</button>
      <button data-action="reschedule" data-ref="${ref}" class="text-amber-700 font-bold hover:underline">Reschedule</button>
      <button data-action="cancel" data-ref="${ref}" class="text-rose-700 font-bold hover:underline">Cancel</button>
    `;
  }
  const reschedule = `<button data-action="reschedule" data-ref="${ref}" class="text-amber-700 font-bold hover:underline">Reschedule</button>`;
  // Same rule as payment.html's "Ready for Checkout" list: a Confirmed
  // booking can be checked out from its appointment day onward.
  const checkout = `<a href="payment.html?ref=${encodeURIComponent(b.id)}" class="text-emerald-700 font-bold hover:underline">Checkout</a>`;
  const isDue = (b.date || '') <= localDateYmd(new Date());
  if (b.status === 'Confirmed') {
    return `${details}${isDue ? checkout : ''}<button data-action="reassign" data-ref="${ref}" class="text-amber-700 font-bold hover:underline">${b.staffName ? 'Reassign' : 'Assign'} Staff</button>${reschedule}`;
  }
  if (b.status === 'Reschedule Requested') {
    return `${details}${reschedule}<button data-action="cancel" data-ref="${ref}" class="text-rose-700 font-bold hover:underline">Cancel</button>`;
  }
  if (b.status === 'In Progress') {
    return details + checkout;
  }
  if (b.status === 'Completed') {
    // Unpaid completed visits are checked out from the "Ready for Checkout"
    // list on payment.html, so only the receipt link belongs here.
    return `
      ${details}
      ${b.paymentStatus === 'Fully Paid' || b.paymentStatus === 'Down Payment Verified'
        ? `<a href="receipt.html?ref=${encodeURIComponent(b.id)}" class="text-emerald-700 font-bold hover:underline">Receipt</a>` : ''}
    `;
  }
  return details;
}

function showDetails(ref) {
  const b = state.data.bookings.find(x => x.id === ref);
  if (!b) return;
  document.getElementById('detailsModalBody').innerHTML = `
    <div class="grid grid-cols-2 gap-y-2 gap-x-3">
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Reference</span>${cellOrDash(b.id)}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Status</span>${cellOrDash(b.status)}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Client</span>${cellOrDash(b.clientName)}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Contact</span>${cellOrDash(b.clientPhone)}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Date</span>${cellOrDash(b.date)}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Time</span>${cellOrDash(b.time)} &ndash; ${cellOrDash(b.endTime)}</div>
      <div class="col-span-2"><span class="text-gray-400 block text-[10px] uppercase font-bold">Service(s)</span>${cellOrDash(b.serviceName)}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Duration</span>${b.durationMinutes ? b.durationMinutes + ' min' : '—'}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Staff</span>${cellOrDash(b.staffName)}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Payment Status</span>${cellOrDash(b.paymentStatus)}</div>
      <div><span class="text-gray-400 block text-[10px] uppercase font-bold">Total</span>${CashierApp.formatCurrency(b.price)}</div>
      ${b.depositAmount || b.depositReference ? `
      <div class="col-span-2 border-t border-gray-100 pt-2 mt-1">
        <span class="text-gray-400 block text-[10px] uppercase font-bold mb-1">${b.depositVerified ? 'Verified Payment' : 'Self-Reported Payment (unverified)'}</span>
        <p>Amount: <strong>${b.depositAmount ? CashierApp.formatCurrency(b.depositAmount) : '—'}</strong> via <strong>${cellOrDash(b.paymentMethod)}</strong></p>
        <p>Reference: <strong>${cellOrDash(b.depositReference)}</strong></p>
      </div>` : ''}
    </div>
  `;
  CashierApp.showModal('detailsModal');
}

/* Pending bookings haven't been staffed yet. "Confirm" auto-assigns the
   first on-duty stylist at this branch, then -- after a deposit is
   recorded in the modal -- advances the booking to Confirmed. The deposit
   is what actually "locks" the reservation. */
async function admitAppointmentToQueue(ref, depositAmount, depositMethod) {
  const onDutyStaff = state.data.staffList.filter(s => s.onShift);
  if (!onDutyStaff.length) {
    CashierApp.toast('No on-duty stylist available to admit this client.', 'error');
    return;
  }

  let assignedStylist = null;
  let lastError = null;
  for (const stylist of onDutyStaff) {
    const assignResult = await CashierApp.post('assignStaff.php', { id: ref, staffId: stylist.id });
    if (assignResult.success) {
      assignedStylist = stylist;
      break;
    }
    lastError = assignResult.message;
  }

  if (!assignedStylist) {
    CashierApp.toast(lastError || 'No on-duty stylist is qualified for this booking.', 'error');
    return;
  }

  const statusResult = await CashierApp.post('updateStatus.php', { id: ref, status: 'Confirmed', depositAmount, depositMethod });
  if (!statusResult.success) {
    CashierApp.toast(statusResult.message || 'Failed to confirm the booking.', 'error');
    return;
  }

  CashierApp.toast(`Booking confirmed, assigned to ${assignedStylist.name}.`, 'success');
  await refreshData();
}

function openDepositModal(ref) {
  state.pendingAdmitRef = ref;
  const booking = state.data.bookings.find(b => b.id === ref);
  const allowedDepositMethods = ['Cash', 'GCash', 'Maya', 'PayMongo'];

  document.getElementById('depositAmountInput').value = booking ? (booking.depositAmount || booking.price) : '';
  document.getElementById('depositMethodInput').value = booking && allowedDepositMethods.includes(booking.paymentMethod) ? booking.paymentMethod : '';

  const banner = document.getElementById('depositVerifyBanner');
  if (booking && booking.depositAmount && !booking.depositVerified) {
    banner.classList.remove('hidden');
    document.getElementById('depositVerifyAmount').textContent = CashierApp.formatCurrency(booking.depositAmount);
    document.getElementById('depositVerifyMethod').textContent = booking.paymentMethod || '';
    document.getElementById('depositVerifyRefRow').classList.toggle('hidden', !booking.depositReference);
    document.getElementById('depositVerifyRefValue').textContent = booking.depositReference || '';
    document.getElementById('depositVerifyNoRef').classList.toggle('hidden', !!booking.depositReference);
  } else {
    banner.classList.add('hidden');
  }
  document.getElementById('btnConfirmDeposit').textContent = (booking && booking.depositAmount && !booking.depositVerified) ? 'Verify & Admit' : 'Confirm & Admit';

  CashierApp.showModal('depositModal');
}

async function confirmDepositAndAdmit() {
  const amount = document.getElementById('depositAmountInput').value;
  const method = document.getElementById('depositMethodInput').value;
  if (!amount || Number(amount) <= 0 || !method) {
    CashierApp.toast('A deposit amount and payment method are required.', 'error');
    return;
  }
  await admitAppointmentToQueue(state.pendingAdmitRef, amount, method);
  CashierApp.hideModal('depositModal');
  state.pendingAdmitRef = null;
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
function localDateYmd(d) {
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
}

function openRescheduleModal(ref) {
  const b = state.data.bookings.find(x => x.id === ref);
  if (!b) return;
  state.pendingRescheduleRef = ref;
  state.pendingRescheduleTime = '';
  const info = [
    ['Reference', `<span class="font-mono">${cellOrDash(b.id)}</span>`],
    ['Status', `<span class="text-[10px] font-bold px-2 py-0.5 rounded ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span>`],
    ['Client', `<span class="capitalize">${cellOrDash(b.clientName)}</span>`],
    ['Contact', cellOrDash(b.clientPhone)],
    ['Service(s)', cellOrDash(b.serviceName), true],
    ['Duration', b.durationMinutes ? b.durationMinutes + ' min' : '—'],
    ['Total', CashierApp.formatCurrency(b.price)],
    ['Payment', `<span class="text-[10px] font-bold px-2 py-0.5 rounded ${paymentStatusBadgeClass(b.paymentStatus)}">${cellOrDash(b.paymentStatus)}</span>`],
    ['Deposit', b.depositAmount ? CashierApp.formatCurrency(b.depositAmount) + (b.depositVerified ? ' (verified)' : ' (unverified)') : '—'],
  ];
  document.getElementById('rescheduleInfo').innerHTML = info.map(([label, value, wide]) => `
    <div class="${wide ? 'col-span-2' : ''}"><span class="block text-[10px] text-gray-400 font-bold uppercase">${label}</span><span class="text-slate-800">${value}</span></div>
  `).join('');
  document.getElementById('rescheduleCurrent').textContent = b.date + ' · ' + b.time + ' – ' + (b.endTime || '') + (b.staffName ? ' with ' + titleCase(b.staffName) : '');
  const verified = ['Down Payment Verified', 'Fully Paid'].includes(b.paymentStatus);
  document.getElementById('rescheduleStatusNote').textContent = hasKeptDeposit(b)
    ? `Reinstates this cancelled booking — its ${CashierApp.formatCurrency(b.depositAmount)} non-refundable deposit is applied to the new time (${b.depositVerified ? 'status becomes Confirmed' : 'status becomes Pending until the deposit is verified'}).`
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
    box.innerHTML = '<p class="col-span-4 text-gray-400 italic">Pick a date to see open times.</p>';
    return;
  }
  box.innerHTML = '<p class="col-span-4 text-gray-400 italic">Loading open times…</p>';
  const requestId = ++rescheduleSlotsRequest;
  const result = await CashierApp.get('rescheduleAppointment.php', { id: state.pendingRescheduleRef, date });
  if (requestId !== rescheduleSlotsRequest) return; // a newer date pick superseded this one
  if (!result.success) {
    box.innerHTML = `<p class="col-span-4 text-rose-600">${escapeHtml(result.message || 'Could not load times.')}</p>`;
    return;
  }
  const booking = state.data.bookings.find(x => x.id === state.pendingRescheduleRef);
  const open = result.slots.filter(s => s.available);
  document.getElementById('rescheduleSlotNote').textContent = open.length
    ? `${open.length} open time${open.length === 1 ? '' : 's'} · each start fits the full ${result.durationMinutes}-min service before closing, with a qualified stylist free.`
    : '';
  if (!open.length) {
    box.innerHTML = '<p class="col-span-4 text-gray-400 italic">No open times on this date. Try another day.</p>';
    return;
  }
  box.innerHTML = result.slots.map(s => {
    const isCurrent = booking && booking.date === date && booking.time === s.time;
    return `<button type="button" data-time="${escapeHtml(s.time)}" ${s.available && !isCurrent ? '' : 'disabled'}
      class="reschedule-slot border rounded-lg py-1.5 font-semibold transition ${s.available && !isCurrent
        ? 'border-gray-300 hover:border-amber-500 text-slate-700'
        : 'border-gray-100 text-gray-300 line-through cursor-not-allowed'}">${escapeHtml(s.time)}</button>`;
  }).join('');
}

function pickRescheduleSlot(btn) {
  document.querySelectorAll('#rescheduleSlots .reschedule-slot').forEach(b => b.classList.remove('bg-amber-500', 'border-amber-500', 'text-slate-950'));
  btn.classList.add('bg-amber-500', 'border-amber-500', 'text-slate-950');
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
  if (!window.confirm('Mark this booking as needing a schedule change? The customer will be notified to arrange a new time.')) return;
  const result = await CashierApp.post('updateStatus.php', { id: state.pendingRescheduleRef, status: 'Reschedule Requested' });
  CashierApp.toast(result.message || (result.success ? 'Reschedule requested.' : 'Failed to update booking.'), result.success ? 'success' : 'error');
  if (result.success) {
    CashierApp.hideModal('rescheduleModal');
    await refreshData();
  }
}

/* Cancelled, but the salon kept the (non-refundable) deposit -- the booking
   can be rescheduled to use it. Mirrors Reschedule::isReinstatable(). */
function hasKeptDeposit(b) {
  return b.status === 'Cancelled' && ['Forfeited', 'Refund Due'].includes(b.paymentStatus) && Number(b.depositAmount) > 0;
}

async function cancelBooking(ref) {
  const b = state.data.bookings.find(x => x.id === ref);
  const depositNote = b && b.depositAmount && ['Down Payment Verified', 'Fully Paid'].includes(b.paymentStatus)
    ? `\n\nThe ${CashierApp.formatCurrency(b.depositAmount)} deposit is NON-REFUNDABLE. Consider "Reschedule" instead — the customer can also reschedule later to use it.`
    : '';
  if (!window.confirm('Cancel this booking?' + depositNote)) return;
  const result = await CashierApp.post('updateStatus.php', { id: ref, status: 'Cancelled' });
  CashierApp.toast(result.message || (result.success ? 'Booking cancelled.' : 'Failed to cancel booking.'), result.success ? 'success' : 'error');
  if (result.success) await refreshData();
}

function wireStaticEvents() {
  document.getElementById('statusTabs').addEventListener('click', (e) => {
    const btn = e.target.closest('.tab-btn');
    if (btn) setActiveTab(btn.dataset.tab);
  });
  document.getElementById('apptSearch').addEventListener('input', renderAppointments);

  document.getElementById('appointmentsTableBody').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const ref = btn.dataset.ref;
    switch (btn.dataset.action) {
      case 'details': showDetails(ref); break;
      case 'verify': showDetails(ref); break;
      case 'confirm': openDepositModal(ref); break;
      case 'reassign': openReassignModal(ref); break;
      case 'reschedule': openRescheduleModal(ref); break;
      case 'cancel': cancelBooking(ref); break;
    }
  });

  document.getElementById('btnCloseDepositModal').addEventListener('click', () => CashierApp.hideModal('depositModal'));
  document.getElementById('btnConfirmDeposit').addEventListener('click', confirmDepositAndAdmit);
  document.getElementById('btnCloseDetailsModal').addEventListener('click', () => CashierApp.hideModal('detailsModal'));
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
