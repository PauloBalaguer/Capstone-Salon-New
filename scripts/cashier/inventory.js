/* pages/cashier/inventory.html — stock analytics, adjustments, product catalog, EOD register.
   Branch is fixed by the logged-in Cashier's account (server-side); see
   database/migrations/007_cashier_branch_lock.sql. inventory/eodByBranch are
   already scoped to that one branch by getDashboardData.php. branchId is
   still sent on writes for Admin accounts (no branch lock), and is
   simply ignored server-side for a real Cashier. */

const state = {
  data: null,
  branch: null,
  pendingAdjustment: null,
  lowStockOnly: false
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
      CashierApp.toast(result.message || 'Failed to load inventory data.', 'error');
      return;
    }
    state.data = result;
    state.branch = result.myBranch.key;
    CashierApp.applyBranchChrome(state.branch);
    renderInventory();
    renderEod();
    CashierApp.updateQueueBadge(result.bookings);
  } catch (e) {
    console.error(e);
  }
}


function inventoryStatus(item) {
  return item.stock <= item.maxStock * 0.2 ? 'Critical' : 'Optimal';
}

function renderInventory() {
  if (!state.data) return;
  const items = state.data.inventory;

  const critical = items.filter(i => inventoryStatus(i) === 'Critical');
  document.getElementById('invTotalSkus').textContent = `${items.length} SKUs`;
  document.getElementById('invOptimalSkus').textContent = `${items.length - critical.length} Safe`;
  document.getElementById('invCriticalSkus').textContent = `${critical.length} Warnings`;

  const search = (document.getElementById('inventorySearch').value || '').toLowerCase().trim();
  const visible = items.filter(i =>
    (!state.lowStockOnly || inventoryStatus(i) === 'Critical') &&
    (!search || i.name.toLowerCase().includes(search) || (i.category || '').toLowerCase().includes(search))
  );

  const tbody = document.getElementById('inventoryTableBody');
  if (!visible.length) {
    tbody.innerHTML = `
      <tr><td colspan="5" class="py-12 text-center">
        <i class="fa-solid fa-box-open text-3xl text-gray-200 mb-2 block"></i>
        <span class="text-xs text-gray-400 italic">No matching products found.</span>
      </td></tr>
    `;
  } else {
    tbody.innerHTML = visible.map(item => {
      const status = inventoryStatus(item);
      const pct = Math.min(100, Math.round((item.stock / (item.maxStock || 1)) * 100));
      const barColor = status === 'Critical' ? 'bg-rose-500' : 'bg-emerald-500';
      const badgeClass = status === 'Critical' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800';
      return `
        <tr class="hover:bg-amber-50/40 transition-colors duration-150">
          <td class="p-3 font-semibold text-slate-800">${escapeHtml(item.name)}</td>
          <td class="p-3 text-gray-500">${escapeHtml(item.category)}</td>
          <td class="p-3 text-center font-bold">${item.stock} <span class="text-gray-400 font-normal">/ ${item.maxStock}</span></td>
          <td class="p-3">
            <div class="w-full bg-gray-200 rounded-full h-1.5 overflow-hidden">
              <div class="${barColor} h-1.5 rounded-full transition-all duration-500 ease-out" style="width:${pct}%"></div>
            </div>
          </td>
          <td class="p-3 text-center"><span class="text-[10px] font-bold px-2 py-0.5 rounded ${badgeClass}${status === 'Critical' ? ' badge-live' : ''}">${status}</span></td>
        </tr>
      `;
    }).join('');
  }

  const select = document.getElementById('invFormProductSelect');
  const previousValue = select.value;
  select.innerHTML = items.map(i => `<option value="${i.id}">${escapeHtml(i.name)} (${i.stock} in stock)</option>`).join('')
    || '<option value="">No products available</option>';
  if (previousValue && items.some(i => i.id === previousValue)) select.value = previousValue;
}


function renderEod() {
  if (!state.data) return;
  const eod = (state.data.eodByBranch && state.data.eodByBranch[state.branch]) || { invoiceCount: 0, grossRevenue: 0 };
  document.getElementById('eodInvoiceCount').textContent = `${eod.invoiceCount} Trans`;
  document.getElementById('eodGrossRevenue').textContent = CashierApp.formatCurrency(eod.grossRevenue);

  const critical = state.data.inventory.filter(i => inventoryStatus(i) === 'Critical');
  const card = document.getElementById('eodStockStatusCard');
  const text = document.getElementById('eodStockSummaryText');
  if (critical.length) {
    card.className = 'p-3 bg-rose-50 rounded-xl border border-rose-200 text-xs text-rose-800';
    text.textContent = `${critical.length} product(s) below critical threshold.`;
  } else {
    card.className = 'p-3 bg-emerald-50 rounded-xl border border-emerald-200 text-xs text-emerald-800';
    text.textContent = 'All item nodes verified clean.';
  }
}


function setLowStockFilter(lowStockOnly) {
  state.lowStockOnly = lowStockOnly;
  const allBtn = document.getElementById('btnFilterAllStock');
  const lowBtn = document.getElementById('btnFilterLowStock');
  allBtn.className = `px-2.5 py-1 rounded text-[10px] font-bold ${lowStockOnly ? 'text-slate-500' : 'bg-white text-slate-800 shadow-sm'}`;
  lowBtn.className = `px-2.5 py-1 rounded text-[10px] font-bold ${lowStockOnly ? 'bg-white text-rose-700 shadow-sm' : 'text-slate-500'}`;
  renderInventory();
}

function openAdjustmentConfirm(e) {
  e.preventDefault();
  const select = document.getElementById('invFormProductSelect');
  const productId = select.value;
  const productName = select.options[select.selectedIndex]?.textContent || '';
  const adjType = document.querySelector('input[name="adjType"]:checked').value;
  const quantity = parseInt(document.getElementById('invFormQty').value, 10);
  const reason = document.getElementById('invFormReason').value.trim();

  if (!productId || !quantity || quantity < 1 || !reason) {
    CashierApp.toast('Please complete all adjustment fields.', 'error');
    return;
  }

  state.pendingAdjustment = { id: productId, adjType, quantity, reason };

  document.getElementById('confirmAdjProduct').textContent = productName;
  document.getElementById('confirmAdjAction').textContent = adjType === 'add' ? 'Restock (+)' : 'Deduct (-)';
  document.getElementById('confirmAdjQty').textContent = quantity;
  document.getElementById('confirmAdjReason').textContent = reason;

  CashierApp.showModal('stockAdjustmentConfirmModal');
}

async function confirmAdjustment() {
  if (!state.pendingAdjustment) return;
  const result = await CashierApp.post('adjustStock.php', state.pendingAdjustment);
  if (result.success) {
    CashierApp.toast('Stock adjustment applied.', 'success');
    CashierApp.hideModal('stockAdjustmentConfirmModal');
    document.getElementById('inventoryAdjustmentForm').reset();
    state.pendingAdjustment = null;
    await refreshData();
  } else {
    CashierApp.toast(result.message || 'Failed to adjust stock.', 'error');
  }
}

async function openAdjustmentLog() {
  document.getElementById('logBranchName').textContent = state.branch.toUpperCase();

  const result = await CashierApp.get('getAdjustmentLog.php', { branchId: state.branch });
  const tbody = document.getElementById('inventoryLogTableBody');
  if (result.success && result.log.length) {
    tbody.innerHTML = result.log.map(row => `
      <tr>
        <td class="p-2.5">${escapeHtml(row.timestamp)}</td>
        <td class="p-2.5 font-semibold">${escapeHtml(row.product)}</td>
        <td class="p-2.5">${row.action === 'add' ? 'Restock (+)' : 'Deduct (-)'}</td>
        <td class="p-2.5 text-center">${row.quantity}</td>
        <td class="p-2.5">${escapeHtml(row.reason)}</td>
        <td class="p-2.5 text-center">${row.previousStock} &rarr; ${row.newStock}</td>
      </tr>
    `).join('');
  } else {
    tbody.innerHTML = '<tr><td colspan="6" class="p-4 text-center text-gray-400 italic">No adjustments logged for this branch yet.</td></tr>';
  }
  CashierApp.showModal('inventoryLogModal');
}

/* The backend hard-blocks closing when critical records are unfinished
   (an unpaid Completed checkout, a payment still Pending/processing) --
   this confirm is only for the softer case of still-active queue tickets,
   which aren't blockers on their own. */
async function triggerEod() {
  const unsettledCount = (state.data?.bookings || [])
    .filter(b => ['Pending', 'Confirmed', 'In Progress'].includes(b.status)).length;

  if (unsettledCount > 0) {
    const proceed = confirm(`${unsettledCount} booking(s) are still active (not yet completed) for this branch. Close the business day anyway? Completed-but-unpaid checkouts will still block closing.`);
    if (!proceed) return;
  }

  const result = await CashierApp.post('closeEod.php', { branchId: state.branch });
  if (result.success) {
    const clockedOut = result.clockedOut || [];
    CashierApp.toast(`Business day closed: ${result.invoiceCount} transactions, ${CashierApp.formatCurrency(result.grossRevenue)} gross.`
      + (clockedOut.length ? ` Clocked out (didn't sign out): ${clockedOut.join(', ')}.` : ''), 'success');
    await refreshData();
  } else {
    CashierApp.toast(result.message || 'Failed to close the business day.', 'error');
  }
}


function wireStaticEvents() {
  document.getElementById('inventorySearch').addEventListener('input', renderInventory);
  document.getElementById('btnFilterAllStock').addEventListener('click', () => setLowStockFilter(false));
  document.getElementById('btnFilterLowStock').addEventListener('click', () => setLowStockFilter(true));

  document.getElementById('inventoryAdjustmentForm').addEventListener('submit', openAdjustmentConfirm);
  document.getElementById('btnCloseConfirmAdjustmentModal').addEventListener('click', () => CashierApp.hideModal('stockAdjustmentConfirmModal'));
  document.getElementById('btnCancelStockAdjustment').addEventListener('click', () => CashierApp.hideModal('stockAdjustmentConfirmModal'));
  document.getElementById('btnConfirmStockAdjustment').addEventListener('click', confirmAdjustment);

  document.getElementById('btnViewAdjLog').addEventListener('click', openAdjustmentLog);
  document.getElementById('btnCloseInventoryLogModal').addEventListener('click', () => CashierApp.hideModal('inventoryLogModal'));

  document.getElementById('btnTriggerEOD').addEventListener('click', triggerEod);
}

document.addEventListener('DOMContentLoaded', init);
