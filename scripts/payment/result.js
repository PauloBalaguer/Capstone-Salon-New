// Landing page PayMongo returns to after checkout (success_url / cancel_url,
// built by PayMongo::resultUrl). Asks backend/public/paymongoStatus.php
// whether the payment went through. On success, PayMongo can take a few
// seconds to mark the session paid, so the check is retried briefly.
(function () {
  const params = new URLSearchParams(window.location.search);
  const ref = params.get('ref') || '';
  const token = params.get('token') || '';
  const outcome = params.get('outcome');
  const fromCustomer = params.get('from') === 'customer';

  const el = id => document.getElementById(id);
  const peso = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  if (fromCustomer) {
    el('resultBack').href = '../customer/appointments.html';
    el('resultBack').textContent = 'Go to my appointments';
  }

  function show(title, message) {
    el('resultTitle').textContent = title;
    el('resultMessage').textContent = message;
  }

  function render(data) {
    el('resultDetails').classList.remove('hidden');
    el('resultRef').textContent = data.reference;
    if (data.type === 'home_service') {
      if (fromCustomer) {
        el('resultBack').href = '../customer/home-service.html';
        el('resultBack').textContent = 'Go to my home service requests';
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
      el('resultAmountLabel').textContent = data.paid ? 'Amount paid' : 'Reservation fee due';
      el('resultAmount').textContent = peso(data.paid ? data.amountPaid : data.amountDue);
      if (data.paid) {
        show('Reservation fee received', 'Thank you! Your home service request is now confirmed. We\'ll notify you once a stylist is assigned — keep your reference number to track it.');
        return;
      }
      show('Payment not completed', 'Your reservation fee hasn\'t been paid yet. Your request is confirmed only after the fee is paid.');
      if (data.checkoutUrl) {
        el('resultRetry').href = data.checkoutUrl;
        el('resultRetry').classList.remove('hidden');
      }
      return;
    }
    if (data.paid) {
      el('resultAmountLabel').textContent = 'Amount paid';
      el('resultAmount').textContent = peso(data.amountPaid);
      show('Payment received', 'Thank you! Your reservation payment went through. The branch will confirm your appointment shortly — keep your reference number to track your booking.');
      return;
    }
    el('resultAmountLabel').textContent = 'Amount due';
    el('resultAmount').textContent = peso(data.amountDue);
    if (data.status === 'Cancelled') {
      show('Booking released', 'This booking was cancelled because the payment was not completed in time. Please book again.');
      return;
    }
    show('Payment not completed', 'Your slot is held for ' + data.holdMinutes + ' minutes after booking. Complete the payment before then, or the booking is released automatically.');
    if (data.checkoutUrl) {
      el('resultRetry').href = data.checkoutUrl;
      el('resultRetry').classList.remove('hidden');
    }
  }

  let attempts = outcome === 'success' ? 6 : 1;
  function check() {
    fetch('../../backend/public/paymongoStatus.php?ref=' + encodeURIComponent(ref) + '&token=' + encodeURIComponent(token))
      .then(r => r.json())
      .then(data => {
        if (!data.success) { show('Payment link not found', data.message || 'Please check your booking reference.'); return; }
        attempts--;
        if (!data.paid && attempts > 0) { setTimeout(check, 2000); return; }
        render(data);
      })
      .catch(() => show('Something went wrong', 'We could not check your payment. Please refresh this page.'));
  }

  if (!ref || !token) show('Payment link not found', 'This payment link is incomplete.');
  else check();
})();
