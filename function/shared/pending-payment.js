// "Continue payment" bar for a booking whose online payment was started in
// this tab -- e.g. the customer pressed Back on the GCash / Maya / card page.
// Booking pages call PendingPayment.remember() right before redirecting to
// PayMongo; payment-result.js calls forget() once the booking is paid or
// released, so the bar never offers a booking that can't be paid.
window.PendingPayment = {
  key: 'pendingPayment',
  tabName: 'paymongoTab',
  /** Closes the PayMongo tab opened by handOff()/a retry, if it is still open, and brings this tab forward. */
  closeTab(handle) {
    let tab = handle && !handle.closed ? handle : null;
    if (!tab) {
      try { tab = window.open('', this.tabName); } catch (e) { tab = null; }
    }
    if (tab) {
      // A blank same-origin page means no PayMongo tab existed and we just made one; either way close it.
      try { tab.close(); } catch (e) { /* not ours to close */ }
    }
    window.focus();
  },
  remember(reference, url) {
    try { sessionStorage.setItem(this.key, JSON.stringify({ reference, url })); } catch (e) { /* storage blocked */ }
  },
  /**
   * Opens PayMongo in a NEW tab and turns this tab into the result page in
   * "waiting" mode, which polls the payment status. That way the customer
   * always lands on the result screen -- even when PayMongo's page doesn't
   * send them back (e.g. GCash "Fail" in test mode stops on its own page).
   * If the browser blocks the new tab, falls back to the old same-tab redirect.
   */
  handOff(checkoutUrl, resultUrl, reference) {
    if (reference && resultUrl) this.remember(reference, resultUrl);
    // Named tab so the result page can find it again and close it once PayMongo answers.
    const tab = window.open(checkoutUrl, this.tabName);
    if (!tab) { window.location.href = checkoutUrl; return; }
    if (!resultUrl) return;
    const url = new URL(resultUrl, window.location.href);
    url.searchParams.set('outcome', 'waiting');
    window.location.href = url.toString();
  },
  forget() {
    clearInterval(this.noticeTimer);
    this.noticeVersion = (this.noticeVersion || 0) + 1;
    try { sessionStorage.removeItem(this.key); } catch (e) { /* storage blocked */ }
    document.getElementById('pendingPaymentBar')?.remove();
  },
  show() {
    clearInterval(this.noticeTimer);
    const version = this.noticeVersion = (this.noticeVersion || 0) + 1;
    let pending = null;
    try { pending = JSON.parse(sessionStorage.getItem(this.key) || 'null'); } catch (e) { /* storage blocked */ }
    document.getElementById('pendingPaymentBar')?.remove();
    if (!pending || !pending.url) return;
    // Already on the payment page: the bar would only link back to this same page.
    if (window.location.pathname.endsWith('/payment-result.html')) return;
    const host = document.getElementById('pendingPaymentNotice');
    if (host) {
      this.showNotice(host, pending, version);
      return;
    }
    const bar = document.createElement('div');
    bar.id = 'pendingPaymentBar';
    bar.setAttribute('role', 'status');
    bar.style.cssText = 'position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;max-width:34rem;margin:0 auto;display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 16px;border-radius:14px;background:#1f2937;color:#fff;font:14px/1.4 "Segoe UI",Arial,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.25)';
    bar.innerHTML = '<span>Booking <b></b> is waiting for payment. No need to book again.</span>'
      + '<a style="flex:none;background:#fff;color:#111;font-weight:700;border-radius:999px;padding:6px 14px;text-decoration:none">Continue payment</a>';
    bar.querySelector('b').textContent = pending.reference;
    bar.querySelector('a').href = pending.url;
    document.body.appendChild(bar);
  },
  async showNotice(host, pending, version) {
    try {
      const paymentUrl = new URL(pending.url, window.location.href);
      if (paymentUrl.origin !== window.location.origin) return;
      const ref = paymentUrl.searchParams.get('ref');
      const token = paymentUrl.searchParams.get('token');
      if (!ref || !token) return;
      const endpoint = new URL('../../backend/public/paymongoStatus.php', window.location.href);
      endpoint.search = new URLSearchParams({ ref, token }).toString();
      const response = await fetch(endpoint, { cache: 'no-store' });
      const data = await response.json();
      if (version !== this.noticeVersion) return;
      if (!response.ok || !data.success) {
        if (response.status === 404) this.forget();
        return;
      }
      if (data.paid || data.status === 'Cancelled' || (data.type === 'appointment' && data.holdSecondsRemaining <= 0)) {
        this.forget();
        return;
      }
      if (!data.methods?.length) return;
      if (!document.getElementById('pendingPaymentNoticeStyles')) {
        const style = document.createElement('style');
        style.id = 'pendingPaymentNoticeStyles';
        style.textContent = `
          #pendingPaymentNotice .payment-notice { margin:20px 24px 0; padding:20px; display:flex; align-items:center; justify-content:space-between; gap:20px; background:#FFF8EE; color:#06464A; border:1px solid #D9C79E; border-left:4px solid #A67C00; border-radius:14px; }
          #pendingPaymentNotice .payment-notice-title { margin:0 0 6px; font-size:16px; font-weight:700; color:#06464A; }
          #pendingPaymentNotice .payment-notice-reference { margin:0 0 6px; font-size:14px; overflow-wrap:anywhere; color:#06464A; }
          #pendingPaymentNotice .payment-notice-message { margin:0; font-size:14px; line-height:1.5; color:#52636A; }
          #pendingPaymentNotice .payment-notice-actions { display:flex; flex-direction:column; align-items:center; gap:10px; flex-shrink:0; }
          #pendingPaymentNotice .payment-notice-pay { display:inline-block; padding:11px 22px; background:#006D6F; color:#fff; border-radius:999px; font-size:14px; font-weight:700; text-decoration:none; text-align:center; }
          #pendingPaymentNotice .payment-notice-pay:hover { background:#06464A; }
          #pendingPaymentNotice .payment-notice-view { color:#006D6F; font-size:14px; font-weight:600; text-decoration:none; }
          #pendingPaymentNotice a:focus-visible { outline:3px solid #A67C00; outline-offset:4px; }
          @media(max-width:640px) { #pendingPaymentNotice .payment-notice { margin:16px 16px 0; padding:16px; flex-direction:column; align-items:stretch; gap:16px; } #pendingPaymentNotice .payment-notice-actions { align-items:stretch; text-align:center; } }
        `;
        document.head.appendChild(style);
      }
      const card = document.createElement('section');
      card.id = 'pendingPaymentBar';
      card.className = 'payment-notice';
      card.setAttribute('aria-label', 'Pending reservation payment');
      card.innerHTML = '<div><h2 class="payment-notice-title">Payment pending</h2><p class="payment-notice-reference"></p><p class="payment-notice-message"></p></div><div class="payment-notice-actions"><a class="payment-notice-pay">Continue payment</a><a class="payment-notice-view">View appointment</a></div>';
      card.querySelector('.payment-notice-reference').textContent = 'Booking ' + data.reference;
      paymentUrl.searchParams.set('outcome', 'choose');
      card.querySelector('.payment-notice-pay').href = paymentUrl.href;
      const view = card.querySelector('.payment-notice-view');
      view.href = data.type === 'home_service' ? 'home-service.html' : 'appointments.html';
      view.textContent = data.type === 'home_service' ? 'View home service request' : 'View appointment';
      const amount = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(data.amountDue);
      const deadline = data.holdSecondsRemaining == null ? null : Date.now() + data.holdSecondsRemaining * 1000;
      const update = () => {
        const seconds = deadline === null ? null : Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        if (seconds === 0) { this.forget(); return; }
        card.querySelector('.payment-notice-message').textContent = deadline === null
          ? amount + ' due. Complete your reservation payment to confirm your request.'
          : amount + ' due. Your slot is held for ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0') + '. Complete payment to keep your appointment slot.';
      };
      host.replaceChildren(card);
      update();
      if (deadline !== null) this.noticeTimer = setInterval(update, 1000);
    } catch (e) {
      // Leave the notice hidden when the booking status cannot be verified.
    }
  }
};
window.addEventListener('pageshow', () => window.PendingPayment.show());
window.addEventListener('focus', () => window.PendingPayment.show());
