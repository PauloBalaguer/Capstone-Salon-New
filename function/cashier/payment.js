/* frontend/cashier/payment.html Payment Records.
   Read-only list of every payment the branch received in a date range
   (backend/cashier/getPaymentRecords.php): checkout payments (with a
   receipt) and verified reservation payments. Taking a payment happens in
   the Cashier Panel's checkout popup (function/cashier/checkout-modal.js). */

const PAGE_SIZE = 40;

const state = {
  records: [],
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
    const result = await CashierApp.fetchDashboard();
    if (result.success) {
      CashierApp.applyBranchChrome(result.myBranch.key);
      CashierApp.updateQueueBadge(result.bookings);
    }
  } catch (e) { console.error(e); }
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
      document.getElementById('paymentList').innerHTML = '<p class="py-10 text-center text-sm text-rose-700">Could not load payment records. Refresh to try again.</p>';
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
      document.getElementById('paymentList').innerHTML = '<p class="py-10 text-center text-sm text-rose-700">Connection lost. Refresh to try again.</p>';
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
      ? `<div class="px-4 sm:px-5 py-2 bg-[#FFFDF9] border-t border-gray-200 text-sm font-bold text-[#06464A]">${escapeHtml(friendlyDate(day))}</div>`
      : '';
    lastDay = day;
    const typeBadge = r.type === 'Checkout'
      ? '<span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-[#E5F2F0] text-[#06464A]">Checkout</span>'
      : '<span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-900">Reservation</span>';
    const receipt = r.paymentId
      ? `<a href="receipt.html?ref=${encodeURIComponent(r.reference)}&pid=${encodeURIComponent(r.paymentId)}" class="inline-flex items-center gap-1 text-sm font-bold text-[#06464A] hover:underline whitespace-nowrap"><i class="fa-solid fa-receipt"></i>${escapeHtml(r.invoiceId)}</a>`
      : '<span class="text-xs text-slate-400">No receipt</span>';
    return heading + `
      <div class="border-t border-gray-100 px-4 sm:px-5 py-3 grid grid-cols-[1fr_auto] lg:grid-cols-[6rem_1.3fr_1.4fr_8rem_7rem_8rem_7.5rem] gap-x-4 gap-y-1 items-center">
        <span class="order-2 lg:order-none text-right lg:text-left text-sm font-semibold text-slate-700 whitespace-nowrap">${escapeHtml(timeOf(r.paidAt))}</span>
        <span class="order-1 lg:order-none min-w-0">
          <span class="block font-bold text-slate-900 capitalize truncate">${escapeHtml(r.clientName)}</span>
          <span class="block text-xs text-slate-500 font-mono truncate">${escapeHtml(r.reference)}</span>
        </span>
        <span class="order-3 lg:order-none col-span-2 lg:col-span-1 text-sm text-slate-700 truncate">${escapeHtml(r.serviceName || '')}${r.cashierName ? `<span class="block text-xs text-slate-500">By ${escapeHtml(r.cashierName)}</span>` : ''}</span>
        <span class="order-4 lg:order-none">${typeBadge}</span>
        <span class="order-5 lg:order-none text-sm text-slate-700 text-right lg:text-left">${escapeHtml(methodLabel(r.method))}</span>
        <span class="order-6 lg:order-none text-right">
          <span class="block font-bold text-slate-900">${CashierApp.formatCurrency(r.amount)}</span>
          ${r.tip > 0 ? `<span class="block text-xs text-slate-500">incl. ${CashierApp.formatCurrency(r.tip)} tip</span>` : ''}
        </span>
        <span class="order-7 lg:order-none col-span-2 lg:col-span-1 lg:text-right">${receipt}</span>
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
}

document.addEventListener('DOMContentLoaded', init);
