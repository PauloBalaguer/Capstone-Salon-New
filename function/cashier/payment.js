/* frontend/cashier/payment.html Payment Records.
   Read-only list of every payment the branch received in a date range
   (backend/cashier/getPaymentRecords.php): checkout payments (with a
   receipt) and verified reservation payments. Taking a payment happens in
   the Cashier Panel's checkout popup (function/cashier/checkout-modal.js). */

const PAGE_SIZE = 10; // payments per page

const state = {
  records: [],
  bookings: [],
  branch: null,
  page: 1,
  loadedRange: null,
  request: 0
};

const METHOD_LABELS = {
  'PayMongo-GCash': 'GCash (online)',
  'PayMongo-Maya': 'Maya (online)',
  'PayMongo-Card': 'Card (online)',
  PayMongo: 'Online (other)'
};

function methodLabel(method) {
  return METHOD_LABELS[method] || (typeof PortalPresentation !== 'undefined' && PortalPresentation.methodLabel(method)) || method || 'Unknown';
}

function ymd(d) {
  return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-');
}

function rangeFor(key) {
  const now = new Date();
  const to = ymd(now);
  if (key === 'today') return [to, to];
  if (key === 'month') return [to.slice(0, 8) + '01', to];
  return [ymd(new Date(now.getTime() - (Number(key) - 1) * 86400000)), to];
}

async function init() {
  CashierApp.initChrome();
  wireEvents();
  setRange('30');
  try {
    await loadBookings();
  } catch (e) { console.error(e); }
  loadMyShift();
  CashierApp.liveSync(() => { loadBookings(); loadRecords(); loadMyShift(); }, () => state.branch);
}

/* "Your shift today": today's payments this cashier recorded (r.mine). */
async function loadMyShift() {
  const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
  try {
    const today = ymd(new Date());
    const result = await CashierApp.get('getPaymentRecords.php', { from: today, to: today });
    if (!result.success) throw new Error(result.message);
    const mine = (result.records || []).filter(r => r.mine);
    const counter = mine.filter(r => r.type === 'Checkout').length;
    const deposits = mine.length - counter;
    document.getElementById('meCollected').textContent = CashierApp.formatCurrency(mine.reduce((sum, r) => sum + Number(r.amount || 0), 0));
    document.getElementById('meCollectedHint').textContent = plural(mine.length, 'payment');
    document.getElementById('meCheckouts').textContent = counter;
    document.getElementById('meCheckoutsHint').textContent = plural(counter, 'receipt') + ' issued';
    document.getElementById('meReservations').textContent = deposits;
    document.getElementById('meReservationsHint').textContent = plural(deposits, 'deposit');
  } catch (e) {
    console.error(e);
    document.getElementById('meCollectedHint').textContent = 'Could not load. Refresh to try again.';
  }
}

async function loadBookings() {
  const result = await CashierApp.fetchDashboard();
  if (!result.success) {
    document.getElementById('sumOutstandingCount').textContent = 'Could not load. Refresh to try again.';
    return;
  }
  state.branch = result.myBranch.key;
  state.bookings = result.bookings;
  CashierApp.applyBranchChrome(result.myBranch.key);
  CashierApp.updateQueueBadge(result.bookings);
  renderBalances();
}

/* Same rules as the Cashier Panel queue (dashboard.js): payable while In
   Progress or Completed until a receipt exists; a reported reservation
   payment needs a cashier to verify it. Display only -- the backend checks
   everything again when the payment is recorded. */
function balPayable(b) {
  return !b.checkoutPaid && (b.status === 'In Progress' || b.status === 'Completed');
}
function balNeedsReview(b) {
  if (needsPaymentVerification(b)) return true;
  return b.status === 'Pending' && !b.depositVerified
    && (b.paymentStatus === 'Awaiting Verification' || !!(b.depositAmount || b.depositReference));
}

function renderBalances() {
  const open = state.bookings.filter(b => balPayable(b) || balNeedsReview(b));
  const payable = open.filter(balPayable);
  const review = open.filter(balNeedsReview);
  const outstanding = payable.reduce((s, b) => s + CashierApp.balanceOf(b), 0);
  document.getElementById('sumOutstanding').textContent = CashierApp.formatCurrency(outstanding);
  document.getElementById('sumOutstandingCount').textContent = `${payable.length} booking${payable.length === 1 ? '' : 's'} ready to pay`;
  document.getElementById('sumReview').textContent = review.length;
  document.getElementById('sumReviewHint').textContent = review.length ? 'Check these before confirming' : 'Nothing to check';
}

function setRange(key) {
  const [from, to] = rangeFor(key);
  document.getElementById('payFrom').value = from;
  document.getElementById('payTo').value = to;
  highlightRange();
  loadRecords();
}

function highlightRange() {
  const from = document.getElementById('payFrom').value;
  const to = document.getElementById('payTo').value;
  document.querySelectorAll('#payQuickRanges [data-range]').forEach(btn => {
    const [f, t] = rangeFor(btn.dataset.range);
    btn.classList.toggle('is-active', f === from && t === to);
  });
}

async function loadRecords() {
  const from = document.getElementById('payFrom').value;
  const to = document.getElementById('payTo').value;
  const requestId = ++state.request;
  document.getElementById('paymentList').innerHTML = '<p class="py-10 text-center text-sm text-slate-500">Loading payments…</p>';
  try {
    const result = await CashierApp.get('getPaymentRecords.php', { from, to });
    if (requestId !== state.request) return; // a newer range was picked
    if (!result.success) {
      CashierApp.toast(result.message || 'Failed to load payment records.', 'error');
      document.getElementById('paymentList').innerHTML = '<p class="py-10 text-center text-sm text-[color:var(--danger)]">Could not load payment records. Refresh to try again.</p>';
      return;
    }
    state.records = result.records;
    state.page = 1;
    state.loadedRange = [result.from, result.to];
    fillMethodFilter();
    render();
  } catch (e) {
    console.error(e);
    if (requestId === state.request) {
      document.getElementById('paymentList').innerHTML = '<p class="py-10 text-center text-sm text-[color:var(--danger)]">Connection lost. Refresh to try again.</p>';
    }
  }
}

function fillMethodFilter() {
  const select = document.getElementById('payMethod');
  const current = select.value;
  const methods = [...new Set(state.records.map(r => r.method).filter(Boolean))].sort();
  select.innerHTML = '<option value="">All methods</option>' + methods.map(m => `<option value="${escapeHtml(m)}">${escapeHtml(methodLabel(m))}</option>`).join('');
  if (methods.includes(current)) select.value = current;
}

function filtered() {
  const q = (document.getElementById('paySearch').value || '').toLowerCase().trim();
  const type = document.getElementById('payType').value;
  const method = document.getElementById('payMethod').value;
  return state.records.filter(r => (!type || r.type === type)
    && (!method || r.method === method)
    && (!q || (r.clientName || '').toLowerCase().includes(q)
      || (r.reference || '').toLowerCase().includes(q)
      || (r.paymentReference || '').toLowerCase().includes(q)
      || (r.invoiceId || '').toLowerCase().includes(q)));
}

function friendlyDate(day) {
  const today = ymd(new Date());
  const yesterday = ymd(new Date(Date.now() - 86400000));
  const label = new Date(day + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  return day === today ? 'Today · ' + label : day === yesterday ? 'Yesterday · ' + label : label;
}

function timeOf(paidAt) {
  const [h, m] = String(paidAt || '').slice(11, 16).split(':').map(Number);
  if (Number.isNaN(h)) return '';
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}

/* Page buttons: ‹ 1 2 3 … 9 › (same as the Appointments page). */
function renderPayPager(pages) {
  const box = document.getElementById('payPager');
  if (pages <= 1) { box.innerHTML = ''; return; }
  const cur = state.page;
  const nums = [...new Set([1, cur - 1, cur, cur + 1, pages].filter(n => n >= 1 && n <= pages))].sort((a, b) => a - b);
  let html = `<button type="button" class="cp-page-btn" data-page="${cur - 1}" ${cur === 1 ? 'disabled' : ''} aria-label="Previous page"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>`;
  nums.forEach((n, i) => {
    if (i && n - nums[i - 1] > 1) html += '<span class="cp-page-gap" aria-hidden="true">…</span>';
    html += `<button type="button" class="cp-page-btn${n === cur ? ' is-current' : ''}" data-page="${n}"${n === cur ? ' aria-current="page"' : ''} aria-label="Page ${n}">${n}</button>`;
  });
  html += `<button type="button" class="cp-page-btn" data-page="${cur + 1}" ${cur === pages ? 'disabled' : ''} aria-label="Next page"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>`;
  box.innerHTML = html;
}

function render() {
  const list = filtered();
  const sum = (rows) => rows.reduce((s, r) => s + Number(r.amount || 0), 0);
  const checkout = list.filter(r => r.type === 'Checkout');
  const reservation = list.filter(r => r.type === 'Reservation');

  document.getElementById('sumTotal').textContent = CashierApp.formatCurrency(sum(list));
  document.getElementById('sumCount').textContent = `${list.length} payment${list.length === 1 ? '' : 's'}`;
  document.getElementById('sumCheckout').textContent = CashierApp.formatCurrency(sum(checkout));
  document.getElementById('sumCheckoutCount').textContent = `${checkout.length} payment${checkout.length === 1 ? '' : 's'}`;
  document.getElementById('sumReservation').textContent = CashierApp.formatCurrency(sum(reservation));
  document.getElementById('sumReservationCount').textContent = `${reservation.length} payment${reservation.length === 1 ? '' : 's'}`;
  const byMethod = {};
  list.forEach(r => { const k = methodLabel(r.method); byMethod[k] = (byMethod[k] || 0) + Number(r.amount || 0); });
  document.getElementById('sumByMethod').innerHTML = Object.keys(byMethod).length
    ? Object.entries(byMethod).sort((a, b) => b[1] - a[1]).map(([k, v]) => `<div class="cp-bd-row"><span>${escapeHtml(k)}</span><span>${CashierApp.formatCurrency(v)}</span></div>`).join('')
    : '-';
  document.getElementById('sumTips').textContent = CashierApp.formatCurrency(list.reduce((s, r) => s + Number(r.tip || 0), 0));

  const container = document.getElementById('paymentList');
  if (!list.length) {
    container.innerHTML = `
      <div class="py-14 text-center px-4">
        <i class="fa-solid fa-receipt text-3xl text-gray-300 mb-2 block"></i>
        <p class="text-sm text-gray-500">No payments in this period.</p>
      </div>`;
    document.getElementById('payCountLabel').textContent = '';
    document.getElementById('payPager').innerHTML = '';
    return;
  }

  const pages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
  state.page = Math.min(Math.max(1, state.page), pages);
  const first = (state.page - 1) * PAGE_SIZE;
  const visible = list.slice(first, first + PAGE_SIZE);
  let lastDay = null;
  container.innerHTML = visible.map(r => {
    const day = String(r.paidAt || '').slice(0, 10);
    const heading = day !== lastDay
      ? `<div class="cp-payment-date px-4 sm:px-5 py-2 bg-[color:var(--card)] border-t border-gray-200">${escapeHtml(friendlyDate(day))}</div>`
      : '';
    lastDay = day;
    const typeBadge = r.type === 'Checkout'
      ? '<span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-teal-100 text-teal-700 whitespace-nowrap">Paid at counter</span>'
      : '<span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-100 text-[color:var(--warn)] whitespace-nowrap">Online deposit</span>';
    const receipt = r.paymentId
      ? `<a href="receipt.html?ref=${encodeURIComponent(r.reference)}&pid=${encodeURIComponent(r.paymentId)}" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:underline whitespace-nowrap"><i class="fa-solid fa-receipt"></i>${escapeHtml(r.invoiceId)}</a>`
      : r.type === 'Checkout'
        ? '<span class="text-xs font-semibold text-[color:var(--danger)]">No receipt</span>'
        : '<span class="text-sm text-slate-400" title="Online deposits do not get a receipt">–</span>';
    return heading + `
      <div class="border-t border-gray-100 px-4 sm:px-5 py-3 grid grid-cols-[1fr_auto] xl:grid-cols-[6rem_1.3fr_1.4fr_8rem_7rem_8rem_7.5rem] gap-x-4 gap-y-1 items-center">
        <span class="order-2 xl:order-none text-right xl:text-left text-sm font-semibold text-slate-700 whitespace-nowrap">${escapeHtml(timeOf(r.paidAt))}</span>
        <span class="order-1 xl:order-none min-w-0">
          <span class="block font-bold text-slate-900 capitalize truncate">${escapeHtml(r.clientName)}</span>
          <span class="block text-xs text-slate-500 font-mono truncate">${escapeHtml(r.reference)}</span>
          ${r.paymentReference ? `<span class="block text-xs text-slate-500 break-all">Payment ref: ${escapeHtml(r.paymentReference)}</span>` : ''}
        </span>
        <span class="order-3 xl:order-none col-span-2 xl:col-span-1 text-sm text-slate-700 truncate">${escapeHtml(r.serviceName || '')}${r.cashierName ? `<span class="block text-xs text-slate-500">${r.type === 'Checkout' ? 'Received' : 'Verified'} by ${escapeHtml(r.cashierName)}</span>` : ''}</span>
        <span class="order-4 xl:order-none">${typeBadge}</span>
        <span class="order-5 xl:order-none text-sm text-slate-700 text-right xl:text-left whitespace-nowrap">${escapeHtml(methodLabel(r.method))}</span>
        <span class="order-6 xl:order-none text-right">
          <span class="block font-bold text-slate-900">${CashierApp.formatCurrency(r.amount)}</span>
          ${r.tip > 0 ? `<span class="block text-xs text-slate-500">incl. ${CashierApp.formatCurrency(r.tip)} tip</span>` : ''}
        </span>
        <span class="order-7 xl:order-none col-span-2 xl:col-span-1 xl:text-right">${receipt}</span>
      </div>`;
  }).join('');

  document.getElementById('payCountLabel').textContent = `Showing ${first + 1}–${first + visible.length} of ${list.length}`;
  renderPayPager(pages);
}

function exportCsv() {
  const rows = filtered();
  if (!rows.length) { CashierApp.toast('No payments to download.', 'error'); return; }
  const cell = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const header = ['Date and time', 'Type', 'Invoice', 'Reference', 'Customer', 'Services', 'Stylist', 'Method', 'Payment reference', 'Amount', 'Tip', 'Received / verified by'];
  const lines = rows.map(r => [r.paidAt, r.type === 'Checkout' ? 'Paid at counter' : 'Online deposit', r.invoiceId || '', r.reference, r.clientName, r.serviceName || '', r.stylistName || '', methodLabel(r.method), r.paymentReference || '', Number(r.amount).toFixed(2), Number(r.tip || 0).toFixed(2), r.cashierName || ''].map(cell).join(','));
  const blob = new Blob(['﻿' + [header.map(cell).join(','), ...lines].join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  const [from, to] = state.loadedRange || ['', ''];
  a.href = URL.createObjectURL(blob);
  a.download = `payment-records-${from}-to-${to}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

function wireEvents() {
  document.getElementById('payQuickRanges').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-range]');
    if (btn) setRange(btn.dataset.range);
  });
  ['payFrom', 'payTo'].forEach(id => document.getElementById(id).addEventListener('change', () => { highlightRange(); loadRecords(); }));
  document.getElementById('paySearch').addEventListener('input', () => { state.page = 1; render(); });
  document.getElementById('payType').addEventListener('change', () => { state.page = 1; render(); });
  document.getElementById('payMethod').addEventListener('change', () => { state.page = 1; render(); });
  document.getElementById('payPager').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-page]');
    if (!btn || btn.disabled) return;
    state.page = Number(btn.dataset.page);
    render();
    document.getElementById('paymentList').scrollIntoView({ block: 'start', behavior: 'smooth' });
  });
  document.getElementById('btnExportCsv').addEventListener('click', exportCsv);
}

document.addEventListener('DOMContentLoaded', init);
