// "Continue payment" bar for a booking whose online payment was started in
// this tab -- e.g. the customer pressed Back on the GCash / Maya / card page.
// Booking pages call PendingPayment.remember() right before redirecting to
// PayMongo; payment-result.js calls forget() once the booking is paid or
// released, so the bar never offers a booking that can't be paid.
window.PendingPayment = {
  key: 'pendingPayment',
  remember(reference, url) {
    try { sessionStorage.setItem(this.key, JSON.stringify({ reference, url })); } catch (e) { /* storage blocked */ }
  },
  forget() {
    try { sessionStorage.removeItem(this.key); } catch (e) { /* storage blocked */ }
    document.getElementById('pendingPaymentBar')?.remove();
  },
  show() {
    let pending = null;
    try { pending = JSON.parse(sessionStorage.getItem(this.key) || 'null'); } catch (e) { /* storage blocked */ }
    document.getElementById('pendingPaymentBar')?.remove();
    if (!pending || !pending.url) return;
    const bar = document.createElement('div');
    bar.id = 'pendingPaymentBar';
    bar.setAttribute('role', 'status');
    bar.style.cssText = 'position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;max-width:34rem;margin:0 auto;display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 16px;border-radius:14px;background:#1f2937;color:#fff;font:14px/1.4 "Segoe UI",Arial,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.25)';
    bar.innerHTML = '<span>Booking <b></b> is waiting for payment — no need to book again.</span>'
      + '<a style="flex:none;background:#fff;color:#111;font-weight:700;border-radius:999px;padding:6px 14px;text-decoration:none">Continue payment</a>';
    bar.querySelector('b').textContent = pending.reference;
    bar.querySelector('a').href = pending.url;
    document.body.appendChild(bar);
  }
};
window.addEventListener('pageshow', () => window.PendingPayment.show());
