/* frontend/cashier/inventory.html stock analytics, adjustments, product catalog, EOD register.
   Branch is fixed by the logged-in Cashier's account (server-side); see
   database/migrations/007_cashier_branch_lock.sql. inventory/eodByBranch are
   already scoped to that one branch by getDashboardData.php. branchId is
   still sent on writes for Admin accounts (no branch lock), and is
   simply ignored server-side for a real Cashier. */

const state = {
  data: null,
  branch: null,
  pendingAdjustment: null,
  lowStockOnly: false,
  page: 1,
  pageSize: 10,
  productId: '',
  logRows: [],
  logPage: 1,
  logPageSize: 10
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


/* "Low" is 20% of the item's maximum stock or less. */
function inventoryStatus(item) {
  if (item.stock <= 0) return 'Out of Stock';
  return item.stock <= item.maxStock * 0.2 ? 'Low Stock' : 'In Stock';
}

function needsAttention(item) {
  return inventoryStatus(item) !== 'In Stock';
}

function statusBadgeStyle(status) {
  if (status === 'Out of Stock') return 'background:#FDECEA;color:#9C1E15;border:1px solid #FDECEA';
  if (status === 'Low Stock') return 'background:#FFF3D6;color:#6C4C09;border:1px solid #E0B84C';
  return 'background:#E6F4EC;color:#115C39;border:1px solid #E6F4EC';
}

function renderInventory() {
  if (!state.data) return;
  const items = state.data.inventory;

  const critical = items.filter(needsAttention);
  document.getElementById('invTotalSkus').textContent = items.length;
  document.getElementById('invOptimalSkus').textContent = items.length - critical.length;
  document.getElementById('invCriticalSkus').textContent = critical.length;

  // Items needing attention first, then alphabetical.
  const rank = item => ({ 'Out of Stock': 0, 'Low Stock': 1, 'In Stock': 2 })[inventoryStatus(item)];
  const search = (document.getElementById('inventorySearch').value || '').toLowerCase().trim();
  const visible = items.filter(i =>
    (!state.lowStockOnly || needsAttention(i)) &&
    (!search || i.name.toLowerCase().includes(search) || (i.category || '').toLowerCase().includes(search))
  ).sort((a, b) => (rank(a) - rank(b)) || a.name.localeCompare(b.name));

  const totalPages = Math.max(1, Math.ceil(visible.length / state.pageSize));
  state.page = Math.min(state.page, totalPages);
  const first = (state.page - 1) * state.pageSize;
  const pageItems = visible.slice(first, first + state.pageSize);

  const tbody = document.getElementById('inventoryTableBody');
  if (!pageItems.length) {
    tbody.innerHTML = `
      <tr><td colspan="6" class="py-12 text-center">
        <i class="fa-solid fa-box-open text-3xl text-gray-200 mb-2 block"></i>
        <span class="text-xs text-gray-400 ">No matching products found.</span>
      </td></tr>
    `;
  } else {
    tbody.innerHTML = pageItems.map(item => {
      const status = inventoryStatus(item);
      const pct = Math.min(100, Math.round((item.stock / (item.maxStock || 1)) * 100));
      const barColor = status === 'In Stock' ? 'bg-emerald-500' : 'bg-rose-500';
      return `
        <tr class="cp-inv-row" ${String(item.id) === String(state.productId) ? 'style="background:var(--brand-soft)"' : ''}>
          <td data-label="Product" class="px-3 py-2 font-semibold text-slate-800">${escapeHtml(item.name)}</td>
          <td data-label="Category" class="px-3 py-2 text-gray-500">${escapeHtml(item.category)}</td>
          <td data-label="Available" class="px-3 py-2 text-center font-bold whitespace-nowrap">${item.stock} <span class="text-gray-400 font-normal">/ ${item.maxStock}</span>
            <div class="cp-stock-bar" aria-hidden="true"><div class="${barColor}" style="width:${pct}%"></div></div>
          </td>
          <td data-label="Minimum stock" class="px-3 py-2 text-center whitespace-nowrap" title="Shown as Low Stock at 20% of maximum or less">${Math.floor(item.maxStock * 0.2)}</td>
          <td data-label="Status" class="px-3 py-2 text-center"><span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold whitespace-nowrap" style="${statusBadgeStyle(status)}">${status}</span></td>
          <td class="px-3 py-2 text-right cp-cell-action"><button type="button" data-adjust="${escapeHtml(item.id)}" class="btn-row cp-btn-outline" aria-label="Adjust stock for ${escapeHtml(item.name)}">Adjust</button></td>
        </tr>
      `;
    }).join('');
  }

  document.getElementById('btnFilterLowStock').textContent = `Low Stock (${critical.length})`;
  document.getElementById('inventoryPager').classList.toggle('hidden', totalPages <= 1);
  document.getElementById('inventoryPageLabel').textContent = visible.length ? `Showing ${first + 1}-${Math.min(first + state.pageSize, visible.length)} of ${visible.length}` : '';
  document.getElementById('inventoryPageNumber').textContent = `Page ${state.page} of ${totalPages}`;
  document.getElementById('btnInvPrev').disabled = state.page <= 1;
  document.getElementById('btnInvNext').disabled = state.page >= totalPages;

  renderSelectedProduct();
}

function selectedProduct() {
  return (state.data?.inventory || []).find(i => String(i.id) === String(state.productId)) || null;
}

function renderSelectedProduct() {
  const label = document.getElementById('invProductSelected');
  const item = selectedProduct();
  label.classList.toggle('hidden', !item);
  label.textContent = item ? `${item.name}: ${item.stock} in stock` : '';
  renderPreview();
}

function renderProductList() {
  const list = document.getElementById('invProductList');
  const q = document.getElementById('invProductSearch').value.trim().toLowerCase();
  const items = (state.data?.inventory || []).filter(i => !q || i.name.toLowerCase().includes(q));
  list.innerHTML = items.length ? items.map(i => `
    <button type="button" data-product="${escapeHtml(i.id)}" class="w-full flex items-center justify-between gap-3 px-3 py-2 text-justify text-xs hover:bg-amber-50">
      <span class="font-semibold text-slate-800">${escapeHtml(i.name)}</span>
      <span class="shrink-0 text-gray-500">${i.stock} in stock</span>
    </button>`).join('') : '<p class="px-3 py-4 text-center text-xs text-gray-400">No matching product.</p>';
  list.classList.remove('hidden');
}

function selectProduct(id) {
  state.productId = id;
  const item = selectedProduct();
  document.getElementById('invProductSearch').value = item ? item.name : '';
  document.getElementById('invProductList').classList.add('hidden');
  renderInventory();
}


function renderEod() {
  if (!state.data) return;
  const eod = (state.data.eodByBranch && state.data.eodByBranch[state.branch]) || { invoiceCount: 0, grossRevenue: 0 };
  document.getElementById('eodInvoiceCount').textContent = `${eod.invoiceCount} Trans`;
  document.getElementById('eodGrossRevenue').textContent = CashierApp.formatCurrency(eod.grossRevenue);

  const critical = state.data.inventory.filter(needsAttention);
  const card = document.getElementById('eodStockStatusCard');
  const text = document.getElementById('eodStockSummaryText');
  if (critical.length) {
    card.className = 'p-3 bg-rose-50 rounded-xl border border-[#9C1E15]/30 text-xs text-[color:var(--danger)]';
    text.textContent = `${critical.length} product(s) are low or out of stock.`;
  } else {
    card.className = 'p-3 bg-emerald-50 rounded-xl border border-[#115C39]/30 text-xs text-[color:var(--success)]';
    text.textContent = 'All products are in stock.';
  }
}


function stockTabStyle(active) {
  return active ? 'background:#16604B !important;color:#FFFFFF !important' : 'background:transparent !important;color:#4A5751 !important';
}

function renderPreview() {
  const preview = document.getElementById('invPreview');
  const item = selectedProduct();
  const quantity = parseInt(document.getElementById('invFormQty').value, 10);
  const adding = document.querySelector('input[name="adjType"]:checked').value === 'add';
  if (!item || !quantity || quantity < 1) {
    preview.classList.add('hidden');
    return;
  }
  const result = adding ? item.stock + quantity : item.stock - quantity;
  preview.classList.remove('hidden');
  preview.style.color = result < 0 ? 'var(--danger)' : 'var(--brand-dark)';
  preview.textContent = result < 0
    ? `Only ${item.stock} in stock, so you can't deduct ${quantity}.`
    : `${item.name}: ${item.stock} → ${result}`;
}

function setLowStockFilter(lowStockOnly) {
  state.lowStockOnly = lowStockOnly;
  state.page = 1;
  const allBtn = document.getElementById('btnFilterAllStock');
  const lowBtn = document.getElementById('btnFilterLowStock');
  allBtn.style.cssText = stockTabStyle(!lowStockOnly);
  lowBtn.style.cssText = stockTabStyle(lowStockOnly);
  renderInventory();
}

function openAdjustmentConfirm(e) {
  e.preventDefault();
  const item = selectedProduct();
  const productId = item ? item.id : '';
  const productName = item ? `${item.name} (${item.stock} in stock)` : '';
  const adjType = document.querySelector('input[name="adjType"]:checked').value;
  const quantity = parseInt(document.getElementById('invFormQty').value, 10);
  const reasonType = document.getElementById('invFormReasonType').value;
  const reason = reasonType === 'other' ? document.getElementById('invFormReason').value.trim() : reasonType;

  if (!productId || !quantity || quantity < 1 || !reason) {
    CashierApp.toast('Please complete all adjustment fields.', 'error');
    return;
  }

  if (adjType === 'sub' && quantity > item.stock) {
    CashierApp.toast(`Only ${item.stock} in stock.`, 'error');
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
    document.getElementById('invFormReason').classList.add('hidden');
    selectProduct('');
    state.pendingAdjustment = null;
    await refreshData();
  } else {
    CashierApp.toast(result.message || 'Failed to adjust stock.', 'error');
  }
}

const LOG_TYPES = { add: 'Restock', sub: 'Deduct', create: 'Opening Stock', edit: 'Edited', deactivate: 'Archived', reactivate: 'Restored' };

function logDate(timestamp) {
  const [date, time, meridiem] = String(timestamp).split(' ');
  const day = new Date(date + 'T00:00:00');
  const label = day.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  return `<span class="block font-semibold text-slate-800 whitespace-nowrap">${escapeHtml(label)}</span><span class="block text-gray-500 whitespace-nowrap">${escapeHtml(time)} ${escapeHtml(meridiem)}</span>`;
}

// The product already has its own column.
function logReason(row) {
  return /^Opening stock:/i.test(row.reason || '') ? 'Opening stock' : (row.reason || '');
}

function renderAdjustmentLog() {
  const q = document.getElementById('logSearch').value.trim().toLowerCase();
  const type = document.getElementById('logTypeFilter').value;
  const rows = state.logRows.filter(row => (!q || row.product.toLowerCase().includes(q)) && (!type || row.action === type));
  const totalPages = Math.max(1, Math.ceil(rows.length / state.logPageSize));
  state.logPage = Math.min(state.logPage, totalPages);
  const first = (state.logPage - 1) * state.logPageSize;

  const tbody = document.getElementById('inventoryLogTableBody');
  tbody.innerHTML = rows.length ? rows.slice(first, first + state.logPageSize).map(row => {
    const change = row.newStock - row.previousStock;
    const color = change > 0 ? '#115C39' : change < 0 ? '#9C1E15' : '#4A5751';
    const changeLabel = change > 0 ? `+${change}` : change < 0 ? `−${Math.abs(change)}` : '0';
    return `
      <tr>
        <td data-label="Date" class="p-2.5">${logDate(row.timestamp)}</td>
        <td data-label="Product" class="p-2.5 font-semibold text-slate-800">${escapeHtml(row.product)}</td>
        <td data-label="Type" class="p-2.5 whitespace-nowrap">${escapeHtml(LOG_TYPES[row.action] || row.action)}</td>
        <td data-label="Change" class="p-2.5 text-center font-bold" style="color:${color}">${changeLabel}</td>
        <td data-label="Stock" class="p-2.5 text-center whitespace-nowrap">${row.previousStock} &rarr; ${row.newStock}</td>
        <td data-label="Reason" class="p-2.5">${escapeHtml(logReason(row))}</td>
        <td data-label="By" class="p-2.5">${escapeHtml(row.adjustedBy || 'System')}</td>
      </tr>`;
  }).join('') : `<tr><td colspan="7" class="p-6 text-center text-gray-400">${state.logRows.length ? 'No entries match these filters.' : 'No stock changes logged for this branch yet.'}</td></tr>`;

  document.getElementById('logPager').classList.toggle('hidden', totalPages <= 1);
  document.getElementById('logPageLabel').textContent = rows.length ? `Showing ${first + 1}-${Math.min(first + state.logPageSize, rows.length)} of ${rows.length}` : '';
  document.getElementById('logPageNumber').textContent = `Page ${state.logPage} of ${totalPages}`;
  document.getElementById('btnLogPrev').disabled = state.logPage <= 1;
  document.getElementById('btnLogNext').disabled = state.logPage >= totalPages;
}

async function openAdjustmentLog() {
  document.getElementById('logBranchName').textContent = state.branch.toUpperCase();
  const result = await CashierApp.get('getAdjustmentLog.php', { branchId: state.branch });
  state.logRows = result.success ? result.log : [];
  state.logPage = 1;
  document.getElementById('logSearch').value = '';
  document.getElementById('logTypeFilter').value = '';
  renderAdjustmentLog();
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
    const proceed = await LMConfirm({
      title: 'Close the business day?',
      message: `${unsettledCount} booking(s) are still open. Closing locks today's transactions, and only an administrator can reopen the day. Completed but unpaid checkouts will still block closing.`,
      confirmLabel: 'Close Anyway'
    });
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
  document.getElementById('inventorySearch').addEventListener('input', () => { state.page = 1; renderInventory(); });
  document.getElementById('btnCardLowStock').addEventListener('click', () => setLowStockFilter(true));
  document.getElementById('btnInvPrev').addEventListener('click', () => { state.page -= 1; renderInventory(); });
  document.getElementById('btnInvNext').addEventListener('click', () => { state.page += 1; renderInventory(); });
  document.getElementById('inventoryTableBody').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-adjust]');
    if (!btn) return;
    selectProduct(btn.dataset.adjust);
    document.getElementById('invFormQty').focus();
  });

  document.getElementById('invFormQty').addEventListener('input', renderPreview);
  document.querySelectorAll('input[name="adjType"]').forEach(input => input.addEventListener('change', renderPreview));
  document.getElementById('btnFilterAllStock').style.cssText = stockTabStyle(true);
  document.getElementById('btnFilterLowStock').style.cssText = stockTabStyle(false);

  const productSearch = document.getElementById('invProductSearch');
  const productList = document.getElementById('invProductList');
  productSearch.addEventListener('focus', renderProductList);
  productSearch.addEventListener('input', () => { state.productId = ''; renderSelectedProduct(); renderProductList(); });
  productSearch.addEventListener('keydown', (e) => { if (e.key === 'Escape') productList.classList.add('hidden'); });
  productList.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-product]');
    if (btn) selectProduct(btn.dataset.product);
  });
  document.addEventListener('click', (e) => {
    if (e.target !== productSearch && !productList.contains(e.target)) productList.classList.add('hidden');
  });
  document.getElementById('invFormReasonType').addEventListener('change', (e) => {
    const other = document.getElementById('invFormReason');
    other.classList.toggle('hidden', e.target.value !== 'other');
    if (e.target.value === 'other') other.focus();
  });
  document.getElementById('btnFilterAllStock').addEventListener('click', () => setLowStockFilter(false));
  document.getElementById('btnFilterLowStock').addEventListener('click', () => setLowStockFilter(true));

  document.getElementById('inventoryAdjustmentForm').addEventListener('submit', openAdjustmentConfirm);
  document.getElementById('btnCloseConfirmAdjustmentModal').addEventListener('click', () => CashierApp.hideModal('stockAdjustmentConfirmModal'));
  document.getElementById('btnCancelStockAdjustment').addEventListener('click', () => CashierApp.hideModal('stockAdjustmentConfirmModal'));
  document.getElementById('btnConfirmStockAdjustment').addEventListener('click', confirmAdjustment);

  document.getElementById('logSearch').addEventListener('input', () => { state.logPage = 1; renderAdjustmentLog(); });
  document.getElementById('logTypeFilter').addEventListener('change', () => { state.logPage = 1; renderAdjustmentLog(); });
  document.getElementById('btnLogPrev').addEventListener('click', () => { state.logPage -= 1; renderAdjustmentLog(); });
  document.getElementById('btnLogNext').addEventListener('click', () => { state.logPage += 1; renderAdjustmentLog(); });
  document.getElementById('btnViewAdjLog').addEventListener('click', openAdjustmentLog);
  document.getElementById('btnCloseInventoryLogModal').addEventListener('click', () => CashierApp.hideModal('inventoryLogModal'));

  document.getElementById('btnTriggerEOD').addEventListener('click', triggerEod);
}

document.addEventListener('DOMContentLoaded', init);
