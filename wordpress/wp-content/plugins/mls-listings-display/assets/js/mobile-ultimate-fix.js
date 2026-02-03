/**
 * Ultimate Mobile Fix - Complete Solution
 * Fixes images, removes black spaces, and enables proper dragging
 * Version: 4.0.0
 */

(function() {
    'use strict';


    // Fix 1: Remove all the problematic placeholders and spinners
    function cleanupImageContainers() {

        // Remove all placeholders and spinners
        document.querySelectorAll('.mld-image-placeholder').forEach(el => {
            const img = el.querySelector('img');
            if (img && el.parentNode) {
                // Move image out of placeholder
                el.parentNode.insertBefore(img, el);
                el.remove();
            }
        });

        // Remove all spinners
        document.querySelectorAll('.mld-image-spinner').forEach(el => el.remove());

        // Clean up photo items
        document.querySelectorAll('.mld-photo-item').forEach(item => {
            // Remove min-height that causes black spaces
            item.style.minHeight = 'auto';
            item.style.height = 'auto';
            item.style.background = 'transparent';

            // Ensure proper display
            item.style.display = 'block';
            item.style.position = 'relative';
            item.style.width = '100%';
            item.style.margin = '0';
            item.style.padding = '0';
        });
    }

    // Fix 2: Fix all image sources and remove undefined
    function fixAllImages() {

        const images = document.querySelectorAll('.mld-photo-item img, .mld-photo, img');
        let fixedCount = 0;

        images.forEach((img, index) => {
            // Check if src is undefined or contains "undefined"
            if (!img.src || img.src.includes('undefined') || img.src.includes('404')) {
                // Try to get from data-src
                if (img.dataset.src && !img.dataset.src.includes('undefined')) {
                    img.src = img.dataset.src;
                    fixedCount++;
                } else if (img.dataset.originalSrc && !img.dataset.originalSrc.includes('undefined')) {
                    img.src = img.dataset.originalSrc;
                    fixedCount++;
                } else {
                    // Check parent for data attributes
                    const parent = img.closest('.mld-photo-item');
                    if (parent && parent.dataset.imageUrl) {
                        img.src = parent.dataset.imageUrl;
                        fixedCount++;
                    } else {
                        // Hide broken images
                        img.style.display = 'none';
                        if (parent) {
                            parent.style.display = 'none';
                        }
                    }
                }
            }

            // Remove problematic CSS
            img.style.minHeight = 'auto';
            img.style.height = 'auto';
            img.style.width = '100%';
            img.style.display = 'block';
            img.style.objectFit = 'cover';
            img.style.background = 'transparent';

            // Remove blur and opacity issues
            img.style.filter = 'none';
            img.style.opacity = '1';
            img.classList.remove('lazy-image');
        });

    }

    // Fix 3: Enable proper bottom sheet dragging
    function enableProperDragging() {

        const bottomSheet = document.getElementById('bottomSheet');
        const handle = document.querySelector('.mld-sheet-handle');

        if (!bottomSheet || !handle) {
            return;
        }

        // Remove all existing event listeners by cloning
        const newHandle = handle.cloneNode(true);
        handle.parentNode.replaceChild(newHandle, handle);

        // Set initial position
        bottomSheet.style.cssText = `
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
            height: 100vh !important;
            transform: translateY(50%) !important;
            transition: transform 0.3s ease !important;
            z-index: 9999 !important;
            display: flex !important;
            flex-direction: column !important;
            background: white !important;
        `;

        newHandle.style.cssText = `
            cursor: grab !important;
            touch-action: none !important;
            user-select: none !important;
            -webkit-user-select: none !important;
        `;

        // Dragging state
        let isDragging = false;
        let startY = 0;
        let currentTransform = 50;

        // Get current transform percentage
        function getCurrentTransform() {
            const transform = bottomSheet.style.transform;
            const match = transform.match(/translateY\(([0-9.]+)%\)/);
            return match ? parseFloat(match[1]) : 50;
        }

        // Snap to nearest position
        function snapToPosition(current) {
            if (current < 35) return 20;
            if (current > 65) return 80;
            return 50;
        }

        // Touch events with NON-passive listeners for dragging
        newHandle.addEventListener('touchstart', function(e) {
            isDragging = true;
            startY = e.touches[0].clientY;
            currentTransform = getCurrentTransform();
            bottomSheet.style.transition = 'none';
            newHandle.style.cursor = 'grabbing';
        }, { passive: false }); // Non-passive to allow preventDefault

        // Handle touchmove on the handle itself to avoid conflicts with image scrolling
        newHandle.addEventListener('touchmove', function(e) {
            if (!isDragging) return;

            e.preventDefault(); // Prevent scrolling during drag
            e.stopPropagation(); // Stop event from bubbling

            const deltaY = e.touches[0].clientY - startY;
            const percentChange = (deltaY / window.innerHeight) * 100;
            const newTransform = Math.max(10, Math.min(90, currentTransform + percentChange));

            bottomSheet.style.transform = `translateY(${newTransform}%)`;
        }, { passive: false }); // Non-passive to allow preventDefault

        document.addEventListener('touchend', function(e) {
            if (!isDragging) return;

            isDragging = false;
            newHandle.style.cursor = 'grab';

            const finalTransform = getCurrentTransform();
            const snappedPosition = snapToPosition(finalTransform);

            bottomSheet.style.transition = 'transform 0.3s ease';
            bottomSheet.style.transform = `translateY(${snappedPosition}%)`;
        });

        // Mouse events for desktop
        newHandle.addEventListener('mousedown', function(e) {
            isDragging = true;
            startY = e.clientY;
            currentTransform = getCurrentTransform();
            bottomSheet.style.transition = 'none';
            newHandle.style.cursor = 'grabbing';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;

            const deltaY = e.clientY - startY;
            const percentChange = (deltaY / window.innerHeight) * 100;
            const newTransform = Math.max(10, Math.min(90, currentTransform + percentChange));

            bottomSheet.style.transform = `translateY(${newTransform}%)`;
        });

        document.addEventListener('mouseup', function(e) {
            if (!isDragging) return;

            isDragging = false;
            newHandle.style.cursor = 'grab';

            const finalTransform = getCurrentTransform();
            const snappedPosition = snapToPosition(finalTransform);

            bottomSheet.style.transition = 'transform 0.3s ease';
            bottomSheet.style.transform = `translateY(${snappedPosition}%)`;
        });

        // Click to toggle positions
        newHandle.addEventListener('click', function(e) {
            // Ignore if it was a drag
            if (e.detail === 0) return;

            const current = getCurrentTransform();
            let target;

            if (current < 35) {
                target = 50; // From top to middle
            } else if (current < 65) {
                target = 80; // From middle to bottom
            } else {
                target = 20; // From bottom to top
            }

            bottomSheet.style.transition = 'transform 0.3s ease';
            bottomSheet.style.transform = `translateY(${target}%)`;
        });

    }

    // Fix 4: Remove black spaces between images
    function removeBlackSpaces() {

        // Add critical styles to override everything
        const style = document.createElement('style');
        style.textContent = `
            /* Remove all black spaces and gaps */
            .mld-gallery-scroll {
                display: block !important;
                padding: 0 !important;
                margin: 0 !important;
                background: transparent !important;
            }

            .mld-photo-item {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
                min-height: auto !important;
                height: auto !important;
                background: transparent !important;
                line-height: 0 !important;
            }

            .mld-photo-item img {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                background: transparent !important;
                opacity: 1 !important;
                filter: none !important;
            }

            /* Hide broken images */
            .mld-photo-item img[src*="undefined"],
            .mld-photo-item img[src*="404"],
            .mld-photo-item:has(img[src*="undefined"]),
            .mld-photo-item:has(img[src*="404"]) {
                display: none !important;
            }

            /* Fix gallery container */
            .mld-gallery-container {
                background: white !important;
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch !important;
                height: 100vh !important;
            }

            /* Ensure last image stops at middle of viewport */
            .mld-gallery-scroll {
                padding-bottom: 50vh !important;
                margin-bottom: 0 !important;
                touch-action: pan-y !important;
            }

            /* Allow native scrolling on gallery images */
            .mld-gallery-container,
            .mld-gallery-container * {
                touch-action: pan-y !important;
            }

            /* Allow native scrolling on sheet content */
            .mld-sheet-content,
            .mld-sheet-content *,
            .mld-v3-main-mobile,
            .mld-v3-section-mobile {
                touch-action: pan-y !important;
            }

            /* Ensure handle is draggable and prevent scrolling */
            .mld-sheet-handle {
                cursor: grab !important;
                touch-action: none !important;
                -webkit-touch-action: none !important;
            }

            .mld-sheet-handle:active {
                cursor: grabbing !important;
            }

            /* Hide all placeholders and spinners */
            .mld-image-placeholder,
            .mld-image-spinner,
            .image-error-overlay {
                display: none !important;
            }
        `;
        document.head.appendChild(style);

    }

    // Fix 5: Clean up failed lazy loading attempts
    function cleanupLazyLoading() {

        // Find all images that are still trying to lazy load
        document.querySelectorAll('img[data-src]').forEach(img => {
            if (img.dataset.src && !img.dataset.src.includes('undefined')) {
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
            }
            img.classList.remove('lazy-image');
        });
    }

    // Fix 6: Fix gallery controls and scroll behavior
    function fixGalleryControls() {

        const controls = document.querySelector('.mld-gallery-controls');
        const bottomSheet = document.getElementById('bottomSheet');
        const galleryContainer = document.querySelector('.mld-gallery-container');
        const galleryScroll = document.querySelector('.mld-gallery-scroll');
        const sheetContent = document.querySelector('.mld-sheet-content');

        if (!controls || !bottomSheet) {
            return;
        }

        // First, move controls OUTSIDE of scrollable area if they're inside
        if (galleryContainer && controls.parentElement === galleryContainer) {
            document.body.appendChild(controls);
        }

        // Fix gallery scroll padding so last image stops at middle of screen
        if (galleryScroll) {
            galleryScroll.style.paddingBottom = '50vh !important';
            galleryScroll.style.marginBottom = '0 !important';

            // Also ensure the container allows this padding to work
            if (galleryContainer) {
                galleryContainer.style.overflowY = 'auto !important';
                galleryContainer.style.height = '100vh !important';

                // Add explicit touch-action to allow native scrolling without warnings
                galleryContainer.style.touchAction = 'pan-y';
                galleryScroll.style.touchAction = 'pan-y';
            }
        }

        // Update controls positioning to stick to sheet
        const updateControlsPosition = () => {
            const sheetTransform = bottomSheet.style.transform;
            const match = sheetTransform.match(/translateY\(([0-9.]+)%\)/);
            const transformPercent = match ? parseFloat(match[1]) : 50;

            // Calculate the position relative to the top of the sheet
            const viewportHeight = window.innerHeight;
            const sheetTopPosition = viewportHeight * (transformPercent / 100);
            const visibleSheetHeight = viewportHeight - sheetTopPosition;

            // Position controls just above the sheet handle
            // Lower z-index so modals appear above (modals typically use 9999+)
            controls.style.cssText = `
                position: fixed !important;
                bottom: ${viewportHeight - sheetTopPosition + 10}px !important;
                left: 50% !important;
                transform: translateX(-50%) !important;
                z-index: 999 !important;
                transition: bottom 0.3s ease-out !important;
            `;

            // Adjust sheet content padding based on visible height
            if (sheetContent) {
                // Add padding to bottom of sheet content based on how much is hidden
                const paddingNeeded = Math.max(100, viewportHeight - visibleSheetHeight);
                sheetContent.style.paddingBottom = `${paddingNeeded}px`;

                // Ensure native scrolling works without warnings
                sheetContent.style.touchAction = 'pan-y';
            }
        };

        // Initial positioning
        updateControlsPosition();

        // Create MutationObserver to watch for sheet transform changes
        const observer = new MutationObserver(() => {
            updateControlsPosition();
        });

        // Observe style changes on the bottom sheet
        observer.observe(bottomSheet, {
            attributes: true,
            attributeFilter: ['style']
        });

        // Also update on window resize
        window.addEventListener('resize', updateControlsPosition);

    }

    // Main execution function
    function applyAllFixes() {

        cleanupImageContainers();
        fixAllImages();
        removeBlackSpaces();
        cleanupLazyLoading();
        enableProperDragging();
        fixGalleryControls();

    }

    // Apply fixes immediately
    applyAllFixes();

    // Apply again on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyAllFixes);
    }

    // Apply again after delays to catch late-loading content
    setTimeout(applyAllFixes, 500);
    setTimeout(applyAllFixes, 1500);
    setTimeout(applyAllFixes, 3000);

    // Export for manual use
    window.MLDUltimateFix = {
        applyAll: applyAllFixes,
        fixImages: fixAllImages,
        fixDragging: enableProperDragging,
        removeSpaces: removeBlackSpaces,
        cleanup: cleanupImageContainers,
        fixControls: fixGalleryControls
    };


})();