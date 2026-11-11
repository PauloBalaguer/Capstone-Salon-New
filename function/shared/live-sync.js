/* Live sync for every panel (Admin, Cashier, Staff, Customer, guest site).
 *
 * Watches backend/public/getSyncVersion.php -- a hash of the attendance,
 * break, booking, assignment and schedule rows in scope -- and calls the
 * page's own reload function whenever it changes, so a change made on one
 * device/browser/account shows up everywhere without a manual refresh.
 *
 * Server-Sent Events first (one ~25 s stream at a time, reopened by the
 * browser); if the stream can't be held open (e.g. a host that buffers it),
 * it falls back to light polling every few seconds. Paused while the tab is
 * hidden; on return it checks immediately.
 *
 *   LiveSync.start({ branch: 'daraga' | 'all' | () => key, onChange: fn })
 *
 * onChange may return a Promise; calls never overlap, and a burst of changes
 * collapses into one reload.
 */
(function () {
  'use strict';

  const script = document.currentScript;
  const ROOT = script && script.src ? new URL('../../', script.src).href : '../../';
  const ENDPOINT = ROOT + 'backend/public/getSyncVersion.php';
  const POLL_MS = 5000;

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
    let useSse = typeof window.EventSource === 'function' && opts.sse !== false;
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
        pollTimer = setInterval(poll, POLL_MS);
      }
    }

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
