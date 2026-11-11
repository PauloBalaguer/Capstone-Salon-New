/* Live data + actions for the Leo Mejillano Salon Staff Portal
   (frontend/staff/dashboard.html). Fills Alpine.store('staff') from
   backend/staff/getDashboardData.php and refreshes it after every change.

   Attendance times always come from the server: the page only adds the
   seconds that passed since the last load, so the live clock and the
   "hours worked" counter keep moving between refreshes. */

const STAFF_API_BASE = '../../';
const STAFF_LOGIN_URL = '../../frontend/auth/login.html';
const STAFF_TZ = 'Asia/Manila';

document.addEventListener('alpine:init', () => {
  Alpine.store('staff', {
    loading: true,
    loadError: '',
    csrfToken: '',
    profile: { employeeId: null, employeeCode: '', name: '', firstName: '', lastName: '', phone: '', profilePicture: null, email: '', position: '', role: '', accountStatus: '', branchId: null, branchName: '', hireDate: null },
    shift: { status: 'off', serverNow: null, attendanceId: null, clockIn: null, breakStart: null, breakSeconds: 0, workedSeconds: 0, breakCount: 0, scheduleToday: null, today: { timeIn: null, timeOut: null, workedSeconds: 0, breakSeconds: 0 } },
    schedule: [],
    appointments: [],
    attendance: [],
    inventory: [],
    notifications: [],
    lateGraceMinutes: null,
    availability: { busyUntil: null, busyMinutesLeft: 0 },
    // Server time minus this device's time, so "now" follows the server.
    clockOffsetMs: 0,
    now: new Date(),
    loadedAt: new Date(),
    busy: false,
    shiftBusy: false,

    toast(message, type = 'success') {
      LMToast.show(message, type, { duration: 3500 });
    },

    async refresh() {
      try {
        const response = await fetch(STAFF_API_BASE + 'backend/staff/getDashboardData.php', { credentials: 'same-origin', cache: 'no-store' });
        if (response.status === 401) {
          window.location.replace(STAFF_LOGIN_URL);
          return;
        }
        const data = await response.json();
        if (!data.success) {
          this.loadError = data.message || 'Your dashboard could not be loaded.';
          return;
        }
        this.loadError = '';
        this.csrfToken = data.csrfToken;
        window.LMCsrfToken = data.csrfToken;
        this.profile = data.profile;
        this.schedule = data.schedule;
        this.lateGraceMinutes = data.lateGraceMinutes;
        this.availability = data.availability;
        this.appointments = data.appointments;
        this.attendance = data.attendance;
        this.inventory = data.inventory;
        this.notifications = data.notifications;
        this.applyShift(data.shift);
      } catch (e) {
        this.loadError = 'No connection. Check your internet and try again.';
        console.error('Network error while loading staff dashboard data:', e);
      } finally {
        this.loading = false;
      }
    },

    applyShift(shift) {
      this.shift = shift;
      if (shift.serverNow) this.clockOffsetMs = new Date(shift.serverNow).getTime() - Date.now();
      this.now = this.serverNow();
      // Same clock as `now`, or a wrong device clock would count as elapsed time.
      this.loadedAt = this.now;
    },

    serverNow() {
      return new Date(Date.now() + this.clockOffsetMs);
    },

    /* POSTs to a backend endpoint (relative to the project root) with this
       session's CSRF token and returns the parsed JSON response. */
    async post(endpoint, params) {
      const body = new URLSearchParams();
      Object.entries(params || {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined) body.append(key, value);
      });
      const response = await fetch(STAFF_API_BASE + endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': this.csrfToken },
        body
      });
      if (response.status === 401) {
        window.location.replace(STAFF_LOGIN_URL);
        throw new Error('Not authenticated');
      }
      const data = await response.json().catch(() => ({ success: false, message: 'Unexpected server response. Please try again.' }));
      // A stale token (e.g. the session was renewed in another tab): reload it once.
      if (data.csrfExpired) await this.refresh();
      return data;
    },

    /* ---------- Shift In / breaks / Shift Out ---------- */

    async shiftAction(action) {
      if (this.shiftBusy) return { success: false };
      this.shiftBusy = true;
      try {
        const res = await this.post('backend/staff/shiftAction.php', { action });
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) {
          this.applyShift(res.shift);
          await this.refresh();
        } else {
          await this.refresh(); // show the real state if another tab changed it
        }
        return res;
      } catch {
        this.toast('Could not reach the server. Your shift was not changed.', 'error');
        return { success: false };
      } finally {
        this.shiftBusy = false;
      }
    },

    elapsedSinceLoad() {
      return Math.max(0, Math.floor((this.now - this.loadedAt) / 1000));
    },

    // Live worked seconds of the open shift: breaks don't count.
    liveWorkedSeconds() {
      const s = this.shift;
      if (s.status === 'on') return s.workedSeconds + this.elapsedSinceLoad();
      return s.workedSeconds;
    },

    liveBreakSeconds() {
      const s = this.shift;
      if (s.status === 'break') return s.breakSeconds + this.elapsedSinceLoad();
      return s.breakSeconds;
    },

    // Today's total (all shifts that started today) for the dashboard.
    liveTodayWorkedSeconds() {
      const t = this.shift.today || { workedSeconds: 0 };
      return t.workedSeconds + (this.shift.status === 'on' ? this.elapsedSinceLoad() : 0);
    },

    currentBreakSeconds() {
      if (this.shift.status !== 'break' || !this.shift.breakStart) return 0;
      return Math.max(0, Math.floor((this.now - new Date(this.shift.breakStart)) / 1000));
    },

    shiftStatusLabel() {
      return { on: 'On Shift', break: 'On Break', off: 'Off Shift' }[this.shift.status] || 'Off Shift';
    },

    /* ---------- Formatting (always in Asia/Manila) ---------- */

    timeLabel(iso, withSeconds = false) {
      if (!iso) return '--';
      const d = iso instanceof Date ? iso : new Date(iso);
      return d.toLocaleTimeString('en-US', { timeZone: STAFF_TZ, hour: 'numeric', minute: '2-digit', ...(withSeconds ? { second: '2-digit' } : {}) });
    },

    clockLabel() {
      return this.timeLabel(this.now, true);
    },

    longDateLabel(date = this.now) {
      return date.toLocaleDateString('en-US', { timeZone: STAFF_TZ, weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    },

    // "2026-10-21" -> "Oct 21, 2026"; parsed at local midnight so it can't shift a day.
    formatDate(iso, opts = { month: 'short', day: 'numeric', year: 'numeric' }) {
      return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', opts);
    },

    // 9000 -> "2h 30m"; under a minute reads "0m".
    durationLabel(seconds) {
      const total = Math.max(0, Math.floor((seconds || 0) / 60));
      const h = Math.floor(total / 60);
      const m = total % 60;
      return h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
    },

    hoursLabel(seconds) {
      return ((seconds || 0) / 3600).toFixed(2) + ' hrs';
    },

    greeting() {
      const hour = Number(this.now.toLocaleString('en-US', { timeZone: STAFF_TZ, hour: 'numeric', hour12: false })) % 24;
      if (hour < 12) return 'Good morning';
      if (hour < 18) return 'Good afternoon';
      return 'Good evening';
    },

    todayISO() {
      const parts = new Intl.DateTimeFormat('en-CA', { timeZone: STAFF_TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(this.now);
      return parts; // en-CA formats as YYYY-MM-DD
    },

    scheduleTodayLabel() {
      const day = this.shift.scheduleToday;
      if (!day) return 'No schedule set';
      if (day.isRestDay) return 'Rest day';
      if (!day.startLabel) return 'No schedule set';
      return `${day.startLabel} – ${day.endLabel}`;
    },

    firstName() {
      return this.profile.firstName || (this.profile.name || '').split(' ')[0] || 'there';
    },

    initials() {
      const parts = (this.profile.name || '').trim().split(/\s+/).filter(Boolean);
      if (!parts.length) return '';
      return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
    },

    /* ---------- Appointments (same rules the server enforces) ---------- */

    // "YYYY-MM-DD HH:MM" in 24-hour time: appt.time is "01:00 PM".
    appointmentSortKey(appt) {
      const [time, meridiem] = appt.time.split(' ');
      const [h, m] = time.split(':').map(Number);
      const hours = (h % 12) + (meridiem === 'PM' ? 12 : 0);
      return `${appt.date} ${String(hours).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    },

    isInactiveAppointment(appt) {
      return ['Cancelled', 'No-Show'].includes(appt.status);
    },

    isFinished(appt) {
      return ['Completed', 'Reviewed'].includes(appt.status);
    },

    getTodayAppointments() {
      const today = this.todayISO();
      return this.appointments
        .filter(a => a.date === today)
        .sort((a, b) => (this.isInactiveAppointment(a) - this.isInactiveAppointment(b))
          || this.appointmentSortKey(a).localeCompare(this.appointmentSortKey(b)));
    },

    // Still to do: today's open bookings first, then later days.
    getUpcomingAppointments() {
      const today = this.todayISO();
      return this.appointments
        .filter(a => a.date >= today && ['Pending', 'Confirmed', 'In Progress', 'Reschedule Requested'].includes(a.status))
        .sort((a, b) => this.appointmentSortKey(a).localeCompare(this.appointmentSortKey(b)));
    },

    todayAssignedCount() {
      return this.getTodayAppointments().filter(a => !this.isInactiveAppointment(a)).length;
    },

    todayCompletedCount() {
      return this.getTodayAppointments().filter(a => this.isFinished(a)).length;
    },

    // A shared booking is started and completed by its main stylist.
    canStart(appt) {
      return appt.isMainStylist !== false && appt.status === 'Confirmed' && appt.date === this.todayISO();
    },

    canComplete(appt) {
      return appt.isMainStylist !== false && appt.status === 'In Progress';
    },

    inProgressAppointments() {
      return this.appointments.filter(a => a.status === 'In Progress');
    },

    // appt.minutesLate is from the server's clock when the data loaded.
    minutesLate(appt) {
      return appt.minutesLate + Math.floor((this.now - this.loadedAt) / 60000);
    },

    isLate(appt) {
      return appt.status === 'Confirmed' && appt.isMainStylist !== false
        && appt.date <= this.todayISO() && this.minutesLate(appt) > 0;
    },

    isMissed(appt) {
      return this.isLate(appt) && appt.date < this.todayISO();
    },

    isBeyondGrace(appt) {
      return this.isLate(appt) && this.minutesLate(appt) > (this.lateGraceMinutes ?? 0);
    },

    lateLabel(appt) {
      if (appt.date < this.todayISO()) return 'Missed date';
      const minutes = this.minutesLate(appt);
      return minutes < 60 ? `Late ${minutes} min` : `Late ${Math.floor(minutes / 60)}h ${minutes % 60}m`;
    },

    hasArrivalRequest(appt, kind) {
      return (appt.arrivalRequests || []).includes(kind);
    },

    statusTone(status) {
      return {
        Pending: 'amber', Confirmed: 'blue', 'In Progress': 'green', Completed: 'teal', Reviewed: 'teal',
        'Reschedule Requested': 'orange', 'Reschedule Required': 'orange', Cancelled: 'red', 'No-Show': 'red'
      }[status] || 'slate';
    },

    async startService(referenceCode) {
      if (this.busy) return { success: false };
      this.busy = true;
      try {
        const changed = await AppointmentArrival.review(referenceCode, 'backend/staff/updateAppointment.php');
        if (changed) await this.refresh();
        return { success: changed };
      } finally {
        this.busy = false;
      }
    },

    async completeService(referenceCode) {
      const finished = await LMConfirm({ title: 'Mark service as finished?', message: 'This cannot be undone. Log any supplies you used first.', confirmLabel: 'Mark as Finished' });
      if (!finished) return { success: false };
      this.busy = true;
      try {
        const res = await this.post('backend/staff/updateAppointment.php', { id: referenceCode, status: 'Completed' });
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) await this.refresh();
        return res;
      } catch {
        this.toast('Could not reach the server. The service was not marked as finished.', 'error');
        return { success: false };
      } finally {
        this.busy = false;
      }
    },

    // Asks the front desk to act; the booking and payment stay as they are.
    async requestArrivalHelp(referenceCode, kind) {
      const question = kind === 'no_show'
        ? 'Ask the front desk to mark this customer as No-Show? The cashier or admin makes the final decision.'
        : 'Ask the front desk to reassign or reschedule this booking?';
      const send = await LMConfirm({ title: 'Send to the front desk?', message: question, confirmLabel: 'Send Request' });
      if (!send) return { success: false };
      this.busy = true;
      try {
        const res = await this.post('backend/staff/requestArrivalHelp.php', { id: referenceCode, kind });
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) await this.refresh();
        return res;
      } catch {
        this.toast('Could not send the request. Please try again.', 'error');
        return { success: false };
      } finally {
        this.busy = false;
      }
    },

    async logSupplyUsage(itemId, quantity, referenceCode, note) {
      this.busy = true;
      try {
        const res = await this.post('backend/staff/updateInventory.php', { id: itemId, quantity, referenceCode, note });
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) await this.refresh();
        return res;
      } catch {
        this.toast('Could not reach the server. The supply was not logged.', 'error');
        return { success: false };
      } finally {
        this.busy = false;
      }
    },

    /* ---------- Profile and password ---------- */

    async saveProfile(fields) {
      this.busy = true;
      try {
        const res = await this.post('backend/staff/saveProfile.php', fields);
        this.toast(res.message, res.success ? 'success' : 'error');
        if (res.success) await this.refresh();
        return res;
      } catch {
        this.toast('Could not reach the server. Nothing was saved.', 'error');
        return { success: false };
      } finally {
        this.busy = false;
      }
    },

    // Step 1 of 2: checks the current password and the new password's rules,
    // then emails a confirmation code. Nothing changes yet.
    async changePassword(fields) {
      this.busy = true;
      try {
        return await this.post('backend/staff/changePassword.php', fields);
      } catch {
        return { success: false, message: 'Could not reach the server. Your password was not changed.' };
      } finally {
        this.busy = false;
      }
    },

    // Step 2 of 2: the emailed code plus the same new password applies it.
    async confirmPasswordChange(fields) {
      this.busy = true;
      try {
        return await this.post('backend/staff/confirmPasswordChange.php', fields);
      } catch {
        return { success: false, message: 'Could not reach the server. Your password was not changed.' };
      } finally {
        this.busy = false;
      }
    },

    /* ---------- Notifications ---------- */

    unreadNotificationCount() {
      return this.notifications.filter(n => !n.isRead).length;
    },

    async markAllNotificationsAsRead() {
      if (!this.unreadNotificationCount()) return;
      try {
        const result = await this.post('backend/staff/markNotificationsRead.php', {});
        if (!result.success) throw new Error('Could not update notifications');
        this.notifications = this.notifications.map(n => ({ ...n, isRead: true }));
      } catch { this.toast('Could not mark notifications as read. Please try again.', 'error'); }
    },

    startClock() {
      setInterval(() => { this.now = this.serverNow(); }, 1000);
      // Pick up changes made elsewhere (cashier clock-out, new bookings).
      setInterval(() => { if (!document.hidden && !this.busy && !this.shiftBusy) this.refresh(); }, 60000);
      document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refresh(); });
    }
  });

  const staffStore = Alpine.store('staff');
  // Live sync: reload the moment anything at this stylist's branch changes
  // on another device (cashier clock-out, new/cancelled/reassigned
  // bookings) -- function/shared/live-sync.js. Skipped while one of this
  // page's own actions is saving; that action reloads the state itself.
  staffStore.refresh().finally(() => {
    if (window.LiveSync) window.LiveSync.start({
      branch: staffStore.profile.branchKey || 'all',
      onChange: () => (staffStore.busy || staffStore.shiftBusy) ? null : staffStore.refresh()
    });
  });
  staffStore.startClock();
});
