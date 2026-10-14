/* ==========================================================
   login.js - runs only on pages/login/login.html
   Pressing the aperture button clip-path-reveals the login card
   in place (no navigation) from the button's own position; Close
   collapses it back to the welcome cover. Needs common.js to be
   loaded first for the shared toast/loading/password-toggle helpers.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {
  const page = document.querySelector('.page');
  const cover = document.getElementById('cover');
  const overlay = document.getElementById('login-reveal');
  const card = overlay.querySelector('.card');
  const apertureBtn = document.getElementById('aperture-btn');
  const closeBtn = document.getElementById('close-btn');
  const form = document.getElementById('login-form');
  if (!form) return;

  const submit = form.querySelector('[type=submit]');

  function setRevealOrigin() {
    const cardRect = card.getBoundingClientRect();
    const btnRect = apertureBtn.getBoundingClientRect();
    const x = (btnRect.left + btnRect.width / 2) - cardRect.left;
    const y = (btnRect.top + btnRect.height / 2) - cardRect.top;
    overlay.style.setProperty('--reveal-x', x + 'px');
    overlay.style.setProperty('--reveal-y', y + 'px');
  }

  function openReveal() {
    overlay.hidden = false;
    setRevealOrigin();
    overlay.getBoundingClientRect(); // force reflow so the clip-path transition actually runs
    page.classList.add('reveal-active');
    overlay.classList.add('is-open');
    cover.setAttribute('aria-hidden', 'true');
    apertureBtn.setAttribute('aria-expanded', 'true');
    setTimeout(function () { document.getElementById('login-email').focus(); }, 350);
  }

  function closeReveal() {
    setRevealOrigin();
    overlay.classList.remove('is-open');
    page.classList.remove('reveal-active');
    cover.removeAttribute('aria-hidden');
    apertureBtn.setAttribute('aria-expanded', 'false');
    const onDone = function (e) {
      if (e && e.target !== card) return;
      overlay.hidden = true;
      card.removeEventListener('transitionend', onDone);
    };
    card.addEventListener('transitionend', onDone);
    // Reduced-motion / no-transition fallback so it never gets stuck open.
    setTimeout(function () { overlay.hidden = true; }, 650);
    apertureBtn.focus();
  }

  apertureBtn.addEventListener('click', openReveal);
  closeBtn.addEventListener('click', closeReveal);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !overlay.hidden) closeReveal();
  });

  let busy = false;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (busy) return;
    LM.clearWrong();

    const email = LM.value('login-email');
    const password = document.getElementById('login-password').value;

    let ok = true;
    if (!LM.isEmail(email)) {
      LM.markWrong('login-email', 'Please enter a valid email address.');
      ok = false;
    }
    if (!password) {
      LM.markWrong('login-password', 'Please enter your password.');
      ok = false;
    }
    if (!ok) {
      LM.toast('Please check the highlighted fields.', 'wrong');
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

      LM.toast(result.message || 'Unable to sign in. Please try again.', 'wrong');
    } catch {
      LM.loading(false);
      LM.toast('Could not reach the server. Please try again.', 'wrong');
    } finally {
      busy = false;
      submit.disabled = false;
    }
  });
});
