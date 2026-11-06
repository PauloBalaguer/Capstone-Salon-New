/* Checkout popup on the Cashier Panel (frontend/cashier/dashboard.html).
   Takes the final payment for one visit: services + retail products + an
   optional cash tip + a loyalty reward, minus a verified reservation
   payment. Posts to backend/cashier/payment.php, which records the payment,
   marks the visit Completed, and returns the receipt to open.
   This is the same checkout the old payment.html terminal had; that page is
   now the read-only Payment Records list.

   Usage: CheckoutModal.open(booking, dashboardData) */

const CheckoutModal = (() => {
  const co = {
    ticket: null,
    inventory: [],
    cart: [], // [{ id, name, price, stock, qty }]
    tip: 0,
    method: 'Cash',
    loyalty: null,
    amountDue: 0,
    processing: false,
    paid: false
  };
  const $ = (id) => document.getElementById(id);

  async function open(booking, data) {
    Object.assign(co, { ticket: booking, cart: [], tip: 0, method: 'Cash', loyalty: null, amountDue: 0, processing: false, paid: false });
    co.inventory = (data.inventory || []).filter(i => i.branchId === booking.branchId);

    $('customerNameDisplay').textContent = booking.clientName;
    $('invoiceIdDisplay').textContent = booking.id;
    $('ticketStaffName').textContent = booking.staffName ? 'Stylist: ' + booking.staffName : 'No stylist';
    $('alreadyPaidNotice').className = 'hidden';
    $('paymentForm').classList.remove('hidden');
    $('paymentForm').inert = false;
    $('paymentPaidState').classList.add('hidden');
    $('btnSellProduct').disabled = true;
    $('btnProcessPayment').disabled = true;
    $('cashReceivedInput').value = '';
    $('customTipInput').value = '';
    $('loyaltyRewardSelect').innerHTML = '<option value="">No reward</option>';
    $('loyaltyRewardSelect').disabled = true;
    $('loyaltyTreatmentSelect').innerHTML = '';
    $('loyaltyTreatmentSelect').classList.add('hidden');
    $('loyaltyTreatmentLabel').classList.add('hidden');
    $('loyaltyRewardHint').textContent = 'One reward per visit.';
    $('loyaltyCardInfo').textContent = 'Loading loyalty card…';

    selectMethod('Cash');
    renderCart();
    CashierApp.showModal('checkoutModal');

    const [, receiptCheck] = await Promise.all([
      loadLoyaltyCard(),
      CashierApp.get('receipt.php', { reference: booking.id }).catch(() => ({ success: false }))
    ]);
    if (co.ticket !== booking) return; // another booking was opened meanwhile
    if (receiptCheck.success) {
      showAlreadyPaid(receiptCheck.receipt);
    } else {
      $('btnSellProduct').disabled = false;
      renderTotals();
    }
  }

  function close() {
    if (co.processing) return;
    CashierApp.hideModal('checkoutModal');
  }

  function receiptUrl(paymentId) {
    return `receipt.html?ref=${encodeURIComponent(co.ticket.id)}&pid=${encodeURIComponent(paymentId)}`;
  }

  function showAlreadyPaid(receipt) {
    co.paid = true;
    const notice = $('alreadyPaidNotice');
    notice.className = 'bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-sm text-emerald-800 flex items-center gap-2';
    notice.innerHTML = `<i class="fa-solid fa-circle-check"></i><span>Already paid (${escapeHtml(receipt.invoiceId)}).</span>`;
    $('btnSellProduct').disabled = true;
    $('btnProcessPayment').disabled = true;
    $('paymentForm').classList.add('hidden');
    $('paymentPaidState').classList.remove('hidden');
    $('paymentPaidReceiptLink').href = receiptUrl(receipt.paymentId);
  }

  function renderCart() {
    const t = co.ticket;
    const rows = [`
      <div class="flex justify-between items-start gap-4 px-4 py-3">
        <div class="min-w-0">
          <p class="font-semibold text-slate-800">Services</p>
          <p class="text-sm text-slate-500">${escapeHtml(t.serviceName || 'No services listed')}</p>
        </div>
        <span class="font-semibold text-slate-800 shrink-0">${CashierApp.formatCurrency(t.price)}</span>
      </div>`];
    co.cart.forEach(p => rows.push(`
      <div class="flex flex-wrap justify-between items-center gap-3 px-4 py-3" data-cart-product="${escapeHtml(p.id)}">
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
          <span class="w-20 text-right font-semibold text-slate-800">${CashierApp.formatCurrency(p.price * p.qty)}</span>
          <button type="button" class="cart-remove-btn text-slate-400 hover:text-rose-600" aria-label="Remove ${escapeHtml(p.name)}"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>`));
    $('cartItemsContainer').innerHTML = rows.join('');
    renderTotals();
  }

  async function loadLoyaltyCard() {
    const select = $('loyaltyRewardSelect');
    const treatment = $('loyaltyTreatmentSelect');
    const info = $('loyaltyCardInfo');
    const ticket = co.ticket;
    try {
      const result = await CashierApp.get('getLoyaltyCard.php', { reference: ticket.id });
      if (co.ticket !== ticket) return;
      if (!result.success) { info.textContent = result.message || 'No loyalty card for this customer.'; return; }
      co.loyalty = result;
      info.textContent = result.card.stamps + '/10 stamps · ' + result.card.rewards.length + ' reward(s) available. 1 stamp for every visit with at least ₱500 in services.';
      const deposit = ticket.depositPaid ? Number(ticket.depositAmount || 0) : 0;
      for (const reward of result.card.rewards) {
        const discount = reward.type === 'discount20' ? Math.round(ticket.price * 20) / 100 : 0;
        if (reward.type === 'discount20' && deposit > Math.round((ticket.price - discount) * 100) / 100) continue;
        if (reward.type === 'treatment' && !result.treatments.some(t => deposit <= Math.round((ticket.price - t.discount) * 100) / 100 && t.discount > 0)) continue;
        select.add(new Option(reward.type === 'discount20' ? '20% off salon services' : 'Free eligible hair treatment', reward.id));
      }
      for (const item of result.treatments || []) {
        if (deposit <= Math.round((ticket.price - item.discount) * 100) / 100 && item.discount > 0) treatment.add(new Option(item.name, item.id));
      }
      select.disabled = select.options.length === 1 || result.pointsUsed > 0;
      if (result.pointsUsed > 0) $('loyaltyRewardHint').textContent = 'Points were used on this booking. Save stamp rewards for another visit.';
      else if (select.options.length === 1 && result.card.rewards.length) $('loyaltyRewardHint').textContent = 'No reward fits this booking. Save rewards for a later visit.';
    } catch (error) {
      info.textContent = 'Could not load rewards. Close and reopen before redeeming a reward.';
    }
  }

  function onRewardChange() {
    const reward = co.loyalty?.card.rewards.find(r => String(r.id) === $('loyaltyRewardSelect').value);
    const show = reward?.type === 'treatment';
    $('loyaltyTreatmentSelect').classList.toggle('hidden', !show);
    $('loyaltyTreatmentLabel').classList.toggle('hidden', !show);
    renderTotals();
  }

  function selectedLoyaltyDiscount() {
    if (!co.loyalty || !co.ticket) return 0;
    const reward = co.loyalty.card.rewards.find(r => String(r.id) === $('loyaltyRewardSelect').value);
    if (!reward) return 0;
    if (reward.type === 'discount20') return Math.round(co.ticket.price * 20) / 100;
    const serviceId = $('loyaltyTreatmentSelect').value;
    return Number(co.loyalty.treatments.find(t => String(t.id) === serviceId)?.discount || 0);
  }

  /* Same "already-paid reservation reduces what's due now" rule as
     backend/cashier/payment.php, so the cashier sees the real amount. */
  function renderTotals() {
    const t = co.ticket;
    if (!t) return;
    const productsTotal = co.cart.reduce((sum, p) => sum + p.price * p.qty, 0);
    const rewardDiscount = selectedLoyaltyDiscount();
    const serviceCharges = Math.round((t.price - rewardDiscount) * 100) / 100;
    const reservationReceived = (t.depositPaid && t.depositVerified) ? Number(t.depositAmount || 0) : 0;
    const remaining = Math.max(0, serviceCharges - reservationReceived);
    const tip = co.method === 'Cash' ? co.tip : 0; // Tips are cash only.
    const total = remaining + productsTotal + tip;

    $('loyaltyDiscountRow').classList.toggle('hidden', rewardDiscount <= 0);
    $('loyaltyDiscountDisplay').textContent = '− ' + CashierApp.formatCurrency(rewardDiscount);
    $('serviceChargesDisplay').textContent = CashierApp.formatCurrency(t.price);
    $('reservationReceivedRow').classList.toggle('hidden', reservationReceived <= 0);
    $('reservationReceivedDisplay').textContent = '− ' + CashierApp.formatCurrency(reservationReceived);
    $('productsChargeRow').classList.toggle('hidden', reservationReceived <= 0 && rewardDiscount <= 0);
    $('remainingServiceDisplay').textContent = CashierApp.formatCurrency(remaining);
    $('productsTotalRow').classList.toggle('hidden', productsTotal <= 0);
    $('subtotalDisplay').textContent = CashierApp.formatCurrency(productsTotal);
    $('tipRow').classList.toggle('hidden', tip <= 0);
    $('tipDisplay').textContent = CashierApp.formatCurrency(tip);
    $('totalDisplay').textContent = CashierApp.formatCurrency(total);
    $('payButtonLabel').textContent = 'Complete payment · ' + CashierApp.formatCurrency(total);
    co.amountDue = total;
    updateCashChange(total);
  }

  /* The button stays locked until checkout is allowed and, for cash,
     enough has been received. The hint under it says why. */
  function updateCashChange(total) {
    const btn = $('btnProcessPayment');
    const hint = $('payHint');
    const t = co.ticket;
    if (co.paid) return;
    if (co.processing) { btn.disabled = true; return; }
    const lock = (message) => { btn.disabled = true; hint.textContent = message; };
    if (t.status === 'Confirmed') return lock('Start the service first.');
    if (!['In Progress', 'Completed'].includes(t.status)) return lock('Confirm the booking first.');
    if (t.depositPaid && !t.depositVerified && Number(t.depositAmount) > 0) return lock('Verify the reservation payment first.');
    if (co.method !== 'Cash') {
      btn.disabled = false;
      hint.textContent = `Collect ${CashierApp.formatCurrency(total)} by ${co.method}, then complete.`;
      return;
    }
    const received = Number($('cashReceivedInput').value) || 0;
    const change = Math.max(0, received - total);
    $('changeDueDisplay').textContent = CashierApp.formatCurrency(change);
    btn.disabled = received < total - 0.001;
    hint.textContent = btn.disabled
      ? `Enter the cash received (at least ${CashierApp.formatCurrency(total)}).`
      : (change > 0 ? `Give ${CashierApp.formatCurrency(change)} change.` : 'Exact amount received.');
  }

  function setTip(value, fromInput = false) {
    co.tip = Math.max(0, Number(value) || 0);
    const input = $('customTipInput');
    if (!fromInput) input.value = '';
    document.querySelectorAll('#checkoutModal .tip-btn').forEach(btn => {
      btn.classList.toggle('is-active', !input.value && Number(btn.dataset.tip) === co.tip);
    });
    renderTotals();
  }

  function selectMethod(method) {
    co.method = method;
    document.querySelectorAll('#checkoutModal .pay-method-card').forEach(card => {
      const active = card.dataset.method === method;
      card.classList.toggle('is-active', active);
      card.setAttribute('aria-checked', String(active));
    });
    const isCash = method === 'Cash';
    $('tipSection').classList.toggle('hidden', !isCash);
    $('cashSection').classList.toggle('hidden', !isCash);
    $('methodNote').classList.toggle('hidden', isCash);
    setTip(isCash ? co.tip : 0);
  }

  function openProductPicker() {
    $('productSearchInput').value = '';
    renderProductList('');
    CashierApp.showModal('productSaleModal');
  }

  function renderProductList(query) {
    const q = query.toLowerCase().trim();
    // Only retail items with a sale price can be sold; salon supplies can't.
    const items = co.inventory.filter(i => Number(i.price) > 0 && (!q || i.name.toLowerCase().includes(q)));
    const container = $('productListContainer');
    if (!items.length) {
      container.innerHTML = '<p class="text-gray-400 text-center py-6">No products match your search.</p>';
      return;
    }
    container.innerHTML = items.map(item => {
      const remaining = item.stock - (co.cart.find(p => p.id === item.id)?.qty || 0);
      return `
      <button type="button" class="product-pick-btn w-full flex justify-between items-center bg-white border border-gray-200 hover:border-[#006D6F] rounded-xl p-3 text-left ${remaining <= 0 ? 'opacity-50 cursor-not-allowed' : ''}" data-product-id="${escapeHtml(item.id)}" ${remaining <= 0 ? 'disabled' : ''}>
        <span>
          <span class="block font-bold text-slate-800">${escapeHtml(item.name)}</span>
          <span class="block text-xs text-gray-500">${escapeHtml(item.category)} · ${remaining} in stock</span>
        </span>
        <span class="font-bold text-[#06464A]">${CashierApp.formatCurrency(item.price)}</span>
      </button>`;
    }).join('');
  }

  function addProduct(productId) {
    const item = co.inventory.find(i => String(i.id) === String(productId));
    if (!item) return;
    const line = co.cart.find(p => p.id === item.id);
    if ((line ? line.qty : 0) + 1 > item.stock) {
      CashierApp.toast(`Only ${item.stock} of ${item.name} in stock.`, 'error');
      return;
    }
    if (line) line.qty += 1;
    else co.cart.push({ id: item.id, name: item.name, price: Number(item.price), stock: item.stock, qty: 1 });
    CashierApp.hideModal('productSaleModal');
    renderCart();
  }

  function changeQty(productId, delta) {
    const line = co.cart.find(p => String(p.id) === String(productId));
    if (!line) return;
    const qty = line.qty + delta;
    if (qty <= 0) co.cart = co.cart.filter(p => p !== line);
    else if (qty > line.stock) { CashierApp.toast(`Only ${line.stock} of ${line.name} in stock.`, 'error'); return; }
    else line.qty = qty;
    renderCart();
  }

  async function processPayment() {
    const btn = $('btnProcessPayment');
    if (!co.ticket || co.processing || btn.disabled) return;
    const payload = {
      reference: co.ticket.id,
      loyaltyRewardId: $('loyaltyRewardSelect').value || 0,
      loyaltyServiceId: $('loyaltyTreatmentSelect').value || 0,
      paymentMethod: co.method,
      tip: co.method === 'Cash' ? co.tip : 0,
      products: JSON.stringify(co.cart.map(p => ({ id: p.id, qty: p.qty })))
    };
    if (co.method === 'Cash') {
      const cash = Number($('cashReceivedInput').value) || 0;
      if (cash < co.amountDue - 0.001) { CashierApp.toast('Cash received is less than the amount due.', 'error'); return; }
      payload.cashReceived = cash;
    }
    if (!(await LMConfirm({ title: 'Record this payment?', message: `Record ${CashierApp.formatCurrency(co.amountDue)} by ${co.method}, complete this visit, and print a receipt. Confirm only after you have received the payment.`, confirmLabel: 'Record payment' }))) return;

    co.processing = true;
    btn.disabled = true;
    $('paymentForm').inert = true;
    $('payButtonLabel').textContent = 'Saving payment…';
    let done = false;
    try {
      const result = await CashierApp.post('payment.php', payload);
      if (result.success) {
        done = true;
        CashierApp.toast('Payment saved. Opening the receipt…', 'success');
        setTimeout(() => { window.location.href = `receipt.html?ref=${encodeURIComponent(result.reference)}&pid=${encodeURIComponent(result.paymentId)}`; }, 600);
      } else {
        CashierApp.toast(result.message || 'Failed to save the payment.', 'error');
      }
    } catch (error) {
      CashierApp.toast('Connection lost. Check Payment Records for a receipt before trying again.', 'error');
    } finally {
      if (!done) {
        co.processing = false;
        $('paymentForm').inert = false;
        renderTotals();
      }
    }
  }

  function wire() {
    $('btnCloseCheckout').addEventListener('click', close);
    $('checkoutModal').addEventListener('click', (e) => { if (e.target.id === 'checkoutModal') close(); });
    $('btnSellProduct').addEventListener('click', openProductPicker);
    $('btnCloseProductModal').addEventListener('click', () => CashierApp.hideModal('productSaleModal'));
    $('productSearchInput').addEventListener('input', (e) => renderProductList(e.target.value));
    $('productListContainer').addEventListener('click', (e) => {
      const btn = e.target.closest('.product-pick-btn');
      if (btn && !btn.disabled) addProduct(btn.dataset.productId);
    });
    $('cartItemsContainer').addEventListener('click', (e) => {
      const row = e.target.closest('[data-cart-product]');
      if (!row) return;
      if (e.target.closest('.cart-qty-btn')) changeQty(row.dataset.cartProduct, e.target.closest('.cart-qty-btn').dataset.action === 'inc' ? 1 : -1);
      else if (e.target.closest('.cart-remove-btn')) changeQty(row.dataset.cartProduct, -Infinity);
    });
    $('loyaltyRewardSelect').addEventListener('change', onRewardChange);
    $('loyaltyTreatmentSelect').addEventListener('change', renderTotals);
    document.querySelectorAll('#checkoutModal .tip-btn').forEach(btn => btn.addEventListener('click', () => setTip(btn.dataset.tip)));
    $('customTipInput').addEventListener('input', (e) => setTip(e.target.value, true));
    $('cashReceivedInput').addEventListener('input', renderTotals);
    document.querySelectorAll('#checkoutModal .cash-quick-btn').forEach(btn => btn.addEventListener('click', () => {
      const amount = btn.dataset.exact ? co.amountDue : Number(btn.dataset.amount);
      $('cashReceivedInput').value = amount.toFixed(2);
      renderTotals();
    }));
    document.querySelectorAll('#checkoutModal .pay-method-card').forEach(card => card.addEventListener('click', () => selectMethod(card.dataset.method)));
    $('btnProcessPayment').addEventListener('click', processPayment);
  }

  document.addEventListener('DOMContentLoaded', wire);
  return { open };
})();
