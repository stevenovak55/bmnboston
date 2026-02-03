/**
 * MLD Gallery Module V2
 * Enhanced photo gallery with lightbox, zoom, and swipe gestures
 * Works for both mobile and desktop
 *
 * @version 2.0.0
 */

class MLDGallery {
  constructor(options = {}) {
    this.options = {
      container: options.container || '#mld-gallery',
      photos: options.photos || [],
      videos: options.videos || [],
      virtualTours: options.virtualTours || [],
      enableLightbox: options.enableLightbox !== false,
      enableZoom: options.enableZoom !== false,
      enableSwipe: options.enableSwipe !== false,
      enableKeyboard: options.enableKeyboard !== false,
      enableThumbnails: options.enableThumbnails !== false,
      lazyLoad: options.lazyLoad !== false,
      onPhotoChange: options.onPhotoChange || null,
      onLightboxOpen: options.onLightboxOpen || null,
      onLightboxClose: options.onLightboxClose || null,
    };

    this.state = {
      currentIndex: 0,
      isLightboxOpen: false,
      isZoomed: false,
      zoomLevel: 1,
      isDragging: false,
      startX: 0,
      startY: 0,
      translateX: 0,
      translateY: 0,
    };

    this.elements = {};
    this.touchStartX = 0;
    this.touchStartY = 0;
    this.init();
  }

  init() {
    this.setupContainer();
    this.createLightbox();
    this.bindEvents();
    this.loadInitialImages();
  }

  setupContainer() {
    const container = document.querySelector(this.options.container);
    if (!container) {
      MLDLogger.error('Gallery container not found');
      return;
    }
    this.elements.container = container;
  }

  createLightbox() {
    if (!this.options.enableLightbox) return;

    const lightbox = document.createElement('div');
    lightbox.className = 'mld-lightbox-v2';
    lightbox.innerHTML = `
            <div class="mld-lightbox-header">
                <div class="mld-lightbox-counter">
                    <span class="current">1</span> / <span class="total">${this.options.photos.length}</span>
                </div>
                <button class="mld-lightbox-close" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            
            <div class="mld-lightbox-main">
                <button class="mld-lightbox-nav prev" aria-label="Previous photo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                </button>
                
                <div class="mld-lightbox-image-container">
                    <img class="mld-lightbox-image" src="" alt="">
                    <div class="mld-lightbox-loading">
                        <div class="spinner"></div>
                    </div>
                </div>
                
                <button class="mld-lightbox-nav next" aria-label="Next photo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </button>
            </div>
            
            <div class="mld-lightbox-toolbar">
                <button class="mld-lightbox-zoom-in" aria-label="Zoom in">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                        <line x1="11" y1="8" x2="11" y2="14"/>
                        <line x1="8" y1="11" x2="14" y2="11"/>
                    </svg>
                </button>
                <button class="mld-lightbox-zoom-out" aria-label="Zoom out">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                        <line x1="8" y1="11" x2="14" y2="11"/>
                    </svg>
                </button>
                <button class="mld-lightbox-reset" aria-label="Reset zoom">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M21 2v6h-6"/>
                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                        <path d="M3 22v-6h6"/>
                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                    </svg>
                </button>
                <button class="mld-lightbox-fullscreen" aria-label="Fullscreen">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                    </svg>
                </button>
            </div>
            
            ${
              this.options.enableThumbnails
                ? `
            <div class="mld-lightbox-thumbs">
                <div class="mld-lightbox-thumbs-scroll">
                    ${this.options.photos
                      .map(
                        (photo, index) => `
                        <button class="mld-lightbox-thumb ${index === 0 ? 'active' : ''}" 
                                data-index="${index}" 
                                aria-label="View photo ${index + 1}">
                            <img src="${photo.MediaURL || photo}" alt="" loading="lazy">
                        </button>
                    `
                      )
                      .join('')}
                </div>
            </div>
            `
                : ''
            }
        `;

    document.body.appendChild(lightbox);
    this.elements.lightbox = lightbox;
    this.cacheLightboxElements();
  }

  cacheLightboxElements() {
    const lb = this.elements.lightbox;
    this.elements.lightboxClose = lb.querySelector('.mld-lightbox-close');
    this.elements.lightboxPrev = lb.querySelector('.mld-lightbox-nav.prev');
    this.elements.lightboxNext = lb.querySelector('.mld-lightbox-nav.next');
    this.elements.lightboxImage = lb.querySelector('.mld-lightbox-image');
    this.elements.lightboxImageContainer = lb.querySelector('.mld-lightbox-image-container');
    this.elements.lightboxCounter = lb.querySelector('.mld-lightbox-counter .current');
    this.elements.lightboxLoading = lb.querySelector('.mld-lightbox-loading');
    this.elements.lightboxThumbs = lb.querySelectorAll('.mld-lightbox-thumb');
    this.elements.zoomIn = lb.querySelector('.mld-lightbox-zoom-in');
    this.elements.zoomOut = lb.querySelector('.mld-lightbox-zoom-out');
    this.elements.zoomReset = lb.querySelector('.mld-lightbox-reset');
    this.elements.fullscreen = lb.querySelector('.mld-lightbox-fullscreen');
  }

  bindEvents() {
    // Gallery click events
    if (this.elements.container) {
      this.elements.container.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-gallery-trigger]');
        if (trigger) {
          const index = parseInt(trigger.dataset.index || 0);
          this.openLightbox(index);
        }
      });
    }

    // Lightbox events
    if (this.options.enableLightbox) {
      this.bindLightboxEvents();
    }

    // Keyboard events
    if (this.options.enableKeyboard) {
      this.bindKeyboardEvents();
    }

    // Touch events
    if (this.options.enableSwipe) {
      this.bindSwipeEvents();
    }
  }

  bindLightboxEvents() {
    // Close button
    this.elements.lightboxClose?.addEventListener('click', () => this.closeLightbox());

    // Navigation
    this.elements.lightboxPrev?.addEventListener('click', () => this.previousPhoto());
    this.elements.lightboxNext?.addEventListener('click', () => this.nextPhoto());

    // Thumbnails
    this.elements.lightboxThumbs?.forEach((thumb) => {
      thumb.addEventListener('click', () => {
        const index = parseInt(thumb.dataset.index);
        this.goToPhoto(index);
      });
    });

    // Zoom controls
    this.elements.zoomIn?.addEventListener('click', () => this.zoomIn());
    this.elements.zoomOut?.addEventListener('click', () => this.zoomOut());
    this.elements.zoomReset?.addEventListener('click', () => this.resetZoom());
    this.elements.fullscreen?.addEventListener('click', () => this.toggleFullscreen());

    // Click outside to close
    this.elements.lightbox?.addEventListener('click', (e) => {
      if (e.target === this.elements.lightbox || e.target.classList.contains('mld-lightbox-main')) {
        this.closeLightbox();
      }
    });

    // Double click/tap to zoom
    this.elements.lightboxImageContainer?.addEventListener('dblclick', (e) => {
      this.handleDoubleClick(e);
    });
  }

  bindKeyboardEvents() {
    document.addEventListener('keydown', (e) => {
      if (!this.state.isLightboxOpen) return;

      switch (e.key) {
        case 'Escape':
          this.closeLightbox();
          break;
        case 'ArrowLeft':
          this.previousPhoto();
          break;
        case 'ArrowRight':
          this.nextPhoto();
          break;
        case '+':
        case '=':
          this.zoomIn();
          break;
        case '-':
        case '_':
          this.zoomOut();
          break;
        case '0':
          this.resetZoom();
          break;
      }
    });
  }

  bindSwipeEvents() {
    let touchStartX = 0;
    let touchEndX = 0;
    const threshold = 50;

    const handleTouchStart = (e) => {
      touchStartX = e.touches[0].clientX;
    };

    const handleTouchEnd = (e) => {
      touchEndX = e.changedTouches[0].clientX;
      const diff = touchStartX - touchEndX;

      if (Math.abs(diff) > threshold) {
        if (diff > 0) {
          this.nextPhoto();
        } else {
          this.previousPhoto();
        }
      }
    };

    if (this.elements.lightboxImageContainer) {
      this.elements.lightboxImageContainer.addEventListener('touchstart', handleTouchStart, {
        passive: true,
      });
      this.elements.lightboxImageContainer.addEventListener('touchend', handleTouchEnd, {
        passive: true,
      });
    }

    // Pinch to zoom
    this.setupPinchZoom();
  }

  setupPinchZoom() {
    let initialDistance = 0;
    let currentScale = 1;

    const getDistance = (touches) => {
      const dx = touches[0].clientX - touches[1].clientX;
      const dy = touches[0].clientY - touches[1].clientY;
      return Math.sqrt(dx * dx + dy * dy);
    };

    this.elements.lightboxImageContainer?.addEventListener(
      'touchstart',
      (e) => {
        if (e.touches.length === 2) {
          initialDistance = getDistance(e.touches);
          currentScale = this.state.zoomLevel;
        }
      },
      { passive: true }
    );

    this.elements.lightboxImageContainer?.addEventListener(
      'touchmove',
      (e) => {
        if (e.touches.length === 2 && initialDistance > 0) {
          const currentDistance = getDistance(e.touches);
          const scale = (currentDistance / initialDistance) * currentScale;
          this.setZoom(scale);
        }
      },
      { passive: true }
    );

    this.elements.lightboxImageContainer?.addEventListener(
      'touchend',
      () => {
        initialDistance = 0;
      },
      { passive: true }
    );
  }

  loadInitialImages() {
    if (!this.options.lazyLoad) return;

    // Load first 3 images
    const images = this.elements.container?.querySelectorAll('img[data-src]');
    if (!images) return;

    Array.from(images)
      .slice(0, 3)
      .forEach((img) => {
        img.src = img.dataset.src;
        img.removeAttribute('data-src');
      });

    // Setup intersection observer for rest
    this.setupLazyLoading();
  }

  setupLazyLoading() {
    const images = this.elements.container?.querySelectorAll('img[data-src]');
    if (!images || !('IntersectionObserver' in window)) return;

    const imageObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const img = entry.target;
            img.src = img.dataset.src;
            img.removeAttribute('data-src');
            imageObserver.unobserve(img);
          }
        });
      },
      {
        rootMargin: '100px',
      }
    );

    images.forEach((img) => imageObserver.observe(img));
  }

  openLightbox(index = 0) {
    if (!this.elements.lightbox) return;

    // Track starting index for calculating photos viewed
    this.state.lightboxStartIndex = index;
    this.state.photosViewedInLightbox = new Set([index]);

    this.state.isLightboxOpen = true;
    this.state.currentIndex = index;
    this.elements.lightbox.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Analytics: Track lightbox open (v6.38.0)
    document.dispatchEvent(new CustomEvent('mld:photo_lightbox_open', {
      detail: {
        listingId: window.mldPropertyDataV3?.listing_id || null,
        photoIndex: index
      }
    }));

    this.updateLightboxImage();

    if (this.options.onLightboxOpen) {
      this.options.onLightboxOpen(index);
    }
  }

  closeLightbox() {
    if (!this.elements.lightbox) return;

    // Analytics: Track lightbox close with photos viewed count (v6.38.0)
    const photosViewedCount = this.state.photosViewedInLightbox ? this.state.photosViewedInLightbox.size : 1;
    document.dispatchEvent(new CustomEvent('mld:photo_lightbox_close', {
      detail: {
        listingId: window.mldPropertyDataV3?.listing_id || null,
        photosViewedCount: photosViewedCount
      }
    }));

    this.state.isLightboxOpen = false;
    this.elements.lightbox.classList.remove('active');
    document.body.style.overflow = '';
    this.resetZoom();

    if (this.options.onLightboxClose) {
      this.options.onLightboxClose();
    }
  }

  nextPhoto() {
    const newIndex = (this.state.currentIndex + 1) % this.options.photos.length;
    this.goToPhoto(newIndex);
  }

  previousPhoto() {
    const newIndex =
      (this.state.currentIndex - 1 + this.options.photos.length) % this.options.photos.length;
    this.goToPhoto(newIndex);
  }

  goToPhoto(index) {
    if (index < 0 || index >= this.options.photos.length) return;

    this.state.currentIndex = index;

    // Track photos viewed in lightbox for analytics
    if (this.state.photosViewedInLightbox) {
      this.state.photosViewedInLightbox.add(index);
    }

    this.updateLightboxImage();
    this.resetZoom();

    if (this.options.onPhotoChange) {
      this.options.onPhotoChange(index);
    }
  }

  updateLightboxImage() {
    const photo = this.options.photos[this.state.currentIndex];
    const imageUrl = photo.MediaURL || photo;

    // Show loading
    this.elements.lightboxLoading.style.display = 'flex';

    // Preload image
    const img = new Image();
    img.onload = () => {
      this.elements.lightboxImage.src = imageUrl;
      this.elements.lightboxLoading.style.display = 'none';
    };
    img.onerror = () => {
      this.elements.lightboxImage.src = '/path/to/placeholder.jpg';
      this.elements.lightboxLoading.style.display = 'none';
    };
    img.src = imageUrl;

    // Update counter
    if (this.elements.lightboxCounter) {
      this.elements.lightboxCounter.textContent = this.state.currentIndex + 1;
    }

    // Update thumbnails
    this.elements.lightboxThumbs?.forEach((thumb, index) => {
      thumb.classList.toggle('active', index === this.state.currentIndex);
    });

    // Scroll active thumbnail into view
    const activeThumb = this.elements.lightboxThumbs?.[this.state.currentIndex];
    activeThumb?.scrollIntoView({ behavior: 'smooth', inline: 'center' });
  }

  zoomIn() {
    const newZoom = Math.min(this.state.zoomLevel * 1.2, 3);
    this.setZoom(newZoom);
  }

  zoomOut() {
    const newZoom = Math.max(this.state.zoomLevel / 1.2, 0.5);
    this.setZoom(newZoom);
  }

  resetZoom() {
    this.setZoom(1);
    this.state.translateX = 0;
    this.state.translateY = 0;
    this.updateImageTransform();
  }

  setZoom(level) {
    this.state.zoomLevel = Math.max(0.5, Math.min(3, level));
    this.state.isZoomed = this.state.zoomLevel !== 1;
    this.updateImageTransform();
  }

  updateImageTransform() {
    if (!this.elements.lightboxImage) return;

    const transform = `scale(${this.state.zoomLevel}) translate(${this.state.translateX}px, ${this.state.translateY}px)`;
    this.elements.lightboxImage.style.transform = transform;
  }

  handleDoubleClick(e) {
    if (this.state.isZoomed) {
      this.resetZoom();
    } else {
      // Zoom to click point
      const rect = this.elements.lightboxImageContainer.getBoundingClientRect();
      const x = e.clientX - rect.left - rect.width / 2;
      const y = e.clientY - rect.top - rect.height / 2;

      this.state.translateX = -x;
      this.state.translateY = -y;
      this.setZoom(2);
    }
  }

  toggleFullscreen() {
    if (!document.fullscreenElement) {
      this.elements.lightbox.requestFullscreen().catch((err) => {
        MLDLogger.error('Fullscreen error:', err);
      });
    } else {
      document.exitFullscreen();
    }
  }

  destroy() {
    // Remove event listeners
    this.elements.lightbox?.remove();
    this.elements = {};
    this.state = {};
  }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = MLDGallery;
} else {
  window.MLDGallery = MLDGallery;
}
