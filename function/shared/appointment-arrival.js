/* Arrival review uses the existing staff, scheduling and rescheduling APIs.
   A review alone never cancels a booking or changes a payment. */
(() => {
  const backend = new URL('../../', document.currentScript.src);
  let active = false;
  async function request(path, data, method = 'POST') {
    const params = new URLSearchParams(data);
    const url = new URL(path, backend);
    if (method === 'GET') url.search = params;
    const response = await fetch(url, { method, credentials: 'same-origin', ...(method === 'POST' ? { body: params } : {}) });
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
      dialog.className = 'rounded-2xl border border-slate-300 bg-white text-slate-800 p-5 shadow-2xl';
      dialog.style.cssText = 'width:min(32rem,calc(100% - 2rem));max-height:90dvh;overflow:auto';
      dialog.setAttribute('aria-labelledby', 'arrivalReviewTitle');
      dialog.innerHTML = `<form method="dialog" class="space-y-4">
        <h2 id="arrivalReviewTitle" class="text-xl font-bold">Review arrival</h2>
        <p data-arrival-summary class="text-sm whitespace-pre-line"></p>
        <p data-arrival-help class="text-sm">The full service and cleaning buffer must fit. Another customer's appointment will not be moved. Payments stay unchanged. Selecting another stylist replaces only the current main stylist's services.</p>
        <label class="block text-sm font-semibold">Staff for this arrival<select data-arrival-staff class="block w-full rounded-lg border p-3 mt-1"></select></label>
        <button type="button" data-arrival-start class="w-full rounded-lg bg-[#006D6F] text-white p-3 font-bold disabled:opacity-50">Confirm arrival and start service</button>
        <details><summary class="cursor-pointer py-2 font-semibold">Choose another schedule</summary>
          <label class="block mt-2">Date<input data-arrival-date type="date" class="block border rounded-lg w-full p-3 mt-1"></label>
          <button type="button" data-arrival-load class="w-full border rounded-lg p-3 mt-2">Find available times</button>
          <label class="block mt-2">Available time<select data-arrival-time class="block border rounded-lg p-3 w-full mt-1"></select></label>
          <button type="button" data-arrival-reschedule disabled class="w-full rounded-lg bg-[#006D6F] text-white p-3 mt-2 disabled:opacity-50">Confirm reschedule</button>
        </details>
        <p data-arrival-error role="status" aria-live="polite" class="text-sm text-rose-700"></p>
        <button value="close" class="w-full rounded-lg border p-3">Close without changes</button>
      </form>`;
      const find = name => dialog.querySelector(`[data-arrival-${name}]`);
      const scheduled = review.scheduledStart;
      if (review.canReschedule === false) {
        dialog.querySelector('details').hidden = true;
        find('help').textContent = 'The full service and cleaning buffer must fit. Other appointments and payments stay unchanged. If the service cannot fit, ask the cashier to review reassignment or rescheduling.';
      }
      const grace = review.graceMinutes === null ? 'Grace period is unset. No automatic lateness penalty applies.'
        : `Configured grace period: ${review.graceMinutes} minutes. ${review.minutesLate > review.graceMinutes ? 'Arrival is beyond it; staff review is required.' : 'Arrival is within it.'} No automatic payment action applies.`;
      const late = review.isLate ? (review.minutesLate ? review.minutesLate + ' minutes' : 'less than one minute') + ' after reserved start' : 'on time or early';
      find('summary').textContent = `${reference}\nReserved: ${scheduled}\nActual service start: ${review.actualStart} (${review.durationMinutes} minutes)\nArrival: ${late}\nPayment: ${review.paymentStatus}\n${grace}\n${review.reason || 'Review qualified staff below.'}`;
      for (const staff of review.staff) {
        const option = document.createElement('option');
        option.value = staff.id;
        option.textContent = staff.name + (staff.available ? ' - Available' : ' - Unavailable');
        option.disabled = !staff.available;
        find('staff').append(option);
      }
      const free = review.staff.find(staff => staff.available);
      if (!free && !review.reason) find('summary').textContent += '\nNo qualified staff can fit this arrival. Choose another schedule or ask the cashier for assistance.';
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
        if (!window.confirm('Start the full service at the current server time with the selected staff? Availability will be checked again and payment will remain unchanged.')) { find('error').textContent = ''; return; }
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
        if (!window.confirm('Move only this appointment to the selected schedule? Existing verified payments will carry over.')) return;
        await request('backend/cashier/rescheduleAppointment.php', { id: reference, date: find('date').value, time: find('time').value, reason: 'Staff-reviewed arrival; rescheduled without displacing another booking.' });
        changed = true; dialog.close();
      });
      document.body.append(dialog);
      dialog.showModal();
      return new Promise(resolve => dialog.addEventListener('close', () => { active = false; dialog.remove(); resolve(changed); }, { once: true }));
    }
  };
})();
