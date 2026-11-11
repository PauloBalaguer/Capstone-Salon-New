/* Helpers for the shared notification panel (frontend/shared/notification-panel.css):
   relative time like the Customer panel, and the left icon. */
window.NotifUI = {
  /* "just now", "5 min ago", "4 hours ago", "Yesterday", "Nov 3". */
  ago(value) {
    const d = value instanceof Date ? value : new Date(String(value || '').replace(/^(\d{4}-\d{2}-\d{2}) /, '$1T'));
    const raw = String(value || '');
    const date = isNaN(d) ? new Date(raw) : d;
    if (isNaN(date)) return raw;
    const mins = Math.round((Date.now() - date.getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + ' min ago';
    const hours = Math.round(mins / 60);
    if (hours < 24) return hours + (hours === 1 ? ' hour ago' : ' hours ago');
    const days = Math.round(hours / 24);
    if (days === 1) return 'Yesterday';
    if (days < 7) return days + ' days ago';
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', ...(date.getFullYear() !== new Date().getFullYear() ? { year: 'numeric' } : {}) });
  },
  isBad(type, text) {
    return /CANCEL|REJECT|FAIL|NO.?SHOW|EXPIRE|REFUND|LOW.?STOCK/i.test(String(type || '') + ' ' + String(text || ''));
  },
  icon(bad) {
    return bad
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></svg>';
  },
};
