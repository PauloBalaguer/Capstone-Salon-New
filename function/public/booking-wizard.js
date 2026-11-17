/* Guest booking: screen-by-screen controller.
   Every screen size sees one screen at a time:
     1 Guest information → 2 Branch & services → 3 Date, time & stylist
     → 4 Payment → 5 Review
   with a step header, one scroll area and a fixed Back / Continue bar.

   The screens are the existing sections wrapped in .bk-step blocks, so every
   field keeps its id. This script only handles presentation and navigation:
   branch cards, progressive reveal, per-screen validation, the live summary
   and waitlist chips. It reads state kept by main.js (selectedSlotLabel,
   selectedStaffId, bookReservationQuote, ...); every API call, price
   calculation, PayMongo hand-off, the review → OTP → confirmation flow and
   the final submit stay in main.js. */
(function () {
  const $ = id => document.getElementById(id);
  const form = $('bookingModalForm');
  const box = $('bookingModalBox');
  if (!form || !box) return;

  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const peso = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const checkedServices = () => Array.from(document.querySelectorAll('#bookServiceList .book-service-check:checked'));
  const visible = el => el && !el.classList.contains('hidden');
  const formPanel = $('bookingFormPanel');
  const reviewPanel = $('bookingReviewPanel');
  const accessPanel = $('bookingAccessPanel');
  const select = $('bookBranchSelect');
  const scroller = form.querySelector('.bk-main');

  /* ---------- screens ---------- */
  const STEPS = ['details', 'services', 'schedule', 'payment'];
  const ALL = [...STEPS, 'review'];
  const TITLES = { details: 'Guest information', services: 'Branch & services', schedule: 'Date, time & stylist', payment: 'Payment', review: 'Review booking' };
  const NEXT_LABEL = { details: 'Continue', services: 'Continue', schedule: 'Continue', payment: 'Review booking' };
  let current = 'details';
  // Opened from the review's Edit links: Continue goes straight back to the review.
  let returnToReview = false;
  const attempted = new Set(); // screens whose Continue was pressed: show their messages
  const stepOf = el => el?.closest?.('.bk-step')?.dataset.step || null;
  const stepEl = step => form.querySelector('.bk-step[data-step="' + step + '"]');
  const stepped = () => box.classList.contains('bk-stepped');
  function scrollToEl(el, where = 'start') {
    if (!stepped() || !scroller.contains(el)) { el.scrollIntoView({ behavior: 'smooth', block: where }); return; }
    const top = el.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
    const offset = where === 'center' ? (scroller.clientHeight - el.offsetHeight) / 2 : 8;
    scroller.scrollTo({ top: Math.max(0, top - Math.max(8, offset)), behavior: 'smooth' });
  }

  // Applies the screen layout and the current screen (synchronous, so
  // a panel switch never paints with the wrong layout).
  function layout() {
    const formOn = visible(formPanel);
    const reviewOn = visible(reviewPanel);
    if (visible(accessPanel) && (current !== 'details' || attempted.size)) { current = 'details'; attempted.clear(); renderErrors([]); }
    if (reviewOn || visible(accessPanel)) returnToReview = false;
    box.classList.toggle('bk-is-form', formOn);
    box.classList.toggle('bk-stepped', formOn || reviewOn);
    const shown = reviewOn ? 'review' : current;
    box.dataset.bkStep = shown;
    const i = ALL.indexOf(shown);
    $('bkStepCount').textContent = 'Step ' + (i + 1) + ' of ' + ALL.length;
    $('bkStepTitle').textContent = TITLES[shown];
    document.querySelectorAll('#bkStepper .bk-stepper-bar li').forEach((li, n) => {
      li.classList.toggle('is-done', n < i);
      li.classList.toggle('is-on', n === i);
    });
    STEPS.forEach(s => stepEl(s)?.toggleAttribute('data-current', s === current));
    $('bkBack').hidden = current === 'details';
    $('bkMobileSubmit').textContent = returnToReview ? 'Back to review' : NEXT_LABEL[current];
  }

  function goto(step, focusTitle = true) {
    if (!STEPS.includes(step)) return;
    current = step;
    revealScroll = false;
    layout();
    if (stepped()) {
      scroller.scrollTop = 0;
      if (focusTitle) $('bkStepTitle').focus({ preventScroll: true });
    } else {
      stepEl(step)?.querySelector('.bk-sec')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    refresh();
  }

  function next() {
    if (returnToReview && current !== 'payment') {
      // Check this screen first, then the real submit validates the rest and
      // shows the review (or opens the screen that now needs attention).
      attempted.add(current);
      const list = problems();
      renderErrors(list);
      const bad = list.find(p => p[3] === current && p[2]);
      if (bad) { focusProblem(bad); return; }
      form.requestSubmit ? form.requestSubmit() : $('bookSubmitBtn').click();
      return;
    }
    if (current === 'payment') {
      // Final screen: the real submit (validation below, then main.js → review).
      form.requestSubmit ? form.requestSubmit() : $('bookSubmitBtn').click();
      return;
    }
    attempted.add(current);
    const list = problems();
    renderErrors(list);
    const bad = list.find(p => p[3] === current && p[2]);
    if (bad) { focusProblem(bad); return; }
    goto(STEPS[STEPS.indexOf(current) + 1]);
  }

  function back() {
    const i = STEPS.indexOf(current);
    if (i > 0) goto(STEPS[i - 1]);
  }

  $('bkMobileSubmit').addEventListener('click', next);
  $('bkBack').addEventListener('click', back);

  /* ---------- branch cards (built from the existing <select>) ---------- */
  const cards = $('bkBranchCards');
  // Once a branch is chosen only its card shows, with "Change" to see all again.
  let branchListOpen = false;
  function renderBranchCards() {
    const collapse = select.value && !branchListOpen;
    cards.innerHTML = Array.from(select.options).filter(o => o.value && (!collapse || o.value === select.value)).map(o => {
      const [name, ...rest] = o.textContent.split(/\s+[–-]\s+/);
      const on = select.value === o.value;
      return '<button type="button" role="radio" aria-checked="' + on + '" class="bk-branch-card' + (on ? ' is-selected' : '') + '" data-branch="' + esc(o.value) + '"' + (select.disabled && !on ? ' disabled' : '') + '>' +
        '<span class="bk-branch-name">' + esc(name) + '</span>' + (rest.length ? '<span class="bk-branch-meta">' + esc(rest.join(' – ')) + '</span>' : '') +
        '<span class="bk-card-check" aria-hidden="true">✓</span></button>';
    }).join('') + (collapse && !select.disabled ? '<button type="button" class="bk-branch-change">Change branch</button>' : '');
  }
  cards.addEventListener('click', e => {
    if (e.target.closest('.bk-branch-change')) {
      branchListOpen = true;
      renderBranchCards();
      cards.querySelector('.bk-branch-card.is-selected')?.focus();
      return;
    }
    const card = e.target.closest('.bk-branch-card');
    if (!card || card.disabled || select.disabled) return;
    branchListOpen = false;
    if (select.value !== card.dataset.branch) {
      select.value = card.dataset.branch;
      select.dispatchEvent(new Event('change'));
    }
    renderBranchCards();
  });
  select.addEventListener('change', renderBranchCards);

  /* ---------- waitlist: stylist chips instead of the dropdown ---------- */
  // main.js fills #bookWaitlistStaff and reads its value; the chips just set it.
  const waitSelect = $('bookWaitlistStaff');
  if (waitSelect) {
    waitSelect.classList.add('bk-visually-hidden');
    waitSelect.tabIndex = -1;
    const waitChips = document.createElement('div');
    waitChips.className = 'bk-wait-chips';
    waitChips.setAttribute('role', 'radiogroup');
    waitChips.setAttribute('aria-label', 'Stylist to wait for');
    waitSelect.insertAdjacentElement('beforebegin', waitChips);
    const renderWaitChips = () => {
      waitChips.innerHTML = Array.from(waitSelect.options).map(o =>
        '<button type="button" role="radio" class="bk-chip' + (o.selected ? ' is-on' : '') + '" aria-checked="' + o.selected + '" data-id="' + esc(o.value) + '">' + esc(o.textContent) + '</button>').join('');
    };
    waitChips.addEventListener('click', e => {
      const chip = e.target.closest('.bk-chip');
      if (!chip) return;
      waitSelect.value = chip.dataset.id;
      renderWaitChips();
    });
    new MutationObserver(renderWaitChips).observe(waitSelect, { childList: true });
  }

  /* ---------- requirements, shared by the ticks and validation ---------- */
  const paymentMethod = () => document.querySelector('input[name="bookDepositMethod"]:checked')?.value || '';
  const noOnlinePayment = () => bookReservationQuote?.reservationRequirement === 'No Online Reservation';
  const nameOk = v => v && /^[\p{L}\p{M} .’'\-]+$/u.test(v) && /\p{L}/u.test(v);

  // Each entry: [section id, message target, problem ('' when fine), screen].
  // Listed in screen order so the first problem is the earliest one.
  function problems() {
    const date = $('bookDate').value;
    const staffShown = visible($('bookStaffSection'));
    const gateShown = visible($('bookDepositGate'));
    const first = $('bookFirstName').value.trim();
    const middle = $('bookMiddleNameNA').checked ? '' : $('bookMiddleName').value.trim();
    const last = $('bookLastName').value.trim();
    const phone = $('bookPhone').value.trim();
    const email = $('bookEmail').value.trim();
    return [
      ['bkSecDetails', $('bookFirstName'), !first ? 'Please enter your first name.' : nameOk(first) ? '' : 'Please enter your first name using letters, spaces, apostrophes, periods or hyphens.'],
      ['bkSecDetails', $('bookMiddleName'), !middle || nameOk(middle) ? '' : 'Please enter your middle name using letters, spaces, apostrophes, periods or hyphens, or tick N/A.'],
      ['bkSecDetails', $('bookLastName'), !last ? 'Please enter your last name.' : nameOk(last) ? '' : 'Please enter your last name using letters, spaces, apostrophes, periods or hyphens.'],
      ['bkSecDetails', $('bookPhone'), BookingFormValidation.mobile(phone) ? '' : BookingFormValidation.MOBILE_MESSAGE],
      ['bkSecDetails', $('bookEmail'), !email || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? '' : 'Please enter a valid email address, or leave it blank.'],
      ['bkSecDetails', $('bookAgreeTerms').closest('label'), $('bookAgreeTerms').checked ? '' : 'Please agree to the Terms & Conditions.'],
      ['bkSecBranch', cards, select.value ? '' : 'Please choose a branch.'],
      ['bkSecServices', $('bookServiceList'), !select.value ? '' : checkedServices().length ? '' : 'Please choose at least one service.'],
      ['bookScheduleSection', $('bookDate'), date ? '' : 'Please select a date.'],
      ['bookScheduleSection', $('bookSlotGrid'), !date || selectedSlotLabel ? '' : 'Please choose an available time.'],
      ['bkSecStaff', staffShown ? $('bookStaffList') : $('bkStaffPending'),
        selectedStaffId ? '' : staffShown ? 'Please select an available stylist for each service.' : 'Pick a date and time to see available stylists.'],
      ['bkSecPayment', gateShown ? (bookReservationQuote ? $('bookDepositMethod') : $('bookQuoteError')) : $('bkPaymentPending'),
        !selectedStaffId ? '' // the stylist message already explains why payment is waiting
          : !bookReservationQuote ? ($('bookQuoteError').textContent || 'Please wait while we calculate your payment.')
          : noOnlinePayment() || paymentMethod() ? '' : 'Please choose a payment method.']
    ].map(p => [...p, stepOf($(p[0]))]);
  }

  // Section ticks: a section is complete when none of its own checks fail
  // and its inputs actually have values (Branch blank → services not "done").
  const filled = {
    bkSecBranch: () => !!select.value,
    bkSecServices: () => checkedServices().length > 0,
    bookScheduleSection: () => !!($('bookDate').value && selectedSlotLabel),
    bkSecStaff: () => !!selectedStaffId,
    bkSecPayment: () => !!bookReservationQuote && !!selectedStaffId,
    bkSecDetails: () => true
  };
  function renderTicks(list) {
    Object.keys(filled).forEach(id => {
      const ok = filled[id]() && !list.some(p => p[0] === id && p[2]);
      $(id)?.classList.toggle('is-complete', ok);
    });
  }

  // Screens 2-3: each part appears once the one before is chosen.
  // A part that just appeared scrolls into view, so the guest doesn't hunt for it.
  function renderReveal() {
    [['bkSecServices', !select.value],
     ['bookScheduleSection', !checkedServices().length],
     ['bkSecStaff', !($('bookDate').value && selectedSlotLabel)]].forEach(([id, later]) => {
      const sec = $(id);
      const appeared = sec.classList.contains('bk-later') && !later;
      sec.classList.toggle('bk-later', later);
      if (appeared && revealScroll && stepped() && (current === 'services' || current === 'schedule')) {
        scrollToEl(sec);
      }
    });
  }
  let revealScroll = false; // off while a screen is being (re)built

  /* ---------- validation messages beside each field ---------- */
  const shown = new Set();
  function renderErrors(list) {
    // Only screens the guest tried to leave (or the final submit) show messages.
    list = list.map(p => attempted.has(p[3]) ? p : [p[0], p[1], '', p[3]]);
    const bad = new Set();
    list.forEach(([sec, target, msg]) => {
      if (!target) return;
      if (msg) {
        BookingFormValidation.message(target, msg);
        if (target.matches('input, select, textarea')) target.setAttribute('aria-invalid', 'true');
        shown.add(target);
        bad.add(sec);
      } else if (shown.has(target)) {
        BookingFormValidation.message(target, '');
        if (target.matches('input, select, textarea')) target.removeAttribute('aria-invalid');
        shown.delete(target);
      }
    });
    // Clear messages on targets that were swapped out (e.g. staff ↔ pending).
    shown.forEach(t => { if (!list.some(p => p[1] === t && p[2])) { BookingFormValidation.message(t, ''); shown.delete(t); } });
    document.querySelectorAll('#bookingModalForm .bk-sec').forEach(s => s.classList.toggle('has-error', bad.has(s.id)));
  }

  function focusProblem(p) {
    const t = p[1];
    scrollToEl(t, 'center');
    (t.matches('input, select, textarea, button') ? t : t.querySelector('input:not(.bk-visually-hidden), button:not([disabled])') || t).focus?.({ preventScroll: true });
  }

  // Runs before main.js's submit handler (window capture also runs before
  // booking-validation.js's document listener). Phones: Enter on screens 1-2
  // just moves on. The real submit only reaches main.js (→ review) when every
  // screen is valid; otherwise it opens the screen with the first problem.
  window.addEventListener('submit', e => {
    if (e.target !== form) return;
    if (stepped() && current !== 'payment' && !returnToReview) {
      e.preventDefault();
      e.stopImmediatePropagation();
      next();
      return;
    }
    STEPS.forEach(s => attempted.add(s));
    const list = problems();
    renderErrors(list);
    const first = list.find(p => p[2]);
    if (!first) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (stepped() && first[3] !== current) goto(first[3], false);
    focusProblem(first);
  }, true);

  /* ---------- placeholders for parts main.js reveals later ---------- */
  function syncPending() {
    $('bkStaffPending').classList.toggle('hidden', visible($('bookStaffSection')));
    $('bkPaymentPending').classList.toggle('hidden', visible($('bookDepositGate')));
  }

  /* ---------- live booking summary (bar total; the aside stays hidden) ---------- */
  const row = (label, value, empty) =>
    '<div class="bk-sum-row"><dt>' + label + '</dt><dd' + (value ? '' : ' class="is-empty"') + '>' + (value || esc(empty)) + '</dd></div>';

  function renderSummary() {
    const picked = checkedServices();
    const opt = select.selectedOptions[0];
    const branch = select.value && opt ? esc(opt.textContent.split(/\s+[–-]\s+/)[0]) : '';
    const minutes = picked.reduce((t, c) => t + Number(c.dataset.minutes || 30), 0);
    const date = $('bookDate').value;
    const services = picked.length
      ? '<ul class="bk-sum-services">' + picked.map(c => '<li><span>' + esc(c.dataset.name) + '</span><span>₱' + Number(c.dataset.price || 0).toLocaleString('en-PH') + '</span></li>').join('') + '</ul>'
      : '';
    const end = date && selectedSlotLabel && minutes ? ServiceDuration.endTime(date, selectedSlotLabel, minutes) : '';
    const when = date ? esc(fmtApptDate(date)) + (selectedSlotLabel ? '<br>' + esc(selectedSlotLabel) + (end ? ' – ' + esc(end) : '') : '') : '';
    const q = bookReservationQuote;
    const plan = document.querySelector('input[name="bookPaymentPlan"]:checked')?.value;
    const method = paymentMethod();
    const pay = q && noOnlinePayment() ? 'No online payment needed'
      : method ? esc(PAY_METHOD_NAMES[method] || method) + (q && q.canChoosePlan ? ' · ' + (plan === 'full' ? 'Full amount' : 'Deposit') : '') : '';
    const guest = [$('bookName').value.trim(), $('bookPhone').value.trim()].filter(Boolean).map(esc).join('<br>');

    let html = '<dl class="bk-sum-list">' +
      row('Guest', guest, 'Not entered') +
      row('Branch', branch, 'Not selected') +
      row('Services', services, 'None yet') +
      (picked.length ? row('Duration', esc(ServiceDuration.format(minutes)), '') : '') +
      row('When', when, 'Not selected') +
      row('Stylist', selectedStaffName ? esc(selectedStaffName) : '', 'Not selected') +
      row('Payment', pay, 'Not selected') +
      '</dl>';
    if (picked.length) {
      const total = q ? q.serviceTotal : picked.reduce((t, c) => t + Number(c.dataset.price || 0), 0);
      html += '<dl class="bk-sum-money"><div><dt>Service total</dt><dd>' + peso(total) + '</dd></div>' +
        (q ? '<div class="is-paynow"><dt>Pay now</dt><dd>' + peso(q.amountDue) + '</dd></div>' +
             '<div><dt>Balance later</dt><dd>' + peso(q.remainingBalance) + '</dd></div>'
           : '') + '</dl>' +
        (q ? '<p class="bk-sum-hint">' + esc(q.reservationRequirement) + '</p>'
           : '<p class="bk-sum-hint">' + esc($('bookQuoteError').textContent || 'Calculating payment…') + '</p>');
    }
    $('bkSummary').innerHTML = html;

    $('bkMobileTotal').innerHTML = picked.length
      ? (q ? 'Pay now <b>' + peso(q.amountDue) + '</b>' : picked.length + ' service' + (picked.length > 1 ? 's' : '') + '<b>' + peso(picked.reduce((t, c) => t + Number(c.dataset.price || 0), 0)) + '</b>')
      : '<span>No services yet</span>';
  }

  /* ---------- keep everything in sync with main.js ---------- */
  let queued = false;
  function refresh() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
      queued = false;
      layout();
      syncPending();
      renderReveal();
      const list = problems();
      renderTicks(list);
      if (attempted.size) renderErrors(list);
      renderSummary();
    });
  }
  ['booking:services-loaded', 'booking:staff-changed'].forEach(ev => document.addEventListener(ev, refresh));
  ['change', 'input', 'click'].forEach(ev => form.addEventListener(ev, e => {
    if (e.isTrusted) revealScroll = true;
    setTimeout(refresh, 0);
  }));
  // main.js toggles sections/panels and writes the quote and slot/staff boxes;
  // observe those instead of editing each call site. Panel switches re-layout
  // at once so a screen never paints in the wrong layout.
  const panelObserver = new MutationObserver(() => { layout(); refresh(); });
  ['bookingAccessPanel', 'bookingFormPanel', 'bookingReviewPanel', 'bookingOtpPanel', 'bookingConfirmPanel']
    .forEach(id => $(id) && panelObserver.observe($(id), { attributes: true, attributeFilter: ['class'] }));
  const observer = new MutationObserver(refresh);
  ['bookStaffSection', 'bookDepositGate'].forEach(id => $(id) && observer.observe($(id), { attributes: true, attributeFilter: ['class'] }));
  ['bookDepositRequired', 'bookQuoteError', 'bookSlotGrid', 'bookStaffList']
    .forEach(id => $(id) && observer.observe($(id), { childList: true, characterData: true, subtree: true }));

  /* ---------- review screen: Edit returns to the form ---------- */
  // main.js's own handler (registered first) swaps the panels back; then pick the screen.
  $('bookReviewEdit').addEventListener('click', () => { returnToReview = true; goto('details'); });
  reviewPanel.addEventListener('click', e => {
    const btn = e.target.closest('[data-bk-goto]');
    if (!btn || $('bookReviewEdit').disabled) return;
    $('bookReviewEdit').click();
    goto(btn.dataset.bkGoto);
  });
  // "Edit" on the code screen: the contact details are on screen 1.
  $('bookOtpEditBtn')?.addEventListener('click', () => { returnToReview = true; goto('details'); });

  function reset() {
    current = 'details';
    returnToReview = false;
    revealScroll = false;
    branchListOpen = false;
    attempted.clear();
    renderErrors([]);
    renderBranchCards();
    layout();
    scroller.scrollTop = 0;
    refresh();
  }

  /* ---------- entry points ---------- */
  const origOpen = window.openBookingModal;
  window.openBookingModal = function () {
    const r = origOpen.apply(this, arguments);
    reset();
    return r;
  };
  const origGuest = window.continueAsGuestBooking;
  window.continueAsGuestBooking = function () {
    const r = origGuest.apply(this, arguments);
    // "Book at <branch>" on the Branches tab pre-selects that branch.
    if (window.__preBookBranch && !select.disabled && select.querySelector('option[value="' + window.__preBookBranch + '"]')) {
      select.value = window.__preBookBranch;
      select.dispatchEvent(new Event('change'));
    }
    window.__preBookBranch = '';
    // A fresh guest form: no messages from an earlier attempt.
    attempted.clear();
    renderErrors([]);
    returnToReview = false;
    renderBranchCards();
    goto('details');
    return r;
  };

  // main.js hooks for server-side errors: open the screen holding a field.
  window.BookingWizard = {
    goto: step => goto(step, false),
    showStepFor: el => { const s = stepOf(el); if (s) goto(s, false); }
  };

  reset();
})();
