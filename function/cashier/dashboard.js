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
  confirm: { ref: null, method: 'Cash', busy: false, verifyOnly: false }
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
  CashierApp.liveSync(refreshData, () => state.branch);
  // Other pages link here with ?confirm=<ref>, ?checkout=<ref> or ?verify=<ref>.
  const params = new URLSearchParams(window.location.search);
  const ref = params.get('confirm') || params.get('checkout') || params.get('verify');
  if (ref && state.data) {
    const booking = findBooking(ref);
    if (booking && needsPaymentVerification(booking)) openVerify(ref);
    else if (booking && booking.status === 'Pending') openConfirm(ref);
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
    case 'pending': return b.status === 'Pending' || needsPaymentVerification(b);
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
    || needsPaymentVerification(b)
  );
}

function renderStats() {
  const bookings = state.data.bookings;
  const today = todayISO();
  const queue = queueBookings();
  const todays = bookings.filter(b => b.date === today && !['Cancelled', 'No-Show'].includes(b.status));
  const toVerify = bookings.filter(b => (b.status === 'Pending' && hasReportedPayment(b)) || needsPaymentVerification(b)).length;
  const payable = queue.filter(isPayable);

  document.getElementById('statPendingConfirmation').textContent = queue.filter(b => inTab(b, 'pending')).length;
  document.getElementById('statPaymentsToVerifyHint').textContent = toVerify
    ? `${toVerify} payment${toVerify === 1 ? '' : 's'} to check`
    : 'No payments to check';
  document.getElementById('statPaymentsToVerifyHint').classList.toggle('text-[color:var(--danger)]', toVerify > 0);
  document.getElementById('statPaymentsToVerifyHint').classList.toggle('font-bold', toVerify > 0);
  document.getElementById('statConfirmed').textContent = queue.filter(b => b.status === 'Confirmed').length;
  document.getElementById('statTodayAppointments').textContent = `${todays.length} appointment${todays.length === 1 ? '' : 's'} today`;
  document.getElementById('statInProgress').textContent = queue.filter(b => b.status === 'In Progress').length;
  const walkIns = todays.filter(b => b.depositMethod === 'Walk-in').length;
  document.getElementById('statWalkInsToday').textContent = `${walkIns} walk-in${walkIns === 1 ? '' : 's'} today`;
  document.getElementById('statReadyForCheckout').textContent = payable.length;
  document.getElementById('statToCollect').textContent = CashierApp.formatCurrency(payable.reduce((sum, b) => sum + CashierApp.balanceOf(b), 0)) + ' remaining balance';

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
  if (b.checkoutPaid || b.paymentStatus === 'Fully Paid') return ['Paid', 'bg-emerald-100 text-[color:var(--success)]'];
  if (b.depositVerified) return ['Reservation paid', 'bg-emerald-100 text-[color:var(--success)]'];
  if (hasReportedPayment(b)) return ['Payment to check', 'bg-amber-100 text-[color:var(--warn)]'];
  return ['Not paid yet', 'bg-gray-100 text-gray-600'];
}

/* One clear next step per booking. */
function primaryAction(b) {
  const ref = escapeHtml(b.id);
  if (needsPaymentVerification(b)) {
    return `<button type="button" data-action="verify" data-ref="${ref}" class="btn-row cp-btn-primary"><i class="fa-solid fa-magnifying-glass-dollar" aria-hidden="true"></i>Review Payment</button>`;
  }
  if (b.status === 'Pending') {
    return `<button type="button" data-action="confirm" data-ref="${ref}" class="btn-row cp-btn-primary"><i class="fa-solid fa-check" aria-hidden="true"></i>Confirm Booking</button>`;
  }
  if (b.status === 'Confirmed') {
    return `<button type="button" data-action="start" data-ref="${ref}" class="btn-row cp-btn-primary"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>Check In</button>`;
  }
  if (isPayable(b)) {
    return `<button type="button" data-action="checkout" data-ref="${ref}" class="btn-row cp-btn-gold"><i class="fa-solid fa-cash-register" aria-hidden="true"></i>Checkout</button>`;
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
    const balance = CashierApp.balanceOf(b);
    const late = (b.date || '') < today;
    const dateNote = b.date !== today ? `<span class="cp-q-date${late ? ' is-late' : ''}">${escapeHtml(b.date || '')}</span>` : '';
    return `
    <article class="cp-q-row" aria-label="${escapeHtml(b.clientName)}, ${escapeHtml(b.time || '')}">
      <div class="cp-q-time" data-label="Time"><strong>${escapeHtml(b.time || '')}</strong>${dateNote}</div>
      <div class="cp-q-who">
        <span class="cp-q-avatar" aria-hidden="true">${escapeHtml(initialsOf(b.clientName))}</span>
        <div class="cp-q-who-text">
          <p class="cp-q-name">${escapeHtml(titleCase(b.clientName))}</p>
          <p class="cp-q-service" data-label="Service">${escapeHtml(b.serviceName || 'No service listed')}</p>
          <p class="cp-q-meta"><span class="cp-mono">${escapeHtml(b.id)}</span> · <span data-label="Staff">${escapeHtml(titleCase(b.staffName || 'No stylist yet'))}</span></p>
        </div>
      </div>
      <dl class="cp-q-facts">
        <div><dt>Status</dt><dd><span class="cp-badge ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span></dd></div>
        <div><dt>Payment</dt><dd><span class="cp-badge ${payClass}">${payLabel}</span></dd></div>
        <div class="cp-q-balance"><dt>Balance</dt><dd>${CashierApp.formatCurrency(balance)}</dd></div>
      </dl>
      <div class="cp-q-actions">
        ${primaryAction(b)}
        <a href="appointments.html?ref=${encodeURIComponent(b.id)}" class="btn-row cp-btn-ghost" aria-label="Details for ${escapeHtml(b.clientName)}">Details</a>
      </div>
    </article>`;
  };

  const empty = `
    <div class="cp-empty">
      <i class="fa-solid ${search ? 'fa-magnifying-glass' : 'fa-mug-hot'}" aria-hidden="true"></i>
      <p class="cp-empty-title">${search ? 'No bookings match your search' : 'The queue is clear'}</p>
      <p>${search ? 'Check the spelling, or search by booking reference.' : 'New bookings and walk-ins will appear here.'}</p>
    </div>`;

  const section = (title, note, tone, rows) => rows.length
    ? `<div class="cp-q-section ${tone}"><strong>${title} (${rows.length})</strong><span>${note}</span></div>` + rows.map(row).join('')
    : '';
  const html = section('Today', 'Appointments scheduled for today.', 'is-today', todays)
    + section('Still open from earlier days', 'Not scheduled for today. Finish or close these before the end of the day.', 'is-late', earlier)
    + section('Upcoming bookings to check', 'Confirm or verify these ahead of time once the payment is checked.', 'is-upcoming', upcoming);
  document.getElementById('queueContainer').innerHTML = html || empty;
}

/* ---------- Confirm booking popup ---------- */

function showConfirmStep(step) {
  document.querySelectorAll('#confirmModal [data-step]').forEach(el => el.classList.toggle('hidden', el.dataset.step !== String(step)));
  document.getElementById('confirmSteps').classList.toggle('hidden', step === 3 || state.confirm.verifyOnly);
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
  state.confirm = { ref, method: 'Cash', busy: false, verifyOnly: false };
  document.getElementById('confirmTitle').textContent = 'Confirm booking';
  document.getElementById('cfDoneTitle').textContent = 'Booking confirmed';
  document.getElementById('btnConfirmBack2').classList.remove('hidden');
  document.getElementById('confirmRef').textContent = b.id;
  document.getElementById('cfClient').textContent = b.clientName;
  document.getElementById('cfPhone').textContent = b.clientPhone || '';
  document.getElementById('cfDate').textContent = b.date || '';
  document.getElementById('cfTime').textContent = b.time + (b.endTime ? ' – ' + b.endTime : '');
  document.getElementById('cfServices').textContent = b.serviceName || 'No service listed';
  document.getElementById('cfDuration').textContent = b.durationMinutes ? b.durationMinutes + ' min' : '';
  document.getElementById('cfTotal').textContent = CashierApp.formatCurrency(b.price);
  document.getElementById('cfConflict').classList.toggle('hidden', !b.hasConflict);

  const onShift = state.data.staffList.filter(s => s.onShift && s.status !== 'On Break');
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

/* The payment step alone, for a booking already past Pending. Records the
   payment without changing the booking's status. */
function openVerify(ref) {
  const b = findBooking(ref);
  if (!b) return;
  state.confirm = { ref, method: 'Cash', busy: false, verifyOnly: true };
  document.getElementById('confirmTitle').textContent = 'Verify payment';
  document.getElementById('cfDoneTitle').textContent = 'Payment verified';
  document.getElementById('btnConfirmBack2').classList.add('hidden');
  document.getElementById('confirmRef').textContent = b.id;
  goToPaymentStep();
  CashierApp.showModal('confirmModal');
}

function submitLabel(skipPayment) {
  if (state.confirm.verifyOnly) return 'Verify payment';
  return skipPayment ? 'Confirm booking' : 'Verify & confirm';
}

function paymentRules(b) {
  const required = b.requiredAmount !== null && b.requiredAmount !== undefined ? Number(b.requiredAmount) : Number(b.depositAmount || 0);
  const alreadyVerified = ['Down Payment Verified', 'Fully Paid'].includes(b.paymentStatus);
  const noneNeeded = !state.confirm.verifyOnly && !alreadyVerified && b.requiredAmount !== null && b.requiredAmount !== undefined && Number(b.requiredAmount) <= 0 && !b.depositAmount;
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
  document.getElementById('btnConfirmSubmit').textContent = submitLabel(skipPayment);

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
  btn.textContent = state.confirm.verifyOnly ? 'Verifying…' : 'Confirming…';
  try {
    const verifyOnly = state.confirm.verifyOnly;
    // Verifying a payment leaves the booking and its stylist as they are.
    const chosen = verifyOnly ? b.staffId : document.getElementById('cfStaff').value;
    let stylistName = b.staffName;
    if (chosen && String(chosen) !== String(b.staffId)) {
      const r = await CashierApp.post('assignStaff.php', { id: b.id, staffId: chosen });
      if (!r.success) return confirmError(r.message || 'That stylist cannot take this booking. Pick another one.');
      stylistName = state.data.staffList.find(s => String(s.id) === String(chosen))?.name || stylistName;
    } else if (!verifyOnly && !chosen && !b.staffId) {
      let assigned = null, lastError = 'No stylist on shift can take this booking.';
      for (const s of state.data.staffList.filter(x => x.onShift && x.status !== 'On Break')) {
        const r = await CashierApp.post('assignStaff.php', { id: b.id, staffId: s.id });
        if (r.success) { assigned = s; break; }
        lastError = r.message || lastError;
      }
      if (!assigned) return confirmError(lastError);
      stylistName = assigned.name;
    }

    const payload = verifyOnly ? { id: b.id, paymentAction: 'verify' } : { id: b.id, status: 'Confirmed' };
    if (!skipPayment) Object.assign(payload, { depositAmount: amount.toFixed(2), depositMethod: state.confirm.method });
    const result = await CashierApp.post('updateStatus.php', payload);
    if (!result.success) return confirmError(result.message || (verifyOnly ? 'Could not verify the payment.' : 'Could not confirm the booking.'));

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
      btn.textContent = submitLabel(skipPayment);
    }
  }
}

async function markPaymentNeedsAttention() {
  const b = findBooking(state.confirm.ref);
  if (!b) return;
  if (!(await LMConfirm({ title: 'Mark this payment for checking?', message: 'Use this when the amount or reference does not match what the salon received. The booking keeps its current status.', confirmLabel: 'Mark for checking' }))) return;
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
  // On-shift staff first; off-shift staff are listed but cannot be picked
  // (createWalkIn.php refuses them anyway).
  const available = staff.filter(s => s.onShift && s.status !== 'On Break');
  const unavailable = staff.filter(s => !available.includes(s));
  const option = (s, ok) => `<option value="${escapeHtml(s.id)}"${ok ? '' : ' disabled'}>${escapeHtml(titleCase(s.name))} (${escapeHtml(s.role || 'Staff')})${ok ? '' : ' - ' + (s.onShift ? 'on break' : 'off shift')}</option>`;
  document.getElementById('selectStylist').innerHTML = available.length
    ? available.map(s => option(s, true)).join('') + unavailable.map(s => option(s, false)).join('')
    : '<option value="">No staff on shift right now</option>' + unavailable.map(s => option(s, false)).join('');
  document.getElementById('walkInForm').reset();
  updateWalkInSummary();
  CashierApp.showModal('walkInModal');
}

function updateWalkInSummary() {
  const services = (state.data && state.data.servicesByBranch[state.branch]) || [];
  const ids = Array.from(document.querySelectorAll('.walkin-service-checkbox:checked')).map(cb => cb.value);
  const total = services.filter(sv => ids.includes(String(sv.id))).reduce((sum, sv) => sum + Number(sv.price || 0), 0);
  document.getElementById('walkInTotal').textContent = CashierApp.formatCurrency(total);
  let now = '';
  try { now = new Date().toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit' }); } catch (e) { /* older browsers */ }
  document.getElementById('walkInSchedule').textContent = now ? `Today, now (${now})` : 'Today, now';
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
    else if (btn.dataset.action === 'verify') openVerify(btn.dataset.ref);
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
  document.getElementById('servicesCheckboxContainer').addEventListener('change', updateWalkInSummary);

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
