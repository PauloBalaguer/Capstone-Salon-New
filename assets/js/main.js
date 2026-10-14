  function switchTab(tabId) {
    // Stop gallery previews when the visitor leaves the Gallery tab.
    if (tabId !== 'gallery-tab') {
      document.querySelectorAll('#gallery-tab video').forEach(video => {
        video.pause();
        video.currentTime = 0;
      });
    }

    document.querySelectorAll('.app-page').forEach(page => {
      page.classList.remove('active-page');
    });

    document.querySelectorAll('.nav-tab-link').forEach(link => {
      link.classList.remove('active-tab');
    });

    const activePage = document.getElementById(tabId);
    if(activePage) {
      activePage.classList.add('active-page');
    }

    const targetNav = document.getElementById('nav-' + tabId);
    if(targetNav) {
      targetNav.classList.add('active-tab');
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  const menuBtn = document.getElementById('menuBtn');
  const mobileNav = document.getElementById('mobileNav');
  function setMobileNav(open){
    if (!mobileNav || !menuBtn) return;
    mobileNav.classList.toggle('hidden', !open);
    mobileNav.classList.toggle('flex', open);
    menuBtn.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
  }
  if (menuBtn && mobileNav) {
    menuBtn.addEventListener('click', () => setMobileNav(mobileNav.classList.contains('hidden')));
  }

  /* If the phone is rotated (or the window widened) while the menu is open,
     the menu is hidden by the stylesheet but the page would stay locked,
     so it is closed properly here. */
  window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024 && mobileNav && !mobileNav.classList.contains('hidden')) {
      setMobileNav(false);
    }
  });

  window.addEventListener('scroll', () => {
    const winScroll = document.body.scrollTop || document.documentElement.scrollTop;
    const height = Math.max(1, document.documentElement.scrollHeight - document.documentElement.clientHeight);
    const progressBar = document.getElementById('scroll-progress-bar');
    if (progressBar) progressBar.style.width = Math.min(100, Math.max(0, (winScroll / height) * 100)) + '%';
    document.getElementById('siteHeader').classList.toggle('scrolled', window.scrollY > 12);
  });

  const PRODUCT_CATEGORIES = [
    'Hair Care', 'Hair Color & Chemical', 'Hair Treatment', 'Nail Care', 'Nail Art',
    'Lash', 'Brow', 'Makeup', 'Skin Care', 'Facial Care',
    'Waxing', 'Salon Consumables', 'Cleaning & Sanitization'
  ];

  const PRODUCT_SUGGESTIONS = {
    'Hair Care': ['Shampoo', 'Conditioner', 'Hair Serum', 'Hair Spray'],
    'Hair Color & Chemical': ['Hair Dye', 'Bleach Powder', 'Developer', 'Rebonding Cream'],
    'Hair Treatment': ['Keratin Treatment', 'Hair Mask', 'Hot Oil Treatment'],
    'Nail Care': ['Nail Polish', 'Base Coat', 'Top Coat', 'Cuticle Oil'],
    'Nail Art': ['Nail Gems', 'Glitter', 'Stickers', 'Acrylic Powder'],
    'Lash': ['False Eyelashes', 'Lash Glue', 'Lash Serum'],
    'Brow': ['Eyebrow Pencil', 'Brow Gel', 'Brow Tint'],
    'Makeup': ['Foundation', 'Lipstick', 'Blush', 'Setting Spray'],
    'Skin Care': ['Facial Cleanser', 'Moisturizer', 'Sunscreen'],
    'Facial Care': ['Facial Mask', 'Toner', 'Facial Scrub'],
    'Waxing': ['Hot Wax', 'Cold Wax Strips', 'Soothing Gel'],
    'Salon Consumables': ['Cotton Balls', 'Gloves', 'Tissues', 'Shower Caps'],
    'Cleaning & Sanitization': ['Alcohol', 'Disinfectant Spray', 'Tool Sanitizer']
  };

  const PRODUCT_DATA = Object.entries(PRODUCT_SUGGESTIONS).flatMap(([cat, products]) =>
    products.map(name => ({ name, cat, img: '' }))
  );

  let activeProdCat = 'all';

  function renderProducts() {
    const catBox = document.getElementById('prodCats');
    const grid = document.getElementById('prodGrid');
    const empty = document.getElementById('prodEmpty');
    if (!catBox || !grid) return;

    catBox.innerHTML = PRODUCT_CATEGORIES.map(c => {
      const on = activeProdCat === c;
      const stateClass = on ? 'border-copper bg-copper/12 text-copper-dark' : 'border-bronze/30 bg-sand-100 text-ink hover:border-plum';
      const labelClass = on ? 'text-copper-dark/70' : 'text-ink/35';
      return '<button onclick="setProdCat(\'' + c.replace(/'/g, "\\'") + '\')" class="rounded-2xl p-3 text-left border transition ' +
        stateClass + '">' +
        '<span class="block text-xs font-bold leading-snug">' + c + '</span>' +
        '<span class="block text-[10px] font-bold uppercase tracking-widest mt-1 ' + labelClass + '">' +
        'In stock at branch</span></button>';
    }).join('');

    const list = activeProdCat === 'all' ? PRODUCT_DATA : PRODUCT_DATA.filter(p => p.cat === activeProdCat);
    grid.innerHTML = list.map(p => {
      const visual = p.img
        ? '<img src="' + p.img + '" class="w-3/4 max-h-full object-contain" alt="' + p.name + '">'
        : '<div class="w-20 h-20 rounded-full bg-copper/10 text-copper-dark flex items-center justify-center font-display text-3xl" aria-hidden="true">' + p.name.charAt(0) + '</div>';
      return '<div class="group text-center" data-product="' + p.name + '">' +
        '<div class="bg-sand-100 rounded-3xl p-6 aspect-square flex items-center justify-center border border-bronze/30 shadow-sm mb-4 group-hover:-translate-y-2 transition-all duration-300">' +
          visual +
        '</div>' +
        '<span class="text-[10px] font-bold uppercase tracking-widest text-copper">' + p.cat + '</span>' +
        '<h4 class="font-bold text-sm text-ink mt-1">' + p.name + '</h4>' +
      '</div>';
    }).join('');

    const isEmpty = list.length === 0;
    grid.classList.toggle('hidden', isEmpty);
    empty.classList.toggle('hidden', !isEmpty);
    if (isEmpty) {
      document.getElementById('prodEmptyLead').textContent = activeProdCat === 'all'
        ? 'Our retail line-up is being updated.'
        : 'No items listed under ' + activeProdCat + ' yet.';
    }
  }

  function setProdCat(c) {
    // Tapping the active category again clears the filter and shows every product.
    activeProdCat = (activeProdCat === c) ? 'all' : c;
    renderProducts();
  }

  function showToast(msg) {
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.classList.remove('opacity-0');
    toast.classList.add('opacity-100');
    setTimeout(() => {
      toast.classList.remove('opacity-100');
      toast.classList.add('opacity-0');
    }, 2500);
  }

  const bookingModalOverlay = document.getElementById('bookingModalOverlay');
  const bookingModalBox = document.getElementById('bookingModalBox');
  const closeBooking = document.getElementById('closeBooking');
  const bookingModalForm = document.getElementById('bookingModalForm');

  function openBookingModal() {
    bookingModalOverlay.classList.remove('opacity-0', 'pointer-events-none');
    bookingModalBox.classList.remove('scale-95');
    bookingModalBox.scrollTop = 0;
    document.getElementById('bookingAccessPanel').classList.remove('hidden');
    document.getElementById('bookingFormPanel').classList.add('hidden');
    document.getElementById('bookingOtpPanel').classList.add('hidden');
    document.getElementById('bookingConfirmPanel').classList.add('hidden');
    document.getElementById('bookingReviewPanel').classList.add('hidden');
    if (typeof resetSlotGrid === 'function') resetSlotGrid();
    if (typeof resetStaffList === 'function') resetStaffList();
    if (typeof resetServiceList === 'function') resetServiceList();
    if (typeof resetBookOtpInputs === 'function') resetBookOtpInputs();
    clearInterval(bookOtpCooldownTimer);
    bookOtpCooldownTimer = null;
    pendingBooking = null;
    const banner = document.getElementById('bookingService');
    if (banner) { banner.classList.add('hidden'); banner.classList.remove('flex'); }
    currentBooking = { service: '', price: '' };
    const branchSelect = document.getElementById('bookBranchSelect');
    branchSelect.value = '';
    branchSelect.disabled = false;
    document.getElementById('bookBranchLockNote')?.classList.add('hidden');
    bookDepositLocked = false;
    bookDepositInfo = { amount: 0, method: '', reference: '' };
    document.getElementById('bookDepositForm').classList.remove('hidden');
    document.getElementById('bookDepositLocked').classList.add('hidden');
    document.getElementById('bookDepositLocked').classList.remove('flex');
    document.getElementById('bookDepositMethod').value = '';
    document.getElementById('bookDepositCashNote').classList.add('hidden');
    document.getElementById('bookDepositOnlineNote').classList.add('hidden');
    document.getElementById('bookPaymentPlanWrap').classList.add('hidden');
    const depositPlanRadio = document.querySelector('input[name="bookPaymentPlan"][value="deposit"]');
    if (depositPlanRadio) depositPlanRadio.checked = true;
    document.getElementById('bookDepositRequired').textContent = '—';
    bookReservationQuote = null;
    bookQuoteRequest++;
    document.getElementById('bookDepositGate').classList.add('hidden');
    document.getElementById('bookScheduleSection').classList.remove('hidden');
    const dateEl = document.getElementById('bookDate');
    dateEl.value = '';
    if (dateEl && !dateEl.min) {
      const now = new Date();
      const localToday = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
      dateEl.min = localToday;
    }
  }

  function continueAsGuestBooking() {
    if (bookingModalOverlay.classList.contains('opacity-0')) openBookingModal();
    document.getElementById('bookingAccessPanel').classList.add('hidden');
    document.getElementById('bookingFormPanel').classList.remove('hidden');
    document.getElementById('bookingModeText').textContent = 'Guest booking — just your name and mobile number are required.';
    document.getElementById('bookingModeBadge').textContent = 'Guest';
    document.getElementById('bookingModeBadge').className = 'shrink-0 rounded-full bg-copper/10 border border-copper/25 px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-copper-dark';
    document.getElementById('bookingModalBox').scrollTop = 0;
  }

  function closeBookingModal() {
    bookingModalOverlay.classList.add('opacity-0', 'pointer-events-none');
    bookingModalBox.classList.add('scale-95');
  }
  closeBooking?.addEventListener('click', closeBookingModal);

  function bookService(name, price, branch, lockNote) {
    openBookingModal();
    const banner = document.getElementById('bookingService');
    if (name) {
      currentBooking = { service: name, price: price || '' };
      document.getElementById('bookingServiceName').textContent = name;
      document.getElementById('bookingServicePrice').textContent = price || '';
      banner.classList.remove('hidden');
      banner.classList.add('flex');
    }
    if (branch) {
      const id = (typeof BRANCH_ID_BY_KEY !== 'undefined' && BRANCH_ID_BY_KEY[branch]) || '';
      const select = document.getElementById('bookBranchSelect');
      select.value = id;
      select.dispatchEvent(new Event('change'));
      // A service picked from a branch's menu is priced for that branch,
      // so switching branches would leave the banner pointing at the wrong
      // menu entry -- lock the branch to the one the service came from.
      if (name && id) {
        select.disabled = true;
        const note = document.getElementById('bookBranchLockNote');
        note.textContent = lockNote || (name + ' is booked at this branch.');
        note.classList.remove('hidden');
      }
    }
    // A caller that already knows what/where to book (a specific service,
    // stylist, or branch) has no use for the guest-vs-Client-Portal choice
    // screen -- openBookingModal() above always lands there first, so jump
    // straight past it to the actual form where the branch above shows up.
    continueAsGuestBooking();
  }

  // Wedding Packages — rendered from backend/public/getWeddingPackages.php
  // so admins can edit/add packages from the admin panel.
  const WEDDING_PACKAGE_STYLES = {
    Plain: {
      figure: 'bg-panel rounded-3xl p-7 flex flex-col border border-bronze/20 shadow-sm',
      label: 'text-[10px] font-bold uppercase tracking-widest text-ink/40',
      price: 'font-display text-3xl text-ink mt-2 mb-5',
      list: 'text-[13px] text-ink/65 leading-relaxed flex-1 space-y-3',
      dot: 'shrink-0 w-1.5 h-1.5 rounded-full bg-copper mt-1.75',
      badge: 'mt-5 inline-flex self-start items-center gap-2 rounded-full bg-copper/12 text-copper-dark text-[11px] font-bold uppercase tracking-widest px-3.5 py-1.5',
      button: 'mt-6 rounded-full border border-ink/15 text-[12px] font-bold uppercase tracking-widest py-3 hover:border-plum hover:text-plum transition'
    },
    Highlight: {
      figure: 'bg-copper/12 rounded-3xl p-7 flex flex-col border border-copper/30 shadow-sm relative',
      label: 'text-[10px] font-bold uppercase tracking-widest text-copper-dark',
      price: 'font-display text-3xl text-ink mt-2 mb-5',
      list: 'text-[13px] text-ink/65 leading-relaxed flex-1 space-y-3',
      dot: 'shrink-0 w-1.5 h-1.5 rounded-full bg-copper mt-1.75',
      badge: 'mt-5 inline-flex self-start items-center gap-2 rounded-full bg-copper/12 text-copper-dark text-[11px] font-bold uppercase tracking-widest px-3.5 py-1.5',
      button: 'mt-6 rounded-full bg-copper text-white text-[12px] font-bold uppercase tracking-widest py-3 hover:brightness-110 transition'
    },
    Premium: {
      figure: 'bg-plum text-white rounded-3xl p-7 flex flex-col shadow-sm',
      label: 'text-[10px] font-bold uppercase tracking-widest text-copper',
      price: 'font-display text-3xl mt-2 mb-5',
      list: 'text-[13px] text-white/75 leading-relaxed flex-1 space-y-3',
      dot: 'shrink-0 w-1.5 h-1.5 rounded-full bg-copper mt-1.75',
      badge: 'mt-5 inline-flex self-start items-center gap-2 rounded-full bg-white/15 text-white text-[11px] font-bold uppercase tracking-widest px-3.5 py-1.5',
      button: 'mt-6 rounded-full bg-white text-plum text-[12px] font-bold uppercase tracking-widest py-3 hover:bg-sand-100 transition'
    }
  };

  function buildWeddingPackageCard(pkg) {
    const styles = WEDDING_PACKAGE_STYLES[pkg.style] || WEDDING_PACKAGE_STYLES.Plain;
    const priceLabel = '₱' + Number(pkg.price).toLocaleString();

    const figure = document.createElement('figure');
    figure.className = styles.figure;

    const label = document.createElement('span');
    label.className = styles.label;
    label.textContent = pkg.packageName;
    figure.appendChild(label);

    const price = document.createElement('div');
    price.className = styles.price;
    price.textContent = priceLabel;
    figure.appendChild(price);

    const list = document.createElement('ul');
    list.className = styles.list;
    (pkg.features || []).forEach(feature => {
      const li = document.createElement('li');
      li.className = 'flex gap-2.5';
      const dot = document.createElement('span');
      dot.className = styles.dot;
      const span = document.createElement('span');
      span.style.textAlign = 'justify';
      span.textContent = feature.replace(/\s+—\s+/g, ' ');
      li.appendChild(dot);
      li.appendChild(span);
      list.appendChild(li);
    });
    figure.appendChild(list);

    if (pkg.reservationFee) {
      const badge = document.createElement('span');
      badge.className = styles.badge;
      badge.textContent = '₱' + Number(pkg.reservationFee).toLocaleString() + ' reservation fee';
      figure.appendChild(badge);
    }

    const button = document.createElement('button');
    button.className = styles.button;
    button.textContent = 'Inquire';
    button.addEventListener('click', () => bookService('Wedding ' + pkg.packageName, priceLabel));
    figure.appendChild(button);

    return figure;
  }

  function renderWeddingPackages() {
    const grid = document.getElementById('weddingPackagesGrid');
    const empty = document.getElementById('weddingPackagesEmpty');
    if (!grid) return;
    fetch('backend/public/getWeddingPackages.php')
      .then(r => r.json())
      .then(res => {
        const packages = (res.success && res.packages) ? res.packages : [];
        grid.innerHTML = '';
        if (packages.length === 0) {
          empty?.classList.remove('hidden');
          return;
        }
        empty?.classList.add('hidden');
        packages.forEach(pkg => grid.appendChild(buildWeddingPackageCard(pkg)));
      })
      .catch(() => { empty?.classList.remove('hidden'); });
  }

  function openLightbox(src) {
    const img = document.getElementById('lightboxImg');
    const overlay = document.getElementById('lightboxOverlay');
    img.src = src;
    overlay.classList.remove('opacity-0', 'pointer-events-none');
    setTimeout(() => img.classList.remove('scale-95'), 50);
  }
  
  const closeLightboxBtn = document.getElementById('closeLightboxBtn');
  const lightboxOverlayElement = document.getElementById('lightboxOverlay');
  
  function closeLightbox() {
    const img = document.getElementById('lightboxImg');
    const overlay = document.getElementById('lightboxOverlay');
    overlay.classList.add('opacity-0', 'pointer-events-none');
    img.classList.add('scale-95');
  }

  if (closeLightboxBtn) closeLightboxBtn.addEventListener('click', closeLightbox);
  if (lightboxOverlayElement) {
    lightboxOverlayElement.addEventListener('click', (e) => {
      if (e.target === lightboxOverlayElement) closeLightbox();
    });
  }

  // Keep gallery previews short and consistent without altering the source files.
  document.querySelectorAll('#gallery-tab video[data-preview-duration]').forEach(video => {
    const previewDuration = Number(video.dataset.previewDuration) || 10;
    video.muted = true;
    const toggle = video.closest('.gallery-video-card')?.querySelector('.gallery-video-toggle');
    const icon = toggle?.querySelector('span');

    const showPlayButton = () => {
      if (!toggle || !icon) return;
      icon.innerHTML = '&#9654;';
      toggle.classList.remove('opacity-0', 'pointer-events-none');
    };

    if (toggle) {
      toggle.addEventListener('click', () => {
        if (video.paused) {
          document.querySelectorAll('#gallery-tab video').forEach(otherVideo => {
            if (otherVideo !== video) otherVideo.pause();
          });
          video.play();
        } else {
          video.pause();
        }
      });
    }

    video.addEventListener('play', () => {
      if (!toggle || !icon) return;
      toggle.classList.add('opacity-0', 'pointer-events-none');
    });

    video.addEventListener('pause', showPlayButton);

    video.addEventListener('timeupdate', () => {
      if (video.currentTime >= previewDuration) {
        video.pause();
        video.currentTime = 0;
      }
    });

    video.addEventListener('seeking', () => {
      if (video.currentTime > previewDuration) video.currentTime = previewDuration;
    });
  });

  // Allow closing lightbox with Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lightboxOverlayElement && !lightboxOverlayElement.classList.contains('opacity-0')) {
      closeLightbox();
    }
  });

  // Source price list synchronized from ser.docx (15-page service/pricing list).
  // Only services and prices supported by the supplied source are included.
  const SERVICE_DATA = {
    daraga: {
      promo: 'Haircut with blowdry ₱30 · Organic rebond, any length ₱999 · Manicure/pedicure ₱49',
      menus: [
        {
          title: 'Promo Deals & Best Sellers', sub: 'Daraga Main',
          cats: [
            { name: 'Promo Deals', g: 'deals', items: [['Haircut with Blow Dry','30','Promo'], ['Organic Rebonding (any length)','999','Promo'], ['Manicure / Pedicure','49','Promo']] },
            { name: 'Forever Promo & Best Sellers', g: 'deals', items: [['Haircut w/ Blowdry (men & women)','30'], ['Organic Rebond','999'], ['Hair Color','499'], ['Keratin Treatment','499'], ['Basic Facial','499'], ['Eyelash Extensions','599'], ['Hair & Make-up','700'], ['Gel Polish','399'], ['Foot Spa','149'], ['Manicure / Pedicure','49']] }
          ]
        },
        {
          title: 'Salon Services', sub: 'In-salon Daraga Main',
          cats: [
            { name: 'Hair Services', g: 'hair', items: [['Haircut','30','Promo'], ['Blow Dry','50'], ['Hair Ironing', '199'], ['Hair Setting', '250'], ['Curl Setting', '350'], ['Organic Rebonding (any length)', '999', 'Promo']] },
            { name: 'Hair & Scalp Treatment', g: 'hair', items: [['Hot Oil', '199'], ['Hair Spa', '299'], ['Hair Cellophane','399'], ['Hair Color','499'], ['Keratin Treatment','499'], ['Brazilian Treatment','899'], ['Amazon Flowers', '3,500']] },
            { name: 'Hair & Make-Up', g: 'hair', items: [['Hair & Make-up Wedding, Debut, Birthday, Graduation','700']] },
            { name: 'Nails & Spa Services', g: 'nails', items: [['Manicure','49','Promo'], ['Pedicure','49','Promo'], ['Foot Spa','149'], ['Hand Spa', '399'], ['Semi-Gel Polish', '199'], ['Gel Polish','399'], ['Soft Gel Extensions','1,500']] },
            { name: 'Lash & Brows Special', g: 'lash', items: [['Eyebrow Threading','50'], ['Brow Lamination', '399'], ['Eyelash Lift','399'], ['Eyelash Lift w/ Tint', '599'], ['Eyelash Extensions','599'], ['Microblading','2,500']] },
            { name: 'Face & Body Treatment', g: 'skin', items: [['Underarm Wax', '100'], ['Underarm Whitening', '300'], ['Basic Facial', '499'], ['Diamond Peel', '599'], ['Whole Body Massage', '499'], ['Body Scrub', '999'], ['Warts Removal', '1,500']] }
          ]
        },
        {
          title: 'Skin Care Center', sub: 'Daraga Main',
          cats: [
            { name: 'Facial', g: 'skin', items: [['Basic Facial / Whitening', '350'], ['Acne Clear Facial', '499'], ['Add-on Dr. Mask', '299']] },
            { name: 'Foot Spa & Waxing', g: 'nails', items: [['Regular Foot Spa', '89'], ['Whitening mask', '149'], ['Special Foot Spa', '399'], ['Waxing', '99']] },
            { name: 'Nail Care', g: 'nails', items: [['Regular Polish', '49'], ['Weekly Polish', '199'], ['Gel Polish', '399']] },
            { name: 'Lashes & Body', g: 'lash', items: [['Eyelash Extension', '599'], ['Eyelash Perming', '199'], ['Eyelash Remover', '299'], ['Body Scrub', '999']] }
          ]
        },
        {
          title: 'Aesthetics by Nurse Jemar', sub: 'Clinic room, same address',
          cats: [
            { name: 'Featured Promo', g: 'deals', items: [['Threading', '49'], ['Underarm Waxing', '99'], ['Signature Facial', '499'], ['Whitening Drip', '888']] },
            { name: 'Aesthetics Promo', g: 'deals', items: [['Microblading', '999','Promo'], ['Eyeliner', '999','Promo'], ['Lip Pigmentation', '999','Promo']] },
            { name: 'Facial Services', g: 'skin', items: [['Collagen Facial', '599'], ['Black Doll Facial', '699'], ['Diamond Peel', '799'], ['Hydra Facial', '899'], ['Picu Rejuvination', '999'], ['Head Spa w/ Signature Facial', '1,499'], ['Stem Cell Facial', '1,499'], ['Platelet Rich Plasma', '1,999'], ['CO2 Fractional Facial', '1,999'], ['Ultraformer HIFU', '2,999']] },
            { name: 'Permanent Make-Up', g: 'lash', items: [['BB Blush', '999'], ['BB Glow', '999'], ['Lip Pigmentation', '1,499'], ['Eyeliner', '1,499'], ['Microblading', '2,499']] },
            { name: 'Lash Services', g: 'lash', items: [['Eyelash Perm', '399'], ['Eyelash Perm w/ Tint', '599'], ['Classic Eyelash', '599'], ['Volume Eyelash', '699'], ['Cat Eye Eyelash', '699'], ['Mega Volume Eyelash', '799'], ['Hybrid Eyelash', '799']] },
            { name: 'Laser Treatment', g: 'skin', items: [['Diode Laser Treatment', '799'], ['IPL Underarm', '799'], ['IPL Bikini', '799'], ['IPL Legs', '999'], ['Tattoo Removal (per session)', '1,499']] },
            { name: 'Skin Whitening Drips', g: 'skin', items: [['Celebrity Drip', '888'], ['Luminous Drip', '999'], ['Mystical Drip', '1,399'], ['Cindella Drip', '1,499'], ['Snow White Drip', '1,499'], ['Glutax Drip', '1,799'], ['Pro Drip', '1,999']] },
            { name: 'Whitening Push', g: 'skin', items: [['Lush Glow Push', '888'], ['Radiant Push', '999'], ['Super Radiant Push', '1,499']] },
            { name: 'Facial & Gluta Add-Ons', g: 'skin', items: [['Vitamin C', '199'], ['L-Carnitine', '499'], ['Collagen', '599'], ['PDT & Other Facial Mask', '299']] },
            { name: 'Waxing', g: 'skin', items: [['Underarm Wax (cold)', '99'], ['Underarm Wax (hot)', '150'], ['Leg Wax – Half (cold)', '399'], ['Leg Wax – Half (hot)', '499'], ['Leg Wax – Full (cold)', '599'], ['Leg Wax – Full (hot)', '699'], ['Brazilian Wax', '599']] },
            { name: 'Meso Lipo (per session)', g: 'skin', items: [['Lipo – Face', '1,999'], ['Lipo – Plus', '1,499'], ['Face RF', '599'], ['Body RF', '699']] },
            { name: 'Other', g: 'skin', items: [['Warts Removal (per area)', '1,500'], ['Warts Removal (per piece)', '500'], ['Botox (per area)', '4,999']] }
          ]
        },
        {
          title: 'Mobile / Home Service Salon', sub: 'We come to you',
          cats: [
            { name: 'Home Hair', g: 'hair', items: [['Haircut', '150'], ['Blow Dry', '100'], ['Hair Ironing', '399'], ['Curl Setting', '500'], ['Hot Oil', '299'], ['Rebond', '1,999'], ['Amazon', '4,500'], ['Cellophane', '599'], ['Keratin Treatment', '999'], ['Brazilian Blow Out', '1,499'], ['Hair Color', '999'], ['Hair & Make-up', '999']] },
            { name: 'Home Nails, Lash & Spa', g: 'nails', items: [['Eyelash Extensions', '899'], ['Foot Spa', '300'], ['Manicure', '100'], ['Pedicure', '100'], ['Gel Polish', '599'], ['Hand Spa', '499']] }
          ]
        }
      ]
    },
    yashano: {
      promo: 'Haircut with blowdry ₱49 · Manicure or pedicure ₱49',
      menus: [
        {
          title: 'Promos & Best Sellers', sub: 'Skin Brows · Yashano Mall',
          cats: [
            { name: 'Forever Promo', g: 'deals', items: [['Haircut w/ Blowdry (men/women)','49','Promo'], ['Manicure / Pedicure','49','Promo']] },
            { name: 'Promo Deals', g: 'deals', items: [['Microblading','2,499','Promo'], ['Organic Rebond + Free Hair Treatment','999','Promo'], ['Black Doll Facial','699','Promo'], ['Hair Color','499','Promo'], ['Foot Spa','149','Promo']] }
          ]
        },
        {
          title: 'Skin Brows Services', sub: 'Yashano Mall',
          cats: [
            { name: 'Hair Services', g: 'hair', items: [['Haircut', '49'], ['Blowdry', '49'], ['Hair Spa Treatment', '299'], ['Hair Setting / Curl', '299'], ['Hair Ironing', '299'], ['Cellophane Treatment', '399'], ['Keratin Treatment', '499'], ['Organic Hair Color', '899'], ['Brazilian Treatment', '899'], ['L’Oréal Treatment', '999'], ['Stem Cell Treatment', '1,499'], ['Brazilian Blowout Treatment', '1,499'], ['Luxury Treatment', '1,499'], ['Amazon Flowers', '3,499']] },
            { name: 'Nails & Spa Services', g: 'nails', items: [['Manicure', '49'], ['Pedicure', '49'], ['Semi Gel', '199'], ['Foot Spa', '149'], ['Special Foot Spa', '399'], ['Hand Spa', '399'], ['Organic Gel Polish', '499'], ['Soft Gel Extensions', '799']] },
            { name: 'Facial Services', g: 'skin', items: [['Basic Organic Facial','499'], ['Diamond Peel','699'], ['Acne Clear Facial','699'], ['Hydra Facial','799'], ['Red Cell Facial', '1,499'], ['PRP Facial (Vampire Facial)','1,999']] },
            { name: 'Skin Brows Specials', g: 'lash', items: [['Threading', '49'], ['BB Glow', '999'], ['BB Blush', '999'], ['Warts Removal (unlimited)', '1,499'], ['Permanent Eyeliner', '1,999'], ['Lip Pigmentation', '1,999'], ['Microblading', '2,499']] },
            { name: 'Lash Special Services', g: 'lash', items: [['Eyelash Lift', '399'], ['Eyelash Lift w/ Tint', '599'], ['Classic Lash Extensions', '599'], ['Volume Lash Extensions', '699'], ['Cat Eye Lash Extensions', '799'], ['Mega Volume Lash Extensions', '799']] },
            { name: 'Laser & Underarm Treatments', g: 'skin', items: [['Underarm Waxing (cold wax)', '149'], ['Underarm Waxing (hot wax)', '199'], ['IPL Underarm Laser (per session)', '499'], ['Diode Laser Treatment', '699'], ['Underarm Bleaching', '999']] },
            { name: 'Whitening Drips (per session)', g: 'skin', items: [['Celebrity Drip', '888'], ['Luminous Drip', '999'], ['Mystical Drip', '1,399'], ['Cindella Drip', '1,499'], ['Snow White Drip', '1,499'], ['Glutax Drip', '1,799'], ['Pro Drip', '1,999']] },
            { name: 'Whitening Push (per session)', g: 'skin', items: [['Lush Glow Push', '888'], ['Radiant Push', '999'], ['Super Radiant Push', '1,399']] },
            { name: 'Other Services', g: 'skin', items: [['Leg Wax – Half (cold wax)', '399'], ['Leg Wax – Half (hot wax)', '499'], ['Leg Wax – Full (cold wax)', '599'], ['Leg Wax – Full (hot wax)', '699'], ['Brazilian Wax', '599'], ['Mesolipo (per session)', '1,499'], ['Botox (per session)', '4,999'], ['Revok 50 (per session)', '14,999']] }
          ]
        }
      ]
    },
    cabangan: {
      promo: 'Organic rebond, any length ₱999 · Manicure or pedicure ₱49',
      menus: [
        {
          title: 'Best Sellers & Packages', sub: 'Cabangan Hub',
          cats: [
            { name: 'Best Sellers', g: 'deals', items: [['Organic Rebond (any length)','999','Promo'], ['Eyelash Extensions','599','Promo'], ['Hair Color w/ Keratin (any length)','998','Promo'], ['Special Foot Spa','399','Promo'], ['Organic Hair Color (any length)','899','Promo'], ['Basic Facial','499','Promo']] },
            { name: 'Best Selling Hair Packages', g: 'deals', items: [['Rebond + Hair Color + Brazilian Botox (any length)', '2,397'], ['Hair Color + Highlights + Brazilian Botox (any length)', '2,397'], ['Hair Color + Brazilian Botox (any length)', '1,398']] }
          ]
        },
        {
          title: 'Complete Salon Services', sub: 'Also available at Cabangan Hub',
          cats: [
            { name: 'Hair Services', g: 'hair', items: [['Haircut','30'], ['Blow Dry','50'], ['Hair Ironing','199'], ['Hair Setting','250'], ['Curl Setting','350'], ['Organic Rebonding (any length)','999']] },
            { name: 'Hair & Scalp Treatment', g: 'hair', items: [['Hot Oil','199'], ['Hair Spa','299'], ['Hair Cellophane','399'], ['Keratin Treatment','499'], ['Brazilian Treatment','899'], ['Hair Color','499'], ['Amazon Flowers','3,500']] },
            { name: 'Hair & Make-Up Services', g: 'hair', items: [['Hair & Make-up — Wedding','700'], ['Hair & Make-up — Debut','700'], ['Hair & Make-up — Birthday','700'], ['Hair & Make-up — Graduation','700']] },
            { name: 'Nails & Spa Services', g: 'nails', items: [['Manicure','49'], ['Pedicure','49'], ['Hand Spa','399'], ['Semi-Gel Polish','199'], ['Gel Polish','399'], ['Soft Gel Extensions','1,500']] },
            { name: 'Lash & Brows Special', g: 'lash', items: [['Eyebrow Threading','50'], ['Brow Lamination','399'], ['Eyelash Lift','399'], ['Eyelash Lift w/ Tint','599'], ['Eyelash Extensions','599'], ['Microblading','2,500']] },
            { name: 'Face & Body Treatment', g: 'skin', items: [['Underarm Wax','100'], ['Underarm Whitening','300'], ['Basic Facial','499'], ['Diamond Peel','599'], ['Whole Body Massage','499'], ['Body Scrub','999'], ['Warts Removal','1,500']] }
          ]
        },
        {
          title: 'Lash & Brow Studio', sub: 'Cabangan Hub',
          cats: [
            { name: 'Hair', g: 'hair', items: [['Organic Rebond (any length)','999','Promo'], ['Organic Hair Color (any length)', '899'], ['Hair Color w/ Keratin (any length)','998']] },
            { name: 'Nails & Spa', g: 'nails', items: [['Manicure', '49'], ['Pedicure', '49'], ['Foot Spa', '149'], ['Hand Spa', '399'], ['Special Foot Spa', '399'], ['Gel Polish', '499'], ['Softgel Extension', '1,499']] },
            { name: 'Facial', g: 'skin', items: [['Basic Facial', '499'], ['Acne Clear Facial', '599'], ['Carbon Laser Peel', '699'], ['Timeless Anti-Aging', '799'], ['Firm Bright Facial (w/ Diamond Peel)', '899'], ['Aqua Facial', '999'], ['Meso Lipo', '999']] },
            { name: 'Facial Promo Board', g: 'skin', items: [['Basic Facial', '499'], ['Diamond Peel Facial', '599'], ['Acne Clear Facial', '699'], ['Carbon Laser / Black Doll', '699'], ['Skin Planing Facial', '999'], ['Platelet Rich Plasma Facial', '2,499']] },
            { name: 'Waxing / Threading', g: 'skin', items: [['Eyebrows', '49'], ['Upper Lips', '49'], ['Underarms', '50'], ['Lower Legs', '149'], ['Brazilian', '499'], ['Warts Removal', '1,500']] },
            { name: 'Lash & Brows Specials', g: 'lash', items: [['Lash Removal', '299'], ['Korean Lash Lift','399'], ['Korean Lash Lift w/ Tint', '599'], ['Classic Lash','599'], ['Mega Volume Lash','699'], ['Microblading','2,500']] },
            { name: 'IPL Permanent Hair Removal', g: 'skin', items: [['Underarm (per session)', '499'], ['Bikini (per session)', '799']] },
            { name: 'Massage Services', g: 'skin', items: [['Swedish Massage', '499'], ['Shiatsu Massage', '499'], ['Signature Massage', '499'], ['Foot Massage', '349']] }
          ]
        },
        {
          title: 'Aesthetics Menu', sub: 'Gluta · Facial · Botox',
          cats: [
            { name: 'Beauty Drip (per session)', g: 'skin', items: [['Tationil', '999'], ['TAD', '999'], ['Luminous', '999'], ['Mystical', '1,299'], ['Cindella', '1,499'], ['Snow White', '1,499'], ['Glutax', '1,999']] },
            { name: 'Beauty Push (per session)', g: 'skin', items: [['Radiant Glow', '999'], ['Luminous', '999'], ['Super Radiant', '1,399']] },
            { name: 'Drip & Push Packages (5+1)', g: 'deals', items: [['Luminous Glow Drip', '4,999'], ['Snow White Drip', '7,499'], ['Radiant Gluta Push', '4,999'], ['Super Radiant Glow Push', '6,995'], ['Pro Drip', '9,995']] },
            { name: 'Drip & Push Packages (10+2)', g: 'deals', items: [['Luminous Glow Drip', '9,999'], ['Snow White Drip', '14,999'], ['Radiant Gluta Push', '9,999'], ['Super Radiant Glow Push', '13,990'], ['Pro Drip', '19,990']] },
            { name: 'Aesthetics Facial (per session)', g: 'skin', items: [['Basic Facial', '499'], ['Diamond Peel', '599'], ['Acne Clear Facial', '599'], ['Timeless Anti-Aging', '799'], ['Firm Bright Facial', '899'], ['Black Doll', '999'], ['Vampire Facial', '1,999'], ['Special PRP', '2,499']] },
            { name: 'Skin Boosters (per session)', g: 'skin', items: [['Acne Clear Healer', '1,999'], ['Red Cell / Stem Cell', '1,999'], ['Lucia / Anti-Melasma', '1,999']] },
            { name: 'Add-Ons (per session)', g: 'skin', items: [['Vitamin C', '199'], ['Collagen', '499'], ['L-Carnitine', '599']] },
            { name: 'Meso Lipo (per session)', g: 'skin', items: [['Lipo – Face', '1,999'], ['Lipo – Plus', '1,499'], ['Face RF', '499'], ['Body RF', '599']] },
            { name: 'IPL Laser (per session)', g: 'skin', items: [['Underarm Whitening', '499'], ['Bikini Whitening', '799']] },
            { name: 'Other', g: 'skin', items: [['Warts Removal (per piece)', '500'], ['BB Blush (per session)', '1,499'], ['Warts Removal (per area)', '1,500'], ['BB Glow (per session)', '1,999'], ['Botox (per area)', '4,999']] }
          ]
        }
      ]
    }
  };

  // Section 5 of the price list — "GROUP-WIDE PROMOS & PACKAGES", valid at all three branches.
  const GROUP_WIDE_MENU = {
    title: 'Group-Wide Promos & Packages', sub: 'Valid at all three branches',
    cats: [
      { name: 'SALE! Permanent Make-Up', g: 'deals', items: [['6D Eyebrow Microblading', '999', 'Best Seller'], ['Top Eyeliner', '999', 'Best Seller'], ['Derma Pen', '999', 'Best Seller'], ['Korean BB Glow', '999', 'Promo'], ['Combo Brows', '999', 'Best Seller'], ['Lip Pigmentation', '999', 'Promo'], ['Package: Lips 6D & Eyeliner (free aftercare kit)', '1,499'], ['Package: Lip Pigmentation & Combo Brows (free aftercare kit)', '1,499']] },
      { name: 'Eyelash Extensions', g: 'lash', items: [['Classic Lash', '599'], ['Volume Lash', '699'], ['Cat Eye Lash', '699'], ['Doll Eye Lash', '799'], ['Hybrid Lash', '799'], ['Mega Volume Lash', '899']] },
      { name: 'Gift Certificates', g: 'deals', items: [['Gift Certificate (₱500 denomination)', '500'], ['Gift Certificate (₱1,000 denomination)', '1,000']] }
    ]
  };

  // Append the group-wide menu to every branch.
  ['daraga', 'yashano', 'cabangan'].forEach(b => SERVICE_DATA[b].menus.push(GROUP_WIDE_MENU));

  const SERVICE_GROUPS = [
    { k:'deals', label:'Deals' },
    { k:'hair', label:'Hair' },
    { k:'nails', label:'Nails' },
    { k:'lash', label:'Lash & Brows' },
    { k:'skin', label:'Skin' }
  ];

  let currentSvcBranch = 'daraga';
  let activeSvcGroup = 'all';
  let svcAllOpen = true;

  function renderServices() {
    const svcMenus = document.getElementById('svcMenus');
    const svcGroups = document.getElementById('svcGroups');
    const svcPromo = document.getElementById('svcPromo');
    const svcPromoText = document.getElementById('svcPromoText');
    const svcEmpty = document.getElementById('svcEmpty');
    const svcCount = document.getElementById('svcCount');
    if (!svcMenus) return;

    const bData = SERVICE_DATA[currentSvcBranch];
    if (!bData) return;
    const branchLabel = BRANCH_LABEL[currentSvcBranch] || currentSvcBranch;

    // Tell the customer which branch the prices below belong to
    const svcBranchNow = document.getElementById('svcBranchNow');
    if (svcBranchNow) svcBranchNow.textContent = branchLabel;
    const svcPromoBranch = document.getElementById('svcPromoBranch');
    if (svcPromoBranch) svcPromoBranch.textContent = branchLabel;

    if (bData.promo) {
      svcPromo.classList.remove('hidden');
      svcPromoText.textContent = bData.promo;
    } else {
      svcPromo.classList.add('hidden');
    }

    svcGroups.innerHTML = SERVICE_GROUPS.map(g =>
      `<button onclick="setSvcGroup('${g.k}')" class="svc-grp ${activeSvcGroup === g.k ? 'on' : ''} rounded-2xl p-3 text-left">
         <span class="block text-xs font-bold">${g.label}</span>
       </button>`).join('');

    const q = (document.getElementById('svcFilter')?.value || '').trim().toLowerCase();
    document.getElementById('svcFilterClear')?.classList.toggle('hidden', !q);

    let html = '';
    let shown = 0;

    bData.menus.forEach(m => {
      m.cats.forEach(c => {
        if (activeSvcGroup !== 'all' && c.g !== activeSvcGroup) return;
        const items = q ? c.items.filter(i => i[0].toLowerCase().includes(q)) : c.items;
        if (!items.length) return;
        shown += items.length;

        const rows = items.map(i => {
          const name = i[0], price = i[1], tag = i[2];
          return `
            <button type="button" class="svc-row w-full flex items-center py-2.5 text-left border-b border-bronze/10 last:border-0"
                    data-svc="${escAttr(name)}" data-price="${escAttr(price)}">
              <span class="text-xs font-semibold text-ink">${name}</span>
              ${tag ? `<span class="ml-2 text-[9px] font-bold uppercase tracking-widest text-copper-dark bg-copper/15 rounded-full px-2 py-0.5 shrink-0">${tag}</span>` : ''}
              <span class="svc-lead"></span>
              <span class="svc-book mr-2">Book</span>
              <span class="text-xs font-bold text-ink whitespace-nowrap">₱${price}</span>
            </button>`;
        }).join('');

        html += `
          <div class="svc-acc bg-panel rounded-2xl mb-3 ${svcAllOpen || q ? 'open' : ''} border border-bronze/20">
            <button type="button" class="svc-acc-head w-full px-5 py-3.5 flex justify-between items-center gap-3 text-left">
              <span class="font-bold text-xs uppercase tracking-widest text-plum">${c.name}</span>
              <span class="flex items-center gap-3 shrink-0">
                <span class="text-[10px] font-semibold text-ink/35">${items.length} item${items.length > 1 ? 's' : ''}</span>
                <svg class="svc-chev text-ink/40" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M6 9l6 6 6-6"/></svg>
              </span>
            </button>
            <div class="svc-acc-panel">
              <div class="px-5 pb-2 border-t border-bronze/10">${rows}</div>
            </div>
          </div>`;
      });
    });

    svcMenus.innerHTML = html;
    if (svcEmpty) svcEmpty.classList.toggle('hidden', shown > 0);
    if (svcCount) svcCount.textContent = shown
      ? `${shown} service${shown > 1 ? 's' : ''} · ${branchLabel}`
      : '';
    const expandBtn = document.getElementById('svcExpandAll');
    if (expandBtn) expandBtn.textContent = svcAllOpen ? 'Close all' : 'Open all';
  }

  /* Services menu interactions — delegated so they survive re-renders. */
  (function () {
    const svcMenus = document.getElementById('svcMenus');
    if (!svcMenus) return;

    svcMenus.addEventListener('click', e => {
      const head = e.target.closest('.svc-acc-head');
      if (head) { head.parentElement.classList.toggle('open'); return; }
      const row = e.target.closest('.svc-row');
      if (row) bookService(row.dataset.svc, '₱' + row.dataset.price, currentSvcBranch);
    });

    const filter = document.getElementById('svcFilter');
    let t = null;
    filter?.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(renderServices, 140);
    });

    document.getElementById('svcFilterClear')?.addEventListener('click', () => {
      filter.value = '';
      renderServices();
      filter.focus();
    });

    document.getElementById('svcExpandAll')?.addEventListener('click', () => {
      svcAllOpen = !svcAllOpen;
      renderServices();
    });
  })();

  function setSvcGroup(g) {
    // Tapping the active category again clears the filter and shows every service.
    activeSvcGroup = (activeSvcGroup === g) ? 'all' : g;
    renderServices();
  }

  /* Branch rate cards. Only one branch can be selected at a time, so the
     'on' class is cleared from all of them before it is added to the one
     that was clicked. */
  document.querySelectorAll('.svc-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      currentSvcBranch = btn.dataset.s;
      highlightSvcBranch();
      renderServices();
    });
  });

  function highlightSvcBranch() {
    document.querySelectorAll('.svc-tab-btn').forEach(b => {
      b.classList.toggle('on', b.dataset.s === currentSvcBranch);
    });
  }

  let currentStaffBranch = 'daraga';
  let staffRosterCache = {};
  let staffRosterRequestId = 0;

  function getStaffInitials(name) {
    const parts = name.trim().split(/\s+/);
    if (parts.length > 1) return (parts[0][0] + parts[1][0]).toUpperCase();
    return (parts[0][0] + (parts[0][1] || '')).toUpperCase();
  }

  // Mirrors employees.shift_status, kept live by the staff login/logout
  // attendance system (see backend/auth/login.php) -- not something anyone
  // sets by hand for the public site.
  const STAFF_STATUS_META = {
    'On Duty': { cls: 'is-available', label: 'Available now', note: 'Currently clocked in and able to take walk-ins or bookings.' },
    'With Client': { cls: 'is-busy', label: 'With a client', note: 'Attending to another guest right now — book ahead for a later slot.' },
    'Off Shift': { cls: '', label: 'Off duty', note: "Not clocked in at the moment. Book an appointment for a time they're scheduled to work." }
  };
  function staffStatusMeta(status) {
    return STAFF_STATUS_META[status] || STAFF_STATUS_META['Off Shift'];
  }

  function staffCardHtml(role, s) {
    const meta = staffStatusMeta(s.status);
    const avatar = s.photo
      ? `<img src="${escAttr(s.photo)}" alt="" class="w-10 h-10 rounded-full object-cover mb-3">`
      : `<div class="w-10 h-10 rounded-full ${role.bookable ? 'bg-plum text-white group-hover:bg-copper' : 'bg-sand-200 text-copper-dark'} font-bold flex items-center justify-center text-xs mb-3 transition">${getStaffInitials(s.name)}</div>`;
    const statusBadge = `<span class="staff-status-badge ${meta.cls} mt-2"><span class="staff-status-dot ${meta.cls}"></span>${meta.label}</span>`;

    if (role.bookable) {
      return `
        <button type="button" onclick="openStaffProfile('${escAttr(s.id)}')" class="bg-panel p-4 rounded-2xl border border-bronze/20 shadow-sm text-left hover:-translate-y-1 hover:shadow-md hover:border-plum/30 transition duration-300 group">
          ${avatar}
          <span class="block font-bold text-[13px] text-ink leading-tight">${escAttr(s.name)}</span>
          ${statusBadge}
        </button>
      `;
    }
    return `
      <div class="bg-panel/60 p-4 rounded-2xl border border-bronze/10 text-left opacity-80 cursor-default">
        ${avatar}
        <span class="block font-bold text-[13px] text-ink leading-tight">${escAttr(s.name)}</span>
        ${statusBadge}
      </div>
    `;
  }

  function renderStaffRoster(data) {
    document.getElementById('staffStudioName').textContent = data.branch.name;
    const totalStaff = data.roles.reduce((sum, r) => sum + r.staff.length, 0);
    document.getElementById('staffStudioCount').textContent = `${totalStaff} Team Members`;

    const roster = document.getElementById('staffRoster');
    roster.innerHTML = data.roles.map(role => `
      <div class="mb-10">
        <div class="flex items-center gap-4 mb-5">
          <h4 class="text-[11px] font-bold uppercase tracking-widest text-copper-dark whitespace-nowrap">${role.title}</h4>
          <span class="text-[11px] font-semibold text-ink/30 tabular-nums">${role.staff.length}</span>
          <span class="flex-1 h-px bg-bronze/30"></span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
          ${role.staff.map(s => staffCardHtml(role, s)).join('')}
        </div>
      </div>
    `).join('') || '<p class="text-center text-sm text-ink/50 py-10">No team members listed for this branch yet.</p>';
  }

  // force=true bypasses the cache -- used by the periodic refresh so shift
  // status (on duty / with a client / off duty) doesn't go stale while a
  // visitor sits on the Stylists & Team tab.
  function renderStaff(force) {
    const roster = document.getElementById('staffRoster');
    if (!roster) return;
    const branch = currentStaffBranch;

    if (!force && staffRosterCache[branch]) {
      renderStaffRoster(staffRosterCache[branch]);
      return;
    }

    if (!staffRosterCache[branch]) {
      roster.innerHTML = '<p class="text-center text-sm text-ink/50 py-10">Loading team…</p>';
    }

    const requestId = ++staffRosterRequestId;
    fetch('backend/public/getStaffRoster.php?branchKey=' + encodeURIComponent(branch))
      .then(r => r.json())
      .then(res => {
        if (requestId !== staffRosterRequestId || currentStaffBranch !== branch) return;
        if (!res.success) {
          roster.innerHTML = '<p class="text-center text-sm text-copper-dark py-10">Couldn\'t load the team roster — please try again.</p>';
          return;
        }
        staffRosterCache[branch] = res;
        renderStaffRoster(res);
      })
      .catch(() => {
        if (requestId !== staffRosterRequestId || currentStaffBranch !== branch) return;
        if (!staffRosterCache[branch]) {
          roster.innerHTML = '<p class="text-center text-sm text-copper-dark py-10">Couldn\'t load the team roster — please try again.</p>';
        }
      });
  }

  function findStaffById(id) {
    const data = staffRosterCache[currentStaffBranch];
    if (!data) return null;
    for (const role of data.roles) {
      const match = role.staff.find(s => s.id === id);
      if (match) return { staff: match, role };
    }
    return null;
  }

  const staffProfileOverlay = document.getElementById('staffProfileOverlay');
  const staffProfileBox = document.getElementById('staffProfileBox');

  function openStaffProfile(id) {
    const found = findStaffById(id);
    if (!found || !staffProfileOverlay) return;
    const { staff: s, role } = found;
    const meta = staffStatusMeta(s.status);

    document.getElementById('staffProfileName').textContent = s.name;
    document.getElementById('staffProfilePosition').textContent = s.position;

    const photoWrap = document.getElementById('staffProfilePhotoWrap');
    photoWrap.innerHTML = s.photo
      ? `<img src="${escAttr(s.photo)}" alt="" class="w-full h-full object-cover">`
      : getStaffInitials(s.name);

    document.getElementById('staffProfileStatusDot').className = 'staff-status-dot ' + meta.cls;
    document.getElementById('staffProfileStatusLabel').textContent = meta.label;
    document.getElementById('staffProfileStatusNote').textContent = meta.note;

    const details = [
      ['Branch', staffRosterCache[currentStaffBranch].branch.name],
      ['Role', s.position],
    ];
    if (s.hireDate) {
      const years = Math.max(0, new Date().getFullYear() - new Date(s.hireDate).getFullYear());
      details.push(['With us since', new Date(s.hireDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long' }) + (years > 0 ? ` · ${years} yr${years === 1 ? '' : 's'}` : '')]);
    }
    document.getElementById('staffProfileDetails').innerHTML = details
      .map(([label, value]) => `<div class="flex justify-between gap-3"><dt class="text-ink/50">${label}</dt><dd class="font-bold text-ink text-right">${escAttr(value)}</dd></div>`)
      .join('');

    const bookBtn = document.getElementById('staffProfileBookBtn');
    bookBtn.onclick = () => {
      closeStaffProfile();
      // s.name only works at this one branch, so bookService locks it.
      bookService('Appointment with ' + s.name, '', currentStaffBranch, s.name + ' works at this branch only.');
    };

    staffProfileOverlay.classList.remove('opacity-0', 'pointer-events-none');
    staffProfileBox.classList.remove('scale-95');
  }

  function closeStaffProfile() {
    if (!staffProfileOverlay) return;
    staffProfileOverlay.classList.add('opacity-0', 'pointer-events-none');
    staffProfileBox.classList.add('scale-95');
  }

  document.getElementById('closeStaffProfile')?.addEventListener('click', closeStaffProfile);
  staffProfileOverlay?.addEventListener('click', e => {
    if (e.target === staffProfileOverlay) closeStaffProfile();
  });

  // Shift status can change anytime staff clock in/out elsewhere in the
  // system, so refresh it periodically rather than only on tab switch.
  setInterval(() => {
    if (document.getElementById('stylists-tab')?.classList.contains('active-page')) renderStaff(true);
  }, 60000);

  document.querySelectorAll('.staff-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      currentStaffBranch = btn.dataset.t;
      document.querySelectorAll('.staff-tab-btn').forEach(b => b.classList.remove('bg-plum', 'text-white'));
      btn.classList.add('bg-plum', 'text-white');
      renderStaff();
    });
  });

  const branchMap = {
    daraga: {
      tag: 'Flagship Hub',
      name: 'Leo Mejillano Salon & Make-Up Studio',
      addr: '2nd Floor, Luna Building, Regidor Street, Daraga, Albay',
      spec: "Step into the Leo Mejillano beauty experience at our flagship Daraga branch, where professional beauty services, personalized care, and expertise come together. From hair, nails, skin, brows, and make-up to academy training, our team is dedicated to making every visit comfortable, memorable, and uniquely yours. Whether you're refreshing your look, preparing for a special occasion, or simply taking time for yourself, we're ready to help you look and feel your best.",
      hours: '8:00 AM – 8:00 PM', hoursNote: 'Open Daily',
      phone: '0918 536 8016', tel: '+639185368016', email: 'leomejillano88@yahoo.com',
      phone2: '0907 869 6035', tel2: '+639078696035', phone2Label: 'Aesthetics line — Nurse Jemar',
      img: 'assets/image/Daraga.png'
    },
    yashano: {
      tag: 'Mall Studio',
      name: 'Skin Brows by Leo Mejillano',
      addr: '2nd Floor, Yashano Mall, Legazpi City, Albay',
      landmark: "Beside Angel's Pizza",
      spec: "Discover a beauty experience designed to help you feel confident, refreshed, and beautifully cared for at Skin Brows by Leo Mejillano. Conveniently located on the 2nd Floor of Yashano Mall beside Angel's Pizza, our branch provides a comfortable and professional space where you can take time for yourself and enjoy personalized beauty care. From skin and brow enhancement to other beauty treatments, our team is committed to giving every client attentive service and an experience tailored to their individual needs. Whether you're preparing for a special occasion, maintaining your beauty routine, or simply treating yourself to some well-deserved self-care, we're here to help you look and feel your best.",
      hours: '9:30 AM – 8:00 PM', hoursNote: 'Open Daily',
      phone: '0918 536 8016', tel: '+639185368016', email: '',
      phone2: '0907 869 6035', tel2: '+639078696035', phone2Label: 'Aesthetics line — Nurse Jemar',
      img: 'assets/image/Yashano.jpg'
    },
    cabangan: {
      tag: 'Lash & Brow Hub',
      name: 'Lash & Brows by Leo Mejillano',
      addr: 'Brgy. 18, Rizal Street, Cabangan, Legazpi City, Albay',
      spec: "Discover personalized beauty care at Lash & Brows by Leo Mejillano in Cabangan, where professional service and attention to detail come together to create a beauty experience made for you. From fresh haircuts and hair treatments to rebonding, hair color, lash, and brow services, our team is dedicated to helping you achieve a look that feels confident, beautiful, and uniquely yours. Whether you're looking for a simple refresh, a complete hair transformation, or beauty enhancements for a special occasion, take a moment to relax, enjoy professional care, and let our team bring your desired look to life.",
      hours: '8:00 AM – 8:00 PM', hoursNote: 'Open Daily',
      phone: '0918 536 8016', tel: '+639185368016', email: 'mejillanoleo@gmail.com',
      img: 'assets/image/Cabangan.jpg'
    }
  };

  document.querySelectorAll('.branch-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.branch-tab-btn').forEach(b => b.classList.remove('bg-white/10'));
      btn.classList.add('bg-white/10');
      const b = branchMap[btn.dataset.b];
      document.getElementById('bTag').textContent = b.tag;
      document.getElementById('bName').textContent = b.name;
      document.getElementById('bAddr').textContent = b.addr;
      const lm = document.getElementById('bLandmark');
      lm.textContent = b.landmark || '';
      lm.classList.toggle('hidden', !b.landmark);
      document.getElementById('bSpec').textContent = b.spec;
      document.getElementById('bHours').textContent = b.hours;
      document.getElementById('bHoursNote').textContent = b.hoursNote;
      document.getElementById('bPhoneText').textContent = b.phone;
      document.getElementById('bPhone').href = 'tel:' + b.tel;
      const em = document.getElementById('bEmail');
      em.classList.toggle('hidden', !b.email);
      em.classList.toggle('flex', !!b.email);
      document.getElementById('bEmailText').textContent = b.email || '';
      em.href = b.email ? 'mailto:' + b.email : '#';
      const p2 = document.getElementById('bPhone2');
      p2.classList.toggle('hidden', !b.phone2);
      p2.classList.toggle('flex', !!b.phone2);
      if (b.phone2) {
        document.getElementById('bPhone2Text').textContent = b.phone2;
        document.getElementById('bPhone2Label').textContent = b.phone2Label || '';
        p2.href = 'tel:' + b.tel2;
      }
      document.getElementById('bImg').src = b.img;
    });
  });

  // Status vocabulary matches the `home_service_requests.status` ENUM in
  // the database exactly (see trackBooking.php) — 'Cancelled' is a
  // terminal state handled separately, not a step in this progression.
  const HS_STATUSES = ['Pending Review', 'Confirmed', 'Completed'];
  // Customer-facing labels -- the database enum ('Pending Review', etc.) is
  // internal; guests should see the friendlier wording everywhere it's shown.
  const HS_STATUS_LABELS = { 'Pending Review': 'Under Review' };
  function hsStatusLabel(status) { return HS_STATUS_LABELS[status] || status; }
  let hsSelectedServices = [];
  let hsSelectedWeddingPackage = null;
  let hsWeddingMode = false;
  const HS_WEDDING_PACKAGES = [
    {
      name: 'Package A', price: 5000, reservationFee: null,
      short: 'Traditional bridal makeup, two bridal looks, groom grooming, and styling for 2 heads.',
      inclusions: [
        'Hairstyle and Traditional Makeup for the Bride',
        'Prep Look and Ceremony Look',
        'Unlimited touch-up until the Bride leaves the hotel for the ceremony',
        'Groom Grooming, only if the Groom is at the same preparation venue',
        'Hairstyle and Traditional Makeup for 2 heads'
      ]
    },
    {
      name: 'Package B', price: 8000, reservationFee: null,
      short: 'Airbrush bridal makeup, two bridal looks, groom grooming, and styling for 2 heads.',
      inclusions: [
        'Hairstyle and Airbrush Makeup for the Bride',
        'Prep Look and Ceremony Look',
        'Unlimited touch-up until the Bride leaves the hotel for the ceremony',
        'Groom Grooming, only if the Groom is at the same preparation venue',
        'Hairstyle and Traditional Makeup for 2 heads'
      ]
    },
    {
      name: 'Package C', price: 10000, reservationFee: null,
      short: 'Airbrush bridal makeup, prep, ceremony and reception looks, plus styling for 2 heads.',
      inclusions: [
        'Hairstyle and Airbrush Makeup for the Bride',
        'Prep Look and Ceremony Look',
        'Unlimited touch-up until the Bride leaves the hotel for the ceremony',
        'Reception Look / Change Look for the Bride',
        'Groom Grooming, only if the Groom is at the same preparation venue',
        'Hairstyle and Traditional Makeup for 2 heads'
      ]
    },
    {
      name: 'Package D', price: 12000, reservationFee: 2000,
      short: 'Complete airbrush bridal coverage with reception look and styling for 7 heads.',
      inclusions: [
        'Hairstyle and Airbrush Makeup for the Bride',
        'Prep Look and Ceremony Look',
        'Unlimited touch-up until the Bride leaves the hotel for the ceremony',
        'Reception Look / Change Look for the Bride',
        'Groom Grooming, only if the Groom is at the same preparation venue',
        'Hairstyle and Traditional Makeup for 7 heads'
      ]
    }
  ];
  let hsFeedbackRef = '';
  let hsFeedbackEmail = '';
  const hsRatings = { service: 0, staff: 0 };
  function hsJump(id) {
    switchTab('homeservice-tab');
    setTimeout(() => document.getElementById(id)?.scrollIntoView({ behavior:'smooth', block:'start' }), 120);
  }

  function hsPreset(type) {
    const sel = document.getElementById('hsEventType');
    if (sel) {
      sel.value = type;
      hsSyncWeddingPackages();
    }
    hsJump('hsRequest');
  }

  function hsPeso(amount) {
    return '₱' + Number(amount).toLocaleString('en-PH');
  }

  function hsRenderWeddingPackages() {
    const grid = document.getElementById('hsWeddingPackageGrid');
    if (!grid || grid.children.length) return;
    grid.innerHTML = HS_WEDDING_PACKAGES.map((pkg, index) => `
      <article class="hs-wedding-card rounded-2xl border border-bronze/30 bg-sand-100 p-5 transition" data-package-index="${index}">
        <div class="flex items-start justify-between gap-3">
          <div>
            <span class="text-[10px] font-bold uppercase tracking-widest text-copper">Wedding Package</span>
            <h5 class="font-display text-xl mt-1">${pkg.name}</h5>
          </div>
          <b class="text-plum text-lg">${hsPeso(pkg.price)}</b>
        </div>
        <p class="text-[12px] text-ink/60 mt-3 leading-relaxed">${pkg.short}</p>
        <p class="text-[12px] font-semibold mt-3 ${pkg.reservationFee === null ? 'text-ink/55' : 'text-copper-dark'}">Reservation Fee: ${pkg.reservationFee === null ? 'To be confirmed after review.' : hsPeso(pkg.reservationFee)}</p>
        <button type="button" onclick="hsToggleWeddingDetails(${index}, this)" class="mt-4 text-[11px] font-bold uppercase tracking-widest text-plum hover:underline">View Full Details</button>
        <div class="hidden mt-3 pt-3 border-t border-bronze/25" data-package-details>
          <p class="text-[10px] font-bold uppercase tracking-widest text-copper mb-2">Includes</p>
          <ul class="space-y-2 text-[12px] text-ink/65 leading-relaxed">${pkg.inclusions.map(item => `<li class="flex gap-2"><span class="text-copper">•</span><span>${item}</span></li>`).join('')}</ul>
        </div>
        <button type="button" onclick="hsSelectWeddingPackage(${index})" class="mt-5 w-full rounded-full border border-plum text-plum text-[11px] font-bold uppercase tracking-widest py-3 hover:bg-plum hover:text-white transition" data-package-select>Select Package</button>
      </article>
    `).join('');
  }

  function hsToggleWeddingDetails(index, button) {
    const card = document.querySelector(`.hs-wedding-card[data-package-index="${index}"]`);
    const details = card?.querySelector('[data-package-details]');
    if (!details) return;
    const expanding = details.classList.contains('hidden');
    details.classList.toggle('hidden', !expanding);
    button.textContent = expanding ? 'Hide Full Details' : 'View Full Details';
  }

  // Estimated reservation fee (DP): the package's fixed fee, otherwise ~30%
  // of its price (rounded to ₱100). The admin confirms the final DP in the
  // quote; it's then paid online via PayMongo.
  const HS_DP_ESTIMATE_RATE = 0.30;
  function hsEstimatedDp(pkg) {
    if (!pkg) return null;
    if (pkg.reservationFee !== null && pkg.reservationFee !== undefined) return { amount: Number(pkg.reservationFee), fixed: true };
    return { amount: Math.round((Number(pkg.price) * HS_DP_ESTIMATE_RATE) / 100) * 100, fixed: false };
  }

  // The "How payment works" box's estimate line (review step).
  function hsRenderDpEstimate() {
    const el = document.getElementById('hsDpEstimate');
    if (!el) return;
    const isWedding = document.getElementById('hsEventType').value === 'Wedding';
    const dp = isWedding ? hsEstimatedDp(hsSelectedWeddingPackage) : null;
    el.innerHTML = dp
      ? 'Estimated DP for ' + escAttr(hsSelectedWeddingPackage.name) + ': <b class="text-plum text-[15px]">' + (dp.fixed ? '' : 'about ') + hsPeso(dp.amount) + '</b> ' +
        '<span class="text-ink/50">' + (dp.fixed ? '(fixed reservation fee)' : '(≈30% of ' + hsPeso(hsSelectedWeddingPackage.price) + ')') + '</span>'
      : '<span class="text-ink/60">Your DP is set in your quote — usually around 30% of the total, depending on venue, number of clients and services.</span>';
  }

  function hsFillWeddingSummary(selector) {
    if (!hsSelectedWeddingPackage) return;
    const pkg = hsSelectedWeddingPackage;
    const dp = hsEstimatedDp(pkg);
    const values = {
      name: pkg.name,
      price: hsPeso(pkg.price),
      fee: dp.fixed ? hsPeso(dp.amount) : 'about ' + hsPeso(dp.amount) + ' (estimate — confirmed in your quote)',
      balance: (dp.fixed ? '' : 'about ') + hsPeso(pkg.price - dp.amount) + ' (paid on the event day)',
      venue: document.getElementById('hsVenue').value.trim() || '—',
      date: hsFmtDate(document.getElementById('hsDate').value),
      time: hsFmtTime(document.getElementById('hsTime').value)
    };
    document.querySelectorAll(selector).forEach(el => { el.textContent = values[el.dataset.weddingSummary || el.dataset.reviewWedding]; });
  }

  function hsSelectWeddingPackage(index) {
    hsSelectedWeddingPackage = HS_WEDDING_PACKAGES[index];
    hsSelectedServices = [`Wedding ${hsSelectedWeddingPackage.name}`];
    document.querySelectorAll('.hs-wedding-card').forEach((card, cardIndex) => {
      const selected = cardIndex === index;
      card.classList.toggle('border-copper', selected);
      card.classList.toggle('bg-copper/10', selected);
      card.classList.toggle('ring-1', selected);
      card.classList.toggle('ring-copper/40', selected);
      card.classList.toggle('border-bronze/30', !selected);
      card.classList.toggle('bg-sand-100', !selected);
      const button = card.querySelector('[data-package-select]');
      button.textContent = selected ? 'Selected' : 'Select Package';
      button.classList.toggle('bg-plum', selected);
      button.classList.toggle('text-white', selected);
    });
    document.getElementById('hsWeddingLiveSummary')?.classList.remove('hidden');
    hsFillWeddingSummary('[data-wedding-summary]');
  }

  function hsSyncWeddingPackages() {
    document.getElementById('hsOtherEventRow')?.classList.toggle('hidden', document.getElementById('hsEventType').value !== 'Other');
    const isWedding = document.getElementById('hsEventType')?.value === 'Wedding';
    const wasWedding = hsWeddingMode;
    hsWeddingMode = isWedding;
    document.getElementById('hsWeddingPackages')?.classList.toggle('hidden', !isWedding);
    document.getElementById('hsServiceCards')?.classList.toggle('hidden', isWedding);
    document.getElementById('hsNonWeddingPricingNote')?.classList.toggle('hidden', isWedding);
    const heading = document.getElementById('hsServicesHeading');
    const intro = document.getElementById('hsServicesIntro');
    if (heading) heading.textContent = isWedding ? 'Select Wedding Package' : 'What Services Do You Need?';
    if (intro) intro.textContent = isWedding ? 'Choose the package that best fits your wedding day.' : 'Select all that apply.';
    if (isWedding) {
      hsSelectedServices = hsSelectedWeddingPackage ? [`Wedding ${hsSelectedWeddingPackage.name}`] : [];
      hsRenderWeddingPackages();
    } else {
      hsSelectedWeddingPackage = null;
      if (wasWedding) hsSelectedServices = Array.from(document.querySelectorAll('.hs-svc.border-copper')).map(button => button.dataset.svc);
      document.getElementById('hsWeddingLiveSummary')?.classList.add('hidden');
    }
  }

  function hsGo(step, noScroll) {
    document.querySelectorAll('.hs-step').forEach(el => {
      el.classList.toggle('hidden', Number(el.dataset.step) !== step);
    });
    document.querySelectorAll('.hs-prog').forEach(el => {
      const p = Number(el.dataset.p);
      const bar = el.querySelector('span');
      const lbl = el.querySelectorAll('span')[1];
      const done = p <= step || step === 5;
      bar.className = 'block h-1 rounded-full ' + (done ? 'bg-copper' : 'bg-bronze/30');
      lbl.className = 'block text-[10px] font-bold uppercase tracking-widest mt-2 ' + (done ? 'text-copper-dark' : 'text-ink/40');
    });
    if (!noScroll) document.getElementById('hsRequest')?.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  function hsNext(from) {
    if (from === 1) {
      const name = document.getElementById('hsName').value.trim();
      const phone = document.getElementById('hsPhone').value.trim();
      if (!name) { showToast('Please enter your full name'); return; }
      if (!/^09\d{9}$/.test(phone)) { showToast('Please enter a valid 11-digit mobile number'); return; }
      // Required: guests track and reschedule with reference + email, and get updates there.
      const email = document.getElementById('hsEmail').value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('Please enter a valid email address — you\'ll use it to track and reschedule your request'); return; }

      hsGo(2);
    } else if (from === 2) {
      if (!document.getElementById('hsEventType').value) { showToast('Please choose your event type'); return; }
      if (!document.getElementById('hsDate').value) { showToast('Please select a preferred date'); return; }
      if (!document.getElementById('hsTime').value) { showToast('Please select a preferred time'); return; }
      if (!document.getElementById('hsClients').value) { showToast('Please enter the number of clients'); return; }
      if (!document.getElementById('hsVenue').value.trim()) { showToast('Please enter the venue or address'); return; }
      if (!hsValidateSchedule()) return;
      if (document.getElementById('hsEventType').value === 'Other' && !document.getElementById('hsOtherEvent').value.trim()) { showToast('Please specify the event type.'); return; }
      hsGo(3);
    } else if (from === 3) {
      if (document.getElementById('hsEventType').value === 'Wedding' && !hsSelectedWeddingPackage) { showToast('Please select a wedding package'); return; }
      if (!hsSelectedServices.length) { showToast('Please select at least one service'); return; }
      hsBuildReview();
      hsGo(4);
    }
  }

  function hsToggleSvc(btn) {
    const svc = btn.dataset.svc;
    const on = hsSelectedServices.includes(svc);
    if (on) {
      hsSelectedServices = hsSelectedServices.filter(s => s !== svc);
      btn.classList.remove('border-copper', 'bg-copper/10', 'ring-1', 'ring-copper/40');
      btn.classList.add('border-bronze/30', 'bg-sand-100');
    } else {
      hsSelectedServices.push(svc);
      btn.classList.add('border-copper', 'bg-copper/10', 'ring-1', 'ring-copper/40');
      btn.classList.remove('border-bronze/30', 'bg-sand-100');
    }
  }

  function hsFmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    return isNaN(dt) ? d : dt.toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' });
  }

  function hsFmtTime(t) {
    if (!t) return '—';
    const [h, m] = t.split(':').map(Number);
    const ap = h >= 12 ? 'PM' : 'AM';
    const hr = h % 12 === 0 ? 12 : h % 12;
    return hr + ':' + String(m).padStart(2, '0') + ' ' + ap;
  }

  function hsValidateSchedule() {
    const date = document.getElementById('hsDate').value;
    const time = document.getElementById('hsTime').value;
    if (!date || !time || new Date(date + 'T' + time + ':00+08:00').getTime() <= Date.now()) {
      showToast('Please choose a future date and time.');
      return false;
    }
    return true;
  }

  ['hsDate','hsTime'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', () => {
      if (hsSelectedWeddingPackage) hsFillWeddingSummary('[data-wedding-summary]');
    });
  });
  document.getElementById('hsEventType')?.addEventListener('change', hsSyncWeddingPackages);

  function hsBuildReview() {
    const email = document.getElementById('hsEmail').value.trim();
    document.getElementById('rvName').textContent = document.getElementById('hsName').value.trim();
    document.getElementById('rvPhone').textContent = document.getElementById('hsPhone').value.trim();
    document.getElementById('rvEmail').textContent = email || '—';
    document.getElementById('rvEmailRow').classList.toggle('hidden', !email);
    document.getElementById('rvEvent').textContent = document.getElementById('hsEventType').value === 'Other' ? document.getElementById('hsOtherEvent').value.trim() : document.getElementById('hsEventType').value;
    document.getElementById('rvDate').textContent = hsFmtDate(document.getElementById('hsDate').value);
    document.getElementById('rvTime').textContent = hsFmtTime(document.getElementById('hsTime').value);
    document.getElementById('rvClients').textContent = document.getElementById('hsClients').value;
    document.getElementById('rvVenue').textContent = document.getElementById('hsVenue').value.trim();
    const vd = document.getElementById('hsVenueDetails').value.trim();
    document.getElementById('rvVenueDetails').textContent = vd;
    document.getElementById('rvServices').innerHTML = hsSelectedServices
      .map(s => '<li class="flex gap-2.5"><span class="shrink-0 w-1.5 h-1.5 rounded-full bg-copper mt-1.75"></span><span>' + s + '</span></li>').join('');
    const isWedding = document.getElementById('hsEventType').value === 'Wedding';
    document.getElementById('rvStandardServicesCard').classList.toggle('hidden', isWedding);
    document.getElementById('rvWeddingPackageCard').classList.toggle('hidden', !isWedding);
    if (isWedding) hsFillWeddingSummary('[data-review-wedding]');
    hsRenderDpEstimate();
    document.getElementById('rvNotes').textContent = document.getElementById('hsNotes').value.trim() || '—';
  }

  function hsSubmit() {
    if (!hsValidateSchedule()) return;
    const name = document.getElementById('hsName').value.trim();
    const phone = document.getElementById('hsPhone').value.trim();
    const email = document.getElementById('hsEmail').value.trim();
    const venue = document.getElementById('hsVenue').value.trim();
    const venueDetails = document.getElementById('hsVenueDetails').value.trim();
    const eventType = document.getElementById('hsEventType').value === 'Other' ? document.getElementById('hsOtherEvent').value.trim() : document.getElementById('hsEventType').value;
    const date = document.getElementById('hsDate').value;
    const time = document.getElementById('hsTime').value;
    const clients = document.getElementById('hsClients').value;
    const notes = document.getElementById('hsNotes').value.trim();

    if (!document.getElementById('hsAgreeTerms')?.checked) { showToast('Please agree to the Terms & Conditions'); return; }

    const btn = document.getElementById('hsSubmitBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }

    const formData = new FormData();
    formData.append('fullname', name);
    formData.append('contact', phone);
    formData.append('email', email);
    formData.append('address', venue);
    formData.append('venueDetails', venueDetails);
    formData.append('eventType', eventType);
    if (document.getElementById('hsEventType').value === 'Wedding') {
      if (!hsSelectedWeddingPackage) { if (btn) btn.disabled = false; showToast('Please choose a wedding package.'); return; }
      formData.append('weddingPackage', hsSelectedWeddingPackage.name.slice(-1));
    }
    formData.append('date', date);
    formData.append('time', time);
    formData.append('clients', clients);
    hsSelectedServices.forEach(s => formData.append('services[]', s));
    formData.append('requests', notes);
    formData.append('agreedToTerms', '1');

    fetch('backend/public/submitGuestHomeService.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (btn) { btn.disabled = false; btn.textContent = 'Submit Home Service Request'; }
        if (!res.success) { showToast(res.message); return; }

        const ref = res.reference;
        document.getElementById('hsRef').textContent = ref;
        const submittedSummary = eventType === 'Wedding' && hsSelectedWeddingPackage ? [
          ['Wedding Package', hsSelectedWeddingPackage.name],
          ['Package Price', hsPeso(hsSelectedWeddingPackage.price)],
          ['Reservation Fee', hsSelectedWeddingPackage.reservationFee === null ? 'To be confirmed after review.' : hsPeso(hsSelectedWeddingPackage.reservationFee)],
          ['Remaining Balance', hsSelectedWeddingPackage.reservationFee === null ? 'To be confirmed after review.' : hsPeso(hsSelectedWeddingPackage.price - hsSelectedWeddingPackage.reservationFee)],
          ['Venue Address', venue], ['Preferred Date', hsFmtDate(date)], ['Preferred Time', hsFmtTime(time)],
          ['Status', 'Under Review']
        ] : [
          ['Event Type', eventType], ['Date', hsFmtDate(date)], ['Time', hsFmtTime(time)],
          ['Clients', clients], ['Location', venue], ['Status', 'Under Review']
        ];
        document.getElementById('hsSummary').innerHTML = submittedSummary
          .map(r => '<p><span class="text-ink/50">' + r[0] + ':</span> <b>' + escAttr(r[1]) + '</b></p>').join('');
        document.getElementById('trkRef').value = ref;
        document.getElementById('trkEmail').value = email; // pre-fill the tracker (reference + email)
        hsGo(5);
      })
      .catch(() => {
        if (btn) { btn.disabled = false; btn.textContent = 'Submit Home Service Request'; }
        showToast('A network error occurred. Please try again.');
      });
  }

  function hsReset() {
    ['hsName','hsPhone','hsEmail','hsClients','hsDate','hsTime','hsVenue','hsVenueDetails','hsNotes','hsOtherEvent'].forEach(id => {
      const el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('hsEventType').value = '';
    const agreeBox = document.getElementById('hsAgreeTerms');
    if (agreeBox) agreeBox.checked = false;
    hsSelectedServices = [];
    hsSelectedWeddingPackage = null;
    document.querySelectorAll('.hs-svc').forEach(b => {
      b.classList.remove('border-copper', 'bg-copper/10', 'ring-1', 'ring-copper/40');
      b.classList.add('border-bronze/30', 'bg-sand-100');
    });
    document.getElementById('hsAvailResult').classList.add('hidden');
    document.getElementById('hsWeddingLiveSummary')?.classList.add('hidden');
    hsSyncWeddingPackages();
    hsGo(1);
  }

  function hsTrack() {
    const ref = document.getElementById('trkRef').value.trim().toUpperCase();
    // Home services are tracked with reference + the email given on the request.
    const email = document.getElementById('trkEmail').value.trim();
    const box = document.getElementById('trkResult');
    const fb = document.getElementById('hsFeedback');
    box.classList.remove('hidden');

    if (!ref || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      box.innerHTML = '<div class="rounded-xl bg-copper/12 border border-copper/30 text-copper-dark p-4 text-[13px]">Enter your reference number and the email address you used for the request.</div>';
      fb.classList.add('hidden');
      return;
    }

    box.innerHTML = '<div class="text-[13px] text-ink/50">Looking up your request…</div>';
    fb.classList.add('hidden');

    fetch('backend/public/trackBooking.php?reference=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(email))
      .then(r => r.json())
      .then(res => {
        if (!res.success) {
          box.innerHTML = '<div class="rounded-xl bg-copper/12 border border-copper/30 text-copper-dark p-4 text-[13px]">' + escAttr(res.message) + '</div>';
          return;
        }
        res.email = email; // proves ownership again if the guest reschedules from here
        box.innerHTML = renderTrackingResult(res);

        if (res.canReview) {
          hsFeedbackRef = res.reference;
          hsFeedbackEmail = email;
          hsRatings.service = 0; hsRatings.staff = 0;
          document.querySelectorAll('#hsFeedback .fb-star').forEach(s => { s.className = 'fb-star text-bronze/40 hover:text-copper transition'; });
          document.getElementById('fbFor').textContent = 'For booking ' + res.reference + ' — ' + (res.event || res.services || '') + '.';
          fb.classList.remove('hidden');
        } else {
          fb.classList.add('hidden');
        }
      })
      .catch(() => {
        box.innerHTML = '<div class="rounded-xl bg-copper/12 border border-copper/30 text-copper-dark p-4 text-[13px]">A network error occurred. Please try again.</div>';
      });
  }

  function hsStar(btn, value) {
    const group = btn.closest('[data-stars]');
    const key = group.dataset.stars;
    hsRatings[key] = value;
    group.querySelectorAll('.fb-star').forEach((s, i) => {
      s.className = 'fb-star transition ' + (i < value ? 'text-copper' : 'text-bronze/40 hover:text-copper');
    });
  }

  function hsSubmitFeedback() {
    if (!hsRatings.service) { showToast('Please rate your experience first'); return; }
    if (!hsFeedbackRef || !hsFeedbackEmail) { showToast('Please look up your request again.'); return; }

    const formData = new FormData();
    formData.append('reference', hsFeedbackRef);
    formData.append('email', hsFeedbackEmail);
    formData.append('rating', hsRatings.service);
    if (hsRatings.staff) formData.append('staff_rating', hsRatings.staff);
    formData.append('comment', document.getElementById('fbText').value.trim());

    fetch('backend/public/submitGuestFeedback.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        showToast(res.message);
        if (!res.success) return;
        document.getElementById('fbText').value = '';
        hsRatings.service = 0; hsRatings.staff = 0;
        document.querySelectorAll('#hsFeedback .fb-star').forEach(s => { s.className = 'fb-star text-bronze/40 hover:text-copper transition'; });
        document.getElementById('hsFeedback').classList.add('hidden');
      })
      .catch(() => showToast('A network error occurred. Please try again.'));
  }



  const BRANCH_LABEL = { daraga: 'Daraga Main', yashano: 'Yashano Mall', cabangan: 'Cabangan' };
  // Real branch_id values from the `branches` table — the booking select's
  // <option> values are these IDs, not the labels above.
  const BRANCH_ID_BY_KEY = { daraga: 1, yashano: 2, cabangan: 3 };
  const BRANCH_LABEL_BY_ID = Object.fromEntries(
    Object.entries(BRANCH_ID_BY_KEY).map(([key, id]) => [id, BRANCH_LABEL[key]])
  );
  const BRANCH_HOURS = {
    daraga:   { open: 8 * 60,      close: 20 * 60 },
    yashano:  { open: 9 * 60 + 30, close: 20 * 60 },
    cabangan: { open: 8 * 60,      close: 20 * 60 }
  };

  function refreshLiveStatus() {
    const now = new Date();
    const mins = now.getHours() * 60 + now.getMinutes();
    Object.keys(BRANCH_HOURS).forEach(key => {
      const h = BRANCH_HOURS[key];
      const isOpen = mins >= h.open && mins < h.close;
      const fmt = m => {
        const hr = Math.floor(m / 60), mn = m % 60;
        const ampm = hr >= 12 ? 'PM' : 'AM';
        const h12 = hr % 12 === 0 ? 12 : hr % 12;
        return mn ? `${h12}:${String(mn).padStart(2, '0')} ${ampm}` : `${h12} ${ampm}`;
      };
      document.querySelectorAll(`.live-dot[data-live="${key}"]`)
        .forEach(d => d.classList.toggle('closed', !isOpen));
      document.querySelectorAll(`[data-live-label="${key}"]`)
        .forEach(l => l.textContent = isOpen ? `Open now until ${fmt(h.close)}` : `Opens ${fmt(h.open)}`);
    });
  }

  function goBranchMenu(key) {
    switchTab('services-tab');
    const btn = document.querySelector(`#svcTabs [data-s="${key}"]`);
    if (btn) btn.click();
  }

  const BRANCH_PICKER_CARDS = [
    {
      key: 'daraga', delay: 'sr-d1', badge: 'Flagship', name: 'Daraga Main',
      address: 'Salon &amp; Make-Up Studio · Regidor St.',
      blurb: 'The full menu hair, nails, skin, brows, lashes, and event make-up, plus the skin care centre.',
      tags: ['Hair', 'Nails', 'Skin', 'Make-up'], hours: '8:00 AM – 8:00 PM, daily'
    },
    {
      key: 'yashano', delay: 'sr-d2', badge: 'Mall studio', name: 'Yashano Mall',
      address: "Skin Brows · 2F, beside Angel's Pizza",
      blurb: 'Facials, peels, and brow work in the middle of Legazpi easy to slot into a mall run.',
      tags: ['Skin', 'Brows', 'Lashes'], hours: '9:30 AM – 8:00 PM, daily'
    },
    {
      key: 'cabangan', delay: 'sr-d3', badge: 'Lash &amp; brow hub', name: 'Cabangan',
      address: 'Lash &amp; Brows · Rizal St., Brgy. 18',
      blurb: 'Lash extensions, lifts, and brow shaping plus cuts, colour, and rebonding.',
      tags: ['Lashes', 'Brows', 'Hair'], hours: '8:00 AM – 8:00 PM, daily'
    }
  ];

  function renderBranchPickerCards() {
    const grid = document.getElementById('branchPickerGrid');
    if (!grid) return;
    const clockIcon = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>`;
    grid.innerHTML = BRANCH_PICKER_CARDS.map(b => `
      <article class="sr ${b.delay} branch-pick bg-sand-100 border border-bronze/25 rounded-3xl p-7 flex flex-col">
        <div class="flex items-start justify-between gap-3">
          <span class="text-[10px] font-bold tracking-[0.16em] uppercase text-copper-dark bg-copper/15 rounded-full px-3 py-1.5">${b.badge}</span>
          <span class="text-[11px] font-bold flex items-center gap-1.5 text-ink/55">
            <span class="live-dot" data-live="${b.key}"></span>
            <span data-live-label="${b.key}">—</span>
          </span>
        </div>
        <h3 class="font-display text-[1.6rem] leading-tight mt-5">${b.name}</h3>
        <p class="text-[13px] text-ink/55 mt-1.5">${b.address}</p>
        <p class="text-sm text-ink/70 mt-4 leading-relaxed">${b.blurb}</p>
        <div class="flex flex-wrap gap-2 mt-5">
          ${b.tags.map(t => `<span class="text-[11px] font-semibold rounded-full border border-bronze/40 px-3 py-1.5">${t}</span>`).join('')}
        </div>
        <p class="text-[12px] text-ink/50 mt-5 flex items-center gap-2">${clockIcon}${b.hours}</p>
        <div class="flex gap-2.5 mt-6 pt-6 border-t border-bronze/25">
          <button onclick="goBranchMenu('${b.key}')" class="flex-1 rounded-full bg-plum text-white text-[13px] font-bold py-3 hover:brightness-110 transition">See menu</button>
          <button onclick="bookService('', '', '${b.key}')" class="flex-1 rounded-full border border-ink/20 text-[13px] font-bold py-3 hover:border-plum hover:text-plum transition">Book here</button>
        </div>
      </article>
    `).join('');
  }
  renderBranchPickerCards();

  (function () {
    const els = document.querySelectorAll('.sr');
    if (!('IntersectionObserver' in window)) { els.forEach(e => e.classList.add('in')); return; }
    const io = new IntersectionObserver(entries => {
      entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
    els.forEach(e => io.observe(e));
  })();

  (function () {
    const stats = document.querySelectorAll('.hero-stat b[data-count]');
    if (!stats.length) return;
    const run = el => {
      const target = parseInt(el.dataset.count, 10);
      const pre = el.dataset.prefix || '';
      const suf = el.dataset.suffix || '';
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        el.textContent = pre + target + suf; return;
      }
      const dur = 1100, t0 = performance.now();
      const tick = now => {
        const p = Math.min((now - t0) / dur, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = pre + Math.round(target * eased) + suf;
        if (p < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    };
    if (!('IntersectionObserver' in window)) { stats.forEach(run); return; }
    const io = new IntersectionObserver(entries => {
      entries.forEach(en => { if (en.isIntersecting) { run(en.target); io.unobserve(en.target); } });
    }, { threshold: 0.6 });
    stats.forEach(s => io.observe(s));
  })();

  const BA_LOOKS = [
    { kicker: 'Hair', title: 'Organic Rebonding', price: '₱999', where: 'Daraga Main · Cabangan',
      blurb: 'Any length, one sitting. Straightened without the harsh chemical smell and sealed with keratin, so it grows out soft instead of snapping at the line.',
      before: 'assets/image/Before rebond.jpg',
      after:  'assets/image/after rebond.jpg' },
    { kicker: 'Brows', title: 'Premium Microblading', price: '₱2,500', where: 'Daraga Main · Yashano Mall',
      blurb: 'Hair-stroke brows mapped to your face, not to a stencil. Healed shape lasts through the year with one touch-up.',
      before: 'assets/image/latestenhancebeforeMicrobladding.jpg',
      after:  'assets/image/ehance after mricobladding.jpg' },
    { kicker: 'Skin', title: 'Basic Facial', price: '₱499', where: 'All three branches',
      blurb: 'Deep clean, extraction, and a calming mask. The one to book before an event, or every few weeks to keep congestion down.',
      before: 'assets/image/basic facial before.jpg',
      after:  'assets/image/basic facial after.jpg' }
  ];

  (function () {
    const reveal = document.getElementById('baReveal');
    const knob = document.getElementById('baKnob');
    if (!reveal || !knob) return;

    let pos = 50, active = 0;

    const setPos = v => {
      pos = Math.max(0, Math.min(100, v));
      reveal.style.setProperty('--pos', pos + '%');
      knob.setAttribute('aria-valuenow', Math.round(pos));
    };
    const fromEvent = e => {
      const r = reveal.getBoundingClientRect();
      const x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
      setPos((x / r.width) * 100);
    };

    let dragging = false;
    const start = e => { dragging = true; fromEvent(e); };
    const move = e => { if (!dragging) return; if (e.cancelable) e.preventDefault(); fromEvent(e); };
    const end = () => { dragging = false; };

    reveal.addEventListener('mousedown', start);
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    reveal.addEventListener('touchstart', start, { passive: true });
    window.addEventListener('touchmove', move, { passive: false });
    window.addEventListener('touchend', end);

    knob.addEventListener('keydown', e => {
      if (e.key === 'ArrowLeft') { setPos(pos - 4); e.preventDefault(); }
      if (e.key === 'ArrowRight') { setPos(pos + 4); e.preventDefault(); }
      if (e.key === 'Home') { setPos(0); e.preventDefault(); }
      if (e.key === 'End') { setPos(100); e.preventDefault(); }
    });

    const applyLook = i => {
      active = i;
      const L = BA_LOOKS[i];
      document.getElementById('baAfter').src = L.before;
      document.getElementById('baBefore').src = L.after;
      document.getElementById('baKicker').textContent = L.kicker;
      document.getElementById('baTitle').textContent = L.title;
      document.getElementById('baBlurb').textContent = L.blurb;
      document.getElementById('baPrice').textContent = L.price;
      document.getElementById('baWhere').textContent = L.where;
      setPos(50);
    };

    document.querySelectorAll('.ba-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.ba-tab').forEach(b => b.classList.remove('on'));
        btn.classList.add('on');
        applyLook(parseInt(btn.dataset.ba, 10));
      });
    });

    document.getElementById('baBook')?.addEventListener('click', () => {
      const L = BA_LOOKS[active];
      bookService(L.title, L.price);
    });

    applyLook(0);
  })();


  (function () {
    const rail = document.getElementById('trkFill');
    if (!rail) return;
    const nodes = document.querySelectorAll('.trk-node');
    const msg = document.getElementById('trkMsg');
    const COPY = [
      'Request received. The Daraga front desk is checking the slot.',
      'Confirmed for 20 December, 8:00 AM. A reminder text goes out the day before.',
      'Your stylist has started. Sit back this is the good part.',
      'All done. A short rating form is waiting for you it takes about twenty seconds.'
    ];
    let i = 0, started = false;

    const paint = () => {
      nodes.forEach((n, k) => {
        n.classList.toggle('on', k <= i);
        n.classList.toggle('now', k === i);
      });
      rail.style.width = (i / (nodes.length - 1) * 100) + '%';
      msg.textContent = COPY[i];
    };

    const advance = () => { i = (i + 1) % nodes.length; paint(); };

    const begin = () => {
      if (started) return;
      started = true;
      paint();
      setInterval(advance, 2600);
    };

    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(entries => {
        entries.forEach(en => { if (en.isIntersecting) { begin(); io.disconnect(); } });
      }, { threshold: 0.4 });
      io.observe(rail.closest('.trk-rail'));
    } else { begin(); }
  })();

  (function () {
    const slides = document.querySelectorAll('.testi-slide');
    const dots = document.querySelectorAll('.testi-dot');
    const box = document.getElementById('testiBox');
    if (!slides.length) return;
    let i = 0, timer = null;

    const go = n => {
      i = (n + slides.length) % slides.length;
      slides.forEach((s, k) => s.classList.toggle('on', k === i));
      dots.forEach((d, k) => d.classList.toggle('on', k === i));
    };
    const play = () => { timer = setInterval(() => go(i + 1), 6000); };
    const stop = () => { clearInterval(timer); };

    dots.forEach(d => d.addEventListener('click', () => { stop(); go(parseInt(d.dataset.i, 10)); play(); }));
    box?.addEventListener('mouseenter', stop);
    box?.addEventListener('mouseleave', play);
    play();
  })();


  /* ============================================================
     APPOINTMENT BOOKING ENGINE
     Implements the Customer/Guest storyboard: branch selection →
     real-time slot + service loading → email OTP verification →
     booking reference → status tracking → post-service feedback.
     ============================================================ */

  // Matches the `appointments.status` ENUM in the database (see
  // trackBooking.php) — 'Cancelled', 'No-Show', and 'Reviewed' are
  // terminal/derived states handled separately, not steps here.
  const APPT_STATUSES = ['Pending', 'Confirmed', 'In Progress', 'Completed'];

  // Current selection carried from the menu / lookbook / look finder into the modal.
  let currentBooking = { service: '', price: '' };
  let bookNotifyChoice = 'SMS';
  let selectedSlotLabel = '';
  let selectedStaffId = '';
  let selectedStaffName = '';
  // One stylist per service: { [serviceId]: { id, name } } (see loadStaffList).
  let selectedServiceStaff = {};
  let pendingBooking = null;
  let bookDepositLocked = false;
  let bookDepositInfo = { amount: 0, method: '', reference: '' };

  let bookReservationQuote = null;
  let bookQuoteRequest = 0;
  const formatReservationMoney = value => '₱' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function computeSelectedServicesSubtotal() {
    return bookReservationQuote?.serviceTotal ?? 0;
  }
  let lastBookingPhone = '';
  let bookOtpCooldownTimer = null;

  function fmtApptDate(d) {
    if (!d) return '';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' });
  }
  function escAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function renderTrackingResult(res) {
    const isHome = res.type === 'home_service';
    const statuses = isHome ? HS_STATUSES : APPT_STATUSES;
    const terminal = res.status === 'Cancelled' || res.status === 'No-Show';
    const effectiveStatus = res.status === 'Reviewed' ? 'Completed' : res.status;
    const current = statuses.indexOf(effectiveStatus);

    let statusBlock;
    if (terminal) {
      const label = res.status === 'No-Show' ? 'marked as a no-show' : 'cancelled';
      statusBlock = '<div class="rounded-xl bg-copper/12 border border-copper/30 text-copper-dark p-4 text-[13px]">This booking was ' + label + '.</div>';
    } else {
      const nodes = statuses.map((st, i) => {
        const done = i <= current;
        const label = isHome ? hsStatusLabel(st) : st;
        return '<li class="flex items-center gap-3">' +
          '<span class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold shrink-0 ' +
          (done ? 'bg-plum text-white' : 'border border-bronze/40 text-ink/30') + '">' + (done ? '✓' : (i + 1)) + '</span>' +
          '<span class="text-sm ' + (done ? 'font-bold text-ink' : 'text-ink/40') + '">' + escAttr(label) + '</span></li>';
      }).join('<li class="ml-3 h-4 border-l border-bronze/30"></li>');
      statusBlock = '<span class="text-[10px] font-bold uppercase tracking-widest text-copper">Booking status</span><ul class="mt-4 space-y-0">' + nodes + '</ul>';
    }

    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const detail = isHome
      ? [['Type', 'Home &amp; event service'], ['Event', escAttr(res.event)], ['Date', hsFmtDate(res.date)],
         ['Location', escAttr(res.venue)],
         ...(res.quote ? [['Quote', peso(res.quote)]] : []),
         ...(res.reservationFee ? [['Reservation fee', peso(res.reservationFee) + (res.feePaid ? ' — paid' : ' — not yet paid')]] : []),
         ['Details', escAttr(res.requests || '—').replace(/\n/g, '<br>')]]
      : [['Type', 'In-salon appointment'], ['Branch', escAttr(res.branch || '—')],
         ['Service', escAttr(res.services) + (res.price ? ' · ₱' + Number(res.price).toFixed(0) : '')],
         ['Date', fmtApptDate(res.date) + ' · ' + escAttr(res.time)]];

    return '<div class="flex flex-wrap items-center justify-between gap-3 mb-6">' +
        '<div><span class="text-[10px] font-bold uppercase tracking-widest text-ink/45">Reference</span>' +
        '<div class="font-display text-xl">' + escAttr(res.reference) + '</div></div>' +
        '<span class="rounded-full bg-plum/10 text-plum text-[11px] font-bold uppercase tracking-widest px-4 py-2">' + escAttr(isHome ? hsStatusLabel(res.status) : res.status) + '</span>' +
      '</div>' +
      statusBlock +
      '<div class="mt-6 pt-5 border-t border-bronze/25 text-sm space-y-1.5">' +
        detail.map(r => '<p><span class="text-ink/50">' + r[0] + ':</span> <b>' + r[1] + '</b></p>').join('') +
      '</div>' +
      // Home service reservation fee (down payment) -- paid online via PayMongo.
      (isHome && res.payUrl
        ? '<div class="mt-5 rounded-2xl bg-copper/10 border border-copper/30 p-4 text-sm">' +
            '<p class="font-bold text-ink">Pay your ' + peso(res.reservationFee) + ' reservation fee to confirm this request.</p>' +
            '<p class="text-ink/60 text-[13px] mt-1">The remaining balance is paid on the event day.</p>' +
            '<a href="' + escAttr(res.payUrl) + '" class="mt-3 inline-flex w-full justify-center rounded-full bg-plum hover:bg-plum/85 text-white font-bold py-3 transition">Pay ' + peso(res.reservationFee) + ' now — GCash, Maya or card</a>' +
          '</div>'
        : '') +
      // Remaining balance after the DP (paid online once the salon sends the link).
      (isHome && res.quote && res.amountPaid > 0 && res.status !== 'Cancelled'
        ? (res.balanceDue > 0
          ? '<div class="mt-5 rounded-2xl bg-copper/10 border border-copper/30 p-4 text-sm">' +
              '<p class="font-bold text-ink">Remaining balance: ' + peso(res.balanceDue) + '</p>' +
              '<p class="text-ink/60 text-[13px] mt-1">Paid ' + peso(res.amountPaid) + ' of ' + peso(res.quote) + '.' + (res.balancePayUrl ? '' : ' Pay in cash on the event day, or ask us for an online payment link.') + '</p>' +
              (res.balancePayUrl ? '<a href="' + escAttr(res.balancePayUrl) + '" class="mt-3 inline-flex w-full justify-center rounded-full bg-plum hover:bg-plum/85 text-white font-bold py-3 transition">Pay ' + peso(res.balanceDue) + ' now — GCash, Maya or card</a>' : '') +
            '</div>'
          : '<div class="mt-5 rounded-2xl bg-[#DCFCE7] border border-[#86EFAC] p-4 text-sm font-bold text-[#166534]">Fully paid ✓ — thank you!</div>')
        : '') +
      // Home service online reschedule (backend/public/rescheduleHomeService.php).
      (isHome ? renderHomeServiceReschedule(res) : '');
  }

  function renderHomeServiceReschedule(res) {
    if (!res.canReschedule) {
      return res.rescheduleNote && !['Completed', 'Cancelled'].includes(res.status)
        ? '<p class="mt-5 text-[12px] text-ink/55">Need a different date? ' + escAttr(res.rescheduleNote) + ' Call 0918 536 8016.</p>'
        : '';
    }
    const min = new Date(Date.now() + res.rescheduleCutoffDays * 86400000);
    const minDate = [min.getFullYear(), String(min.getMonth() + 1).padStart(2, '0'), String(min.getDate()).padStart(2, '0')].join('-');
    // Rescheduling needs the email proof (found via the appointment tracker by phone? point them to the email tracker).
    if (!res.email) {
      return '<p class="mt-5 text-[12px] text-ink/55">Need a different date? Look this request up in <b>Track Your Home Service</b> (Home &amp; Events) with your reference and email to reschedule online.</p>';
    }
    return '<details class="hs-resched mt-5 rounded-2xl border border-bronze/40 bg-sand-100 p-4 text-sm" data-reference="' + escAttr(res.reference) + '" data-email="' + escAttr(res.email) + '">' +
        '<summary class="cursor-pointer font-bold text-plum">Need a different date? Reschedule online</summary>' +
        '<p class="mt-2 text-[12px] text-ink/60">Currently <b>' + escAttr(hsFmtDate(res.date)) + (res.time ? ' at ' + escAttr(res.time) : '') + '</b>. ' +
          'Pick a new date at least ' + res.rescheduleCutoffDays + ' days away. Your quote, reservation fee and assigned team carry over' +
          ' (the team must be free on the new date). ' + res.reschedulesLeft + ' online change' + (res.reschedulesLeft === 1 ? '' : 's') + ' left. ' +
          'A confirmation is emailed to <b>' + escAttr(res.email) + '</b>.</p>' +
        '<div class="mt-3 grid grid-cols-2 gap-2">' +
          '<input type="date" class="hs-resched-date px-3 py-2 border border-bronze/50 bg-white rounded-lg text-[13px]" min="' + minDate + '">' +
          '<input type="time" class="hs-resched-time px-3 py-2 border border-bronze/50 bg-white rounded-lg text-[13px]" value="09:00">' +
        '</div>' +
        '<button type="button" class="hs-resched-btn mt-3 w-full rounded-full bg-plum hover:bg-plum/85 text-white font-bold py-2.5 transition">Move my booking</button>' +
        '<p class="hs-resched-msg mt-2 text-[12px] hidden"></p>' +
      '</details>';
  }

  // One delegated handler covers every rendered Track result.
  document.addEventListener('click', e => {
    const btn = e.target.closest('.hs-resched-btn');
    if (!btn) return;
    const box = btn.closest('.hs-resched');
    const msg = box.querySelector('.hs-resched-msg');
    const date = box.querySelector('.hs-resched-date').value;
    const time = box.querySelector('.hs-resched-time').value;
    const say = (text, ok) => { msg.textContent = text; msg.className = 'hs-resched-msg mt-2 text-[12px] font-semibold ' + (ok ? 'text-[#15803D]' : 'text-copper-dark'); };
    if (!date || !time) { say('Please choose a new date and time.', false); return; }
    btn.disabled = true;
    const body = new FormData();
    body.append('reference', box.dataset.reference);
    body.append('email', box.dataset.email);
    body.append('date', date);
    body.append('time', time);
    fetch('backend/public/rescheduleHomeService.php', { method: 'POST', body })
      .then(r => r.json())
      .then(res => {
        say(res.message || (res.success ? 'Rescheduled.' : 'Could not reschedule.'), res.success);
        if (res.success) { box.querySelectorAll('input').forEach(i => { i.disabled = true; }); btn.classList.add('hidden'); }
      })
      .catch(() => say('A network error occurred. Please try again.', false))
      .finally(() => { btn.disabled = false; });
  });

  function resetServiceList(message) {
    branchServicesRequestId++;
    const box = document.getElementById('bookServiceList');
    box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3 text-[13px] text-ink/50';
    box.innerHTML = message || 'Choose a branch to see its services.';
  }

  let branchServicesRequestId = 0;
  function loadBranchServices(branchId) {
    const box = document.getElementById('bookServiceList');
    box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3 text-[13px] text-ink/50';
    box.innerHTML = 'Loading services…';

    const requestId = ++branchServicesRequestId;
    fetch('backend/public/getBranchServices.php?branch=' + encodeURIComponent(branchId))
      .then(r => r.json())
      .then(res => {
        if (requestId !== branchServicesRequestId) return; // a newer branch selection superseded this response
        if (!res.success || !res.services.length) {
          box.innerHTML = '<span class="text-copper-dark">No services available for this branch right now.</span>';
          return;
        }
        // The branch's services listed directly (no category sections), with
        // a search box and a running "selected" summary.
        box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3';
        box.innerHTML =
          '<input type="search" id="bookServiceSearch" placeholder="Search services…" class="w-full mb-2 px-3 py-2 border border-bronze/50 bg-white rounded-lg text-[13px] focus:outline-none focus:border-plum">' +
          '<p id="bookServiceSummary" class="text-xs text-ink/60 mb-2">Select one or more services. You can pick a different stylist for each.</p>' +
          '<div class="max-h-64 overflow-y-auto pr-1">' +
          res.services.map(s =>
            '<label class="book-service-row flex items-center justify-between gap-3 py-1.5 cursor-pointer border-t border-bronze/15 first:border-t-0" data-search="' + escAttr(s.name.toLowerCase()) + '">' +
              '<span class="flex items-center gap-2 text-ink text-sm">' +
                '<input type="checkbox" class="book-service-check accent-plum" value="' + s.id + '" data-name="' + escAttr(s.name) + '" data-price="' + Number(s.price) + '" data-minutes="' + Number(s.durationMinutes || 30) + '"> ' +
                escAttr(s.name) + ' <small class="text-ink/45">' + escAttr(s.duration || (s.durationMinutes ? s.durationMinutes + ' min' : '')) + '</small>' +
              '</span>' +
              '<span class="text-ink/60 whitespace-nowrap text-sm">₱' + Number(s.price).toFixed(0) + '</span>' +
            '</label>').join('') +
          '<p id="bookServiceNoMatch" class="hidden py-3 text-center text-[12px] text-ink/50">No services match your search.</p>' +
          '</div>';

        const search = box.querySelector('#bookServiceSearch');
        search.addEventListener('input', () => {
          const q = search.value.trim().toLowerCase();
          let shown = 0;
          box.querySelectorAll('.book-service-row').forEach(row => {
            const match = !q || row.dataset.search.includes(q);
            row.classList.toggle('hidden', !match);
            if (match) shown++;
          });
          box.querySelector('#bookServiceNoMatch').classList.toggle('hidden', shown > 0);
        });
        let preselectedService = false;
        const menuPrice = Number(String(currentBooking.price || '').replace(/[^0-9.]/g, ''));
        box.querySelectorAll('.book-service-check').forEach(input => {
          input.checked = !preselectedService && input.dataset.name.toLowerCase() === currentBooking.service.toLowerCase()
            && (!menuPrice || Number(input.dataset.price) === menuPrice);
          if (input.checked) preselectedService = true;
        });
        updateServiceSummary();
        updateDepositRequiredDisplay();
        refreshSlotGridIfReady();
      })
      .catch(() => { if (requestId === branchServicesRequestId) box.innerHTML = '<span class="text-copper-dark">Couldn\'t load services — please try again.</span>'; });
  }

  /* "2 selected · 1 hr 30 min · ₱999" above the service list. */
  function updateServiceSummary() {
    const box = document.getElementById('bookServiceList');
    const checked = Array.from(box.querySelectorAll('.book-service-check:checked'));
    const summary = document.getElementById('bookServiceSummary');
    if (!summary) return;
    if (!checked.length) {
      summary.textContent = 'Select one or more services. You can pick a different stylist for each.';
      return;
    }
    const minutes = checked.reduce((sum, c) => sum + Number(c.dataset.minutes || 30), 0);
    const price = checked.reduce((sum, c) => sum + Number(c.dataset.price || 0), 0);
    const duration = (minutes >= 60 ? Math.floor(minutes / 60) + ' hr ' : '') + (minutes % 60 ? (minutes % 60) + ' min' : '');
    summary.innerHTML = '<b class="text-plum">' + checked.length + ' selected</b> · ' + duration.trim() + ' total · ₱' + price.toLocaleString('en-PH')
      + (checked.length > 1 ? ' · <span class="text-ink/50">done back-to-back, a stylist for each</span>' : '');
  }

  function resetStaffList(message) {
    document.getElementById('bookDepositGate').classList.add('hidden');
    staffListRequestId++;
    selectedStaffId = '';
    selectedStaffName = '';
    selectedServiceStaff = {};
    const section = document.getElementById('bookStaffSection');
    if (section) section.classList.add('hidden');
    const box = document.getElementById('bookStaffList');
    box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3 text-[13px] text-ink/50';
    box.innerHTML = message || 'Choose your branch, services, date, and time to see available staff.';
    renderWaitlistOption([]);
  }

  // Stylists who qualify but are booked at the chosen time can be
  // waitlisted: backend/public/joinWaitlist.php emails the guest if one of
  // that stylist's bookings on this date is cancelled.
  let waitlistContext = null;
  function renderWaitlistOption(busyStaff, context) {
    const wrap = document.getElementById('bookWaitlist');
    if (!wrap) return;
    waitlistContext = busyStaff.length ? context : null;
    wrap.classList.toggle('hidden', !busyStaff.length);
    document.getElementById('bookWaitlistStaff').innerHTML = busyStaff
      .map(s => '<option value="' + escAttr(s.id) + '">' + escAttr(s.name) + (s.role ? ' — ' + escAttr(s.role) : '') + '</option>').join('');
  }

  document.getElementById('bookWaitlistBtn')?.addEventListener('click', () => {
    if (!waitlistContext) return;
    const name = document.getElementById('bookWaitlistName').value.trim() || document.getElementById('bookName').value.trim();
    const email = document.getElementById('bookWaitlistEmail').value.trim() || document.getElementById('bookEmail').value.trim();
    if (!name || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('Enter your name and a valid email to join the waitlist'); return; }
    const btn = document.getElementById('bookWaitlistBtn');
    btn.disabled = true;
    const formData = new FormData();
    formData.append('branch', waitlistContext.branchId);
    formData.append('date', waitlistContext.date);
    formData.append('time', waitlistContext.time);
    formData.append('employeeId', document.getElementById('bookWaitlistStaff').value);
    formData.append('name', name);
    formData.append('email', email);
    formData.append('phone', document.getElementById('bookPhone')?.value.trim() || '');
    fetch('backend/public/joinWaitlist.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => showToast(res.message || (res.success ? "You're on the waitlist." : 'Could not join the waitlist.')))
      .catch(() => showToast('A network error occurred. Please try again.'))
      .finally(() => { btn.disabled = false; });
  });

  let staffListRequestId = 0;
  function loadStaffList(branchId, serviceIds, date, time) {
    document.getElementById('bookDepositGate').classList.add('hidden');
    selectedStaffId = '';
    selectedStaffName = '';
    document.getElementById('bookStaffSection').classList.remove('hidden');
    const box = document.getElementById('bookStaffList');
    box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3 text-[13px] text-ink/50';
    box.innerHTML = 'Loading available staff…';

    const requestId = ++staffListRequestId;
    const params = new URLSearchParams({ branch: branchId, date, time });
    serviceIds.forEach(id => params.append('services[]', id));
    fetch('backend/public/getAvailableStaff.php?' + params)
      .then(r => r.json())
      .then(res => {
        if (requestId !== staffListRequestId) return; // a newer selection superseded this response
        const services = res.success ? (res.services || []) : [];
        if (!services.length) {
          renderWaitlistOption([], { branchId, date, time });
          box.innerHTML = '<span class="text-copper-dark">Couldn\'t load staff for this schedule. Please choose another time.</span>';
          return;
        }
        // Anyone busy for any of the services can be waitlisted.
        const busyById = {};
        services.forEach(svc => svc.staff.filter(s => !s.available).forEach(s => { busyById[s.id] = s; }));
        renderWaitlistOption(Object.values(busyById), { branchId, date, time });

        // One stylist per service, back-to-back. Every qualified stylist is
        // listed; busy ones are shown (with "busy until") but can't be picked.
        box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3 space-y-3';
        box.innerHTML = services.map((svc, i) =>
          '<div class="book-staff-service" data-service="' + escAttr(svc.id) + '">' +
            '<div class="flex items-baseline justify-between gap-2 mb-1.5">' +
              '<p class="text-[13px] font-bold text-ink">' + (services.length > 1 ? (i + 1) + '. ' : '') + escAttr(svc.name) + '</p>' +
              '<p class="text-[11px] text-ink/55 whitespace-nowrap">' + escAttr(svc.start) + ' – ' + escAttr(svc.end) + '</p>' +
            '</div>' +
            (svc.staff.length
              ? '<div class="grid grid-cols-2 sm:grid-cols-3 gap-2">' + svc.staff.map(s =>
                  '<button type="button" data-staff="' + escAttr(s.id) + '" data-name="' + escAttr(s.name) + '"' + (s.available ? '' : ' disabled') +
                  ' title="' + (s.available ? 'Free for this service' : escAttr(s.name) + ' is with another client until ' + escAttr(s.busyUntil)) + '"' +
                  ' class="staff-btn rounded-lg border text-[12px] py-2 px-2 text-center transition ' +
                    (s.available ? 'border-bronze/40 text-ink bg-white hover:border-plum hover:text-plum' : 'border-[#FDA4AF] bg-[#FFF1F2] text-[#9F1239]/80 cursor-not-allowed') + '">' +
                    '<span class="block font-semibold">' + escAttr(s.name) + '</span>' +
                    (s.role ? '<span class="block text-[10px] opacity-60">' + escAttr(s.role) + '</span>' : '') +
                    '<span class="mt-1 inline-flex items-center gap-1 text-[10px] font-bold ' + (s.available ? 'text-[#15803D]' : 'text-[#9F1239]') + '">' +
                      '<span class="w-1.5 h-1.5 rounded-full ' + (s.available ? 'bg-[#16A34A]' : 'bg-[#E11D48]') + '"></span>' +
                      (s.available ? 'Available' : 'Busy until ' + escAttr(s.busyUntil)) +
                    '</span>' +
                  '</button>').join('') + '</div>'
              : '<p class="text-[12px] text-copper-dark">No stylist at this branch offers this service.</p>') +
            (svc.staff.length && !svc.staff.some(s => s.available)
              ? '<p class="mt-1.5 text-[11px] text-copper-dark">Everyone who does this service is busy at ' + escAttr(svc.start) + ' — pick another time, or join a waitlist below.</p>' : '') +
          '</div>').join('') +
          (services.length > 1 ? '<p class="text-[11px] text-ink/55">Your services are done one after another, starting ' + escAttr(services[0].start) + '. The same stylist can do more than one if they\'re free.</p>' : '');

        selectedServiceStaff = {};
        box.querySelectorAll('.book-staff-service').forEach(group => {
          group.querySelectorAll('.staff-btn:not([disabled])').forEach(btn => {
            btn.addEventListener('click', () => {
              group.querySelectorAll('.staff-btn').forEach(b => b.classList.remove('bg-plum', 'text-white', 'border-plum', '!text-white'));
              btn.classList.add('bg-plum', 'text-white', 'border-plum');
              selectedServiceStaff[group.dataset.service] = { id: btn.dataset.staff, name: btn.dataset.name };
              const picks = services.map(svc => selectedServiceStaff[svc.id]);
              const complete = picks.every(Boolean);
              // selectedStaffId/Name drive the rest of the form (main stylist
              // = first service's; names listed for the summary).
              selectedStaffId = complete ? picks[0].id : '';
              selectedStaffName = complete ? [...new Set(picks.map(p => p.name))].join(', ') : '';
              document.getElementById('bookDepositGate').classList.toggle('hidden', !complete);
            });
          });
        });
      })
      .catch(() => { if (requestId === staffListRequestId) box.innerHTML = '<span class="text-copper-dark">Couldn\'t load staff — please try again.</span>'; });
  }

  function refreshStaffListIfReady() {
    const branchId = document.getElementById('bookBranchSelect').value;
    const date = document.getElementById('bookDate').value;
    const time = selectedSlotLabel;
    const serviceIds = Array.from(document.querySelectorAll('.book-service-check:checked')).map(c => c.value);
    if (branchId && date && time && serviceIds.length) loadStaffList(branchId, serviceIds, date, time);
    else resetStaffList();
  }

  function resetSlotGrid(message) {
    document.getElementById('bookDepositGate').classList.add('hidden');
    slotGridRequestId++;
    selectedSlotLabel = '';
    resetStaffList();
    const box = document.getElementById('bookSlotGrid');
    box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3 text-[13px] text-ink/50';
    box.innerHTML = message || 'Choose a branch and date to see open times.';
  }

  let slotGridRequestId = 0;
  function loadSlotGrid(branchId, date) {
    document.getElementById('bookDepositGate').classList.add('hidden');
    selectedSlotLabel = '';
    resetStaffList();
    const box = document.getElementById('bookSlotGrid');
    box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3 text-[13px] text-ink/50';
    box.innerHTML = 'Loading available times…';

    const requestId = ++slotGridRequestId;
    const serviceIds = Array.from(document.querySelectorAll('.book-service-check:checked')).map(c => c.value);
    const serviceQuery = serviceIds.map(id => '&services[]=' + encodeURIComponent(id)).join('');
    fetch('backend/public/getAvailableSlots.php?branch=' + encodeURIComponent(branchId) + '&date=' + encodeURIComponent(date) + serviceQuery)
      .then(r => r.json())
      .then(res => {
        if (requestId !== slotGridRequestId) return; // a newer branch/date selection superseded this response
        if (!res.success || !res.slots.length) {
          box.innerHTML = '<span class="text-copper-dark">No time slots configured for that date.</span>';
          return;
        }
        box.className = 'rounded-xl border border-bronze/50 bg-sand-100 p-3';
        box.innerHTML = '<div class="grid grid-cols-3 sm:grid-cols-4 gap-2">' +
          res.slots.map(s => {
            const slotClass = s.available ? 'border-bronze/40 text-ink hover:border-plum hover:text-plum' : 'border-bronze/20 text-ink/25 line-through cursor-not-allowed';
            return '<button type="button" data-slot="' + escAttr(s.time) + '"' + (s.available ? '' : ' disabled') +
              ' class="slot-btn rounded-lg border text-[12px] py-2 px-1 transition ' +
              slotClass +
              '">' + s.time + '</button>';
          }).join('') +
        '</div>';
        box.querySelectorAll('.slot-btn:not([disabled])').forEach(btn => {
          btn.addEventListener('click', () => {
            box.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('bg-plum', 'text-white', 'border-plum'));
            btn.classList.add('bg-plum', 'text-white', 'border-plum');
            selectedSlotLabel = btn.dataset.slot;
            refreshStaffListIfReady();
          });
        });
      })
      .catch(() => { if (requestId === slotGridRequestId) box.innerHTML = '<span class="text-copper-dark">Couldn\'t load time slots — please try again.</span>'; });
  }

  function refreshSlotGridIfReady() {
    const branchId = document.getElementById('bookBranchSelect').value;
    const date = document.getElementById('bookDate').value;
    if (branchId && date) loadSlotGrid(branchId, date);
    else resetSlotGrid();
  }

  document.getElementById('bookBranchSelect')?.addEventListener('change', e => {
    const branchId = e.target.value;
    unlockDepositGate();
    resetServiceList();
    updateDepositRequiredDisplay();
    if (branchId) loadBranchServices(branchId); else resetServiceList();
    refreshSlotGridIfReady();
  });
  document.getElementById('bookDate')?.addEventListener('change', refreshSlotGridIfReady);
  // Service checkboxes are re-rendered per branch by loadBranchServices(), so
  // listen via delegation on their container instead of binding per-checkbox.
  // A longer treatment occupies more of the schedule, so the slot grid must
  // reflect the currently-checked services' total duration.
  document.getElementById('bookServiceList')?.addEventListener('change', e => {
    if (e.target.classList.contains('book-service-check')) {
      updateServiceSummary();
      refreshSlotGridIfReady();
      updateDepositRequiredDisplay();
      // Services changed after the deposit was already locked in -- the
      // required amount may no longer match what was submitted, so make
      // them resubmit rather than silently keep a stale deposit.
      if (bookDepositLocked) {
        showToast('Services changed — please update your deposit details.');
        unlockDepositGate();
      }
    }
  });

  // Deposit (50%, min ₱100) or full amount -- sent with both the quote and
  // the booking so the backend's quoteToken matches.
  function selectedPaymentPlan() {
    return document.querySelector('input[name="bookPaymentPlan"]:checked')?.value || 'deposit';
  }

  document.querySelectorAll('input[name="bookPaymentPlan"]').forEach(radio => radio.addEventListener('change', () => {
    if (bookDepositLocked) unlockDepositGate();
    updateDepositRequiredDisplay();
  }));

  async function updateDepositRequiredDisplay() {
    const request = ++bookQuoteRequest;
    bookReservationQuote = null;
    const button = document.getElementById('bookDepositLockBtn');
    button.disabled = true;
    ['bookPaymentServices', 'bookPaymentTotal', 'bookPaymentRequirement', 'bookDepositRequired', 'bookPaymentBalance'].forEach(id => document.getElementById(id).textContent = '—');
    const params = new URLSearchParams({ branch: document.getElementById('bookBranchSelect').value });
    const selected = document.querySelectorAll('.book-service-check:checked');
    if (!selected.length) { document.getElementById('bookQuoteError').textContent = 'Select a service to see Pay Now.'; return null; }
    selected.forEach(c => params.append('serviceIds[]', c.value));
    params.set('paymentPlan', selectedPaymentPlan());
    document.getElementById('bookQuoteError').textContent = 'Calculating reservation payment…';
    try {
      const response = await fetch('backend/public/getReservationQuote.php?' + params);
      const body = await response.json();
      if (request !== bookQuoteRequest) return null;
      if (!body.success) throw new Error(body.message || 'Unable to calculate Pay Now.');
      bookReservationQuote = body.quote;
      document.getElementById('bookPaymentPlanWrap').classList.toggle('hidden', !body.quote.canChoosePlan);
      document.getElementById('bookPaymentServices').textContent = body.quote.items.map(s => s.name).join(', ');
      document.getElementById('bookPaymentTotal').textContent = formatReservationMoney(body.quote.serviceTotal);
      document.getElementById('bookPaymentRequirement').textContent = body.quote.reservationRequirement;
      document.getElementById('bookDepositRequired').textContent = formatReservationMoney(body.quote.amountDue);
      document.getElementById('bookPaymentBalance').textContent = formatReservationMoney(body.quote.remainingBalance);
      document.getElementById('bookQuoteError').textContent = '';

      // A service configured 'No Online Reservation' (see Service Menu &
      // Promos -> Reservation Payment Rule) skips the deposit step entirely
      // -- the booking is submitted with no payment and confirmed after
      // admin review, instead of requiring a Cash/GCash/Maya choice for a
      // ₱0 amount. The backend still records method 'Cash' (already
      // validated, requires no reference) since amountDue is 0.
      if (body.quote.reservationRequirement === 'No Online Reservation') {
        bookDepositInfo = { amount: 0, method: 'Cash', reference: '' };
        bookDepositLocked = true;
        document.getElementById('bookDepositForm').classList.add('hidden');
        const locked = document.getElementById('bookDepositLocked');
        locked.classList.remove('hidden');
        locked.classList.add('flex');
        document.getElementById('bookDepositLockedSummary').textContent = 'No online reservation payment required — confirmed after review.';
      } else {
        document.getElementById('bookDepositForm').classList.remove('hidden');
        document.getElementById('bookDepositLocked').classList.add('hidden');
        bookDepositLocked = false;
      }
      return body.quote.amountDue;
    } catch (error) {
      if (request === bookQuoteRequest) document.getElementById('bookQuoteError').textContent = error.message || 'Unable to calculate Pay Now. Please select your services again.';
      return null;
    } finally {
      if (request === bookQuoteRequest) button.disabled = false;
    }
  }

  function unlockDepositGate() {
    bookDepositLocked = false;
    bookDepositInfo = { amount: 0, method: '', reference: '' };
    document.getElementById('bookDepositForm').classList.remove('hidden');
    const locked = document.getElementById('bookDepositLocked');
    locked.classList.add('hidden');
    locked.classList.remove('flex');
    document.getElementById('bookScheduleSection').classList.remove('hidden');
  }

  document.getElementById('bookDepositMethod')?.addEventListener('change', e => {
    const method = e.target.value;
    document.getElementById('bookDepositCashNote').classList.toggle('hidden', method !== 'Cash');
    document.getElementById('bookDepositOnlineNote').classList.toggle('hidden', method !== 'PayMongo');
  });

  document.getElementById('bookDepositLockBtn')?.addEventListener('click', async () => {
    if (!selectedSlotLabel) { showToast('Please choose an available schedule first'); return; }
    if (!document.querySelectorAll('.book-service-check:checked').length) {
      showToast('Please choose at least one service first');
      return;
    }
    if (!selectedStaffId) { showToast('Please select a staff member first'); return; }
    const method = document.getElementById('bookDepositMethod').value;
    if (!method) { showToast('Please choose a payment method for your deposit'); return; }

    const amount = await updateDepositRequiredDisplay();
    if (amount === null) return;
    bookDepositInfo = { amount, method, reference: '' };
    bookDepositLocked = true;

    document.getElementById('bookDepositForm').classList.add('hidden');
    const locked = document.getElementById('bookDepositLocked');
    locked.classList.remove('hidden');
    locked.classList.add('flex');
    document.getElementById('bookDepositLockedSummary').textContent =
      method === 'PayMongo'
        ? formatReservationMoney(amount) + ' — pay online after you confirm'
        : formatReservationMoney(amount) + ' via ' + method + (bookDepositInfo.reference ? ' (Ref: ' + bookDepositInfo.reference + ')' : ' — pay in person');

    document.getElementById('bookScheduleSection').classList.remove('hidden');
    document.getElementById('bookName').focus();
  });

  document.getElementById('bookDepositChangeBtn')?.addEventListener('click', unlockDepositGate);

  document.querySelectorAll('#bookNotify .notify-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#bookNotify .notify-opt').forEach(b => b.classList.remove('on'));
      btn.classList.add('on');
      bookNotifyChoice = btn.dataset.notify;
    });
  });

  document.getElementById('bookingModalForm')?.addEventListener('submit', e => {
    e.preventDefault();

    const branchId = document.getElementById('bookBranchSelect').value;
    const date = document.getElementById('bookDate').value;
    const name = document.getElementById('bookName').value.trim();
    const phone = document.getElementById('bookPhone').value.trim();
    const email = document.getElementById('bookEmail').value.trim();
    const serviceIds = Array.from(document.querySelectorAll('.book-service-check:checked')).map(c => c.value);
    const serviceNames = Array.from(document.querySelectorAll('.book-service-check:checked')).map(c => c.dataset.name);

    if (!branchId) { showToast('Please choose a branch'); return; }
    if (!serviceIds.length) { showToast('Please choose at least one service'); return; }
    if (!bookDepositLocked) { showToast('Please save your deposit details before reviewing your booking'); return; }
    if (!date) { showToast('Please select a date'); return; }
    if (!selectedSlotLabel) { showToast('Please choose an available time'); return; }
    if (!selectedStaffId) { showToast('Please select a staff member for your appointment'); return; }
    if (!name) { showToast('Please enter your full name'); return; }
    if (!/^09\d{9}$/.test(phone)) { showToast('Please enter a valid 11-digit mobile number'); return; }
    if (email && !/^\S+@\S+\.\S+$/.test(email)) { showToast('Please enter a valid email address, or leave it blank'); return; }
    if (!document.getElementById('bookAgreeTerms')?.checked) { showToast('Please agree to the Terms & Conditions'); return; }

    pendingBooking = {
      branchId, date, time: selectedSlotLabel, name, phone, email,
      staffId: selectedStaffId, staffName: selectedStaffName,
      serviceStaff: Object.fromEntries(Object.entries(selectedServiceStaff).map(([svc, s]) => [svc, s.id])),
      depositAmount: bookDepositInfo.amount, depositMethod: bookDepositInfo.method, depositReference: bookDepositInfo.reference,
      paymentPlan: selectedPaymentPlan(),
      serviceIds, serviceNames, notify: bookNotifyChoice, quoteToken: bookReservationQuote.quoteToken
    };

    const total = computeSelectedServicesSubtotal();
    document.getElementById('bookingReviewSummary').innerHTML = [
      ['Branch', BRANCH_LABEL_BY_ID[branchId] || branchId],
      ['Services', serviceNames.join(', ')],
      ['Date', fmtApptDate(date)], ['Time', selectedSlotLabel],
      ['Staff', selectedStaffName],
      ['Name', name], ['Mobile', phone],
      ...(email ? [['Email', email]] : []),
      ['Service Total', formatReservationMoney(total)],
      ['Reservation Requirement', bookReservationQuote.reservationRequirement],
      ['Pay Now', formatReservationMoney(bookDepositInfo.amount) + ' via ' + bookDepositInfo.method],
      ['Deposit reference', bookDepositInfo.reference || (bookDepositInfo.method === 'PayMongo' ? 'Pay online via PayMongo after confirming' : 'Pay cash at the branch')],
      ['Balance to Pay Later', formatReservationMoney(bookReservationQuote.remainingBalance)]
    ].map(([label, value]) => '<p><span class="text-ink/50">' + label + ':</span> <b>' + escAttr(value) + '</b></p>').join('');
    document.getElementById('bookingFormPanel').classList.add('hidden');
    document.getElementById('bookingReviewPanel').classList.remove('hidden');
    bookingModalBox.scrollTop = 0;
    document.getElementById('bookingReviewHeading').focus();

    // No email means nothing to OTP-verify -- the confirm button submits
    // straight to the backend instead of first requesting an email code.
    document.getElementById('bookReviewConfirm').textContent = email ? 'Continue to email verification' : 'Confirm booking';
    document.getElementById('bookReviewNextNote').textContent = email
      ? 'Next, verify your email. Your request will remain pending until the branch confirms your appointment and deposit.'
      : 'Your request will remain pending until the branch confirms your appointment and deposit.';
  });

  document.getElementById('bookReviewEdit').addEventListener('click', () => {
    document.getElementById('bookingReviewPanel').classList.add('hidden');
    document.getElementById('bookingFormPanel').classList.remove('hidden');
    pendingBooking = null;
    bookingModalBox.scrollTop = 0;
  });

  document.getElementById('bookReviewConfirm').addEventListener('click', () => {
    if (!pendingBooking) return;
    const email = pendingBooking.email;
    const reviewedBooking = pendingBooking;

    const submitBtn = document.getElementById('bookReviewConfirm');
    if (submitBtn.disabled) return;

    // No email was given, so there's nothing to OTP-verify -- submit the
    // booking directly with the same backend endpoint and payment rules
    // used for the email-verified path.
    if (!email) {
      submitBtn.disabled = true;
      document.getElementById('bookReviewEdit').disabled = true;
      submitBtn.textContent = 'Booking…';
      finalizeGuestBooking(pendingBooking, '', submitBtn, 'Confirm booking')
        .finally(() => { document.getElementById('bookReviewEdit').disabled = false; });
      return;
    }

    submitBtn.disabled = true;
    document.getElementById('bookReviewEdit').disabled = true;
    submitBtn.textContent = 'Sending code…';

    const formData = new FormData();
    formData.append('email', email);

    fetch('backend/public/sendBookingOtp.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        submitBtn.disabled = false;
        document.getElementById('bookReviewEdit').disabled = false;
        submitBtn.textContent = 'Continue to email verification';
        if (pendingBooking !== reviewedBooking) return;
        if (!res.success) { showToast(res.message); return; }

        document.getElementById('bookOtpEmailLabel').textContent = email;
        document.getElementById('bookingFormPanel').classList.add('hidden');
        document.getElementById('bookingReviewPanel').classList.add('hidden');
        document.getElementById('bookingOtpPanel').classList.remove('hidden');
        document.getElementById('bookingModalBox').scrollTop = 0;
        resetBookOtpInputs();
        startBookOtpCooldown(60);
        document.querySelector('.book-otp-digit')?.focus();
      })
      .catch(() => {
        submitBtn.disabled = false;
        document.getElementById('bookReviewEdit').disabled = false;
        submitBtn.textContent = 'Continue to email verification';
        showToast('A network error occurred. Please try again.');
      });
  });

  const bookOtpDigits = Array.from(document.querySelectorAll('.book-otp-digit'));
  bookOtpDigits.forEach((input, index) => {
    input.addEventListener('input', () => {
      input.value = input.value.replace(/[^0-9]/g, '').slice(0, 1);
      if (input.value && index < bookOtpDigits.length - 1) bookOtpDigits[index + 1].focus();
    });
    input.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !input.value && index > 0) bookOtpDigits[index - 1].focus();
    });
    input.addEventListener('paste', e => {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
      if (!pasted) return;
      bookOtpDigits.forEach((box, i) => { box.value = pasted[i] || ''; });
      const nextEmpty = bookOtpDigits.find(box => !box.value);
      (nextEmpty || bookOtpDigits[bookOtpDigits.length - 1]).focus();
    });
  });

  function resetBookOtpInputs() {
    bookOtpDigits.forEach(d => d.value = '');
  }

  function startBookOtpCooldown(seconds) {
    let cooldown = seconds;
    const btn = document.getElementById('bookOtpResendBtn');
    const baseLabel = 'Resend code';
    btn.disabled = true;
    btn.textContent = 'Resend code (' + cooldown + 's)';
    clearInterval(bookOtpCooldownTimer);
    bookOtpCooldownTimer = setInterval(() => {
      cooldown -= 1;
      if (cooldown <= 0) {
        clearInterval(bookOtpCooldownTimer);
        bookOtpCooldownTimer = null;
        btn.disabled = false;
        btn.textContent = baseLabel;
      } else {
        btn.textContent = 'Resend code (' + cooldown + 's)';
      }
    }, 1000);
  }

  document.getElementById('bookOtpResendBtn')?.addEventListener('click', () => {
    if (!pendingBooking) return;
    const btn = document.getElementById('bookOtpResendBtn');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('email', pendingBooking.email);

    fetch('backend/public/sendBookingOtp.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        showToast(res.message);
        resetBookOtpInputs();
        startBookOtpCooldown(60);
      })
      .catch(() => {
        showToast('A network error occurred. Please try again.');
        btn.disabled = false;
      });
  });

  document.getElementById('bookOtpEditBtn')?.addEventListener('click', () => {
    document.getElementById('bookingOtpPanel').classList.add('hidden');
    document.getElementById('bookingFormPanel').classList.remove('hidden');
    document.getElementById('bookingModalBox').scrollTop = 0;
  });

  // Shared by both guest-booking completion paths: with an email (after the
  // OTP form) and without one (straight from the review step, since there's
  // nothing to verify). Same backend endpoint, same booking system, same
  // payment rules either way -- only whether otpCode is populated differs.
  function finalizeGuestBooking(booking, otpCode, submitBtn, idleLabel) {
    const formData = new FormData();
    formData.append('fullname', booking.name);
    formData.append('contact', booking.phone);
    formData.append('branch', booking.branchId);
    formData.append('appointment_date', booking.date);
    formData.append('time_slot', booking.time);
    formData.append('staff_id', booking.staffId);
    Object.entries(booking.serviceStaff || {}).forEach(([svc, staff]) => formData.append('service_staff[' + svc + ']', staff));
    booking.serviceIds.forEach(id => formData.append('services[]', id));
    formData.append('email', booking.email);
    formData.append('otp_code', otpCode);
    formData.append('payment_method', booking.depositMethod);
    formData.append('payment_plan', booking.paymentPlan || 'deposit');
    formData.append('quoteToken', booking.quoteToken);
    formData.append('deposit_reference', booking.depositReference);
    formData.append('agreedToTerms', '1');

    return fetch('backend/public/submitGuestBooking.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = idleLabel; }

        if (!res.success) {
          showToast(res.message);
          if (res.quoteChanged) {
            unlockDepositGate();
            updateDepositRequiredDisplay();
            document.getElementById('bookingOtpPanel').classList.add('hidden');
            document.getElementById('bookingReviewPanel').classList.add('hidden');
            document.getElementById('bookingFormPanel').classList.remove('hidden');
          }
          return false;
        }

        // Online payment: hand off to PayMongo's hosted checkout, which
        // returns to pages/payment/result.html when done.
        if (res.checkoutUrl) {
          if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Redirecting to payment…'; }
          window.location.href = res.checkoutUrl;
          return true;
        }

        const branchLabel = BRANCH_LABEL_BY_ID[booking.branchId] || '';

        document.getElementById('bookRef').textContent = res.reference;
        document.getElementById('bookSummary').innerHTML = [
          ['Branch', branchLabel],
          ['Services', booking.serviceNames.join(', ')],
          ['Date', fmtApptDate(booking.date)],
          ['Time', booking.time],
          ['Staff', booking.staffName],
          ['Booked for', booking.name + ' · ' + booking.phone],
          ['Service Total', formatReservationMoney(res.payment.serviceTotal)],
          ['Reservation Requirement', res.payment.reservationRequirement],
          ['Pay Now', formatReservationMoney(res.payment.amountDue) + ' via ' + booking.depositMethod],
          ['Balance to Pay Later', formatReservationMoney(res.payment.remainingBalance)]
        ].map(r => '<p><span class="text-ink/50">' + r[0] + ':</span> <b>' + escAttr(r[1]) + '</b></p>').join('');

        document.getElementById('bookNotifyNote').textContent =
          'Your request is pending confirmation by ' + branchLabel + '. Keep your reference and use Track this booking to check for updates.';

        lastBookingPhone = booking.phone;
        pendingBooking = null;
        document.getElementById('bookingOtpPanel').classList.add('hidden');
        document.getElementById('bookingReviewPanel').classList.add('hidden');
        document.getElementById('bookingConfirmPanel').classList.remove('hidden');
        document.getElementById('bookingModalBox').scrollTop = 0;
        return true;
      })
      .catch(() => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = idleLabel; }
        showToast('A network error occurred. Please try again.');
        return false;
      });
  }

  document.getElementById('bookingOtpForm')?.addEventListener('submit', e => {
    e.preventDefault();
    if (!pendingBooking) return;

    const code = bookOtpDigits.map(d => d.value).join('');
    if (code.length !== bookOtpDigits.length) { showToast('Please enter the full 6-digit code.'); return; }

    const submitBtn = document.getElementById('bookOtpSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Booking…';

    finalizeGuestBooking(pendingBooking, code, submitBtn, 'Verify & submit request')
      .then(succeeded => {
        if (!succeeded) { resetBookOtpInputs(); bookOtpDigits[0].focus(); }
      });
  });

  const trackModalOverlay = document.getElementById('trackModalOverlay');
  const trackModalBox = document.getElementById('trackModalBox');
  let apptFbRef = null;
  let apptFbPhone = null;
  let apptRatings = { service: 0, staff: 0 };

  function openTrackModal(prefillRef, prefillPhone) {
    closeBookingModal();
    trackModalOverlay.classList.remove('opacity-0', 'pointer-events-none');
    trackModalBox.classList.remove('scale-95');
    if (prefillRef && prefillRef !== '—') {
      document.getElementById('apptTrkRef').value = prefillRef;
    }
    if (prefillPhone) {
      document.getElementById('apptTrkPhone').value = prefillPhone;
    }
  }
  function closeTrackModal() {
    trackModalOverlay.classList.add('opacity-0', 'pointer-events-none');
    trackModalBox.classList.add('scale-95');
  }
  document.getElementById('closeTrack')?.addEventListener('click', closeTrackModal);
  trackModalOverlay?.addEventListener('click', e => { if (e.target === trackModalOverlay) closeTrackModal(); });

  function apptTrack() {
    const ref = document.getElementById('apptTrkRef').value.trim().toUpperCase();
    const phone = document.getElementById('apptTrkPhone').value.trim();
    const box = document.getElementById('apptTrkResult');
    const fb = document.getElementById('apptTrkFeedback');
    box.classList.remove('hidden');

    if (!ref || !phone) {
      box.innerHTML = '<div class="rounded-xl bg-copper/12 border border-copper/30 text-copper-dark p-4 text-[13px]">Enter both the booking reference and the mobile number used for it.</div>';
      fb.classList.add('hidden');
      return;
    }

    box.innerHTML = '<div class="text-[13px] text-ink/50">Looking up your booking…</div>';
    fb.classList.add('hidden');

    fetch('backend/public/trackBooking.php?reference=' + encodeURIComponent(ref) + '&phone=' + encodeURIComponent(phone))
      .then(r => r.json())
      .then(res => {
        if (!res.success) {
          box.innerHTML = '<div class="rounded-xl bg-copper/12 border border-copper/30 text-copper-dark p-4 text-[13px]">' + escAttr(res.message) + ' Or call 0918 536 8016.</div>';
          return;
        }
        res.phone = phone; // proves ownership again if the guest reschedules from here
        box.innerHTML = renderTrackingResult(res);

        // Feedback is offered only once the service is finished and hasn't been rated yet.
        if (res.canReview) {
          apptFbRef = res.reference;
          apptFbPhone = phone;
          apptRatings = { service: 0, staff: 0 };
          document.querySelectorAll('.appt-star').forEach(s => s.classList.remove('on'));
          document.getElementById('apptFbFor').textContent =
            'For booking ' + res.reference + ' — ' + (res.type === 'home_service' ? res.event : res.services) + '.';
          fb.classList.remove('hidden');
        } else {
          fb.classList.add('hidden');
        }
      })
      .catch(() => {
        box.innerHTML = '<div class="rounded-xl bg-copper/12 border border-copper/30 text-copper-dark p-4 text-[13px]">A network error occurred. Please try again.</div>';
      });
  }

  function apptStar(which, value) {
    apptRatings[which] = value;
    const wrap = document.getElementById(which === 'service' ? 'apptFbService' : 'apptFbStaff');
    wrap.querySelectorAll('.appt-star').forEach((s, i) => s.classList.toggle('on', i < value));
  }

  function apptSubmitFeedback() {
    if (!apptRatings.service) { showToast('Please rate the service first'); return; }
    if (!apptFbRef || !apptFbPhone) { showToast('Please look up your booking again.'); return; }

    const formData = new FormData();
    formData.append('reference', apptFbRef);
    formData.append('phone', apptFbPhone);
    formData.append('rating', apptRatings.service);
    if (apptRatings.staff) formData.append('staff_rating', apptRatings.staff);
    formData.append('comment', document.getElementById('apptFbText').value.trim());

    fetch('backend/public/submitGuestFeedback.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        showToast(res.message);
        if (!res.success) return;
        document.getElementById('apptFbText').value = '';
        apptRatings = { service: 0, staff: 0 };
        document.querySelectorAll('.appt-star').forEach(s => s.classList.remove('on'));
        document.getElementById('apptTrkFeedback').classList.add('hidden');
      })
      .catch(() => showToast('A network error occurred. Please try again.'));
  }

  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (!trackModalOverlay.classList.contains('pointer-events-none')) closeTrackModal();
    if (!bookingModalOverlay.classList.contains('pointer-events-none')) closeBookingModal();
    if (staffProfileOverlay && !staffProfileOverlay.classList.contains('pointer-events-none')) closeStaffProfile();
  });


  function applyTheme(mode) {
    const dark = mode === 'dark';
    document.documentElement.classList.toggle('dark', dark);
    const btn = document.getElementById('themeToggle');
    if (btn) {
      btn.querySelector('.theme-moon').classList.toggle('hidden', dark);
      btn.querySelector('.theme-sun').classList.toggle('hidden', !dark);
      btn.setAttribute('aria-pressed', String(dark));
      btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
    }
    const ml = document.getElementById('themeLabelMobile');
    if (ml) ml.textContent = dark ? 'Light mode' : 'Dark mode';
    try { localStorage.setItem('lm-theme', mode); } catch (e) { /* storage unavailable */ }
  }

  function toggleTheme() {
    applyTheme(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
  }

  function initTheme() {
    let saved = null;
    try { saved = localStorage.getItem('lm-theme'); } catch (e) { /* storage unavailable */ }
    if (!saved) {
      saved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    applyTheme(saved);
  }

  /* ---- Portal login ----------------------------------------------------
     Team Portal uses the same sign-in page as the Client Portal; the
     backend (backend/auth/login.php) figures out the account's role and
     redirects to the right dashboard, so there's no separate team-only
     login screen.

     >>> CHANGE THIS LINE if your login page is saved somewhere else. <<<   */
  const PORTAL_LOGIN_URL = 'pages/login/login.html';

  function goToPortalLogin() {
    window.location.href = PORTAL_LOGIN_URL;
  }

  function allowNumbersOnly() {
    const fields = document.querySelectorAll('.numbers-only');
    for (let i = 0; i < fields.length; i++) {
      fields[i].addEventListener('keypress', function (e) {
        if (e.key.length === 1 && (e.key < '0' || e.key > '9')) {
          e.preventDefault();
        }
      });
      fields[i].addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '');
      });
    }
  }

  window.addEventListener('DOMContentLoaded', () => {
    initTheme();
    allowNumbersOnly();
    switchTab('home-tab');
    if (new URLSearchParams(window.location.search).get('track') === 'booking') {
      openTrackModal();
      document.getElementById('apptTrkRef').focus();
    }
    refreshLiveStatus();
    setInterval(refreshLiveStatus, 60000);
    highlightSvcBranch();
    renderServices();
    renderStaff();
    renderProducts();
    renderWeddingPackages();
    const homeDate = document.getElementById('hsDate');
    if (homeDate) homeDate.min = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    hsGo(1, true);
  });
