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
  // On Break counts with Busy (clocked in, but can't take a client now).
  return s.status === 'On Shift' ? 'available' : 'busy';
}

async function init() {
  CashierApp.initChrome();
  wireStaticEvents();
  updateAssignButton();
  await refreshData();
  CashierApp.liveSync(refreshData, () => state.branch);
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
  if (group === 'available') return 'background:#E6F4EC;color:#115C39;border:1px solid #E6F4EC';
  if (group === 'busy') return 'background:#FFF3D6;color:#6C4C09;border:1px solid #E0B84C';
  return 'background:#F6F8F7;color:#38443E;border:1px solid #CDD6D0';
}

function renderStaffControls(staff) {
  const count = group => staff.filter(s => staffGroup(s) === group).length;
  document.getElementById('staffCounts').textContent = `${staff.length} staff in total`;
  document.getElementById('statAvailable').textContent = count('available');
  document.getElementById('statBusy').textContent = count('busy');
  document.getElementById('statOff').textContent = count('off');
  document.querySelectorAll('.stat-card[data-status]').forEach(card => {
    card.classList.toggle('is-active', card.dataset.status === state.statusFilter);
  });

  const tabs = [['all', 'All', staff.length], ['available', 'Available', count('available')], ['busy', 'Busy', count('busy')], ['off', 'Off shift', count('off')]];
  document.getElementById('staffStatusFilters').innerHTML = tabs.map(([key, label, n]) => {
    const active = state.statusFilter === key;
    return `<button type="button" data-status="${key}" aria-pressed="${active}" class="queue-tab${active ? ' is-active' : ''}">${label}<span>${n}</span></button>`;
  }).join('');

  const roles = [...new Set(staff.map(s => s.role || 'Staff'))].sort();
  if (state.roleFilter && !roles.includes(state.roleFilter)) state.roleFilter = '';
  document.getElementById('staffRoleFilter').innerHTML = '<option value="">All roles</option>'
    + roles.map(role => `<option value="${escapeHtml(role)}"${role === state.roleFilter ? ' selected' : ''}>${escapeHtml(role)}</option>`).join('');
}

function initials(name) {
  return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || '?';
}

/* "2026-11-05 07:00 PM" -> "Today, 07:00 PM" / "Tomorrow, ..." / "Nov 8, ...". */
function friendlyNext(label) {
  const m = String(label || '').match(/^(\d{4}-\d{2}-\d{2})\s+(.*)$/);
  if (!m) return label || '';
  const ymd = d => [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
  const now = new Date();
  if (m[1] === ymd(now)) return 'Today, ' + m[2];
  if (m[1] === ymd(new Date(now.getTime() + 86400000))) return 'Tomorrow, ' + m[2];
  return new Date(m[1] + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ', ' + m[2];
}

const AVATAR_STYLE = {
  available: 'bg-emerald-100 text-[color:var(--success)]',
  busy: 'bg-amber-100 text-[color:var(--warn)]',
  off: 'bg-[color:var(--bg)] text-slate-500'
};

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
  grid.innerHTML = staff.length ? staff.map(s => {
    const group = staffGroup(s);
    return `
      <div id="staff-card-${s.id}" class="rounded-xl border border-gray-200 bg-white p-4 flex flex-col gap-3 transition-colors duration-300${group === 'off' ? ' opacity-80' : ''}">
        <div class="flex items-start gap-3">
          <span aria-hidden="true" class="avatar ${AVATAR_STYLE[group]}">${escapeHtml(initials(s.name))}</span>
          <div class="flex-1 min-w-0">
            <button type="button" data-staff-details="${escapeHtml(s.id)}" class="cp-link-btn font-bold text-slate-900 capitalize truncate" aria-label="View details for ${escapeHtml(s.name)}">${escapeHtml(s.name)}</button>
            <p class="text-sm text-slate-500 truncate">${escapeHtml(s.role || 'Staff')}</p>
          </div>
          <span class="shrink-0 px-2.5 py-0.5 rounded-full text-xs font-bold whitespace-nowrap${s.availability.startsWith('Busy') ? ' badge-live' : ''}" style="${availabilityBadgeStyle(s)}">${escapeHtml(s.availability)}</span>
        </div>
        <dl class="grid grid-cols-2 gap-2 text-sm">
          <div class="rounded-lg bg-[color:var(--card)] border border-[color:var(--border)] px-3 py-2">
            <dt class="text-xs text-slate-500">Today</dt>
            <dd class="font-semibold text-slate-800">${s.todaysWorkload} booking${s.todaysWorkload === 1 ? '' : 's'}</dd>
          </div>
          <div class="rounded-lg bg-[color:var(--card)] border border-[color:var(--border)] px-3 py-2 min-w-0">
            <dt class="text-xs text-slate-500">Next</dt>
            <dd class="font-semibold text-slate-800 leading-snug" title="${escapeHtml(s.nextAppointment || '')}">${s.nextAppointment ? escapeHtml(friendlyNext(s.nextAppointment)) : 'Nothing booked'}</dd>
          </div>
        </dl>
        <div class="flex items-center justify-between gap-2 text-sm">
          <span class="text-slate-500"><i class="fa-regular fa-clock mr-1"></i>${s.onShift ? (s.clockInTime ? 'Clocked in ' + escapeHtml(s.clockInTime) : 'On shift') : 'Not clocked in'}</span>
          <span class="text-slate-600 font-semibold">${openAssignments(s).length} open assignment${openAssignments(s).length === 1 ? '' : 's'}</span>
        </div>
        <div class="cp-staff-actions">
          <button type="button" data-staff-details="${escapeHtml(s.id)}" class="btn-row cp-btn-ghost">Details</button>
          <a href="#assignCard" class="btn-row cp-btn-outline"><i class="fa-solid fa-user-check" aria-hidden="true"></i>Assign Booking</a>
          ${s.onShift ? `<button type="button" data-clockout="${escapeHtml(s.id)}" class="btn-row border border-[#9C1E15]/30 text-[color:var(--danger)] hover:bg-rose-50"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Clock out</button>` : ''}
        </div>
      </div>`;
  }).join('') : `
    <div class="col-span-full text-center py-12">
      <i class="fa-solid fa-user-slash text-3xl text-gray-300 mb-2 block"></i>
      <span class="text-sm text-slate-500">${emptyMessage}</span>
    </div>
  `;
}

/* Bookings this person is assigned to that are not finished yet. */
function openAssignments(s) {
  return (state.data?.bookings || []).filter(b => String(b.staffId) === String(s.id)
    && ['Pending', 'Confirmed', 'In Progress', 'Reschedule Requested'].includes(b.status));
}

function openStaffDetails(staffId) {
  const s = (state.data?.staffList || []).find(x => String(x.id) === String(staffId));
  if (!s) return;
  const open = openAssignments(s).sort((a, b) => String(a.startDateTime || '').localeCompare(String(b.startDateTime || '')));
  const item = (label, value) => `<div><dt class="field-label mb-0.5">${label}</dt><dd class="text-slate-800 font-semibold">${value}</dd></div>`;
  document.getElementById('staffDetailsTitle').textContent = titleCase(s.name);
  document.getElementById('staffDetailsBody').innerHTML = `
    <div class="space-y-4">
      <div class="flex items-center gap-3">
        <span aria-hidden="true" class="avatar ${AVATAR_STYLE[staffGroup(s)]}">${escapeHtml(initials(s.name))}</span>
        <div class="min-w-0"><p class="font-bold text-lg text-slate-900 capitalize">${escapeHtml(s.name)}</p><p class="text-sm text-slate-500">${escapeHtml(s.role || 'Staff')}</p></div>
        <span class="ml-auto shrink-0 px-2.5 py-0.5 rounded-full text-xs font-bold" style="${availabilityBadgeStyle(s)}">${escapeHtml(s.availability)}</span>
      </div>
      <dl class="grid grid-cols-2 gap-3 text-sm rounded-xl border border-gray-200 bg-[color:var(--card)] p-4">
        ${item('Branch', escapeHtml(document.getElementById('sidebarBranchTitle')?.textContent || ''))}
        ${item('Shift status', s.onShift ? (s.clockInTime ? 'Clocked in ' + escapeHtml(s.clockInTime) : 'On shift') : 'Not clocked in')}
        ${item('Completed services', escapeHtml(s.completedCount ?? 0))}
        ${item('Average rating', s.rating ? escapeHtml(s.rating) + ' / 5' : 'No ratings yet')}
        ${item('Attendance', escapeHtml(s.attendance ?? '-') + (typeof s.attendance === 'number' ? '%' : ''))}
        ${item('Tips received', CashierApp.formatCurrency(s.totalTips || 0))}
        <div class="col-span-2"><dt class="field-label mb-0.5">Services offered</dt><dd class="text-slate-800">${escapeHtml(s.specialties || '')}</dd></div>
      </dl>
      <div>
        <p class="font-bold text-slate-800 mb-2">Assigned bookings (${open.length})</p>
        ${open.length ? `<ul class="space-y-2">${open.map(b => `
          <li class="rounded-xl border border-gray-200 p-3 flex flex-wrap justify-between gap-2 text-sm">
            <span class="min-w-0"><span class="font-semibold text-slate-900 capitalize">${escapeHtml(b.clientName)}</span><span class="block text-slate-500">${escapeHtml(b.serviceName || '')} · <span class="font-mono">${escapeHtml(b.id)}</span></span></span>
            <span class="text-right"><span class="block font-semibold">${escapeHtml(b.date || '')} ${escapeHtml(b.time || '')}</span><span class="cp-badge ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span></span>
          </li>`).join('')}</ul>` : '<p class="text-sm text-slate-500">No open bookings assigned.</p>'}
      </div>
    </div>`;
  CashierApp.showModal('staffDetailsModal');
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

function selectableClass(selected) {
  return selected ? 'border-teal-500 bg-teal-100 ring-1 ring-teal-500' : 'border-[color:var(--border)] bg-[color:var(--card)] hover:border-teal-500';
}

const SELECTED_CHECK = '<span aria-hidden="true" class="shrink-0 w-5 h-5 rounded-full flex items-center justify-center" style="background:var(--brand);color:#FFFFFF"><svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M5 13l4 4L19 7"></path></svg></span>';

function renderTicketOptions() {
  const list = document.getElementById('assignTicketList');
  const tickets = assignableTickets();
  if (!state.ticketSearch.trim()) {
    const unassigned = tickets.filter(t => !t.staffName).length;
    document.getElementById('statToAssign').textContent = tickets.length;
    document.getElementById('statToAssignHint').textContent = unassigned ? `${unassigned} still without a stylist` : 'All have a stylist';
  }
  list.innerHTML = tickets.length ? tickets.map(t => {
    const selected = t.id === state.selectedTicket;
    const day = new Date(t.date + 'T00:00:00');
    const details = [formatDuration(t.durationMinutes), t.staffName ? 'Current: ' + titleCase(t.staffName) : 'Unassigned'].filter(Boolean).join(' · ');
    return `<button type="button" role="radio" aria-checked="${selected}" data-ticket="${escapeHtml(t.id)}" class="w-full flex items-center gap-3 rounded-xl border p-3 text-justify ${selectableClass(selected)}">
      <span class="shrink-0 w-[4.75rem] rounded-lg py-1.5 text-center bg-white border border-[color:var(--border)] text-teal-700">
        <span class="block text-xs font-bold uppercase">${escapeHtml(day.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }))}</span>
        <span class="block text-xs whitespace-nowrap">${escapeHtml(t.time)}</span>
      </span>
      <span class="flex-1 min-w-0">
        <span class="block font-bold text-slate-900 truncate">${escapeHtml(titleCase(t.clientName))}</span>
        <span class="block text-sm text-slate-600 truncate">${escapeHtml(t.serviceName || 'No service')}</span>
        <span class="block text-xs ${t.staffName ? 'text-slate-500' : 'text-[color:var(--warn)] font-semibold'} truncate">${escapeHtml(details)}</span>
      </span>
      ${selected ? SELECTED_CHECK : ''}
    </button>`;
  }).join('') : `<p class="py-6 text-center text-sm text-slate-500">${state.ticketSearch.trim() ? 'No bookings match your search.' : 'No upcoming Confirmed bookings.'}</p>`;
}

function renderStaffOptions(message) {
  const list = document.getElementById('assignStaffList');
  list.innerHTML = message ? `<p class="py-4 px-3 text-center text-sm font-semibold rounded-xl bg-amber-50 text-[color:var(--warn)]">${escapeHtml(message)}</p>` : state.qualifiedStaff.map(s => {
    const selected = String(s.id) === String(state.selectedStaff);
    return `<button type="button" role="radio" aria-checked="${selected}" data-staff="${escapeHtml(s.id)}" class="w-full flex items-center gap-3 rounded-xl border p-3 text-justify ${selectableClass(selected)}">
      <span aria-hidden="true" class="avatar w-10 h-10 bg-white border border-[color:var(--border)] text-teal-700">${escapeHtml(initials(s.name))}</span>
      <span class="flex-1 min-w-0">
        <span class="block font-bold text-slate-900 truncate">${escapeHtml(titleCase(s.name))}</span>
        <span class="block text-sm text-slate-500 truncate">${escapeHtml(s.role)}</span>
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
  card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  card.classList.add('bg-amber-50', 'border-[#774800]/40', 'shadow-lg');
  setTimeout(() => card.classList.remove('bg-amber-50', 'border-[#774800]/40', 'shadow-lg'), 900);
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
  document.getElementById('staffContainerGrid').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-staff-details]');
    if (btn) openStaffDetails(btn.dataset.staffDetails);
  });
  document.getElementById('btnCloseStaffDetails').addEventListener('click', () => CashierApp.hideModal('staffDetailsModal'));
  document.getElementById('staffStatusFilters').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-status]');
    if (!btn) return;
    state.statusFilter = btn.dataset.status;
    renderStaff();
  });
  document.querySelectorAll('.stat-card[data-status]').forEach(card => card.addEventListener('click', () => {
    state.statusFilter = state.statusFilter === card.dataset.status ? 'all' : card.dataset.status;
    renderStaff();
  }));
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
