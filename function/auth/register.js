/* ==========================================================
   register.js - runs only on register.html
   Checks every field, then asks the real backend
   (backend/auth/register.php) to create the account. Needs
   common.js to be loaded first.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {

  const form = document.getElementById('register-form');
  if (!form) return;

  // Terms & Conditions pop-up. Each link opens it at its own section; the links
  // sit inside the checkbox's label, and reading them doesn't tick the box.
  const termsModal = document.getElementById('terms-modal');
  const termsBody = document.getElementById('terms-modal-body');
  const termsBox = document.getElementById('reg-terms');
  form.querySelectorAll('[data-terms-open]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      termsModal.showModal();
      window.LateArrivalRule.show(termsModal.querySelectorAll('[data-late-rule]'));
      termsBody.scrollTop = document.querySelector(link.getAttribute('href')).offsetTop - termsBody.offsetTop - parseFloat(getComputedStyle(termsBody).paddingTop);
      termsBody.focus({ preventScroll: true });
    });
  });
  termsModal.querySelectorAll('[data-terms-close]').forEach(function (button) {
    button.addEventListener('click', function () { termsModal.close(); });
  });
  termsModal.addEventListener('click', function (e) {
    if (e.target === termsModal) termsModal.close(); // the dimmed area around it
  });
  document.getElementById('terms-modal-agree').addEventListener('click', function () {
    termsBox.checked = true;
    termsBox.dispatchEvent(new Event('input', { bubbles: true }));
    document.getElementById('reg-terms-error').classList.remove('show');
    termsModal.close();
  });

  // Keep what was typed (never the passwords) so "Go back" from the
  // verify page brings the form back filled in. Cleared once verified.
  const DRAFT_KEY = 'lmRegisterDraft';
  const draftFields = ['reg-firstname', 'reg-middlename', 'reg-middlename-na', 'reg-lastname', 'reg-email', 'reg-contact', 'reg-notify-sms', 'reg-terms'];
  try {
    const draft = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || '{}');
    draftFields.forEach(function (id) {
      const el = document.getElementById(id);
      if (!el || !(id in draft)) return;
      if (el.type === 'checkbox') el.checked = !!draft[id]; else el.value = draft[id];
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
  } catch (err) { /* storage unavailable: start blank */ }
  function saveDraft() {
    const draft = {};
    draftFields.forEach(function (id) {
      const el = document.getElementById(id);
      if (el) draft[id] = el.type === 'checkbox' ? el.checked : el.value;
    });
    try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft)); } catch (err) {}
  }
  form.addEventListener('input', saveDraft);
  form.addEventListener('change', saveDraft);

  // N/A means no middle name: clear and lock the field.
  const middleInput = document.getElementById('reg-middlename');
  const middleNA = document.getElementById('reg-middlename-na');
  function syncMiddleNA() {
    middleInput.disabled = middleNA.checked;
    if (middleNA.checked) middleInput.value = '';
  }
  middleNA.addEventListener('change', syncMiddleNA);
  setTimeout(syncMiddleNA, 0); // after a saved draft is restored

  const NAME_RE = /^[\p{L}\p{M} .’'\-]+$/u;
  const nameOk = function (v) { return NAME_RE.test(v) && /\p{L}/u.test(v) && v.length <= 100; };

  const mobileInput = document.getElementById('reg-contact');
  const mobileError = document.getElementById('reg-contact-error');
  mobileInput.setAttribute('aria-describedby', 'reg-contact-error');
  mobileError.setAttribute('aria-live', 'polite');
  function validateMobile() {
    const valid = /^9\d{9}$/.test(mobileInput.value.trim());
    mobileInput.setAttribute('aria-invalid', String(!valid));
    if (!valid) {
      LM.markWrong('reg-contact', 'Please enter a valid mobile number (9XXXXXXXXX after +63).');
    } else {
      mobileInput.closest('.field-box').classList.remove('is-wrong');
      mobileError.classList.remove('show');
      LM.wrong = LM.wrong.filter(function (input) { return input !== mobileInput; });
    }
    return valid;
  }
  mobileInput.addEventListener('blur', function () {
    validateMobile();
  });
  // Run after the shared input handler clears old errors.
  document.addEventListener('input', function (event) {
    if (event.target === mobileInput) {
      validateMobile();
    }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    LM.clearWrong();

    const firstName = LM.value('reg-firstname');
    const lastName  = LM.value('reg-lastname');
    const email     = LM.value('reg-email').toLowerCase();
    // Typed after the fixed +63 prefix, e.g. 9171234567; stored as 09171234567.
    const typedContact = LM.value('reg-contact').replace(/\D/g, '').replace(/^(63|0)/, '');
    const contact   = typedContact ? '0' + typedContact : '';
    const password  = document.getElementById('reg-password').value;
    const confirm   = document.getElementById('reg-confirm-password').value;

    let ok = true;

    const middleName = middleNA.checked ? '' : LM.value('reg-middlename');
    const badName = 'Use letters, spaces, apostrophes, periods or hyphens only.';

    if (!firstName) {
      LM.markWrong('reg-firstname', 'Enter your first name.');
      ok = false;
    } else if (!nameOk(firstName)) {
      LM.markWrong('reg-firstname', badName);
      ok = false;
    }

    if (middleName && !nameOk(middleName)) {
      LM.markWrong('reg-middlename', badName + ' Or tick N/A.');
      ok = false;
    }

    if (!lastName) {
      LM.markWrong('reg-lastname', 'Enter your last name.');
      ok = false;
    } else if (!nameOk(lastName)) {
      LM.markWrong('reg-lastname', badName);
      ok = false;
    }

    if (!email) {
      LM.markWrong('reg-email', 'Enter your email, like name@email.com.');
      ok = false;
    } else if (!LM.isEmail(email)) {
      LM.markWrong('reg-email', 'Enter your email, like name@email.com.');
      ok = false;
    }

    if (!validateMobile()) ok = false;

    if (!passwordValid(password)) {
      LM.markWrong('reg-password', 'Use 8+ characters with an uppercase letter, a lowercase letter, a number, and a special character.');
      ok = false;
    }

    if (!confirm) {
      LM.markWrong('reg-confirm-password', 'Type your password again.');
      ok = false;
    } else if (password !== confirm) {
      LM.markWrong('reg-confirm-password', 'Type the same password in both fields.');
      ok = false;
    }

    if (!document.getElementById('reg-terms').checked) {
      LM.markWrong('reg-terms', 'Tick the box to agree to the terms and deposit policy.');
      ok = false;
    }

    if (!ok) {
      LM.reportWrong();
      return;
    }

    LM.loading(true);

    const body = new FormData(form);
    body.set('contact_number', contact);

    fetch('../../backend/auth/register.php', {
      method: 'POST',
      credentials: 'include',
      body: body
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { status: response.status, body: data };
        });
      })
      .then(function (res) {
        LM.loading(false);

        if (res.body.success) {
          if (/^\d{6}$/.test(res.body.verification_code || '')) {
            sessionStorage.setItem('localRegistrationOtp', res.body.verification_code);
          } else {
            sessionStorage.removeItem('localRegistrationOtp');
          }
          LM.toast(res.body.message || 'Account created. Please check your email for a verification code.', 'note');
          setTimeout(function () {
            window.location.href = 'verify-otp.html?email=' + encodeURIComponent(res.body.email || email);
          }, 1800);
          return;
        }

        if (res.status === 409) {
          const msg = res.body.message || 'That account already exists.';
          if (msg.toLowerCase().indexOf('contact') !== -1) {
            LM.markWrong('reg-contact', msg);
          } else {
            LM.markWrong('reg-email', msg);
          }
          LM.toast(msg, 'wrong');
          return;
        }

        if (res.status === 400 && Array.isArray(res.body.errors) && res.body.errors.length) {
          LM.toast(res.body.errors[0], 'wrong');
          return;
        }

        LM.toast(res.body.message || 'Something went wrong. Please try again.', 'wrong');
      })
      .catch(function () {
        LM.loading(false);
        LM.toast('Could not reach the server. Please try again.', 'wrong');
      });
  });
});
