/* frontend/cashier/profile.html Profile & Security.
   Account info comes from getDashboardData.php (cashierProfile, myBranch,
   bookings, staffList). "Today at your desk" uses today's Payment Records
   (getPaymentRecords.php) filtered to rows this cashier recorded (mine). */

function initialsOf(name) {
  return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase() || '?';
}

function todayYmd() {
  const d = new Date();
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
}

function timeOf(paidAt) {
  const [h, m] = String(paidAt || '').slice(11, 16).split(':').map(Number);
  if (Number.isNaN(h)) return '';
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}

function methodLabel(method) {
  return (typeof PortalPresentation !== 'undefined' && PortalPresentation.methodLabel(method)) || method || '';
}

function renderAccount(result) {
  const profile = result.cashierProfile || {};
  const terminal = (CASHIER_BRANCH_LABELS[result.myBranch.key] || {}).terminal || '';
  document.getElementById('profileInitials').textContent = initialsOf(profile.name);
  document.getElementById('profileName').textContent = profile.name || 'Cashier';
  document.getElementById('profileEmail').textContent = profile.email || '';
  document.getElementById('profileRole').textContent = profile.role || 'Cashier';
  document.getElementById('profileTerminal').textContent = terminal || 'Terminal';
  document.getElementById('profileAssignedBranch').textContent = result.myBranch.name || result.myBranch.key;
  document.getElementById('profileBranchLocation').textContent = result.myBranch.location || '-';
  document.getElementById('profileBranchTerminal').textContent = terminal.replace(/^Terminal:\s*/, '') || '-';

  const today = todayYmd();
  const visits = (result.bookings || []).filter(b => b.date === today && !['Cancelled', 'Rejected'].includes(b.status)).length;
  const onShift = (result.staffList || []).filter(s => s.onShift).length;
  document.getElementById('branchToday').textContent = visits;
  document.getElementById('branchTodayHint').textContent = `${onShift} staff on shift`;
}

async function renderMyPayments() {
  const list = document.getElementById('myPaymentsList');
  try {
    const today = todayYmd();
    const result = await CashierApp.get('getPaymentRecords.php', { from: today, to: today });
    if (!result.success) throw new Error(result.message);
    const mine = result.records.filter(r => r.mine);
    const checkouts = mine.filter(r => r.type === 'Checkout');
    const total = mine.reduce((s, r) => s + Number(r.amount || 0), 0);
    document.getElementById('meCollected').textContent = CashierApp.formatCurrency(total);
    document.getElementById('meCollectedHint').textContent = `${mine.length} payment${mine.length === 1 ? '' : 's'} today`;
    document.getElementById('meCheckouts').textContent = checkouts.length;
    document.getElementById('meReservations').textContent = mine.length - checkouts.length;

    list.innerHTML = mine.length ? mine.slice(0, 8).map(r => `
      <div class="px-4 sm:px-5 py-3 flex items-center gap-3">
        <span class="w-[4.5rem] shrink-0 whitespace-nowrap text-sm font-semibold text-slate-600">${escapeHtml(timeOf(r.paidAt))}</span>
        <span class="flex-1 min-w-0">
          <span class="block font-bold text-slate-900 capitalize truncate">${escapeHtml(r.clientName)}</span>
          <span class="block text-xs text-slate-500 truncate">${escapeHtml(r.type === 'Checkout' ? (r.invoiceId || 'Checkout') : 'Reservation')} · ${escapeHtml(methodLabel(r.method))}</span>
        </span>
        <span class="font-bold text-slate-900 whitespace-nowrap">${CashierApp.formatCurrency(r.amount)}</span>
      </div>`).join('') + (mine.length > 8 ? `<a href="payment.html" class="block px-5 py-3 text-sm font-bold text-[#06464A] hover:underline">See all ${mine.length}</a>` : '')
      : `<div class="py-10 text-center px-4">
          <i class="fa-solid fa-receipt text-3xl text-gray-300 mb-2 block"></i>
          <p class="text-sm text-slate-500">You have not taken any payments today.</p>
        </div>`;
  } catch (e) {
    console.error(e);
    list.innerHTML = '<p class="py-8 text-center text-sm text-rose-700">Could not load your payments. Refresh to try again.</p>';
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  CashierApp.initChrome();
  renderMyPayments();
  try {
    const result = await CashierApp.fetchDashboard();
    if (result.success) {
      CashierApp.applyBranchChrome(result.myBranch.key);
      CashierApp.updateQueueBadge(result.bookings);
      renderAccount(result);
    }
  } catch (e) { console.error(e); }
});
