/* ==========================================================
   verify-phone.js - runs only on verify-phone.html
   The texted code that links guest bookings to a new account
   (backend/auth/verifyGuestPhone.php). verify-otp.js and
   login.js leave what this step needs in sessionStorage.
   ========================================================== */

document.addEventListener('DOMContentLoaded', function () {

  const form = document.getElementById('verify-phone-form');
  if (!form) return;

  const email = LM.fromUrl('email').toLowerCase();
  let claim = null;
  try { claim = JSON.parse(sessionStorage.getItem('lmPhoneClaim')); } catch { /* handled below */ }

  function backToSignIn(message) {
    sessionStorage.removeItem('lmPhoneClaim');
    LM.toast(message, 'note');
    setTimeout(function () { window.location.href = 'login.html'; }, 1800);
  }

  if (!email || !claim || !claim.token) {
    backToSignIn('Please sign in to continue.');
    return;
  }

  document.getElementById('phone-hint').textContent = claim.hint;
  const firstBox = document.querySelector('.otp-digit');
  firstBox.focus();

  const otherForm = document.getElementById('other-number-form');
  const otherOpen = document.getElementById('other-number-open');
  function showOtherNumber() {
    otherForm.hidden = false;
    otherOpen.hidden = true;
    document.getElementById('other-number').focus();
  }
  otherOpen.querySelector('a').addEventListener('click', function (e) {
    e.preventDefault();
    showOtherNumber();
  });

  const textFailed = 'We could not text a code right now. Tap Resend code in a minute, or use a different number.';
  if (!claim.codeSent) LM.toast(textFailed, 'wrong');

  function send(action, fields) {
    const body = new FormData();
    body.append('email', email);
    body.append('claimToken', claim.token);
    body.append('action', action);
    Object.keys(fields).forEach(function (key) { body.append(key, fields[key]); });

    LM.loading(true);
    return fetch('../../backend/auth/verifyGuestPhone.php', { method: 'POST', credentials: 'include', body: body })
      .then(function (response) {
        return response.json().then(function (data) { return { status: response.status, body: data }; });
      })
      .then(function (res) {
        LM.loading(false);
        if (res.body.sessionExpired) {
          backToSignIn(res.body.message);
          return null;
        }
        return res;
      })
      .catch(function () {
        LM.loading(false);
        LM.toast('Could not reach the server. Please try again.', 'wrong');
        return null;
      });
  }

  function finished(message) {
    sessionStorage.removeItem('lmPhoneClaim');
    LM.toast(message, 'good');
    setTimeout(function () { window.location.href = 'login.html'; }, 1600);
  }

  function clearBoxes() {
    document.querySelectorAll('.otp-digit').forEach(function (box) {
      box.value = '';
      box.classList.remove('filled');
    });
    firstBox.focus();
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const typed = readCodeBoxes();
    if (typed.length < 6) {
      LM.toast('Please enter all 6 digits.', 'wrong');
      return;
    }
    send('verify', { code: typed }).then(function (res) {
      if (!res) return;
      if (res.body.success) {
        finished(res.body.message);
        return;
      }
      LM.toast(res.body.message || 'That code is not correct. Please try again.', 'wrong');
      if (res.body.numberTaken) showOtherNumber();
      else clearBoxes();
    });
  });

  const resendBtn = document.getElementById('resend-btn');
  resendBtn.addEventListener('click', function () {
    startResendCountdown(resendBtn);
    send('resend', {}).then(function (res) {
      if (!res) return;
      LM.toast(res.body.message || textFailed, res.body.success ? 'note' : 'wrong');
      clearBoxes();
    });
  });

  otherForm.addEventListener('submit', function (e) {
    e.preventDefault();
    LM.clearWrong();
    const typed = LM.value('other-number').replace(/\D/g, '').replace(/^(63|0)/, '');
    const phone = typed ? '0' + typed : '';
    if (!LM.isMobile(phone)) {
      LM.markWrong('other-number', 'Enter the 10 digits after +63, starting with 9, like 917 123 4567.');
      LM.reportWrong();
      return;
    }
    send('different_number', { phone: phone }).then(function (res) {
      if (!res) return;
      if (res.body.success) {
        finished(res.body.message);
        return;
      }
      LM.markWrong('other-number', res.body.message || 'Please try another number.');
    });
  });
});
