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
  const rememberBox = document.getElementById('remember-me');

  // "Remember me": this page keeps only which portal to return to, never the
  // email or password. Storage can be blocked, e.g. in a private window.
  const PORTAL_KEY = 'lm-remember-portal';
  const RESUMED_KEY = 'lm-resumed';
  function readPortal() {
    try {
      localStorage.removeItem('lm-remember'); // an earlier version kept the email here
      return localStorage.getItem(PORTAL_KEY);
    } catch { return null; }
  }
  function savePortal(portal) {
    try {
      if (rememberBox.checked) localStorage.setItem(PORTAL_KEY, portal);
      else localStorage.removeItem(PORTAL_KEY);
    } catch { /* the login itself still works */ }
  }

  // Still signed in? Go straight to the dashboard. Only once per tab, so a
  // portal that sends the person back here can't bounce them forever.
  async function resumeSession(portal) {
    try {
      if (sessionStorage.getItem(RESUMED_KEY)) {
        sessionStorage.removeItem(RESUMED_KEY);
        return;
      }
      const response = await fetch('../../backend/auth/currentSession.php?portal=' + encodeURIComponent(portal), { credentials: 'include', cache: 'no-store' });
      const result = await response.json();
      if (result.loggedIn) {
        sessionStorage.setItem(RESUMED_KEY, '1');
        window.location.replace(result.redirect);
      }
    } catch { /* stay on the form */ }
  }

  const rememberedPortal = readPortal();
  if (rememberedPortal) {
    rememberBox.checked = true;
    resumeSession(rememberedPortal);
  }
  const emailInput = document.getElementById('login-email');
  const passwordInput = document.getElementById('login-password');
  emailInput.focus();

  // A recognised device (backend/auth/deviceLogin.php): typing the remembered
  // email shows dots in the password box. They are only a display: nothing
  // about the password is on this page, and signing in uses the device cookie.
  const deviceNote = document.getElementById('device-note');
  const eyeButton = form.querySelector('[data-toggle-password="login-password"]');
  let deviceRecognised = false;
  let checkTimer = null;

  function showDevice(on) {
    if (on === deviceRecognised) return;
    deviceRecognised = on;
    passwordInput.type = on ? 'text' : 'password'; // text, so a browser never offers to save the dots
    passwordInput.value = on ? '••••••••' : '';
    passwordInput.readOnly = on;
    eyeButton.style.display = on ? 'none' : '';
    deviceNote.classList.toggle('hidden', !on);
  }

  function deviceRequest(action, email) {
    const body = new FormData();
    body.append('action', action);
    if (email) body.append('email', email);
    return fetch('../../backend/auth/deviceLogin.php', { method: 'POST', credentials: 'include', body })
      .then(function (response) { return response.json(); });
  }

  emailInput.addEventListener('input', function () {
    showDevice(false);
    clearTimeout(checkTimer);
    const typed = LM.value('login-email').toLowerCase();
    if (rememberedPortal !== 'customer' || !LM.isEmail(typed)) return;
    checkTimer = setTimeout(function () {
      deviceRequest('check', typed).then(function (result) {
        const stillSame = LM.value('login-email').toLowerCase() === typed;
        if (result.recognised && stillSame && !passwordInput.value) showDevice(true);
      }).catch(function () { /* they can still type the password */ });
    }, 350);
  });

  document.getElementById('device-forget').addEventListener('click', function () {
    showDevice(false);
    rememberBox.checked = false;
    savePortal('');
    passwordInput.focus();
    deviceRequest('forget').catch(function () { /* it expires by itself */ });
  });

  let busy = false;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (busy) return;
    LM.clearWrong();

    const email = LM.value('login-email');
    const password = passwordInput.value;
    const byDevice = deviceRecognised;

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
    if (byDevice) body.append('action', 'login');
    else body.append('password', password);
    if (rememberBox.checked) body.append('remember', '1');

    busy = true;
    submit.disabled = true;
    LM.loading(true);

    try {
      const response = await fetch('../../backend/auth/' + (byDevice ? 'deviceLogin.php' : 'login.php'), { method: 'POST', credentials: 'include', body });
      const result = await response.json();
      LM.loading(false);

      if (response.ok && result.success) {
        savePortal(result.portal);
        LM.toast(result.message || 'Signed in. Redirecting…', 'good');
        window.location.assign(result.redirect);
        return;
      }

      // The device stopped working (expired, or the password changed).
      if (byDevice) {
        showDevice(false);
        passwordInput.focus();
      }

      if (response.status === 403 && result.needsPhoneCode) {
        sessionStorage.setItem('lmPhoneClaim', JSON.stringify({ token: result.claimToken, hint: result.phoneHint, codeSent: result.codeSent }));
        window.location.assign('verify-phone.html?email=' + encodeURIComponent(result.email || email));
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
