/* pages/cashier/staff.html — automatic staff availability + booking assignment.
   Branch is fixed by the logged-in Cashier's account (server-side); see
   database/migrations/007_cashier_branch_lock.sql. staffList/bookings are
   already scoped to that one branch by getDashboardData.php.

   Availability (Available / Busy until [time] / Off Shift) is computed
   server-side from real attendance + today's schedule -- there is no
   manual "Set Shifting Status" control here anymore (see
   backend/cashier/updateStaffStatus.php, now Admin-only). */

const state = {
  data: null,
  branch: null,
  qualifiedStaff: []
};

async function init() {
  CashierApp.initChrome();
  wireStaticEvents();
  await refreshData();
}

async function refreshData() {
  try {
    const result = await CashierApp.fetchDashboard();
    if (!result.success) {
      CashierApp.toast(result.message || 'Failed to load staff data.', 'error');
      return;
    }
    state.data = result;
    state.branch = result.myBranch.key;
    CashierApp.applyBranchChrome(state.branch);
    renderStaff();
    renderTicketOptions();
    CashierApp.updateQueueBadge(result.bookings);
  } catch (e) {
    console.error(e);
  }
}

function availabilityBadgeClass(s) {
  if (!s.onShift) return 'text-rose-500';
  return s.availability === 'Available' ? 'text-emerald-600' : 'text-amber-600';
}

/* Only what a cashier needs to make an assignment decision: availability,
   qualified services, and current workload/schedule. Detailed performance
   (ratings, tips, satisfaction) is an Admin/Owner report, not shown here. */
function renderStaff() {
  if (!state.data) return;
  const staff = state.data.staffList;

  const grid = document.getElementById('staffContainerGrid');
  grid.innerHTML = staff.length ? staff.map(s => `
      <div id="staff-card-${s.id}" class="card-lift border border-gray-200 rounded-xl p-3 space-y-1.5 transition-colors duration-300">
        <div class="flex justify-between items-start">
          <div>
            <p class="font-bold text-slate-800 text-xs capitalize">${escapeHtml(s.name)}</p>
            <p class="text-[10px] text-gray-400">${escapeHtml(s.role || 'Staff')}</p>
          </div>
          <span class="text-[10px] font-bold uppercase ${availabilityBadgeClass(s)}${s.availability.startsWith('Busy') ? ' badge-live' : ''}">${escapeHtml(s.availability)}</span>
        </div>
        <div class="grid grid-cols-2 gap-x-2 text-[10px] text-gray-500 pt-1 border-t border-gray-100">
          <span>Today: ${s.todaysWorkload} booking${s.todaysWorkload === 1 ? '' : 's'}</span>
          <span class="text-right truncate" title="${escapeHtml(s.nextAppointment || '')}">${s.nextAppointment ? 'Next: ' + escapeHtml(s.nextAppointment) : 'No upcoming'}</span>
        </div>
        ${s.onShift ? `
        <div class="flex items-center justify-between pt-1.5 border-t border-gray-100 text-[10px]">
          <span class="text-gray-500">${s.clockInTime ? 'Clocked in ' + escapeHtml(s.clockInTime) : 'On shift'}</span>
          <button data-clockout="${escapeHtml(s.id)}" class="text-rose-700 font-bold hover:underline"><i class="fa-solid fa-right-from-bracket mr-1"></i>Clock Out</button>
        </div>` : ''}
      </div>
    `).join('') : `
    <div class="col-span-2 text-center py-10">
      <i class="fa-solid fa-user-slash text-3xl text-gray-200 mb-2 block"></i>
      <span class="text-xs text-gray-400 italic">No staff assigned to this branch yet.</span>
    </div>
  `;
}

/* Step 1: only Confirmed bookings can go through staff assignment here --
   Pending needs payment verification first (Checkout & Payments), and
   In Progress/Completed already have their staff locked in. */
function renderTicketOptions() {
  const tickets = state.data.bookings.filter(b => b.status === 'Confirmed');
  const select = document.getElementById('staffTicketSelect');
  const prev = select.value;
  select.innerHTML = tickets.length
    ? '<option value="">Select a booking...</option>' + tickets.map(t => `<option value="${t.id}">${escapeHtml(t.id)} — ${escapeHtml(titleCase(t.clientName))} (${escapeHtml(t.serviceName || 'No service')})</option>`).join('')
    : '<option value="">No Confirmed bookings need staffing</option>';
  if (prev && tickets.some(t => t.id === prev)) select.value = prev;
  loadQualifiedStaff(select.value);
}

/* Step 2: only staff who belong to this branch, cover every service on the
   booking, are On Shift, and have no schedule conflict for the full service
   duration -- backend/cashier/getQualifiedStaffForBooking.php mirrors the
   exact same checks assignStaff.php enforces on write. Satisfaction
   score/tips are never used to filter or rank this list. */
async function loadQualifiedStaff(ref) {
  const select = document.getElementById('staffQualifiedSelect');
  if (!ref) {
    state.qualifiedStaff = [];
    select.innerHTML = '<option value="">Select a booking first...</option>';
    return;
  }
  select.innerHTML = '<option value="">Loading...</option>';
  const result = await CashierApp.get('getQualifiedStaffForBooking.php', { id: ref });
  if (!result.success) {
    select.innerHTML = '<option value="">Unable to load staff</option>';
    return;
  }
  state.qualifiedStaff = result.staff;
  select.innerHTML = result.staff.length
    ? result.staff.map(s => `<option value="${s.id}">${escapeHtml(titleCase(s.name))} (${escapeHtml(s.role)})</option>`).join('')
    : '<option value="">No available qualified staff right now</option>';
}

/* Briefly flashes a staff card amber so the cashier can see which stylist
   just changed, since the whole grid re-renders after every action. */
function flashStaffCard(staffId) {
  const card = document.getElementById(`staff-card-${staffId}`);
  if (!card) return;
  card.classList.add('bg-amber-100', 'border-amber-300', 'shadow-lg');
  setTimeout(() => card.classList.remove('bg-amber-100', 'border-amber-300', 'shadow-lg'), 600);
}

async function assignStaffToTicket() {
  const ticketRef = document.getElementById('staffTicketSelect').value;
  const staffId = document.getElementById('staffQualifiedSelect').value;
  if (!ticketRef || !staffId) {
    CashierApp.toast('Select both a booking and an available qualified staff member.', 'error');
    return;
  }
  const result = await CashierApp.post('assignStaff.php', { id: ticketRef, staffId });
  if (result.success) {
    CashierApp.toast('Staff member assigned.', 'success');
    await refreshData();
    flashStaffCard(staffId);
  } else {
    CashierApp.toast(result.message || 'Failed to assign staff.', 'error');
  }
}


/* Manual clock-out for someone who left without signing out
   (backend/cashier/clockOutStaff.php). The time defaults to now. */
function openClockOutModal(staffId) {
  const s = state.data.staffList.find(x => String(x.id) === String(staffId));
  if (!s) return;
  state.clockOutStaffId = s.id;
  document.getElementById('clockOutName').textContent = s.name + ' · ' + (s.role || 'Staff');
  document.getElementById('clockOutSince').textContent = s.clockInTime ? 'Clocked in today at ' + s.clockInTime : 'Currently on shift';
  const now = new Date();
  document.getElementById('clockOutTime').value = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
  CashierApp.showModal('clockOutModal');
}

async function confirmClockOut() {
  const btn = document.getElementById('btnConfirmClockOut');
  btn.disabled = true;
  try {
    const result = await CashierApp.post('clockOutStaff.php', { staffId: state.clockOutStaffId, time: document.getElementById('clockOutTime').value });
    CashierApp.toast(result.message || (result.success ? 'Clocked out.' : 'Failed to clock out.'), result.success ? 'success' : 'error');
    if (result.success) {
      CashierApp.hideModal('clockOutModal');
      await refreshData();
    }
  } finally {
    btn.disabled = false;
  }
}

function wireStaticEvents() {
  document.getElementById('staffTicketSelect').addEventListener('change', (e) => loadQualifiedStaff(e.target.value));
  document.getElementById('btnAssignStaffToTicket').addEventListener('click', assignStaffToTicket);
  document.getElementById('staffContainerGrid').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-clockout]');
    if (btn) openClockOutModal(btn.dataset.clockout);
  });
  document.getElementById('btnCloseClockOutModal').addEventListener('click', () => CashierApp.hideModal('clockOutModal'));
  document.getElementById('btnConfirmClockOut').addEventListener('click', confirmClockOut);
}

document.addEventListener('DOMContentLoaded', init);
