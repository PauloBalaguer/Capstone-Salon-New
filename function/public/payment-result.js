// Landing page PayMongo returns to after paying (outcome=success / cancel for
// the card page, outcome=return for the GCash / Maya authorization page).
// Asks backend/public/paymongoStatus.php whether the payment went through --
// never trusts the redirect itself. While PayMongo is still confirming, the
// check is retried briefly. Two more entry points:
//   outcome=choose          -- "Continue payment": shows the method buttons
//   outcome=pay&method=...  -- home service pay links: starts that method
// The method buttons ("Try again" / "Choose another method") call
// backend/public/paymongoPay.php, which creates a NEW payment for the same
// booking and returns the page to send the customer to.
(function () {
  const params = new URLSearchParams(window.location.search);
  const ref = params.get('ref') || '';
  const token = params.get('token') || '';
  const outcome = params.get('outcome');
  const fromCustomer = params.get('from') === 'customer';

  const el = id => document.getElementById(id);
  const peso = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const LOGOS = { 'PayMongo-GCash': 'gcash', 'PayMongo-Maya': 'maya', 'PayMongo-Card': 'card' };

  if (fromCustomer) {
    el('resultBack').href = '../../frontend/customer/appointments.html';
    el('resultBack').textContent = 'View my booking';
  }

  function show(title, message) {
    el('resultTitle').textContent = title;
    el('resultMessage').textContent = message;
  }

  function detail(rowId, valueId, value) {
    el(rowId).classList.toggle('hidden', !value);
    el(valueId).textContent = value || '';
  }

  function startPayment(method) {
    el('resultMethods').classList.add('hidden');
    el('resultReason').classList.add('hidden');
    show('Opening ' + (method === 'PayMongo-Card' ? 'the secure card page' : method === 'PayMongo-Maya' ? 'Maya' : 'GCash') + '…', 'Taking you to PayMongo to complete the payment.');
    fetch('../../backend/public/paymongoPay.php', {
      method: 'POST',
      body: new URLSearchParams({ ref, token, method, from: fromCustomer ? 'customer' : 'guest' })
    })
      .then(r => r.json())
      .then(data => {
        // replace(): Back from PayMongo shouldn't land here and start yet another payment.
        if (data.checkoutUrl) { window.location.replace(data.checkoutUrl); return; }
        if (data.paid) { check(); return; }
        // Re-show the method buttons, keeping this message on top.
        check(() => show('Payment unavailable', data.message || 'Please try again in a moment.'));
      })
      .catch(() => show('Something went wrong', 'We could not start the payment. Please refresh this page.'));
  }

  // "Try again with <last method>" first, then the others as "Choose another method".
  function offerMethods(data) {
    const box = el('resultMethods');
    if (!data.methods || !data.methods.length) return;
    const retry = outcome !== 'choose' && data.methods.some(m => m.value === data.method) ? data.method : null;
    const ordered = retry ? [...data.methods.filter(m => m.value === retry), ...data.methods.filter(m => m.value !== retry)] : data.methods;
    box.innerHTML = '';
    if (retry) {
      const hint = document.createElement('p');
      hint.className = 'text-xs text-ink/50 order-2 mt-2';
      hint.textContent = 'Or choose another method';
      box.appendChild(hint);
    }
    ordered.forEach((m, i) => {
      const button = document.createElement('button');
      button.type = 'button';
      const primary = retry && i === 0;
      button.className = 'flex items-center gap-3 rounded-full border px-4 py-2.5 text-sm font-bold transition '
        + (primary ? 'order-1 bg-plum text-white border-plum hover:brightness-110' : 'order-3 border-bronze/60 hover:bg-sand-200');
      button.innerHTML = '<span class="pm-logo pm-logo--' + LOGOS[m.value] + '" aria-hidden="true"></span><span></span>';
      button.lastChild.textContent = (primary ? 'Try again with ' : 'Pay with ') + m.label.replace(' (PayMongo)', '');
      button.addEventListener('click', () => startPayment(m.value));
      box.appendChild(button);
    });
    box.classList.remove('hidden');
  }

  function renderPaid(data) {
    PendingPayment.forget();
    el('resultAmountLabel').textContent = 'Amount paid';
    el('resultAmount').textContent = peso(data.amountPaid);
    detail('resultMethodRow', 'resultMethod', data.methodLabel);
    detail('resultDateRow', 'resultDate', data.paidAt ? new Date(data.paidAt.replace(' ', 'T') + '+08:00').toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '');
    detail('resultPaymentIdRow', 'resultPaymentId', data.paymentId);
    if (data.type !== 'home_service') {
      show('Payment successful', 'Thank you! Your reservation payment went through. The branch will confirm your appointment shortly — keep your reference number to track your booking.');
      return;
    }
    const confirmed = ['Confirmed', 'Staff Assigned', 'Service in Progress', 'Completed'].includes(data.status);
    show('Payment successful', confirmed
      ? 'Thank you! Your home service request is confirmed. Keep your reference number to track your schedule and assigned team.'
      : (data.status === 'Cancelled'
        ? 'Your payment was received. Your request remains cancelled. The branch will need to review the payment.'
        : 'Your payment was received and preserved. Your request needs staff review before the schedule can be confirmed. It has not been moved automatically.'));
  }

  function render(data) {
    el('resultDetails').classList.remove('hidden');
    el('resultRef').textContent = data.reference;
    if (data.type === 'home_service' && fromCustomer) {
      el('resultBack').href = '../../frontend/customer/home-service.html';
      el('resultBack').textContent = 'View my home service request';
    }
    if (data.kind === 'balance') {
      el('resultAmountLabel').textContent = data.paid ? 'Total paid' : 'Balance due';
      el('resultAmount').textContent = peso(data.paid ? data.amountPaid : data.amountDue);
      if (data.paid) {
        show('Balance received', data.amountDue > 0
          ? 'Thank you! Your payment went through. Remaining balance: ' + peso(data.amountDue) + '.'
          : 'Thank you! Your home service is now fully paid.');
        return;
      }
      show('Payment not completed', 'Your remaining balance hasn\'t been paid yet. You can try again, or pay in cash on the event day.');
      if (data.checkoutUrl) {
        el('resultRetry').href = data.checkoutUrl;
        el('resultRetry').classList.remove('hidden');
      }
      return;
    }
    if (data.paid) { renderPaid(data); return; }

    el('resultAmountLabel').textContent = data.type === 'home_service' ? 'Reservation fee due' : 'Amount due';
    el('resultAmount').textContent = peso(data.amountDue);
    if (data.type !== 'home_service' && data.status === 'Cancelled') {
      PendingPayment.forget();
      show('Booking released', 'This booking was cancelled because the payment was not completed in time. Please book again.');
      return;
    }
    const keepSlot = data.type === 'home_service'
      ? 'Your request is confirmed only after the reservation fee is paid.'
      : 'Your slot is held for ' + data.holdMinutes + ' minutes after booking — pay before then, or the booking is released automatically.';
    if (outcome === 'choose' && data.state !== 'failed') {
      show('Complete your payment', 'Choose how to pay. ' + keepSlot);
    } else if (data.state === 'failed' || outcome === 'cancel') {
      show('Payment was cancelled', 'No money was taken. ' + keepSlot);
    } else {
      show('Payment not completed yet', 'We haven\'t received confirmation from PayMongo. If you already paid, refresh this page in a moment. ' + keepSlot);
    }
    if (data.reason) {
      el('resultReason').textContent = 'PayMongo says: ' + data.reason;
      el('resultReason').classList.remove('hidden');
    }
    offerMethods(data);
  }

  // Coming back from PayMongo: it can take a few seconds to confirm, so poll while the payment is still pending.
  let polls = ['success', 'return'].includes(outcome) ? 10 : 0;
  function check(after) {
    fetch('../../backend/public/paymongoStatus.php?ref=' + encodeURIComponent(ref) + '&token=' + encodeURIComponent(token))
      .then(r => r.json())
      .then(data => {
        if (!data.success) { show('Payment link not found', data.message || 'Please check your booking reference.'); return; }
        const pending = data.kind === 'balance' ? !data.paid : data.state === 'pending';
        if (pending && polls-- > 0) { setTimeout(() => check(after), 3000); return; }
        render(data);
        if (after) after();
      })
      .catch(() => show('Something went wrong', 'We could not check your payment. Please refresh this page.'));
  }

  if (!ref || !token) show('Payment link not found', 'This payment link is incomplete.');
  else if (outcome === 'pay' && LOGOS[params.get('method')]) startPayment(params.get('method'));
  else check();
})();
