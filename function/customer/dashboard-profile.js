// Profile picture upload, profile/preferences saving, and password change.

function customerProfileMixin() {
  return {
    // Add or change the profile photo. The picked image is shrunk to at most
    // 400px in the browser and saved straight away through
    // backend/customer/saveProfilePhoto.php, which re-checks it.
    async handleProfilePictureUpload(event) {
      const input = event.target;
      const file = input.files && input.files[0];
      input.value = '';
      if (!file) return;
      if (!/^image\/(png|jpeg|webp)$/.test(file.type)) {
        return this.pushToast('error', 'Please choose a PNG, JPG or WebP photo.');
      }
      if (file.size > 10 * 1024 * 1024) {
        return this.pushToast('error', 'That photo is larger than 10 MB. Please choose a smaller one.');
      }
      this.isUploadingPhoto = true;
      try {
        const dataUrl = await this.shrinkProfilePhoto(file);
        const formData = new FormData();
        formData.append('profilePicture', dataUrl);
        formData.append('csrfToken', this.csrfToken);
        const response = await fetch('../../backend/customer/saveProfilePhoto.php', { method: 'POST', body: formData, headers: { 'X-CSRF-Token': this.csrfToken } });
        const body = await response.json();
        if (!response.ok || !body.success) throw new Error(body.message || 'Could not save your photo.');
        this.customerProfile.profilePicture = body.profilePicture;
        this.pushToast('success', 'Profile photo updated.');
      } catch (error) {
        this.pushToast('error', error.message && !/JSON|fetch/i.test(error.message) ? error.message : 'Could not save your photo. Please try again.');
      } finally {
        this.isUploadingPhoto = false;
      }
    },

    shrinkProfilePhoto(file) {
      return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
          const scale = Math.min(1, 400 / Math.max(img.width, img.height));
          const canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(img.width * scale));
          canvas.height = Math.max(1, Math.round(img.height * scale));
          const ctx = canvas.getContext('2d');
          ctx.fillStyle = '#FFFFFF';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
          URL.revokeObjectURL(url);
          resolve(canvas.toDataURL('image/jpeg', 0.85));
        };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('That file could not be read as an image.')); };
        img.src = url;
      });
    },

    saveProfile() {
      // Same rule as the server (PhoneNumber.php): 09XXXXXXXXX or +639XXXXXXXXX.
      const phone = String(this.customerProfile.phone || '').replace(/[\s\-().]/g, '').match(/^(?:09|\+639)(\d{9})$/);
      if (!phone) return this.pushToast('error', 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX (11 digits starting with 09).');
      this.customerProfile.phone = '09' + phone[1];
      const formData = new FormData();
      if (!AddressForms.prepare('profile-address', formData)) return;
      for (const field of ['firstName', 'middleName', 'lastName']) {
        formData.append(field, (this.customerProfile[field] || '').trim());
      }
      formData.append('email', this.customerProfile.email);
      formData.append('phone', this.customerProfile.phone);
      formData.append('notifyEmail', this.customerProfile.notificationPrefs.email ? '1' : '0');
      formData.append('notifySms', this.customerProfile.notificationPrefs.sms ? '1' : '0');
      // The photo is saved on its own when it is picked (saveProfilePhoto.php).

      fetch('../../backend/customer/saveProfile.php', { method: 'POST', body: formData })
        .then(response => response.json().then(body => ({ status: response.status, body })))
        .then(({ body }) => {
          if (!body.success) {
            AddressForms.errors('profile-address', body.errors);
            this.pushToast('error', body.message || 'Could not save your profile.');
            return;
          }
          if (body.nameProfile) Object.assign(this.customerProfile, body.nameProfile);
          if (body.addressProfile) {
            Object.assign(this.customerProfile, body.addressProfile);
            AddressForms.setSaved(this.customerProfile);
          }
          this.pushToast('success', body.message || 'Profile and preferences updated successfully!');
        })
        .catch(() => this.pushToast('error', 'A network error occurred while saving your profile.'));
    },

    // Step 1: verifies the current password + new-password rules and, on
    // success, emails a confirmation code -- see backend/customer/changePassword.php.
    // Nothing is applied to the account yet.
    // Same rules as backend/shared/config/security/PasswordPolicy.php.
    passwordRules() {
      const value = this.passwordForm.new;
      return [
        { label: 'At least 8 characters', met: value.length >= 8 },
        { label: 'At least one uppercase letter (A–Z)', met: /[A-Z]/.test(value) },
        { label: 'At least one lowercase letter (a–z)', met: /[a-z]/.test(value) },
        { label: 'At least one number (0–9)', met: /[0-9]/.test(value) },
        { label: 'At least one special character (e.g., @, #, $, or -)', met: /[^A-Za-z0-9\s]/.test(value) }
      ];
    },

    passwordMeetsRules() {
      return this.passwordRules().every(rule => rule.met);
    },

    canSubmitPassword() {
      const { current, new: next, confirm } = this.passwordForm;
      return !!current && this.passwordMeetsRules() && next !== current && next === confirm;
    },

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
      if (!this.passwordMeetsRules()) {
        this.passwordChangeStatus.error = 'Your new password does not meet all the requirements.';
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
