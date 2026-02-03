/**
 * MLD Mobile Enhancements
 * Improves mobile user experience with touch gestures and optimized interactions
 *
 * @version 1.0.0
 */

class MLDMobileEnhancements {
  constructor() {
    this.isMobile = window.innerWidth <= 768;
    this.touchStartY = 0;
    this.touchEndY = 0;
    this.isScrolling = false;

    if (this.isMobile) {
      this.init();
    }

    // Re-initialize on window resize
    window.addEventListener('resize', () => {
      const wasMobile = this.isMobile;
      this.isMobile = window.innerWidth <= 768;

      if (!wasMobile && this.isMobile) {
        this.init();
      } else if (wasMobile && !this.isMobile) {
        this.cleanup();
      }
    });
  }

  init() {
    this.addSwipeGestures();
    this.addTouchOptimizations();
    this.addMobileFriendlyControls();
    this.addPullToRefresh();
    this.improveModalInteractions();
  }

  cleanup() {
    // Remove mobile-specific event listeners and modifications
    document.removeEventListener('touchstart', this.handleTouchStart);
    document.removeEventListener('touchend', this.handleTouchEnd);
    document.removeEventListener('touchmove', this.handleTouchMove);
  }

  addSwipeGestures() {
    let startX = 0;
    let startY = 0;
    let diffX = 0;
    let diffY = 0;

    document.addEventListener(
      'touchstart',
      (e) => {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
      },
      { passive: true }
    );

    document.addEventListener(
      'touchmove',
      (e) => {
        if (!startX || !startY) return;

        diffX = startX - e.touches[0].clientX;
        diffY = startY - e.touches[0].clientY;
      },
      { passive: true }
    );

    document.addEventListener(
      'touchend',
      (e) => {
        if (!startX || !startY) return;

        // Horizontal swipe
        if (Math.abs(diffX) > Math.abs(diffY)) {
          if (Math.abs(diffX) > 50) {
            if (diffX > 0) {
              this.handleSwipeLeft();
            } else {
              this.handleSwipeRight();
            }
          }
        }

        // Vertical swipe
        else if (Math.abs(diffY) > 50) {
          if (diffY > 0) {
            this.handleSwipeUp();
          } else {
            this.handleSwipeDown();
          }
        }

        // Reset values
        startX = 0;
        startY = 0;
        diffX = 0;
        diffY = 0;
      },
      { passive: true }
    );
  }

  handleSwipeLeft() {
    // Close side panels or go to next image in gallery
    // Future functionality can be added here
  }

  handleSwipeRight() {
    // Future functionality can be added here
  }

  handleSwipeUp() {
    // Could be used to show more details or expand listings
    this.collapseExpandedElements();
  }

  handleSwipeDown() {
    // Pull to refresh functionality
    if (window.pageYOffset === 0) {
      this.triggerRefresh();
    }
  }

  addTouchOptimizations() {
    // Improve touch targets
    const style = document.createElement('style');
    style.textContent = `
            @media (max-width: 768px) {
                /* Larger touch targets */
                button,
                .clickable {
                    min-height: 44px !important;
                    min-width: 44px !important;
                    padding: 12px !important;
                }
                
                /* Better spacing for touch */
                .filter-options {
                    gap: 10px !important;
                }
                
                /* Smoother animations */
                * {
                    -webkit-tap-highlight-color: rgba(0,0,0,0.1);
                    -webkit-touch-callout: none;
                }
                
                /* Improved modal sizing */
                .modal {
                    padding: 5px !important;
                }
                
                .modal-content {
                    margin-top: 5px !important;
                    max-height: 95vh !important;
                    border-radius: 12px 12px 0 0 !important;
                }
            }
        `;
    document.head.appendChild(style);
  }

  addMobileFriendlyControls() {
    // Add mobile-specific UI elements
    this.addMobileActionBar();
    this.addScrollToTop();
    this.improveMobileFilters();
  }

  addMobileActionBar() {
    // Check if action bar already exists
    if (document.querySelector('.mld-mobile-action-bar')) return;

    const actionBar = document.createElement('div');
    actionBar.className = 'mld-mobile-action-bar';
    actionBar.innerHTML = `
            <button class="mobile-action-btn scroll-to-top" title="Scroll to top">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="18,15 12,9 6,15"></polyline>
                </svg>
            </button>
            <button class="mobile-action-btn toggle-view" title="Toggle view">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"></rect>
                    <rect x="14" y="3" width="7" height="7"></rect>
                    <rect x="14" y="14" width="7" height="7"></rect>
                    <rect x="3" y="14" width="7" height="7"></rect>
                </svg>
            </button>
            <button class="mobile-action-btn refresh-listings" title="Refresh">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="1,4 1,10 7,10"></polyline>
                    <polyline points="23,20 23,14 17,14"></polyline>
                    <path d="m20.49,9A9,9 0 0,0 5.64,5.64L1,10m22,4l-4.64,4.36A9,9 0 0,1 3.51,15"></path>
                </svg>
            </button>
        `;

    actionBar.style.cssText = `
            position: fixed;
            bottom: 80px;
            right: 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1001;
            opacity: 0;
            transform: translateX(60px);
            transition: all 0.3s ease;
        `;

    document.body.appendChild(actionBar);

    // Show action bar after scroll
    let showTimeout;
    window.addEventListener('scroll', () => {
      clearTimeout(showTimeout);
      actionBar.style.opacity = '1';
      actionBar.style.transform = 'translateX(0)';

      showTimeout = setTimeout(() => {
        if (window.pageYOffset < 100) {
          actionBar.style.opacity = '0';
          actionBar.style.transform = 'translateX(60px)';
        }
      }, 2000);
    });

    // Add click handlers
    actionBar.querySelector('.scroll-to-top').addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    actionBar.querySelector('.refresh-listings').addEventListener('click', () => {
      this.triggerRefresh();
    });

    actionBar.querySelector('.toggle-view').addEventListener('click', () => {
      this.toggleListView();
    });

    // Style the action buttons
    const actionButtons = actionBar.querySelectorAll('.mobile-action-btn');
    actionButtons.forEach((btn) => {
      btn.style.cssText = `
                background: rgba(255, 255, 255, 0.95);
                border: none;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 2px 10px rgba(0,0,0,0.15);
                cursor: pointer;
                color: #333;
                transition: all 0.2s ease;
            `;

      btn.addEventListener('touchstart', () => {
        btn.style.transform = 'scale(0.95)';
      });

      btn.addEventListener('touchend', () => {
        btn.style.transform = 'scale(1)';
      });
    });
  }

  addScrollToTop() {
    // Already handled in addMobileActionBar
  }

  addPullToRefresh() {
    let startY = 0;
    let pullDistance = 0;
    let isPulling = false;
    const refreshThreshold = 80;

    // Create pull to refresh indicator
    const pullIndicator = document.createElement('div');
    pullIndicator.className = 'mld-pull-indicator';
    pullIndicator.innerHTML = `
            <div class="pull-content">
                <div class="pull-icon">↓</div>
                <div class="pull-text">Pull to refresh</div>
            </div>
        `;

    pullIndicator.style.cssText = `
            position: fixed;
            top: -80px;
            left: 0;
            right: 0;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1002;
            transition: transform 0.3s ease;
            font-size: 14px;
            font-weight: 500;
        `;

    document.body.appendChild(pullIndicator);

    document.addEventListener(
      'touchstart',
      (e) => {
        if (window.pageYOffset === 0) {
          startY = e.touches[0].clientY;
          isPulling = true;
        }
      },
      { passive: true }
    );

    document.addEventListener('touchmove', (e) => {
      if (!isPulling) return;

      pullDistance = e.touches[0].clientY - startY;

      if (pullDistance > 0 && window.pageYOffset === 0) {
        e.preventDefault();
        const progress = Math.min(pullDistance / refreshThreshold, 1);
        pullIndicator.style.transform = `translateY(${pullDistance}px)`;

        if (pullDistance > refreshThreshold) {
          pullIndicator.querySelector('.pull-text').textContent = 'Release to refresh';
          pullIndicator.querySelector('.pull-icon').style.transform = 'rotate(180deg)';
        } else {
          pullIndicator.querySelector('.pull-text').textContent = 'Pull to refresh';
          pullIndicator.querySelector('.pull-icon').style.transform = 'rotate(0deg)';
        }
      }
    });

    document.addEventListener('touchend', () => {
      if (isPulling && pullDistance > refreshThreshold) {
        this.triggerRefresh();
      }

      isPulling = false;
      pullDistance = 0;
      pullIndicator.style.transform = 'translateY(-80px)';
      pullIndicator.querySelector('.pull-text').textContent = 'Pull to refresh';
      pullIndicator.querySelector('.pull-icon').style.transform = 'rotate(0deg)';
    });
  }

  improveModalInteractions() {
    // Make modals more touch-friendly
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === Node.ELEMENT_NODE && node.classList?.contains('modal')) {
            this.optimizeModalForTouch(node);
          }
        });
      });
    });

    observer.observe(document.body, { childList: true });
  }

  optimizeModalForTouch(modal) {
    const content = modal.querySelector('.modal-content');
    if (!content) return;

    // Add touch scrolling improvements
    content.style.cssText += `
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
        `;

    // Add swipe to close
    let startY = 0;
    let deltaY = 0;

    content.addEventListener(
      'touchstart',
      (e) => {
        startY = e.touches[0].clientY;
      },
      { passive: true }
    );

    content.addEventListener(
      'touchmove',
      (e) => {
        deltaY = e.touches[0].clientY - startY;
      },
      { passive: true }
    );

    content.addEventListener('touchend', () => {
      if (deltaY > 100 && content.scrollTop === 0) {
        // Swipe down to close
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) closeBtn.click();
      }
      deltaY = 0;
    });
  }

  improveMobileFilters() {
    // Make filter controls more touch-friendly
    const style = document.createElement('style');
    style.textContent = `
            @media (max-width: 768px) {
                .filter-section {
                    padding: 15px !important;
                }
                
                .filter-option {
                    padding: 12px 16px !important;
                    font-size: 16px !important;
                    margin: 5px 0 !important;
                }
                
                input[type="range"] {
                    height: 44px !important;
                    -webkit-appearance: none !important;
                    background: #ddd !important;
                    border-radius: 22px !important;
                }
                
                input[type="range"]::-webkit-slider-thumb {
                    -webkit-appearance: none !important;
                    width: 44px !important;
                    height: 44px !important;
                    border-radius: 50% !important;
                    background: #2271b1 !important;
                    cursor: pointer !important;
                }
            }
        `;
    document.head.appendChild(style);
  }

  collapseExpandedElements() {
    // Close any expanded dropdowns or panels
    document.querySelectorAll('.expanded, .open, .active').forEach((el) => {
      el.classList.remove('expanded', 'open', 'active');
    });
  }

  triggerRefresh() {
    // Trigger a refresh of the listings
    if (window.MLD_API && window.MLD_API.fetchAllListingsInBatches) {
      this.showRefreshIndicator();
      window.MLD_API.fetchAllListingsInBatches(1);

      setTimeout(() => {
        this.hideRefreshIndicator();
      }, 2000);
    }
  }

  showRefreshIndicator() {
    let indicator = document.querySelector('.refresh-indicator');
    if (!indicator) {
      indicator = document.createElement('div');
      indicator.className = 'refresh-indicator';
      indicator.innerHTML = 'Refreshing listings...';
      indicator.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0, 0, 0, 0.8);
                color: white;
                padding: 10px 20px;
                border-radius: 20px;
                font-size: 14px;
                z-index: 10000;
                opacity: 0;
                transition: opacity 0.3s ease;
            `;
      document.body.appendChild(indicator);
    }

    setTimeout(() => {
      indicator.style.opacity = '1';
    }, 10);
  }

  hideRefreshIndicator() {
    const indicator = document.querySelector('.refresh-indicator');
    if (indicator) {
      indicator.style.opacity = '0';
      setTimeout(() => {
        indicator.remove();
      }, 300);
    }
  }

  toggleListView() {
    // Toggle between list and grid view if available
    const listContainer = document.querySelector('.listings-container, .mld-listings-grid');
    if (listContainer) {
      listContainer.classList.toggle('list-view');
      listContainer.classList.toggle('grid-view');
    }
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  window.mldMobileEnhancements = new MLDMobileEnhancements();
});

// Also initialize if script loads after DOM ready
if (document.readyState !== 'loading') {
  window.mldMobileEnhancements = new MLDMobileEnhancements();
}
