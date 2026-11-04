/* ==========================================================
   login.js - runs only on frontend/auth/login.html
   Validates the sign-in form, posts it to backend/auth/login.php
   and follows the role redirect it returns. Needs common.js to be
   loaded first for the shared toast/loading/password-toggle helpers.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('login-form');
  if (!form) return;

  const submit = form.querySelector('[type=submit]');
  document.getElementById('login-email').focus();

  let busy = false;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (busy) return;
    LM.clearWrong();

    const email = LM.value('login-email');
    const password = document.getElementById('login-password').value;

    let ok = true;
    if (!LM.isEmail(email)) {
      LM.markWrong('login-email', 'Enter your email, like name@email.com.');
      ok = false;
    }
    if (!password) {
      LM.markWrong('login-password', 'Enter your password.');
      ok = false;
    }
    if (!ok) {
      LM.reportWrong();
      return;
    }

    const body = new FormData();
    body.append('email', email.toLowerCase());
    body.append('password', password);
    if (document.getElementById('remember-me').checked) body.append('remember', '1');

    busy = true;
    submit.disabled = true;
    LM.loading(true);

    try {
      const response = await fetch('../../backend/auth/login.php', { method: 'POST', credentials: 'include', body });
      const result = await response.json();
      LM.loading(false);

      if (response.ok && result.success) {
        LM.toast(result.message || 'Signed in. Redirecting…', 'good');
        window.location.assign(result.redirect);
        return;
      }

      if (response.status === 403 && result.needs_verification) {
        window.location.assign('verify-otp.html?email=' + encodeURIComponent(result.email || email));
        return;
      }

      LM.toast(result.message || 'Unable to sign in. Please try again.', 'wrong',
        response.status === 429 ? 'Too many attempts' : 'Couldn’t sign you in');
    } catch {
      LM.loading(false);
      LM.toast('Could not reach the server. Please try again.', 'wrong');
    } finally {
      busy = false;
      submit.disabled = false;
    }
  });
});
