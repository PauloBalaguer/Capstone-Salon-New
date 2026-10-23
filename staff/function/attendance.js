function staffAttendanceApp() {
  return {
    init() {
      staffHighlightNav();
      staffInitMobileNav();
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

    hoursBetween(date, clockInLabel, clockOutLabel) {
      if (!clockOutLabel) return '0.0';
      let startMin = this.parseClockMinutes(clockInLabel);
      let endMin = this.parseClockMinutes(clockOutLabel);
      if (endMin < startMin) endMin += 24 * 60;
      return ((endMin - startMin) / 60).toFixed(1);
    },

    todayAttendance() {
      return Alpine.store('staff').getTodayAttendance();
    },

    hoursWorkedToday() {
      const today = this.todayAttendance();
      if (!today) return '0.0';
      const clockOut = today.clockOut || Alpine.store('staff').now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: true });
      return this.hoursBetween(today.date, today.clockIn, clockOut);
    },

    daysAttendedLast30() {
      const cutoff = new Date();
      cutoff.setDate(cutoff.getDate() - 30);
      const seen = new Set();
      Alpine.store('staff').attendance.forEach(row => {
        if (new Date(row.date) >= cutoff) seen.add(row.date);
      });
      return seen.size;
    },

    totalHoursLast30() {
      const cutoff = new Date();
      cutoff.setDate(cutoff.getDate() - 30);
      let total = 0;
      Alpine.store('staff').attendance.forEach(row => {
        if (new Date(row.date) >= cutoff && row.clockOut) {
          total += parseFloat(this.hoursBetween(row.date, row.clockIn, row.clockOut));
        }
      });
      return total.toFixed(1);
    }
  };
}
