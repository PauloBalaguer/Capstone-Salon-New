function adminFeedbackApp() {
  return {
    feedbackFilterBranch: 'all',
    feedbackFilterRating: 0,

    init() {
      adminHighlightNav();
    },

    getStaffName(staffId) {
      const store = Alpine.store('admin');
      const staff = store.getStaffMember(staffId);
      return staff ? staff.name : 'Unassigned';
    },

    getFilteredFeedback() {
      const store = Alpine.store('admin');
      let list = store.feedback;
      if (this.feedbackFilterBranch !== 'all') list = list.filter(f => f.branchId === this.feedbackFilterBranch);
      if (this.feedbackFilterRating === 5) list = list.filter(f => f.rating === 5);
      else if (this.feedbackFilterRating === 4) list = list.filter(f => f.rating === 4);
      else if (this.feedbackFilterRating === 3) list = list.filter(f => f.rating <= 3);
      return [...list].sort((a, b) => (a.date < b.date ? 1 : -1));
    },

    // Only ever writes admin_status -- the customer's own rating/comment
    // columns are never touched from here (see backend/admin/setFeedbackStatus.php).
    async setFeedbackStatus(review, status) {
      const store = Alpine.store('admin');
      const result = await store.post('setFeedbackStatus.php', { id: review.id, status });
      if (!result.success) {
        alert(result.message || 'Failed to update review status.');
        return;
      }
      await store.refresh();
    }
  };
}
