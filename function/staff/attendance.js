function staffAttendanceApp() {
  return {
    historyRange: 'all',
    expandedDate: null,
    page: 1,
    pageSize: 10,

    init() {
      staffHighlightNav();
      staffInitMobileNav();
      this.$watch('historyRange', () => { this.page = 1; });
    },

    nowLabel() {
      return Alpine.store('staff').now.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    },

    clockLabel() {
      return Alpine.store('staff').now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    },

    /* Parses a "%h:%i %p"-formatted time (e.g. "02:15 PM") into minutes since midnight. */
    parseClockMinutes(label) {
      const [time, meridiem] = label.split(' ');
      let [h, m] = time.split(':').map(Number);
      if (meridiem === 'PM' && h !== 12) h += 12;
      if (meridiem === 'AM' && h === 12) h = 0;
      return h * 60 + m;
    },

    // An open session counts up to now, but only on its own day.
    sessionMinutes(row) {
      const store = Alpine.store('staff');
      const start = this.parseClockMinutes(row.clockIn);
      let end;
      if (row.clockOut) {
        end = this.parseClockMinutes(row.clockOut);
      } else if (row.date === store.todayISO()) {
        end = store.now.getHours() * 60 + store.now.getMinutes();
      } else {
        return 0;
      }
      if (end < start) end += 24 * 60;
      return end - start;
    },

    hoursLabel(minutes) {
      return (minutes / 60).toFixed(1);
    },

    // "2 mins", "2 hrs 42 mins"; under a minute reads as such instead of 0.
    durationLabel(minutes) {
      const total = Math.round(minutes);
      if (total < 1) return 'Under 1 min';
      const hours = Math.floor(total / 60);
      const mins = total % 60;
      const parts = [];
      if (hours) parts.push(`${hours} ${hours === 1 ? 'hr' : 'hrs'}`);
      if (mins) parts.push(`${mins} ${mins === 1 ? 'min' : 'mins'}`);
      return parts.join(' ');
    },

    dayMonth(iso) {
      return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short' });
    },

    dayNumber(iso) {
      return new Date(iso + 'T00:00:00').getDate();
    },

    dayWeekday(iso) {
      return new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric' });
    },

    // Same status colours as the staff booking badges.
    dayStatusClass(day) {
      if (day.isOpen) return 'bg-[#DCFCE7] text-[#166534] border border-[#86EFAC]';
      if (day.isFlagged) return 'bg-[#FEF3C7] text-[#92400E] border border-[#FCD34D]';
      return 'bg-[#F1F5F9] text-[#334155] border border-[#CBD5E1]';
    },

    // Short reason for one session, in plain words.
    sessionNote(row) {
      if (row.isOpen) return 'Still working';
      const note = row.notes || '';
      if (!note) return 'You signed out';
      if (/end-of-day/i.test(note)) return 'Ended at end-of-day close';
      if (/auto clock-out/i.test(note)) return 'Ended automatically at closing';
      return 'Ended by the cashier';
    },

    // On a day with several sign-ins, say how many actually need checking.
    reviewReason(day) {
      const flagged = day.sessions.filter(row => row.notes);
      if (day.sessions.length > 1) return `Check ${flagged.length} of ${day.sessions.length} sign-ins`;
      return flagged.length ? this.sessionNote(flagged[0]) : '';
    },

    // One day's sessions, earliest first, with its totals.
    dayGroup(date, sessions) {
      const ordered = [...sessions].sort((a, b) => this.parseClockMinutes(a.clockIn) - this.parseClockMinutes(b.clockIn));
      const last = ordered[ordered.length - 1];
      return {
        date,
        sessions: ordered,
        firstIn: ordered[0].clockIn,
        lastOut: last.clockOut || '',
        isOpen: ordered.some(row => row.isOpen),
        isFlagged: ordered.some(row => row.notes),
        minutes: ordered.reduce((sum, row) => sum + this.sessionMinutes(row), 0),
      };
    },

    dayGroups() {
      const byDate = {};
      Alpine.store('staff').attendance.forEach(row => { (byDate[row.date] = byDate[row.date] || []).push(row); });
      return Object.keys(byDate).sort().reverse().map(date => this.dayGroup(date, byDate[date]));
    },

    today() {
      const today = Alpine.store('staff').todayISO();
      return this.dayGroups().find(group => group.date === today) || null;
    },

    hoursWorkedToday() {
      const today = this.today();
      return today ? this.hoursLabel(today.minutes) : '0.0';
    },

    dateISO(date) {
      return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    },

    last30Cutoff() {
      const cutoff = new Date(Alpine.store('staff').now);
      cutoff.setDate(cutoff.getDate() - 30);
      return this.dateISO(cutoff);
    },

    daysAttendedLast30() {
      const cutoff = this.last30Cutoff();
      return this.dayGroups().filter(group => group.date >= cutoff).length;
    },

    totalHoursLast30() {
      const cutoff = this.last30Cutoff();
      const minutes = this.dayGroups().filter(group => group.date >= cutoff).reduce((sum, group) => sum + group.minutes, 0);
      return this.hoursLabel(minutes);
    },

    filteredDays() {
      const now = Alpine.store('staff').now;
      let from = '';
      if (this.historyRange === 'week') {
        const monday = new Date(now);
        monday.setDate(now.getDate() - ((now.getDay() + 6) % 7));
        from = this.dateISO(monday);
      } else if (this.historyRange === 'month') {
        from = this.dateISO(new Date(now.getFullYear(), now.getMonth(), 1));
      }
      return this.dayGroups().filter(group => !from || group.date >= from);
    },

    totalPages() {
      return Math.max(1, Math.ceil(this.filteredDays().length / this.pageSize));
    },

    // Clamped because a refresh can shrink the list while on a later page.
    currentPage() {
      return Math.min(this.page, this.totalPages());
    },

    pagedDays() {
      const start = (this.currentPage() - 1) * this.pageSize;
      return this.filteredDays().slice(start, start + this.pageSize);
    },

    pageLabel() {
      const total = this.filteredDays().length;
      const first = (this.currentPage() - 1) * this.pageSize + 1;
      return `Showing ${first}-${Math.min(first + this.pageSize - 1, total)} of ${total} days`;
    },

    toggleDay(date) {
      this.expandedDate = this.expandedDate === date ? null : date;
    }
  };
}
