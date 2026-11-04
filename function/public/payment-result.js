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
        if (data.checkoutUrl) {
          // Pay in a new tab while this one waits for the result.
          const tab = window.open(data.checkoutUrl, PendingPayment.tabName);
          // Blocked: same tab. replace(): Back from PayMongo shouldn't land here and start yet another payment.
          if (!tab) { window.location.replace(data.checkoutUrl); return; }
          payTab = tab;
          waitForResult();
          return;
        }
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
      show('Payment successful', 'Thank you! Your reservation payment went through. The branch will confirm your appointment shortly keep your reference number to track your booking.');
      LateArrivalRule.show(el('resultLateRule'));
      return;
    }
    const confirmed = ['Confirmed', 'Staff Assigned', 'Service in Progress', 'Completed'].includes(data.status);
    show('Payment successful', confirmed
      ? 'Thank you! Your home service request is confirmed. Keep your reference number to track your schedule and assigned team.'
      : (data.status === 'Cancelled'
        ? 'Your payment was received. Your request remains cancelled. The branch will need to review the payment.'
        : 'Your payment was received and preserved. Your request needs staff review before the schedule can be confirmed. It has not been moved automatically.'));
  }

  // Live countdown of the slot hold (seconds come from paymongoStatus.php).
  let holdTimer = null;
  function stopHold() {
    clearInterval(holdTimer);
    el('resultHold').classList.add('hidden');
  }
  function startHold(seconds) {
    stopHold();
    const deadline = Date.now() + seconds * 1000;
    const paint = () => {
      const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      if (left === 0) {
        stopHold();
        check(); // the server decides whether the booking was released
        return;
      }
      el('resultHold').innerHTML = 'Slot held for <b></b>. Pay before then, or the booking is released automatically.';
      el('resultHold').querySelector('b').textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
    };
    el('resultHold').classList.remove('hidden');
    paint();
    holdTimer = setInterval(paint, 1000);
  }

  function render(data) {
    stopHold();
    // Re-renders (polling, countdown end) must not leave the previous state behind.
    el("resultReason").classList.add("hidden");
    el("resultMethods").classList.add("hidden");
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
      : (data.holdSecondsRemaining > 0 ? '' : 'The time to pay for this slot has ended. The booking will be released shortly.');
    if (outcome === 'choose' && data.state !== 'failed') {
      show('Complete your payment', ('Choose how to pay. ' + keepSlot).trim());
    } else if (data.state === 'failed' || outcome === 'cancel') {
      show(outcome === 'cancel' ? 'Payment was cancelled' : 'Payment didn\'t go through', ('No money was taken. ' + keepSlot).trim());
    } else {
      show('Payment not completed yet', ('We haven\'t received confirmation from PayMongo. If you already paid, refresh this page in a moment. ' + keepSlot).trim());
    }
    if (data.reason && /\s/.test(data.reason)) {
      el('resultReason').textContent = data.reason;
      el('resultReason').classList.remove('hidden');
    }
    if (data.type !== 'home_service' && data.holdSecondsRemaining > 0) startHold(data.holdSecondsRemaining);
    offerMethods(data);
  }

  // Coming back from PayMongo: it can take a few seconds to confirm, so poll while the payment is still pending.
  let polls = ['success', 'return'].includes(outcome) ? 30 : 0;
  let pollTimer = null;
  let waiting = false; // true while PayMongo is open in another tab
  let payTab = null;

  // PayMongo is open in another tab: keep checking (every 1 s, up to the 30-minute hold) until it is paid or failed.
  function waitForResult() {
    el('resultMethods').classList.add('hidden');
    el('resultReason').classList.add('hidden');
    show('Waiting for your payment…', 'Finish paying in the PayMongo tab. This page updates by itself once PayMongo responds you can close the PayMongo tab afterwards.');
    polls = 1800;
    waiting = true;
    stopHold();
    clearTimeout(pollTimer);
    check();
  }

  function check(after) {
    clearTimeout(pollTimer);
    fetch('../../backend/public/paymongoStatus.php?ref=' + encodeURIComponent(ref) + '&token=' + encodeURIComponent(token))
      .then(r => r.json())
      .then(data => {
        if (!data.success) { show('Payment link not found', data.message || 'Please check your booking reference.'); return; }
        const pending = data.kind === 'balance' ? !data.paid : data.state === 'pending';
        if (pending && polls-- > 0) { pollTimer = setTimeout(() => check(after), 1000); return; }
        polls = 0;
        // PayMongo answered: close its tab (it may be stuck on "Source ... has expired") and show the result here.
        if (waiting) { waiting = false; PendingPayment.closeTab(payTab); payTab = null; }
        render(data);
        if (after) after();
      })
      .catch(() => show('Something went wrong', 'We could not check your payment. Please refresh this page.'));
  }

  if (!ref || !token) show('Payment link not found', 'This payment link is incomplete.');
  else if (outcome === 'pay' && LOGOS[params.get('method')]) startPayment(params.get('method'));
  else if (outcome === 'waiting') waitForResult();
  else check();

  // Coming back to this tab from PayMongo: check right away instead of waiting for the next poll.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && polls > 0) check();
  });
})();
