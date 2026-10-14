// Profile picture upload, profile/preferences saving, and password change.

function customerProfileMixin() {
  return {
    handleProfilePictureUpload(event) {
      const file = event.target.files[0];
      if (file && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = (e) => {
          this.customerProfile.profilePicture = e.target.result;
          this.pushToast('success', 'Profile picture updated. Click "Save" to apply changes.');
        };
        reader.readAsDataURL(file);
      }
    },

    saveProfile() {
      const formData = new FormData();
      formData.append('name', this.customerProfile.name);
      formData.append('email', this.customerProfile.email);
      formData.append('phone', this.customerProfile.phone);
      formData.append('notifyEmail', this.customerProfile.notificationPrefs.email ? '1' : '0');
      formData.append('notifySms', this.customerProfile.notificationPrefs.sms ? '1' : '0');
      if (this.customerProfile.profilePicture) {
        formData.append('profilePicture', this.customerProfile.profilePicture);
      }

      fetch('../../backend/customer/saveProfile.php', { method: 'POST', body: formData })
        .then(response => response.json().then(body => ({ status: response.status, body })))
        .then(({ body }) => {
          if (!body.success) {
            this.pushToast('error', body.message || 'Could not save your profile.');
            return;
          }
          this.pushToast('success', body.message || 'Profile and preferences updated successfully!');
        })
        .catch(() => this.pushToast('error', 'A network error occurred while saving your profile.'));
    },

    // Step 1: verifies the current password + new-password rules and, on
    // success, emails a confirmation code -- see backend/customer/changePassword.php.
    // Nothing is applied to the account yet.
    changePassword() {
      this.passwordChangeStatus = { success: '', error: '' };
      if (!this.passwordForm.current || !this.passwordForm.new || !this.passwordForm.confirm) {
        this.passwordChangeStatus.error = 'Please fill in all password fields.';
        return;
      }
      if (this.passwordForm.new !== this.passwordForm.confirm) {
        this.passwordChangeStatus.error = 'New password and confirmation do not match.';
        return;
      }
      if (this.passwordForm.new.length < 8) {
        this.passwordChangeStatus.error = 'New password must be at least 8 characters long.';
        return;
      }

      const formData = new FormData();
      formData.append('currentPassword', this.passwordForm.current);
      formData.append('newPassword', this.passwordForm.new);
      formData.append('confirmPassword', this.passwordForm.confirm);

      fetch('../../backend/customer/changePassword.php', { method: 'POST', body: formData })
        .then(response => response.json().then(body => ({ status: response.status, body })))
        .then(({ body }) => {
          if (!body.success) {
            this.passwordChangeStatus.error = body.message || 'Could not change your password.';
            return;
          }
          this.passwordChangeStatus.success = body.message || 'A confirmation code was sent to your email.';
          this.passwordChangeCode = '';
          this.passwordChangeStep = 'verify';
        })
        .catch(() => {
          this.passwordChangeStatus.error = 'A network error occurred while changing your password.';
        });
    },

    // Step 2: submits the emailed code alongside the same new password to
    // actually apply the change -- see backend/customer/confirmPasswordChange.php.
    confirmPasswordChange() {
      this.passwordChangeStatus = { success: '', error: '' };
      if (!this.passwordChangeCode.trim()) {
        this.passwordChangeStatus.error = 'Please enter the confirmation code from your email.';
        return;
      }

      const formData = new FormData();
      formData.append('code', this.passwordChangeCode.trim());
      formData.append('newPassword', this.passwordForm.new);
      formData.append('confirmPassword', this.passwordForm.confirm);

      fetch('../../backend/customer/confirmPasswordChange.php', { method: 'POST', body: formData })
        .then(response => response.json().then(body => ({ status: response.status, body })))
        .then(({ body }) => {
          if (!body.success) {
            this.passwordChangeStatus.error = body.message || 'Could not confirm your password change.';
            return;
          }
          this.passwordChangeStatus.success = body.message || 'Password updated successfully!';
          this.passwordForm = { current: '', new: '', confirm: '' };
          this.passwordChangeCode = '';
          this.passwordChangeStep = 'form';
        })
        .catch(() => {
          this.passwordChangeStatus.error = 'A network error occurred while confirming your password change.';
        });
    },

    cancelPasswordChangeVerification() {
      this.passwordChangeStep = 'form';
      this.passwordChangeCode = '';
      this.passwordChangeStatus = { success: '', error: '' };
    }
  };
}
