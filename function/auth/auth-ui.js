/* ==========================================================
   auth-ui.js - shared look-and-feel for every auth page.
   Load after common.js and before the page's own script.
   - draws the animated teal background
   - strength bar ([data-pw-meter]) and rule checklist ([data-pw-rules])
     under new-password fields. Guidance only: the page scripts and the
     backend still enforce just the 8-character minimum and the match.
   - resend countdown and masked email for the code pages
   Show/Hide buttons and the code boxes are wired by common.js.
   ========================================================== */

(function () {
  const bubbles = [[8, 6, 16, 0], [18, 10, 22, 4], [30, 4, 14, 8], [44, 8, 20, 2], [58, 5, 18, 10],
    [70, 12, 26, 6], [82, 6, 15, 3], [92, 9, 24, 12], [50, 4, 17, 14], [24, 7, 21, 9]];

  document.body.insertAdjacentHTML('afterbegin',
    '<div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">' +
      '<div class="absolute -left-40 -top-40 h-[520px] w-[520px] rounded-full bg-teal-glow-auth/50 blur-3xl motion-safe:animate-drift"></div>' +
      '<div class="absolute -bottom-48 right-[-120px] h-[600px] w-[600px] rounded-full bg-gold-auth/20 blur-3xl motion-safe:animate-drift2"></div>' +
      '<div class="absolute left-[40%] top-[30%] h-[360px] w-[360px] rounded-full bg-sand/10 blur-3xl motion-safe:animate-drift2 [animation-delay:-8s]"></div>' +
      '<svg class="absolute -right-56 -top-56 h-[720px] w-[720px] motion-safe:animate-spin-slow" viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="96" stroke="#C9A24E" stroke-opacity=".35" stroke-width=".4" stroke-dasharray="1 4"/><circle cx="100" cy="100" r="80" stroke="#E8DCC8" stroke-opacity=".12" stroke-width=".3"/></svg>' +
      '<svg class="absolute inset-0 h-full w-full" viewBox="0 0 1600 1000" preserveAspectRatio="xMidYMid slice" fill="none"><path d="M-100 980C300 620 700 380 1700 260" stroke="#C9A24E" stroke-opacity=".5" stroke-width="1.4" stroke-dasharray="2400" stroke-dashoffset="2400" class="motion-safe:animate-sweep motion-reduce:[stroke-dashoffset:0]"/><path d="M-100 1020C300 660 700 420 1700 300" stroke="#E8DCC8" stroke-opacity=".15" stroke-width="1"/></svg>' +
      '<div class="absolute inset-y-0 left-0 w-1/3 bg-linear-to-r from-transparent via-white/5 to-transparent motion-safe:animate-shimmer"></div>' +
      bubbles.map(function (b) {
        return '<span class="absolute bottom-[-20px] rounded-full bg-sand/30 motion-safe:animate-rise" style="left:' + b[0] +
          '%;width:' + b[1] + 'px;height:' + b[1] + 'px;animation-duration:' + b[2] + 's;animation-delay:' + b[3] + 's"></span>';
      }).join('') +
    '</div>');

  // Swatch fan in the phone header's corner, behind the text. Hidden below
  // 360px so it never covers the headline.
  const swatches = ['#2B1D16', '#5A3A26', '#8C2F2A', '#B5713A', '#D9B07A', '#EEDDC2', '#7E8FB8'];
  const hero = document.querySelector('.hero');
  if (hero) hero.insertAdjacentHTML('beforeend',
    '<div aria-hidden="true" class="absolute -bottom-4 -right-8 -z-10 hidden h-[190px] w-[150px] min-[360px]:block">' +
      swatches.map(function (color, i) {
        return '<span class="strand absolute bottom-0 left-[45px] h-[165px] w-10 origin-[50%_calc(100%-22px)] rounded-[20px] shadow-lg [transform:rotate(var(--r))] motion-safe:animate-fan" style="background-color:' +
          color + ';--r:' + (i * 12 - 12) + 'deg;animation-delay:' + (i * 0.05) + 's"></span>';
      }).join('') +
      '<span class="absolute bottom-[10px] left-[55px] h-5 w-5 rounded-full border-2 border-gold-auth bg-teal-900"></span>' +
    '</div>');
})();


function passwordChecks(v) {
  return {
    len: v.length >= 8,
    case: /[a-z]/.test(v) && /[A-Z]/.test(v),
    num: /\d/.test(v),
    symbol: /[^\w\s]/.test(v)
  };
}

/* ana.cruz@gmail.com -> a•••@gmail.com */
function maskEmail(email) {
  const at = email.indexOf('@');
  return at > 0 ? email[0] + '•••' + email.slice(at) : email;
}

/* Fills every [data-email] slot (form subtitle and the phone header). */
function showEmail(email) {
  document.querySelectorAll('[data-email]').forEach(function (el) {
    el.textContent = maskEmail(email);
  });
}

/* Locks the resend button and counts down, e.g. "Resend code in 0:45". */
function startResendCountdown(btn, seconds) {
  let left = seconds || 45;
  clearInterval(btn._timer);
  btn.disabled = true;

  function paint() {
    btn.textContent = left > 0 ? 'Resend code in 0:' + String(left).padStart(2, '0') : 'Resend code';
  }
  paint();

  btn._timer = setInterval(function () {
    left--;
    paint();
    if (left <= 0) {
      clearInterval(btn._timer);
      btn.disabled = false;
    }
  }, 1000);
}


document.addEventListener('DOMContentLoaded', function () {

  document.querySelectorAll('[data-pw-meter]').forEach(function (meter) {
    const input = document.getElementById(meter.dataset.pwMeter);
    input.addEventListener('input', function () {
      const c = passwordChecks(input.value);
      const score = c.len + c.case + c.num + c.symbol;
      meter.dataset.level = input.value ? Math.max(1, score) : '';
    });
  });

  document.querySelectorAll('[data-pw-rules]').forEach(function (list) {
    const pw = document.getElementById(list.dataset.pwRules);
    const pw2 = document.getElementById(list.dataset.pwConfirm);
    function update() {
      const met = passwordChecks(pw.value);
      met.match = !!pw.value && pw.value === pw2.value;
      list.querySelectorAll('[data-rule]').forEach(function (li) {
        li.classList.toggle('on', met[li.dataset.rule]);
      });
    }
    pw.addEventListener('input', update);
    pw2.addEventListener('input', update);
  });

  /* A code was just sent when these pages open, so start locked. */
  const resendBtn = document.getElementById('resend-btn');
  if (resendBtn) startResendCountdown(resendBtn);
});
