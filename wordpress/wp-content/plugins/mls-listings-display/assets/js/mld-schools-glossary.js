/**
 * MLD Schools Glossary Handler
 * Tooltip on hover, Modal on click
 *
 * @package MLS_Listings_Display
 * @since 6.30.0
 */
(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        apiBase: '/wp-json/bmn-schools/v1/glossary/',
        tooltipDelay: 300,
        tooltipMaxLength: 150,
        cacheExpiry: 30 * 60 * 1000 // 30 minutes
    };

    // Cache for glossary terms
    const termCache = new Map();

    // DOM elements
    let tooltipEl = null;
    let modalEl = null;
    let tooltipTimeout = null;
    let currentTooltipChip = null;

    /**
     * Initialize the glossary handler
     */
    function init() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setup);
        } else {
            setup();
        }
    }

    /**
     * Set up event listeners and create elements
     */
    function setup() {
        // Create tooltip element
        createTooltip();

        // Create modal element if not in DOM
        createModal();

        // Bind events using event delegation
        document.addEventListener('mouseenter', handleMouseEnter, true);
        document.addEventListener('mouseleave', handleMouseLeave, true);
        document.addEventListener('click', handleClick, true);

        // Keyboard events for accessibility
        document.addEventListener('keydown', handleKeydown);
    }

    /**
     * Create the tooltip element
     */
    function createTooltip() {
        tooltipEl = document.createElement('div');
        tooltipEl.className = 'mld-glossary-tooltip';
        tooltipEl.setAttribute('role', 'tooltip');
        tooltipEl.setAttribute('aria-hidden', 'true');
        document.body.appendChild(tooltipEl);
    }

    /**
     * Create the modal element
     */
    function createModal() {
        // Check if modal already exists in DOM (rendered by PHP)
        modalEl = document.getElementById('mld-glossary-modal');
        if (modalEl) {
            // Move modal to body to avoid position:fixed issues with ancestor transforms
            // This ensures the modal is positioned relative to the viewport, not a transformed parent
            if (modalEl.parentNode !== document.body) {
                document.body.appendChild(modalEl);
            }
            // Attach close handlers to existing modal
            attachModalCloseHandlers();
            return;
        }

        // Create modal dynamically
        modalEl = document.createElement('div');
        modalEl.id = 'mld-glossary-modal';
        modalEl.className = 'mld-glossary-modal';
        modalEl.setAttribute('role', 'dialog');
        modalEl.setAttribute('aria-modal', 'true');
        modalEl.setAttribute('aria-hidden', 'true');

        modalEl.innerHTML = `
            <div class="mld-modal-overlay"></div>
            <div class="mld-modal-content">
                <button class="mld-modal-close" aria-label="Close">&times;</button>
                <h3 class="mld-modal-term"></h3>
                <p class="mld-modal-fullname"></p>
                <div class="mld-modal-section">
                    <h4 class="mld-modal-section-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="16" x2="12" y2="12"/>
                            <line x1="12" y1="8" x2="12.01" y2="8"/>
                        </svg>
                        What is it?
                    </h4>
                    <div class="mld-modal-description"></div>
                </div>
                <div class="mld-parent-tip">
                    <div class="mld-parent-tip-label">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7z"/>
                        </svg>
                        Parent Tip
                    </div>
                    <p class="mld-parent-tip-text"></p>
                </div>
            </div>
        `;

        document.body.appendChild(modalEl);
        attachModalCloseHandlers();
    }

    /**
     * Attach close handlers to modal
     */
    function attachModalCloseHandlers() {
        if (!modalEl) return;

        const overlay = modalEl.querySelector('.mld-modal-overlay');
        const closeBtn = modalEl.querySelector('.mld-modal-close');

        if (overlay) {
            overlay.addEventListener('click', closeModal);
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
    }

    /**
     * Handle mouse enter on glossary chips
     */
    function handleMouseEnter(e) {
        const chip = e.target.closest('.mld-glossary-chip');
        if (!chip) return;

        const term = chip.dataset.term;
        if (!term) return;

        currentTooltipChip = chip;

        // Clear any existing timeout
        if (tooltipTimeout) {
            clearTimeout(tooltipTimeout);
        }

        // Delay showing tooltip
        tooltipTimeout = setTimeout(async () => {
            if (currentTooltipChip !== chip) return;

            const termData = await fetchTerm(term);
            if (termData && currentTooltipChip === chip) {
                showTooltip(chip, termData);
            }
        }, CONFIG.tooltipDelay);
    }

    /**
     * Handle mouse leave on glossary chips
     */
    function handleMouseLeave(e) {
        const chip = e.target.closest('.mld-glossary-chip');
        if (!chip) return;

        if (tooltipTimeout) {
            clearTimeout(tooltipTimeout);
            tooltipTimeout = null;
        }

        currentTooltipChip = null;
        hideTooltip();
    }

    /**
     * Handle click on glossary chips
     */
    function handleClick(e) {
        const chip = e.target.closest('.mld-glossary-chip');
        if (!chip) return;

        e.preventDefault();
        e.stopPropagation();

        const term = chip.dataset.term;
        if (!term) return;

        // Hide tooltip immediately
        hideTooltip();
        if (tooltipTimeout) {
            clearTimeout(tooltipTimeout);
            tooltipTimeout = null;
        }

        // Show modal
        showModal(term);
    }

    /**
     * Handle keyboard events
     */
    function handleKeydown(e) {
        // ESC closes modal
        if (e.key === 'Escape' && modalEl && modalEl.classList.contains('visible')) {
            closeModal();
        }

        // Enter/Space on focused chip opens modal
        if ((e.key === 'Enter' || e.key === ' ') && document.activeElement.classList.contains('mld-glossary-chip')) {
            e.preventDefault();
            const term = document.activeElement.dataset.term;
            if (term) {
                showModal(term);
            }
        }
    }

    /**
     * Fetch term data from API with caching
     */
    async function fetchTerm(term) {
        // Check cache first
        const cached = termCache.get(term);
        if (cached && Date.now() - cached.timestamp < CONFIG.cacheExpiry) {
            return cached.data;
        }

        try {
            const response = await fetch(`${CONFIG.apiBase}?term=${encodeURIComponent(term)}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = await response.json();
            if (json.success && json.data) {
                // Cache the result
                termCache.set(term, {
                    data: json.data,
                    timestamp: Date.now()
                });
                return json.data;
            }
        } catch (error) {
            console.error('Failed to fetch glossary term:', term, error);
        }

        return null;
    }

    /**
     * Show tooltip near the anchor element
     */
    function showTooltip(anchor, termData) {
        if (!tooltipEl || !termData) return;

        // Build tooltip content
        let description = termData.description || '';
        if (description.length > CONFIG.tooltipMaxLength) {
            description = description.substring(0, CONFIG.tooltipMaxLength).trim() + '...';
        }

        tooltipEl.innerHTML = `
            <div class="mld-glossary-tooltip-title">${escapeHtml(termData.term || '')}</div>
            <div class="mld-glossary-tooltip-desc">${escapeHtml(description)}</div>
            <span class="mld-glossary-tooltip-more">Click for more details</span>
        `;

        // Position tooltip
        positionTooltip(anchor);

        // Show with animation
        tooltipEl.classList.add('visible');
        tooltipEl.setAttribute('aria-hidden', 'false');
    }

    /**
     * Position tooltip relative to anchor
     */
    function positionTooltip(anchor) {
        const rect = anchor.getBoundingClientRect();
        const tooltipRect = tooltipEl.getBoundingClientRect();

        // Default: below the anchor
        let top = rect.bottom + 8;
        let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);

        // Adjust if tooltip would overflow right edge
        if (left + tooltipRect.width > window.innerWidth - 16) {
            left = window.innerWidth - tooltipRect.width - 16;
        }

        // Adjust if tooltip would overflow left edge
        if (left < 16) {
            left = 16;
        }

        // Adjust if tooltip would overflow bottom
        if (top + tooltipRect.height > window.innerHeight - 16) {
            // Show above instead
            top = rect.top - tooltipRect.height - 8;
        }

        tooltipEl.style.top = `${top}px`;
        tooltipEl.style.left = `${left}px`;
    }

    /**
     * Hide tooltip
     */
    function hideTooltip() {
        if (!tooltipEl) return;

        tooltipEl.classList.remove('visible');
        tooltipEl.setAttribute('aria-hidden', 'true');
    }

    /**
     * Show modal with term data
     */
    async function showModal(term) {
        if (!modalEl) return;

        // Show loading state
        const termEl = modalEl.querySelector('.mld-modal-term');
        const fullnameEl = modalEl.querySelector('.mld-modal-fullname');
        const descriptionEl = modalEl.querySelector('.mld-modal-description');
        const tipTextEl = modalEl.querySelector('.mld-parent-tip-text');
        const tipContainer = modalEl.querySelector('.mld-parent-tip');

        if (termEl) termEl.textContent = term;
        if (fullnameEl) fullnameEl.textContent = 'Loading...';
        if (descriptionEl) descriptionEl.textContent = '';
        if (tipTextEl) tipTextEl.textContent = '';
        if (tipContainer) tipContainer.style.display = 'none';

        // Show modal
        modalEl.classList.add('visible');
        modalEl.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        // Focus the close button for accessibility
        const closeBtn = modalEl.querySelector('.mld-modal-close');
        if (closeBtn) {
            setTimeout(() => closeBtn.focus(), 100);
        }

        // Fetch term data
        const termData = await fetchTerm(term);

        if (termData) {
            if (termEl) termEl.textContent = termData.term || term;
            if (fullnameEl) fullnameEl.textContent = termData.full_name || '';
            if (descriptionEl) descriptionEl.textContent = termData.description || '';
            if (tipTextEl && termData.parent_tip) {
                tipTextEl.textContent = termData.parent_tip;
                if (tipContainer) tipContainer.style.display = 'block';
            }
        } else {
            if (fullnameEl) fullnameEl.textContent = '';
            if (descriptionEl) descriptionEl.textContent = 'Unable to load information. Please try again.';
        }
    }

    /**
     * Close modal
     */
    function closeModal() {
        if (!modalEl) return;

        modalEl.classList.remove('visible');
        modalEl.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize
    init();

})();
