/* pages/cashier/dashboard.html — Cashier Panel: Active Terminal Queue + New Walk-In.
   Branch is fixed by the logged-in Cashier's account (server-side), not a
   client-side selector -- see database/migrations/007_cashier_branch_lock.sql.
   getDashboardData.php already scopes bookings/staff/inventory/services to
   that one branch and tells us which one via result.myBranch. */

const state = {
  data: null,
  branch: null
};

function initialsOf(name) {
  return String(name || '?').split(' ').filter(Boolean).map(p => p[0]).slice(0, 2).join('').toUpperCase();
}

async function init() {
  CashierApp.initChrome();
  wireStaticEvents();
  await refreshData();
}

async function refreshData() {
  try {
    const result = await CashierApp.fetchDashboard();
    if (!result.success) {
      CashierApp.toast(result.message || 'Failed to load dashboard data.', 'error');
      return;
    }
    state.data = result;
    state.branch = result.myBranch.key;
    CashierApp.applyBranchChrome(state.branch);
    document.getElementById('queueBranchBadge').textContent = `${state.branch.toUpperCase()} Active`;
    renderQueue();
    renderStats();
    CashierApp.updateQueueBadge(result.bookings);
  } catch (e) {
    console.error(e);
  }
}

function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/* A deposit was self-reported (amount or reference on file) but a cashier
   hasn't verified it yet -- see backend/admin|cashier updateStatus.php,
   the same "depositVerified" flag the Appointments pipeline uses. */
function paymentStatusLabel(b) {
  if (b.depositVerified) return 'Verified';
  if (b.depositAmount || b.depositReference) return 'Awaiting Verification';
  return 'Payment Required';
}

function paymentStatusBadgeClass(b) {
  if (b.depositVerified) return 'bg-emerald-100 text-emerald-700';
  if (b.depositAmount || b.depositReference) return 'bg-amber-100 text-amber-800';
  return 'bg-gray-100 text-gray-500';
}

function renderStats() {
  if (!state.data) return;
  const bookings = state.data.bookings;
  const today = todayISO();

  const todayBookings = bookings.filter(b => b.date === today);
  document.getElementById('statTodayAppointments').textContent = todayBookings.length;
  document.getElementById('statPendingConfirmation').textContent = bookings.filter(b => b.status === 'Pending').length;
  document.getElementById('statPaymentsToVerify').textContent = bookings.filter(b => b.depositAmount && !b.depositVerified).length;
  document.getElementById('statWalkInsToday').textContent = todayBookings.filter(b => b.depositMethod === 'Walk-in').length;
  document.getElementById('statInProgress').textContent = bookings.filter(b => b.status === 'In Progress').length;
  // Same set the Checkout buttons in today's queue cover -- a confirmed booking
  // for a later date isn't ready to check out yet.
  document.getElementById('statReadyForCheckout').textContent = todayBookings.filter(b => ['Confirmed', 'In Progress'].includes(b.status)).length;
}

/* Only actions valid for the booking's current status -- Confirm Booking and
   Assign Staff reuse the same "Admit Client" flow already implemented on
   the Appointments pipeline (assigns staff + records a deposit in one step),
   rather than duplicating that modal here. */
function queueActionsFor(b) {
  const detailsLink = `<a href="appointments.html" class="text-slate-500 hover:text-slate-800 hover:underline">View Details</a>`;
  if (b.status === 'Pending') {
    return `${detailsLink} &middot; <a href="appointments.html" class="text-emerald-700 font-bold hover:underline">${b.staffName ? 'Confirm Booking' : 'Assign Staff'}</a>`;
  }
  if (['Confirmed', 'In Progress'].includes(b.status)) {
    return `${detailsLink} &middot; <a href="payment.html?ref=${encodeURIComponent(b.id)}" class="text-amber-700 font-bold hover:underline">Checkout</a>`;
  }
  return detailsLink;
}

function renderQueue() {
  if (!state.data) return;

  const search = (document.getElementById('queueSearch').value || '').toLowerCase().trim();
  const today = todayISO();
  const queue = state.data.bookings.filter(b =>
    ['Pending', 'Confirmed', 'In Progress'].includes(b.status) &&
    b.date === today &&
    (!search || b.clientName.toLowerCase().includes(search))
  );

  const container = document.getElementById('queueContainer');
  if (!queue.length) {
    container.innerHTML = `
      <div class="text-center py-12">
        <i class="fa-solid fa-mug-hot text-3xl text-gray-200 mb-2"></i>
        <p class="text-xs text-gray-400 italic">No active tickets in today's queue.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = queue.map(b => `
    <div class="p-4 hover:bg-amber-50/40 transition-colors duration-150 flex items-center justify-between gap-3 flex-wrap">
      <div class="flex items-center space-x-3 flex-1 min-w-0">
        <div class="w-9 h-9 rounded-full bg-amber-100 text-amber-700 font-bold text-[11px] flex items-center justify-center shrink-0">${escapeHtml(initialsOf(b.clientName))}</div>
        <div class="min-w-0">
          <p class="font-bold text-slate-800 text-xs truncate capitalize">${escapeHtml(b.clientName)}</p>
          <p class="text-[10px] text-gray-400 truncate">${escapeHtml(b.serviceName || 'No service listed')} &bull; <span class="font-mono">${escapeHtml(b.id)}</span></p>
        </div>
      </div>
      <div class="text-center shrink-0 w-24 hidden sm:block">
        <p class="text-[10px] text-gray-400">${escapeHtml(b.time)}</p>
        <p class="text-[10px] font-semibold text-slate-600 truncate capitalize">${escapeHtml(b.staffName || 'Unassigned')}</p>
      </div>
      <div class="shrink-0 space-y-1 text-center">
        <span class="block text-[10px] font-bold px-2 py-0.5 rounded ${statusBadgeClass(b.status)}${b.status === 'In Progress' ? ' badge-live' : ''}">${escapeHtml(b.status)}</span>
        <span class="block text-[9px] font-bold px-2 py-0.5 rounded ${paymentStatusBadgeClass(b)}">${paymentStatusLabel(b)}</span>
      </div>
      <div class="text-right shrink-0 w-20">
        <p class="font-black text-slate-800 text-xs">${CashierApp.formatCurrency(b.price)}</p>
      </div>
      <div class="shrink-0 text-[10px] w-40 text-right">
        ${queueActionsFor(b)}
      </div>
    </div>
  `).join('');
}


function openWalkInModal() {
  const services = (state.data.servicesByBranch[state.branch] || []);
  const staff = state.data.staffList;

  document.getElementById('servicesCheckboxContainer').innerHTML = services.length
    ? services.map(sv => `
      <label class="flex items-center justify-between text-xs cursor-pointer hover:bg-amber-100/50 rounded-lg px-2 py-1.5 -mx-2 transition-colors duration-150">
        <span class="flex items-center space-x-2">
          <input type="checkbox" class="walkin-service-checkbox text-amber-500 focus:ring-amber-500 rounded" value="${sv.id}">
          <span>${escapeHtml(sv.name)}</span>
        </span>
        <span class="text-gray-400 font-semibold">${CashierApp.formatCurrency(sv.price)}</span>
      </label>
    `).join('')
    : '<p class="text-gray-400 italic">No services configured for this branch.</p>';

  document.getElementById('selectStylist').innerHTML = staff.length
    ? staff.map(s => `<option value="${s.id}">${escapeHtml(titleCase(s.name))} (${escapeHtml(s.role || 'Staff')})</option>`).join('')
    : '<option value="">No stylists available</option>';

  document.getElementById('walkInForm').reset();
  CashierApp.showModal('walkInModal');
}

async function submitWalkIn(e) {
  e.preventDefault();
  const clientName = document.getElementById('inputClientName').value.trim();
  const clientPhone = document.getElementById('inputClientPhone').value.trim();
  const stylistId = document.getElementById('selectStylist').value;
  const serviceIds = Array.from(document.querySelectorAll('.walkin-service-checkbox:checked')).map(cb => cb.value);

  if (!/^[0-9]{11}$/.test(clientPhone)) {
    CashierApp.toast('Contact number must be exactly 11 digits.', 'error');
    return;
  }

  if (!serviceIds.length) {
    CashierApp.toast('Please select at least one service.', 'error');
    return;
  }

  const result = await CashierApp.post('createWalkIn.php', {
    clientName,
    clientPhone,
    stylistId,
    serviceIds
  });

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
}

document.addEventListener('DOMContentLoaded', init);
