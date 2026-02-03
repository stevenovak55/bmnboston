/**
 * Direct Mobile Fix - Immediate DOM Manipulation
 * Forces correct behavior regardless of other scripts
 * Version: 3.0.0
 */

(function() {
    'use strict';


    // Fix 1: Force Images to Display
    function forceImageDisplay() {

        // Get all images in the gallery
        const images = document.querySelectorAll('.mld-photo-item img, .mld-photo img, img[data-src]');

        images.forEach((img, index) => {
            // If image has data-src but no src, copy it
            if (img.dataset.src && !img.src) {
                img.src = img.dataset.src;
            }

            // Force display styles
            img.style.display = 'block';
            img.style.visibility = 'visible';
            img.style.opacity = '1';
            img.style.width = '100%';
            img.style.height = 'auto';
            img.style.minHeight = '200px';
            img.style.objectFit = 'cover';

            // Remove any hiding classes
            img.classList.remove('lazy-image', 'hidden', 'd-none');

            // Handle parent container
            const parent = img.closest('.mld-photo-item');
            if (parent) {
                parent.style.display = 'block';
                parent.style.visibility = 'visible';
                parent.style.opacity = '1';
            }
        });

    }

    // Fix 2: Make Bottom Sheet Draggable
    function fixBottomSheetDragging() {

        const bottomSheet = document.getElementById('bottomSheet');
        const handle = document.querySelector('.mld-sheet-handle');

        if (!bottomSheet || !handle) {
            return;
        }

        // Force correct positioning
        bottomSheet.style.position = 'fixed';
        bottomSheet.style.bottom = '0';
        bottomSheet.style.left = '0';
        bottomSheet.style.right = '0';
        bottomSheet.style.height = '100vh';
        bottomSheet.style.transform = 'translateY(50%)';
        bottomSheet.style.transition = 'transform 0.3s ease';
        bottomSheet.style.zIndex = '9999';
        bottomSheet.style.display = 'flex';
        bottomSheet.style.flexDirection = 'column';

        // Make handle draggable
        handle.style.cursor = 'grab';
        handle.style.touchAction = 'none';
        handle.style.userSelect = 'none';

        let isDragging = false;
        let startY = 0;
        let startTransform = 50;

        // Remove all existing listeners first
        const newHandle = handle.cloneNode(true);
        handle.parentNode.replaceChild(newHandle, handle);

        // Mouse events
        newHandle.addEventListener('mousedown', (e) => {
            isDragging = true;
            startY = e.clientY;
            startTransform = getCurrentTransform();
            newHandle.style.cursor = 'grabbing';
            bottomSheet.style.transition = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;

            const deltaY = e.clientY - startY;
            const percentChange = (deltaY / window.innerHeight) * 100;
            const newTransform = Math.max(20, Math.min(80, startTransform + percentChange));

            bottomSheet.style.transform = `translateY(${newTransform}%)`;
        });

        document.addEventListener('mouseup', () => {
            if (!isDragging) return;
            isDragging = false;
            newHandle.style.cursor = 'grab';
            bottomSheet.style.transition = 'transform 0.3s ease';
            snapToPosition();
        });

        // Touch events - Commented out as mobile-ultimate-fix.js handles this
        // newHandle.addEventListener('touchstart', (e) => {
        //     isDragging = true;
        //     startY = e.touches[0].clientY;
        //     startTransform = getCurrentTransform();
        //     bottomSheet.style.transition = 'none';
        //     e.preventDefault();
        // }, { passive: false });

        // document.addEventListener('touchmove', (e) => {
        //     if (!isDragging) return;

        //     const deltaY = e.touches[0].clientY - startY;
        //     const percentChange = (deltaY / window.innerHeight) * 100;
        //     const newTransform = Math.max(20, Math.min(80, startTransform + percentChange));

        //     bottomSheet.style.transform = `translateY(${newTransform}%)`;
        // }, { passive: false });

        // document.addEventListener('touchend', () => {
        //     if (!isDragging) return;
        //     isDragging = false;
        //     bottomSheet.style.transition = 'transform 0.3s ease';
        //     snapToPosition();
        // });

        // Click to toggle
        newHandle.addEventListener('click', (e) => {
            if (e.detail > 1) return; // Ignore double clicks

            const current = getCurrentTransform();
            let target;

            if (current > 65) {
                target = 50; // Go to middle
            } else if (current > 35) {
                target = 20; // Go to top
            } else {
                target = 80; // Go to bottom
            }

            bottomSheet.style.transition = 'transform 0.3s ease';
            bottomSheet.style.transform = `translateY(${target}%)`;
        });

        function getCurrentTransform() {
            const transform = bottomSheet.style.transform;
            const match = transform.match(/translateY\(([0-9.]+)%\)/);
            return match ? parseFloat(match[1]) : 50;
        }

        function snapToPosition() {
            const current = getCurrentTransform();
            let target;

            if (current > 65) {
                target = 80; // Snap to bottom
            } else if (current < 35) {
                target = 20; // Snap to top
            } else {
                target = 50; // Snap to middle
            }

            bottomSheet.style.transform = `translateY(${target}%)`;
        }

    }

    // Fix 3: Ensure Gallery is Scrollable
    function fixGalleryScroll() {

        const gallery = document.querySelector('.mld-gallery-container');
        if (gallery) {
            gallery.style.overflowY = 'auto';
            gallery.style.overflowX = 'hidden';
            gallery.style.webkitOverflowScrolling = 'touch';
            gallery.style.height = '100vh';
            gallery.style.position = 'relative';

            // Ensure scroll content is visible
            const scrollContent = gallery.querySelector('.mld-gallery-scroll');
            if (scrollContent) {
                scrollContent.style.display = 'flex';
                scrollContent.style.flexDirection = 'column';
                scrollContent.style.minHeight = '100%';
            }

        }
    }

    // Fix 4: Remove Conflicting Styles
    function removeConflictingStyles() {

        // Find and disable any conflicting stylesheets
        const styles = document.querySelectorAll('style');
        styles.forEach(style => {
            if (style.textContent.includes('mld-photo-item') &&
                style.textContent.includes('display: none')) {
                style.disabled = true;
            }
        });

        // Override with important styles
        const fixStyle = document.createElement('style');
        fixStyle.textContent = `
            .mld-photo-item {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
            }
            .mld-photo-item img {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                width: 100% !important;
                height: auto !important;
            }
            .mld-gallery-container {
                display: block !important;
                visibility: visible !important;
            }
            .mld-gallery-scroll {
                display: flex !important;
                flex-direction: column !important;
            }
            .mld-bottom-sheet {
                display: flex !important;
                visibility: visible !important;
            }
            .mld-sheet-handle {
                cursor: grab !important;
                touch-action: none !important;
            }
        `;
        document.head.appendChild(fixStyle);

    }

    // Fix 5: Handle Lazy Loading Images
    function fixLazyImages() {

        // Find all images with data-src
        const lazyImages = document.querySelectorAll('img[data-src]');

        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            img.classList.remove('lazy-image');
                            imageObserver.unobserve(img);
                        }
                    }
                });
            }, {
                rootMargin: '50px',
                threshold: 0.01
            });

            lazyImages.forEach(img => {
                imageObserver.observe(img);
            });
        } else {
            // Fallback: load all images immediately
            lazyImages.forEach(img => {
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    img.classList.remove('lazy-image');
                }
            });
        }

    }

    // Main execution
    function applyAllFixes() {

        removeConflictingStyles();
        forceImageDisplay();
        fixBottomSheetDragging();
        fixGalleryScroll();
        fixLazyImages();

    }

    // Run immediately
    applyAllFixes();

    // Run again after DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyAllFixes);
    }

    // Run again after a delay to override any late-loading scripts
    setTimeout(applyAllFixes, 1000);
    setTimeout(applyAllFixes, 2000);

    // Export for manual triggering
    window.MLDDirectFix = {
        applyAll: applyAllFixes,
        fixImages: forceImageDisplay,
        fixDragging: fixBottomSheetDragging,
        fixScroll: fixGalleryScroll,
        fixLazy: fixLazyImages
    };


})();