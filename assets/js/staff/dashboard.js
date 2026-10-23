function staffDashboardApp() {
  return {
    performanceModalOpen: false,
    loadingPerformance: false,
    monthlyTrend: [],
    allFeedback: [],
    modalAttendanceDays: 0,
    modalAttendanceRate: 0,

    mobileSearch: '',
    mobileTab: 'today',
    mobileFilterOpen: false,
    mobileIndex: 0,
    tiltStyle: 'transform: perspective(800px) rotateX(0deg) rotateY(0deg) scale3d(1,1,1);',
    touchStartX: null,
    touchStartY: null,
    mobileTabs: [
      { key: 'today', label: 'Today' },
      { key: 'upcoming', label: 'Upcoming' },
      { key: 'completed', label: 'Completed' }
    ],

    init() {
      staffHighlightNav();
      staffInitMobileNav();
      this.$watch('mobileTab', () => {
        this.mobileIndex = 0;
        this.$nextTick(() => this.playSlideAnimation('fade'));
      });
      this.$watch('mobileSearch', () => { this.mobileIndex = 0; });
    },

    async openPerformanceModal() {
      this.performanceModalOpen = true;
      this.loadingPerformance = true;
      try {
        const res = await Alpine.store('staff').post('employee/ranking.php', {});
        if (res.success) {
          this.monthlyTrend = res.monthlyTrend;
          this.allFeedback = res.allFeedback;
          this.modalAttendanceDays = res.attendanceDays;
          this.modalAttendanceRate = res.attendanceRate;
        } else {
          Alpine.store('staff').toast(res.message || 'Failed to load performance data.', 'error');
        }
      } catch (e) {
        Alpine.store('staff').toast('Network error while loading performance data.', 'error');
      } finally {
        this.loadingPerformance = false;
      }
    },

    mobileCandidates() {
      const store = Alpine.store('staff');
      let list;
      if (this.mobileTab === 'today') {
        list = store.getTodayAppointments();
      } else if (this.mobileTab === 'upcoming') {
        list = store.getUpcomingAppointments();
      } else {
        list = store.appointments
          .filter(a => ['Completed', 'Reviewed'].includes(a.status))
          .sort((a, b) => (b.date + b.time).localeCompare(a.date + a.time))
          .slice(0, 10);
      }

      const q = this.mobileSearch.trim().toLowerCase();
      if (q) list = list.filter(a => a.clientName.toLowerCase().includes(q));
      return list;
    },

    mobileFeatured() {
      const list = this.mobileCandidates();
      if (!list.length) return null;
      const idx = Math.min(this.mobileIndex, list.length - 1);
      return list[idx];
    },

    mobileHeroActionLabel() {
      const appt = this.mobileFeatured();
      if (!appt) return '';
      if (['Pending', 'Confirmed'].includes(appt.status)) return 'Start';
      if (appt.status === 'In Progress') return 'Complete';
      return 'View';
    },

    async handleMobileHeroAction() {
      const appt = this.mobileFeatured();
      if (!appt) return;
      if (['Pending', 'Confirmed'].includes(appt.status)) {
        await Alpine.store('staff').startService(appt.id);
      } else if (appt.status === 'In Progress') {
        await Alpine.store('staff').completeService(appt.id);
      } else {
        window.location.href = 'assignedAppointments.html';
      }
    },

    onHeroTouchStart(e) {
      this.touchStartX = e.touches[0].clientX;
      this.touchStartY = e.touches[0].clientY;
    },

    onHeroTouchMove(e) {
      this.tiltCard(e);
    },

    onHeroTouchEnd(e) {
      if (this.touchStartX !== null) {
        const deltaX = e.changedTouches[0].clientX - this.touchStartX;
        const deltaY = Math.abs(e.changedTouches[0].clientY - (this.touchStartY || 0));
        const list = this.mobileCandidates();
        if (Math.abs(deltaX) > 40 && deltaY < 60 && list.length > 1) {
          if (deltaX < 0) {
            this.mobileIndex = (this.mobileIndex + 1) % list.length;
            this.playSlideAnimation('right');
          } else {
            this.mobileIndex = (this.mobileIndex - 1 + list.length) % list.length;
            this.playSlideAnimation('left');
          }
        }
      }
      this.touchStartX = null;
      this.touchStartY = null;
      this.resetTilt();
    },

    // Tap a dot to jump straight to that card, sliding in from whichever
    // side matches the direction of travel (so it still reads as movement,
    // not a random re-shuffle).
    goToSlide(i) {
      const list = this.mobileCandidates();
      if (i === this.mobileIndex || i < 0 || i >= list.length) return;
      const direction = i > this.mobileIndex ? 'right' : 'left';
      this.mobileIndex = i;
      this.playSlideAnimation(direction);
    },

    // Restarts the CSS slide-in animation on the hero card by removing then
    // re-adding its class (with a forced reflow in between) -- toggling a
    // class that's already present wouldn't replay the @keyframes.
    playSlideAnimation(direction) {
      const card = document.getElementById('mobile-hero-card');
      if (!card) return;
      card.classList.remove('slide-in-left', 'slide-in-right', 'slide-in-fade');
      void card.offsetWidth;
      card.classList.add(direction === 'left' ? 'slide-in-left' : direction === 'right' ? 'slide-in-right' : 'slide-in-fade');
    },

    tiltCard(e) {
      const card = document.getElementById('mobile-hero-card');
      if (!card) return;
      const point = e.touches ? e.touches[0] : e;
      const rect = card.getBoundingClientRect();
      const x = (point.clientX - rect.left) / rect.width - 0.5;
      const y = (point.clientY - rect.top) / rect.height - 0.5;
      const rotateY = x * 10;
      const rotateX = -y * 10;
      this.tiltStyle = `transform: perspective(800px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale3d(1.02,1.02,1.02);`;
    },

    resetTilt() {
      this.tiltStyle = 'transform: perspective(800px) rotateX(0deg) rotateY(0deg) scale3d(1,1,1);';
    }
  };
}
