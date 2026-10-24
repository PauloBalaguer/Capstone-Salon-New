/* ==========================================================
   toast.js - one notification style for the whole system.

   notify({ type, title, message, action, duration })
     type:   'error' | 'success' | 'warning' | 'info' | 'reminder'
     action: optional { label, onClick } button
   Phones and tablets (below lg): a dark pill at the top of the
   screen grows into a card, then shrinks back and fades after
   `duration` (default 5s). Desktop (lg+): cream cards stack in
   the bottom-right corner, at most 3, each with a close button.

   Every portal's own toast helper calls
   LMToast.show(message, kind, { duration, title }), which routes
   here. The older kind names ('good', 'wrong', 'note', 'system')
   still work. Classes are written in full and avoid v4-only syntax:
   the portals build them with the Tailwind CDN (v3), the auth pages
   and index.html with output.css (v4). Both layers are manual
   popovers so they stay above an open <dialog>.
   ========================================================== */

const LMToast = (function () {
  const ALIAS = { good: 'success', wrong: 'error', note: 'info', system: 'info', warn: 'warning', remind: 'reminder' };

  // card = cream desktop card, pill = dark phone island.
  const TYPE = {
    error: {
      title: 'Check this',
      card: 'bg-[#B23A3A]/10 text-[#B23A3A]', cardBar: 'bg-[#B23A3A]',
      pill: 'bg-[#B23A3A]/25 text-[#F2A3A3]', pillBar: 'bg-[#F2A3A3]',
      icon: '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/>'
    },
    success: {
      title: 'All set',
      card: 'bg-[#2F7D4F]/10 text-[#2F7D4F]', cardBar: 'bg-[#2F7D4F]',
      pill: 'bg-[#2F7D4F]/30 text-[#8BE0AE]', pillBar: 'bg-[#8BE0AE]',
      icon: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>'
    },
    warning: {
      title: 'Heads up',
      card: 'bg-[#B7791F]/10 text-[#B7791F]', cardBar: 'bg-[#B7791F]',
      pill: 'bg-[#B7791F]/30 text-[#F5C26B]', pillBar: 'bg-[#F5C26B]',
      icon: '<path d="M12 4 2.5 20h19z"/><path d="M12 10v4M12 17v.5"/>'
    },
    info: {
      title: 'Heads up',
      card: 'bg-[#006D6F]/10 text-[#006D6F]', cardBar: 'bg-[#006D6F]',
      pill: 'bg-[#0B8C8E]/30 text-[#7FD1D3]', pillBar: 'bg-[#7FD1D3]',
      icon: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.5"/>'
    },
    reminder: {
      title: 'Reminder',
      card: 'bg-[#C9A24E]/15 text-[#8A6A1F]', cardBar: 'bg-[#C9A24E]',
      pill: 'bg-[#C9A24E]/25 text-[#E6C67A]', pillBar: 'bg-[#E6C67A]',
      icon: '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>'
    }
  };

  const DESKTOP = window.matchMedia('(min-width: 1024px)');
  const MAX_VISIBLE = 3;
  const TITLE_FONT = '[font-family:var(--toast-font,Georgia,serif)]';
  let stack = null;
  let island = null;
  let islandTimers = [];

  function icon(paths, size) {
    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
  }

  function layer(className) {
    const box = document.createElement('div');
    box.className = className;
    box.setAttribute('role', 'status');
    box.setAttribute('aria-live', 'polite');
    if (typeof box.showPopover === 'function') box.setAttribute('popover', 'manual');
    document.body.appendChild(box);
    return box;
  }

  // Re-open the popover so a message shown while a <dialog> is open sits above it.
  function raise(box) {
    box.style.display = '';
    if (!box.hasAttribute('popover')) return;
    if (box.matches(':popover-open')) box.hidePopover();
    box.showPopover();
  }

  function lower(box) {
    if (!box.hasAttribute('popover')) box.style.display = 'none';
    else if (box.matches(':popover-open')) box.hidePopover();
  }

  // Badge, text and optional action button. Text goes in with textContent:
  // messages often come straight from the server.
  function fill(el, opts, type, onPill, close) {
    const badge = document.createElement('span');
    badge.className = 'grid h-10 w-10 shrink-0 place-items-center rounded-full ' + (onPill ? type.pill : type.card);
    badge.innerHTML = icon(type.icon, 20);

    const body = document.createElement('div');
    body.className = 'min-w-0 flex-1';
    const title = document.createElement('p');
    title.className = 'm-0 font-semibold leading-snug ' + TITLE_FONT + (onPill ? ' text-[15px]' : ' text-[1.05rem]');
    title.textContent = opts.title || type.title;
    const text = document.createElement('p');
    text.className = 'm-0 mt-0.5 break-words text-sm leading-snug ' + (onPill ? 'text-[#c9d6d4]' : 'text-[#5B6472]');
    text.textContent = opts.message || '';
    body.append(title, text);

    if (opts.action && opts.action.label) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mt-2 rounded-full border-0 px-3.5 py-1.5 text-xs font-semibold ' +
        (onPill ? 'bg-[#E8DCC8] text-[#06464A]' : 'bg-[#006D6F] text-[#FFFDF9] hover:bg-[#06464A]');
      btn.textContent = opts.action.label;
      btn.addEventListener('click', function () {
        if (typeof opts.action.onClick === 'function') opts.action.onClick();
        close();
      });
      body.appendChild(btn);
    }

    el.append(badge, body);
  }

  // Countdown line: shrinks over the message's lifetime.
  function countdown(bar, duration) {
    requestAnimationFrame(function () {
      bar.style.transition = 'transform ' + duration + 'ms linear';
      requestAnimationFrame(function () { bar.style.transform = 'scaleX(0)'; });
    });
  }

  /* ---------- Desktop: cream cards, bottom-right ---------- */

  function showCard(opts, type, duration) {
    if (!stack) {
      stack = layer('pointer-events-none fixed bottom-6 right-6 left-auto top-auto z-[9999] m-0 flex w-[360px] max-w-[calc(100vw-3rem)] flex-col gap-3 overflow-visible border-0 bg-transparent p-0');
    }

    const card = document.createElement('div');
    card.className = 'pointer-events-auto relative flex gap-3 overflow-hidden rounded-2xl border border-[#E4D7C0] bg-[#FFFDF9] p-4 pr-12 text-[#1F2937] opacity-0 translate-y-3 shadow-[0_20px_40px_-20px_rgba(0,0,0,.45)] transition duration-300 ease-out motion-reduce:transition-none';

    function dismiss() {
      if (!card.isConnected || card.dataset.leaving) return;
      card.dataset.leaving = '1';
      card.classList.add('opacity-0', 'translate-x-5');
      setTimeout(function () {
        card.remove();
        if (!stack.children.length) lower(stack);
      }, 250);
    }

    fill(card, opts, type, false, dismiss);

    const close = document.createElement('button');
    close.type = 'button';
    // 44px tap target (the portals' CSS also forces buttons to 2.75rem tall).
    close.className = 'absolute right-1.5 top-1.5 grid h-11 w-11 place-items-center rounded-full border-0 bg-transparent p-0 text-[#5B6472] transition hover:bg-[#F6F0E6] hover:text-[#006D6F]';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = icon('<path d="M6 6l12 12M18 6 6 18"/>', 16);
    close.addEventListener('click', dismiss);

    const bar = document.createElement('span');
    bar.className = 'absolute bottom-0 left-0 h-1 w-full origin-left motion-reduce:hidden ' + type.cardBar;
    bar.setAttribute('aria-hidden', 'true');

    card.append(close, bar);
    stack.appendChild(card);
    while (stack.children.length > MAX_VISIBLE) stack.firstElementChild.remove();
    raise(stack);

    requestAnimationFrame(function () { card.classList.remove('opacity-0', 'translate-y-3'); });
    countdown(bar, duration);
    setTimeout(dismiss, duration);
  }

  /* ---------- Phones: the island grows out of a pill ---------- */

  function islandParts() {
    if (!island) {
      const wrap = layer('pointer-events-none fixed inset-x-0 top-[max(8px,env(safe-area-inset-top))] bottom-auto z-[9999] mx-auto my-0 h-auto w-full max-w-md overflow-visible border-0 bg-transparent px-2.5 py-0');
      wrap.innerHTML =
        '<div class="group pointer-events-auto relative mx-auto h-8 w-28 overflow-hidden rounded-full bg-[#0f1f20] text-[#FFFDF9] opacity-0 shadow-2xl ring-1 ring-white/10 transition-[width,height,border-radius,opacity] duration-[450ms] ease-[cubic-bezier(.2,.9,.2,1)] motion-reduce:transition-none [&.open]:h-[var(--h)] [&.open]:w-full [&.open]:rounded-[26px] [&.show]:opacity-100">' +
          '<span class="absolute inset-0 m-auto h-1.5 w-10 rounded-full bg-white/15 transition-opacity group-[.open]:opacity-0"></span>' +
          '<div class="absolute left-1/2 top-0 flex -translate-x-1/2 items-start gap-3 p-4 pb-5 opacity-0 transition-opacity duration-200 motion-reduce:transition-none group-[.open]:opacity-100 group-[.open]:delay-200"></div>' +
        '</div>';
      island = { wrap: wrap, pill: wrap.firstElementChild, body: wrap.firstElementChild.lastElementChild };
    }
    return island;
  }

  function later(fn, ms) { islandTimers.push(setTimeout(fn, ms)); }

  function closeIsland() {
    const isl = islandParts();
    islandTimers.forEach(clearTimeout);
    islandTimers = [];
    isl.pill.classList.remove('open');
    later(function () { isl.pill.classList.remove('show'); }, 450);
    later(function () { lower(isl.wrap); }, 900);
  }

  function showIsland(opts, type, duration) {
    const isl = islandParts();
    islandTimers.forEach(clearTimeout);
    islandTimers = [];

    isl.body.innerHTML = '';
    fill(isl.body, opts, type, true, closeIsland);
    const bar = document.createElement('span');
    bar.className = 'absolute bottom-0 left-6 right-6 h-[3px] origin-left rounded-full motion-reduce:hidden ' + type.pillBar;
    bar.setAttribute('aria-hidden', 'true');
    isl.body.appendChild(bar);

    // The text is laid out at the open width (max-w-md minus the 10px gutters)
    // so the card grows to fit it. Height is measured a beat later: the
    // portals' Tailwind CDN styles new classes asynchronously.
    raise(isl.wrap);
    isl.body.style.width = (Math.min(document.documentElement.clientWidth, 448) - 20) + 'px';
    isl.pill.classList.add('show');
    later(function () {
      isl.pill.style.setProperty('--h', isl.body.offsetHeight + 'px');
      isl.pill.classList.add('open');
    }, 60);
    countdown(bar, duration);
    later(closeIsland, duration);
  }

  function notify(opts) {
    if (!opts || !opts.message) return;
    const type = TYPE[ALIAS[opts.type] || opts.type] || TYPE.info;
    const duration = opts.duration || 5000;
    (DESKTOP.matches ? showCard : showIsland)(opts, type, duration);
  }

  function show(message, kind, options) {
    const opts = options || {};
    notify({ type: kind, title: opts.title, message: message, duration: opts.duration });
  }

  return { notify: notify, show: show };
})();

const notify = LMToast.notify;
