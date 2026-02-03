/**
 * MLD Map Gallery Module
 * Touch-responsive image slider for property photos
 *
 * Version 4.2.0 Improvements:
 * - Integrated YouTube video support in mobile gallery
 * - Added fullscreen video playback
 * - Smooth scrolling in gallery thumbnails
 * - Fixed image ordering and photo counter
 * - Support for multiple virtual tours (Matterport 3D + YouTube)
 *
 * Primary Features:
 * - Responsive touch-based navigation
 * - Preloading of adjacent images
 * - Dynamic media handling
 * - Error handling for media loading
 */
const MLD_Gallery = {
  // State management
  isOpen: false,
  currentIndex: 0,
  photos: [],
  listingId: null,
  mediaCache: {},
  touchStartX: 0,
  touchStartY: 0,

  // Initialize the gallery module
  init() {
    this.createGalleryHTML();
    this.bindEvents();
  },

  // Create the gallery HTML structure
  createGalleryHTML() {
    const galleryHTML = `
            <div id="mld-gallery-overlay" class="mld-gallery-overlay" style="display: none;">
                <div class="mld-gallery-container">
                    <button class="mld-gallery-close" aria-label="Close gallery">&times;</button>
                    <div class="mld-gallery-main">
                        <div class="mld-gallery-loading">
                            <div class="mld-gallery-spinner"></div>
                        </div>
                        <img class="mld-gallery-image" alt="Property photo">
                        <button class="mld-gallery-prev" aria-label="Previous photo">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/>
                            </svg>
                        </button>
                        <button class="mld-gallery-next" aria-label="Next photo">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/>
                            </svg>
                        </button>
                    </div>
                    <div class="mld-gallery-counter">
                        <span class="mld-gallery-current">1</span> / <span class="mld-gallery-total">1</span>
                    </div>
                    <div class="mld-gallery-thumbnails"></div>
                </div>
            </div>
        `;
    document.body.insertAdjacentHTML('beforeend', galleryHTML);

    // Cache DOM elements
    this.overlay = document.getElementById('mld-gallery-overlay');
    this.image = this.overlay.querySelector('.mld-gallery-image');
    this.loading = this.overlay.querySelector('.mld-gallery-loading');
    this.prevBtn = this.overlay.querySelector('.mld-gallery-prev');
    this.nextBtn = this.overlay.querySelector('.mld-gallery-next');
    this.closeBtn = this.overlay.querySelector('.mld-gallery-close');
    this.counter = this.overlay.querySelector('.mld-gallery-counter');
    this.thumbnails = this.overlay.querySelector('.mld-gallery-thumbnails');
  },

  // Bind event listeners
  bindEvents() {
    // Navigation buttons
    this.prevBtn.addEventListener('click', () => this.navigate(-1));
    this.nextBtn.addEventListener('click', () => this.navigate(1));
    this.closeBtn.addEventListener('click', () => this.close());

    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
      if (!this.isOpen) return;
      switch (e.key) {
        case 'ArrowLeft':
          this.navigate(-1);
          break;
        case 'ArrowRight':
          this.navigate(1);
          break;
        case 'Escape':
          this.close();
          break;
      }
    });

    // Touch gestures
    let touchStartX = 0;
    let touchStartY = 0;
    let touchEndX = 0;
    let touchEndY = 0;

    this.overlay.addEventListener(
      'touchstart',
      (e) => {
        touchStartX = e.changedTouches[0].screenX;
        touchStartY = e.changedTouches[0].screenY;
      },
      { passive: true }
    );

    this.overlay.addEventListener(
      'touchend',
      (e) => {
        touchEndX = e.changedTouches[0].screenX;
        touchEndY = e.changedTouches[0].screenY;
        this.handleSwipe(touchStartX, touchStartY, touchEndX, touchEndY);
      },
      { passive: true }
    );

    // Click outside to close
    this.overlay.addEventListener('click', (e) => {
      if (e.target === this.overlay) this.close();
    });

    // Prevent image drag
    this.image.addEventListener('dragstart', (e) => e.preventDefault());
  },

  // Handle swipe gestures
  handleSwipe(startX, startY, endX, endY) {
    const diffX = startX - endX;
    const diffY = startY - endY;
    const threshold = 50;

    // Only handle horizontal swipes
    if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > threshold) {
      if (diffX > 0) {
        this.navigate(1); // Swipe left = next
      } else {
        this.navigate(-1); // Swipe right = previous
      }
    }
  },

  // Open gallery for a specific listing
  async open(listingId) {
    this.listingId = listingId;
    this.currentIndex = 0;
    this.isOpen = true;

    // Show overlay with loading state
    this.overlay.style.display = 'flex';
    this.loading.style.display = 'flex';
    this.image.style.display = 'none';
    document.body.style.overflow = 'hidden';

    // Check cache first
    if (this.mediaCache[listingId]) {
      this.photos = this.mediaCache[listingId];
      this.displayCurrentPhoto();
    } else {
      // Fetch photos
      try {
        const photos = await this.fetchPhotos(listingId);
        if (photos && photos.length > 0) {
          this.photos = photos;
          this.mediaCache[listingId] = photos;
          this.displayCurrentPhoto();
        } else {
          this.showError('No photos available for this property');
        }
      } catch (error) {
        MLDLogger.error('Failed to fetch photos:', error);
        this.showError('Failed to load photos');
      }
    }
  },

  // Fetch photos for a listing
  fetchPhotos(listingId) {
    return new Promise((resolve, reject) => {
      jQuery
        .post(bmeMapData.ajax_url, {
          action: 'get_listing_details',
          security: bmeMapData.security,
          listing_id: listingId,
        })
        .done((response) => {
          if (response.success && response.data && response.data.Media) {
            const photos = response.data.Media.filter(
              (media) => media.MediaCategory === 'Photo' || media.MediaCategory === 'image'
            );
            resolve(photos);
          } else {
            reject('No media found');
          }
        })
        .fail(() => reject('AJAX request failed'));
    });
  },

  // Display current photo
  displayCurrentPhoto() {
    if (!this.photos || this.photos.length === 0) return;

    const photo = this.photos[this.currentIndex];
    this.loading.style.display = 'flex';

    // Preload image
    const img = new Image();
    img.onload = () => {
      this.image.src = photo.MediaURL;
      this.image.alt = photo.ShortDescription || 'Property photo';
      this.image.style.display = 'block';
      this.loading.style.display = 'none';

      // Update UI
      this.updateCounter();
      this.updateNavigation();
      this.updateThumbnails();

      // Preload adjacent images
      this.preloadAdjacent();
    };
    img.onerror = () => {
      this.showError('Failed to load image');
    };
    img.src = photo.MediaURL;
  },

  // Navigate to next/previous photo
  navigate(direction) {
    const newIndex = this.currentIndex + direction;
    if (newIndex >= 0 && newIndex < this.photos.length) {
      this.currentIndex = newIndex;
      this.displayCurrentPhoto();
    }
  },

  // Update counter display
  updateCounter() {
    this.counter.querySelector('.mld-gallery-current').textContent = this.currentIndex + 1;
    this.counter.querySelector('.mld-gallery-total').textContent = this.photos.length;
  },

  // Update navigation buttons
  updateNavigation() {
    this.prevBtn.disabled = this.currentIndex === 0;
    this.nextBtn.disabled = this.currentIndex === this.photos.length - 1;

    // Hide navigation for single photo
    if (this.photos.length <= 1) {
      this.prevBtn.style.display = 'none';
      this.nextBtn.style.display = 'none';
    } else {
      this.prevBtn.style.display = 'flex';
      this.nextBtn.style.display = 'flex';
    }
  },

  // Update thumbnail strip
  updateThumbnails() {
    if (this.photos.length <= 1) {
      this.thumbnails.style.display = 'none';
      return;
    }

    this.thumbnails.style.display = 'flex';
    this.thumbnails.innerHTML = '';

    this.photos.forEach((photo, index) => {
      const thumb = document.createElement('button');
      thumb.className = 'mld-gallery-thumb' + (index === this.currentIndex ? ' active' : '');
      thumb.setAttribute('aria-label', `Go to photo ${index + 1}`);
      thumb.style.backgroundImage = `url(${photo.MediaURL})`;
      thumb.addEventListener('click', () => {
        this.currentIndex = index;
        this.displayCurrentPhoto();
      });
      this.thumbnails.appendChild(thumb);
    });

    // Scroll active thumbnail into view
    const activeThumb = this.thumbnails.children[this.currentIndex];
    if (activeThumb) {
      activeThumb.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    }
  },

  // Preload adjacent images for smooth navigation
  preloadAdjacent() {
    const preloadIndexes = [this.currentIndex - 1, this.currentIndex + 1].filter(
      (i) => i >= 0 && i < this.photos.length
    );

    preloadIndexes.forEach((index) => {
      const img = new Image();
      img.src = this.photos[index].MediaURL;
    });
  },

  // Show error message
  showError(message) {
    this.loading.style.display = 'none';
    this.image.style.display = 'none';
    this.counter.innerHTML = `<span class="mld-gallery-error">${message}</span>`;
  },

  // Close gallery
  close() {
    this.isOpen = false;
    this.overlay.style.display = 'none';
    document.body.style.overflow = '';
    this.photos = [];
    this.currentIndex = 0;
  },
};

// Initialize when DOM is ready
// Expose globally
window.MLD_Gallery = MLD_Gallery;

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => MLD_Gallery.init());
} else {
  MLD_Gallery.init();
}
