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
        <div data-arrival-grace role="status" class="rounded-lg border p-3 text-sm"></div>
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
      // Grace period verdict (Scheduling::arrivalReview graceStatus). Information
      // only: lateness never cancels, marks a no-show or changes payment by itself.
      const late = review.isLate ? (review.minutesLate ? review.minutesLate + ' minutes' : 'less than one minute') + ' after reserved start' : 'on time or early';
      // Fixed colours: each portal re-themes Tailwind's palette differently (admin's slate-100 is charcoal).
      const green = 'background:#EDF8F1;border-color:#B7DFC6;color:#17603A', amber = 'background:#FFF7E2;border-color:#D9BB69;color:#7A5200';
      const verdicts = {
        on_time: ['On time', 'The customer arrived at or before the reserved start.', green],
        within: ['Within the grace period', `Arrived ${late} (grace period: ${review.graceMinutes} minutes).`, green],
        beyond: ['Beyond the grace period', `Arrived ${late}: ${review.minutesLate - review.graceMinutes} minutes past the ${review.graceMinutes}-minute grace period.`, amber],
        unset: ['Late (no grace period set)', `Arrived ${late}. SALON_LATE_GRACE_MINUTES is not configured.`, 'background:#F1F5F9;border-color:#CBD5E1;color:#1E293B']
      };
      const [verdictTitle, verdictText, verdictTone] = verdicts[review.graceStatus] || verdicts.unset;
      find('grace').style.cssText = verdictTone;
      find('grace').innerHTML = '<b class="block text-base"></b><span></span><span class="block mt-1">Nothing is cancelled or marked as a no-show automatically. Starting, reassigning, rescheduling or marking a no-show is your decision.</span>';
      find('grace').querySelector('b').textContent = verdictTitle;
      find('grace').querySelector('span').textContent = verdictText;
      find('summary').textContent = `${reference}\nReserved: ${scheduled}\nActual service start: ${review.actualStart} (${review.durationMinutes} minutes)\nArrival: ${late}\nPayment: ${review.paymentStatus} (unchanged by this review)\n${review.reason || 'Review qualified staff below.'}`;
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
