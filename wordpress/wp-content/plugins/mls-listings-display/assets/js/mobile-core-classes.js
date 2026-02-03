/**
 * Mobile Core Classes
 * Essential classes required by the mobile property template
 * Version: 1.0.0
 */

(function() {
  'use strict';

  // Safe Logger wrapper (shared utility)
  if (typeof window.SafeLogger === 'undefined') {
    window.SafeLogger = {
      debug: function(msg, context) {
        if (typeof MLDLogger !== 'undefined' && MLDLogger.debug) {
          MLDLogger.debug(msg, context);
        } else if (console && console.debug) {
          console.debug('[MLD Debug]', msg, context || '');
        }
      },
      info: function(msg, context) {
        if (typeof MLDLogger !== 'undefined' && MLDLogger.info) {
          MLDLogger.info(msg, context);
        } else if (console && console.info) {
          console.info('[MLD Info]', msg, context || '');
        }
      },
      warning: function(msg, context) {
        if (typeof MLDLogger !== 'undefined' && MLDLogger.warning) {
          MLDLogger.warning(msg, context);
        } else if (console && console.warn) {
          console.warn('[MLD Warning]', msg, context || '');
        }
      },
      error: function(msg, context) {
        if (typeof MLDLogger !== 'undefined' && MLDLogger.error) {
          MLDLogger.error(msg, context);
        } else if (console && console.error) {
          console.error('[MLD Error]', msg, context || '');
        }
      }
    };
  }

  // SectionManager Class
  window.SectionManager = class SectionManager {
    constructor() {
      this.sections = new Map();
      this.activeSection = null;
      this.init();
    }

    init() {
      SafeLogger.debug('SectionManager initialized');
      this.registerSections();
      this.bindEvents();
    }

    registerSections() {
      // Auto-register sections based on common patterns
      const sectionElements = document.querySelectorAll('[data-section], .mld-section, .property-section');
      sectionElements.forEach(el => {
        const sectionId = el.dataset.section || el.id || `section-${Date.now()}`;
        this.registerSection(sectionId, el);
      });
    }

    registerSection(id, element) {
      if (!element) return;

      this.sections.set(id, {
        id: id,
        element: element,
        visible: !element.classList.contains('hidden'),
        initialized: false
      });

      SafeLogger.debug(`Section registered: ${id}`);
    }

    showSection(sectionId) {
      const section = this.sections.get(sectionId);
      if (!section) {
        SafeLogger.warning(`Section not found: ${sectionId}`);
        return false;
      }

      // Hide current active section
      if (this.activeSection && this.activeSection !== sectionId) {
        this.hideSection(this.activeSection);
      }

      section.element.classList.remove('hidden');
      section.element.style.display = '';
      section.visible = true;
      this.activeSection = sectionId;

      SafeLogger.debug(`Section shown: ${sectionId}`);
      return true;
    }

    hideSection(sectionId) {
      const section = this.sections.get(sectionId);
      if (!section) return false;

      section.element.classList.add('hidden');
      section.visible = false;

      if (this.activeSection === sectionId) {
        this.activeSection = null;
      }

      SafeLogger.debug(`Section hidden: ${sectionId}`);
      return true;
    }

    bindEvents() {
      // Handle section toggle buttons
      document.addEventListener('click', (e) => {
        const toggleBtn = e.target.closest('[data-toggle-section]');
        if (toggleBtn) {
          e.preventDefault();
          const sectionId = toggleBtn.dataset.toggleSection;
          const section = this.sections.get(sectionId);
          if (section) {
            if (section.visible) {
              this.hideSection(sectionId);
            } else {
              this.showSection(sectionId);
            }
          }
        }
      });
    }

    getSectionInfo(sectionId) {
      return this.sections.get(sectionId) || null;
    }
  };

  // ModalHandler Class
  window.ModalHandler = class ModalHandler {
    constructor() {
      this.modals = new Map();
      this.activeModal = null;
      this.init();
    }

    init() {
      SafeLogger.debug('ModalHandler initialized');
      this.registerModals();
      this.bindEvents();
    }

    registerModals() {
      // Auto-register modals
      const modalElements = document.querySelectorAll('.modal, [data-modal], .mld-modal');
      modalElements.forEach(el => {
        const modalId = el.dataset.modal || el.id || `modal-${Date.now()}`;
        this.registerModal(modalId, el);
      });
    }

    registerModal(id, element) {
      if (!element) return;

      this.modals.set(id, {
        id: id,
        element: element,
        isOpen: false,
        closeOnOverlayClick: !element.dataset.noOverlayClose,
        closeOnEscape: !element.dataset.noEscapeClose
      });

      SafeLogger.debug(`Modal registered: ${id}`);
    }

    openModal(modalId, options = {}) {
      const modal = this.modals.get(modalId);
      if (!modal) {
        SafeLogger.warning(`Modal not found: ${modalId}`);
        return false;
      }

      // Close active modal first
      if (this.activeModal && this.activeModal !== modalId) {
        this.closeModal(this.activeModal);
      }

      modal.element.style.display = 'flex';
      modal.element.classList.add('active', 'open');
      modal.element.classList.remove('hidden');
      modal.isOpen = true;
      this.activeModal = modalId;

      // Prevent body scroll
      document.body.style.overflow = 'hidden';

      SafeLogger.debug(`Modal opened: ${modalId}`);
      return true;
    }

    closeModal(modalId) {
      const modal = this.modals.get(modalId);
      if (!modal) return false;

      modal.element.style.display = 'none';
      modal.element.classList.remove('active', 'open');
      modal.element.classList.add('hidden');
      modal.isOpen = false;

      if (this.activeModal === modalId) {
        this.activeModal = null;
      }

      // Restore body scroll
      document.body.style.overflow = '';

      SafeLogger.debug(`Modal closed: ${modalId}`);
      return true;
    }

    bindEvents() {
      // Handle modal open buttons
      document.addEventListener('click', (e) => {
        const openBtn = e.target.closest('[data-open-modal]');
        if (openBtn) {
          e.preventDefault();
          const modalId = openBtn.dataset.openModal;
          this.openModal(modalId);
        }

        const closeBtn = e.target.closest('[data-close-modal], .modal-close, .close-modal');
        if (closeBtn) {
          e.preventDefault();
          const modalId = closeBtn.dataset.closeModal || this.activeModal;
          if (modalId) {
            this.closeModal(modalId);
          }
        }
      });

      // Handle overlay clicks
      document.addEventListener('click', (e) => {
        if (this.activeModal && e.target.classList.contains('modal-overlay')) {
          const modal = this.modals.get(this.activeModal);
          if (modal && modal.closeOnOverlayClick) {
            this.closeModal(this.activeModal);
          }
        }
      });

      // Handle escape key
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.activeModal) {
          const modal = this.modals.get(this.activeModal);
          if (modal && modal.closeOnEscape) {
            this.closeModal(this.activeModal);
          }
        }
      });
    }
  };

  // FormHandler Class
  window.FormHandler = class FormHandler {
    constructor() {
      this.forms = new Map();
      this.init();
    }

    init() {
      SafeLogger.debug('FormHandler initialized');
      this.registerForms();
      this.bindEvents();
    }

    registerForms() {
      // Auto-register forms
      const formElements = document.querySelectorAll('form[data-mld-form], .mld-form form, .contact-form, .tour-form');
      formElements.forEach(form => {
        const formId = form.dataset.mldForm || form.id || `form-${Date.now()}`;
        this.registerForm(formId, form);
      });
    }

    registerForm(id, formElement) {
      if (!formElement) return;

      this.forms.set(id, {
        id: id,
        element: formElement,
        submitHandler: null,
        validation: {},
        isSubmitting: false
      });

      SafeLogger.debug(`Form registered: ${id}`);
    }

    handleSubmit(formId, handler) {
      const form = this.forms.get(formId);
      if (!form) return false;

      form.submitHandler = handler;
      return true;
    }

    async submitForm(formId, customData = {}) {
      const form = this.forms.get(formId);
      if (!form || form.isSubmitting) return false;

      try {
        form.isSubmitting = true;
        this.showLoading(formId);

        const formData = new FormData(form.element);

        // Add custom data
        Object.keys(customData).forEach(key => {
          formData.append(key, customData[key]);
        });

        // Use custom handler if available
        if (form.submitHandler) {
          const result = await form.submitHandler(formData);
          this.hideLoading(formId);
          form.isSubmitting = false;
          return result;
        }

        // Default AJAX submission
        const response = await fetch(window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        this.hideLoading(formId);
        form.isSubmitting = false;

        if (result.success) {
          this.showSuccess(formId, result.data?.message || 'Form submitted successfully');
        } else {
          this.showError(formId, result.data?.message || 'Submission failed');
        }

        return result;

      } catch (error) {
        SafeLogger.error('Form submission error:', error);
        this.hideLoading(formId);
        this.showError(formId, 'An error occurred. Please try again.');
        form.isSubmitting = false;
        return { success: false, error: error.message };
      }
    }

    showLoading(formId) {
      const form = this.forms.get(formId);
      if (!form) return;

      const submitBtn = form.element.querySelector('[type="submit"]');
      const loadingDiv = form.element.querySelector('.loading');

      if (submitBtn) submitBtn.disabled = true;
      if (loadingDiv) loadingDiv.style.display = 'block';
    }

    hideLoading(formId) {
      const form = this.forms.get(formId);
      if (!form) return;

      const submitBtn = form.element.querySelector('[type="submit"]');
      const loadingDiv = form.element.querySelector('.loading');

      if (submitBtn) submitBtn.disabled = false;
      if (loadingDiv) loadingDiv.style.display = 'none';
    }

    showSuccess(formId, message) {
      const form = this.forms.get(formId);
      if (!form) return;

      const successDiv = form.element.querySelector('.success-message');
      if (successDiv) {
        successDiv.textContent = message;
        successDiv.style.display = 'block';
        setTimeout(() => successDiv.style.display = 'none', 5000);
      }
    }

    showError(formId, message) {
      const form = this.forms.get(formId);
      if (!form) return;

      const errorDiv = form.element.querySelector('.error-message');
      if (errorDiv) {
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
      }
    }

    bindEvents() {
      document.addEventListener('submit', (e) => {
        const form = e.target;
        if (form.hasAttribute('data-mld-form') || form.closest('.mld-form')) {
          e.preventDefault();
          const formId = form.dataset.mldForm || form.id;
          if (formId && this.forms.has(formId)) {
            this.submitForm(formId);
          }
        }
      });
    }
  };

  // VirtualTourHandler Class
  window.VirtualTourHandler = class VirtualTourHandler {
    constructor() {
      this.tours = [];
      this.currentTour = null;
      this.init();
    }

    init() {
      SafeLogger.debug('VirtualTourHandler initialized');
      this.discoverTours();
      this.bindEvents();
    }

    discoverTours() {
      // Find virtual tour links and buttons
      const tourElements = document.querySelectorAll('[data-tour-url], .virtual-tour-link, .tour-button');

      tourElements.forEach((element, index) => {
        const tourData = {
          id: element.dataset.tourId || `tour-${index}`,
          url: element.dataset.tourUrl || element.href,
          title: element.dataset.tourTitle || element.textContent || 'Virtual Tour',
          type: this.detectTourType(element.dataset.tourUrl || element.href),
          element: element
        };

        this.tours.push(tourData);
        SafeLogger.debug(`Virtual tour discovered: ${tourData.title}`);
      });
    }

    detectTourType(url) {
      if (!url) return 'unknown';

      if (url.includes('matterport')) return 'matterport';
      if (url.includes('zillow')) return 'zillow';
      if (url.includes('youtube')) return 'youtube';
      if (url.includes('vimeo')) return 'vimeo';

      return 'iframe';
    }

    openTour(tourId) {
      const tour = this.tours.find(t => t.id === tourId);
      if (!tour) {
        SafeLogger.warning(`Tour not found: ${tourId}`);
        return false;
      }

      this.currentTour = tour;

      // Open tour based on type
      switch (tour.type) {
        case 'matterport':
        case 'iframe':
          this.openInModal(tour);
          break;
        case 'youtube':
        case 'vimeo':
          this.openVideoTour(tour);
          break;
        default:
          this.openInNewWindow(tour);
      }

      SafeLogger.debug(`Virtual tour opened: ${tour.title}`);
      return true;
    }

    openInModal(tour) {
      // Create or get tour modal
      let modal = document.getElementById('virtualTourModal');
      if (!modal) {
        modal = this.createTourModal();
      }

      const iframe = modal.querySelector('iframe');
      if (iframe) {
        iframe.src = tour.url;
      }

      // Use ModalHandler if available
      if (window.ModalHandler && window.modalHandler) {
        window.modalHandler.openModal('virtualTourModal');
      } else {
        modal.style.display = 'flex';
        modal.classList.add('active');
      }
    }

    openVideoTour(tour) {
      // Handle YouTube/Vimeo tours similar to other video modals
      this.openInModal(tour);
    }

    openInNewWindow(tour) {
      window.open(tour.url, '_blank', 'width=1200,height=800,scrollbars=yes,resizable=yes');
    }

    createTourModal() {
      const modal = document.createElement('div');
      modal.id = 'virtualTourModal';
      modal.className = 'modal virtual-tour-modal';
      modal.innerHTML = `
        <div class="modal-overlay"></div>
        <div class="modal-content">
          <button class="modal-close" data-close-modal="virtualTourModal">&times;</button>
          <iframe src="" frameborder="0" allowfullscreen></iframe>
        </div>
      `;

      document.body.appendChild(modal);

      // Register with ModalHandler if available
      if (window.ModalHandler && window.modalHandler) {
        window.modalHandler.registerModal('virtualTourModal', modal);
      }

      return modal;
    }

    bindEvents() {
      document.addEventListener('click', (e) => {
        const tourBtn = e.target.closest('[data-tour-url], .virtual-tour-link, .tour-button');
        if (tourBtn) {
          e.preventDefault();

          const tourId = tourBtn.dataset.tourId ||
                        this.tours.find(t => t.element === tourBtn)?.id;

          if (tourId) {
            this.openTour(tourId);
          }
        }
      });
    }
  };

  SafeLogger.debug('Mobile core classes loaded successfully');
})();