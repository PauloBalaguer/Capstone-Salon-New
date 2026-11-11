/* frontend/cashier/profile.html Profile & Security.
   Account info comes from getDashboardData.php (cashierProfile, myBranch,
   bookings, staffList). A cashier's own payments and shift totals are on
   Payment Records ("Your shift today"). */

function renderAccount(result) {
  const profile = result.cashierProfile || {};
  const terminal = (CASHIER_BRANCH_LABELS[result.myBranch.key] || {}).terminal || '';
  document.getElementById('profileName').textContent = profile.name || 'Cashier';
  document.getElementById('profileEmail').textContent = profile.email || '';
  document.getElementById('profileRole').textContent = profile.role || 'Cashier';
  document.getElementById('profileTerminal').textContent = terminal || 'Terminal';
  document.getElementById('profileAssignedBranch').textContent = result.myBranch.name || result.myBranch.key;
  document.getElementById('profileBranchLocation').textContent = result.myBranch.location || '-';
  document.getElementById('profileBranchTerminal').textContent = terminal.replace(/^Terminal:\s*/, '') || '-';

}

document.addEventListener('DOMContentLoaded', async () => {
  CashierApp.initChrome();
  try {
    const result = await CashierApp.fetchDashboard();
    if (result.success) {
      CashierApp.applyBranchChrome(result.myBranch.key);
      CashierApp.updateQueueBadge(result.bookings);
      renderAccount(result);
    }
  } catch (e) { console.error(e); }
});
