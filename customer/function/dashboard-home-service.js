// Home service request modal and status badge styling.

// Estimated reservation fee (DP) shown while filling in the request. Mirrors
// the wedding_packages table: a package with a fixed reservation fee uses it,
// otherwise ~30% of the package price (rounded to ₱100). The admin sets the
// final DP with the quote; it's paid online via PayMongo after that.
const HS_DP_ESTIMATE_RATE = 0.30;
const HS_WEDDING_PACKAGES = { A: { price: 5000, fee: null }, B: { price: 8000, fee: null }, C: { price: 10000, fee: null }, D: { price: 12000, fee: 2000 } };

function customerHomeServiceMixin() {
  return {
    homeServiceError: '',
    homeServiceDetails(request) {
      const text = request.requests || '';
      const value = label => text.split('\n').find(line => line.toLowerCase().startsWith(label.toLowerCase() + ':'))?.slice(label.length + 1).trim() || '';
      const originalPrice = value('Package Price').replace(/[^0-9.]/g, '');
      const other = text.split('\n').filter(line => !/^(Number of clients:|Wedding Package |Package Price:|Reservation Fee:|Remaining Balance:|Remaining balance shown|Requested services:|Venue details:|Additional requirements:|Email:)/i.test(line)).join('\n').trim();
      return { clients:value('Number of clients'), services:value('Requested services'), venue:value('Venue details'), notes:[value('Additional requirements'),other].filter(Boolean).join('\n'), originalPrice:originalPrice ? Number(originalPrice) : null };
    },
    homeServiceQuoteChanged(request) {
      const original = this.homeServiceDetails(request).originalPrice;
      return original !== null && request.quotePrice != null && Math.abs(Number(request.quotePrice) - original) > .01;
    },
    homeServiceDate(request) {
      if (!request.preferredDate) return 'Not scheduled';
      const date = new Date(request.preferredDate + 'T00:00:00+08:00');
      return Number.isNaN(date.getTime()) ? request.preferredDate : new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric' }).format(date);
    },
    homeServiceTime(request) {
      if (!request.preferredTime) return 'Not set';
      const date = new Date('2000-01-01T' + request.preferredTime + '+08:00');
      return Number.isNaN(date.getTime()) ? request.preferredTime : new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' }).format(date);
    },
    homeServiceSchedule(request) {
      if (!request.preferredDate) return 'Not scheduled';
      const date = new Date(request.preferredDate + 'T' + (request.preferredTime || '00:00:00') + '+08:00');
      if (Number.isNaN(date.getTime())) return request.preferredDate;
      return new Intl.DateTimeFormat('en-US', {timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',...(request.preferredTime ? {hour:'numeric',minute:'2-digit'} : {})}).format(date) + ' (PH)';
    },
    homeServiceFutureCompleted(request) {
      return request.status === 'Completed' && new Date(request.preferredDate + 'T' + (request.preferredTime || '00:00:00') + '+08:00').getTime() > Date.now();
    },
    homeServicePaymentLabel(request) {
      return request.paymentStatus === 'Down Payment Verified' ? 'Deposit verified' : request.paymentStatus || 'Not recorded';
    },
    eventAvailabilityLoading: false,
    homeServiceAvailability: null,
    eventAvailabilityMessage: '',
    eventAvailabilityRequest: 0,
    async refreshEventAvailability() {
      const seq = ++this.eventAvailabilityRequest;
      this.homeServiceAvailability = null;
      this.eventAvailabilityMessage = '';
      this.eventAvailabilityLoading = false;
      const f = this.homeServiceForm;
      if (!f.preferredDate || !f.preferredTime || (f.eventType === 'Wedding' ? !f.weddingPackage : !f.services.length)) return;
      const params = new URLSearchParams({date:f.preferredDate,time:f.preferredTime,eventType:f.eventType === 'Other' ? f.otherEventType : f.eventType,weddingPackage:f.weddingPackage});
      f.services.forEach(s => params.append('services[]',s));
      this.eventAvailabilityLoading = true;
      try {
        const response = await fetch('../../public/backend/getHomeServiceAvailability.php?' + params);
        const body = await response.json();
        if (seq !== this.eventAvailabilityRequest) return;
        this.homeServiceAvailability = body.success ? body.available : null;
        this.eventAvailabilityMessage = body.message || 'Availability could not be checked. Please try again.';
      } catch (_) {
        if (seq === this.eventAvailabilityRequest) this.eventAvailabilityMessage = 'Availability could not be checked. We will check again when you submit.';
      } finally { if (seq === this.eventAvailabilityRequest) this.eventAvailabilityLoading = false; }
    },
    hsEstimatedDp() {
      const form = this.homeServiceForm || {};
      if (form.eventType !== 'Wedding') return null;
      const pkg = HS_WEDDING_PACKAGES[form.weddingPackage];
      if (!pkg) return null;
      if (pkg.fee) return { amount: pkg.fee, fixed: true, price: pkg.price };
      return { amount: Math.round((pkg.price * HS_DP_ESTIMATE_RATE) / 100) * 100, fixed: false, price: pkg.price };
    },

    // Online reschedule (customer/backend/rescheduleHomeService.php): one
    // request's inline form is open at a time.
    hsRescheduleId: null,
    hsRescheduleDate: '',
    hsRescheduleTime: '09:00',
    hsRescheduling: false,
    hsRescheduleMin(request) {
      const min = new Date(Date.now() + (request.rescheduleCutoffDays || 3) * 86400000);
      return [min.getFullYear(), String(min.getMonth() + 1).padStart(2, '0'), String(min.getDate()).padStart(2, '0')].join('-');
    },
    openHomeServiceReschedule(request) {
      this.hsRescheduleId = request.id;
      this.hsRescheduleDate = '';
      this.hsRescheduleTime = (request.preferredTime || '09:00').slice(0, 5);
    },
    submitHomeServiceReschedule(request) {
      if (!this.hsRescheduleDate || !this.hsRescheduleTime) return this.pushToast('error', 'Please choose a new date and time.');
      this.hsRescheduling = true;
      const formData = new FormData();
      formData.append('id', request.id);
      formData.append('date', this.hsRescheduleDate);
      formData.append('time', this.hsRescheduleTime);
      fetch('../../customer/backend/rescheduleHomeService.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(body => {
          if (!body.success) return this.pushToast('error', body.message || 'Could not reschedule this request.');
          request.preferredDate = body.date;
          request.preferredTime = body.time;
          request.reschedulesLeft = Math.max(0, (request.reschedulesLeft || 1) - 1);
          if (request.reschedulesLeft === 0) { request.canReschedule = false; request.rescheduleNote = 'No online changes left. Please contact the branch to change it again.'; }
          this.hsRescheduleId = null;
          this.pushToast('success', body.message || 'Your home service has been rescheduled.');
        })
        .catch(() => this.pushToast('error', 'A network error occurred while rescheduling.'))
        .finally(() => { this.hsRescheduling = false; });
    },

    // Maps the internal `home_service_requests.status` enum values
    // ('Pending Review'/'Confirmed'/'Completed'/'Cancelled') to
    // customer-friendly display labels. Display-only -- the internal
    // values are unchanged and still drive the admin-side workflow
    // (see admin/backend/updateBookingStatus.php).
    getHomeServiceStatusLabel(status) {
      const labels = {
        'Pending Review': 'Pending Review',
        'Payment Required': 'Awaiting Payment',
        'Payment Being Verified': 'Payment / Schedule Under Review',
        'Quote Ready': 'Quotation Ready',
        'Staff Assigned': 'Confirmed — Team Assigned',
        'Confirmed': 'Confirmed',
        'Completed': 'Completed',
        'Cancelled': 'Cancelled'
      };
      return labels[status] || status;
    },

    getHomeServiceStatusBadgeClass(status) {
      const classes = {
        'Pending Review': 'bg-amber-950 text-amber-400 border border-amber-900/60',
        'Confirmed': 'bg-indigo-950 text-indigo-400 border border-indigo-900/60',
        'Completed': 'bg-emerald-950 text-emerald-400 border border-emerald-900/60',
        'Cancelled': 'bg-rose-950 text-rose-400 border border-rose-900/60'
      };
      return classes[status] || 'bg-slate-800 text-slate-300';
    },

    openHomeServiceModal() {
      AddressForms.defaultService();
      this.homeServiceError = '';
      this.homeServiceForm.preferredDate = getLocalISODate();
      this.refreshEventAvailability();
      this.isHomeServiceSuccess = false;
      this.isHomeServiceModalOpen = true;
    },
    closeHomeServiceModal() {
      if (this.isSubmittingHomeService) return;
      this.isHomeServiceModalOpen = false;
      this.homeServiceForm = { address: '', eventType: 'Standard Home Service', otherEventType: '', weddingPackage: '', clients: '', services: [], venueDetails: '', preferredDate: getLocalISODate(), preferredTime: '', requests: '', agreedToTerms: false };
    },

    submitHomeServiceRequest() {
      if (this.isSubmittingHomeService) return;
      this.homeServiceError = '';
      const formData = new FormData();
      if (!AddressForms.prepare('service-address', formData)) return;
      if (!BookingFormValidation.fields([
        ['[x-model="homeServiceForm.address"]', 'address'],
        ['[x-model="homeServiceForm.clients"]', 'quantity'],
        ['[x-model="homeServiceForm.preferredDate"]', 'date'],
        ['[x-model="homeServiceForm.preferredTime"]', 'time']
      ])) return;
      if (!this.homeServiceForm.address || !this.homeServiceForm.preferredDate || !this.homeServiceForm.preferredTime) {
        return this.pushToast('error', 'Please provide your address, preferred date, and preferred time.');
      }
      const eventType = this.homeServiceForm.eventType === 'Other' ? this.homeServiceForm.otherEventType.trim() : this.homeServiceForm.eventType;
      if (!eventType) return this.pushToast('error', 'Please specify the event type.');
      if (this.homeServiceForm.eventType === 'Wedding' && !this.homeServiceForm.weddingPackage) {
        return this.pushToast('error', 'Please choose a wedding package.');
      }
      if (!this.homeServiceForm.agreedToTerms) return this.pushToast('error', 'Please agree to the Terms & Conditions to continue.');

      if (!Number.isInteger(Number(this.homeServiceForm.clients)) || Number(this.homeServiceForm.clients) < 1) return this.pushToast('error', 'Please enter the number of clients (at least 1).');
      if (this.homeServiceForm.eventType !== 'Wedding' && !this.homeServiceForm.services.length) return this.pushToast('error', 'Please select the services you need.');
      if (!Number.isFinite(new Date(this.homeServiceForm.preferredDate + 'T' + this.homeServiceForm.preferredTime + ':00+08:00').getTime()) || new Date(this.homeServiceForm.preferredDate + 'T' + this.homeServiceForm.preferredTime + ':00+08:00').getTime() <= Date.now()) return this.pushToast('error', 'Please choose a future date and time.');
      this.isSubmittingHomeService = true;

      formData.append('address', this.homeServiceForm.address);
      formData.append('eventType', eventType);
      formData.append('preferredDate', this.homeServiceForm.preferredDate);
      formData.append('preferredTime', this.homeServiceForm.preferredTime);
      formData.append('requests', this.homeServiceForm.requests);
      formData.append('clients', this.homeServiceForm.clients);
      formData.append('venueDetails', this.homeServiceForm.venueDetails);
      this.homeServiceForm.services.forEach(service => formData.append('services[]', service));
      if (this.homeServiceForm.eventType === 'Wedding') {
        formData.append('weddingPackage', this.homeServiceForm.weddingPackage);
      }
      formData.append('agreedToTerms', this.homeServiceForm.agreedToTerms ? '1' : '0');

      fetch('../../customer/backend/submitHomeServiceRequest.php', { method: 'POST', body: formData })
        .then(response => response.json().then(body => ({ status: response.status, body })))
        .then(({ body }) => {
          this.isSubmittingHomeService = false;

          if (!body.success) {
            this.homeServiceError = body.message || 'Could not submit your home service request. Your details are still here.';
            AddressForms.errors('service-address', body.errors);
            this.pushToast('error', body.message || 'Could not submit your home service request.');
            return;
          }

          this.homeServiceRequests.unshift(body.request);
          this.addNotification('success', `Your home service request ${body.request.id} has been received. We will contact you at ${this.customerProfile.phone} about availability, pricing, staff, and any required payment.`);
          this.isHomeServiceModalOpen = false;
          this.isHomeServiceSuccess = true;
          this.homeServiceForm = { address: '', eventType: 'Standard Home Service', otherEventType: '', weddingPackage: '', clients: '', services: [], venueDetails: '', preferredDate: getLocalISODate(), preferredTime: '', requests: '', agreedToTerms: false };
          this.pushToast('success', body.message || 'Home service request submitted!');
        })
        .catch(() => {
          this.isSubmittingHomeService = false;
          this.homeServiceError = 'Could not send your request. Your details are saved here. Check your connection and try again.';
          this.pushToast('error', 'A network error occurred while submitting your request.');
        });
    }
  };
}
