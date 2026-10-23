/* ==========================================================
   verify-otp.js - runs only on verify-otp.html
   Reads the six boxes and asks the real backend
   (auth/backend/verifyOtp.php) to activate the account.
   Needs common.js and auth-ui.js to be loaded first.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {

  const form = document.getElementById('verify-otp-form');
  if (!form) return;

  /* The email travels here in the address bar, for example
     verify-otp.html?email=ana@email.com */
  const email = LM.fromUrl('email').toLowerCase();

  if (!email) {
    LM.toast('No email was given. Please register first.', 'wrong');
    setTimeout(function () { window.location.href = 'register.html'; }, 1800);
    return;
  }

  showEmail(email);

  const firstBox = document.querySelector('.otp-digit');
  if (firstBox) firstBox.focus();

  function fillCodeBoxes(code) {
    if (!/^\d{6}$/.test(code || '')) return;
    document.querySelectorAll('.otp-digit').forEach(function (box, index) {
      box.value = code[index];
      box.classList.add('filled');
    });
  }

  const localOtp = sessionStorage.getItem('localRegistrationOtp');
  if (/^\d{6}$/.test(localOtp || '')) {
    fillCodeBoxes(localOtp);
    LM.toast('Local verification code filled in. Select Verify account.', 'note');
  }


  form.addEventListener('submit', function (e) {
    e.preventDefault();

    const typed = readCodeBoxes();

    if (typed.length < 6) {
      LM.toast('Please enter all 6 digits.', 'wrong');
      return;
    }

    LM.loading(true);

    const body = new FormData();
    body.append('email', email);
    body.append('otp', typed);

    fetch('../../auth/backend/verifyOtp.php', {
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
          sessionStorage.removeItem('localRegistrationOtp');
          LM.toast(res.body.message || 'Your email is verified. Taking you to sign in.', 'good');
          setTimeout(function () { window.location.href = 'login.html'; }, 1600);
          return;
        }

        if (res.status === 429) {
          LM.toast(res.body.message || 'Too many attempts. Please try again later.', 'wrong');
          return;
        }

        LM.toast(res.body.message || 'That code is not correct. Please try again.', 'wrong');
        clearBoxes();
      })
      .catch(function () {
        LM.loading(false);
        LM.toast('Could not reach the server. Please try again.', 'wrong');
      });
  });


  const resendBtn = document.getElementById('resend-btn');

  resendBtn.addEventListener('click', function () {
    const body = new FormData();
    body.append('email', email);

    fetch('../../auth/backend/resendOtp.php', {
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
        if (/^\d{6}$/.test(res.body.verification_code || '')) {
          sessionStorage.setItem('localRegistrationOtp', res.body.verification_code);
          fillCodeBoxes(res.body.verification_code);
        }
        LM.toast(res.body.message || 'If that account needs verification, a new code has been sent.', 'note');
      })
      .catch(function () {
        LM.toast('Could not reach the server. Please try again.', 'wrong');
      });

    clearBoxes();
    startResendCountdown(resendBtn);
  });


  function clearBoxes() {
    document.querySelectorAll('.otp-digit').forEach(function (box) {
      box.value = '';
      box.classList.remove('filled');
    });
    if (firstBox) firstBox.focus();
  }
});
