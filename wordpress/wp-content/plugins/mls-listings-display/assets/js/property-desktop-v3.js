/**
 * MLS Property Desktop V3 JavaScript
 * Modern homes.com-inspired interactions and functionality
 *
 * @version 3.0.0
 */

(function () {
  'use strict';

  // State management
  const state = {
    currentPhoto: 0,
    totalPhotos: 0,
    saved: false,
    contactCardVisible: true,
    map: null,
    mapMarker: null,
    galleryView: 'photos', // photos, map, streetview
    galleryMap: null,
    galleryMapMarker: null,
  };

  // DOM elements cache
  const elements = {};

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  /**
   * Initialize the application
   */
  function init() {
    cacheElements();

    if (elements.container) {
      setupBackButton();
      setupGallery();
      setupNavigation();
      setupCalculator();
      setupModals();
      setupActions();

      // Initialize map in try-catch to prevent blocking other features
      try {
        initializeMap();
      } catch (error) {
        // Error initializing map
      }

      // Initialize Walk Score with a slight delay to ensure all data is loaded
      setTimeout(() => {
        try {
          initializeWalkScore();
        } catch (error) {
          // Error initializing Walk Score
        }
      }, 500);

      // Initialize MLS copy to clipboard
      initializeMLSCopyToClipboard();
    }
  }

  /**
   * Cache DOM elements
   */
  function cacheElements() {
    elements.container = document.querySelector('.mld-v3-property');

    if (!elements.container) return;

    // Gallery
    elements.galleryImages = document.querySelectorAll('.mld-v3-gallery-image');
    elements.backBtn = document.getElementById('v3BackButton');
    elements.prevBtn = document.getElementById('v3GalleryPrev');
    elements.nextBtn = document.getElementById('v3GalleryNext');
    elements.viewPhotosBtn = document.getElementById('v3ViewPhotos');
    elements.streetViewBtn = document.getElementById('v3StreetView');
    elements.mapViewBtn = document.getElementById('v3MapView');

    // Navigation
    elements.navBar = document.getElementById('v3NavBar');
    elements.navLinks = document.querySelectorAll('.mld-v3-nav-link');
    elements.saveBtn = document.querySelector('.mld-v3-save-btn');
    elements.shareBtn = document.querySelector('.mld-v3-share-btn');

    // Contact Card
    elements.contactCard = document.getElementById('v3ContactCard');
    elements.tourBtn = document.querySelector('.mld-v3-tour-btn');
    elements.messageBtn = document.querySelector('.mld-v3-message-btn');

    // Calculator
    elements.calcPrice = document.getElementById('v3CalcPrice');
    elements.calcDownPercent = document.getElementById('v3CalcDownPercent');
    elements.calcDownAmount = document.getElementById('v3CalcDownAmount');
    elements.calcRate = document.getElementById('v3CalcRate');
    elements.calcTerm = document.getElementById('v3CalcTerm');
    elements.calcPayment = document.getElementById('v3CalcPayment');
    elements.calcPI = document.getElementById('v3CalcPI');
    elements.calcPMI = document.getElementById('v3CalcPMI');
    elements.calcTax = document.getElementById('v3CalcTax');
    elements.calcInsurance = document.getElementById('v3CalcInsurance');

    // Modals (Photo modal still exists)
    elements.photoModal = document.getElementById('v3PhotoModal');
    elements.photoGrid = document.getElementById('v3PhotoGrid');
    elements.modalCloses = document.querySelectorAll('.mld-v3-modal-close');

    // Map
    elements.propertyMap = document.getElementById('v3PropertyMap');

    // Chat Forms
    elements.contactForm = document.getElementById('v3ChatContactInfoForm');
    elements.tourForm = document.getElementById('v3ChatTourForm');
  }

  /**
   * Setup back button functionality
   */
  function setupBackButton() {
    if (!elements.backBtn) return;

    elements.backBtn.addEventListener('click', function() {
      // Check if we can go back in history
      if (window.history.length > 1 && document.referrer && document.referrer !== '') {
        // Check if referrer is from the same domain
        try {
          const referrerUrl = new URL(document.referrer);
          const currentUrl = new URL(window.location.href);

          if (referrerUrl.hostname === currentUrl.hostname) {
            // Same domain, safe to go back
            window.history.back();
            return;
          }
        } catch (e) {
          // URL parsing failed, fall through to home navigation
        }
      }

      // No valid history or came from external site, go to home page
      window.location.href = window.location.origin + '/';
    });
  }

  /**
   * Setup gallery functionality
   */
  function setupGallery() {
    if (!elements.galleryImages || elements.galleryImages.length === 0) return;

    state.totalPhotos = elements.galleryImages.length;

    // Previous/Next buttons
    if (elements.prevBtn) {
      elements.prevBtn.addEventListener('click', () => navigateGallery(-1));
    }

    if (elements.nextBtn) {
      elements.nextBtn.addEventListener('click', () => navigateGallery(1));
    }

    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') navigateGallery(-1);
      if (e.key === 'ArrowRight') navigateGallery(1);
    });

    // View photos button
    if (elements.viewPhotosBtn) {
      elements.viewPhotosBtn.addEventListener('click', () => {
        if (state.galleryView !== 'photos') {
          toggleGalleryView('photos');
        } else {
          openPhotoGrid();
        }
      });
    }

    // Street view button - embed in gallery
    if (elements.streetViewBtn && window.mldPropertyDataV3) {
      elements.streetViewBtn.addEventListener('click', () => {
        toggleGalleryView('streetview');
      });
    }

    // Map view button - embed in gallery
    if (elements.mapViewBtn) {
      elements.mapViewBtn.addEventListener('click', () => {
        toggleGalleryView('map');
      });
    }

    // Virtual tour buttons
    const virtualTourButtons = document.querySelectorAll('.mld-v3-virtual-tour-btn');
    virtualTourButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const tourIndex = button.getAttribute('data-tour-index');
        const tourType = button.getAttribute('data-tour-type');
        toggleGalleryView('tour', { index: tourIndex, type: tourType });
      });
    });

    // Touch gestures for mobile-like experience
    let touchStartX = null;
    const gallery = document.querySelector('.mld-v3-gallery-main');

    if (gallery) {
      gallery.addEventListener('touchstart', (e) => {
        touchStartX = e.touches[0].clientX;
      });

      gallery.addEventListener('touchend', (e) => {
        if (!touchStartX) return;

        const touchEndX = e.changedTouches[0].clientX;
        const diff = touchStartX - touchEndX;

        if (Math.abs(diff) > 50) {
          if (diff > 0) {
            navigateGallery(1); // Next
          } else {
            navigateGallery(-1); // Previous
          }
        }

        touchStartX = null;
      });
    }

    // Setup preview image click handlers
    setupPreviewClicks();

    // Initialize preview images on page load
    updatePreviewImages();
  }

  /**
   * Navigate gallery photos with preview updates
   * @param direction
   */
  function navigateGallery(direction) {
    // Hide current photo
    if (elements.galleryImages[state.currentPhoto]) {
      elements.galleryImages[state.currentPhoto].classList.remove('active');
    }

    // Update index with infinite loop
    state.currentPhoto = (state.currentPhoto + direction + state.totalPhotos) % state.totalPhotos;

    // Show new photo
    if (elements.galleryImages[state.currentPhoto]) {
      elements.galleryImages[state.currentPhoto].classList.add('active');
    }

    // Analytics: Track photo view (v6.38.0)
    document.dispatchEvent(new CustomEvent('mld:photo_view', {
      detail: {
        listingId: window.mldPropertyDataV3?.listing_id || null,
        photoIndex: state.currentPhoto,
        totalPhotos: state.totalPhotos
      }
    }));

    // Update preview images
    updatePreviewImages();
  }

  /**
   * Update preview images to show next 2 photos
   */
  function updatePreviewImages() {
    const previewSlot1 = document.getElementById('v3PreviewSlot1');
    const previewSlot2 = document.getElementById('v3PreviewSlot2');

    if (!previewSlot1 || !previewSlot2) return;

    // Calculate next image indices with infinite loop
    const nextIndex1 = (state.currentPhoto + 1) % state.totalPhotos;
    const nextIndex2 = (state.currentPhoto + 2) % state.totalPhotos;

    // Update first preview slot
    const preview1Images = previewSlot1.querySelectorAll('img');
    preview1Images.forEach((img, index) => {
      if (index === nextIndex1) {
        img.classList.add('active');
      } else {
        img.classList.remove('active');
      }
    });

    // Update second preview slot
    const preview2Images = previewSlot2.querySelectorAll('img');
    preview2Images.forEach((img, index) => {
      if (index === nextIndex2) {
        img.classList.add('active');
      } else {
        img.classList.remove('active');
      }
    });
  }

  /**
   * Setup preview image click handlers
   */
  function setupPreviewClicks() {
    const previewSlot1 = document.getElementById('v3PreviewSlot1');
    const previewSlot2 = document.getElementById('v3PreviewSlot2');

    if (previewSlot1) {
      previewSlot1.addEventListener('click', () => {
        navigateGallery(1); // Go to next image
      });
    }

    if (previewSlot2) {
      previewSlot2.addEventListener('click', () => {
        navigateGallery(2); // Go to image after next
      });
    }
  }

  /**
   * Open photo grid modal
   */
  function openPhotoGrid() {
    if (elements.photoModal) {
      elements.photoModal.classList.add('active');
      document.body.style.overflow = 'hidden';

      // Add click handlers to grid photos
      const gridPhotos = elements.photoGrid.querySelectorAll('.mld-v3-grid-photo');
      gridPhotos.forEach((photo, index) => {
        photo.addEventListener('click', () => {
          state.currentPhoto = index;
          navigateGallery(0);
          closeModal(elements.photoModal);
        });
      });
    }
  }

  /**
   * Setup sticky navigation
   */
  function setupNavigation() {
    if (!elements.navBar) return;

    // Sticky nav scroll effect
    let lastScroll = 0;

    window.addEventListener('scroll', () => {
      const currentScroll = window.pageYOffset;

      if (currentScroll > 100) {
        elements.navBar.classList.add('scrolled');
      } else {
        elements.navBar.classList.remove('scrolled');
      }

      lastScroll = currentScroll;
    });

    // Smooth scroll navigation
    elements.navLinks.forEach((link) => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const targetId = link.getAttribute('href').substring(1);
        const targetElement = document.getElementById(targetId);

        if (targetElement) {
          targetElement.scrollIntoView({ behavior: 'smooth' });

          // Update active state
          elements.navLinks.forEach((l) => l.classList.remove('active'));
          link.classList.add('active');
        }
      });
    });

    // Update active nav on scroll
    const sections = document.querySelectorAll('.mld-v3-section');
    const observerOptions = {
      root: null,
      rootMargin: '-30% 0px -70% 0px',
      threshold: 0,
    };

    const navObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          const id = entry.target.getAttribute('id');
          elements.navLinks.forEach((link) => {
            if (link.getAttribute('href') === `#${id}`) {
              elements.navLinks.forEach((l) => l.classList.remove('active'));
              link.classList.add('active');
            }
          });
        }
      });
    }, observerOptions);

    sections.forEach((section) => navObserver.observe(section));
  }

  /**
   * Setup mortgage calculator
   */
  function setupCalculator() {
    if (!elements.calcPrice) return;

    // Helper function to calculate monthly payment
    const calcMonthlyPayment = (principal, rate, years) => {
      const monthlyRate = rate / 100 / 12;
      const numPayments = years * 12;

      if (monthlyRate > 0) {
        return (principal * (monthlyRate * Math.pow(1 + monthlyRate, numPayments))) /
               (Math.pow(1 + monthlyRate, numPayments) - 1);
      }
      return principal / numPayments;
    };

    const calculatePayment = () => {
      const price = parseFloat(elements.calcPrice.value) || 0;
      const downPercent = parseFloat(elements.calcDownPercent.value) || 20;
      const rate = parseFloat(elements.calcRate.value) || 6.5;
      const term = parseInt(elements.calcTerm.value) || 30;

      // Calculate down payment
      const downPayment = price * (downPercent / 100);
      const loanAmount = price - downPayment;

      // Calculate monthly principal & interest
      const numPayments = term * 12;
      const monthlyPI = calcMonthlyPayment(loanAmount, rate, term);

      // Calculate PMI (if down payment < 20%)
      let pmi = 0;
      if (downPercent < 20) {
        pmi = (loanAmount * 0.005) / 12; // 0.5% annually
      }

      // Get other costs from property data
      const propertyTax = (window.mldPropertyDataV3?.propertyTax || 0) / 12;
      const insurance = 200; // Default estimate
      const hoa = window.mldPropertyDataV3?.hoaFees || 0;

      // Calculate totals
      const totalMonthly = monthlyPI + propertyTax + insurance + hoa + pmi;
      const totalPayments = monthlyPI * numPayments;
      const totalInterest = totalPayments - loanAmount;
      const totalCost = downPayment + totalPayments;

      // Update Summary Cards
      const summaryPayment = document.getElementById('v3CalcPaymentSummary');
      const summaryLoan = document.getElementById('v3CalcLoanAmount');
      const summaryInterest = document.getElementById('v3CalcTotalInterest');
      const summaryCost = document.getElementById('v3CalcTotalCost');

      if (summaryPayment) summaryPayment.textContent = '$' + Math.round(totalMonthly).toLocaleString();
      if (summaryLoan) summaryLoan.textContent = '$' + Math.round(loanAmount).toLocaleString();
      if (summaryInterest) summaryInterest.textContent = '$' + Math.round(totalInterest).toLocaleString();
      if (summaryCost) summaryCost.textContent = '$' + Math.round(totalCost).toLocaleString();

      // Update Down Payment Display Helper
      const downDisplay = document.getElementById('v3CalcDownDisplay');
      if (downDisplay) downDisplay.textContent = Math.round(downPayment).toLocaleString();

      // Update Monthly Breakdown
      elements.calcPI.textContent = '$' + Math.round(monthlyPI).toLocaleString();
      elements.calcPMI.textContent = '$' + Math.round(pmi).toLocaleString();
      if (elements.calcTax) elements.calcTax.textContent = '$' + Math.round(propertyTax).toLocaleString();
      if (elements.calcInsurance) elements.calcInsurance.textContent = '$' + Math.round(insurance).toLocaleString();
      if (elements.calcPayment) elements.calcPayment.textContent = '$' + Math.round(totalMonthly).toLocaleString();

      // Update Loan Summary
      const totalPaymentsEl = document.getElementById('v3CalcTotalPayments');
      const totalPrincipalEl = document.getElementById('v3CalcTotalPrincipal');
      const totalInterestDetail = document.getElementById('v3CalcTotalInterestDetail');

      if (totalPaymentsEl) totalPaymentsEl.textContent = '$' + Math.round(totalPayments).toLocaleString();
      if (totalPrincipalEl) totalPrincipalEl.textContent = '$' + Math.round(loanAmount).toLocaleString();
      if (totalInterestDetail) totalInterestDetail.textContent = '$' + Math.round(totalInterest).toLocaleString();

      // Update Rate Impact Analysis - NEW FEATURE
      const rateLower = rate - 0.5;
      const rateHigher = rate + 0.5;
      const paymentLower = calcMonthlyPayment(loanAmount, rateLower, term) + propertyTax + insurance + hoa + pmi;
      const paymentHigher = calcMonthlyPayment(loanAmount, rateHigher, term) + propertyTax + insurance + hoa + pmi;

      const rateLowerEl = document.getElementById('v3CalcRateLower');
      const rateLowerSave = document.getElementById('v3CalcRateLowerSave');
      const rateCurrentEl = document.getElementById('v3CalcRateCurrent');
      const rateHigherEl = document.getElementById('v3CalcRateHigher');
      const rateHigherCost = document.getElementById('v3CalcRateHigherCost');

      if (rateLowerEl) rateLowerEl.textContent = '$' + Math.round(paymentLower).toLocaleString();
      if (rateLowerSave) rateLowerSave.textContent = 'Save $' + Math.round(totalMonthly - paymentLower).toLocaleString() + '/mo';
      if (rateCurrentEl) rateCurrentEl.textContent = '$' + Math.round(totalMonthly).toLocaleString();
      if (rateHigherEl) rateHigherEl.textContent = '$' + Math.round(paymentHigher).toLocaleString();
      if (rateHigherCost) rateHigherCost.textContent = 'Cost $' + Math.round(paymentHigher - totalMonthly).toLocaleString() + '/mo';

      // Update Amortization Breakdown - NEW FEATURE
      // Calculate first payment breakdown
      const monthlyRate = rate / 100 / 12;
      const firstInterest = loanAmount * monthlyRate;
      const firstPrincipal = monthlyPI - firstInterest;
      const interestPct = (firstInterest / monthlyPI) * 100;
      const principalPct = (firstPrincipal / monthlyPI) * 100;

      // Update visual bar
      const amortInterest = document.getElementById('v3AmortInterest');
      const amortPrincipal = document.getElementById('v3AmortPrincipal');
      if (amortInterest) amortInterest.style.width = interestPct + '%';
      if (amortPrincipal) amortPrincipal.style.width = principalPct + '%';

      // Calculate milestones
      const year1Interest = document.getElementById('v3Year1Interest');
      const year15Split = document.getElementById('v3Year15Split');
      const year30Principal = document.getElementById('v3Year30Principal');

      if (year1Interest) year1Interest.textContent = Math.round(interestPct) + '%';

      // Year 15 calculation (halfway point for 30-year)
      const paymentsAt15 = 180;
      const remainingAt15 = loanAmount * Math.pow(1 + monthlyRate, paymentsAt15) -
                           (monthlyPI * (Math.pow(1 + monthlyRate, paymentsAt15) - 1) / monthlyRate);
      const interest15 = remainingAt15 * monthlyRate;
      const principal15 = monthlyPI - interest15;
      const interestPct15 = (interest15 / monthlyPI) * 100;
      const principalPct15 = (principal15 / monthlyPI) * 100;

      if (year15Split) {
        year15Split.textContent = Math.round(interestPct15) + '/' + Math.round(principalPct15);
      }

      // Last payment is almost all principal
      if (year30Principal) year30Principal.textContent = Math.round(principalPct) + '%';
    };

    // Add event listeners
    elements.calcPrice.addEventListener('input', calculatePayment);
    elements.calcDownPercent.addEventListener('input', calculatePayment);
    elements.calcRate.addEventListener('input', calculatePayment);
    elements.calcTerm.addEventListener('change', calculatePayment);

    // Initial calculation
    calculatePayment();
  }

  /**
   * Setup modals
   */
  function setupModals() {
    // Close buttons for .mld-v3-modal
    elements.modalCloses.forEach((closeBtn) => {
      closeBtn.addEventListener('click', (e) => {
        const modal = e.target.closest('.mld-v3-modal');
        closeModal(modal);
      });
    });

    // Close on background click for .mld-v3-modal
    document.querySelectorAll('.mld-v3-modal').forEach((modal) => {
      modal.addEventListener('click', (e) => {
        if (e.target === modal) {
          closeModal(modal);
        }
      });
    });

    // Setup CTA buttons for new .mld-modal modals
    setupContactTourModals();

    // Chat form submissions (legacy)
    if (elements.contactForm) {
      elements.contactForm.addEventListener('submit', handleContactSubmit);
    }

    if (elements.tourForm) {
      elements.tourForm.addEventListener('submit', handleTourSubmit);
    }
  }

  /**
   * Setup Contact and Tour modal buttons and handlers
   */
  function setupContactTourModals() {
    const contactModal = document.getElementById('contactModal');
    const tourModal = document.getElementById('tourModal');
    const contactForm = contactModal ? contactModal.querySelector('#contactForm') : null;
    const tourForm = tourModal ? tourModal.querySelector('#tourForm') : null;

    // CTA button click handlers
    const scheduleTourBtns = document.querySelectorAll('.mld-schedule-tour');
    const contactAgentBtns = document.querySelectorAll('.mld-contact-agent');

    scheduleTourBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        // Analytics: Track contact click for tour (v6.38.0)
        document.dispatchEvent(new CustomEvent('mld:contact_click', {
          detail: {
            listingId: window.mldPropertyDataV3?.listing_id || null,
            contactType: 'tour'
          }
        }));
        if (tourModal) {
          tourModal.style.display = 'block';
          document.body.style.overflow = 'hidden';
        }
      });
    });

    contactAgentBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        // Analytics: Track contact click for message (v6.38.0)
        document.dispatchEvent(new CustomEvent('mld:contact_click', {
          detail: {
            listingId: window.mldPropertyDataV3?.listing_id || null,
            contactType: 'message'
          }
        }));
        if (contactModal) {
          contactModal.style.display = 'block';
          document.body.style.overflow = 'hidden';
        }
      });
    });

    // Close button handlers for .mld-modal
    document.querySelectorAll('.mld-modal .mld-modal-close').forEach(closeBtn => {
      closeBtn.addEventListener('click', (e) => {
        const modal = e.target.closest('.mld-modal');
        if (modal) {
          modal.style.display = 'none';
          document.body.style.overflow = '';
        }
      });
    });

    // Backdrop click handlers for .mld-modal
    document.querySelectorAll('.mld-modal .mld-modal-backdrop').forEach(backdrop => {
      backdrop.addEventListener('click', () => {
        const modal = backdrop.closest('.mld-modal');
        if (modal) {
          modal.style.display = 'none';
          document.body.style.overflow = '';
        }
      });
    });

    // Form submission handlers
    if (contactForm) {
      contactForm.addEventListener('submit', handleModalContactSubmit);
    }

    if (tourForm) {
      tourForm.addEventListener('submit', handleModalTourSubmit);
    }
  }

  /**
   * Handle contact form submission from modal
   */
  function handleModalContactSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('.mld-form-submit');
    const statusEl = form.querySelector('.mld-form-status');

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending...';
    }
    if (statusEl) {
      statusEl.textContent = '';
      statusEl.className = 'mld-form-status';
    }

    const formData = new FormData(form);
    formData.append('action', 'mld_contact_agent');

    fetch(window.mldSettings?.ajax_url || window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send Message';
      }

      if (data.success) {
        if (statusEl) {
          statusEl.className = 'mld-form-status success';
          statusEl.textContent = data.data?.message || 'Message sent successfully!';
        }
        form.reset();
        // Close modal after delay
        setTimeout(() => {
          const modal = form.closest('.mld-modal');
          if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
          }
        }, 2000);
      } else {
        if (statusEl) {
          statusEl.className = 'mld-form-status error';
          statusEl.textContent = data.data || 'An error occurred. Please try again.';
        }
      }
    })
    .catch(error => {
      console.error('Contact form error:', error);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send Message';
      }
      if (statusEl) {
        statusEl.className = 'mld-form-status error';
        statusEl.textContent = 'Network error. Please try again.';
      }
    });
  }

  /**
   * Handle tour form submission from modal
   */
  function handleModalTourSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('.mld-form-submit');
    const statusEl = form.querySelector('.mld-form-status');

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';
    }
    if (statusEl) {
      statusEl.textContent = '';
      statusEl.className = 'mld-form-status';
    }

    const formData = new FormData(form);
    formData.append('action', 'mld_schedule_tour');

    fetch(window.mldSettings?.ajax_url || window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Request Tour';
      }

      if (data.success) {
        if (statusEl) {
          statusEl.className = 'mld-form-status success';
          statusEl.textContent = data.data?.message || 'Tour request submitted successfully!';
        }
        form.reset();
        // Close modal after delay
        setTimeout(() => {
          const modal = form.closest('.mld-modal');
          if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
          }
        }, 2000);
      } else {
        if (statusEl) {
          statusEl.className = 'mld-form-status error';
          statusEl.textContent = data.data || 'An error occurred. Please try again.';
        }
      }
    })
    .catch(error => {
      console.error('Tour form error:', error);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Request Tour';
      }
      if (statusEl) {
        statusEl.className = 'mld-form-status error';
        statusEl.textContent = 'Network error. Please try again.';
      }
    });
  }

  /**
   * Setup action buttons
   */
  function setupActions() {
    // Save button
    if (elements.saveBtn) {
      elements.saveBtn.addEventListener('click', async () => {
        // Disable button during request
        elements.saveBtn.disabled = true;

        try {
          const formData = new FormData();
          formData.append('action', 'mld_save_property');
          formData.append('nonce', window.mldPropertyData?.nonce || '');
          formData.append('mls_number', window.mldPropertyDataV3?.mlsNumber || '');
          formData.append('action_type', 'toggle');

          const response = await fetch(
            window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php',
            {
              method: 'POST',
              body: formData,
            }
          );

          const result = await response.json();

          if (result.success) {
            state.saved = result.data.is_saved;

            if (state.saved) {
              elements.saveBtn.classList.add('saved');
              elements.saveBtn.querySelector('span').textContent = 'Saved';
            } else {
              elements.saveBtn.classList.remove('saved');
              elements.saveBtn.querySelector('span').textContent = 'Save';
            }
          } else {
            // Fallback to localStorage if AJAX fails
            handleLocalStorageSave();
          }
        } catch (error) {
          // Fallback to localStorage on error
          handleLocalStorageSave();
        } finally {
          elements.saveBtn.disabled = false;
        }
      });

      // Check if already saved on page load
      checkSavedStatus();
    }

    // Share button
    if (elements.shareBtn) {
      elements.shareBtn.addEventListener('click', async () => {
        // Analytics: Track share click (v6.38.0)
        const shareMethod = navigator.share ? 'native' : 'clipboard';
        document.dispatchEvent(new CustomEvent('mld:share_click', {
          detail: {
            listingId: window.mldPropertyDataV3?.listing_id || null,
            shareMethod: shareMethod
          }
        }));

        if (navigator.share) {
          try {
            await navigator.share({
              title: window.mldPropertyDataV3?.address || 'Property',
              text: `Check out this property: ${window.mldPropertyDataV3?.address}`,
              url: window.location.href,
            });
          } catch (err) {
            // Share cancelled
          }
        } else {
          // Fallback - copy to clipboard
          navigator.clipboard.writeText(window.location.href);

          // Show feedback
          const originalText = elements.shareBtn.querySelector('span').textContent;
          elements.shareBtn.querySelector('span').textContent = 'Link Copied!';
          setTimeout(() => {
            elements.shareBtn.querySelector('span').textContent = originalText;
          }, 2000);
        }
      });
    }
  }

  /**
   * Initialize map
   */
  function initializeMap() {
    if (!elements.propertyMap || !window.mldPropertyDataV3?.lat || !window.mldPropertyDataV3?.lng)
      return;

    const lat = parseFloat(window.mldPropertyDataV3.lat);
    const lng = parseFloat(window.mldPropertyDataV3.lng);

    // Check which map provider to use
    if (window.bmeMapDataV3?.mapProvider === 'google' && window.bmeMapDataV3?.google_key) {
      // Listen for Google Maps ready event if not already loaded
      if (!window.google || !window.google.maps || !window.google.maps.Map) {
        window.addEventListener(
          'googleMapsReady',
          () => {
            initGoogleMap(lat, lng);
          },
          { once: true }
        );
      } else {
        initGoogleMap(lat, lng);
      }
    } // Mapbox provider removed - Google Maps only for performance optimization
  }

  /**
   * Initialize Google Map
   * @param lat
   * @param lng
   */
  function initGoogleMap(lat, lng) {
    // Check if Google Maps is fully loaded
    if (!window.google || !window.google.maps || !window.google.maps.Map) {
      // Google Maps not yet loaded, loading now...

      // Check if script is already loading
      const existingScript = document.querySelector('script[src*="maps.googleapis.com"]');
      if (existingScript) {
        // Google Maps script already exists, waiting for it to load...
        // Wait for Google Maps to be ready
        const checkGoogleMaps = setInterval(() => {
          if (window.google && window.google.maps && window.google.maps.Map) {
            clearInterval(checkGoogleMaps);
            createGoogleMap(lat, lng);
          }
        }, 100);
        return;
      }

      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${window.bmeMapDataV3.google_key}&loading=async&callback=initV3GoogleMapCallback`;
      script.async = true;
      script.defer = true;
      document.head.appendChild(script);

      // Define callback
      window.initV3GoogleMapCallback = () => {
        createGoogleMap(lat, lng);
        // Also initialize gallery map if needed
        if (state.galleryView === 'map') {
          initGalleryMap();
        }
      };
    } else {
      createGoogleMap(lat, lng);
    }
  }

  /**
   * Create Google Map instance
   * @param lat
   * @param lng
   */
  function createGoogleMap(lat, lng) {
    // Check if we should use AdvancedMarkerElement
    const useAdvancedMarker = google.maps.marker && google.maps.marker.AdvancedMarkerElement;

    // Build map options conditionally
    const mapOptions = {
      center: { lat, lng },
      zoom: 15,

      // Map Type Control - Allow switching between Map/Satellite/Terrain
      mapTypeControl: true,
      mapTypeControlOptions: {
        style: google.maps.MapTypeControlStyle.DROPDOWN_MENU,
        position: google.maps.ControlPosition.TOP_RIGHT,
        mapTypeIds: ['roadmap', 'satellite', 'terrain', 'hybrid']
      },

      // Zoom Control - Enhanced positioning
      zoomControl: true,
      zoomControlOptions: {
        position: google.maps.ControlPosition.RIGHT_CENTER
      },

      // Street View Control - Enhanced positioning
      streetViewControl: true,
      streetViewControlOptions: {
        position: google.maps.ControlPosition.RIGHT_CENTER
      },

      // Fullscreen Control
      fullscreenControl: true,
      fullscreenControlOptions: {
        position: google.maps.ControlPosition.TOP_RIGHT
      },

      // Scale Control - Shows distance scale
      scaleControl: true,

      // Rotate Control - For 3D/45° imagery
      rotateControl: true,
      rotateControlOptions: {
        position: google.maps.ControlPosition.RIGHT_CENTER
      },

      // Gesture Handling - Better mobile/desktop experience
      gestureHandling: 'cooperative',
    };

    // Only add mapId if using AdvancedMarkerElement
    // When mapId is present, styles must be configured in Google Cloud Console
    if (useAdvancedMarker) {
      mapOptions.mapId = 'PROPERTY_MAP'; // Required for AdvancedMarkerElement
    } else {
      // Only add styles if NOT using mapId (classic mode)
      mapOptions.styles = [
        {
          featureType: 'poi',
          elementType: 'labels',
          stylers: [{ visibility: 'off' }],
        },
      ];
    }

    state.map = new google.maps.Map(elements.propertyMap, mapOptions);

    // Create marker based on availability
    if (useAdvancedMarker) {
      state.mapMarker = new google.maps.marker.AdvancedMarkerElement({
        position: { lat, lng },
        map: state.map,
        title: window.mldPropertyDataV3?.address || 'Property Location',
      });
    } else {
      // Fallback to classic Marker for older browsers
      state.mapMarker = new google.maps.Marker({
        position: { lat, lng },
        map: state.map,
        title: window.mldPropertyDataV3?.address || 'Property Location',
      });
    }

    // Add Info Window with property details
    const propertyAddress = window.mldPropertyDataV3?.address || 'Property Location';
    const propertyPrice = window.mldPropertyDataV3?.price ?
      '$' + window.mldPropertyDataV3.price.toLocaleString() : '';

    const infoContent = `
      <div style="padding: 10px; max-width: 250px;">
        <h3 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #1d2327;">
          ${propertyAddress}
        </h3>
        ${propertyPrice ? `<p style="margin: 0 0 12px 0; font-size: 18px; font-weight: 700; color: #2271b1;">${propertyPrice}</p>` : ''}
        <a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}"
           target="_blank"
           style="display: inline-block; padding: 8px 16px; background: #2271b1; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; font-weight: 500;">
          Get Directions
        </a>
      </div>
    `;

    const infoWindow = new google.maps.InfoWindow({
      content: infoContent
    });

    // Show info window on marker click
    if (useAdvancedMarker) {
      state.mapMarker.addListener('click', () => {
        infoWindow.open(state.map, state.mapMarker);
      });
    } else {
      state.mapMarker.addListener('click', () => {
        infoWindow.open(state.map, state.mapMarker);
      });
    }

    // Add custom "Get Directions" button to map
    const directionsButton = document.createElement('button');
    directionsButton.textContent = '🚗 Get Directions';
    directionsButton.style.cssText = `
      background-color: #fff;
      border: 2px solid #fff;
      border-radius: 3px;
      box-shadow: 0 2px 6px rgba(0,0,0,.3);
      color: #333;
      cursor: pointer;
      font-family: Roboto,Arial,sans-serif;
      font-size: 14px;
      font-weight: 500;
      line-height: 38px;
      margin: 10px;
      padding: 0 12px;
      text-align: center;
    `;
    directionsButton.addEventListener('click', () => {
      window.open(`https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`, '_blank');
    });

    // Add the button to the map
    state.map.controls[google.maps.ControlPosition.TOP_LEFT].push(directionsButton);
  }

  // Mapbox map initialization functions removed - Google Maps only for performance optimization

  /**
   * Initialize Walk Score
   */
  function initializeWalkScore() {
    const container = document.getElementById('v3-walk-score-container');

    // Walk Score initialization

    if (!container) {
      // Walk Score: No container found
      return;
    }

    // Check if Walk Score is enabled (handle 1, "1", true)
    const walkScoreEnabled = window.mldSettings?.enableWalkScore;
    if (!walkScoreEnabled || walkScoreEnabled === '0' || walkScoreEnabled === 'false') {
      // Walk Score: Not enabled in settings
      return;
    }

    if (!window.mldPropertyDataV3?.lat || !window.mldPropertyDataV3?.lng) {
      // Walk Score: No coordinates available
      return;
    }

    // Clear loading message
    container.innerHTML =
      '<div style="color: #666; font-size: 14px;">Fetching Walk Score data...</div>';

    // Use the same approach as V2 - get from mldPropertyData like V2 does
    const ajaxUrl = window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php';
    const nonce = window.mldPropertyData?.nonce || '';

    const formData = new FormData();
    formData.append('action', 'get_walk_score');
    formData.append('nonce', nonce);
    formData.append(
      'address',
      window.mldPropertyDataV3.address || window.mldPropertyData?.address || ''
    );
    formData.append('lat', window.mldPropertyDataV3.lat);
    formData.append('lng', window.mldPropertyDataV3.lng);
    formData.append('transit', '1');
    formData.append('bike', '1');

    // Making Walk Score AJAX request

    // Make AJAX call to get Walk Score data (same as V2)
    fetch(ajaxUrl, {
      method: 'POST',
      body: formData,
    })
      .then((response) => {
        // Check Walk Score response status
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then((result) => {
        // Process Walk Score result
        if (result.success && result.data) {
          displayWalkScore(container, result.data);
        } else {
          container.innerHTML =
            '<div style="color: #999; font-size: 14px;">Walk Score not available for this location</div>';
        }
      })
      .catch((error) => {
        // Error fetching Walk Score
        container.innerHTML =
          '<div style="color: #999; font-size: 14px;">Unable to load Walk Score</div>';
      });
  }

  /**
   * Display Walk Score data
   * @param container
   * @param data
   */
  function displayWalkScore(container, data) {
    // Display Walk Score data

    // Handle different data formats from the API
    const walkScore = data.walkscore || data.walk || null;
    const transitScore = data.transit?.score || data.transit || null;
    const bikeScore = data.bike?.score || data.bike || null;
    const description = data.description || '';

    const html = `
            <div class="mld-walk-score-modern">
                <div class="mld-ws-scores">
                    ${renderScoreCard('walk', 'Walk Score', '🚶', walkScore, description)}
                    ${renderScoreCard('transit', 'Transit Score', '🚌', transitScore)}
                    ${renderScoreCard('bike', 'Bike Score', '🚴', bikeScore)}
                </div>
                
                
                <div class="mld-ws-footer">
                    <img src="https://cdn.walk.sc/images/api-logo.png" alt="Walk Score" class="mld-ws-logo">
                    <a href="https://www.walkscore.com" target="_blank" rel="noopener" class="mld-ws-link">Learn more at walkscore.com</a>
                </div>
            </div>
        `;
    container.innerHTML = html;
  }

  /**
   * Render a score card
   * @param type
   * @param label
   * @param icon
   * @param score
   * @param description
   */
  function renderScoreCard(type, label, icon, score, description) {
    const rating = getScoreRating(score);
    const color = getScoreColor(score);

    return `
            <div class="mld-ws-score-card ${score === null ? 'unavailable' : ''}" data-score-type="${type}">
                <div class="mld-ws-score-icon">${icon}</div>
                <div class="mld-ws-score-details">
                    <div class="mld-ws-score-label">${label}</div>
                    ${
                      score !== null
                        ? `
                        <div class="mld-ws-score-value" style="color: ${color}">${score}</div>
                        <div class="mld-ws-score-rating">${rating}</div>
                        ${type === 'walk' && description ? `<div class="mld-ws-score-desc">${description}</div>` : ''}
                    `
                        : `
                        <div class="mld-ws-score-unavailable">Not available</div>
                    `
                    }
                </div>
                ${
                  score !== null
                    ? `
                    <div class="mld-ws-score-gauge">
                        <div class="mld-ws-score-gauge-fill" style="width: ${score}%; background-color: ${color}"></div>
                    </div>
                `
                    : ''
                }
            </div>
        `;
  }

  /**
   * Get score rating text
   * @param score
   */
  function getScoreRating(score) {
    if (score === null) return '';
    if (score >= 90) return "Walker's Paradise";
    if (score >= 70) return 'Very Walkable';
    if (score >= 50) return 'Somewhat Walkable';
    if (score >= 25) return 'Car-Dependent';
    return 'Car-Dependent';
  }

  /**
   * Get color for Walk Score
   * @param score
   */
  function getScoreColor(score) {
    if (score >= 90) return '#00B22D';
    if (score >= 70) return '#7FBF00';
    if (score >= 50) return '#FFBF00';
    if (score >= 25) return '#FF7F00';
    return '#FF0000';
  }

  /**
   * Open modal
   * @param modal
   */
  function openModal(modal) {
    if (modal) {
      modal.classList.add('active');
      document.body.style.overflow = 'hidden';
    }
  }

  /**
   * Close modal
   * @param modal
   */
  function closeModal(modal) {
    if (modal) {
      modal.classList.remove('active');
      document.body.style.overflow = '';
    }
  }

  /**
   * Handle contact form submission (chat interface)
   * @param e
   */
  function handleContactSubmit(e) {
    e.preventDefault();

    const formData = new FormData(e.target);
    formData.append('nonce', window.mldPropertyData?.nonce || window.mldSettings?.ajax_nonce);

    // Show loading state
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Sending...';
    submitBtn.disabled = true;

    // Get chat elements for messaging
    const chatMessages = document.getElementById('v3ChatMessages');
    const chatForms = document.getElementById('v3ChatForms');
    const chatInputArea = document.getElementById('v3ChatInputArea');

    fetch(
      window.mldPropertyData?.ajaxUrl || window.mldSettings?.ajax_url || '/wp-admin/admin-ajax.php',
      {
        method: 'POST',
        body: formData,
      }
    )
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          // Hide form
          if (chatForms) chatForms.style.display = 'none';
          if (chatInputArea) chatInputArea.style.display = 'block';

          // Show success message in chat
          setTimeout(() => {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'mld-v3-chat-message system';
            const bubble = document.createElement('div');
            bubble.className = 'mld-v3-message-bubble';
            bubble.textContent = "Thanks! Your message has been sent successfully. We'll get back to you shortly!";
            messageDiv.appendChild(bubble);
            if (chatMessages) {
              chatMessages.appendChild(messageDiv);
              chatMessages.scrollTop = chatMessages.scrollHeight;
            }
          }, 300);

          // Mark contact info as collected
          try {
            const propertyMls = window.mldPropertyDataV3?.mls_number || 'default';
            const stored = localStorage.getItem(`mld_chat_state_${propertyMls}`);
            if (stored) {
              const state = JSON.parse(stored);
              state.contactInfoCollected = true;
              localStorage.setItem(`mld_chat_state_${propertyMls}`, JSON.stringify(state));
            }
          } catch (e) {
            console.error('Error updating chat state:', e);
          }

          e.target.reset();
        } else {
          // Show error in chat
          const messageDiv = document.createElement('div');
          messageDiv.className = 'mld-v3-chat-message system';
          const bubble = document.createElement('div');
          bubble.className = 'mld-v3-message-bubble';
          bubble.textContent = 'Sorry, there was an error sending your message. Please try again.';
          messageDiv.appendChild(bubble);
          if (chatMessages) {
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
          }
        }
      })
      .catch((error) => {
        // Show error in chat
        const messageDiv = document.createElement('div');
        messageDiv.className = 'mld-v3-chat-message system';
        const bubble = document.createElement('div');
        bubble.className = 'mld-v3-message-bubble';
        bubble.textContent = 'Sorry, there was an error sending your message. Please try again.';
        messageDiv.appendChild(bubble);
        if (chatMessages) {
          chatMessages.appendChild(messageDiv);
          chatMessages.scrollTop = chatMessages.scrollHeight;
        }
      })
      .finally(() => {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
      });
  }

  /**
   * Handle tour form submission (chat interface)
   * @param e
   */
  function handleTourSubmit(e) {
    e.preventDefault();

    const formData = new FormData(e.target);
    formData.append('nonce', window.mldPropertyData?.nonce || window.mldSettings?.ajax_nonce);

    // Show loading state
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Requesting...';
    submitBtn.disabled = true;

    // Get chat elements for messaging
    const chatMessages = document.getElementById('v3ChatMessages');
    const chatForms = document.getElementById('v3ChatForms');
    const chatInputArea = document.getElementById('v3ChatInputArea');

    fetch(
      window.mldPropertyData?.ajaxUrl || window.mldSettings?.ajax_url || '/wp-admin/admin-ajax.php',
      {
        method: 'POST',
        body: formData,
      }
    )
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          // Hide form
          if (chatForms) chatForms.style.display = 'none';
          if (chatInputArea) chatInputArea.style.display = 'block';

          // Show success message in chat
          setTimeout(() => {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'mld-v3-chat-message system';
            const bubble = document.createElement('div');
            bubble.className = 'mld-v3-message-bubble';
            bubble.textContent = "Perfect! Your tour request has been submitted. We'll contact you within 24 hours to confirm your appointment.";
            messageDiv.appendChild(bubble);
            if (chatMessages) {
              chatMessages.appendChild(messageDiv);
              chatMessages.scrollTop = chatMessages.scrollHeight;
            }
          }, 300);

          // Mark contact info as collected
          try {
            const propertyMls = window.mldPropertyDataV3?.mls_number || 'default';
            const stored = localStorage.getItem(`mld_chat_state_${propertyMls}`);
            if (stored) {
              const state = JSON.parse(stored);
              state.contactInfoCollected = true;
              localStorage.setItem(`mld_chat_state_${propertyMls}`, JSON.stringify(state));
            }
          } catch (e) {
            console.error('Error updating chat state:', e);
          }

          e.target.reset();
        } else {
          // Show error in chat
          const messageDiv = document.createElement('div');
          messageDiv.className = 'mld-v3-chat-message system';
          const bubble = document.createElement('div');
          bubble.className = 'mld-v3-message-bubble';
          bubble.textContent = 'Sorry, there was an error submitting your tour request. Please try again.';
          messageDiv.appendChild(bubble);
          if (chatMessages) {
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
          }
        }
      })
      .catch((error) => {
        // Show error in chat
        const messageDiv = document.createElement('div');
        messageDiv.className = 'mld-v3-chat-message system';
        const bubble = document.createElement('div');
        bubble.className = 'mld-v3-message-bubble';
        bubble.textContent = 'Sorry, there was an error submitting your tour request. Please try again.';
        messageDiv.appendChild(bubble);
        if (chatMessages) {
          chatMessages.appendChild(messageDiv);
          chatMessages.scrollTop = chatMessages.scrollHeight;
        }
      })
      .finally(() => {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
      });
  }

  /**
   * Toggle gallery view between photos, map, street view, and virtual tours
   * @param view
   * @param options
   */
  function toggleGalleryView(view, options = {}) {
    // Analytics: Track tab/view switch (v6.38.0)
    const listingId = window.mldPropertyDataV3?.listing_id || null;
    document.dispatchEvent(new CustomEvent('mld:tab_click', {
      detail: { listingId: listingId, tabName: view }
    }));

    // Track street view open
    if (view === 'streetview') {
      document.dispatchEvent(new CustomEvent('mld:street_view_open', {
        detail: { listingId: listingId }
      }));
    }

    // Track video/virtual tour play
    if (view === 'tour') {
      document.dispatchEvent(new CustomEvent('mld:video_play', {
        detail: {
          listingId: listingId,
          videoType: options.type || 'virtual_tour'
        }
      }));
    }

    // Hide all views
    elements.galleryImages.forEach((img) => img.classList.remove('active'));
    document.querySelector('.mld-v3-gallery-map')?.classList.remove('active');
    document.querySelector('.mld-v3-gallery-streetview')?.classList.remove('active');

    // Hide all tour containers
    document.querySelectorAll('.mld-v3-gallery-tour').forEach((container) => {
      container.classList.remove('active');
    });

    // Update navigation visibility (keep action buttons always visible)
    if (view === 'photos') {
      elements.prevBtn?.style.setProperty('display', 'flex');
      elements.nextBtn?.style.setProperty('display', 'flex');
      // Show current photo
      if (elements.galleryImages[state.currentPhoto]) {
        elements.galleryImages[state.currentPhoto].classList.add('active');
      }
    } else {
      // Hide prev/next but keep action buttons visible
      elements.prevBtn?.style.setProperty('display', 'none');
      elements.nextBtn?.style.setProperty('display', 'none');

      if (view === 'map') {
        const mapContainer = document.getElementById('v3GalleryMap');
        if (mapContainer) {
          mapContainer.classList.add('active');
          if (!state.galleryMap) {
            initGalleryMap();
          }
        }
      } else if (view === 'streetview') {
        const streetViewContainer = document.getElementById('v3GalleryStreetView');
        if (streetViewContainer && window.mldPropertyDataV3?.lat && window.mldPropertyDataV3?.lng) {
          streetViewContainer.classList.add('active');
          if (!streetViewContainer.querySelector('iframe')) {
            const iframe = document.createElement('iframe');
            iframe.src = `https://www.google.com/maps/embed/v1/streetview?key=${window.bmeMapDataV3?.google_key}&location=${window.mldPropertyDataV3.lat},${window.mldPropertyDataV3.lng}&heading=0&pitch=0&fov=90`;
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.style.border = 'none';
            streetViewContainer.appendChild(iframe);
          }
        }
      } else if (view === 'tour' && options.index !== undefined) {
        const tourContainer = document.getElementById(`v3GalleryTour${options.index}`);
        if (tourContainer) {
          tourContainer.classList.add('active');

          // Only embed if not already embedded
          if (!tourContainer.querySelector('iframe')) {
            const embedUrl = tourContainer.getAttribute('data-embed-url');
            const tourType = tourContainer.getAttribute('data-tour-type');

            if (embedUrl) {
              const iframe = document.createElement('iframe');
              iframe.src = embedUrl;
              iframe.style.width = '100%';
              iframe.style.height = '100%';
              iframe.style.border = 'none';
              iframe.setAttribute('allowfullscreen', 'allowfullscreen');
              iframe.setAttribute('loading', 'lazy');

              // Add specific attributes based on tour type
              if (tourType === 'youtube' || tourType === 'vimeo') {
                iframe.setAttribute(
                  'allow',
                  'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture'
                );
              }

              // Add loaded class when iframe loads to remove spinner
              iframe.addEventListener('load', () => {
                tourContainer.classList.add('loaded');
              });

              tourContainer.appendChild(iframe);
            }
          }
        }
      }
    }

    state.galleryView = view;
    state.currentTourIndex = options.index;

    // Update button states
    elements.viewPhotosBtn?.classList.toggle('active', view === 'photos');
    elements.mapViewBtn?.classList.toggle('active', view === 'map');
    elements.streetViewBtn?.classList.toggle('active', view === 'streetview');

    // Update tour button states
    document.querySelectorAll('.mld-v3-virtual-tour-btn').forEach((btn, index) => {
      btn.classList.toggle('active', view === 'tour' && options.index == index);
    });
  }

  /**
   * Initialize gallery map
   */
  function initGalleryMap() {
    const mapContainer = document.getElementById('v3GalleryMap');
    if (!mapContainer || !window.mldPropertyDataV3?.lat || !window.mldPropertyDataV3?.lng) return;

    const lat = parseFloat(window.mldPropertyDataV3.lat);
    const lng = parseFloat(window.mldPropertyDataV3.lng);

    if (window.google && window.google.maps) {
      state.galleryMap = new google.maps.Map(mapContainer, {
        center: { lat, lng },
        zoom: 15,
        mapTypeControl: true,
        fullscreenControl: false,
      });

      state.galleryMapMarker = new google.maps.Marker({
        position: { lat, lng },
        map: state.galleryMap,
        title: window.mldPropertyDataV3?.address || 'Property Location',
      });
    }
  }

  /**
   * Setup calendar functionality
   */
  function setupCalendar() {
    document.querySelectorAll('.mld-v3-add-calendar').forEach((btn) => {
      btn.addEventListener('click', function () {
        const title = this.dataset.title;
        const startDateStr = this.dataset.start;
        const endDateStr = this.dataset.end;
        const location = this.dataset.location;
        const timezone = this.dataset.timezone || 'America/New_York';

        // Parse the dates - they're already in Eastern Time
        const startDate = new Date(startDateStr);
        const endDate = new Date(endDateStr);

        // Format dates for calendar URL
        // Google Calendar expects UTC times in the format YYYYMMDDTHHmmssZ
        const formatDateForCalendar = (date) => {
          // The date is already in Eastern Time, we need to convert to UTC
          const year = date.getFullYear();
          const month = String(date.getMonth() + 1).padStart(2, '0');
          const day = String(date.getDate()).padStart(2, '0');
          const hours = String(date.getHours()).padStart(2, '0');
          const minutes = String(date.getMinutes()).padStart(2, '0');
          const seconds = String(date.getSeconds()).padStart(2, '0');

          // Return in local time format (without Z) and let Google Calendar handle timezone
          return `${year}${month}${day}T${hours}${minutes}${seconds}`;
        };

        // Create calendar event URL (Google Calendar)
        const googleUrl = new URL('https://calendar.google.com/calendar/render');
        googleUrl.searchParams.append('action', 'TEMPLATE');
        googleUrl.searchParams.append('text', title);
        googleUrl.searchParams.append(
          'dates',
          `${formatDateForCalendar(startDate)}/${formatDateForCalendar(endDate)}`
        );
        googleUrl.searchParams.append('location', location);
        googleUrl.searchParams.append('details', `Open house at ${location}`);
        googleUrl.searchParams.append('ctz', timezone); // Add timezone parameter

        // Send tracking notification to admin
        const formData = new FormData();
        formData.append('action', 'mld_track_calendar_add');
        formData.append('nonce', window.mldPropertyData?.nonce || window.mldSettings?.ajax_nonce);
        formData.append('mls_number', window.mldPropertyDataV3?.mlsNumber || '');
        formData.append('property_address', location);
        formData.append(
          'open_house_date',
          startDate.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
          })
        );
        formData.append(
          'open_house_time',
          `${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })} - ${endDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}`
        );

        // Send the tracking request (don't wait for response)
        fetch(
          window.mldPropertyData?.ajaxUrl ||
            window.mldSettings?.ajax_url ||
            '/wp-admin/admin-ajax.php',
          {
            method: 'POST',
            body: formData,
          }
        ).catch((error) => {
          // Silently fail - don't interrupt user experience
          MLDLogger.error('Calendar tracking error:', error);
        });

        window.open(googleUrl, '_blank');
      });
    });
  }

  // Initialize calendar functionality
  setupCalendar();

  // Back button for gallery views
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && state.galleryView !== 'photos') {
      toggleGalleryView('photos');
    }
  });

  /**
   * Handle localStorage save as fallback
   */
  function handleLocalStorageSave() {
    state.saved = !state.saved;

    if (state.saved) {
      elements.saveBtn.classList.add('saved');
      elements.saveBtn.querySelector('span').textContent = 'Saved';

      // Save to localStorage
      const savedProperties = JSON.parse(localStorage.getItem('savedProperties') || '[]');
      if (!savedProperties.includes(window.mldPropertyDataV3?.mlsNumber)) {
        savedProperties.push(window.mldPropertyDataV3?.mlsNumber);
        localStorage.setItem('savedProperties', JSON.stringify(savedProperties));
      }
    } else {
      elements.saveBtn.classList.remove('saved');
      elements.saveBtn.querySelector('span').textContent = 'Save';

      // Remove from localStorage
      const savedProperties = JSON.parse(localStorage.getItem('savedProperties') || '[]');
      const index = savedProperties.indexOf(window.mldPropertyDataV3?.mlsNumber);
      if (index > -1) {
        savedProperties.splice(index, 1);
        localStorage.setItem('savedProperties', JSON.stringify(savedProperties));
      }
    }
  }

  /**
   * Check if property is saved
   */
  async function checkSavedStatus() {
    // First check backend
    try {
      const formData = new FormData();
      formData.append('action', 'mld_get_saved_properties');
      formData.append('nonce', window.mldPropertyData?.nonce || '');

      const response = await fetch(window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php', {
        method: 'POST',
        body: formData,
      });

      const result = await response.json();

      if (
        result.success &&
        result.data.saved_properties.includes(window.mldPropertyDataV3?.mlsNumber)
      ) {
        state.saved = true;
        elements.saveBtn.classList.add('saved');
        elements.saveBtn.querySelector('span').textContent = 'Saved';
      } else {
        // Check localStorage as fallback
        checkLocalStorageSaved();
      }
    } catch (error) {
      // Fallback to localStorage on error
      checkLocalStorageSaved();
    }
  }

  /**
   * Check localStorage for saved status
   */
  function checkLocalStorageSaved() {
    const savedProperties = JSON.parse(localStorage.getItem('savedProperties') || '[]');
    if (savedProperties.includes(window.mldPropertyDataV3?.mlsNumber)) {
      state.saved = true;
      elements.saveBtn.classList.add('saved');
      elements.saveBtn.querySelector('span').textContent = 'Saved';
    }
  }

  /**
   * Initialize MLS Number Copy to Clipboard
   */
  function initializeMLSCopyToClipboard() {
    const mlsElement = document.querySelector('.mld-v3-mls-number');

    if (!mlsElement) return;

    mlsElement.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();

      const mlsNumber = this.dataset.mls;

      // Copy to clipboard using modern API with fallback
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(mlsNumber).then(() => {
          showCopyFeedback(this);
        }).catch(() => {
          fallbackCopyToClipboard(mlsNumber, this);
        });
      } else {
        fallbackCopyToClipboard(mlsNumber, this);
      }
    });
  }

  /**
   * Fallback copy method for older browsers
   */
  function fallbackCopyToClipboard(text, element) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.top = '0';
    textArea.style.left = '0';
    textArea.style.width = '2em';
    textArea.style.height = '2em';
    textArea.style.padding = '0';
    textArea.style.border = 'none';
    textArea.style.outline = 'none';
    textArea.style.boxShadow = 'none';
    textArea.style.background = 'transparent';

    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      document.execCommand('copy');
      showCopyFeedback(element);
    } catch (err) {
      // ERROR: commented
    }

    document.body.removeChild(textArea);
  }

  /**
   * Show copy feedback
   */
  function showCopyFeedback(element) {
    element.classList.add('copied');

    setTimeout(() => {
      element.classList.remove('copied');
    }, 2000);
  }
})();
