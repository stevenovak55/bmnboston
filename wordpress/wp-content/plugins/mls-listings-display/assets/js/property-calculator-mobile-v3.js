/**
 * MLD Property Calculator Mobile V3
 * Lightweight calculator for mobile property pages
 * Works with the v3Calc* element IDs in single-property-mobile-v3.php
 *
 * @version 6.0.3
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCalculator);
    } else {
        initCalculator();
    }

    function initCalculator() {
        // Check if calculator exists on page
        const priceInput = document.getElementById('v3CalcPrice');
        if (!priceInput) return;

        // Get all input elements
        const inputs = {
            price: document.getElementById('v3CalcPrice'),
            downPercent: document.getElementById('v3CalcDownPercent'),
            rate: document.getElementById('v3CalcRate'),
            term: document.getElementById('v3CalcTerm')
        };

        // Get all display elements
        const displays = {
            // Summary cards
            paymentSummary: document.getElementById('v3CalcPaymentSummary'),
            loanAmount: document.getElementById('v3CalcLoanAmount'),
            totalInterest: document.getElementById('v3CalcTotalInterest'),
            totalCost: document.getElementById('v3CalcTotalCost'),

            // Down payment display
            downDisplay: document.getElementById('v3CalcDownDisplay'),

            // Payment breakdown
            pi: document.getElementById('v3CalcPI'),
            tax: document.getElementById('v3CalcTax'),
            insurance: document.getElementById('v3CalcInsurance'),
            pmi: document.getElementById('v3CalcPMI'),

            // Loan summary
            totalPayments: document.getElementById('v3CalcTotalPayments'),
            totalPrincipal: document.getElementById('v3CalcTotalPrincipal'),
            totalInterestDetail: document.getElementById('v3CalcTotalInterestDetail'),

            // Rate impact
            rateLower: document.getElementById('v3CalcRateLower'),
            rateLowerSave: document.getElementById('v3CalcRateLowerSave'),
            rateCurrent: document.getElementById('v3CalcRateCurrent'),
            rateHigher: document.getElementById('v3CalcRateHigher'),
            rateHigherCost: document.getElementById('v3CalcRateHigherCost'),

            // Amortization
            amortInterest: document.getElementById('v3AmortInterest'),
            amortPrincipal: document.getElementById('v3AmortPrincipal'),
            year1Interest: document.getElementById('v3Year1Interest'),
            year15Split: document.getElementById('v3Year15Split'),
            year30Principal: document.getElementById('v3Year30Principal')
        };

        // Get property tax and HOA from the page (set by PHP)
        const taxElement = document.getElementById('v3CalcTax');
        const propertyTaxMonthly = taxElement ? parseFloat(taxElement.textContent.replace(/[$,]/g, '')) || 0 : 0;
        const insuranceMonthly = 200; // Default insurance estimate

        // Bind events to all inputs
        Object.values(inputs).forEach(input => {
            if (input) {
                input.addEventListener('input', calculate);
                input.addEventListener('change', calculate);
            }
        });

        // Run initial calculation
        calculate();

        function calculate() {
            // Get input values
            const price = parseFloat(inputs.price.value) || 0;
            const downPercent = parseFloat(inputs.downPercent.value) || 20;
            const rate = parseFloat(inputs.rate.value) || 6.5;
            const term = parseInt(inputs.term.value) || 30;

            // Calculate loan details
            const downPayment = (price * downPercent) / 100;
            const loanAmount = price - downPayment;
            const monthlyRate = rate / 100 / 12;
            const numPayments = term * 12;

            // Update down payment display
            if (displays.downDisplay) {
                displays.downDisplay.textContent = formatCurrency(downPayment);
            }

            // Calculate principal & interest using amortization formula
            let principalInterest = 0;
            if (monthlyRate > 0 && loanAmount > 0) {
                principalInterest = (loanAmount * (monthlyRate * Math.pow(1 + monthlyRate, numPayments))) /
                                  (Math.pow(1 + monthlyRate, numPayments) - 1);
            } else if (loanAmount > 0) {
                principalInterest = loanAmount / numPayments;
            }

            // Calculate PMI if down payment < 20%
            let pmi = 0;
            if (downPercent < 20 && loanAmount > 0) {
                pmi = (loanAmount * 0.5) / 100 / 12; // 0.5% annual PMI rate
            }

            // Calculate total monthly payment (PITI + PMI)
            const totalMonthly = principalInterest + propertyTaxMonthly + insuranceMonthly + pmi;

            // Calculate totals over life of loan
            const totalPrincipalPaid = loanAmount;
            const totalInterest = (principalInterest * numPayments) - loanAmount;
            const totalOfPayments = principalInterest * numPayments;
            const totalCost = downPayment + totalOfPayments;

            // Update summary cards
            if (displays.paymentSummary) {
                displays.paymentSummary.textContent = formatCurrency(totalMonthly);
            }
            if (displays.loanAmount) {
                displays.loanAmount.textContent = formatCurrency(loanAmount);
            }
            if (displays.totalInterest) {
                displays.totalInterest.textContent = formatCurrency(totalInterest);
            }
            if (displays.totalCost) {
                displays.totalCost.textContent = formatCurrency(totalCost);
            }

            // Update payment breakdown
            if (displays.pi) {
                displays.pi.textContent = formatCurrency(principalInterest);
            }
            if (displays.insurance) {
                displays.insurance.textContent = formatCurrency(insuranceMonthly);
            }
            if (displays.pmi) {
                displays.pmi.textContent = formatCurrency(pmi);
            }

            // Update loan summary
            if (displays.totalPayments) {
                displays.totalPayments.textContent = formatCurrency(totalOfPayments);
            }
            if (displays.totalPrincipal) {
                displays.totalPrincipal.textContent = formatCurrency(totalPrincipalPaid);
            }
            if (displays.totalInterestDetail) {
                displays.totalInterestDetail.textContent = formatCurrency(totalInterest);
            }

            // Update rate impact analysis
            updateRateImpact(loanAmount, rate, numPayments, propertyTaxMonthly, insuranceMonthly, pmi);

            // Update amortization visualization
            updateAmortization(loanAmount, monthlyRate, numPayments, principalInterest);
        }

        function updateRateImpact(loanAmount, currentRate, numPayments, tax, insurance, pmi) {
            const lowerRate = currentRate - 0.5;
            const higherRate = currentRate + 0.5;

            // Calculate payments at different rates
            const currentPI = calculatePI(loanAmount, currentRate, numPayments);
            const lowerPI = calculatePI(loanAmount, lowerRate, numPayments);
            const higherPI = calculatePI(loanAmount, higherRate, numPayments);

            const currentTotal = currentPI + tax + insurance + pmi;
            const lowerTotal = lowerPI + tax + insurance + pmi;
            const higherTotal = higherPI + tax + insurance + pmi;

            const savings = currentTotal - lowerTotal;
            const extraCost = higherTotal - currentTotal;

            // Update displays
            if (displays.rateLower) {
                displays.rateLower.textContent = formatCurrency(lowerTotal) + '/mo';
            }
            if (displays.rateLowerSave) {
                displays.rateLowerSave.textContent = 'Save ' + formatCurrency(savings) + '/mo';
            }
            if (displays.rateCurrent) {
                displays.rateCurrent.textContent = formatCurrency(currentTotal) + '/mo';
            }
            if (displays.rateHigher) {
                displays.rateHigher.textContent = formatCurrency(higherTotal) + '/mo';
            }
            if (displays.rateHigherCost) {
                displays.rateHigherCost.textContent = 'Cost ' + formatCurrency(extraCost) + '/mo';
            }
        }

        function updateAmortization(loanAmount, monthlyRate, numPayments, monthlyPayment) {
            if (!displays.amortInterest || !displays.amortPrincipal) return;

            // Calculate interest/principal split at different years
            const year1 = calculatePaymentSplit(loanAmount, monthlyRate, monthlyPayment, 12);
            const year15 = calculatePaymentSplit(loanAmount, monthlyRate, monthlyPayment, 15 * 12);
            const year30 = calculatePaymentSplit(loanAmount, monthlyRate, monthlyPayment, numPayments);

            // Update Year 1 visualization (use Year 1 percentages for the bar)
            const year1InterestPct = year1.interestPercent;
            const year1PrincipalPct = year1.principalPercent;

            if (displays.amortInterest) {
                displays.amortInterest.style.width = year1InterestPct + '%';
            }
            if (displays.amortPrincipal) {
                displays.amortPrincipal.style.width = year1PrincipalPct + '%';
            }

            // Update milestone displays
            if (displays.year1Interest) {
                displays.year1Interest.textContent = Math.round(year1InterestPct) + '%';
            }
            if (displays.year15Split) {
                displays.year15Split.textContent = Math.round(year15.interestPercent) + '/' +
                                                   Math.round(year15.principalPercent);
            }
            if (displays.year30Principal) {
                displays.year30Principal.textContent = Math.round(year30.principalPercent) + '%';
            }
        }

        function calculatePaymentSplit(loanAmount, monthlyRate, monthlyPayment, paymentNumber) {
            // Calculate remaining balance at this payment
            let balance = loanAmount;
            for (let i = 1; i < paymentNumber; i++) {
                const interest = balance * monthlyRate;
                const principal = monthlyPayment - interest;
                balance -= principal;
            }

            // Calculate this month's split
            const interest = balance * monthlyRate;
            const principal = monthlyPayment - interest;

            return {
                interest: interest,
                principal: principal,
                interestPercent: (interest / monthlyPayment) * 100,
                principalPercent: (principal / monthlyPayment) * 100
            };
        }

        function calculatePI(loanAmount, rate, numPayments) {
            const monthlyRate = rate / 100 / 12;

            if (monthlyRate > 0 && loanAmount > 0) {
                return (loanAmount * (monthlyRate * Math.pow(1 + monthlyRate, numPayments))) /
                       (Math.pow(1 + monthlyRate, numPayments) - 1);
            } else if (loanAmount > 0) {
                return loanAmount / numPayments;
            }
            return 0;
        }

        function formatCurrency(amount) {
            if (isNaN(amount) || amount === null) return '$0';

            return '$' + Math.round(amount).toLocaleString('en-US', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }
    }
})();
