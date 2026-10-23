/* ==========================================================
   forgot-password.js - runs only on forgot-password.html
   Takes the email and asks the real backend
   (backend/auth/forgotPassword.php) to email a reset code.
   Needs common.js to be loaded first.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {

  const form = document.getElementById('forgot-password-form');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    LM.clearWrong();

    const email = LM.value('forgot-email').toLowerCase();

    if (!email) {
      LM.markWrong('forgot-email', 'Please enter your email address.');
      LM.toast('Please enter your email address.', 'wrong');
      return;
    }
    if (!LM.isEmail(email)) {
      LM.markWrong('forgot-email', 'That does not look like an email address.');
      LM.toast('Please check the email address.', 'wrong');
      return;
    }

    LM.loading(true);

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
        LM.loading(false);

        /* The backend always answers with success:true, whether or not
           the email is registered, so a visitor cannot use this page to
           find out which emails are registered. */
        LM.toast(res.body.message || 'If that email is registered, a reset code has been sent.', 'note');

        if (res.body.success) {
          setTimeout(function () {
            window.location.href = 'reset-password.html?email=' + encodeURIComponent(email);
          }, 2200);
        }
      })
      .catch(function () {
        LM.loading(false);
        LM.toast('Could not reach the server. Please try again.', 'wrong');
      });
  });
});
