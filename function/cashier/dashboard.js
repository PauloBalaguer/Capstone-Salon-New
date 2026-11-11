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

/* True when the booking's start time has arrived (Asia/Manila): it is served
   now, so only stylists on duty can take it (server: assignStaff.php). */
function bookingStartsNow(b) {
  const m = /^(\d{1,2}):(\d{2})\s*(AM|PM)$/i.exec((b.time || '').trim());
  if (!b.date || !m) return false;
  let h = Number(m[1]) % 12;
  if (m[3].toUpperCase() === 'PM') h += 12;
  const start = new Date(b.date + 'T' + String(h).padStart(2, '0') + ':' + m[2] + ':00+08:00');
  return start.getTime() <= Date.now();
}
const ON_DUTY_STATUSES = ['available', 'busy'];

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

  // Advance booking: any active stylist can be chosen (the server checks their
  // schedule, leave and conflicts at the booking time). A booking whose start
  // has arrived is served now, so only on-duty stylists are offered.
  // Available-now stylists are listed first; live status shown for context.
  const startsNow = bookingStartsNow(b);
  const onShift = state.data.staffList
    .filter(s => !startsNow || ON_DUTY_STATUSES.includes(s.status))
    .sort((a, c) => (a.status === 'available' ? 0 : 1) - (c.status === 'available' ? 0 : 1));
  const options = [];
  if (!b.staffId) options.push('<option value="">Choose for me (first free stylist)</option>');
  else if (!onShift.some(s => String(s.id) === String(b.staffId))) {
    options.push(`<option value="${escapeHtml(b.staffId)}" selected>${escapeHtml(titleCase(b.staffName || 'Current stylist'))} (booked)</option>`);
  }
  onShift.forEach(s => options.push(`<option value="${escapeHtml(s.id)}" ${String(s.id) === String(b.staffId) ? 'selected' : ''}>${escapeHtml(titleCase(s.name))} (${escapeHtml(s.role || 'Staff')}) – now: ${escapeHtml(s.statusLabel || 'Off shift')}</option>`));
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
      // "Choose for me": a booking starting now only tries stylists on duty
      // now (immediate); an advance booking may also try scheduled stylists
      // who are off shift at the moment. The server re-checks either way.
      const startsNow = bookingStartsNow(b);
      let assigned = null, lastError = startsNow ? 'No stylist on duty right now can take this booking.' : 'No scheduled stylist is free for this booking.';
      for (const s of state.data.staffList
        .filter(x => !startsNow || ON_DUTY_STATUSES.includes(x.status))
        .sort((a, c) => (a.status === 'available' ? 0 : 1) - (c.status === 'available' ? 0 : 1))) {
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
  document.getElementById('servicesCheckboxContainer').innerHTML = services.length
    ? services.map((sv, i) => {
      const meta = [formatSvcDuration(sv.durationMinutes), svcSubCategory(sv.category)].filter(Boolean).join(' · ');
      return `
      <label class="wi-svc" data-search="${escapeHtml((sv.name + ' ' + (sv.category || '')).toLowerCase())}" data-cat="${escapeHtml(svcGroup(sv))}" data-popular="${isPopularSvc(sv) ? '1' : ''}" data-pop="${Number(sv.bookingCount) || 0}" data-idx="${i}">
        <input type="checkbox" class="walkin-service-checkbox" value="${escapeHtml(sv.id)}">
        <span class="wi-svc-check" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
        <span class="wi-svc-text">
          <span class="wi-svc-name">${escapeHtml(sv.name)}</span>
          ${meta ? `<span class="wi-svc-meta">${escapeHtml(meta)}</span>` : ''}
        </span>
        <span class="wi-svc-price">₱${Number(sv.price || 0).toLocaleString('en-PH')}</span>
      </label>`;
    }).join('')
    : '<p class="text-gray-400">No services set up for this branch.</p>';
  document.getElementById('wiSvcSearch').value = '';
  // Same filters as guest booking: Popular / All Services / category groups.
  const GROUP_ORDER = ['Hair Services', 'Nails & Spa', 'Lashes & Brows', 'Facial & Skin Care', 'Waxing & Laser',
    'Aesthetic Treatments', 'Massage & Body Care', 'Make-Up Services', 'Promos & Packages'];
  const present = new Set(services.map(svcGroup));
  const groups = [...GROUP_ORDER.filter(g => present.has(g)), ...[...present].filter(g => !GROUP_ORDER.includes(g))];
  const filters = [...(services.some(isPopularSvc) ? [['popular', 'Popular']] : []), ['all', 'All Services'], ...groups.map(g => [g, g])];
  const catSel = document.getElementById('wiSvcSort');
  catSel.innerHTML = filters.map(([k, label]) => `<option value="${escapeHtml(k)}">${escapeHtml(label)}</option>`).join('');
  catSel.value = filters[0][0];
  applyWalkInServiceView();
  document.getElementById('walkInForm').reset();
  document.getElementById('inputMiddleName').disabled = false;
  renderWalkInStaff();
  updateWalkInSummary();
  CashierApp.showModal('walkInModal');
}

function renderWalkInStaff() {
  const staff = state.data.staffList;
  // On-shift staff first; off-shift staff are listed but cannot be picked
  // (createWalkIn.php refuses them anyway).
  // Walk-ins start now: only stylists on duty (available, or busy at the
  // salon -- the server rejects an overlap) can be picked.
  const available = staff.filter(s => s.status === 'available' || s.status === 'busy');
  const unavailable = staff.filter(s => !available.includes(s));
  const offList = unavailable.filter(s => !/cashier/i.test(s.role || ''));
  const card = (s, ok) => `
    <label class="wi-staff${ok ? '' : ' is-off'}">
      <input type="radio" name="wiStylist" value="${escapeHtml(s.id)}"${ok ? '' : ' disabled'}>
      <span class="wi-staff-avatar" aria-hidden="true">${escapeHtml(initialsOf(s.name))}</span>
      <span class="wi-staff-text">
        <span class="wi-staff-name">${escapeHtml(titleCase(s.name))}</span>
        <span class="wi-staff-role">${escapeHtml(s.role || 'Staff')}</span>
      </span>
      <span class="wi-staff-badge ${ok ? (s.status === 'busy' ? 'is-busy' : 'is-on') : ''}">${ok ? (s.status === 'busy' ? 'Busy' : 'On shift') : escapeHtml(s.statusLabel || 'Off shift')}</span>
    </label>`;
  document.getElementById('selectStylist').innerHTML = available.length
    ? available.map(s => card(s, true)).join('')
    : `<div class="wi-staff-empty">
        <div class="wi-staff-empty-head">
          <strong>No staff on shift right now</strong>
          <button type="button" class="wi-staff-refresh" aria-label="Refresh staff list" title="Refresh staff list"><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i></button>
        </div>
        <span>Ask the stylist to log in to their Staff Portal. That clocks them in; then tap refresh to see them here. For a later time, use the regular booking flow instead.</span>
      </div>`;
  const offBox = document.getElementById('wiStaffOff');
  const moreBtn = document.getElementById('wiStaffMore');
  offBox.innerHTML = offList.map(s => card(s, false)).join('');
  offBox.classList.add('hidden');
  moreBtn.classList.toggle('hidden', !offList.length);
  moreBtn.textContent = `Show off-shift staff (${offList.length})`;
  // Pre-select the only on-shift stylist, if there is just one.
  const radios = document.querySelectorAll('#selectStylist input[name="wiStylist"]');
  if (radios.length === 1) radios[0].checked = true;
}

function formatSvcDuration(min) {
  min = Number(min) || 0;
  if (!min) return '';
  const h = Math.floor(min / 60), m = min % 60;
  return [h ? `${h} hour${h > 1 ? 's' : ''}` : '', m ? `${m} minutes` : ''].filter(Boolean).join(' ');
}

function svcSubCategory(cat) {
  const parts = String(cat || '').split(' / ');
  return parts.slice(1).join(' / ') || parts[0] || '';
}

// Same guest-facing groups as public booking (server computes sv.group).
function svcGroup(sv) { return sv.group || String(sv.category || 'Other').split(' / ')[0]; }
function isPopularSvc(sv) { return Number(sv.bookingCount) > 0 || !!sv.bestSeller; }

// Filter the walk-in service cards like guest booking: a search looks across
// every service; otherwise show the chosen category. Most-booked first.
function applyWalkInServiceView() {
  const box = document.getElementById('servicesCheckboxContainer');
  const q = document.getElementById('wiSvcSearch').value.trim().toLowerCase();
  const cat = document.getElementById('wiSvcSort').value || 'all';
  const cards = Array.from(box.querySelectorAll('.wi-svc'));
  if (!cards.length) return;
  cards.sort((a, b) => b.dataset.pop - a.dataset.pop || a.dataset.idx - b.dataset.idx).forEach(c => box.appendChild(c));
  const inCat = c => cat === 'all' || (cat === 'popular' ? c.dataset.popular === '1' : c.dataset.cat === cat);
  let shown = 0;
  cards.forEach(c => {
    const ok = (q ? c.dataset.search.includes(q) : inCat(c)) || c.querySelector('input').checked;
    c.classList.toggle('hidden', !ok);
    if (ok) shown++;
  });
  document.getElementById('wiSvcNoMatch').classList.toggle('hidden', shown > 0);
}

function updateWalkInSummary() {
  const services = (state.data && state.data.servicesByBranch[state.branch]) || [];
  const ids = Array.from(document.querySelectorAll('.walkin-service-checkbox:checked')).map(cb => cb.value);
  const chosen = services.filter(sv => ids.includes(String(sv.id)));
  const total = chosen.reduce((sum, sv) => sum + Number(sv.price || 0), 0);
  const minutes = chosen.reduce((sum, sv) => sum + (Number(sv.durationMinutes) || 0), 0);
  const $ = id => document.getElementById(id);
  const notSet = t => `<em>${t}</em>`;
  $('walkInTotal').textContent = CashierApp.formatCurrency(total);
  let now = '';
  try { now = new Date().toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit' }); } catch (e) { /* older browsers */ }
  $('walkInSchedule').textContent = now ? `Today, now (${now})` : 'Today, now';

  const branch = state.data && state.data.myBranch;
  $('wiSumBranch').textContent = (branch && branch.name) || '—';
  $('wiSumServices').innerHTML = chosen.length
    ? chosen.map(sv => `<span class="wi-sum-svc"><span>${escapeHtml(sv.name)}</span><span>₱${Number(sv.price || 0).toLocaleString('en-PH')}</span></span>`).join('')
    : notSet('Not selected');
  $('wiSumDuration').innerHTML = minutes ? escapeHtml(formatSvcDuration(minutes)) : notSet('Not selected');
  $('wiSvcHint').innerHTML = chosen.length
    ? `<strong>${chosen.length} selected</strong> · Estimated duration: ${escapeHtml(formatSvcDuration(minutes) || '—')} · ₱${total.toLocaleString('en-PH')}`
    : 'Select one or more services.';

  const picked = document.querySelector('#selectStylist input[name="wiStylist"]:checked');
  const pickedName = picked ? picked.closest('.wi-staff').querySelector('.wi-staff-name').textContent : '';
  $('wiSumStylist').innerHTML = pickedName ? escapeHtml(pickedName) : notSet('Not selected');

  const noMid = $('inputNoMiddle').checked;
  const name = [$('inputFirstName').value, noMid ? '' : $('inputMiddleName').value, $('inputLastName').value]
    .map(v => v.trim()).filter(Boolean).join(' ');
  const phone = $('inputClientPhone').value.trim();
  $('wiSumCustomer').innerHTML = name || phone
    ? `${escapeHtml(name || '—')}${phone ? `<span class="wi-sum-sub">${escapeHtml(phone)}</span>` : ''}`
    : notSet('Not entered');

  $('wiDone1').classList.toggle('is-on', !!($('inputFirstName').value.trim() && $('inputLastName').value.trim() && /^09\d{9}$/.test(phone)));
  $('wiDone2').classList.toggle('is-on', chosen.length > 0);
  $('wiDone3').classList.toggle('is-on', !!picked);
}

async function submitWalkIn(e) {
  e.preventDefault();
  if (!BookingFormValidation.fields([['#inputFirstName', 'text'], ['#inputLastName', 'text'], ['#inputClientPhone', 'mobile']])) return;
  const val = id => document.getElementById(id).value.trim().replace(/\s+/g, ' ');
  const middle = document.getElementById('inputNoMiddle').checked ? '' : val('inputMiddleName');
  const clientName = [val('inputFirstName'), middle, val('inputLastName')].filter(Boolean).join(' ');
  const clientPhone = document.getElementById('inputClientPhone').value.trim();
  const picked = document.querySelector('#selectStylist input[name="wiStylist"]:checked');
  const stylistId = picked ? picked.value : '';
  if (!stylistId) {
    CashierApp.toast('Please pick an available staff member.', 'error');
    return;
  }
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
  // Keep the booking summary live as the cashier fills in the form.
  document.getElementById('walkInForm').addEventListener('input', updateWalkInSummary);
  document.getElementById('walkInForm').addEventListener('change', updateWalkInSummary);
  document.getElementById('inputNoMiddle').addEventListener('change', function () {
    const mid = document.getElementById('inputMiddleName');
    mid.disabled = this.checked;
    if (this.checked) mid.value = '';
  });
  // "Refresh the list": re-fetch shift status without clearing the form.
  document.getElementById('selectStylist').addEventListener('click', async (e) => {
    const btn = e.target.closest('.wi-staff-refresh');
    if (!btn) return;
    btn.disabled = true;
    btn.classList.add('is-spinning');
    await refreshData();
    renderWalkInStaff();
    updateWalkInSummary();
  });
  document.getElementById('wiStaffMore').addEventListener('click', function () {
    const off = document.getElementById('wiStaffOff');
    const open = off.classList.toggle('hidden') === false;
    this.textContent = open ? 'Hide off-shift staff' : `Show off-shift staff (${off.children.length})`;
  });
  document.getElementById('wiSvcSearch').addEventListener('input', applyWalkInServiceView);
  document.getElementById('wiSvcSort').addEventListener('change', applyWalkInServiceView);

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
