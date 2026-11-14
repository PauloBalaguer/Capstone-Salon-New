/* Arrival review uses the existing staff, scheduling and rescheduling APIs.
   A review alone never cancels a booking or changes a payment. */
(() => {
  const backend = new URL('../../', document.currentScript.src);
  let active = false;
  const arrivalStyles = document.createElement('style');
  arrivalStyles.textContent = `
    dialog.arrival-review { box-sizing: border-box; width: min(32rem, calc(100% - 24px)); max-width: none; max-height: calc(100dvh - 32px); margin: auto; padding: 0; overflow: hidden; border: 1px solid var(--border-strong); border-radius: 18px; background: var(--card); color: var(--text); }
    dialog.arrival-review::backdrop { background: rgba(10, 46, 36, .55); }
    dialog.arrival-review > form { display: flex; flex-direction: column; max-height: calc(100dvh - 34px); margin: 0; }
    .arrival-review-header, .arrival-review-footer { flex: none; padding: 16px 20px; }
    .arrival-review-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); }
    .arrival-review-header h2 { margin: 0; }
    .arrival-review [hidden] { display: none !important; }
    .arrival-review-header .ui-close-icon { display: grid; place-items: center; flex: none; width: 44px; height: 44px; border: 1px solid var(--border); border-radius: 10px; background: var(--card); color: var(--text); cursor: pointer; }
    .arrival-review-content select, .arrival-review-content input { min-width: 0; max-width: 100%; font-size: 16px; }
    .arrival-review-content { min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 16px 20px; scrollbar-gutter: stable; overflow-wrap: anywhere; }
    .arrival-review-footer { border-top: 1px solid var(--border); padding-bottom: max(16px, env(safe-area-inset-bottom)); }
    .arrival-review button:disabled { opacity: .45 !important; cursor: not-allowed; }
    @media (max-width: 767px) {
      dialog.arrival-review { max-height: calc(100dvh - 24px); }
      dialog.arrival-review > form { max-height: calc(100dvh - 26px); }
      .arrival-review-header, .arrival-review-content, .arrival-review-footer { padding-left: 16px; padding-right: 16px; }
    }
  `;
  document.head.append(arrivalStyles);
  async function request(path, data, method = 'POST') {
    const params = new URLSearchParams(data);
    const url = new URL(path, backend);
    if (method === 'GET') url.search = params;
    // Portals that use CSRF tokens (the Staff Portal) set window.LMCsrfToken.
    const headers = window.LMCsrfToken ? { 'X-CSRF-Token': window.LMCsrfToken } : {};
    const response = await fetch(url, { method, credentials: 'same-origin', headers, ...(method === 'POST' ? { body: params } : {}) });
    const result = await response.json();
    if (!result.success) throw new Error(result.message || 'The booking changed. Review availability again.');
    return result;
  }
  window.AppointmentArrival = {
    async review(reference, endpoint) {
      if (active) return false;
      active = true;
      let result;
      try { result = await request(endpoint, { id: reference, status: 'In Progress', arrivalReview: '1' }); }
      catch (error) { active = false; LMToast.show(error.message, 'error'); return false; }
      const review = result.review;
      const dialog = document.createElement('dialog');
      dialog.className = 'arrival-review shadow-2xl';
      dialog.setAttribute('aria-labelledby', 'arrivalReviewTitle');
      dialog.innerHTML = `<form method="dialog">
        <header class="arrival-review-header">
        <h2 id="arrivalReviewTitle" class="text-xl font-bold">Customer check-in</h2>
        <button type="submit" value="close" class="ui-close-icon" aria-label="Close">&times;</button>
        </header>
        <div class="arrival-review-content space-y-4">
        <div data-arrival-grace role="status" class="rounded-lg border p-3 text-sm"></div>
        <p data-arrival-summary class="text-sm whitespace-pre-line"></p>
        <p data-arrival-reason hidden class="rounded-lg border p-3 text-sm font-semibold" style="background:#FDECEC;border-color:#F3C2C2;color:#8A1F1F"></p>
        <p data-arrival-help class="text-sm">Choose an available staff member to start the service.</p>
        <label class="block text-sm font-semibold">Staff member<select data-arrival-staff class="block w-full rounded-lg border p-3 mt-1"></select></label>
        <button type="button" data-arrival-start class="w-full rounded-lg bg-[color:var(--brand)] text-white p-3 font-bold disabled:opacity-50">Start service</button>
        <details><summary class="cursor-pointer py-2 font-semibold">Reschedule booking</summary>
          <label class="block mt-2">Date<input data-arrival-date type="date" class="block border rounded-lg w-full p-3 mt-1"></label>
          <button type="button" data-arrival-load class="w-full border rounded-lg p-3 mt-2">Find available times</button>
          <label class="block mt-2">Available time<select data-arrival-time class="block border rounded-lg p-3 w-full mt-1"></select></label>
          <button type="button" data-arrival-reschedule disabled class="w-full rounded-lg bg-[color:var(--brand)] text-white p-3 mt-2 disabled:opacity-50">Confirm reschedule</button>
        </details>
        <p data-arrival-error role="status" aria-live="polite" class="text-sm text-[color:var(--danger)]"></p>
        </div>
        <footer class="arrival-review-footer"><button value="close" class="w-full rounded-lg border p-3">Close</button></footer>
      </form>`;
      const find = name => dialog.querySelector(`[data-arrival-${name}]`);
      const scheduled = review.scheduledStart;
      if (review.canReschedule === false) {
        dialog.querySelector('details').hidden = true;
        find('help').textContent = 'The full service must fit. If it can\'t, ask the cashier to reschedule.';
      }
      // Grace period verdict (Scheduling::arrivalReview graceStatus). Information
      // only: lateness never cancels, marks a no-show or changes payment by itself.
      // "6 hr 55 min", "45 min", "1 hr".
      const span = m => { m = Math.max(0, Math.round(Number(m) || 0)); const h = Math.floor(m / 60), r = m % 60; return h ? `${h} hr${r ? ` ${r} min` : ''}` : `${r} min`; };
      // "Nov 11, 2026, 1:00 PM" from "2026-11-11 13:00:00".
      const toDate = v => new Date(String(v || '').replace(' ', 'T'));
      const when = v => { const d = toDate(v); return isNaN(d) ? (v || '') : d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }); };
      const clock = d => isNaN(d) ? '' : d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
      const late = review.isLate ? (review.minutesLate ? span(review.minutesLate) + ' late' : 'less than a minute late') : 'on time or early';
      // Fixed colours: each portal re-themes Tailwind's palette differently (admin's slate-100 is charcoal).
      const green = 'background:#E6F4EC;border-color:#E6F4EC;color:#115C39', amber = 'background:#FAF2DC;border-color:#E0B84C;color:#6C4C09';
      const verdicts = {
        on_time: ['On time', 'Arrived on time.', green],
        within: ['Within the grace period', `Arrived ${late}.`, green],
        beyond: ['Past the grace period', `Arrived ${late} (grace period: ${review.graceMinutes} min).`, amber],
        unset: ['Late', `Arrived ${late}.`, 'background:#F6F8F7;border-color:#CDD6D0;color:#18261F']
      };
      const [verdictTitle, verdictText, verdictTone] = verdicts[review.graceStatus] || verdicts.unset;
      find('grace').style.cssText = verdictTone;
      find('grace').innerHTML = '<b class="block text-base"></b><span></span>';
      find('grace').querySelector('b').textContent = verdictTitle;
      find('grace').querySelector('span').textContent = verdictText;
      // Staff can't mark a no-show or reschedule; they ask the cashier from the booking card.
      find('grace').hidden = !!review.reason;
      const startAt = toDate(review.actualStart);
      const endAt = new Date(startAt.getTime() + (Number(review.durationMinutes) || 0) * 60000);
      // "Label: value" lines with the label in bold (values are escaped).
      const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
      find('summary').innerHTML = [
        ['Booking', reference],
        ['Appointment schedule', when(scheduled)],
        ['Service time if started now', `${clock(startAt)}${isNaN(endAt) ? '' : ` to ${clock(endAt)}`} (${span(review.durationMinutes)})`],
        ['Payment', window.PortalPresentation ? PortalPresentation.paymentStatus(review.paymentStatus) : review.paymentStatus],
      ].filter(([label]) => label !== 'Service time if started now' || (!review.reason && review.staff.some(staff => staff.available)))
        .map(([label, value]) => `<strong>${esc(label)}:</strong> ${esc(value)}`).join('\n');
      if (review.reason) {
        find('reason').hidden = false;
        const reasons = {
          'This booking is for another day.': `Check-in is only available on the appointment date: ${when(scheduled)}.`,
          'Too early. Check in at the reserved time.': `Check-in opens at ${when(scheduled)}.`,
          'It would end after closing time.': 'There is not enough time to finish this service before the branch closes.',
          'Confirm the booking before reviewing its arrival.': 'Confirm this booking before checking in the customer.'
        };
        find('reason').textContent = reasons[review.reason] || review.reason;
        find('help').textContent = review.canReschedule === false
          ? 'Contact the cashier if the appointment needs to be rescheduled.'
          : 'Need a different date or time? Select Reschedule booking below.';
        find('staff').closest('label').hidden = true;
        find('start').hidden = true;
      }
      for (const staff of review.staff) {
        const option = document.createElement('option');
        option.value = staff.id;
        option.textContent = staff.name + (staff.available ? ' - Available' : ' - Unavailable');
        option.disabled = !staff.available;
        find('staff').append(option);
      }
      const free = review.staff.find(staff => staff.available);
      if (!free) {
        // Every option is disabled, so the box would otherwise show blank.
        const none = new Option(review.staff.length ? 'No staff available for this arrival' : 'No qualified staff for this service', '', true, true);
        none.disabled = true;
        find('staff').prepend(none);
        find('staff').selectedIndex = 0;
      }
      if (!free && !review.reason) {
        find('reason').hidden = false;
        find('reason').textContent = review.canReschedule === false ? 'No staff are available now. Contact the cashier for help.' : 'No staff are available now. Choose another date or time below.';
        find('help').hidden = true;
      }
      if (free) find('staff').value = free.id;
      find('start').disabled = !free || !!review.reason;
      const localToday = new Date();
      const today = [localToday.getFullYear(),String(localToday.getMonth()+1).padStart(2,'0'),String(localToday.getDate()).padStart(2,'0')].join('-');
      find('date').min = today; find('date').value = today;
      let busy = false;
      dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
      let changed = false;
      const action = async fn => {
        if (busy) return;
        busy = true; find('error').textContent = 'Checking the schedule...';
        dialog.querySelectorAll('button, input, select').forEach(el => el.disabled = true);
        try { await fn(); }
        catch (error) { find('error').textContent = error.message; }
        finally {
          busy = false;
          dialog.querySelectorAll('button, input, select').forEach(el => el.disabled = false);
          find('start').disabled = !free || !!review.reason;
          find('reschedule').disabled = !find('time').value;
        }
      };
      find('start').onclick = () => action(async () => {
        if (!(await LMConfirm({ title: 'Start this service?', message: 'It starts now with the selected staff. Availability will be checked again, and payment stays the same.', confirmLabel: 'Start Service' }))) { find('error').textContent = ''; return; }
        await request(endpoint, { id: reference, status: 'In Progress', arrivalConfirmed: '1', arrivalStaffId: find('staff').value });
        changed = true; dialog.close();
      });
      find('date').onchange = () => { find('time').replaceChildren(); find('reschedule').disabled = true; };
      find('load').onclick = () => action(async () => {
        find('time').replaceChildren();
        const available = await request('backend/cashier/rescheduleAppointment.php', { id: reference, date: find('date').value }, 'GET');
        for (const slot of available.slots.filter(slot => slot.available)) {
          const option = document.createElement('option'); option.value = slot.time; option.textContent = slot.time; find('time').append(option);
        }
        find('error').textContent = find('time').value ? 'Choose a time, then confirm rescheduling.' : 'No available times. Choose another date.';
      });
      find('reschedule').onclick = () => action(async () => {
        if (!(await LMConfirm({ title: 'Move this appointment?', message: 'Only this appointment moves to the selected schedule. Verified payments carry over.', confirmLabel: 'Move Appointment' }))) return;
        await request('backend/cashier/rescheduleAppointment.php', { id: reference, date: find('date').value, time: find('time').value, reason: 'Staff-reviewed arrival; rescheduled without displacing another booking.' });
        changed = true; dialog.close();
      });
      document.body.append(dialog);
      dialog.showModal();
      return new Promise(resolve => dialog.addEventListener('close', () => { active = false; dialog.remove(); resolve(changed); }, { once: true }));
    }
  };
})();
