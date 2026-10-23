/* ==========================================================
   toast.js - one notification style for the whole system,
   matched to the login pages (cream card, serif heading, teal
   and gold accents). Every portal's own toast helper calls
   LMToast.show(message, kind, { duration, title }).

   kind: 'success' | 'error' | 'info'. The older names each
   portal used ('good', 'wrong', 'note', 'system') still work.
   Position: top-right, or bottom-right with <body data-toast="bottom">.
   The stack is a manual popover so it stays above open <dialog>s.
   ========================================================== */

const LMToast = (function () {
  const ALIAS = { good: 'success', wrong: 'error', note: 'info', system: 'info', warning: 'info' };

  // Full class names per kind (never built from pieces, so Tailwind finds them).
  const KIND = {
    success: {
      title: 'All set',
      badge: 'bg-[#006D6F]/10 text-[#006D6F]',
      bar: 'bg-[#006D6F]',
      icon: '<path d="M20 6 9 17l-5-5"/>'
    },
    error: {
      title: 'Something’s not right',
      badge: 'bg-[#A12B2B]/10 text-[#A12B2B]',
      bar: 'bg-[#A12B2B]',
      icon: '<path d="M12 8v5"/><path d="M12 16.5h.01"/><circle cx="12" cy="12" r="9"/>'
    },
    info: {
      title: 'Heads up',
      badge: 'bg-[#C9A24E]/15 text-[#805B0B]',
      bar: 'bg-[#C9A24E]',
      icon: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.5h.01"/>'
    }
  };

  const REGION = {
    top: 'pointer-events-none fixed inset-x-4 top-4 bottom-auto z-[9999] m-0 flex w-auto flex-col gap-2 overflow-visible border-0 bg-transparent p-0 sm:left-auto sm:right-4 sm:w-[360px]',
    bottom: 'pointer-events-none fixed inset-x-5 bottom-5 top-auto z-[9999] m-0 flex w-auto flex-col gap-2 overflow-visible border-0 bg-transparent p-0 sm:bottom-6 sm:left-auto sm:right-6 sm:w-[360px]'
  };

  const MAX_VISIBLE = 3;
  let region = null;

  function getRegion() {
    if (region) return region;
    region = document.createElement('div');
    region.className = document.body.dataset.toast === 'bottom' ? REGION.bottom : REGION.top;
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    if (typeof region.showPopover === 'function') region.setAttribute('popover', 'manual');
    document.body.appendChild(region);
    return region;
  }

  // Re-open the popover so a toast shown while a <dialog> is open sits above it.
  function raise(box) {
    if (!box.hasAttribute('popover')) return;
    if (box.matches(':popover-open')) box.hidePopover();
    box.showPopover();
  }

  function dismiss(card) {
    if (!card.isConnected || card.dataset.leaving) return;
    card.dataset.leaving = '1';
    card.classList.add('opacity-0', 'translate-y-2');
    setTimeout(function () {
      const box = card.parentNode;
      card.remove();
      if (box && !box.children.length && box.hasAttribute('popover') && box.matches(':popover-open')) box.hidePopover();
    }, 250);
  }

  function show(message, kind, options) {
    if (!message) return;
    const opts = options || {};
    const type = KIND[ALIAS[kind] || kind] || KIND.info;
    const duration = opts.duration || 4500;
    const box = getRegion();

    const card = document.createElement('div');
    card.className = 'pointer-events-auto relative flex w-full items-start gap-3 overflow-hidden rounded-2xl border border-[#E4D7C0] bg-[#FFFDF9] py-3.5 pl-3.5 pr-2 text-[#1F2937] opacity-0 translate-y-2 shadow-[0_18px_40px_-22px_rgba(6,70,74,.55)] transition duration-300 ease-out motion-reduce:transition-none';

    const badge = document.createElement('span');
    badge.className = 'grid h-9 w-9 shrink-0 place-items-center rounded-full ' + type.badge;
    badge.setAttribute('aria-hidden', 'true');
    badge.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' + type.icon + '</svg>';

    const body = document.createElement('div');
    body.className = 'min-w-0 flex-1 pt-0.5';
    const title = document.createElement('p');
    title.className = 'm-0 text-[15px] font-semibold leading-snug [font-family:var(--toast-font,Georgia,serif)]';
    title.textContent = opts.title || type.title;
    const text = document.createElement('p');
    text.className = 'm-0 mt-0.5 break-words text-sm leading-relaxed text-[#5B6472]';
    text.textContent = message;
    body.append(title, text);

    const close = document.createElement('button');
    close.type = 'button';
    // 44px tap target (the portals' CSS also forces buttons to 2.75rem tall).
    close.className = '-my-1.5 grid h-11 w-11 shrink-0 place-items-center rounded-full border-0 bg-transparent p-0 text-[#5B6472] transition hover:bg-[#F6F0E6] hover:text-[#006D6F]';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
    close.addEventListener('click', function () { dismiss(card); });

    // Countdown bar: shrinks over the toast's lifetime.
    const bar = document.createElement('span');
    bar.className = 'absolute inset-x-0 bottom-0 h-[3px] origin-left ' + type.bar;
    bar.setAttribute('aria-hidden', 'true');

    card.append(badge, body, close, bar);
    box.appendChild(card);
    while (box.children.length > MAX_VISIBLE) box.firstElementChild.remove();
    raise(box);

    requestAnimationFrame(function () {
      card.classList.remove('opacity-0', 'translate-y-2');
      bar.style.transition = 'transform ' + duration + 'ms linear';
      requestAnimationFrame(function () { bar.style.transform = 'scaleX(0)'; });
    });
    setTimeout(function () { dismiss(card); }, duration);
  }

  return { show: show };
})();
