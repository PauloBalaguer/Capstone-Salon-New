/* ==========================================================
   register.js - runs only on register.html
   Checks every field, then asks the real backend
   (backend/auth/register.php) to create the account. Needs
   common.js to be loaded first.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {

  const form = document.getElementById('register-form');
  if (!form) return;

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

    if (!firstName) {
      LM.markWrong('reg-firstname', 'Enter your first name.');
      ok = false;
    }

    if (!lastName) {
      LM.markWrong('reg-lastname', 'Enter your last name.');
      ok = false;
    }

    if (!email) {
      LM.markWrong('reg-email', 'Enter your email, like name@email.com.');
      ok = false;
    } else if (!LM.isEmail(email)) {
      LM.markWrong('reg-email', 'Enter your email, like name@email.com.');
      ok = false;
    }

    if (!contact) {
      LM.markWrong('reg-contact', 'Enter your mobile number, like 917 123 4567.');
      ok = false;
    } else if (!LM.isMobile(contact)) {
      LM.markWrong('reg-contact', 'Enter the 10 digits after +63, starting with 9, like 917 123 4567.');
      ok = false;
    }

    if (password.length < 8) {
      LM.markWrong('reg-password', 'Use at least 8 characters.');
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
