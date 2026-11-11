/* Change-password card for portals without their own form (cashier, admin).
   Put <div data-password-card></div> on the page. Same design and rules as the
   customer and staff profiles; the server checks everything again.
   Inline styles keep the portal themes from recolouring it. */
document.addEventListener('DOMContentLoaded', () => {
  const API = '../../backend/account/';
  const RULES = [
    ['At least 8 characters', v => v.length >= 8],
    ['At least one uppercase letter (A–Z)', v => /[A-Z]/.test(v)],
    ['At least one lowercase letter (a–z)', v => /[a-z]/.test(v)],
    ['At least one number (0–9)', v => /[0-9]/.test(v)],
    ['At least one special character (e.g., @, #, $, or -)', v => /[^A-Za-z0-9\s]/.test(v)]
  ];
  const EYE = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"></path><circle cx="12" cy="12" r="3" stroke-width="2"></circle></svg>';
  const INPUT = 'width:100%;min-height:44px;padding:10px 44px 10px 14px;border:1px solid #E2E7E3;border-radius:12px;background:#FFFFFF;color:#18261F;font-size:14px;';

  const field = (id, label, placeholder, auto) => `
    <div>
      <label for="${id}" style="display:block;margin-bottom:6px;font-size:14px;color:var(--muted)">${label}</label>
      <div style="position:relative">
        <input id="${id}" type="password" required autocomplete="${auto}" placeholder="${placeholder}" style="${INPUT}">
        <button type="button" data-eye="${id}" aria-label="Show ${label.toLowerCase()}" aria-pressed="false" style="position:absolute;top:0;right:0;bottom:0;width:44px;display:flex;align-items:center;justify-content:center;background:transparent !important;border:0 !important;color:var(--muted) !important;box-shadow:none !important">${EYE}</button>
      </div>
      <p data-error="${id}" role="alert" style="display:none;margin-top:4px;font-size:13px;font-weight:600;color:var(--danger)"></p>
    </div>`;

  document.querySelectorAll('[data-password-card]').forEach(card => {
    card.innerHTML = `
      <section style="background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;color:var(--text)">
        <h3 style="font-size:16px;font-weight:700;color:var(--text)">Security</h3>
        <p style="margin-top:4px;font-size:14px;color:var(--muted)">Change your password regularly to keep your account secure. We'll email a code to confirm the change.</p>

        <form data-step="form" style="margin-top:16px">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            ${field('pc-current', 'Current Password', '', 'current-password')}
            ${field('pc-new', 'New Password', '', 'new-password')}
            ${field('pc-confirm', 'Confirm New Password', '', 'new-password')}
          </div>
          <div style="margin-top:16px;padding:16px;background:var(--bg);border:1px solid var(--border);border-radius:12px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px">
              <p style="font-size:14px;font-weight:700;color:var(--text)">Your new password needs</p>
              <span data-count style="padding:2px 10px;border-radius:999px;font-size:13px;font-weight:700;background:#FFFFFF;color:var(--muted)"></span>
            </div>
            <ul data-rules class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2" aria-label="Password requirements" style="list-style:none;margin:0;padding:0"></ul>
          </div>
          <div style="display:flex;justify-content:flex-end;margin-top:16px">
            <button type="submit" data-submit style="min-height:44px;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:700">Change Password</button>
          </div>
        </form>

        <form data-step="verify" style="display:none;margin-top:16px">
          <p style="font-size:14px;color:var(--muted)">We emailed a 6-digit confirmation code to your account email. Enter it below to finish changing your password.</p>
          <label for="pc-code" style="display:block;margin:12px 0 6px;font-size:14px;color:var(--muted)">Confirmation Code</label>
          <input id="pc-code" type="text" required inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code" style="${INPUT}padding-right:14px;max-width:220px">
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
            <button type="button" data-cancel style="min-height:44px;padding:10px 16px;border-radius:10px;font-size:14px;font-weight:700;background:#FFFFFF !important;color:var(--text) !important;border:1px solid var(--border) !important">Cancel</button>
            <button type="submit" style="min-height:44px;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:700;background:var(--brand) !important;color:#FFFFFF !important;border:1px solid var(--brand) !important">Confirm Change</button>
          </div>
        </form>
      </section>`;

    const el = id => card.querySelector('#' + id);
    const current = el('pc-current');
    const next = el('pc-new');
    const confirm = el('pc-confirm');
    const code = el('pc-code');
    const step1 = card.querySelector('[data-step="form"]');
    const step2 = card.querySelector('[data-step="verify"]');
    const submit = card.querySelector('[data-submit]');
    const toast = (message, type) => LMToast.show(message, type, { duration: 4000 });

    const showError = (id, message) => {
      const p = card.querySelector(`[data-error="${id}"]`);
      p.textContent = message || '';
      p.style.display = message ? 'block' : 'none';
    };

    const post = async (file, values) => {
      const body = new FormData();
      Object.entries(values).forEach(([key, value]) => body.append(key, value));
      const response = await fetch(API + file, { method: 'POST', credentials: 'same-origin', body });
      return response.json();
    };

    function refresh() {
      const value = next.value;
      const results = RULES.map(([label, test]) => ({ label, met: test(value) }));
      const metCount = results.filter(rule => rule.met).length;
      card.querySelector('[data-rules]').innerHTML = results.map(rule => `
        <li style="display:flex;align-items:flex-start;gap:8px;font-size:14px;${rule.met ? 'font-weight:600;color:var(--success)' : 'color:var(--muted)'}">
          <span aria-hidden="true" style="flex:none;margin-top:2px;width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;${rule.met ? 'background:var(--success);color:#FFFFFF' : 'background:#FFFFFF;border:1px solid var(--border)'}">${rule.met ? '<svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M5 13l4 4L19 7"></path></svg>' : ''}</span>
          <span>${rule.label}</span>
        </li>`).join('');

      const count = card.querySelector('[data-count]');
      const allMet = metCount === RULES.length;
      count.textContent = `${metCount} of ${RULES.length}`;
      count.style.background = allMet ? 'var(--success-soft)' : '#FFFFFF';
      count.style.color = allMet ? 'var(--success)' : 'var(--muted)';

      showError('pc-new', value && value === current.value ? 'New password must be different from your current one.' : '');
      showError('pc-confirm', confirm.value && value !== confirm.value ? 'Passwords do not match.' : '');

      const ready = !!current.value && allMet && value !== current.value && value === confirm.value;
      submit.disabled = !ready;
      submit.style.cssText += ready
        ? ';background:#16604B !important;color:#FFFFFF !important;border:1px solid #16604B !important;cursor:pointer;opacity:1'
        : ';background:#F6F8F7 !important;color:#4A5751 !important;border:1px solid #E2E7E3 !important;cursor:not-allowed;opacity:1';
    }

    [current, next, confirm].forEach(input => input.addEventListener('input', refresh));
    current.addEventListener('input', () => showError('pc-current', ''));

    card.querySelectorAll('[data-eye]').forEach(button => {
      button.addEventListener('click', () => {
        const input = el(button.dataset.eye);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
      });
    });

    const showStep = verifying => {
      step1.style.display = verifying ? 'none' : '';
      step2.style.display = verifying ? '' : 'none';
    };

    step1.addEventListener('submit', async event => {
      event.preventDefault();
      submit.disabled = true;
      try {
        const res = await post('changePassword.php', { currentPassword: current.value, newPassword: next.value, confirmPassword: confirm.value });
        if (res.success) {
          code.value = '';
          showStep(true);
          toast(res.message, 'success');
        } else if (/incorrect current/i.test(res.message || '')) {
          showError('pc-current', res.message);
        } else {
          toast(res.message || 'Could not change your password.', 'error');
        }
      } catch {
        toast('Connection problem. Please try again.', 'error');
      }
      refresh();
    });

    step2.addEventListener('submit', async event => {
      event.preventDefault();
      try {
        const res = await post('confirmPasswordChange.php', { code: code.value.trim(), newPassword: next.value, confirmPassword: confirm.value });
        toast(res.message || 'Could not change your password.', res.success ? 'success' : 'error');
        if (res.success) {
          step1.reset();
          code.value = '';
          showStep(false);
          refresh();
        }
      } catch {
        toast('Connection problem. Please try again.', 'error');
      }
    });

    card.querySelector('[data-cancel]').addEventListener('click', () => showStep(false));
    refresh();
  });
});
