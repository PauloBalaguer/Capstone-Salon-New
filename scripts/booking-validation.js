/* Shared only by booking pages. Keeps errors next to the existing controls. */
window.BookingFormValidation = (() => {
  let errorSequence = 0;
  function check(input, kind, required = true) {
    if (!input || input.disabled || input.readOnly) return true;
    const value = String(input.value ?? '').trim();
    let message = '';
    if (!value) message = required ? 'Please complete this field.' : '';
    else if (kind === 'mobile' && !/^09\d{9}$/.test(value)) message = 'Please enter a valid mobile number (09XXXXXXXXX).';
    else if (kind === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) message = 'Please enter a valid email address.';
    else if (kind === 'quantity' && (!/^[1-9]\d*$/.test(value) || Number(value) > 2147483647)) message = 'Please enter a whole number of clients (at least 1).';
    else if (kind === 'money' && (!/^\d+(\.\d{1,2})?$/.test(value) || Number(value) > 99999999.99)) message = 'Please enter a non-negative amount with at most two decimal places.';
    else if (kind === 'address' && (value.length < 5 || value.length > 500)) message = 'Please enter the full service address (5 to 500 characters).';
    else if (kind === 'text' && value.length > 255) message = 'Please use at most 255 characters.';
    else if (kind === 'date' && (!/^\d{4}-\d{2}-\d{2}$/.test(value) || !Number.isFinite(Date.parse(value + 'T00:00:00Z')) || new Date(value + 'T00:00:00Z').toISOString().slice(0, 10) !== value)) message = 'Please select a valid appointment date.';
    else if (kind === 'time' && !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value)) message = 'Please select a valid appointment time.';
    if (!message && value && ['money', 'quantity'].includes(kind) && input.min !== '' && Number(value) < Number(input.min)) message = 'Please enter an amount of at least ' + input.min + '.';
    input.setCustomValidity(message);
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    input.dataset.bookingValidated = 'true';
    let error = input.nextElementSibling;
    if (!error?.classList.contains('booking-field-error')) {
      error = document.createElement('p');
      error.className = 'booking-field-error';
      error.id = 'booking-field-error-' + (++errorSequence);
      error.setAttribute('aria-live', 'polite');
      input.insertAdjacentElement('afterend', error);
      input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
    }
    error.textContent = message;
    error.hidden = !message;
    return !message;
  }
  function fields(spec, root = document) {
    let first;
    for (const [selector, kind, required = true] of spec) {
      const input = root.querySelector(selector);
      if (!check(input, kind, required) && !first) first = input;
    }
    if (first) first.focus();
    return !first;
  }
  function identify(input) {
    const name = [input.id, input.name, input.getAttribute('x-model'), input.getAttribute('x-model.number')].filter(Boolean).join(' ');
    if (/customerPhone|clientPhone|walkInPhone|walkinPhone|bookPhone|hsPhone/i.test(name)) return 'mobile';
    if (/hsEmail|bookEmail/.test(name)) return 'email';
    if (/homeServiceForm.clients|hsClients/.test(name)) return 'quantity';
    if (/homeServiceForm.address|hsVenue$/.test(name)) return 'address';
    if (/depositAmount|cashReceived|tipAmount|customTipInput|verifyAmountInput|quotePrice|reservationFee/i.test(name)) return 'money';
    return null;
  }
  document.addEventListener('focusout', event => {
    if (!(event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement)) return;
    const kind = identify(event.target);
    if (kind) check(event.target, kind, kind === 'money' ? event.target.required : event.target.id !== 'bookEmail');
  });
  document.addEventListener('input', event => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement)) return;
    const kind = identify(input);
    if (kind && input.dataset.bookingValidated) check(input, kind, kind === 'money' ? input.required : input.id !== 'bookEmail');
  });
  document.addEventListener('keydown', event => {
    if (event.target instanceof HTMLInputElement && ['quantity', 'money'].includes(identify(event.target)) && ['e', 'E', '+', '-'].includes(event.key)) event.preventDefault();
  });
  document.addEventListener('submit', event => {
    let first;
    for (const input of event.target.querySelectorAll('input, textarea')) {
      const kind = identify(input);
      if (kind && input.getClientRects().length && !check(input, kind, input.required) && !first) first = input;
    }
    if (first) { event.preventDefault(); event.stopImmediatePropagation(); first.focus(); }
  }, true);
  document.addEventListener('DOMContentLoaded', () => {
    for (const input of document.querySelectorAll('input, textarea')) {
      const kind = identify(input);
      if (!kind) continue;
      input.dataset.bookingValidated = 'true';
      if (kind === 'mobile') { input.inputMode = 'numeric'; input.maxLength = 11; input.placeholder = '09XXXXXXXXX'; }
      if (kind === 'quantity') { input.inputMode = 'numeric'; input.min = '1'; input.step = '1'; }
      if (kind === 'money') { input.inputMode = 'decimal'; input.step = '0.01'; }
    }
  });
  const style = document.createElement('style');
  style.textContent = '.booking-field-error{color:#9f1239;background:#fff1f2;padding:.35rem .5rem;border-radius:.375rem;font-size:.8125rem;line-height:1.5;margin:.35rem 0 .65rem}[data-booking-validated]:disabled{opacity:.6;cursor:not-allowed}[data-booking-validated]:read-only{opacity:.85}[data-booking-validated][aria-invalid="true"]{outline:2px solid #be123c;outline-offset:1px}';
  document.head.appendChild(style);
  return { check, fields };
})();
