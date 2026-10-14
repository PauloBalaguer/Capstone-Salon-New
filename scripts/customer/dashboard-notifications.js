// Toast notifications, the notification bell panel, and simulated backend
// status updates / reminders for the customer dashboard.

function customerNotificationsMixin() {
  return {
    pushToast(type, message) {
      const id = ++this.toastSeq;
      this.toasts.push({ id, type, message });
      // Long messages (e.g. refund/cancellation details) get more reading time.
      setTimeout(() => this.dismissToast(id), Math.max(5000, Math.min(10000, message.length * 70)));
    },
    dismissToast(id) {
      this.toasts = this.toasts.filter(t => t.id !== id);
    },

    animateCount(prop, target, duration = 900) {
      const startTime = performance.now();
      const step = (now) => {
        const progress = Math.min((now - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        this[prop] = Math.round(target * eased);
        if (progress < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    },

    toggleNotificationPanel() {
      this.isNotificationPanelOpen = !this.isNotificationPanelOpen;
      if (this.isNotificationPanelOpen) this.markAllNotificationsAsRead();
    },
    markAllNotificationsAsRead() {
      if (!this.notifications.some(n => !n.read)) return;
      this.notifications.forEach(n => n.read = true);
      fetch('../../backend/customer/markNotificationsRead.php', { method: 'POST' }).catch(() => {});
    },
    clearAllNotifications() {
      this.notifications = [];
    },

    get unreadNotificationCount() {
      return this.notifications.filter(n => !n.read).length;
    },

    notificationIcons: {
      sms: 'M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z',
      email: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
      system: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
      success: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
      error: 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
      reminder: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'
    },

    addNotification(type, message) {
      const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      this.notifications.unshift({ type, message, time, read: false, icon: this.notificationIcons[type] || this.notificationIcons['system'] });
    },

    // Maps a backend notification "type" (see backend/config/CustomerNotifier.php
    // -- BOOKING_SUBMITTED, PAYMENT_SUBMITTED, PAYMENT_VERIFIED,
    // PAYMENT_ATTENTION, CONFIRMED, REMINDER, RESCHEDULE, CANCELLED,
    // COMPLETED, PAYMENT_FORFEITED, REFUND_DUE, PAYMENT_REFUNDED,
    // WAITLIST_OPENING) onto one of the existing bell icons, so real backend events
    // render with the same look as the client-simulated ones above.
    serverNotificationIconFor(type) {
      const map = {
        BOOKING_SUBMITTED: 'success', PAYMENT_SUBMITTED: 'system', PAYMENT_VERIFIED: 'success',
        PAYMENT_ATTENTION: 'error', CONFIRMED: 'success', REMINDER: 'reminder',
        RESCHEDULE: 'system', CANCELLED: 'error', COMPLETED: 'success',
        PAYMENT_FORFEITED: 'error', REFUND_DUE: 'system', PAYMENT_REFUNDED: 'success', WAITLIST_OPENING: 'reminder'
      };
      return map[type] || 'system';
    },

    // Replaces the boot-time placeholder notifications list with the real,
    // persisted ones from getDashboardData.php once the dashboard has
    // loaded. Client-only entries added later this session (e.g. the
    // instant "booking received" toast in sendBookingConfirmation) are
    // still appended live via addNotification as before.
    loadServerNotifications(rows) {
      this.notifications = (rows || []).map(row => ({
        id: row.id,
        type: row.type,
        message: row.message,
        time: row.time,
        read: row.isRead,
        icon: this.notificationIcons[this.serverNotificationIconFor(row.type)]
      }));
    },

    sendBookingConfirmation(appointment) {
      this.addNotification('success', `Booking request ${appointment.id} received.`);
      if (this.customerProfile.notificationPrefs.email) {
        this.addNotification('email', `Booking confirmation sent to ${this.customerProfile.email} for appointment ${appointment.id}.`);
      }
      if (this.customerProfile.notificationPrefs.sms) {
        this.addNotification('sms', `Booking confirmation sent to ${this.customerProfile.phone} for appointment ${appointment.id}.`);
      }
    },

    checkForReminders() {
      // Needs getUpcomingAppointments(), which only exists on pages that
      // also load dashboard-appointments.js.
      if (!this.getUpcomingAppointments) return;

      const now = new Date();
      const reminderThreshold = 24 * 60 * 60 * 1000;
      this.getUpcomingAppointments().forEach(appt => {
        if (appt.reminderSent || !['Pending', 'Confirmed'].includes(appt.status)) return;
        const apptDateTime = parseApptDateTime(appt.date, appt.time);
        const timeDiff = apptDateTime.getTime() - now.getTime();
        if (timeDiff > 0 && timeDiff <= reminderThreshold) {
          this.sendAppointmentReminder(appt);
          appt.reminderSent = true;
        }
      });
    },
    sendAppointmentReminder(appointment) {
      this.addNotification('reminder', `Reminder: Your appointment ${appointment.id} for "${appointment.serviceName}" is tomorrow at ${appointment.time}.`);
    }
  };
}
