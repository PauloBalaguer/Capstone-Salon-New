/* Shared profile / service-address control. No frontend geography dataset. */
(() => {
  const script = document.currentScript;
  const endpoint = new URL('../../backend/public/getAddressLocations.php', script.src);
  const forms = new Map();
  const cache = new Map();
  let saved = null;
  let savedText = '';
  const keys = ['province', 'city', 'barangay', 'house', 'street', 'subdivision', 'landmark'];
  const labels = ['Province', 'City / Municipality', 'Barangay', 'House / Unit Number (optional)', 'Street / Road', 'Subdivision / Building / Purok (optional)', 'Landmark / Additional Directions (optional)'];
  async function locations(level, parent = '') {
    const key = `${level}/${parent}`;
    if (!cache.has(key)) {
      const url = new URL(endpoint); url.searchParams.set('level', level); url.searchParams.set('parent', parent);
      const request = fetch(url).then(async response => {
        const body = await response.json();
        if (!response.ok || !body.success) throw new Error(body.message || 'Could not load locations.');
        return body.locations;
      }).catch(error => { cache.delete(key); throw error; });
      cache.set(key, request);
    }
    return cache.get(key);
  }
  class AddressForm {
    constructor(root) {
      this.root = root; this.target = document.getElementById(root.dataset.addressTarget);
      this.data = {}; this.controls = {}; this.errors = {}; this.version = 0; this.busy = false;
      this.optional = root.dataset.addressOptional === 'true';
      this.message = document.createElement('p'); this.message.setAttribute('role', 'status'); root.append(this.message);
      this.legacy = document.createElement('p'); this.legacy.className = 'text-xs mb-2'; root.append(this.legacy);
      if (!this.optional && root.dataset.addressSaved === 'true') {
        const choice = document.createElement('div');
        this.savedButton = document.createElement('button'); this.savedButton.type = 'button'; this.savedButton.textContent = 'Use saved address';
        this.savedButton.disabled = true; this.savedButton.textContent = 'Use My Saved Address';
        this.savedButton.addEventListener('click', () => this.chooseSaved());
        const other = document.createElement('button'); other.type = 'button'; other.textContent = 'Use a different service address';
        this.otherButton = other; other.textContent = 'Enter Another Address';
        other.addEventListener('click', () => this.chooseOther());
        choice.className = 'flex flex-wrap gap-2 mb-3';
        [this.savedButton, other].forEach(button => {
          button.className = 'min-h-[44px] px-3 py-2 rounded-lg border border-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-purple-400 disabled:opacity-50 disabled:cursor-not-allowed';
          button.setAttribute('aria-pressed', 'false');
        });
        choice.append(this.savedButton, other); root.append(choice);
      }
      const grid = document.createElement('div'); grid.className = 'grid grid-cols-1 sm:grid-cols-2 gap-3'; root.append(grid);
      keys.forEach((key, index) => {
        const wrap = document.createElement('div');
        const label = document.createElement('label'); label.textContent = labels[index]; label.htmlFor = `${root.id}-${key}`;
        label.className = 'block text-xs mb-1'; wrap.append(label);
        const control = document.createElement(index < 3 ? 'select' : 'input');
        control.id = label.htmlFor; control.name = `address_${key}`;
        control.className = root.dataset.addressStyle === 'light'
          ? 'w-full px-4 py-3 border border-bronze/50 bg-sand-100 rounded-xl text-base'
          : 'w-full min-w-0 text-base bg-slate-950 text-slate-200 rounded-lg p-2.5 border border-slate-700 focus:outline-none focus:ring-2 focus:ring-purple-400';
        if (index < 3) {
          control.setAttribute('aria-required', String(!this.optional));
          control.addEventListener('change', () => this.change(key));
        } else {
          control.type = 'text'; control.maxLength = 150;
          control.addEventListener('input', () => { this.markEdited(); this.touched = true; this.data[key] = control.value; this.clearErrors(); this.sync(); });
        }
        this.controls[key] = control;
        const error = document.createElement('p'); error.id = `${control.id}-error`; error.className = 'text-xs text-red-500'; error.setAttribute('aria-live', 'polite');
        control.setAttribute('aria-describedby', error.id); this.errors[key] = error;
        wrap.append(control, error); grid.append(wrap);
      });
      const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Retry loading locations';
      retry.hidden = true; this.retry = retry;
      retry.addEventListener('click', () => this.set(this.data)); root.append(retry);
      const note = document.createElement('p'); note.className = 'text-xs mt-2';
      note.textContent = this.optional ? 'Albay addresses only. Optional. Save a complete location to reuse it for home service. Existing addresses are preserved until you edit these fields.' : 'Albay addresses only. Enter a house/unit, street, or subdivision/purok/sitio. This service address is saved with this request; it does not change your profile.';
      root.append(note);
      this.set({});
    }
    chooseSaved() {
      if (this.savedButton?.disabled) return;
      if (this.mode === 'other') this.otherDraft = { ...this.data };
      this.mode = 'saved'; this.updateChoice();
      return this.set(saved);
    }
    markEdited() {
      if (this.savedButton) { this.mode = 'other'; this.updateChoice(); }
    }
    chooseOther() {
      if (this.mode === 'other') return;
      this.mode = 'other'; this.updateChoice();
      return this.set(this.otherDraft || {});
    }
    updateChoice() {
      this.savedButton?.setAttribute('aria-pressed', String(this.mode === 'saved'));
      this.otherButton?.setAttribute('aria-pressed', String(this.mode === 'other'));
    }
    fill(key, rows, placeholder) {
      const select = this.controls[key]; if (!select) return;
      select.replaceChildren(new Option(placeholder, ''), ...rows.map(row => new Option(row.name, row.code)));
      select.disabled = !rows.length;
    }
    clearErrors() { Object.values(this.errors).forEach(el => { el.textContent = ''; }); Object.values(this.controls).forEach(el => el.removeAttribute('aria-invalid')); }
    showErrors(errors = {}) {
      this.clearErrors();
      for (const [key, value] of Object.entries(errors)) {
        if (this.errors[key]) { this.errors[key].textContent = value; this.controls[key].setAttribute('aria-invalid', 'true'); }
      }
      this.message.textContent = errors.address || '';
    }
    async set(value = {}) {
      const version = ++this.version; this.busy = true; this.clearErrors(); this.message.textContent = 'Loading locations…';
      this.retry.hidden = true;
      this.data = Object.fromEntries(keys.map(key => [key, value?.[key] || '']));
      keys.slice(3).forEach(key => { this.controls[key].value = this.data[key]; });
      ['province', 'city', 'barangay'].forEach(key => this.fill(key, [], 'Loading / select parent first'));
      this.sync();
      try {
        const provinces = await locations('provinces'); if (version !== this.version) return;
        this.fill('province', provinces, 'Select Province'); this.controls.province.value = this.data.province;
        if (this.data.province && !this.controls.province.value) {
          this.message.textContent = 'This saved address is outside Albay. Use a different address to continue. Existing stored addresses remain unchanged.';
          return;
        }
        if (this.data.province) {
          const cities = await locations('cities', this.data.province); if (version !== this.version) return;
          this.fill('city', cities, 'Select City/Municipality'); this.controls.city.value = this.data.city;
        }
        if (this.data.city) {
          const barangays = await locations('barangays', this.data.city); if (version !== this.version) return;
          this.fill('barangay', barangays, 'Select Barangay'); this.controls.barangay.value = this.data.barangay;
        }
        this.message.textContent = ''; this.sync();
      } catch (error) { if (version === this.version) { this.message.textContent = error.message; this.retry.hidden = false; } }
      finally { if (version === this.version) this.busy = false; }
    }
    change(key) {
      this.markEdited();
      this.touched = true;
      const next = { ...this.data, [key]: this.controls[key].value };
      if (key === 'province') { next.city = ''; next.barangay = ''; }
      if (key === 'city') next.barangay = '';
      this.set(next);
    }
    sync() {
      const names = ['barangay', 'city', 'province'].map(key => this.controls[key]?.selectedOptions?.[0]).map(option => option?.value ? option.textContent : '');
      this.target.value = [this.data.house, this.data.street, this.data.subdivision, ...names, this.data.landmark].filter(Boolean).join(', ');
      this.target.dispatchEvent(new Event('input', { bubbles: true }));
    }
    prepare(formData) {
      if (this.optional && !this.touched) return true;
      if (this.busy) { this.message.textContent = 'Please wait for locations to finish loading, or retry.'; return false; }
      const errors = {};
      if (!this.optional || Object.values(this.data).some(Boolean)) {
        ['province', 'city', 'barangay'].forEach(key => { if (!this.controls[key].value) errors[key] = 'Please select ' + key + '.'; });
        if (!this.optional && ![this.data.house, this.data.street, this.data.subdivision].some(v => v?.trim())) errors.street = 'Enter a house/unit, street, or subdivision/purok/sitio.';
      }
      this.showErrors(errors);
      if (Object.keys(errors).length) { this.root.querySelector('[aria-invalid="true"]')?.focus(); return false; }
      formData?.append('structuredAddress', JSON.stringify(this.data)); this.sync(); return true;
    }
  }
  window.AddressForms = {
    prepare: (id, formData) => forms.get(id)?.prepare(formData) === true,
    errors: (id, errors) => forms.get(id)?.showErrors(errors),
    setSaved: profile => {
      saved = profile.structuredAddress && typeof profile.structuredAddress === 'object' ? { ...profile.structuredAddress } : null;
      savedText = profile.address || '';
      const profileForm = forms.get('profile-address');
      if (profileForm) {
        if (saved) profileForm.set(saved);
        else if (profile.address) profileForm.legacy.textContent = `Existing address: ${profile.address}. Enter structured fields to replace it.`;
      }
      locations('provinces').then(rows => {
        for (const form of forms.values()) if (form.savedButton) {
          form.savedButton.disabled = !saved?.city || !saved?.barangay || !rows.some(row => row.code === saved?.province);
          form.legacy.textContent = form.savedButton.disabled
            ? (savedText ? `Saved address: ${savedText}. Select its province, city and barangay below to use it for this request.` : 'No saved address yet. Enter the address for this request below.')
            : `Saved address: ${savedText || saved.formatted || ''}`;
        }
      }).catch(() => {});
    },
    defaultService: () => {
      const form = forms.get('service-address');
      if (!form) return;
      form.otherDraft = null;
      if (saved?.province && saved?.city && saved?.barangay) {
        form.mode = 'saved'; form.updateChoice(); return form.set(saved);
      }
      form.mode = 'other'; form.updateChoice();
      return form.set(savedText ? { street: savedText } : {});
    },
    resetGuest: () => forms.get('guest-service-address')?.set({})
  };
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-address-target]').forEach(root => forms.set(root.id, new AddressForm(root)));
  });
})();
