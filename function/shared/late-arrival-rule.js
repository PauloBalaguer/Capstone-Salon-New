/* Shows the configured late-arrival rule (backend/public/getBookingPolicy.php,
   from SALON_LATE_GRACE_MINUTES) in [data-late-rule] elements. They stay
   hidden while no grace period is set. The text goes into the element's
   [data-late-rule-text] child, or the element itself.
   Also fills [data-policy="lateGraceMinutes" | "holdMinutes"] with the
   configured numbers (PayMongo::HOLD_MINUTES), and hides
   [data-policy-needs="lateGraceMinutes"] while no grace period is set. */
window.LateArrivalRule = (() => {
  const backend = new URL('../../', document.currentScript.src);
  let policy = null;
  const load = () => policy || (policy = fetch(new URL('backend/public/getBookingPolicy.php', backend))
    .then(response => response.json())
    .catch(() => ({})));
  return {
    async show(elements = document.querySelectorAll('[data-late-rule]')) {
      const data = await load();
      const text = data.lateArrivalRule || null;
      (elements instanceof Element ? [elements] : elements).forEach(el => {
        el.classList.toggle('hidden', !text);
        if (text) (el.querySelector('[data-late-rule-text]') || el).textContent = text;
      });
      document.querySelectorAll('[data-policy]').forEach(el => {
        const value = data[el.dataset.policy];
        if (value !== null && value !== undefined) el.textContent = value;
      });
      document.querySelectorAll('[data-policy-needs]').forEach(el => {
        if (data.success) el.classList.toggle('hidden', data[el.dataset.policyNeeds] === null || data[el.dataset.policyNeeds] === undefined);
      });
    }
  };
})();
