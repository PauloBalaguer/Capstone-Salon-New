/* ==========================================================
   reset-password.js - runs only on reset-password.html
   Step 1 checks the emailed code against the real backend
   (backend/auth/verifyResetCode.php), step 2 saves the new
   password (backend/auth/resetPassword.php). Needs common.js
   to be loaded first.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {

  const codeForm = document.getElementById('verify-code-form');
  const passForm = document.getElementById('reset-password-form');
  if (!codeForm || !passForm) return;

  const stepCode = document.getElementById('step-code');
  const stepPass = document.getElementById('step-password');

  const email = LM.fromUrl('email').toLowerCase();

  if (!email) {
    LM.toast('No email was given. Please start again.', 'wrong');
    setTimeout(function () { window.location.href = 'forgot-password.html'; }, 1800);
    return;
  }

  document.getElementById('target-email').textContent = email;

  const firstBox = document.querySelector('.otp-digit');
  if (firstBox) firstBox.focus();

  /* Step 2 re-sends this alongside the new password, because
     resetPassword.php re-validates the code itself rather than
     trusting that step 1 already checked it. */
  let verifiedCode = '';


  codeForm.addEventListener('submit', function (e) {
    e.preventDefault();

    const typed = readCodeBoxes();

    if (typed.length < 6) {
      LM.toast('Please enter all 6 digits.', 'wrong');
      return;
    }

    LM.loading(true);

    const body = new FormData();
    body.append('email', email);
    body.append('code', typed);

    fetch('../../backend/auth/verifyResetCode.php', {
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

        if (!res.body.success) {
          LM.toast(res.body.message || 'That code is not correct. Please try again.', 'wrong');
          clearBoxes();
          return;
        }

        verifiedCode = typed;
        stepCode.style.display = 'none';
        stepPass.style.display = 'block';
        document.getElementById('new-password').focus();
        LM.toast('Code verified. Please set your new password.', 'good');
      })
      .catch(function () {
        LM.loading(false);
        LM.toast('Could not reach the server. Please try again.', 'wrong');
      });
  });


  passForm.addEventListener('submit', function (e) {
    e.preventDefault();
    LM.clearWrong();

    const password = document.getElementById('new-password').value;
    const confirm  = document.getElementById('confirm-password').value;

    let ok = true;

    if (password.length < 8) {
      LM.markWrong('new-password', 'Password must be at least 8 characters.');
      ok = false;
    }

    if (!confirm) {
      LM.markWrong('confirm-password', 'Please type your password again.');
      ok = false;
    } else if (password !== confirm) {
      LM.markWrong('confirm-password', 'The two passwords do not match.');
      ok = false;
    }

    if (!ok) {
      LM.toast('Please check the highlighted fields.', 'wrong');
      return;
    }

    LM.loading(true);

    const body = new FormData();
    body.append('email', email);
    body.append('code', verifiedCode);
    body.append('new_password', password);
    body.append('confirm_password', confirm);

    fetch('../../backend/auth/resetPassword.php', {
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

        if (!res.body.success) {
          LM.toast(res.body.message || 'Could not reset your password. Please try again.', 'wrong');
          return;
        }

        LM.toast(res.body.message || 'Your password has been changed. Taking you to sign in.', 'good');
        setTimeout(function () { window.location.href = 'login.html'; }, 1700);
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

    fetch('../../backend/auth/forgotPassword.php', {
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
        LM.toast(res.body.message || 'If that email is registered, a new code has been sent.', 'note');
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
