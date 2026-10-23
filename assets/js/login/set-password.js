/* ==========================================================
   set-password.js - runs only on set-password.html
   Reads the setup token from the URL (?token=...) and posts it
   alongside the new password to backend/auth/completeAccountSetup.php.
   Needs common.js to be loaded first.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {

  const passForm = document.getElementById('set-password-form');
  if (!passForm) return;

  const stepPassword = document.getElementById('step-password');
  const stepInvalid = document.getElementById('step-invalid');

  const token = LM.fromUrl('token');

  if (!token) {
    stepPassword.style.display = 'none';
    stepInvalid.style.display = 'block';
    return;
  }

  document.getElementById('new-password').focus();

  passForm.addEventListener('submit', function (e) {
    e.preventDefault();
    LM.clearWrong();

    const password = document.getElementById('new-password').value;
    const confirm = document.getElementById('confirm-password').value;

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
    body.append('token', token);
    body.append('new_password', password);
    body.append('confirm_password', confirm);

    fetch('../../backend/auth/completeAccountSetup.php', {
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
          LM.toast(res.body.message || 'Could not set your password. Please try again.', 'wrong');
          return;
        }

        LM.toast(res.body.message || 'Your password has been set. Taking you to sign in.', 'good');
        setTimeout(function () { window.location.href = 'login.html'; }, 1700);
      })
      .catch(function () {
        LM.loading(false);
        LM.toast('Could not reach the server. Please try again.', 'wrong');
      });
  });
});
