/* frontend/cashier/staff.html automatic staff availability + booking assignment.
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
  qualifiedStaff: [],
  statusFilter: 'all',
  roleFilter: '',
  search: '',
  ticketSearch: '',
  selectedTicket: '',
  selectedStaff: ''
};

const STAFF_GROUPS = { available: 0, busy: 1, off: 2 };

function staffGroup(s) {
  if (!s.onShift) return 'off';
  return s.availability === 'Available' ? 'available' : 'busy';
}

async function init() {
  CashierApp.initChrome();
  wireStaticEvents();
  updateAssignButton();
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

/* Same status colours as the staff portal. Off Shift is neutral: it's normal, not an error. */
function availabilityBadgeStyle(s) {
  const group = staffGroup(s);
  if (group === 'available') return 'background:#DCFCE7;color:#166534;border:1px solid #86EFAC';
  if (group === 'busy') return 'background:#FEF3C7;color:#92400E;border:1px solid #FCD34D';
  return 'background:#F1F5F9;color:#334155;border:1px solid #CBD5E1';
}

function renderStaffControls(staff) {
  const count = group => staff.filter(s => staffGroup(s) === group).length;
  document.getElementById('staffCounts').textContent =
    `${count('available')} Available • ${count('busy')} Busy • ${count('off')} Off Shift`;

  const tabs = [['all', 'All'], ['available', 'Available'], ['busy', 'Busy'], ['off', 'Off Shift']];
  document.getElementById('staffStatusFilters').innerHTML = tabs.map(([key, label]) => {
    const active = state.statusFilter === key;
    return `<button type="button" data-status="${key}" aria-pressed="${active}" class="px-3 py-1.5 rounded-lg text-xs font-semibold" style="${active ? 'background:#006D6F !important;color:#FFFFFF !important' : 'background:transparent !important;color:#475569 !important'}">${label}</button>`;
  }).join('');

  const roles = [...new Set(staff.map(s => s.role || 'Staff'))].sort();
  if (state.roleFilter && !roles.includes(state.roleFilter)) state.roleFilter = '';
  document.getElementById('staffRoleFilter').innerHTML = '<option value="">All roles</option>'
    + roles.map(role => `<option value="${escapeHtml(role)}"${role === state.roleFilter ? ' selected' : ''}>${escapeHtml(role)}</option>`).join('');
}

// Available first, since that's who a cashier is usually looking for.
function visibleStaff(staff) {
  const q = state.search.trim().toLowerCase();
  return staff
    .filter(s => state.statusFilter === 'all' || staffGroup(s) === state.statusFilter)
    .filter(s => !state.roleFilter || (s.role || 'Staff') === state.roleFilter)
    .filter(s => !q || s.name.toLowerCase().includes(q))
    .sort((a, b) => (STAFF_GROUPS[staffGroup(a)] - STAFF_GROUPS[staffGroup(b)]) || a.name.localeCompare(b.name));
}

/* Only what a cashier needs to make an assignment decision: availability,
   qualified services, and current workload/schedule. Detailed performance
   (ratings, tips, satisfaction) is an Admin/Owner report, not shown here. */
function renderStaff() {
  if (!state.data) return;
  renderStaffControls(state.data.staffList);
  const staff = visibleStaff(state.data.staffList);
  const emptyMessage = state.data.staffList.length ? 'No staff match these filters.' : 'No staff assigned to this branch yet.';

  const grid = document.getElementById('staffContainerGrid');
  grid.innerHTML = staff.length ? staff.map(s => `
      <div id="staff-card-${s.id}" class="card-lift border border-gray-200 rounded-xl p-3 space-y-1.5 transition-colors duration-300">
        <div class="flex justify-between items-start">
          <div>
            <p class="font-bold text-slate-800 text-xs capitalize">${escapeHtml(s.name)}</p>
            <p class="text-xs text-gray-400">${escapeHtml(s.role || 'Staff')}</p>
          </div>
          <span class="shrink-0 px-2.5 py-0.5 rounded-full text-xs font-bold whitespace-nowrap${s.availability.startsWith('Busy') ? ' badge-live' : ''}" style="${availabilityBadgeStyle(s)}">${escapeHtml(s.availability)}</span>
        </div>
        <div class="grid grid-cols-2 gap-x-2 text-xs text-gray-500 pt-1 border-t border-gray-100">
          <span>Today: ${s.todaysWorkload} booking${s.todaysWorkload === 1 ? '' : 's'}</span>
          <span class="text-right truncate" title="${escapeHtml(s.nextAppointment || '')}">${s.nextAppointment ? 'Next: ' + escapeHtml(s.nextAppointment) : 'No upcoming'}</span>
        </div>
        ${s.onShift ? `
        <div class="flex items-center justify-between pt-1.5 border-t border-gray-100 text-xs">
          <span class="text-gray-500">${s.clockInTime ? 'Clocked in ' + escapeHtml(s.clockInTime) : 'On shift'}</span>
          <button data-clockout="${escapeHtml(s.id)}" class="text-rose-700 font-bold hover:underline"><i class="fa-solid fa-right-from-bracket mr-1"></i>Clock Out</button>
        </div>` : ''}
      </div>
    `).join('') : `
    <div class="col-span-full text-center py-10">
      <i class="fa-solid fa-user-slash text-3xl text-gray-200 mb-2 block"></i>
      <span class="text-xs text-gray-400 ">${emptyMessage}</span>
    </div>
  `;
}

/* Step 1: Confirmed bookings from today onward, soonest first. Missed bookings
   are left out: they need a reschedule or No-Show, not a stylist. Pending needs
   payment verification first; In Progress and Completed have their staff locked in. */
function assignableTickets() {
  const now = new Date();
  const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
  const q = state.ticketSearch.trim().toLowerCase();
  return state.data.bookings
    .filter(b => b.status === 'Confirmed' && (b.date || '') >= today)
    .filter(b => !q || (b.clientName || '').toLowerCase().includes(q) || (b.id || '').toLowerCase().includes(q))
    .sort((a, b) => String(a.startDateTime).localeCompare(String(b.startDateTime)));
}

function formatDuration(minutes) {
  const total = Number(minutes) || 0;
  if (!total) return '';
  const hours = Math.floor(total / 60);
  const mins = total % 60;
  return [hours ? `${hours} ${hours === 1 ? 'hr' : 'hrs'}` : '', mins ? `${mins} mins` : ''].filter(Boolean).join(' ');
}

function selectableStyle(selected) {
  return selected ? 'border:2px solid #006D6F;background:#E5F2F0' : 'border:1px solid #E8DCC8;background:#FFFDF9';
}

const SELECTED_CHECK = '<span aria-hidden="true" class="shrink-0 w-5 h-5 rounded-full flex items-center justify-center" style="background:#006D6F;color:#FFFFFF"><svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M5 13l4 4L19 7"></path></svg></span>';

function renderTicketOptions() {
  const list = document.getElementById('assignTicketList');
  const tickets = assignableTickets();
  list.innerHTML = tickets.length ? tickets.map(t => {
    const selected = t.id === state.selectedTicket;
    const day = new Date(t.date + 'T00:00:00');
    const details = [formatDuration(t.durationMinutes), t.staffName ? 'Current: ' + titleCase(t.staffName) : 'Unassigned'].filter(Boolean).join(' · ');
    return `<button type="button" role="radio" aria-checked="${selected}" data-ticket="${escapeHtml(t.id)}" class="w-full flex items-center gap-3 rounded-xl p-2.5 text-left" style="${selectableStyle(selected)}">
      <span class="shrink-0 w-16 rounded-lg py-1.5 text-center" style="background:#E5F2F0;color:#06464A">
        <span class="block text-xs font-bold uppercase">${escapeHtml(day.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }))}</span>
        <span class="block text-xs">${escapeHtml(t.time)}</span>
      </span>
      <span class="flex-1 min-w-0">
        <span class="block text-xs font-bold text-slate-800 truncate">${escapeHtml(titleCase(t.clientName))}</span>
        <span class="block text-xs text-gray-500 truncate">${escapeHtml(t.serviceName || 'No service')}</span>
        <span class="block text-xs text-gray-400 truncate">${escapeHtml(details)}</span>
      </span>
      ${selected ? SELECTED_CHECK : ''}
    </button>`;
  }).join('') : `<p class="py-6 text-center text-xs text-gray-400">${state.ticketSearch.trim() ? 'No bookings match your search.' : 'No upcoming Confirmed bookings.'}</p>`;
}

function renderStaffOptions(message) {
  const list = document.getElementById('assignStaffList');
  list.innerHTML = message ? `<p class="py-4 text-center text-xs font-semibold" style="color:#92400E">${escapeHtml(message)}</p>` : state.qualifiedStaff.map(s => {
    const selected = String(s.id) === String(state.selectedStaff);
    const initials = String(s.name).split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();
    return `<button type="button" role="radio" aria-checked="${selected}" data-staff="${escapeHtml(s.id)}" class="w-full flex items-center gap-3 rounded-xl p-2.5 text-left" style="${selectableStyle(selected)}">
      <span aria-hidden="true" class="shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold" style="background:#E5F2F0;color:#06464A">${escapeHtml(initials)}</span>
      <span class="flex-1 min-w-0">
        <span class="block text-xs font-bold text-slate-800 truncate">${escapeHtml(titleCase(s.name))}</span>
        <span class="block text-xs text-gray-500 truncate">${escapeHtml(s.role)}</span>
      </span>
      ${selected ? SELECTED_CHECK : ''}
    </button>`;
  }).join('');
  updateAssignButton();
}

function updateAssignButton() {
  const btn = document.getElementById('btnAssignStaffToTicket');
  const ready = !!(state.selectedTicket && state.selectedStaff);
  btn.disabled = !ready;
  btn.style.cssText = ready
    ? 'background:#006D6F !important;color:#FFFFFF !important;border:1px solid #006D6F !important;cursor:pointer'
    : 'background:#F7F0E4 !important;color:#475569 !important;border:1px solid #E8DCC8 !important;cursor:not-allowed';
}

/* Step 2: staff at this branch who cover every service on the booking and have
   no schedule conflict for its full duration (on shift too, for a booking today).
   Satisfaction score/tips are never used to filter or rank this list. */
async function loadQualifiedStaff(ref) {
  const step = document.getElementById('assignStaffStep');
  state.selectedStaff = '';
  state.qualifiedStaff = [];
  step.classList.toggle('hidden', !ref);
  if (!ref) {
    updateAssignButton();
    return;
  }
  renderStaffOptions('Loading...');
  const result = await CashierApp.get('getQualifiedStaffForBooking.php', { id: ref });
  if (ref !== state.selectedTicket) return; // Another booking was chosen meanwhile.
  if (!result.success) {
    renderStaffOptions('Unable to load staff. Please try again.');
    return;
  }
  state.qualifiedStaff = result.staff;
  renderStaffOptions(result.staff.length ? '' : 'No qualified staff is free at this time. Reschedule the booking or choose another time.');
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
  const ticketRef = state.selectedTicket;
  const staffId = state.selectedStaff;
  if (!ticketRef || !staffId) {
    CashierApp.toast('Select both a booking and an available qualified staff member.', 'error');
    return;
  }
  const result = await CashierApp.post('assignStaff.php', { id: ticketRef, staffId });
  if (result.success) {
    CashierApp.toast('Staff member assigned.', 'success');
    state.selectedTicket = '';
    await refreshData();
    await loadQualifiedStaff('');
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
  document.getElementById('staffStatusFilters').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-status]');
    if (!btn) return;
    state.statusFilter = btn.dataset.status;
    renderStaff();
  });
  document.getElementById('staffRoleFilter').addEventListener('change', (e) => {
    state.roleFilter = e.target.value;
    renderStaff();
  });
  document.getElementById('staffSearch').addEventListener('input', (e) => {
    state.search = e.target.value;
    renderStaff();
  });
  document.getElementById('assignTicketSearch').addEventListener('input', (e) => {
    state.ticketSearch = e.target.value;
    renderTicketOptions();
  });
  document.getElementById('assignTicketList').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ticket]');
    if (!btn) return;
    state.selectedTicket = btn.dataset.ticket;
    renderTicketOptions();
    loadQualifiedStaff(state.selectedTicket);
  });
  document.getElementById('assignStaffList').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-staff]');
    if (!btn) return;
    state.selectedStaff = btn.dataset.staff;
    renderStaffOptions('');
  });
  document.getElementById('btnAssignStaffToTicket').addEventListener('click', assignStaffToTicket);
  document.getElementById('staffContainerGrid').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-clockout]');
    if (btn) openClockOutModal(btn.dataset.clockout);
  });
  document.getElementById('btnCloseClockOutModal').addEventListener('click', () => CashierApp.hideModal('clockOutModal'));
  document.getElementById('btnConfirmClockOut').addEventListener('click', confirmClockOut);
}

document.addEventListener('DOMContentLoaded', init);
