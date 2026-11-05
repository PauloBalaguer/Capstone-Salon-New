function staffProfileApp() {
  return {
    formLoaded: false,
    form: {
      firstName: '', lastName: '', phone: '', email: '', profilePicture: '',
      bookingAlerts: true, inventoryAlerts: true, orderAlerts: true, marketingAlerts: false
    },
    passwordForm: { currentPassword: '', newPassword: '', confirmPassword: '' },
    passwordChangeStep: 'form',
    passwordChangeCode: '',
    currentPasswordError: '',
    notificationOptions: [
      { key: 'bookingAlerts', label: 'New Assigned Appointments', desc: 'Get notified when a booking is assigned to you.' },
      { key: 'inventoryAlerts', label: 'Low Stock Alerts', desc: "Get notified when your branch's supplies run low." },
      { key: 'orderAlerts', label: 'Schedule & Status Updates', desc: 'Get notified about changes to your bookings.' },
      { key: 'marketingAlerts', label: 'General Announcements', desc: 'Occasional updates from Leo Mejillano Salon.' }
    ],

    init() {
      staffHighlightNav();
      staffInitMobileNav();

      if (!Alpine.store('staff').loading) {
        this.loadFormFromStore();
      }
      this.$watch(() => Alpine.store('staff').loading, (loading) => {
        if (!loading && !this.formLoaded) this.loadFormFromStore();
      });
    },

    loadFormFromStore() {
      const { profile } = Alpine.store('staff');
      const prefs = Alpine.store('staff').notificationPrefs;
      this.form = {
        firstName: profile.firstName || '',
        lastName: profile.lastName || '',
        phone: profile.phone || '',
        email: profile.email || '',
        profilePicture: '', // no pending change yet -- display falls back to the store's saved photo
        bookingAlerts: prefs.bookingAlerts,
        inventoryAlerts: prefs.inventoryAlerts,
        orderAlerts: prefs.orderAlerts,
        marketingAlerts: prefs.marketingAlerts
      };
      this.formLoaded = true;
    },

    // Reads the chosen image as a data URI for an instant preview (bound via
    // form.profilePicture in the markup) -- it's only sent to the server
    // once submitProfile() runs, same as every other field on this form.
    handleProfilePictureUpload(event) {
      const file = event.target.files[0];
      if (!file) return;

      if (!file.type.startsWith('image/')) {
        Alpine.store('staff').toast('Please choose an image file.', 'error');
        return;
      }
      if (file.size > 2 * 1024 * 1024) {
        Alpine.store('staff').toast('Image is too large. Please choose one under 2MB.', 'error');
        return;
      }

      const reader = new FileReader();
      reader.onload = (e) => {
        this.form.profilePicture = e.target.result;
        Alpine.store('staff').toast('Photo selected. Click "Save Changes" to apply.', 'success');
      };
      reader.readAsDataURL(file);
    },

    async submitProfile() {
      // saveProfile() already awaits a full store refresh() on success, so
      // the store's profile/notificationPrefs are current by the time we
      // get here -- just re-sync the local form from them.
      const res = await Alpine.store('staff').saveProfile(this.form);
      if (res.success) this.loadFormFromStore();
    },

    // Same rules as backend/shared/config/security/PasswordPolicy.php.
    passwordRules() {
      const value = this.passwordForm.newPassword;
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

    passwordSameAsCurrent() {
      const { currentPassword, newPassword } = this.passwordForm;
      return newPassword !== '' && newPassword === currentPassword;
    },

    passwordsMismatch() {
      const { newPassword, confirmPassword } = this.passwordForm;
      return confirmPassword !== '' && newPassword !== confirmPassword;
    },

    canSubmitPassword() {
      const { currentPassword, newPassword, confirmPassword } = this.passwordForm;
      return !!currentPassword && this.passwordMeetsRules() &&!this.passwordSameAsCurrent() && newPassword === confirmPassword;
    },

    async submitPassword() {
      if (this.passwordForm.newPassword !== this.passwordForm.confirmPassword) {
        Alpine.store('staff').toast('New password and confirmation do not match.', 'error');
        return;
      }
      const res = await Alpine.store('staff').changePassword(this.passwordForm);
      this.currentPasswordError = !res.success && /current password/i.test(res.message) && !/different/i.test(res.message) ? res.message : '';
      if (res.success && res.requiresVerification) {
        this.passwordChangeCode = '';
        this.passwordChangeStep = 'verify';
      }
    },

    async submitPasswordConfirmation() {
      if (!this.passwordChangeCode.trim()) {
        Alpine.store('staff').toast('Please enter the confirmation code from your email.', 'error');
        return;
      }
      const res = await Alpine.store('staff').confirmPasswordChange({
        code: this.passwordChangeCode.trim(),
        newPassword: this.passwordForm.newPassword,
        confirmPassword: this.passwordForm.confirmPassword
      });
      if (res.success) {
        this.passwordForm = { currentPassword: '', newPassword: '', confirmPassword: '' };
        this.passwordChangeCode = '';
        this.passwordChangeStep = 'form';
      }
    },

    cancelPasswordChangeVerification() {
      this.passwordChangeStep = 'form';
      this.passwordChangeCode = '';
    }
  };
}
