/* Staff Portal page logic (frontend/staff/dashboard.html).

   One page, five views switched by the URL hash so the sidebar, top bar and
   data stay loaded: #dashboard, #appointments, #attendance, #schedule and
   #profile. My Profile has no navigation item; it opens from the avatar and
   name in the top bar (desktop and tablet) or the mobile header. */

const STAFF_VIEWS = {
  dashboard: { title: 'Dashboard', nav: 'Home' },
  appointments: { title: 'My Appointments', nav: 'Bookings' },
  attendance: { title: 'My Attendance', nav: 'Attendance' },
  schedule: { title: 'My Schedule', nav: 'Schedule' },
  profile: { title: 'My Profile', nav: null },
};

// Lucide-style 24px stroke icons, inlined so nothing extra loads.
const STAFF_ICONS = {
  home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
  calendar: '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  schedule: '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18M8 14h3M8 17.5h6"/>',
  bell: '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
  pin: '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
  play: '<polygon points="7 4 20 12 7 20 7 4"/>',
  pause: '<rect x="6" y="4.5" width="4" height="15" rx="1"/><rect x="14" y="4.5" width="4" height="15" rx="1"/>',
  stop: '<rect x="5" y="5" width="14" height="14" rx="2"/>',
  coffee: '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4z"/><path d="M6 2v2M10 2v2M14 2v2"/>',
  login: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  checkCircle: '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
  mail: '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 7 9-7"/>',
  briefcase: '<rect x="2.5" y="7" width="19" height="13" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M2.5 13h19"/>',
  lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  camera: '<path d="M14.5 4h-5L7.5 6.5H4a2 2 0 0 0-2 2V18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8.5a2 2 0 0 0-2-2h-3.5z"/><circle cx="12" cy="13" r="3.5"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  chevronRight: '<path d="m9 18 6-6-6-6"/>',
  chevronLeft: '<path d="m15 18-6-6 6-6"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
  package: '<path d="m7.5 4.3 9 5.2M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
  scissors: '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
  sparkle: '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  filter: '<path d="M3 5h18l-7 8.5V20l-4-2v-4.5z"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
};

document.addEventListener('alpine:init', () => {
  Alpine.magic('icon', () => (name, cls = '') =>
    `<svg class="ico ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${STAFF_ICONS[name] || ''}</svg>`);
});

function staffPortal() {
  return {
    view: 'dashboard',
    views: STAFF_VIEWS,
    navItems: [
      { key: 'dashboard', label: 'Dashboard', short: 'Home', icon: 'home' },
      { key: 'appointments', label: 'My Appointments', short: 'Bookings', icon: 'calendar' },
      { key: 'attendance', label: 'My Attendance', short: 'Attendance', icon: 'clock' },
      { key: 'schedule', label: 'My Schedule', short: 'Schedule', icon: 'schedule' },
    ],
    notificationsOpen: false,

    // Modals
    shiftOutOpen: false,
    signOutOpen: false,

    // My Appointments
    apptTab: 'today',
    apptSearch: '',
    apptPage: 1,
    apptPageSize: 8,
    expandedId: null,
    supplyForm: { itemId: '', quantity: 1, search: '', open: false },

    // My Attendance
    attRange: 'month',
    attFrom: '',
    attTo: '',
    attPage: 1,
    attPageSize: 10,

    // My Profile
    editing: false,
    profileForm: { phone: '', email: '' },
    profileErrors: { phone: '', email: '' },
    photoPreview: '',
    signingOut: false,

    // Below 1024px the sidebar is a Menu drawer opened from the bottom bar (as in the Customer Portal).
    menuOpen: false,
    compact: false,

    init() {
      const compactQuery = window.matchMedia('(max-width: 1023.98px)');
      const syncCompact = () => { this.compact = compactQuery.matches; this.closeMenu(); };
      syncCompact();
      if (compactQuery.addEventListener) compactQuery.addEventListener('change', syncCompact);
      else compactQuery.addListener(syncCompact);
      this.$watch('menuOpen', open => document.body.classList.toggle('sp-drawer-open', open));
      this.applyHash();
      window.addEventListener('hashchange', () => this.applyHash());
      this.$watch('apptTab', () => { this.apptPage = 1; this.expandedId = null; });
      this.$watch('apptSearch', () => { this.apptPage = 1; });
      this.$watch('attRange', () => { this.attPage = 1; });
      this.setAttRange('month');
      document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (this.menuOpen) this.closeMenu(true);
        else if (this.shiftOutOpen) this.shiftOutOpen = false;
        else if (this.signOutOpen && !this.signingOut) this.signOutOpen = false;
        else this.notificationsOpen = false;
      });
    },

    get store() { return Alpine.store('staff'); },

    /* ---------- Navigation ---------- */

    applyHash() {
      const key = (location.hash || '').replace('#', '').split('?')[0];
      const next = STAFF_VIEWS[key] ? key : 'dashboard';
      if (next !== this.view) {
        if (this.view === 'profile') this.leaveProfile();
        this.view = next;
        this.$nextTick(() => {
          const main = document.getElementById('sp-main');
          if (main) main.scrollTo({ top: 0 });
          window.scrollTo({ top: 0 });
          const heading = document.getElementById('sp-page-title');
          if (heading) heading.focus({ preventScroll: true });
        });
      }
      this.notificationsOpen = false;
      this.closeMenu();
      document.title = `${STAFF_VIEWS[this.view].title} | Leo Mejillano Staff Portal`;
    },

    go(view) {
      if (location.hash === '#' + view) this.applyHash();
      else location.hash = view;
    },

    openProfile() { this.go('profile'); },

    toggleMenu() {
      if (this.menuOpen) { this.closeMenu(true); return; }
      this.notificationsOpen = false;
      this.menuOpen = true;
      this.$nextTick(() => this.$refs.drawerClose && this.$refs.drawerClose.focus({ preventScroll: true }));
    },

    closeMenu(restoreFocus) {
      if (!this.menuOpen) return;
      this.menuOpen = false;
      if (restoreFocus && this.$refs.menuButton) this.$refs.menuButton.focus({ preventScroll: true });
    },

    pageTitle() { return STAFF_VIEWS[this.view].title; },

    toggleNotifications() {
      this.notificationsOpen = !this.notificationsOpen;
    },

    // Opens the booking a notification is about (My Appointments, searched to
    // its reference), or My Schedule for schedule-only updates.
    openNotification(n) {
      this.store.markNotificationRead(n);
      const reference = this.store.notificationReference(n);
      this.notificationsOpen = false;
      if (reference) {
        this.apptSearch = reference;
        this.expandedId = (this.store.appointments.find(a => a.id === reference) || {}).id || null;
        this.go('appointments');
      } else {
        this.go(/SCHEDULE|SHIFT/.test(n.type || '') ? 'schedule' : 'dashboard');
      }
    },

    /* ---------- Shift ---------- */

    shiftIn() { return this.store.shiftAction('shift_in'); },
    startBreak() { return this.store.shiftAction('break_start'); },
    endBreak() { return this.store.shiftAction('break_end'); },

    openShiftOut() {
      this.store.refresh();
      this.shiftOutOpen = true;
      this.$nextTick(() => document.getElementById('shift-out-cancel')?.focus());
    },

    async confirmShiftOut() {
      const res = await this.store.shiftAction('shift_out');
      if (res.success) this.shiftOutOpen = false;
    },

    // Bookings still open today: they stay assigned, Shift Out never closes them.
    remainingTodayAppointments() {
      return this.store.getTodayAppointments().filter(a => ['Pending', 'Confirmed'].includes(a.status));
    },

    /* ---------- Dashboard ---------- */

    dashboardUpcoming() {
      return this.store.getUpcomingAppointments().slice(0, 5);
    },

    appointmentWhen(appt) {
      const today = this.store.todayISO();
      const dayLabel = appt.date === today ? 'Today' : this.store.formatDate(appt.date, { weekday: 'short', month: 'short', day: 'numeric' });
      return `${dayLabel}, ${appt.time.replace(/^0/, '')}`;
    },

    viewAppointment(appt) {
      this.apptTab = appt.date === this.store.todayISO() ? 'today' : 'upcoming';
      this.apptSearch = '';
      this.go('appointments');
      this.$nextTick(() => {
        this.expandedId = appt.id;
        this.$nextTick(() => document.getElementById('appt-' + appt.id)?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
      });
    },

    /* ---------- My Appointments ---------- */

    apptTabs() {
      const store = this.store;
      const today = store.todayISO();
      return [
        { key: 'all', label: 'All', count: store.appointments.length },
        { key: 'today', label: 'Today', count: store.appointments.filter(a => a.date === today).length },
        { key: 'upcoming', label: 'Upcoming', count: store.appointments.filter(a => a.date > today && !store.isInactiveAppointment(a) && !store.isFinished(a)).length },
        { key: 'completed', label: 'Completed', count: store.appointments.filter(a => store.isFinished(a)).length },
      ];
    },

    filteredAppointments() {
      const store = this.store;
      const today = store.todayISO();
      const q = this.apptSearch.trim().toLowerCase();
      let list;
      switch (q ? 'all' : this.apptTab) {
        case 'today': list = store.appointments.filter(a => a.date === today); break;
        case 'upcoming': list = store.appointments.filter(a => a.date > today && !store.isInactiveAppointment(a) && !store.isFinished(a)); break;
        case 'completed': list = store.appointments.filter(a => store.isFinished(a)); break;
        default: list = store.appointments;
      }
      if (q) {
        list = list.filter(a => a.clientName.toLowerCase().includes(q) || a.id.toLowerCase().includes(q) || (a.serviceName || '').toLowerCase().includes(q));
      }
      // Today and Upcoming read earliest first; history reads newest first.
      const direction = !q && ['today', 'upcoming'].includes(this.apptTab) ? 1 : -1;
      return [...list].sort((a, b) => direction * store.appointmentSortKey(a).localeCompare(store.appointmentSortKey(b)));
    },

    apptTotalPages() { return Math.max(1, Math.ceil(this.filteredAppointments().length / this.apptPageSize)); },
    apptCurrentPage() { return Math.min(this.apptPage, this.apptTotalPages()); },
    pagedAppointments() {
      const start = (this.apptCurrentPage() - 1) * this.apptPageSize;
      return this.filteredAppointments().slice(start, start + this.apptPageSize);
    },

    toggleExpand(id) {
      this.expandedId = this.expandedId === id ? null : id;
      this.supplyForm = { itemId: '', quantity: 1, search: '', open: false };
    },

    // Supplies can be logged against a service in progress or finished in the last 7 days.
    canLogSupplies(appt) {
      if (appt.status === 'In Progress') return true;
      if (!this.store.isFinished(appt)) return false;
      const cutoff = new Date(this.store.now);
      cutoff.setDate(cutoff.getDate() - 7);
      return appt.date >= cutoff.toLocaleDateString('en-CA', { timeZone: STAFF_TZ });
    },

    supplyOptions() {
      const q = this.supplyForm.search.trim().toLowerCase();
      const items = this.store.inventory;
      return (q ? items.filter(i => i.name.toLowerCase().includes(q)) : items).slice(0, 30);
    },

    chooseSupply(item) {
      this.supplyForm.itemId = item.id;
      this.supplyForm.search = item.name;
      this.supplyForm.open = false;
    },

    supplyStockLabel(item) {
      if (item.stock <= 0) return 'Out of stock';
      return item.stock <= item.minQty ? `${item.stock} left (low)` : `${item.stock} in stock`;
    },

    async logSupply(referenceCode) {
      const { itemId, quantity } = this.supplyForm;
      if (!itemId || !(Number(quantity) > 0)) {
        this.store.toast('Choose a supply and a quantity first.', 'error');
        return;
      }
      const res = await this.store.logSupplyUsage(itemId, quantity, referenceCode, '');
      if (res.success) this.supplyForm = { itemId: '', quantity: 1, search: '', open: false };
    },

    /* ---------- My Attendance ---------- */

    isoDay(date) {
      return date.toLocaleDateString('en-CA', { timeZone: STAFF_TZ });
    },

    attRangeBounds() {
      const now = this.store.now;
      const today = this.isoDay(now);
      if (this.attRange === 'week') {
        const d = new Date(today + 'T00:00:00');
        d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
        return [this.isoDay(d), today];
      }
      if (this.attRange === 'month') return [today.slice(0, 8) + '01', today];
      if (this.attRange === 'custom') return [this.attFrom || '', this.attTo || ''];
      return ['', ''];
    },

    // Quick ranges also fill the From/To boxes; typing a date switches to custom.
    setAttRange(range) {
      this.attRange = range;
      const [from, to] = this.attRangeBounds();
      this.attFrom = from;
      this.attTo = to;
      this.attPage = 1;
    },

    customAttRange() {
      if (this.attFrom && this.attTo && this.attFrom > this.attTo) [this.attFrom, this.attTo] = [this.attTo, this.attFrom];
      this.attRange = 'custom';
      this.attPage = 1;
    },

    filteredAttendance() {
      const [from, to] = this.attRangeBounds();
      return this.store.attendance.filter(r => (!from || r.date >= from) && (!to || r.date <= to));
    },

    attTotalPages() { return Math.max(1, Math.ceil(this.filteredAttendance().length / this.attPageSize)); },
    attCurrentPage() { return Math.min(this.attPage, this.attTotalPages()); },
    pagedAttendance() {
      const start = (this.attCurrentPage() - 1) * this.attPageSize;
      return this.filteredAttendance().slice(start, start + this.attPageSize);
    },

    // Live numbers for the open shift, stored ones for closed shifts.
    rowWorkedSeconds(row) {
      if (row.isOpen && String(this.store.shift.attendanceId) === row.id) return this.store.liveWorkedSeconds();
      return row.workedSeconds;
    },

    rowBreakSeconds(row) {
      if (row.isOpen && String(this.store.shift.attendanceId) === row.id) return this.store.liveBreakSeconds();
      return row.breakSeconds;
    },

    attSummary() {
      const rows = this.filteredAttendance();
      const days = new Set(rows.map(r => r.date)).size;
      const worked = rows.reduce((sum, r) => sum + this.rowWorkedSeconds(r), 0);
      const breaks = rows.reduce((sum, r) => sum + this.rowBreakSeconds(r), 0);
      return { days, worked, breaks, shifts: rows.length };
    },

    attStatusTone(status) {
      return { 'On shift': 'green', 'On break': 'amber', Completed: 'teal', 'Auto clock-out': 'orange', 'Closed at end of day': 'orange', 'Clocked out by cashier': 'blue' }[status] || 'slate';
    },

    pageLabel(page, size, total) {
      if (!total) return '';
      const first = (page - 1) * size + 1;
      return `${first}–${Math.min(first + size - 1, total)} of ${total}`;
    },

    /* ---------- My Schedule ---------- */

    scheduleWorkDays() { return this.store.schedule.filter(d => !d.isRestDay && d.start); },

    scheduleRestDays() { return this.store.schedule.filter(d => d.isRestDay); },

    scheduleWeeklyHours() {
      const secs = this.scheduleWorkDays().reduce((sum, d) => {
        const [sh, sm] = d.start.split(':').map(Number);
        const [eh, em] = d.end.split(':').map(Number);
        let mins = (eh * 60 + em) - (sh * 60 + sm);
        if (mins < 0) mins += 24 * 60; // schedule that ends after midnight
        return sum + mins * 60;
      }, 0);
      return (secs / 3600).toFixed(1).replace(/\.0$/, '');
    },

    scheduleFromBranch() { return this.store.schedule.length > 0 && this.store.schedule.every(d => d.source !== 'staff'); },

    /* ---------- My Profile ---------- */

    leaveProfile() {
      this.editing = false;
    },

    startEdit() {
      const p = this.store.profile;
      this.profileForm = { phone: p.phone || '', email: p.email || '' };
      this.profileErrors = { phone: '', email: '' };
      this.photoPreview = '';
      this.editing = true;
      this.$nextTick(() => document.getElementById('pf-phone')?.focus());
    },

    cancelEdit() {
      this.editing = false;
      this.photoPreview = '';
      this.profileErrors = { phone: '', email: '' };
    },

    validateProfile() {
      const phone = this.profileForm.phone.replace(/[\s\-()]/g, '').replace(/^\+63/, '0');
      const email = this.profileForm.email.trim();
      this.profileErrors = {
        phone: /^09\d{9}$/.test(phone) ? '' : 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.',
        email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? '' : 'Enter a valid email address.',
      };
      return !this.profileErrors.phone && !this.profileErrors.email;
    },

    async saveProfile() {
      if (!this.validateProfile()) return;
      const res = await this.store.saveProfile({ phone: this.profileForm.phone, email: this.profileForm.email, profilePicture: this.photoPreview });
      if (res.success) {
        this.editing = false;
        this.photoPreview = '';
      } else if (res.field && this.profileErrors[res.field] !== undefined) {
        this.profileErrors[res.field] = res.message;
      }
    },

    async pickPhoto(event) {
      const file = event.target.files[0];
      event.target.value = '';
      if (!file) return;
      if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
        this.store.toast('Please choose a PNG, JPG or WebP photo.', 'error');
        return;
      }
      if (file.size > 2 * 1024 * 1024) {
        this.store.toast('That photo is over 2MB. Please choose a smaller one.', 'error');
        return;
      }
      try {
        this.photoPreview = await this.shrinkPhoto(file);
      } catch {
        this.store.toast('That photo could not be read. Please choose another one.', 'error');
        return;
      }
      if (!this.editing) this.startEditKeepPhoto();
    },

    // The photo is stored in the database and sent with every dashboard load,
    // and MySQL can refuse a large one, so keep only what an avatar needs.
    async shrinkPhoto(file) {
      const image = await createImageBitmap(file);
      const scale = Math.min(1, 512 / Math.max(image.width, image.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(image.width * scale));
      canvas.height = Math.max(1, Math.round(image.height * scale));
      const context = canvas.getContext('2d');
      context.fillStyle = '#FFFFFF'; // JPEG has no transparency
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      image.close();
      return canvas.toDataURL('image/jpeg', 0.85);
    },

    startEditKeepPhoto() {
      const photo = this.photoPreview;
      this.startEdit();
      this.photoPreview = photo;
    },

    avatarSrc() {
      return this.photoPreview || this.store.profile.profilePicture || '';
    },

    openSignOut() {
      this.signOutOpen = true;
      this.$nextTick(() => document.getElementById('sign-out-cancel')?.focus());
    },

    // A POST form to the shared logout endpoint: it ends the PHP session and
    // redirects to the login page. The attendance shift is not touched.
    confirmSignOut() {
      this.signingOut = true;
      document.getElementById('sign-out-form').submit();
    },
  };
}
