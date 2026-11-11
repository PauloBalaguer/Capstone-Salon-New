/* Guest booking: single-page layout controller.
   Every section (branch, services, date & time, stylist, payment, details) is
   shown at once; this script only adds presentation on top of main.js:
   branch cards, section "done" ticks, the live booking summary, waitlist
   stylist chips and all-at-once validation messages. It reads state kept by
   main.js (selectedSlotLabel, selectedStaffName, bookReservationQuote, ...);
   every API call, price calculation, PayMongo hand-off, the review → OTP →
   confirmation flow and the final submit stay in main.js. */
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
  const select = $('bookBranchSelect');

  /* ---------- branch cards (built from the existing <select>) ---------- */
  const cards = $('bkBranchCards');
  function renderBranchCards() {
    cards.innerHTML = Array.from(select.options).filter(o => o.value).map(o => {
      const [name, ...rest] = o.textContent.split(/\s+[–-]\s+/);
      const on = select.value === o.value;
      return '<button type="button" role="radio" aria-checked="' + on + '" class="bk-branch-card' + (on ? ' is-selected' : '') + '" data-branch="' + esc(o.value) + '"' + (select.disabled && !on ? ' disabled' : '') + '>' +
        '<span class="bk-branch-name">' + esc(name) + '</span>' + (rest.length ? '<span class="bk-branch-meta">' + esc(rest.join(' – ')) + '</span>' : '') +
        '<span class="bk-card-check" aria-hidden="true">✓</span></button>';
    }).join('');
  }
  cards.addEventListener('click', e => {
    const card = e.target.closest('.bk-branch-card');
    if (!card || card.disabled || select.disabled || select.value === card.dataset.branch) return;
    select.value = card.dataset.branch;
    select.dispatchEvent(new Event('change'));
    renderBranchCards();
  });
  select.addEventListener('change', renderBranchCards);

  /* ---------- waitlist: stylist chips instead of the dropdown ---------- */
  // main.js fills #bookWaitlistStaff and reads its value; the chips just set it.
  const waitSelect = $('bookWaitlistStaff');
  let waitChips = null;
  if (waitSelect) {
    waitSelect.classList.add('bk-visually-hidden');
    waitSelect.tabIndex = -1;
    waitChips = document.createElement('div');
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

  // Each entry: section, message target, and the problem (or '' when fine).
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
      ['bkSecBranch', cards, select.value ? '' : 'Please choose a branch.'],
      ['bkSecServices', $('bookServiceList'), !select.value ? '' : checkedServices().length ? '' : 'Please choose at least one service.'],
      ['bookScheduleSection', $('bookDate'), date ? '' : 'Please select a date.'],
      ['bookScheduleSection', $('bookSlotGrid'), !date || selectedSlotLabel ? '' : 'Please choose an available time.'],
      ['bkSecStaff', staffShown ? $('bookStaffList') : $('bkStaffPending'),
        selectedStaffId ? '' : staffShown ? 'Please select an available stylist for each service.' : 'Pick a date and time to see available stylists.'],
      ['bkSecPayment', gateShown ? (bookReservationQuote ? $('bookDepositMethod') : $('bookQuoteError')) : $('bkPaymentPending'),
        !selectedStaffId ? '' // the stylist message already explains why payment is waiting
          : !bookReservationQuote ? ($('bookQuoteError').textContent || 'Please wait while we calculate your payment.')
          : noOnlinePayment() || paymentMethod() ? '' : 'Please choose a payment method.'],
      ['bkSecDetails', $('bookFirstName'), !first ? 'Please enter your first name.' : nameOk(first) ? '' : 'Please enter your first name using letters, spaces, apostrophes, periods or hyphens.'],
      ['bkSecDetails', $('bookMiddleName'), !middle || nameOk(middle) ? '' : 'Please enter your middle name using letters, spaces, apostrophes, periods or hyphens, or tick N/A.'],
      ['bkSecDetails', $('bookLastName'), !last ? 'Please enter your last name.' : nameOk(last) ? '' : 'Please enter your last name using letters, spaces, apostrophes, periods or hyphens.'],
      ['bkSecDetails', $('bookPhone'), /^09\d{9}$/.test(phone) ? '' : 'Please enter a valid 11-digit mobile number (09XXXXXXXXX).'],
      ['bkSecDetails', $('bookEmail'), !email || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? '' : 'Please enter a valid email address, or leave it blank.'],
      ['bkSecDetails', $('bookAgreeTerms').closest('label'), $('bookAgreeTerms').checked ? '' : 'Please agree to the Terms & Conditions.']
    ];
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

  /* ---------- validation messages beside each field ---------- */
  let attempted = false; // only show messages after the first submit attempt
  const shown = new Set();
  function renderErrors(list) {
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

  // Runs before main.js's submit handler: show every problem at once and only
  // let the submit through (to main.js's own validation + review) when clean.
  // Window capture runs before booking-validation.js's document listener, so
  // every section's message shows together instead of only the first.
  window.addEventListener('submit', e => {
    if (e.target !== form) return;
    attempted = true;
    const list = problems();
    renderErrors(list);
    const first = list.find(p => p[2]);
    if (!first) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const t = first[1];
    t.scrollIntoView({ behavior: 'smooth', block: 'center' });
    (t.matches('input, select, textarea, button') ? t : t.querySelector('input:not(.bk-visually-hidden), button:not([disabled])') || t).focus?.({ preventScroll: true });
  }, true);

  /* ---------- placeholders for parts main.js reveals later ---------- */
  function syncPending() {
    $('bkStaffPending').classList.toggle('hidden', visible($('bookStaffSection')));
    $('bkPaymentPending').classList.toggle('hidden', visible($('bookDepositGate')));
  }

  /* ---------- live booking summary ---------- */
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
      row('Branch', branch, 'Not selected') +
      row('Services', services, 'None yet') +
      (picked.length ? row('Duration', esc(ServiceDuration.format(minutes)), '') : '') +
      row('When', when, 'Not selected') +
      row('Stylist', selectedStaffName ? esc(selectedStaffName) : '', 'Not selected') +
      row('Payment', pay, 'Not selected') +
      row('Guest', guest, 'Not entered') +
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
      box.classList.toggle('bk-is-form', visible(formPanel));
      syncPending();
      const list = problems();
      renderTicks(list);
      if (attempted) renderErrors(list);
      renderSummary();
    });
  }
  ['booking:services-loaded', 'booking:staff-changed'].forEach(ev => document.addEventListener(ev, refresh));
  ['change', 'input', 'click'].forEach(ev => form.addEventListener(ev, () => setTimeout(refresh, 0)));
  // main.js toggles sections/panels and writes the quote and slot/staff boxes;
  // observe those instead of editing each call site.
  const observer = new MutationObserver(refresh);
  ['bookStaffSection', 'bookDepositGate', 'bookingAccessPanel', 'bookingFormPanel', 'bookingReviewPanel', 'bookingOtpPanel', 'bookingConfirmPanel']
    .forEach(id => $(id) && observer.observe($(id), { attributes: true, attributeFilter: ['class'] }));
  ['bookDepositRequired', 'bookQuoteError', 'bookSlotGrid', 'bookStaffList']
    .forEach(id => $(id) && observer.observe($(id), { childList: true, characterData: true, subtree: true }));

  function reset() {
    attempted = false;
    renderErrors([]);
    renderBranchCards();
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
    renderBranchCards();
    refresh();
    return r;
  };

  reset();
})();
