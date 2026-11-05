/* frontend/cashier/dashboard.html Cashier Panel: today's queue, the
   Confirm Booking popup (booking details -> payment), the checkout popup
   (function/cashier/checkout-modal.js) and New Walk-In.
   Branch is fixed by the logged-in Cashier's account (server-side), not a
   client-side selector -- see database/migrations/007_cashier_branch_lock.sql.
   getDashboardData.php already scopes bookings/staff/inventory/services to
   that one branch and tells us which one via result.myBranch. */

const state = {
  data: null,
  branch: null,
  tab: 'all',
  confirm: { ref: null, method: 'Cash', busy: false }
};

const ONLINE_METHOD_LABELS = {
  'PayMongo-GCash': 'GCash (PayMongo)',
  'PayMongo-Maya': 'Maya (PayMongo)',
  'PayMongo-Card': 'Card (PayMongo)',
  PayMongo: 'PayMongo'
};

async function init() {
  CashierApp.initChrome();
  wireStaticEvents();
  await refreshData();
  // Other pages link here with ?confirm=<ref> or ?checkout=<ref>.
  const params = new URLSearchParams(window.location.search);
  const ref = params.get('confirm') || params.get('checkout');
  if (ref && state.data) {
    const booking = findBooking(ref);
    if (booking && booking.status === 'Pending') openConfirm(ref);
    else if (booking && isPayable(booking)) openCheckout(ref);
    history.replaceState(null, '', 'dashboard.html');
  }
}

async function refreshData() {
  try {
    const result = await CashierApp.fetchDashboard();
    if (!result.success) {
      CashierApp.toast(result.message || 'Failed to load the queue.', 'error');
      return;
    }
    state.data = result;
    state.branch = result.myBranch.key;
    CashierApp.applyBranchChrome(state.branch);
    renderStats();
    renderQueue();
    CashierApp.updateQueueBadge(result.bookings);
  } catch (e) {
    console.error(e);
  }
}

function findBooking(ref) {
  return state.data ? state.data.bookings.find(b => b.id === ref) : null;
}

function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function initialsOf(name) {
  return String(name || '?').split(' ').filter(Boolean).map(p => p[0]).slice(0, 2).join('').toUpperCase();
}

/* Same rule as payment.php: a visit can be paid while In Progress or once
   Completed, until it has a receipt. */
function isPayable(b) {
  return !b.checkoutPaid && (b.status === 'In Progress' || b.status === 'Completed');
}

function hasReportedPayment(b) {
  return !b.depositVerified && (b.paymentStatus === 'Awaiting Verification' || !!(b.depositAmount || b.depositReference));
}

/* Which queue tab a booking belongs to (a payable In Progress visit shows
   under both "In service" and "Ready to pay"). */
function inTab(b, tab) {
  switch (tab) {
    case 'pending': return b.status === 'Pending';
    case 'confirmed': return b.status === 'Confirmed';
    case 'service': return b.status === 'In Progress';
    case 'pay': return isPayable(b);
    default: return true;
  }
}

function queueBookings() {
  const today = todayISO();
  // Today's and earlier open visits, plus upcoming bookings that still need confirming.
  return state.data.bookings.filter(b =>
    ((['Pending', 'Confirmed', 'In Progress'].includes(b.status) || isPayable(b)) && (b.date || '') <= today)
    || (b.status === 'Pending' && (b.date || '') > today)
  );
}

function renderStats() {
  const bookings = state.data.bookings;
  const today = todayISO();
  const queue = queueBookings();
  const todays = bookings.filter(b => b.date === today && !['Cancelled', 'No-Show'].includes(b.status));
  const toVerify = bookings.filter(b => b.status === 'Pending' && hasReportedPayment(b)).length;
  const payable = queue.filter(isPayable);

  document.getElementById('statPendingConfirmation').textContent = bookings.filter(b => b.status === 'Pending').length;
  document.getElementById('statPaymentsToVerifyHint').textContent = toVerify
    ? `${toVerify} payment${toVerify === 1 ? '' : 's'} to check`
    : 'No payments to check';
  document.getElementById('statPaymentsToVerifyHint').classList.toggle('text-rose-600', toVerify > 0);
  document.getElementById('statPaymentsToVerifyHint').classList.toggle('font-bold', toVerify > 0);
  document.getElementById('statConfirmed').textContent = queue.filter(b => b.status === 'Confirmed').length;
  document.getElementById('statTodayAppointments').textContent = `${todays.length} appointment${todays.length === 1 ? '' : 's'} today`;
  document.getElementById('statInProgress').textContent = queue.filter(b => b.status === 'In Progress').length;
  const walkIns = todays.filter(b => b.depositMethod === 'Walk-in').length;
  document.getElementById('statWalkInsToday').textContent = `${walkIns} walk-in${walkIns === 1 ? '' : 's'} today`;
  document.getElementById('statReadyForCheckout').textContent = payable.length;
  document.getElementById('statToCollect').textContent = CashierApp.formatCurrency(payable.reduce((sum, b) => sum + Number(b.price || 0), 0)) + ' in bills';

  ['all', 'pending', 'confirmed', 'service', 'pay'].forEach(tab => {
    const el = document.querySelector(`#queueTabs [data-count="${tab}"]`);
    if (el) el.textContent = queue.filter(b => inTab(b, tab)).length;
  });
}

function setTab(tab) {
  state.tab = tab;
  document.querySelectorAll('#queueTabs .queue-tab').forEach(btn => {
    const active = btn.dataset.queueTab === tab;
    btn.classList.toggle('is-active', active);
    btn.setAttribute('aria-selected', String(active));
  });
  document.querySelectorAll('#statCardsContainer .stat-card').forEach(card => {
    card.classList.toggle('is-active', card.dataset.queueTab === tab);
  });
  renderQueue();
}

function paymentBadge(b) {
  if (b.checkoutPaid || b.paymentStatus === 'Fully Paid') return ['Paid', 'bg-emerald-100 text-emerald-800'];
  if (b.depositVerified) return ['Reservation paid', 'bg-emerald-100 text-emerald-800'];
  if (hasReportedPayment(b)) return ['Payment to check', 'bg-amber-100 text-amber-900'];
  return ['Not paid yet', 'bg-gray-100 text-gray-600'];
}

/* One clear next step per booking. */
function primaryAction(b) {
  const ref = escapeHtml(b.id);
  if (b.status === 'Pending') {
    return `<button type="button" data-action="confirm" data-ref="${ref}" class="btn-row bg-[#006D6F] hover:bg-[#06464A] text-white">Confirm booking</button>`;
  }
  if (b.status === 'Confirmed') {
    return `<button type="button" data-action="start" data-ref="${ref}" class="btn-row border border-[#006D6F] text-[#06464A] bg-white hover:bg-[#E5F2F0]"><i class="fa-solid fa-scissors"></i>Start service</button>`;
  }
  if (isPayable(b)) {
    return `<button type="button" data-action="checkout" data-ref="${ref}" class="btn-row bg-[#E0B84C] hover:bg-[#d4a93a] text-slate-900"><i class="fa-solid fa-cash-register"></i>${b.status === 'Completed' ? 'Take payment' : 'Checkout'}</button>`;
  }
  return '';
}

function renderQueue() {
  if (!state.data) return;
  const search = (document.getElementById('queueSearch').value || '').toLowerCase().trim();
  const today = todayISO();
  const list = queueBookings().filter(b => inTab(b, state.tab) && (!search
    || b.clientName.toLowerCase().includes(search)
    || b.id.toLowerCase().includes(search)));
  const todays = list.filter(b => b.date === today);
  // Still open from earlier days: show them instead of hiding them.
  const earlier = list.filter(b => (b.date || '') < today);
  const upcoming = list.filter(b => (b.date || '') > today);

  const row = b => {
    const [payLabel, payClass] = paymentBadge(b);
    return `
    <div class="p-4 sm:px-5 flex flex-col md:flex-row md:items-center gap-3 md:gap-4 hover:bg-[#FFFDF9]">
      <div class="flex items-start gap-3 flex-1 min-w-0">
        <div class="w-[4.5rem] shrink-0 text-left">
          <p class="font-bold text-slate-900 text-sm whitespace-nowrap">${escapeHtml(b.time || '')}</p>
          ${b.date !== today ? `<p class="text-xs font-semibold ${(b.date || '') < today ? 'text-rose-700' : 'text-[#06464A]'}">${escapeHtml(b.date || '')}</p>` : ''}
        </div>
        <div class="hidden sm:flex w-10 h-10 rounded-full bg-[#E5F2F0] text-[#06464A] font-bold text-sm items-center justify-center shrink-0">${escapeHtml(initialsOf(b.clientName))}</div>
        <div class="min-w-0">
          <p class="font-bold text-slate-900 capitalize truncate">${escapeHtml(b.clientName)}</p>
          <p class="text-sm text-slate-600 truncate">${escapeHtml(b.serviceName || 'No service listed')}</p>
          <p class="text-xs text-slate-500 truncate"><span class="font-mono">${escapeHtml(b.id)}</span> · <span class="capitalize">${escapeHtml(b.staffName || 'No stylist yet')}</span></p>
        </div>
      </div>
      <div class="flex flex-wrap md:flex-nowrap items-center gap-2 md:gap-4 md:justify-end md:shrink-0">
        <div class="flex md:flex-col md:items-end gap-1.5">
          <span class="text-xs font-bold px-2.5 py-1 rounded-full whitespace-nowrap ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span>
          <span class="text-xs font-bold px-2.5 py-1 rounded-full whitespace-nowrap ${payClass}">${payLabel}</span>
        </div>
        <span class="font-black text-slate-900 md:w-24 text-right ml-auto md:ml-0">${CashierApp.formatCurrency(b.price)}</span>
        <div class="flex items-center gap-1 w-full sm:w-auto">
          <span class="flex-1 sm:flex-none grid sm:w-40">${primaryAction(b)}</span>
          <a href="appointments.html?ref=${encodeURIComponent(b.id)}" class="w-10 h-10 shrink-0 inline-flex items-center justify-center rounded-xl text-slate-500 hover:bg-gray-100 hover:text-slate-800" title="View details" aria-label="View details of ${escapeHtml(b.clientName)}"><i class="fa-solid fa-ellipsis"></i></a>
        </div>
      </div>
    </div>`;
  };

  const empty = `
    <div class="text-center py-14 px-4">
      <i class="fa-solid fa-mug-hot text-3xl text-gray-300 mb-2"></i>
      <p class="text-sm text-gray-500">${search ? 'No bookings match your search.' : 'Nothing here right now.'}</p>
    </div>`;

  const section = (title, note, tone, rows) => rows.length
    ? `<div class="px-4 sm:px-5 py-3 ${tone} text-sm font-bold">${title} (${rows.length})<span class="block font-medium opacity-90">${note}</span></div>` + rows.map(row).join('')
    : '';
  const html = todays.map(row).join('')
    + section('Still open from earlier days', 'Finish or close these before the end of the day.', 'bg-amber-50 text-amber-900', earlier)
    + section('Upcoming bookings to confirm', 'Confirm these ahead of time once the payment is checked.', 'bg-[#E5F2F0] text-[#06464A]', upcoming);
  document.getElementById('queueContainer').innerHTML = html || empty;
}

/* ---------- Confirm booking popup ---------- */

function showConfirmStep(step) {
  document.querySelectorAll('#confirmModal [data-step]').forEach(el => el.classList.toggle('hidden', el.dataset.step !== String(step)));
  document.getElementById('confirmSteps').classList.toggle('hidden', step === 3);
  document.querySelectorAll('#confirmSteps [data-step-dot]').forEach(dot => {
    const n = Number(dot.dataset.stepDot);
    dot.classList.toggle('is-active', n === step);
    dot.classList.toggle('is-done', n < step);
    dot.querySelector('span').innerHTML = n < step ? '<i class="fa-solid fa-check"></i>' : String(n);
  });
  document.getElementById('cfError').classList.add('hidden');
}

function openConfirm(ref) {
  const b = findBooking(ref);
  if (!b) return;
  state.confirm = { ref, method: 'Cash', busy: false };
  document.getElementById('confirmRef').textContent = b.id;
  document.getElementById('cfClient').textContent = b.clientName;
  document.getElementById('cfPhone').textContent = b.clientPhone || '';
  document.getElementById('cfDate').textContent = b.date || '';
  document.getElementById('cfTime').textContent = b.time + (b.endTime ? ' – ' + b.endTime : '');
  document.getElementById('cfServices').textContent = b.serviceName || 'No service listed';
  document.getElementById('cfDuration').textContent = b.durationMinutes ? b.durationMinutes + ' min' : '';
  document.getElementById('cfTotal').textContent = CashierApp.formatCurrency(b.price);
  document.getElementById('cfConflict').classList.toggle('hidden', !b.hasConflict);

  const onShift = state.data.staffList.filter(s => s.onShift);
  const options = [];
  if (!b.staffId) options.push('<option value="">Choose for me (first free stylist)</option>');
  else if (!onShift.some(s => String(s.id) === String(b.staffId))) {
    options.push(`<option value="${escapeHtml(b.staffId)}" selected>${escapeHtml(titleCase(b.staffName || 'Current stylist'))} (booked)</option>`);
  }
  onShift.forEach(s => options.push(`<option value="${escapeHtml(s.id)}" ${String(s.id) === String(b.staffId) ? 'selected' : ''}>${escapeHtml(titleCase(s.name))} (${escapeHtml(s.role || 'Staff')})</option>`));
  document.getElementById('cfStaff').innerHTML = options.join('') || '<option value="">No stylist on shift</option>';

  showConfirmStep(1);
  CashierApp.showModal('confirmModal');
}

function paymentRules(b) {
  const required = b.requiredAmount !== null && b.requiredAmount !== undefined ? Number(b.requiredAmount) : Number(b.depositAmount || 0);
  const alreadyVerified = ['Down Payment Verified', 'Fully Paid'].includes(b.paymentStatus);
  const noneNeeded = !alreadyVerified && b.requiredAmount !== null && b.requiredAmount !== undefined && Number(b.requiredAmount) <= 0 && !b.depositAmount;
  return { required, alreadyVerified, noneNeeded };
}

function setConfirmMethod(method) {
  state.confirm.method = method;
  document.querySelectorAll('#cfMethodGroup .pay-method-card').forEach(card => {
    const active = card.dataset.cfMethod === method;
    card.classList.toggle('is-active', active);
    card.setAttribute('aria-checked', String(active));
  });
}

function goToPaymentStep() {
  const b = findBooking(state.confirm.ref);
  if (!b) return;
  const { required, alreadyVerified, noneNeeded } = paymentRules(b);
  document.getElementById('cfPayTotal').textContent = CashierApp.formatCurrency(b.price);
  document.getElementById('cfPayRequired').textContent = noneNeeded ? 'Nothing' : CashierApp.formatCurrency(required);

  const reported = hasReportedPayment(b) && !alreadyVerified;
  document.getElementById('cfReported').classList.toggle('hidden', !reported);
  document.getElementById('btnConfirmAttention').classList.toggle('hidden', !reported);
  if (reported) {
    const method = b.depositMethod || b.paymentMethod;
    document.getElementById('cfReportedAmount').textContent = CashierApp.formatCurrency(b.depositAmount || 0);
    document.getElementById('cfReportedMethod').textContent = ONLINE_METHOD_LABELS[method] || PortalPresentation.methodLabel(method) || method || 'Not given';
    document.getElementById('cfReportedRefRow').classList.toggle('hidden', !b.depositReference);
    document.getElementById('cfReportedRef').textContent = b.depositReference || '';
  }

  const skipPayment = alreadyVerified || noneNeeded;
  const paidNotice = document.getElementById('cfAlreadyPaid');
  paidNotice.classList.toggle('hidden', !skipPayment);
  paidNotice.innerHTML = alreadyVerified
    ? '<i class="fa-solid fa-circle-check mr-1"></i>The reservation payment is already verified. You only need to confirm.'
    : '<i class="fa-solid fa-circle-info mr-1"></i>This booking does not need a reservation payment. Collect the full amount at checkout.';
  document.getElementById('cfPayFields').classList.toggle('hidden', skipPayment);
  document.getElementById('btnConfirmSubmit').textContent = skipPayment ? 'Confirm booking' : 'Verify & confirm';

  if (!skipPayment) {
    const method = b.depositMethod || b.paymentMethod || 'Cash';
    const online = !!ONLINE_METHOD_LABELS[method];
    document.getElementById('cfMethodGroup').classList.toggle('hidden', online);
    document.getElementById('cfOnlineMethod').classList.toggle('hidden', !online);
    document.getElementById('cfOnlineMethodName').textContent = ONLINE_METHOD_LABELS[method] || '';
    setConfirmMethod(online ? method : (['Cash', 'GCash', 'Maya'].includes(method) ? method : 'Cash'));
    const amount = document.getElementById('cfAmount');
    amount.value = Number(b.depositAmount || required || 0).toFixed(2);
    amount.readOnly = online; // PayMongo amounts must match the online payment exactly.
  }
  showConfirmStep(2);
}

function confirmError(message) {
  const el = document.getElementById('cfError');
  el.textContent = message;
  el.classList.remove('hidden');
}

/* Assign the chosen stylist (or the first on-shift stylist who passes the
   server's checks), then confirm with the payment received. Same backend
   calls the Appointments page's old "Admit Client" flow used. */
async function submitConfirm() {
  const b = findBooking(state.confirm.ref);
  if (!b || state.confirm.busy) return;
  const { required, alreadyVerified, noneNeeded } = paymentRules(b);
  const skipPayment = alreadyVerified || noneNeeded;
  const amount = Number(document.getElementById('cfAmount').value);
  if (!skipPayment) {
    if (!(amount > 0)) return confirmError('Enter the amount you received.');
    if (amount < required - 0.001) return confirmError(`The amount must be at least ${CashierApp.formatCurrency(required)}.`);
    if (amount > Number(b.price) + 0.001) return confirmError(`The amount cannot be more than the booking total of ${CashierApp.formatCurrency(b.price)}.`);
  }

  const btn = document.getElementById('btnConfirmSubmit');
  state.confirm.busy = true;
  btn.disabled = true;
  btn.textContent = 'Confirming…';
  try {
    const chosen = document.getElementById('cfStaff').value;
    let stylistName = b.staffName;
    if (chosen && String(chosen) !== String(b.staffId)) {
      const r = await CashierApp.post('assignStaff.php', { id: b.id, staffId: chosen });
      if (!r.success) return confirmError(r.message || 'That stylist cannot take this booking. Pick another one.');
      stylistName = state.data.staffList.find(s => String(s.id) === String(chosen))?.name || stylistName;
    } else if (!chosen && !b.staffId) {
      let assigned = null, lastError = 'No stylist on shift can take this booking.';
      for (const s of state.data.staffList.filter(x => x.onShift)) {
        const r = await CashierApp.post('assignStaff.php', { id: b.id, staffId: s.id });
        if (r.success) { assigned = s; break; }
        lastError = r.message || lastError;
      }
      if (!assigned) return confirmError(lastError);
      stylistName = assigned.name;
    }

    const payload = { id: b.id, status: 'Confirmed' };
    if (!skipPayment) Object.assign(payload, { depositAmount: amount.toFixed(2), depositMethod: state.confirm.method });
    const result = await CashierApp.post('updateStatus.php', payload);
    if (!result.success) return confirmError(result.message || 'Could not confirm the booking.');

    document.getElementById('cfDoneText').textContent = [
      stylistName ? `Stylist: ${titleCase(stylistName)}.` : '',
      skipPayment ? '' : `Payment of ${CashierApp.formatCurrency(amount)} by ${ONLINE_METHOD_LABELS[state.confirm.method] || state.confirm.method} is recorded.`
    ].filter(Boolean).join(' ');
    showConfirmStep(3);
    await refreshData();
  } catch (e) {
    confirmError('Connection lost. Refresh the page and check the booking before trying again.');
  } finally {
    state.confirm.busy = false;
    btn.disabled = false;
    if (!document.querySelector('#confirmModal [data-step="2"]').classList.contains('hidden')) {
      btn.textContent = skipPayment ? 'Confirm booking' : 'Verify & confirm';
    }
  }
}

async function markPaymentNeedsAttention() {
  const b = findBooking(state.confirm.ref);
  if (!b) return;
  if (!(await LMConfirm({ title: 'Mark this payment for checking?', message: 'Use this when the amount or reference does not match what the salon received. The booking stays Pending.', confirmLabel: 'Mark for checking' }))) return;
  const result = await CashierApp.post('updateStatus.php', { id: b.id, paymentAction: 'needs_attention' });
  CashierApp.toast(result.message || (result.success ? 'Payment marked for checking.' : 'Failed to update the payment.'), result.success ? 'success' : 'error');
  if (result.success) {
    CashierApp.hideModal('confirmModal');
    await refreshData();
  }
}

/* ---------- Start service / checkout ---------- */

async function startService(ref) {
  if (await AppointmentArrival.review(ref, 'backend/cashier/updateStatus.php')) await refreshData();
}

function openCheckout(ref) {
  const b = findBooking(ref);
  if (b) CheckoutModal.open(b, state.data);
}

/* ---------- New walk-in ---------- */

function openWalkInModal() {
  if (!state.data) return;
  const services = state.data.servicesByBranch[state.branch] || [];
  const staff = state.data.staffList;
  document.getElementById('servicesCheckboxContainer').innerHTML = services.length
    ? services.map(sv => `
      <label class="flex items-center justify-between gap-3 text-sm cursor-pointer hover:bg-white rounded-lg px-2 py-2">
        <span class="flex items-center gap-2">
          <input type="checkbox" class="walkin-service-checkbox rounded" value="${escapeHtml(sv.id)}">
          <span>${escapeHtml(sv.name)}</span>
        </span>
        <span class="text-gray-500 font-semibold">${CashierApp.formatCurrency(sv.price)}</span>
      </label>`).join('')
    : '<p class="text-gray-400">No services set up for this branch.</p>';
  document.getElementById('selectStylist').innerHTML = staff.length
    ? staff.map(s => `<option value="${escapeHtml(s.id)}">${escapeHtml(titleCase(s.name))} (${escapeHtml(s.role || 'Staff')})</option>`).join('')
    : '<option value="">No stylists available</option>';
  document.getElementById('walkInForm').reset();
  CashierApp.showModal('walkInModal');
}

async function submitWalkIn(e) {
  e.preventDefault();
  if (!BookingFormValidation.fields([['#inputClientName', 'text'], ['#inputClientPhone', 'mobile']])) return;
  const clientName = document.getElementById('inputClientName').value.trim();
  const clientPhone = document.getElementById('inputClientPhone').value.trim();
  const stylistId = document.getElementById('selectStylist').value;
  const serviceIds = Array.from(document.querySelectorAll('.walkin-service-checkbox:checked')).map(cb => cb.value);
  if (!/^09[0-9]{9}$/.test(clientPhone)) {
    CashierApp.toast('Please enter a valid mobile number (09XXXXXXXXX).', 'error');
    return;
  }
  if (!serviceIds.length) {
    CashierApp.toast('Please select at least one service.', 'error');
    return;
  }
  const result = await CashierApp.post('createWalkIn.php', { clientName, clientPhone, stylistId, serviceIds });
  if (result.success) {
    CashierApp.toast(`Walk-in added: ${result.reference}`, 'success');
    CashierApp.hideModal('walkInModal');
    await refreshData();
  } else {
    CashierApp.toast(result.message || 'Failed to add walk-in.', 'error');
  }
}

function wireStaticEvents() {
  document.getElementById('queueSearch').addEventListener('input', renderQueue);
  document.getElementById('queueTabs').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-queue-tab]');
    if (btn) setTab(btn.dataset.queueTab);
  });
  document.getElementById('statCardsContainer').addEventListener('click', (e) => {
    const card = e.target.closest('[data-queue-tab]');
    if (card) setTab(state.tab === card.dataset.queueTab ? 'all' : card.dataset.queueTab);
  });
  document.getElementById('queueContainer').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    if (btn.dataset.action === 'confirm') openConfirm(btn.dataset.ref);
    else if (btn.dataset.action === 'start') startService(btn.dataset.ref);
    else if (btn.dataset.action === 'checkout') openCheckout(btn.dataset.ref);
  });

  const closeConfirm = () => { if (!state.confirm.busy) CashierApp.hideModal('confirmModal'); };
  document.getElementById('btnCloseConfirm').addEventListener('click', closeConfirm);
  document.getElementById('btnConfirmBack1').addEventListener('click', closeConfirm);
  document.getElementById('btnConfirmDone').addEventListener('click', closeConfirm);
  document.getElementById('btnConfirmNext').addEventListener('click', goToPaymentStep);
  document.getElementById('btnConfirmBack2').addEventListener('click', () => showConfirmStep(1));
  document.getElementById('btnConfirmSubmit').addEventListener('click', submitConfirm);
  document.getElementById('btnConfirmAttention').addEventListener('click', markPaymentNeedsAttention);
  document.getElementById('cfMethodGroup').addEventListener('click', (e) => {
    const card = e.target.closest('[data-cf-method]');
    if (card) setConfirmMethod(card.dataset.cfMethod);
  });

  document.getElementById('btnNewWalkIn').addEventListener('click', openWalkInModal);
  document.getElementById('btnCloseModal').addEventListener('click', () => CashierApp.hideModal('walkInModal'));
  document.getElementById('btnCancelModal').addEventListener('click', () => CashierApp.hideModal('walkInModal'));
  document.getElementById('walkInForm').addEventListener('submit', submitWalkIn);

  const phoneInput = document.getElementById('inputClientPhone');
  phoneInput.addEventListener('keypress', (e) => {
    if (e.key.length === 1 && (e.key < '0' || e.key > '9')) e.preventDefault();
  });
  phoneInput.addEventListener('input', function () {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
  });

  setTab('all');
}

document.addEventListener('DOMContentLoaded', init);
