/* frontend/cashier/payment.html Payment Records.
   Read-only list of every payment the branch received in a date range
   (backend/cashier/getPaymentRecords.php): checkout payments (with a
   receipt) and verified reservation payments. Taking a payment happens in
   the Cashier Panel's checkout popup (function/cashier/checkout-modal.js). */

const PAGE_SIZE = 40;

const state = {
  records: [],
  bookings: [],
  branch: null,
  balTab: 'all',
  shown: PAGE_SIZE,
  loadedRange: null,
  request: 0
};

const METHOD_LABELS = {
  'PayMongo-GCash': 'GCash (PayMongo)',
  'PayMongo-Maya': 'Maya (PayMongo)',
  'PayMongo-Card': 'Card (PayMongo)',
  PayMongo: 'PayMongo'
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
  CashierApp.liveSync(() => { loadBookings(); loadRecords(); }, () => state.branch);
}

async function loadBookings() {
  const result = await CashierApp.fetchDashboard();
  if (!result.success) {
    document.getElementById('balanceList').innerHTML = '<p class="py-10 text-center text-sm text-[color:var(--danger)]">Could not load balances. Refresh to try again.</p>';
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
  const counts = { all: open.length, pay: payable.length, review: review.length };
  document.querySelectorAll('#balTabs [data-bal-count]').forEach(el => { el.textContent = counts[el.dataset.balCount]; });

  const list = state.balTab === 'pay' ? payable : state.balTab === 'review' ? review : open;
  const container = document.getElementById('balanceList');
  if (!list.length) {
    container.innerHTML = '<div class="cp-empty"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><p class="cp-empty-title">All settled</p><p>No open balances or payments to review.</p></div>';
    return;
  }
  container.innerHTML = list.map(b => {
    const ref = encodeURIComponent(b.id);
    let action = '';
    if (balNeedsReview(b)) {
      const target = b.status === 'Pending' ? 'confirm' : 'verify';
      action = `<a href="dashboard.html?${target}=${ref}" class="btn-row cp-btn-primary"><i class="fa-solid fa-magnifying-glass-dollar" aria-hidden="true"></i>Review Payment</a>`;
    } else if (balPayable(b)) {
      action = `<a href="dashboard.html?checkout=${ref}" class="btn-row cp-btn-gold"><i class="fa-solid fa-cash-register" aria-hidden="true"></i>Checkout</a>`;
    }
    const payStatus = (typeof PortalPresentation !== 'undefined' && PortalPresentation.paymentStatus(b.paymentStatus)) || b.paymentStatus || 'Not set';
    return `
      <div class="cp-pay-row">
        <span class="cp-cell" data-label="Reference"><span class="cp-mono">${escapeHtml(b.id)}</span></span>
        <span class="cp-cell cp-cell-strong" data-label="Customer">${escapeHtml(titleCase(b.clientName))}</span>
        <span class="cp-cell" data-label="Service">${escapeHtml(b.serviceName || 'No service listed')}</span>
        <span class="cp-cell" data-label="Total">${CashierApp.formatCurrency(b.price)}</span>
        <span class="cp-cell" data-label="Paid">${CashierApp.formatCurrency(CashierApp.amountPaid(b))}</span>
        <span class="cp-cell cp-cell-strong" data-label="Balance">${CashierApp.formatCurrency(CashierApp.balanceOf(b))}</span>
        <span class="cp-cell" data-label="Payment status"><span class="cp-badge bg-amber-50 text-[color:var(--warn)]">${escapeHtml(payStatus)}</span></span>
        <span class="cp-cell cp-cell-action">${action}</span>
      </div>`;
  }).join('');
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
    state.shown = PAGE_SIZE;
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
      || (r.invoiceId || '').toLowerCase().includes(q)));
}

function friendlyDate(day) {
  const today = ymd(new Date());
  const yesterday = ymd(new Date(Date.now() - 86400000));
  const label = new Date(day + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  return day === today ? 'Today · ' + label : day === yesterday ? 'Yesterday · ' + label : label;
}

function timeOf(paidAt) {
  const [h, m] = String(paidAt || '').slice(11, 16).split(':').map(Number);
  if (Number.isNaN(h)) return '';
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}

function render() {
  const list = filtered();
  const sum = (rows) => rows.reduce((s, r) => s + Number(r.amount || 0), 0);
  const checkout = list.filter(r => r.type === 'Checkout');
  const reservation = list.filter(r => r.type === 'Reservation');

  document.getElementById('sumTotal').textContent = CashierApp.formatCurrency(sum(list));
  document.getElementById('sumCount').textContent = `${list.length} payment${list.length === 1 ? '' : 's'}`;
  document.getElementById('sumCheckout').textContent = CashierApp.formatCurrency(sum(checkout));
  document.getElementById('sumCheckoutCount').textContent = `${checkout.length} receipt${checkout.length === 1 ? '' : 's'}`;
  document.getElementById('sumReservation').textContent = CashierApp.formatCurrency(sum(reservation));
  document.getElementById('sumReservationCount').textContent = `${reservation.length} verified`;
  const byMethod = {};
  list.forEach(r => { const k = methodLabel(r.method); byMethod[k] = (byMethod[k] || 0) + Number(r.amount || 0); });
  document.getElementById('sumByMethod').innerHTML = Object.keys(byMethod).length
    ? Object.entries(byMethod).sort((a, b) => b[1] - a[1]).map(([k, v]) => `<span class="block">${escapeHtml(k)}: ${CashierApp.formatCurrency(v)}</span>`).join('')
    : '-';
  document.getElementById('sumTips').textContent = CashierApp.formatCurrency(list.reduce((s, r) => s + Number(r.tip || 0), 0)) + ' in tips';

  const container = document.getElementById('paymentList');
  if (!list.length) {
    container.innerHTML = `
      <div class="py-14 text-center px-4">
        <i class="fa-solid fa-receipt text-3xl text-gray-300 mb-2 block"></i>
        <p class="text-sm text-gray-500">No payments in this period.</p>
      </div>`;
    document.getElementById('payCountLabel').textContent = '';
    document.getElementById('btnPayShowMore').classList.add('hidden');
    return;
  }

  const visible = list.slice(0, state.shown);
  let lastDay = null;
  container.innerHTML = visible.map(r => {
    const day = String(r.paidAt || '').slice(0, 10);
    const heading = day !== lastDay
      ? `<div class="px-4 sm:px-5 py-2 bg-[color:var(--card)] border-t border-gray-200 text-sm font-bold text-teal-700">${escapeHtml(friendlyDate(day))}</div>`
      : '';
    lastDay = day;
    const typeBadge = r.type === 'Checkout'
      ? '<span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-teal-100 text-teal-700">Checkout</span>'
      : '<span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-100 text-[color:var(--warn)]">Reservation</span>';
    const receipt = r.paymentId
      ? `<a href="receipt.html?ref=${encodeURIComponent(r.reference)}&pid=${encodeURIComponent(r.paymentId)}" class="inline-flex items-center gap-1 text-sm font-bold text-teal-700 hover:underline whitespace-nowrap"><i class="fa-solid fa-receipt"></i>${escapeHtml(r.invoiceId)}</a>`
      : '<span class="text-xs text-slate-400">No receipt</span>';
    return heading + `
      <div class="border-t border-gray-100 px-4 sm:px-5 py-3 grid grid-cols-[1fr_auto] xl:grid-cols-[6rem_1.3fr_1.4fr_8rem_7rem_8rem_7.5rem] gap-x-4 gap-y-1 items-center">
        <span class="order-2 xl:order-none text-right xl:text-left text-sm font-semibold text-slate-700 whitespace-nowrap">${escapeHtml(timeOf(r.paidAt))}</span>
        <span class="order-1 xl:order-none min-w-0">
          <span class="block font-bold text-slate-900 capitalize truncate">${escapeHtml(r.clientName)}</span>
          <span class="block text-xs text-slate-500 font-mono truncate">${escapeHtml(r.reference)}</span>
        </span>
        <span class="order-3 xl:order-none col-span-2 xl:col-span-1 text-sm text-slate-700 truncate">${escapeHtml(r.serviceName || '')}${r.cashierName ? `<span class="block text-xs text-slate-500">By ${escapeHtml(r.cashierName)}</span>` : ''}</span>
        <span class="order-4 xl:order-none">${typeBadge}</span>
        <span class="order-5 xl:order-none text-sm text-slate-700 text-right xl:text-left">${escapeHtml(methodLabel(r.method))}</span>
        <span class="order-6 xl:order-none text-right">
          <span class="block font-bold text-slate-900">${CashierApp.formatCurrency(r.amount)}</span>
          ${r.tip > 0 ? `<span class="block text-xs text-slate-500">incl. ${CashierApp.formatCurrency(r.tip)} tip</span>` : ''}
        </span>
        <span class="order-7 xl:order-none col-span-2 xl:col-span-1 xl:text-right">${receipt}</span>
      </div>`;
  }).join('');

  document.getElementById('payCountLabel').textContent = `Showing ${visible.length} of ${list.length}`;
  document.getElementById('btnPayShowMore').classList.toggle('hidden', visible.length >= list.length);
}

function exportCsv() {
  const rows = filtered();
  if (!rows.length) { CashierApp.toast('No payments to download.', 'error'); return; }
  const cell = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const header = ['Date and time', 'Type', 'Invoice', 'Reference', 'Customer', 'Services', 'Stylist', 'Method', 'Amount', 'Tip', 'Recorded by'];
  const lines = rows.map(r => [r.paidAt, r.type, r.invoiceId || '', r.reference, r.clientName, r.serviceName || '', r.stylistName || '', methodLabel(r.method), Number(r.amount).toFixed(2), Number(r.tip || 0).toFixed(2), r.cashierName || ''].map(cell).join(','));
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
  document.getElementById('paySearch').addEventListener('input', () => { state.shown = PAGE_SIZE; render(); });
  document.getElementById('payType').addEventListener('change', () => { state.shown = PAGE_SIZE; render(); });
  document.getElementById('payMethod').addEventListener('change', () => { state.shown = PAGE_SIZE; render(); });
  document.getElementById('btnPayShowMore').addEventListener('click', () => { state.shown += PAGE_SIZE; render(); });
  document.getElementById('btnExportCsv').addEventListener('click', exportCsv);
  document.getElementById('balTabs').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-bal]');
    if (!btn) return;
    state.balTab = btn.dataset.bal;
    document.querySelectorAll('#balTabs [data-bal]').forEach(t => {
      t.classList.toggle('is-active', t === btn);
      t.setAttribute('aria-selected', String(t === btn));
    });
    renderBalances();
  });
}

document.addEventListener('DOMContentLoaded', init);
