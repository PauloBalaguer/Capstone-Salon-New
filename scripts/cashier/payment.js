/* pages/cashier/payment.html — single-ticket checkout terminal.
   Reads ?ref=<reference_code> from the URL, loads that booking from the
   shared getDashboardData.php payload, lets the cashier add retail
   products + a tip, then posts to payment.php and redirects to the
   printable receipt. */

const state = {
  data: null,
  ticket: null,
  branchInventory: [],
  cartProducts: [], // [{ id, name, price, stock, qty }]
  tip: 0,
  selectedMethod: 'Cash',
  pendingVerifyRef: null
};

function getReferenceFromUrl() {
  return new URLSearchParams(window.location.search).get('ref') || '';
}

async function init() {
  CashierApp.startClock('liveClock');
  CashierApp.initChrome();
  wireStaticEvents();
  selectPaymentMethod('Cash');
  setTip(0);

  const ref = getReferenceFromUrl();
  const result = await CashierApp.fetchDashboard();
  if (!result.success) {
    CashierApp.toast(result.message || 'Failed to load data.', 'error');
    if (ref) showNotFound();
    return;
  }
  state.data = result;
  CashierApp.applyBranchChrome(result.myBranch.key);
  CashierApp.updateQueueBadge(result.bookings);

  if (!ref) {
    document.getElementById('verifyListSection').classList.remove('hidden');
    document.getElementById('ticketTerminal').classList.add('hidden');
    renderCheckoutList();
    renderVerifyList();
    return;
  }

  document.getElementById('verifyListSection').classList.add('hidden');
  document.getElementById('ticketTerminal').classList.remove('hidden');

  const ticket = result.bookings.find(b => b.id === ref);
  if (!ticket) {
    showNotFound();
    return;
  }

  state.ticket = ticket;
  state.branchInventory = result.inventory.filter(i => i.branchId === ticket.branchId);

  const branch = result.branches.find(b => b.branchKey === ticket.branchId);
  renderTicketSummary(branch);

  const receiptCheck = await CashierApp.get('receipt.php', { reference: ticket.id });
  if (receiptCheck.success) {
    showAlreadyPaid(ref, receiptCheck.receipt);
  } else {
    document.getElementById('btnSellProduct').disabled = false;
    document.getElementById('btnProcessPayment').disabled = false;
  }

  renderCart();
}

/* READY FOR CHECKOUT -- visits that still owe money: anything In Progress
   or Completed-but-unpaid, plus Confirmed bookings dated today or earlier
   (future Confirmed bookings aren't at the counter yet). */
function renderCheckoutList() {
  const tbody = document.getElementById('checkoutTableBody');
  const now = new Date();
  const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
  const toCheckout = state.data.bookings.filter(b =>
    b.paymentStatus !== 'Fully Paid' && (
      b.status === 'In Progress' ||
      b.status === 'Completed' ||
      (b.status === 'Confirmed' && (b.date || '') <= today)
    )
  );

  if (!toCheckout.length) {
    tbody.innerHTML = `<tr><td colspan="7" class="py-14 text-center">
      <i class="fa-solid fa-cash-register text-3xl text-gray-200 mb-2 block"></i>
      <span class="text-sm text-gray-400">No visits waiting for checkout.</span>
    </td></tr>`;
    return;
  }

  tbody.innerHTML = toCheckout.map(b => `
    <tr class="align-top">
      <td class="px-5 py-3.5">
        <span class="font-mono text-xs font-semibold text-slate-700">${escapeHtml(b.id)}</span>
        <span class="block text-xs text-slate-400">${escapeHtml(b.date || '')} ${escapeHtml(b.time || '')}</span>
      </td>
      <td class="px-5 py-3.5 font-semibold text-slate-800 capitalize">${escapeHtml(b.clientName)}</td>
      <td class="px-5 py-3.5">${escapeHtml(b.serviceName || '—')}</td>
      <td class="px-5 py-3.5 capitalize">${escapeHtml(b.staffName || '—')}</td>
      <td class="px-5 py-3.5"><span class="text-[10px] font-bold px-2 py-0.5 rounded ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span></td>
      <td class="px-5 py-3.5 text-right font-bold text-slate-800 whitespace-nowrap">${CashierApp.formatCurrency(b.price)}</td>
      <td class="px-5 py-3.5 text-right"><a href="payment.html?ref=${encodeURIComponent(b.id)}" class="inline-block bg-amber-500 hover:bg-amber-600 text-slate-950 px-3.5 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap">Checkout</a></td>
    </tr>`).join('');
}

/* PAYMENTS TO VERIFY -- self-reported deposits/reservation payments on
   Pending bookings, awaiting a cashier's manual verification. Customers
   never verify their own payment -- this list and its actions are
   cashier/admin-only (see backend/cashier/updateStatus.php's role check). */
function renderVerifyList() {
  const tbody = document.getElementById('verifyTableBody');
  // Any active booking with an unverified self-reported payment -- not just
  // Pending ones, so e.g. a Reschedule Requested booking's deposit still shows.
  const toVerify = state.data.bookings.filter(b =>
    !['Cancelled', 'Completed'].includes(b.status) && !b.depositVerified &&
    (b.paymentStatus === 'Awaiting Verification' || (b.status === 'Pending' && (b.depositAmount || b.depositReference)))
  );

  if (!toVerify.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="py-14 text-center">
      <i class="fa-solid fa-circle-check text-3xl text-gray-200 mb-2 block"></i>
      <span class="text-sm text-gray-400">No payments waiting on verification.</span>
    </td></tr>`;
    return;
  }

  tbody.innerHTML = toVerify.map(b => {
    const method = b.depositMethod && b.depositMethod !== 'Walk-in' ? b.depositMethod : (b.paymentMethod || '—');
    return `
    <tr class="align-top">
      <td class="px-5 py-3.5">
        <span class="font-mono text-xs font-semibold text-slate-700">${escapeHtml(b.id)}</span>
        <span class="block text-xs text-slate-400">${escapeHtml(b.paymentRequirement || '')}</span>
        ${b.status !== 'Pending' ? `<span class="inline-block mt-1 text-[10px] font-bold px-2 py-0.5 rounded ${statusBadgeClass(b.status)}">${escapeHtml(b.status)}</span>` : ''}
      </td>
      <td class="px-5 py-3.5 font-semibold text-slate-800 capitalize">${escapeHtml(b.clientName)}</td>
      <td class="px-5 py-3.5 text-right whitespace-nowrap">
        <span class="font-bold text-slate-800">${b.depositAmount ? CashierApp.formatCurrency(b.depositAmount) : '—'}</span>
        <span class="block text-xs text-slate-400">of ${b.requiredAmount ? CashierApp.formatCurrency(b.requiredAmount) : CashierApp.formatCurrency(b.price)}</span>
      </td>
      <td class="px-5 py-3.5">
        <span class="font-medium text-slate-700">${escapeHtml(method)}</span>
        ${b.depositReference ? `<span class="block font-mono text-xs text-slate-400">${escapeHtml(b.depositReference)}</span>` : ''}
      </td>
      <td class="px-5 py-3.5 whitespace-nowrap text-slate-500">${escapeHtml(b.submittedAt || '—')}</td>
      <td class="px-5 py-3.5 text-right"><button data-ref="${escapeHtml(b.id)}" class="btn-verify bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap">Verify</button></td>
    </tr>`;
  }).join('');
}

function openVerifyModal(ref) {
  state.pendingVerifyRef = ref;
  const booking = state.data.bookings.find(b => b.id === ref);
  const allowedDepositMethods = ['Cash', 'GCash', 'Maya', 'PayMongo'];
  document.getElementById('verifyAmountInput').value = booking ? (booking.depositAmount || booking.price) : '';
  document.getElementById('verifyMethodInput').value = booking && allowedDepositMethods.includes(booking.depositMethod) ? booking.depositMethod : '';
  CashierApp.showModal('verifyModal');
}

/* If the reservation payment is valid: the appointment moves to Confirmed,
   and payment status resolves to "Reservation Payment Received" (partial)
   or "Paid in Full" (full amount) -- backend/cashier/updateStatus.php's
   Confirmed branch already derives which one from the amount vs. total. */
async function confirmVerify() {
  const amount = document.getElementById('verifyAmountInput').value;
  const method = document.getElementById('verifyMethodInput').value;
  if (!amount || Number(amount) <= 0 || !method) {
    CashierApp.toast('An amount and payment method are required.', 'error');
    return;
  }
  // Pending bookings are confirmed by verifying; any other status (e.g.
  // Reschedule Requested) only gets its payment recorded.
  const booking = state.data.bookings.find(b => b.id === state.pendingVerifyRef);
  const payload = booking && booking.status !== 'Pending'
    ? { id: state.pendingVerifyRef, paymentAction: 'verify', depositAmount: amount, depositMethod: method }
    : { id: state.pendingVerifyRef, status: 'Confirmed', depositAmount: amount, depositMethod: method };
  const result = await CashierApp.post('updateStatus.php', payload);
  CashierApp.toast(result.message || (result.success ? 'Payment verified.' : 'Failed to verify payment.'), result.success ? 'success' : 'error');
  if (result.success) {
    CashierApp.hideModal('verifyModal');
    const refreshed = await CashierApp.fetchDashboard();
    if (refreshed.success) { state.data = refreshed; renderVerifyList(); }
  }
}

async function markNeedsAttention() {
  if (!window.confirm('Flag this payment as needing attention? The customer will be notified to contact the branch.')) return;
  const result = await CashierApp.post('updateStatus.php', { id: state.pendingVerifyRef, paymentAction: 'needs_attention' });
  CashierApp.toast(result.message || (result.success ? 'Payment flagged.' : 'Failed to update payment.'), result.success ? 'success' : 'error');
  if (result.success) {
    CashierApp.hideModal('verifyModal');
    const refreshed = await CashierApp.fetchDashboard();
    if (refreshed.success) { state.data = refreshed; renderVerifyList(); }
  }
}

function showNotFound() {
  document.getElementById('ticketNotFound').classList.remove('hidden');
  document.getElementById('ticketSummaryCard').classList.add('hidden');
  document.getElementById('paymentPanel').classList.add('hidden');
}

function renderTicketSummary(branch) {
  const t = state.ticket;
  document.getElementById('ticketSummaryCard').classList.remove('hidden');
  const refEl = document.getElementById('invoiceIdDisplay');
  refEl.textContent = t.id;
  refEl.classList.remove('hidden');
  document.getElementById('customerNameDisplay').textContent = t.clientName;
  document.getElementById('ticketClientName').textContent = t.clientName;
  document.getElementById('ticketClientPhone').textContent = t.clientPhone;
  document.getElementById('ticketBranchName').textContent = branch ? branch.name : t.branchId;
  document.getElementById('ticketStaffName').textContent = t.staffName || 'Unassigned';

  const servicesEl = document.getElementById('ticketServices');
  const serviceNames = (t.serviceName || '').split(',').map(s => s.trim()).filter(Boolean);
  servicesEl.innerHTML = serviceNames.length
    ? serviceNames.map(name => `<span class="bg-[#006D6F]/10 text-[#06464A] text-sm font-semibold px-3 py-1 rounded-full">${escapeHtml(name)}</span>`).join('')
    : '<span class="text-sm text-gray-400 italic">No services listed</span>';

  renderStatusUI();
}

const STATUS_STEPS = ['Confirmed', 'In Progress', 'Completed'];

function renderStatusUI() {
  const t = state.ticket;
  const badge = document.getElementById('ticketStatusBadge');
  badge.textContent = t.status;
  badge.className = `text-xs font-bold px-2.5 py-1 rounded-full ${statusBadgeClass(t.status)}`;

  const currentIndex = STATUS_STEPS.indexOf(t.status); // -1 while still Pending

  // Numbered step circles: done = check, current = filled teal, upcoming = outline.
  document.querySelectorAll('.progress-seg').forEach((seg, i) => {
    const base = 'progress-seg w-7 h-7 shrink-0 rounded-full flex items-center justify-center text-xs font-bold transition-colors duration-300';
    if (i < currentIndex) {
      seg.className = `${base} bg-emerald-600 text-white`;
      seg.innerHTML = '<i class="fa-solid fa-check"></i>';
    } else {
      seg.className = `${base} ${i === currentIndex ? 'bg-[#006D6F] text-white' : 'bg-white border-2 border-gray-300 text-gray-400'}`;
      seg.textContent = String(i + 1);
    }
  });

  document.querySelectorAll('.progress-seg-label').forEach((label, i) => {
    const textClass = i < currentIndex ? 'text-emerald-700' : i === currentIndex ? 'text-slate-900' : 'text-gray-400';
    label.className = `progress-seg-label text-sm font-semibold transition-colors duration-300 ${textClass}`;
  });

  // "Start Service" only makes sense once a deposit has confirmed the
  // booking (currentIndex 0); "Mark Completed" only while actively in
  // progress (currentIndex 1). One button reflects whichever applies.
  const isCompleted = currentIndex === 2;
  const actionBtn = document.getElementById('btnWorkflowAction');
  const btnBase = 'w-full font-bold py-2.5 px-3 rounded-xl text-sm flex items-center justify-center gap-2 disabled:bg-slate-200 disabled:text-slate-400 disabled:cursor-not-allowed';
  if (currentIndex === 1) {
    actionBtn.className = `${btnBase} bg-emerald-600 hover:bg-emerald-700 text-white`;
    actionBtn.innerHTML = '<i class="fa-solid fa-flag-checkered"></i><span>Mark Service Completed</span>';
    actionBtn.disabled = false;
  } else {
    actionBtn.className = `${btnBase} bg-amber-500 hover:bg-amber-600 text-slate-950`;
    actionBtn.innerHTML = currentIndex === 0
      ? '<i class="fa-solid fa-scissors"></i><span>Start Service</span>'
      : '<i class="fa-solid fa-lock"></i><span>Confirm the booking first (Appointments page)</span>';
    actionBtn.disabled = currentIndex !== 0;
  }
  actionBtn.classList.toggle('hidden', isCompleted);
  const completedNotice = document.getElementById('serviceCompletedNotice');
  completedNotice.classList.toggle('hidden', !isCompleted);
  completedNotice.classList.toggle('flex', isCompleted);
}

function showAlreadyPaid(ref, receipt) {
  const notice = document.getElementById('alreadyPaidNotice');
  notice.classList.remove('hidden');
  notice.innerHTML = `
    <i class="fa-solid fa-circle-check"></i>
    <span>This visit is already paid (${escapeHtml(receipt.invoiceId)}).</span>
    <a href="receipt.html?ref=${encodeURIComponent(ref)}&pid=${encodeURIComponent(receipt.paymentId)}" class="ml-auto font-bold underline">View receipt</a>
  `;
  notice.className = 'bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-sm text-emerald-800 flex items-center gap-2';
  document.getElementById('btnSellProduct').disabled = true;
  document.getElementById('btnProcessPayment').disabled = true;
  const completedText = document.querySelector('#serviceCompletedNotice span');
  if (completedText) completedText.textContent = 'Service completed and paid';
  // Swap the payment form for a "Paid" state so nobody collects twice.
  document.getElementById('paymentForm').classList.add('hidden');
  document.getElementById('paymentPaidState').classList.remove('hidden');
  document.getElementById('paymentPaidReceiptLink').href =
    `receipt.html?ref=${encodeURIComponent(ref)}&pid=${encodeURIComponent(receipt.paymentId)}`;
}


function renderCart() {
  const t = state.ticket;
  const container = document.getElementById('cartItemsContainer');
  const rows = [];

  rows.push(`
    <div class="flex justify-between items-start gap-4 px-6 py-4">
      <div class="min-w-0">
        <p class="font-semibold text-slate-800">Services</p>
        <p class="text-sm text-slate-500">${escapeHtml(t.serviceName || 'No services listed')}</p>
      </div>
      <span class="font-semibold text-slate-800 shrink-0">${CashierApp.formatCurrency(t.price)}</span>
    </div>
  `);

  state.cartProducts.forEach(p => {
    rows.push(`
      <div class="stagger-item flex justify-between items-center gap-4 px-6 py-4" data-cart-product="${p.id}">
        <div class="min-w-0">
          <p class="font-semibold text-slate-800">${escapeHtml(p.name)}</p>
          <p class="text-sm text-slate-500">${CashierApp.formatCurrency(p.price)} each</p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
          <div class="flex items-center rounded-lg border border-gray-200">
            <button type="button" class="cart-qty-btn w-8 h-8 font-bold text-slate-600 hover:bg-gray-100 rounded-l-lg" data-action="dec" aria-label="Decrease quantity">&minus;</button>
            <span class="w-8 text-center text-sm font-semibold">${p.qty}</span>
            <button type="button" class="cart-qty-btn w-8 h-8 font-bold text-slate-600 hover:bg-gray-100 rounded-r-lg" data-action="inc" aria-label="Increase quantity">+</button>
          </div>
          <span class="w-24 text-right font-semibold text-slate-800">${CashierApp.formatCurrency(p.price * p.qty)}</span>
          <button type="button" class="cart-remove-btn text-slate-400 hover:text-rose-600" aria-label="Remove ${escapeHtml(p.name)}"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>
    `);
  });

  container.innerHTML = rows.join('');
  renderTotals();
}

/* Mirrors the same "already-paid deposit reduces what's due now" logic
   backend/cashier/payment.php uses -- shown here so the cashier sees the
   real amount due before submitting, not the full service price again. */
function renderTotals() {
  const t = state.ticket;
  const productsTotal = state.cartProducts.reduce((sum, p) => sum + p.price * p.qty, 0);
  const serviceCharges = t ? t.price : 0;
  const reservationReceived = (t && t.depositPaid && t.depositVerified) ? (t.depositAmount || 0) : 0;
  const remainingBalance = Math.max(0, serviceCharges - reservationReceived);
  // Tips are Cash-only and never fold into a GCash/Maya transaction amount.
  const tip = state.selectedMethod === 'Cash' ? state.tip : 0;
  const total = remainingBalance + productsTotal + tip;

  document.getElementById('serviceChargesDisplay').textContent = CashierApp.formatCurrency(serviceCharges);
  document.getElementById('reservationReceivedRow').classList.toggle('hidden', reservationReceived <= 0);
  document.getElementById('reservationReceivedDisplay').textContent = '− ' + CashierApp.formatCurrency(reservationReceived);
  document.getElementById('productsChargeRow').classList.toggle('hidden', productsTotal <= 0);
  document.getElementById('subtotalDisplay').textContent = CashierApp.formatCurrency(productsTotal);
  document.getElementById('tipRow').classList.toggle('hidden', tip <= 0);
  document.getElementById('tipDisplay').textContent = CashierApp.formatCurrency(tip);
  document.getElementById('totalDisplay').textContent = CashierApp.formatCurrency(total);
  document.getElementById('payButtonLabel').textContent = 'Complete payment · ' + CashierApp.formatCurrency(total);
  state.amountDue = total;

  updateCashChange(total);
}

/* Change = Cash Received - Amount Due. Checkout is blocked (button
   disabled) while Cash Received is less than what's due; the hint under
   the button says why. */
function updateCashChange(total) {
  const btn = document.getElementById('btnProcessPayment');
  const hint = document.getElementById('payHint');
  const alreadyPaid = !document.getElementById('alreadyPaidNotice').classList.contains('hidden');
  if (alreadyPaid) return;
  if (!state.ticket) { btn.disabled = true; hint.textContent = ''; return; }
  if (state.selectedMethod !== 'Cash') {
    btn.disabled = false;
    hint.textContent = `Collect ${CashierApp.formatCurrency(total)} via ${state.selectedMethod}, then complete.`;
    return;
  }
  const received = Number(document.getElementById('cashReceivedInput').value) || 0;
  const change = Math.max(0, received - total);
  document.getElementById('changeDueDisplay').textContent = CashierApp.formatCurrency(change);
  btn.disabled = received < total - 0.001;
  hint.textContent = btn.disabled
    ? `Enter the cash received — at least ${CashierApp.formatCurrency(total)}.`
    : (change > 0 ? `Give ${CashierApp.formatCurrency(change)} change.` : 'Exact amount received.');
}


function openProductModal() {
  document.getElementById('productSearchInput').value = '';
  renderProductList('');
  CashierApp.showModal('productSaleModal');
}

function renderProductList(query) {
  const q = query.toLowerCase().trim();
  // Only retail items with a sale price can be sold; salon supplies (Professional Use) can't.
  const items = state.branchInventory.filter(i => Number(i.price) > 0 && (!q || i.name.toLowerCase().includes(q)));
  const container = document.getElementById('productListContainer');

  if (!items.length) {
    container.innerHTML = '<p class="text-gray-400 italic text-center py-6">No products match your search.</p>';
    return;
  }

  container.innerHTML = items.map(item => {
    const inCartQty = state.cartProducts.find(p => p.id === item.id)?.qty || 0;
    const remaining = item.stock - inCartQty;
    return `
    <button type="button" class="product-pick-btn w-full flex justify-between items-center bg-white border border-gray-200 hover:border-amber-400 rounded-lg p-3 text-left transition-all ${remaining <= 0 ? 'opacity-50 cursor-not-allowed' : ''}" data-product-id="${item.id}" ${remaining <= 0 ? 'disabled' : ''}>
      <div>
        <p class="font-bold text-slate-800">${escapeHtml(item.name)}</p>
        <p class="text-[10px] text-gray-400">${escapeHtml(item.category)} &middot; ${remaining} in stock</p>
      </div>
      <span class="font-bold text-amber-700">${CashierApp.formatCurrency(item.price)}</span>
    </button>
  `;
  }).join('');
}

function addProductToCart(productId) {
  const item = state.branchInventory.find(i => i.id === productId);
  if (!item) return;

  const existing = state.cartProducts.find(p => p.id === productId);
  const currentQty = existing ? existing.qty : 0;
  if (currentQty + 1 > item.stock) {
    CashierApp.toast(`Only ${item.stock} unit(s) of ${item.name} available.`, 'error');
    return;
  }

  if (existing) {
    existing.qty += 1;
  } else {
    state.cartProducts.push({ id: item.id, name: item.name, price: item.price, stock: item.stock, qty: 1 });
  }
  CashierApp.hideModal('productSaleModal');
  renderCart();
}

function adjustCartQty(productId, delta) {
  const line = state.cartProducts.find(p => p.id === productId);
  if (!line) return;
  const newQty = line.qty + delta;
  if (newQty <= 0) {
    state.cartProducts = state.cartProducts.filter(p => p.id !== productId);
  } else if (newQty > line.stock) {
    CashierApp.toast(`Only ${line.stock} unit(s) of ${line.name} available.`, 'error');
    return;
  } else {
    line.qty = newQty;
  }
  renderCart();
}

function removeCartProduct(productId) {
  state.cartProducts = state.cartProducts.filter(p => p.id !== productId);
  renderCart();
}


/* fromInput: typed into "Other" -- leave the field as typed. Otherwise a
   preset chip was clicked, so clear the field and highlight that chip. */
function setTip(value, fromInput = false) {
  state.tip = Math.max(0, Number(value) || 0);
  const input = document.getElementById('customTipInput');
  if (!fromInput) input.value = '';
  document.querySelectorAll('.tip-btn').forEach(btn => {
    btn.classList.toggle('is-active', !input.value && Number(btn.dataset.tip) === state.tip);
  });
  renderTotals();
}

function selectPaymentMethod(method) {
  state.selectedMethod = method;
  document.querySelectorAll('.pay-method-card').forEach(card => {
    const isActive = card.dataset.method === method;
    card.classList.toggle('is-active', isActive);
    card.setAttribute('aria-checked', String(isActive));
  });

  const isCash = method === 'Cash';
  document.getElementById('tipSection').classList.toggle('hidden', !isCash);
  document.getElementById('cashSection').classList.toggle('hidden', !isCash);
  document.getElementById('methodNote').classList.toggle('hidden', isCash);
  if (!isCash) {
    setTip(0);
  } else {
    renderTotals();
  }
}


async function updateTicketStatus(status) {
  const result = await CashierApp.post('updateStatus.php', { id: state.ticket.id, status });
  if (result.success) {
    state.ticket.status = status;
    renderStatusUI();
    CashierApp.toast('Status updated.', 'success');
  } else {
    CashierApp.toast(result.message || 'Failed to update status.', 'error');
  }
}


async function processPayment() {
  if (!state.ticket) return;

  const payload = {
    reference: state.ticket.id,
    paymentMethod: state.selectedMethod,
    tip: state.selectedMethod === 'Cash' ? state.tip : 0,
    products: JSON.stringify(state.cartProducts.map(p => ({ id: p.id, qty: p.qty })))
  };

  if (state.selectedMethod === 'Cash') {
    const cashReceived = Number(document.getElementById('cashReceivedInput').value) || 0;
    const dueText = document.getElementById('totalDisplay').textContent;
    const due = Number(dueText.replace(/[^0-9.]/g, '')) || 0;
    if (cashReceived < due - 0.001) {
      CashierApp.toast('Cash received is less than the amount due.', 'error');
      return;
    }
    payload.cashReceived = cashReceived;
  }

  const btn = document.getElementById('btnProcessPayment');
  btn.disabled = true;

  const result = await CashierApp.post('payment.php', payload);

  if (result.success) {
    CashierApp.toast('Payment processed. Redirecting to receipt...', 'success');
    setTimeout(() => {
      window.location.href = `receipt.html?ref=${encodeURIComponent(result.reference)}&pid=${encodeURIComponent(result.paymentId)}`;
    }, 600);
  } else {
    CashierApp.toast(result.message || 'Failed to process payment.', 'error');
    btn.disabled = false;
  }
}


function wireStaticEvents() {
  document.getElementById('btnSellProduct').addEventListener('click', openProductModal);
  document.getElementById('btnCloseProductModal').addEventListener('click', () => CashierApp.hideModal('productSaleModal'));
  document.getElementById('productSearchInput').addEventListener('input', (e) => renderProductList(e.target.value));
  document.getElementById('productListContainer').addEventListener('click', (e) => {
    const btn = e.target.closest('.product-pick-btn');
    if (!btn || btn.disabled) return;
    addProductToCart(btn.dataset.productId);
  });

  document.getElementById('cartItemsContainer').addEventListener('click', (e) => {
    const row = e.target.closest('[data-cart-product]');
    if (!row) return;
    const productId = row.dataset.cartProduct;
    if (e.target.closest('.cart-qty-btn')) {
      const action = e.target.closest('.cart-qty-btn').dataset.action;
      adjustCartQty(productId, action === 'inc' ? 1 : -1);
    } else if (e.target.closest('.cart-remove-btn')) {
      removeCartProduct(productId);
    }
  });

  document.querySelectorAll('.tip-btn').forEach(btn => {
    btn.addEventListener('click', () => setTip(btn.dataset.tip));
  });
  document.getElementById('customTipInput').addEventListener('input', (e) => setTip(e.target.value, true));
  document.getElementById('cashReceivedInput').addEventListener('input', renderTotals);
  // Quick cash: "Exact" fills the amount due; the bill buttons fill that bill.
  document.querySelectorAll('.cash-quick-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const amount = btn.dataset.exact ? (state.amountDue || 0) : Number(btn.dataset.amount);
      document.getElementById('cashReceivedInput').value = amount.toFixed(2);
      renderTotals();
    });
  });

  document.querySelectorAll('.pay-method-card').forEach(card => {
    card.addEventListener('click', () => selectPaymentMethod(card.dataset.method));
  });

  // "Confirmed" has no button here -- that transition only happens through
  // the Appointments page's Admit Client flow, which collects the deposit
  // updateStatus.php requires for it.
  document.getElementById('btnWorkflowAction').addEventListener('click', () => {
    const currentIndex = STATUS_STEPS.indexOf(state.ticket.status);
    if (currentIndex === 0) updateTicketStatus('In Progress');
    else if (currentIndex === 1) updateTicketStatus('Completed');
  });

  document.getElementById('btnProcessPayment').addEventListener('click', processPayment);

  const verifyBody = document.getElementById('verifyTableBody');
  if (verifyBody) {
    verifyBody.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-verify');
      if (btn) openVerifyModal(btn.dataset.ref);
    });
  }
  const closeVerify = document.getElementById('btnCloseVerifyModal');
  if (closeVerify) closeVerify.addEventListener('click', () => CashierApp.hideModal('verifyModal'));
  const confirmVerifyBtn = document.getElementById('btnConfirmVerify');
  if (confirmVerifyBtn) confirmVerifyBtn.addEventListener('click', confirmVerify);
  const needsAttentionBtn = document.getElementById('btnNeedsAttention');
  if (needsAttentionBtn) needsAttentionBtn.addEventListener('click', markNeedsAttention);
}

document.addEventListener('DOMContentLoaded', init);
