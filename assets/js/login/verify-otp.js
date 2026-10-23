/* ==========================================================
   verify-otp.js - runs only on verify-otp.html
   Reads the six boxes and asks the real backend
   (backend/auth/verifyOtp.php) to activate the account.
   Needs common.js to be loaded first.
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

  document.getElementById('target-email').textContent = email;

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

    fetch('../../backend/auth/verifyOtp.php', {
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

    fetch('../../backend/auth/resendOtp.php', {
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

    let secondsLeft = 30;
    resendBtn.disabled = true;
    resendBtn.textContent = 'Resend in ' + secondsLeft + 's';

    const timer = setInterval(function () {
      secondsLeft--;
      if (secondsLeft <= 0) {
        clearInterval(timer);
        resendBtn.disabled = false;
        resendBtn.textContent = 'Resend code';
      } else {
        resendBtn.textContent = 'Resend in ' + secondsLeft + 's';
      }
    }, 1000);
  });


  function clearBoxes() {
    document.querySelectorAll('.otp-digit').forEach(function (box) {
      box.value = '';
      box.classList.remove('filled');
    });
    if (firstBox) firstBox.focus();
  }
});
