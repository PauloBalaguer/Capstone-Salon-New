/* ==========================================================
   Leo Mejillano Salon and Beauty - shared login helpers
   Capstone Project, STI College Legazpi

   Loaded by all five login pages before their own script.
   Holds the things every page needs: the toast message, the
   loading spinner, the show/hide password buttons, and the
   code boxes. Account data and the 6-digit codes themselves
   live server-side now (see backend/auth/*.php) - each page's
   own script talks to those endpoints directly with fetch().
   ========================================================== */

/* Everything is kept inside one object so the five page
   scripts do not accidentally overwrite each other. */
const LM = {


  /* Shows a small message at the bottom of the screen
     (function/shared/toast.js). kind can be 'good', 'wrong', or 'note'. */
  toast(message, kind) {
    LMToast.show(message, kind || 'good', { duration: 4200 });
  },

  loading(on) {
    const box = document.getElementById('loading');
    if (box) box.classList.toggle('show', !!on);
  },



  markWrong(inputId, message) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const box = input.closest('.field-box');
    if (box) box.classList.add('is-wrong');

    const note = document.getElementById(inputId + '-error');
    if (note) {
      note.textContent = message;
      note.classList.add('show');
    }
  },

  clearWrong() {
    document.querySelectorAll('.field-box.is-wrong')
      .forEach(function (b) { b.classList.remove('is-wrong'); });
    document.querySelectorAll('.field-error.show')
      .forEach(function (n) { n.classList.remove('show'); });
  },

  value(id) {
    const input = document.getElementById(id);
    return input ? input.value.trim() : '';
  },

  /* A plain check for an address that has a name, an @, and a dot. */
  isEmail(text) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text);
  },

  /* Philippine mobile number: 11 digits starting with 09. */
  isMobile(text) {
    return /^09\d{9}$/.test(text);
  },

  /* Reads a value out of the address bar, for example
     verify-otp.html?email=ana@email.com */
  fromUrl(key) {
    return new URLSearchParams(window.location.search).get(key) || '';
  }
};


document.addEventListener('DOMContentLoaded', function () {

  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const input = document.getElementById(btn.dataset.togglePassword);
      if (!input) return;

      const nowVisible = input.type === 'password';
      input.type = nowVisible ? 'text' : 'password';
      btn.setAttribute('aria-label', nowVisible ? 'Hide password' : 'Show password');

      const slash = btn.querySelector('[data-eye-slash]');
      if (slash) slash.style.display = nowVisible ? 'inline' : 'none';
    });
  });


  document.querySelectorAll('.digits-only').forEach(function (input) {
    input.addEventListener('keypress', function (e) {
      if (e.key.length === 1 && (e.key < '0' || e.key > '9')) e.preventDefault();
    });
    input.addEventListener('input', function () {
      this.value = this.value.replace(/[^0-9]/g, '');
    });
  });


  const digits = Array.from(document.querySelectorAll('.otp-digit'));

  digits.forEach(function (box, i) {

    box.addEventListener('input', function () {
      box.value = box.value.replace(/[^0-9]/g, '').slice(0, 1);
      box.classList.toggle('filled', box.value !== '');
      if (box.value && digits[i + 1]) digits[i + 1].focus();
    });

    box.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !box.value && digits[i - 1]) {
        digits[i - 1].focus();
      }
      if (e.key === 'ArrowLeft'  && digits[i - 1]) digits[i - 1].focus();
      if (e.key === 'ArrowRight' && digits[i + 1]) digits[i + 1].focus();
    });

    box.addEventListener('paste', function (e) {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData)
        .getData('text').replace(/[^0-9]/g, '');

      for (let n = 0; n < digits.length; n++) {
        digits[n].value = pasted[n] || '';
        digits[n].classList.toggle('filled', digits[n].value !== '');
      }
      const lastFilled = Math.min(pasted.length, digits.length) - 1;
      if (digits[lastFilled]) digits[lastFilled].focus();
    });
  });


  document.querySelectorAll('.field-box input').forEach(function (input) {
    input.addEventListener('input', function () {
      const box = input.closest('.field-box');
      if (box) box.classList.remove('is-wrong');

      const note = document.getElementById(input.id + '-error');
      if (note) note.classList.remove('show');
    });
  });
});


function readCodeBoxes() {
  return Array.from(document.querySelectorAll('.otp-digit'))
    .map(function (b) { return b.value; })
    .join('');
}