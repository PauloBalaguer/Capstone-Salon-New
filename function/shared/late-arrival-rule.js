/* Shows the configured late-arrival rule (backend/public/getBookingPolicy.php,
   from SALON_LATE_GRACE_MINUTES) in [data-late-rule] elements. They stay
   hidden while no grace period is set. The text goes into the element's
   [data-late-rule-text] child, or the element itself. */
window.LateArrivalRule = (() => {
  const backend = new URL('../../', document.currentScript.src);
  let rule = null;
  const load = () => rule || (rule = fetch(new URL('backend/public/getBookingPolicy.php', backend))
    .then(response => response.json())
    .then(data => data.lateArrivalRule || null)
    .catch(() => null));
  return {
    async show(elements = document.querySelectorAll('[data-late-rule]')) {
      const text = await load();
      (elements instanceof Element ? [elements] : elements).forEach(el => {
        el.classList.toggle('hidden', !text);
        if (text) (el.querySelector('[data-late-rule-text]') || el).textContent = text;
      });
    }
  };
})();
