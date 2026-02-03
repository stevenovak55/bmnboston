/**
 * Lead Generation Tools JavaScript
 *
 * Handles CMA requests, property alerts, tour scheduling, and mortgage calculator
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

(function() {
    'use strict';

    // Module namespace
    const BNELeadTools = {
        config: {
            ajaxUrl: bneLeadTools?.ajaxUrl || '/wp-admin/admin-ajax.php',
            nonce: bneLeadTools?.nonce || '',
            defaultRate: bneLeadTools?.defaultRate || 6.5,
            defaultTerm: bneLeadTools?.defaultTerm || 30,
            defaultTax: bneLeadTools?.defaultTax || 1.2,
            defaultInsurance: bneLeadTools?.defaultInsurance || 1200,
        },

        /**
         * Initialize all lead tools
         */
        init: function() {
            this.initCMAForm();
            this.initPropertyAlerts();
            this.initScheduleTour();
            this.initMortgageCalculator();
            this.initFormAnimations();
        },

        /**
         * Initialize CMA Request Form
         */
        initCMAForm: function() {
            const form = document.getElementById('bne-cma-form');
            if (!form) return;

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleFormSubmit(form, 'bne_cma_request', 'cma');
            });

            // Property type selector enhancement
            const typeButtons = form.querySelectorAll('.bne-property-type-btn');
            const typeInput = form.querySelector('[name="property_type"]');

            typeButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    typeButtons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    if (typeInput) typeInput.value = btn.dataset.type;
                });
            });
        },

        /**
         * Initialize Property Alerts Form
         */
        initPropertyAlerts: function() {
            const form = document.getElementById('bne-alerts-form');
            if (!form) return;

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleFormSubmit(form, 'bne_property_alerts', 'alerts');
            });

            // City tag selector
            const cityInput = form.querySelector('.bne-city-input');
            const cityTags = form.querySelector('.bne-city-tags');
            const cityHidden = form.querySelector('[name="cities"]');

            if (cityInput && cityTags) {
                let selectedCities = [];

                cityInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        const city = cityInput.value.trim().replace(',', '');
                        if (city && !selectedCities.includes(city)) {
                            selectedCities.push(city);
                            this.updateCityTags(cityTags, selectedCities, cityHidden);
                        }
                        cityInput.value = '';
                    }
                });
            }

            // Price range slider
            this.initPriceRangeSlider(form);
        },

        /**
         * Update city tags display
         */
        updateCityTags: function(container, cities, hiddenInput) {
            container.innerHTML = cities.map(city => `
                <span class="bne-city-tag">
                    ${city}
                    <button type="button" class="bne-tag-remove" data-city="${city}">&times;</button>
                </span>
            `).join('');

            if (hiddenInput) {
                hiddenInput.value = JSON.stringify(cities);
            }

            // Add remove handlers
            container.querySelectorAll('.bne-tag-remove').forEach(btn => {
                btn.addEventListener('click', () => {
                    const index = cities.indexOf(btn.dataset.city);
                    if (index > -1) {
                        cities.splice(index, 1);
                        this.updateCityTags(container, cities, hiddenInput);
                    }
                });
            });
        },

        /**
         * Initialize price range slider
         */
        initPriceRangeSlider: function(form) {
            const minSlider = form.querySelector('#bne-min-price');
            const maxSlider = form.querySelector('#bne-max-price');
            const minDisplay = form.querySelector('.bne-min-price-display');
            const maxDisplay = form.querySelector('.bne-max-price-display');

            if (!minSlider || !maxSlider) return;

            const updateDisplay = () => {
                const minVal = parseInt(minSlider.value);
                const maxVal = parseInt(maxSlider.value);

                if (minDisplay) {
                    minDisplay.textContent = minVal > 0 ? this.formatCurrency(minVal) : 'No min';
                }
                if (maxDisplay) {
                    maxDisplay.textContent = maxVal > 0 ? this.formatCurrency(maxVal) : 'No max';
                }
            };

            minSlider.addEventListener('input', updateDisplay);
            maxSlider.addEventListener('input', updateDisplay);
            updateDisplay();
        },

        /**
         * Initialize Schedule Tour Form
         */
        initScheduleTour: function() {
            const form = document.getElementById('bne-tour-form');
            if (!form) return;

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleFormSubmit(form, 'bne_schedule_tour', 'tour');
            });

            // Tour type selector
            const typeCards = form.querySelectorAll('.bne-tour-type-card');
            const typeInput = form.querySelector('[name="tour_type"]');

            typeCards.forEach(card => {
                card.addEventListener('click', () => {
                    typeCards.forEach(c => c.classList.remove('selected'));
                    card.classList.add('selected');
                    if (typeInput) typeInput.value = card.dataset.type;
                });
            });

            // Date picker min date (today)
            const dateInput = form.querySelector('[name="preferred_date"]');
            if (dateInput) {
                const today = new Date().toISOString().split('T')[0];
                dateInput.setAttribute('min', today);
            }

            // Time slot buttons
            const timeSlots = form.querySelectorAll('.bne-time-slot');
            const timeInput = form.querySelector('[name="preferred_time"]');

            timeSlots.forEach(slot => {
                slot.addEventListener('click', () => {
                    timeSlots.forEach(s => s.classList.remove('selected'));
                    slot.classList.add('selected');
                    if (timeInput) timeInput.value = slot.dataset.time;
                });
            });
        },

        /**
         * Initialize Mortgage Calculator
         */
        initMortgageCalculator: function() {
            const calculator = document.getElementById('bne-mortgage-calculator');
            if (!calculator) return;

            const inputs = {
                homePrice: calculator.querySelector('#bne-home-price'),
                downPayment: calculator.querySelector('#bne-down-payment'),
                downPaymentPercent: calculator.querySelector('#bne-down-payment-percent'),
                interestRate: calculator.querySelector('#bne-interest-rate'),
                loanTerm: calculator.querySelector('#bne-loan-term'),
                propertyTax: calculator.querySelector('#bne-property-tax'),
                insurance: calculator.querySelector('#bne-insurance'),
                hoa: calculator.querySelector('#bne-hoa'),
            };

            const outputs = {
                monthlyPayment: calculator.querySelector('.bne-monthly-payment'),
                principal: calculator.querySelector('.bne-payment-principal'),
                interest: calculator.querySelector('.bne-payment-interest'),
                taxes: calculator.querySelector('.bne-payment-taxes'),
                insuranceAmt: calculator.querySelector('.bne-payment-insurance'),
                hoaAmt: calculator.querySelector('.bne-payment-hoa'),
                totalPayment: calculator.querySelector('.bne-total-payment'),
                loanAmount: calculator.querySelector('.bne-loan-amount'),
            };

            const calculate = () => {
                const homePrice = parseFloat(inputs.homePrice?.value) || 500000;
                const downPayment = parseFloat(inputs.downPayment?.value) || 100000;
                const rate = parseFloat(inputs.interestRate?.value) || this.config.defaultRate;
                const term = parseInt(inputs.loanTerm?.value) || this.config.defaultTerm;
                const propertyTax = parseFloat(inputs.propertyTax?.value) || this.config.defaultTax;
                const insurance = parseFloat(inputs.insurance?.value) || this.config.defaultInsurance;
                const hoa = parseFloat(inputs.hoa?.value) || 0;

                const loanAmount = homePrice - downPayment;
                const monthlyRate = (rate / 100) / 12;
                const numPayments = term * 12;

                // Calculate principal & interest payment
                let monthlyPI = 0;
                if (monthlyRate > 0) {
                    monthlyPI = loanAmount * (monthlyRate * Math.pow(1 + monthlyRate, numPayments)) /
                                (Math.pow(1 + monthlyRate, numPayments) - 1);
                } else {
                    monthlyPI = loanAmount / numPayments;
                }

                // Calculate first month's breakdown
                const firstMonthInterest = loanAmount * monthlyRate;
                const firstMonthPrincipal = monthlyPI - firstMonthInterest;

                // Monthly property tax and insurance
                const monthlyTax = (homePrice * (propertyTax / 100)) / 12;
                const monthlyInsurance = insurance / 12;

                // Total monthly payment
                const totalMonthly = monthlyPI + monthlyTax + monthlyInsurance + hoa;

                // Update outputs
                if (outputs.monthlyPayment) {
                    outputs.monthlyPayment.textContent = this.formatCurrency(totalMonthly);
                }
                if (outputs.principal) {
                    outputs.principal.textContent = this.formatCurrency(firstMonthPrincipal);
                }
                if (outputs.interest) {
                    outputs.interest.textContent = this.formatCurrency(firstMonthInterest);
                }
                if (outputs.taxes) {
                    outputs.taxes.textContent = this.formatCurrency(monthlyTax);
                }
                if (outputs.insuranceAmt) {
                    outputs.insuranceAmt.textContent = this.formatCurrency(monthlyInsurance);
                }
                if (outputs.hoaAmt) {
                    outputs.hoaAmt.textContent = this.formatCurrency(hoa);
                }
                if (outputs.totalPayment) {
                    const totalCost = monthlyPI * numPayments;
                    outputs.totalPayment.textContent = this.formatCurrency(totalCost);
                }
                if (outputs.loanAmount) {
                    outputs.loanAmount.textContent = this.formatCurrency(loanAmount);
                }

                // Update pie chart
                this.updatePaymentChart(calculator, {
                    principal: firstMonthPrincipal,
                    interest: firstMonthInterest,
                    taxes: monthlyTax,
                    insurance: monthlyInsurance,
                    hoa: hoa,
                });

                // Update down payment percent display
                if (inputs.downPaymentPercent && inputs.homePrice) {
                    const percent = (downPayment / homePrice) * 100;
                    inputs.downPaymentPercent.textContent = percent.toFixed(1) + '%';
                }
            };

            // Sync down payment slider with input
            const downPaymentSlider = calculator.querySelector('#bne-down-payment-slider');
            if (downPaymentSlider && inputs.downPayment) {
                downPaymentSlider.addEventListener('input', () => {
                    const homePrice = parseFloat(inputs.homePrice?.value) || 500000;
                    const percent = parseFloat(downPaymentSlider.value);
                    inputs.downPayment.value = Math.round(homePrice * (percent / 100));
                    calculate();
                });

                inputs.downPayment.addEventListener('input', () => {
                    const homePrice = parseFloat(inputs.homePrice?.value) || 500000;
                    const percent = (parseFloat(inputs.downPayment.value) / homePrice) * 100;
                    downPaymentSlider.value = Math.min(Math.max(percent, 0), 100);
                    calculate();
                });
            }

            // Add event listeners to all inputs
            Object.values(inputs).forEach(input => {
                if (input && input.tagName) {
                    input.addEventListener('input', calculate);
                    input.addEventListener('change', calculate);
                }
            });

            // Loan term buttons
            const termButtons = calculator.querySelectorAll('.bne-term-btn');
            termButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    termButtons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    if (inputs.loanTerm) {
                        inputs.loanTerm.value = btn.dataset.term;
                        calculate();
                    }
                });
            });

            // Initial calculation
            calculate();
        },

        /**
         * Update payment breakdown chart (CSS-based pie chart)
         */
        updatePaymentChart: function(calculator, values) {
            const chart = calculator.querySelector('.bne-payment-chart');
            if (!chart) return;

            const total = values.principal + values.interest + values.taxes + values.insurance + values.hoa;
            if (total === 0) return;

            const segments = [
                { value: values.principal, color: 'var(--bne-color-primary)' },
                { value: values.interest, color: 'var(--bne-color-accent)' },
                { value: values.taxes, color: 'var(--bne-color-warning)' },
                { value: values.insurance, color: 'var(--bne-color-info)' },
                { value: values.hoa, color: 'var(--bne-color-secondary)' },
            ];

            let gradientParts = [];
            let currentAngle = 0;

            segments.forEach(segment => {
                if (segment.value > 0) {
                    const angle = (segment.value / total) * 360;
                    gradientParts.push(`${segment.color} ${currentAngle}deg ${currentAngle + angle}deg`);
                    currentAngle += angle;
                }
            });

            chart.style.background = `conic-gradient(${gradientParts.join(', ')})`;
        },

        /**
         * Handle form submission
         */
        handleFormSubmit: async function(form, action, formType) {
            const submitBtn = form.querySelector('[type="submit"]');
            const feedback = form.querySelector('.bne-form-feedback');
            const originalText = submitBtn?.textContent;

            // Disable form
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
            }

            // Clear previous feedback
            if (feedback) {
                feedback.className = 'bne-form-feedback';
                feedback.textContent = '';
            }

            try {
                const formData = new FormData(form);
                formData.append('action', action);
                formData.append('nonce', this.config.nonce);

                const response = await fetch(this.config.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                });

                const result = await response.json();

                if (result.success) {
                    // Show success
                    if (feedback) {
                        feedback.className = 'bne-form-feedback success';
                        feedback.textContent = result.data.message;
                    }

                    // Reset form
                    form.reset();

                    // Reset UI elements
                    form.querySelectorAll('.active, .selected').forEach(el => el.classList.remove('active', 'selected'));

                    // Track conversion
                    this.trackConversion(formType, result.data);

                } else {
                    // Show error
                    if (feedback) {
                        feedback.className = 'bne-form-feedback error';
                        feedback.textContent = result.data?.message || 'An error occurred. Please try again.';
                    }
                }

            } catch (error) {
                console.error('Form submission error:', error);
                if (feedback) {
                    feedback.className = 'bne-form-feedback error';
                    feedback.textContent = 'A network error occurred. Please try again.';
                }
            } finally {
                // Re-enable form
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            }
        },

        /**
         * Track conversion event
         */
        trackConversion: function(formType, data) {
            // Google Analytics 4
            if (typeof gtag !== 'undefined') {
                gtag('event', 'generate_lead', {
                    'event_category': 'Lead Generation',
                    'event_label': formType,
                    'value': 1,
                });
            }

            // Facebook Pixel
            if (typeof fbq !== 'undefined') {
                fbq('track', 'Lead', {
                    content_name: formType,
                });
            }

            // Custom event for other tracking
            document.dispatchEvent(new CustomEvent('bne:lead_generated', {
                detail: { formType, data }
            }));
        },

        /**
         * Initialize form animations
         */
        initFormAnimations: function() {
            // Floating labels
            document.querySelectorAll('.bne-float-label input, .bne-float-label textarea').forEach(input => {
                const updateLabel = () => {
                    input.parentElement.classList.toggle('has-value', input.value.length > 0);
                };
                input.addEventListener('focus', () => input.parentElement.classList.add('focused'));
                input.addEventListener('blur', () => {
                    input.parentElement.classList.remove('focused');
                    updateLabel();
                });
                updateLabel();
            });

            // Section reveal on scroll
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('.bne-lead-section').forEach(section => {
                observer.observe(section);
            });
        },

        /**
         * Format currency
         */
        formatCurrency: function(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0,
            }).format(amount);
        },
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => BNELeadTools.init());
    } else {
        BNELeadTools.init();
    }

    // Export to global scope
    window.BNELeadTools = BNELeadTools;
})();
