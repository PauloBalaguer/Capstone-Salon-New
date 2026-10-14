// Review modal (create + edit) for completed appointments.

function customerReviewsMixin() {
  return {
    openReviewModal(appointment) {
      if (appointment.status === 'Reviewed') {
        const review = this.myReviews.find(r => r.appointmentId === appointment.id);
        if (review) this.openEditReviewModal(review);
        else this.pushToast('error', 'Your review data could not be found. Please try again later.');
        return;
      }
      if (appointment.status !== 'Completed') return this.pushToast('error', `You can only review 'Completed' appointments. This one is '${appointment.status}'.`);

      this.isEditingReview = false;
      this.reviewForm = { id: null, appointmentId: appointment.id, serviceName: appointment.serviceName, staffName: appointment.staffName, rating: 5, comment: '' };
      this.isReviewModalOpen = true;
    },

    openEditReviewModal(review) {
      this.isEditingReview = true;
      this.reviewForm = { id: review.id, appointmentId: review.appointmentId, serviceName: review.serviceName, staffName: review.staffName, rating: review.rating, comment: review.comment };
      this.isReviewModalOpen = true;
    },

    closeReviewModal() {
      this.isReviewModalOpen = false;
    },

    submitReview() {
      const formData = new FormData();
      formData.append('rating', this.reviewForm.rating);
      formData.append('comment', this.reviewForm.comment);

      if (this.isEditingReview) {
        formData.append('mode', 'edit');
        formData.append('reviewId', this.reviewForm.id);
      } else {
        if (!this.reviewForm.appointmentId) return;
        formData.append('mode', 'create');
        formData.append('appointmentId', this.reviewForm.appointmentId);
      }

      fetch('../../backend/customer/submitReview.php', { method: 'POST', body: formData })
        .then(response => response.json().then(body => ({ status: response.status, body })))
        .then(({ body }) => {
          if (!body.success) {
            this.pushToast('error', body.message || 'Could not save your review.');
            return;
          }

          if (body.mode === 'edit') {
            const reviewToUpdate = this.myReviews.find(r => r.id === body.review.id);
            if (reviewToUpdate) Object.assign(reviewToUpdate, body.review);
          } else {
            this.myReviews.unshift(body.review);
            const appt = this.appointments.find(a => a.id === body.review.appointmentId);
            if (appt) appt.status = 'Reviewed';
          }

          this.pushToast('success', body.message || 'Thank you for your feedback!');
          this.isReviewModalOpen = false;
        })
        .catch(() => this.pushToast('error', 'A network error occurred while saving your review.'));
    }
  };
}
