/*
 * SelectDropdown: replaces every native <select> with a custom dropdown that
 * ALWAYS opens downward (native lists flip upward when the page lacks room).
 *
 * The real <select> stays in the DOM (visually hidden) and remains the source
 * of truth: forms, `required` validation, `.value`, and existing
 * change/input listeners keep working. Picking an option sets select.value
 * and dispatches bubbling `input` + `change` events.
 *
 * Opt out per element with  <select data-native>.  Multi-selects and
 * list boxes (size > 1) are left native.
 */
(function () {
  'use strict';
  if (window.SelectDropdown) return;

  const CSS = `
:where(.sd-btn){display:inline-flex;align-items:center;width:100%;min-height:2.5rem;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:.6rem;background-color:#fff;color:inherit;}
html body .sd-btn.sd-btn{font:inherit;text-align:left;cursor:pointer;position:relative;padding-right:2.4rem !important;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23374151'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z' clip-rule='evenodd'/%3E%3C/svg%3E") !important;background-repeat:no-repeat !important;background-position:right .8rem center !important;background-size:1.1rem !important;-webkit-appearance:none;appearance:none;}
html body .sd-btn .sd-label{display:block;flex:1;min-width:0;overflow:visible;text-overflow:clip;white-space:normal;overflow-wrap:anywhere;line-height:1.4;}
html body .sd-btn.sd-btn{height:auto !important;min-width:0;}
html body .sd-btn.sd-btn[aria-expanded="true"]{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23374151'%3E%3Cpath fill-rule='evenodd' d='M14.77 12.79a.75.75 0 01-1.06-.02L10 8.94l-3.71 3.83a.75.75 0 11-1.08-1.04l4.25-4.39a.75.75 0 011.08 0l4.25 4.39a.75.75 0 01-.02 1.06z' clip-rule='evenodd'/%3E%3C/svg%3E") !important;}
.sd-btn:disabled{opacity:.6;cursor:not-allowed;}
html body select.sd-native.sd-native{position:absolute !important;width:1px !important;height:1px !important;opacity:0 !important;pointer-events:none !important;margin:0 !important;min-height:0 !important;clip-path:inset(50%) !important;padding:0 !important;border:0 !important;}
html body .sd-list{display:block !important;visibility:visible !important;opacity:1 !important;position:fixed !important;z-index:2147483000 !important;margin:0;padding:6px;list-style:none;background:#fff;border:1px solid #d9dedb;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.18);overflow-y:auto;overscroll-behavior:contain;font-size:15px;color:#1f2a26;box-sizing:border-box;}
html body .sd-list .sd-opt{display:flex;align-items:center;gap:10px;margin:0 !important;padding:9px 12px !important;border:0 !important;border-radius:8px !important;background:transparent !important;box-shadow:none !important;cursor:pointer;line-height:1.35;color:#1f2a26 !important;font-weight:400;opacity:1 !important;}
html body .sd-list .sd-opt.is-active{background:#eaf4ef !important;}
html body .sd-list .sd-opt[aria-selected="true"]{font-weight:600;color:#14532d !important;}
html body .sd-list .sd-opt[aria-selected="true"]::after{content:"\\2713";margin-left:auto;color:#1b5e4b;}
html body .sd-list .sd-opt[aria-disabled="true"]{color:#9ca3af !important;cursor:not-allowed;background:transparent !important;font-weight:400;}
html body .sd-list .sd-opt[aria-disabled="true"]::after{content:none !important;}
html body .sd-list .sd-opt.sd-placeholder{font-style:italic;color:#6b7280 !important;}
html body .sd-list .sd-tag{margin-left:auto;padding:2px 8px;border-radius:999px;background:#f3f4f6;color:#6b7280;font-size:11px;font-weight:600;font-style:normal;white-space:nowrap;}
.sd-group{padding:8px 12px 4px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;}
`;

  function injectCss() {
    if (document.getElementById('sd-style')) return;
    const st = document.createElement('style');
    st.id = 'sd-style';
    st.textContent = CSS;
    (document.head || document.documentElement).appendChild(st);
  }

  let uid = 0;
  let open = null; // { select, btn, list, items, active }

  const eligible = s => s instanceof HTMLSelectElement && !s.multiple && !(s.size > 1)
    && !s.hasAttribute('data-native') && !s._sd;

  function labelOf(select) {
    const opt = select.options[select.selectedIndex];
    return opt ? opt.textContent.trim() : '';
  }

  function syncClasses(select, btn) {
    const cls = Array.from(select.classList).filter(c => c !== 'sd-native');
    btn.className = ['sd-btn', ...cls].join(' ');
    const style = select.getAttribute('style') || '';
    if (btn._lastStyle !== style) { btn.setAttribute('style', style); btn._lastStyle = style; }
    if (select.hidden) btn.hidden = true; else btn.hidden = false;
  }

  function refresh(select, rerender) {
    const sd = select._sd;
    if (!sd) return;
    const text = labelOf(select);
    if (sd.label.textContent !== text) sd.label.textContent = text || ' ';
    sd.btn.disabled = select.disabled;
    const aria = select.getAttribute('aria-label');
    if (aria) sd.btn.setAttribute('aria-label', aria); else sd.btn.removeAttribute('aria-label');
    if (rerender && open && open.select === select) render();
  }

  function enhance(select) {
    if (!eligible(select)) return;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    const label = document.createElement('span');
    label.className = 'sd-label';
    btn.appendChild(label);

    // Point existing <label for="id"> at the button for screen readers.
    if (select.id) {
      const lbl = document.querySelector(`label[for="${CSS_escape(select.id)}"]`);
      if (lbl) {
        if (!lbl.id) lbl.id = 'sd-lbl-' + (++uid);
        btn.setAttribute('aria-labelledby', lbl.id + ' ' + (btn.id = 'sd-btn-' + (++uid)));
        lbl.addEventListener('click', e => { e.preventDefault(); btn.focus(); });
      }
    }

    select._sd = { btn, label };
    syncClasses(select, btn);
    select.classList.add('sd-native');
    select.setAttribute('tabindex', '-1');
    select.setAttribute('aria-hidden', 'true');
    select.parentNode.insertBefore(btn, select);
    refresh(select);

    btn.addEventListener('click', () => (open && open.select === select ? close(true) : show(select)));
    btn.addEventListener('keydown', e => {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); show(select); }
    });
    // Validation libraries may focus the hidden select: hand focus to the button.
    select.addEventListener('focus', () => btn.focus());
    select.addEventListener('change', () => refresh(select));

    new MutationObserver(muts => {
      let cls = false;
      muts.forEach(m => { if (m.type === 'attributes' && (m.attributeName === 'class' || m.attributeName === 'style' || m.attributeName === 'hidden')) cls = true; });
      if (cls) {
        // Re-mirror classes (keep our own hiding class on the select).
        select._sdMuting = true;
        syncClasses(select, btn);
        if (!select.classList.contains('sd-native')) select.classList.add('sd-native');
        select._sdMuting = false;
      }
      refresh(select, muts.some(m => m.type === 'childList'));
    }).observe(select, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'style', 'hidden', 'disabled', 'aria-label'] });
  }

  function CSS_escape(s) { return window.CSS && CSS.escape ? CSS.escape(s) : s.replace(/"/g, '\\"'); }

  /* ---------- the open list ---------- */

  function render() {
    const { select, list } = open;
    list.innerHTML = '';
    open.items = [];
    let lastGroup = null;
    Array.from(select.options).forEach((opt, i) => {
      if (opt.hidden) return;
      const grp = opt.parentElement && opt.parentElement.tagName === 'OPTGROUP' ? opt.parentElement : null;
      if (grp && grp !== lastGroup) {
        const h = document.createElement('li');
        h.className = 'sd-group';
        h.setAttribute('role', 'presentation');
        h.textContent = grp.label;
        list.appendChild(h);
      }
      lastGroup = grp;
      const li = document.createElement('li');
      li.className = 'sd-opt';
      li.id = 'sd-opt-' + (++uid);
      li.setAttribute('role', 'option');
      const txt = opt.textContent.trim();
      const m = txt.match(/^(.*\S)\s+[-–]\s+(Unavailable|Off shift|Not available)$/i);
      if (m) {
        li.textContent = m[1];
        const tag = document.createElement('span');
        tag.className = 'sd-tag';
        tag.textContent = m[2];
        li.appendChild(tag);
      } else li.textContent = txt || ' ';
      if (opt.disabled && opt.value === '') li.classList.add('sd-placeholder');
      li.dataset.index = i;
      const off = opt.disabled || (grp && grp.disabled);
      if (off) li.setAttribute('aria-disabled', 'true');
      li.setAttribute('aria-selected', i === select.selectedIndex ? 'true' : 'false');
      list.appendChild(li);
      open.items.push(li);
    });
    const cur = open.items.findIndex(li => li.getAttribute('aria-selected') === 'true');
    setActive(cur >= 0 ? cur : firstEnabled(0, 1), false);
  }

  function firstEnabled(from, step) {
    const items = open.items;
    for (let i = from; i >= 0 && i < items.length; i += step) {
      if (items[i].getAttribute('aria-disabled') !== 'true') return i;
    }
    return -1;
  }

  function setActive(i, scroll = true) {
    if (!open) return;
    open.items.forEach(li => li.classList.remove('is-active'));
    open.active = i;
    const li = open.items[i];
    if (!li) { open.btn.removeAttribute('aria-activedescendant'); return; }
    li.classList.add('is-active');
    open.list.setAttribute('aria-activedescendant', li.id);
    if (scroll) li.scrollIntoView({ block: 'nearest' });
  }

  function place() {
    if (!open) return;
    const r = open.btn.getBoundingClientRect();
    const vh = window.innerHeight;
    const below = vh - r.bottom - 8;
    const { list } = open;
    list.style.left = Math.max(8, r.left) + 'px';
    list.style.top = (r.bottom + 4) + 'px';
    list.style.minWidth = r.width + 'px';
    list.style.maxWidth = Math.max(r.width, Math.min(420, window.innerWidth - 16)) + 'px';
    list.style.maxHeight = Math.max(120, Math.min(320, below)) + 'px';
  }

  function show(select) {
    if (select.disabled) return;
    close(false);
    const btn = select._sd.btn;
    // Need room below? Scroll the trigger up first so the list can open down.
    const r = btn.getBoundingClientRect();
    if (window.innerHeight - r.bottom < 220) btn.scrollIntoView({ block: 'center' });

    const list = document.createElement('ul');
    list.className = 'sd-list';
    list.setAttribute('role', 'listbox');
    list.id = 'sd-list-' + (++uid);
    list.tabIndex = -1;
    // A modal <dialog> sits in the browser's top layer, above any z-index on
    // <body>: the list must live inside the dialog to be visible over it.
    (select.closest('dialog[open]') || document.body).appendChild(list);
    open = { select, btn, list, items: [], active: -1 };
    btn.setAttribute('aria-expanded', 'true');
    btn.setAttribute('aria-controls', list.id);
    render();
    place();
    if (open.items[open.active]) open.items[open.active].scrollIntoView({ block: 'nearest' });
    list.focus({ preventScroll: true });

    list.addEventListener('mousedown', e => e.preventDefault());
    list.addEventListener('click', e => {
      const li = e.target.closest('.sd-opt');
      if (li && li.getAttribute('aria-disabled') !== 'true') choose(+li.dataset.index);
    });
    list.addEventListener('mousemove', e => {
      const li = e.target.closest('.sd-opt');
      if (li && li.getAttribute('aria-disabled') !== 'true') setActive(open.items.indexOf(li), false);
    });
    list.addEventListener('keydown', onListKey);
  }

  let typed = '', typedAt = 0;
  function onListKey(e) {
    if (!open) return;
    const n = open.items.length;
    if (e.key === 'ArrowDown') { e.preventDefault(); const i = firstEnabled(open.active + 1, 1); if (i >= 0) setActive(i); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); const i = firstEnabled(open.active - 1, -1); if (i >= 0) setActive(i); }
    else if (e.key === 'Home') { e.preventDefault(); setActive(firstEnabled(0, 1)); }
    else if (e.key === 'End') { e.preventDefault(); setActive(firstEnabled(n - 1, -1)); }
    else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      const li = open.items[open.active];
      if (li) choose(+li.dataset.index); else close(true);
    }
    else if (e.key === 'Escape') { e.preventDefault(); close(true); }
    else if (e.key === 'Tab') { close(false); }
    else if (e.key.length === 1 && /\S/.test(e.key)) {
      const now = Date.now();
      typed = (now - typedAt > 700 ? '' : typed) + e.key.toLowerCase();
      typedAt = now;
      const i = open.items.findIndex(li => li.getAttribute('aria-disabled') !== 'true' && li.textContent.toLowerCase().startsWith(typed));
      if (i >= 0) setActive(i);
    }
  }

  function choose(index) {
    const { select } = open;
    const changed = select.selectedIndex !== index;
    close(true);
    if (changed) {
      select.selectedIndex = index;
      select.dispatchEvent(new Event('input', { bubbles: true }));
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    refresh(select);
  }

  function close(refocus) {
    if (!open) return;
    const { btn, list } = open;
    list.remove();
    btn.setAttribute('aria-expanded', 'false');
    btn.removeAttribute('aria-controls');
    open = null;
    if (refocus) btn.focus({ preventScroll: true });
  }

  document.addEventListener('mousedown', e => {
    if (open && !open.list.contains(e.target) && !open.btn.contains(e.target)) close(false);
  }, true);
  window.addEventListener('resize', () => close(false));
  document.addEventListener('scroll', e => {
    if (open && !open.list.contains(e.target)) place();
  }, true);

  /* ---------- keep labels in sync with programmatic changes ---------- */

  // select.value = x / select.selectedIndex = n do not fire events.
  ['value', 'selectedIndex'].forEach(prop => {
    const d = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
    if (!d || !d.set) return;
    Object.defineProperty(HTMLSelectElement.prototype, prop, {
      configurable: true, enumerable: d.enumerable,
      get() { return d.get.call(this); },
      set(v) { d.set.call(this, v); if (this._sd) refresh(this); },
    });
  });
  document.addEventListener('reset', e => {
    const form = e.target;
    setTimeout(() => form.querySelectorAll && form.querySelectorAll('select').forEach(s => refresh(s)), 0);
  }, true);
  // Fallback for option.selected = true and similar silent changes.
  setInterval(() => document.querySelectorAll('select.sd-native').forEach(s => refresh(s)), 800);

  /* ---------- boot ---------- */

  function scan(root) {
    if (root instanceof HTMLSelectElement) { enhance(root); return; }
    if (root.querySelectorAll) root.querySelectorAll('select').forEach(enhance);
  }

  function boot() {
    injectCss();
    scan(document);
    new MutationObserver(muts => muts.forEach(m => m.addedNodes.forEach(n => { if (n.nodeType === 1) scan(n); })))
      .observe(document.body, { childList: true, subtree: true });
  }

  window.SelectDropdown = { enhance, refresh, close: () => close(false) };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
