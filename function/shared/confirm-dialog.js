/* Portal-styled replacements for window.confirm and window.prompt. Focus starts
   on Cancel (or the text box) and Esc cancels. Inline !important keeps the
   portal themes' global button rules from recolouring them. */
function lmDialog({ title, message, confirmLabel, cancelLabel, input }) {
  return new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.setAttribute('aria-labelledby', 'lm-confirm-title');
    dialog.style.cssText = 'width:min(92vw,420px);margin:auto;padding:24px;border:0;border-radius:16px;background:#FFFDF9;color:#1F2937;box-shadow:0 20px 50px rgba(0,0,0,.3);';

    const heading = document.createElement('h2');
    heading.id = 'lm-confirm-title';
    heading.textContent = title;
    heading.style.cssText = 'margin:0 0 8px;font-size:18px;font-weight:700;color:#06464A;';
    const text = document.createElement('p');
    text.textContent = message;
    text.style.cssText = 'margin:0 0 20px;font-size:14px;line-height:1.5;color:#475569;white-space:pre-line;';
    dialog.append(heading, text);

    let field = null;
    if (input) {
      field = document.createElement('input');
      field.type = 'text';
      field.value = input.value ?? '';
      field.setAttribute('aria-label', input.label || title);
      field.style.cssText = 'display:block;width:100%;min-height:44px;margin:-8px 0 20px;padding:10px 14px;border:1px solid #D9C9B4;border-radius:10px;background:#FFFFFF;color:#222222;font-size:14px;';
      dialog.append(field);
    }

    let accepted = false;
    const button = (label, css, result) => {
      const el = document.createElement('button');
      el.type = 'button';
      el.textContent = label;
      el.style.cssText = 'min-height:44px;padding:8px 18px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;' + css;
      el.addEventListener('click', () => { accepted = result; dialog.close(); });
      return el;
    };
    const cancel = button(cancelLabel, 'background:#FFFFFF !important;color:#1F2937 !important;border:1px solid #CBD5E1 !important;', false);
    const accept = button(confirmLabel, 'background:#006D6F !important;color:#FFFFFF !important;border:1px solid #006D6F !important;', true);
    const actions = document.createElement('div');
    actions.style.cssText = 'display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px;';
    actions.append(cancel, accept);
    dialog.append(actions);

    if (field) {
      field.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); accept.click(); }
      });
    }
    dialog.addEventListener('close', () => {
      const value = field ? field.value : '';
      dialog.remove();
      resolve({ accepted, value });
    });
    document.body.append(dialog);
    dialog.showModal();
    if (field) { field.focus(); field.select(); } else { cancel.focus(); }
  });
}

/* Resolves true or false. */
async function LMConfirm({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel' }) {
  return (await lmDialog({ title, message, confirmLabel, cancelLabel })).accepted;
}

/* Resolves the typed text, or null when cancelled. */
async function LMPrompt({ title, message, value = '', label, confirmLabel = 'Save', cancelLabel = 'Cancel' }) {
  const result = await lmDialog({ title, message, confirmLabel, cancelLabel, input: { value, label } });
  return result.accepted ? result.value : null;
}
