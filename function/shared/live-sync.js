/* Live sync for every panel (Admin, Cashier, Staff, Customer, guest site).
 *
 * Watches backend/public/getSyncVersion.php -- a hash of the attendance,
 * break, booking, assignment and schedule rows in scope -- and calls the
 * page's own reload function whenever it changes, so a change made on one
 * device/browser/account shows up everywhere without a manual refresh.
 *
 * Polls once a minute. Paused while the tab is hidden; on return it checks
 * immediately. Server-Sent Events are off by default: each stream kept a
 * server function running and the serverless database awake around the
 * clock, which is billed. Pass { sse: true } to opt a page back in.
 *
 *   LiveSync.start({ branch: 'daraga' | 'all' | () => key, onChange: fn })
 *
 * onChange may return a Promise; calls never overlap, and a burst of changes
 * collapses into one reload.
 *
 * Pusher (optional): when backend/public/getLiveConfig.php returns a Pusher
 * key, the page also listens on the "salon-live" channel. A save anywhere
 * (backend/shared/config/setup/live-updates.php) sends a "changed" message
 * there, and the page checks its version at once instead of waiting up to a
 * minute. While Pusher is connected the minute check slows to every 5
 * minutes as a safety net. Without Pusher keys, or if Pusher can't be
 * reached, everything works exactly as before.
 */
(function () {
  'use strict';

  const script = document.currentScript;
  const ROOT = script && script.src ? new URL('../../', script.src).href : '../../';
  const ENDPOINT = ROOT + 'backend/public/getSyncVersion.php';
  const POLL_MS = 60000;
  const PUSHER_POLL_MS = 300000;
  const CONFIG_ENDPOINT = ROOT + 'backend/public/getLiveConfig.php';
  const PUSHER_JS = 'https://js.pusher.com/8.4.0/pusher.min.js';
  const PUSHER_CHANNEL = 'salon-live';
  const PUSHER_EVENT = 'changed';

  // One Pusher connection per page, shared by every LiveSync.start() on it.
  let pusherPromise = null;
  const pusherListeners = new Set();
  let pusherConnected = false;

  function readCachedConfig() {
    try { return JSON.parse(sessionStorage.getItem('salonLiveConfig') || 'null'); } catch (_) { return null; }
  }

  async function liveConfig() {
    const cached = readCachedConfig();
    if (cached) return cached;
    const res = await fetch(CONFIG_ENDPOINT, { credentials: 'same-origin' });
    const data = await res.json();
    // Only a working config is remembered, so keys added later are picked up on the next page.
    if (data && data.enabled) { try { sessionStorage.setItem('salonLiveConfig', JSON.stringify(data)); } catch (_) {} }
    return data;
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const tag = document.createElement('script');
      tag.src = src;
      tag.async = true;
      tag.onload = resolve;
      tag.onerror = reject;
      document.head.appendChild(tag);
    });
  }

  function connectPusher() {
    if (pusherPromise) return pusherPromise;
    pusherPromise = (async () => {
      const config = await liveConfig();
      if (!config || !config.enabled || !config.key || !config.cluster) return null;
      if (typeof window.Pusher !== 'function') await loadScript(PUSHER_JS);
      const pusher = new window.Pusher(config.key, { cluster: config.cluster, forceTLS: true });
      pusher.connection.bind('state_change', (states) => {
        pusherConnected = states.current === 'connected';
        pusherListeners.forEach((listener) => listener.onState(pusherConnected));
      });
      pusher.subscribe(PUSHER_CHANNEL).bind(PUSHER_EVENT, () => {
        pusherListeners.forEach((listener) => listener.onMessage());
      });
      return pusher;
    })().catch((err) => {
      console.warn('Live updates (Pusher) are off; using the minute check.', err);
      return null;
    });
    return pusherPromise;
  }

  function start(options) {
    const opts = options || {};
    const onChange = typeof opts.onChange === 'function' ? opts.onChange : function () {};
    const branchOf = () => {
      const value = typeof opts.branch === 'function' ? opts.branch() : opts.branch;
      return value ? String(value) : 'all';
    };

    let version = null;
    let branch = branchOf();
    let source = null;
    let pollTimer = null;
    let sseFailures = 0;
    let useSse = typeof window.EventSource === 'function' && opts.sse === true;
    let running = false;
    let pending = false;
    let stopped = false;

    async function fire() {
      if (running) { pending = true; return; }
      running = true;
      try { await onChange(); } catch (err) { console.warn('LiveSync reload failed', err); }
      running = false;
      if (pending) { pending = false; fire(); }
    }

    function accept(next) {
      if (!next) return;
      const changed = version !== null && next !== version;
      version = next;
      if (changed) fire();
    }

    function url(stream) {
      return ENDPOINT + '?branch=' + encodeURIComponent(branch) + (stream ? '&stream=1' : '') + '&_=' + Date.now();
    }

    async function poll() {
      try {
        const res = await fetch(url(false), { cache: 'no-store', credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.success) accept(data.version);
      } catch (_) { /* offline for a moment: next tick retries */ }
    }

    function closeAll() {
      if (source) { source.close(); source = null; }
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function openSse() {
      let gotEvent = false;
      source = new EventSource(url(true));
      source.addEventListener('version', (event) => {
        gotEvent = true;
        sseFailures = 0;
        try { accept(JSON.parse(event.data).version); } catch (_) {}
      });
      source.onerror = () => {
        // A normal end of a ~25 s stream also lands here (the browser then
        // reconnects); only streams that never delivered anything count.
        if (!gotEvent) sseFailures++;
        gotEvent = false;
        if (sseFailures >= 2) {
          useSse = false;
          closeAll();
          resume();
        }
      };
    }

    function resume() {
      if (stopped || document.hidden) return;
      closeAll();
      if (useSse) openSse();
      else {
        poll();
        pollTimer = setInterval(poll, pusherConnected ? PUSHER_POLL_MS : POLL_MS);
      }
    }

    // Pusher: a save anywhere -> check this page's version now. A burst of
    // messages within half a second becomes one check.
    let pushTimer = null;
    pusherListeners.add({
      onMessage() {
        if (stopped || document.hidden) return;
        clearTimeout(pushTimer);
        pushTimer = setTimeout(poll, 500);
      },
      onState() {
        // Connected: slow the safety-net check. Lost: back to every minute.
        if (!stopped && !document.hidden && !useSse) resume();
      }
    });
    connectPusher();

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) { closeAll(); return; }
      // Back on the tab: whatever happened meanwhile, reload once now.
      fire();
      resume();
    });
    window.addEventListener('online', () => { fire(); resume(); });
    window.addEventListener('pagehide', closeAll);

    resume();

    return {
      /** Call when the watched branch changes (e.g. a branch dropdown). */
      setBranch(next) {
        const key = next ? String(next) : branchOf();
        if (key === branch) return;
        branch = key;
        version = null;
        resume();
      },
      refreshNow: fire,
      stop() { stopped = true; closeAll(); }
    };
  }

  window.LiveSync = { start };
})();
