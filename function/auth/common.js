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


  /* Shows a notification (function/shared/toast.js): the pill at the
     top on phones, a card bottom-right on desktop. kind can be 'good',
     'wrong', or 'note'; title is optional. */
  toast(message, kind, title) {
    LMToast.show(message, kind || 'good', { title: title });
  },

  loading(on) {
    const box = document.getElementById('loading');
    if (box) box.classList.toggle('show', !!on);
  },



  markWrong(inputId, message) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const box = input.closest('.field-box');
    if (box) {
      box.classList.remove('is-wrong');
      void box.offsetWidth; // restarts the shake on a repeat submit
      box.classList.add('is-wrong');
    }

    const note = document.getElementById(inputId + '-error');
    if (note) {
      note.textContent = message;
      note.classList.add('show');
    }

    if (!this.wrong.includes(input)) this.wrong.push(input);
  },

  wrong: [],

  clearWrong() {
    document.querySelectorAll('.field-box.is-wrong')
      .forEach(function (b) { b.classList.remove('is-wrong'); });
    document.querySelectorAll('.field-error.show')
      .forEach(function (n) { n.classList.remove('show'); });
    this.wrong = [];
  },

  /* After markWrong: one notification that counts and names the fields,
     e.g. "Two fields need a fix / Check your email address and password,
     then tap Sign in again." Focus moves to the first wrong field. */
  reportWrong() {
    if (!this.wrong.length) return;
    const names = this.wrong.map(function (input) {
      const label = document.querySelector('label[for="' + input.id + '"]');
      return input.dataset.field || (label ? label.textContent.trim().toLowerCase() : input.name);
    });
    const count = ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven'][names.length - 1] || names.length;
    const submit = this.wrong[0].form && this.wrong[0].form.querySelector('[type=submit]');
    const message = names.length > 2
      ? 'Start with your ' + names[0] + ', then fix the others marked in red.'
      : 'Check your ' + names.join(' and ') + (submit ? ', then tap ' + submit.textContent.trim() + ' again.' : '.');

    this.toast(message, 'wrong', count + (names.length === 1 ? ' field needs a fix' : ' fields need a fix'));
    this.wrong[0].focus();
    this.wrong[0].scrollIntoView({ block: 'center' });
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


  /* Phones: once the keyboard is up, bring the tapped field back into view. */
  document.addEventListener('focusin', function (e) {
    const field = e.target;
    if (field.matches('.inp input, .otp input')) {
      setTimeout(function () { field.scrollIntoView({ block: 'center', behavior: 'smooth' }); }, 300);
    }
  });

  /* Typing in a field (or ticking a box) clears its error right away. */
  document.addEventListener('input', function (e) {
    const box = e.target.closest('.field-box');
    if (box) box.classList.remove('is-wrong');

    const note = e.target.id && document.getElementById(e.target.id + '-error');
    if (note) note.classList.remove('show');
  });
});


function readCodeBoxes() {
  return Array.from(document.querySelectorAll('.otp-digit'))
    .map(function (b) { return b.value; })
    .join('');
}