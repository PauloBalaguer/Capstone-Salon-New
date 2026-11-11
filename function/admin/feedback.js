function adminFeedbackApp() {
  return {
    feedbackFilterBranch: AdminUI.param('branch') || 'all',
    feedbackFilterRating: 'all', // 'all' | '1'..'5' | 'low' (3 and below)
    feedbackFilterRead: 'all',   // 'all' | 'unread' | 'read'
    busyId: null,

    init() {
      adminHighlightNav();
    },

    getStaffName(staffId) {
      const staff = Alpine.store('admin').getStaffMember(staffId);
      return staff ? staff.name : 'Unassigned';
    },

    // "Read" uses the existing feedback.admin_status column:
    // New = unread; Reviewed or Resolved = read.
    isUnread(review) {
      return !review.adminStatus || review.adminStatus === 'New';
    },

    getFilteredFeedback() {
      let list = Alpine.store('admin').feedback;
      if (this.feedbackFilterBranch === 'off-site') list = list.filter(f => !f.branchId);
      else if (this.feedbackFilterBranch !== 'all') list = list.filter(f => f.branchId === this.feedbackFilterBranch);
      if (this.feedbackFilterRating === 'low') list = list.filter(f => f.rating <= 3);
      else if (this.feedbackFilterRating !== 'all') list = list.filter(f => f.rating === Number(this.feedbackFilterRating));
      if (this.feedbackFilterRead === 'unread') list = list.filter(f => this.isUnread(f));
      else if (this.feedbackFilterRead === 'read') list = list.filter(f => !this.isUnread(f));
      // Newest first: by review date, then by id (newer rows have larger ids).
      return [...list].sort((a, b) => String(b.date).localeCompare(String(a.date)) || Number(b.id) - Number(a.id));
    },

    // Only ever writes admin_status -- the customer's own rating/comment
    // columns are never touched from here (see backend/admin/setFeedbackStatus.php).
    async setFeedbackStatus(review, status) {
      const store = Alpine.store('admin');
      this.busyId = review.id;
      try {
        const result = await store.post('setFeedbackStatus.php', { id: review.id, status });
        if (!result.success) {
          LMToast.show(result.message || 'Failed to update review status.', 'error');
          return;
        }
        await store.refresh();
      } catch (e) {
        LMToast.show('A network error occurred. Please try again.', 'error');
      } finally {
        this.busyId = null;
      }
    }
  };
}
