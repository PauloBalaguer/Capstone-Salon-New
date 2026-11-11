/*
 * ListPager: one pager (10 rows per page) shared by every long list.
 *
 * Alpine pages (admin): load this BEFORE Alpine (plain <script> in <head>,
 * Alpine itself is `defer`). Then in a template:
 *
 *   <template x-for="c in $store.pager.slice('customers', getFilteredCustomers())">…
 *   <nav x-lm-pager="'customers'"></nav>
 *
 * `slice` returns the current page and remembers the list length; the nav
 * renders  Showing 1–10 of 34   ‹ 1 2 3 … 9 ›  and hides itself when one page
 * is enough. When a list's length changes (search, filters, new data) it goes
 * back to page 1.
 *
 * Plain-JS pages (cashier): ListPager.render(navEl, page, total, onPage).
 */
(function () {
  'use strict';
  const SIZE = 10;

  function numbers(cur, pages) {
    return [...new Set([1, cur - 1, cur, cur + 1, pages].filter(n => n >= 1 && n <= pages))].sort((a, b) => a - b);
  }

  /* Buttons markup. `cls` lets each portal reuse its own button style. */
  function markup(page, total, cls) {
    const pages = Math.max(1, Math.ceil(total / SIZE));
    if (pages <= 1) return '';
    const first = (page - 1) * SIZE + 1;
    const last = Math.min(total, page * SIZE);
    const b = cls.btn, cur = cls.current;
    let html = `<span class="lp-count">Showing ${first}–${last} of ${total}</span><span class="lp-buttons">`;
    html += `<button type="button" class="${b}" data-lp-page="${page - 1}" ${page === 1 ? 'disabled' : ''} aria-label="Previous page">‹</button>`;
    const nums = numbers(page, pages);
    nums.forEach((n, i) => {
      if (i && n - nums[i - 1] > 1) html += '<span class="lp-gap" aria-hidden="true">…</span>';
      html += `<button type="button" class="${b}${n === page ? ' ' + cur : ''}" data-lp-page="${n}"${n === page ? ' aria-current="page"' : ''} aria-label="Page ${n}">${n}</button>`;
    });
    html += `<button type="button" class="${b}" data-lp-page="${page + 1}" ${page === pages ? 'disabled' : ''} aria-label="Next page">›</button></span>`;
    return html;
  }

  const CSS = `
.lp-nav{display:flex;align-items:center;justify-content:space-between;gap:10px 16px;flex-wrap:wrap;padding:12px 20px;border-top:1px solid var(--lm-border,var(--cp-border,#e5e7eb));}
.lp-nav:empty{display:none;}
.lp-count{font-size:13px;color:var(--lm-muted,var(--cp-muted,#6b7280));}
.lp-buttons{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.lp-gap{padding:0 2px;color:var(--lm-muted,var(--cp-muted,#6b7280));}
.lp-btn{min-width:36px;height:36px;padding:0 10px;border-radius:10px;border:1px solid var(--lm-border,var(--cp-border,#e5e7eb));background:#fff;color:inherit;font:inherit;font-size:14px;font-weight:600;cursor:pointer;display:inline-grid;place-items:center;}
.lp-btn:hover:not(:disabled):not(.lp-current){border-color:var(--lm-teal-800,var(--cp-teal-800,#1b5e4b));}
.lp-btn.lp-current{background:var(--lm-teal-800,var(--cp-teal-800,#1b5e4b));border-color:var(--lm-teal-800,var(--cp-teal-800,#1b5e4b));color:#fff;cursor:default;}
.lp-btn:disabled{opacity:.4;cursor:not-allowed;}
`;
  function injectCss() {
    if (document.getElementById('lp-style')) return;
    const st = document.createElement('style');
    st.id = 'lp-style';
    st.textContent = CSS;
    (document.head || document.documentElement).appendChild(st);
  }
  if (document.head) injectCss(); else document.addEventListener('DOMContentLoaded', injectCss);

  function scrollToList(nav) {
    const box = nav.closest('section, .lm-card, .cp-card, [data-lp-scroll]') || nav.parentElement;
    if (box && box.getBoundingClientRect().top < 0) box.scrollIntoView({ block: 'start', behavior: 'smooth' });
  }

  /* ---------- Plain JS ---------- */
  window.ListPager = {
    SIZE,
    /* Returns the clamped page so callers can store it. */
    render(nav, page, total, onPage, cls = { btn: 'lp-btn', current: 'lp-current' }) {
      const pages = Math.max(1, Math.ceil(total / SIZE));
      page = Math.min(Math.max(1, page), pages);
      nav.classList.add('lp-nav');
      nav.innerHTML = markup(page, total, cls);
      nav.onclick = (e) => {
        const btn = e.target.closest('[data-lp-page]');
        if (!btn || btn.disabled) return;
        onPage(Number(btn.dataset.lpPage));
        scrollToList(nav);
      };
      return page;
    },
    slice(list, page) {
      const pages = Math.max(1, Math.ceil(list.length / SIZE));
      page = Math.min(Math.max(1, page), pages);
      return list.slice((page - 1) * SIZE, page * SIZE);
    },
  };

  /* ---------- Alpine ---------- */
  document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;
    Alpine.store('pager', {
      lists: {},
      entry(key) {
        if (!this.lists[key]) this.lists[key] = { page: 1, total: 0 };
        return this.lists[key];
      },
      slice(key, list) {
        list = Array.isArray(list) ? list : [];
        const s = this.entry(key);
        if (s.total !== list.length) {
          if (s.page !== 1) s.page = 1;
          s.total = list.length;
        }
        const pages = Math.max(1, Math.ceil(list.length / SIZE));
        const page = Math.min(s.page, pages);
        return list.slice((page - 1) * SIZE, page * SIZE);
      },
      go(key, page) { this.entry(key).page = page; },
    });

    Alpine.directive('lm-pager', (el, { expression }, { effect, evaluateLater }) => {
      const getKey = evaluateLater(expression);
      el.classList.add('lp-nav');
      el.setAttribute('aria-label', el.getAttribute('aria-label') || 'Pages');
      let key = null;
      effect(() => {
        getKey(k => {
          key = k;
          const s = Alpine.store('pager').entry(k);
          el.innerHTML = markup(s.page, s.total, { btn: 'lp-btn', current: 'lp-current' });
        });
      });
      el.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-lp-page]');
        if (!btn || btn.disabled || key == null) return;
        Alpine.store('pager').go(key, Number(btn.dataset.lpPage));
        scrollToList(el);
      });
    });
  });
})();
