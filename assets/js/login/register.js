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
    const contact   = LM.value('reg-contact');
    const password  = document.getElementById('reg-password').value;
    const confirm   = document.getElementById('reg-confirm-password').value;

    let ok = true;

    if (!firstName) {
      LM.markWrong('reg-firstname', 'Please enter your first name.');
      ok = false;
    }

    if (!lastName) {
      LM.markWrong('reg-lastname', 'Please enter your last name.');
      ok = false;
    }

    if (!email) {
      LM.markWrong('reg-email', 'Please enter your email address.');
      ok = false;
    } else if (!LM.isEmail(email)) {
      LM.markWrong('reg-email', 'That does not look like an email address.');
      ok = false;
    }

    if (!contact) {
      LM.markWrong('reg-contact', 'Please enter your mobile number.');
      ok = false;
    } else if (!LM.isMobile(contact)) {
      LM.markWrong('reg-contact', 'Use 11 digits starting with 09, like 09171234567.');
      ok = false;
    }

    if (password.length < 8) {
      LM.markWrong('reg-password', 'Password must be at least 8 characters.');
      ok = false;
    }

    if (!confirm) {
      LM.markWrong('reg-confirm-password', 'Please type your password again.');
      ok = false;
    } else if (password !== confirm) {
      LM.markWrong('reg-confirm-password', 'The two passwords do not match.');
      ok = false;
    }

    if (!ok) {
      LM.toast('Please check the highlighted fields.', 'wrong');
      return;
    }

    LM.loading(true);

    fetch('../../backend/auth/register.php', {
      method: 'POST',
      credentials: 'include',
      body: new FormData(form)
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
