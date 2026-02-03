/**
 * MLD Calculator Module V2
 * Advanced mortgage calculator with charts and comprehensive breakdown
 *
 * @version 2.0.0
 */

class MLDCalculator {
  constructor(options = {}) {
    this.options = {
      container: options.container || '#mld-calculator',
      price: options.price || 500000,
      downPaymentPercent: options.downPaymentPercent || 20,
      interestRate: options.interestRate || 7.0,
      loanTerm: options.loanTerm || 30,
      propertyTax: options.propertyTax || null,
      propertyTaxRate: options.propertyTaxRate || 1.2, // % of home value annually
      homeInsurance: options.homeInsurance || null,
      homeInsuranceRate: options.homeInsuranceRate || 0.35, // % of home value annually
      hoaFees: options.hoaFees || 0,
      pmiRate: options.pmiRate || 0.5, // % of loan amount annually
      closingCostRate: options.closingCostRate || 2.5, // % of home price
      enableChart: options.enableChart !== false,
      onChange: options.onChange || null,
      currency: options.currency || 'USD',
      locale: options.locale || 'en-US',
    };

    this.state = {
      monthlyPayment: 0,
      principalAndInterest: 0,
      propertyTax: 0,
      homeInsurance: 0,
      pmi: 0,
      hoaFees: 0,
      totalMonthlyPayment: 0,
      downPayment: 0,
      loanAmount: 0,
      totalInterest: 0,
      totalPaid: 0,
      closingCosts: 0,
      cashNeeded: 0,
      amortizationSchedule: [],
    };

    this.elements = {};
    this.chart = null;
    this.init();
  }

  init() {
    this.setupContainer();
    this.createCalculator();
    this.bindEvents();
    this.calculate();
  }

  setupContainer() {
    const container = document.querySelector(this.options.container);
    if (!container) {
      MLDLogger.error('Calculator container not found');
      return;
    }
    this.elements.container = container;
  }

  createCalculator() {
    if (!this.elements.container) return;

    const html = `
            <div class="mld-calc-v2">
                <div class="mld-calc-inputs">
                    <div class="mld-calc-section">
                        <h3 class="mld-calc-section-title">Loan Details</h3>
                        
                        <div class="mld-calc-field">
                            <label for="calc-price">Home Price</label>
                            <div class="mld-calc-input-wrapper">
                                <span class="mld-calc-prefix">$</span>
                                <input type="number" 
                                       id="calc-price" 
                                       value="${this.options.price}" 
                                       min="0" 
                                       step="1000">
                            </div>
                        </div>
                        
                        <div class="mld-calc-field">
                            <label for="calc-down-percent">Down Payment</label>
                            <div class="mld-calc-dual-input">
                                <div class="mld-calc-input-wrapper">
                                    <input type="number" 
                                           id="calc-down-percent" 
                                           value="${this.options.downPaymentPercent}" 
                                           min="0" 
                                           max="100" 
                                           step="1">
                                    <span class="mld-calc-suffix">%</span>
                                </div>
                                <div class="mld-calc-input-wrapper">
                                    <span class="mld-calc-prefix">$</span>
                                    <input type="number" 
                                           id="calc-down-amount" 
                                           value="${(this.options.price * this.options.downPaymentPercent) / 100}" 
                                           min="0" 
                                           step="1000">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mld-calc-field">
                            <label for="calc-rate">Interest Rate</label>
                            <div class="mld-calc-input-wrapper">
                                <input type="number" 
                                       id="calc-rate" 
                                       value="${this.options.interestRate}" 
                                       min="0" 
                                       max="20" 
                                       step="0.125">
                                <span class="mld-calc-suffix">%</span>
                            </div>
                        </div>
                        
                        <div class="mld-calc-field">
                            <label for="calc-term">Loan Term</label>
                            <select id="calc-term">
                                <option value="15" ${this.options.loanTerm === 15 ? 'selected' : ''}>15 years</option>
                                <option value="20" ${this.options.loanTerm === 20 ? 'selected' : ''}>20 years</option>
                                <option value="30" ${this.options.loanTerm === 30 ? 'selected' : ''}>30 years</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mld-calc-section">
                        <h3 class="mld-calc-section-title">Additional Costs</h3>
                        
                        <div class="mld-calc-field">
                            <label for="calc-property-tax">Property Tax</label>
                            <div class="mld-calc-input-wrapper">
                                <span class="mld-calc-prefix">$</span>
                                <input type="number" 
                                       id="calc-property-tax" 
                                       value="${this.options.propertyTax || Math.round((this.options.price * this.options.propertyTaxRate) / 100 / 12)}" 
                                       min="0" 
                                       step="10">
                                <span class="mld-calc-suffix">/mo</span>
                            </div>
                        </div>
                        
                        <div class="mld-calc-field">
                            <label for="calc-insurance">Home Insurance</label>
                            <div class="mld-calc-input-wrapper">
                                <span class="mld-calc-prefix">$</span>
                                <input type="number" 
                                       id="calc-insurance" 
                                       value="${this.options.homeInsurance || Math.round((this.options.price * this.options.homeInsuranceRate) / 100 / 12)}" 
                                       min="0" 
                                       step="10">
                                <span class="mld-calc-suffix">/mo</span>
                            </div>
                        </div>
                        
                        <div class="mld-calc-field">
                            <label for="calc-hoa">HOA Fees</label>
                            <div class="mld-calc-input-wrapper">
                                <span class="mld-calc-prefix">$</span>
                                <input type="number" 
                                       id="calc-hoa" 
                                       value="${this.options.hoaFees}" 
                                       min="0" 
                                       step="10">
                                <span class="mld-calc-suffix">/mo</span>
                            </div>
                        </div>
                        
                        <div class="mld-calc-field mld-calc-pmi-field" style="${this.options.downPaymentPercent >= 20 ? 'display: none;' : ''}">
                            <label>PMI <span class="mld-calc-hint">(Required if down payment < 20%)</span></label>
                            <div class="mld-calc-value" id="calc-pmi">$0/mo</div>
                        </div>
                    </div>
                </div>
                
                <div class="mld-calc-results">
                    <div class="mld-calc-payment-card">
                        <div class="mld-calc-payment-label">Monthly Payment</div>
                        <div class="mld-calc-payment-amount" id="calc-total-payment">$0</div>
                        <div class="mld-calc-payment-breakdown">
                            <div class="mld-calc-breakdown-item">
                                <span>Principal & Interest</span>
                                <span id="calc-pi">$0</span>
                            </div>
                            <div class="mld-calc-breakdown-item">
                                <span>Property Tax</span>
                                <span id="calc-tax">$0</span>
                            </div>
                            <div class="mld-calc-breakdown-item">
                                <span>Home Insurance</span>
                                <span id="calc-ins">$0</span>
                            </div>
                            <div class="mld-calc-breakdown-item pmi" style="${this.options.downPaymentPercent >= 20 ? 'display: none;' : ''}">
                                <span>PMI</span>
                                <span id="calc-pmi-amount">$0</span>
                            </div>
                            <div class="mld-calc-breakdown-item" style="${this.options.hoaFees <= 0 ? 'display: none;' : ''}">
                                <span>HOA</span>
                                <span id="calc-hoa-amount">$0</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mld-calc-chart-container" id="calc-chart" style="${this.options.enableChart ? '' : 'display: none;'}">
                        <canvas id="calc-chart-canvas"></canvas>
                    </div>
                    
                    <div class="mld-calc-summary">
                        <h3 class="mld-calc-section-title">Loan Summary</h3>
                        <div class="mld-calc-summary-grid">
                            <div class="mld-calc-summary-item">
                                <span class="label">Loan Amount</span>
                                <span class="value" id="calc-loan-amount">$0</span>
                            </div>
                            <div class="mld-calc-summary-item">
                                <span class="label">Total Interest Paid</span>
                                <span class="value" id="calc-total-interest">$0</span>
                            </div>
                            <div class="mld-calc-summary-item">
                                <span class="label">Total Amount Paid</span>
                                <span class="value" id="calc-total-paid">$0</span>
                            </div>
                            <div class="mld-calc-summary-item">
                                <span class="label">Est. Closing Costs</span>
                                <span class="value" id="calc-closing">$0</span>
                            </div>
                            <div class="mld-calc-summary-item highlight">
                                <span class="label">Cash Needed at Closing</span>
                                <span class="value" id="calc-cash-needed">$0</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mld-calc-actions">
                        <button class="mld-calc-btn-primary" id="calc-save">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                <polyline points="17 21 17 13 7 13 7 21"/>
                                <polyline points="7 3 7 8 15 8"/>
                            </svg>
                            Save Calculation
                        </button>
                        <button class="mld-calc-btn-secondary" id="calc-share">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <circle cx="18" cy="5" r="3"/>
                                <circle cx="6" cy="12" r="3"/>
                                <circle cx="18" cy="19" r="3"/>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                            </svg>
                            Share
                        </button>
                        <button class="mld-calc-btn-secondary" id="calc-print">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <polyline points="6 9 6 2 18 2 18 9"/>
                                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                                <rect x="6" y="14" width="12" height="8"/>
                            </svg>
                            Print
                        </button>
                    </div>
                </div>
            </div>
        `;

    this.elements.container.innerHTML = html;
    this.cacheElements();
  }

  cacheElements() {
    this.elements.priceInput = document.getElementById('calc-price');
    this.elements.downPercentInput = document.getElementById('calc-down-percent');
    this.elements.downAmountInput = document.getElementById('calc-down-amount');
    this.elements.rateInput = document.getElementById('calc-rate');
    this.elements.termSelect = document.getElementById('calc-term');
    this.elements.propertyTaxInput = document.getElementById('calc-property-tax');
    this.elements.insuranceInput = document.getElementById('calc-insurance');
    this.elements.hoaInput = document.getElementById('calc-hoa');

    // Results elements
    this.elements.totalPayment = document.getElementById('calc-total-payment');
    this.elements.principalInterest = document.getElementById('calc-pi');
    this.elements.propertyTax = document.getElementById('calc-tax');
    this.elements.insurance = document.getElementById('calc-ins');
    this.elements.pmiAmount = document.getElementById('calc-pmi-amount');
    this.elements.hoaAmount = document.getElementById('calc-hoa-amount');
    this.elements.pmiValue = document.getElementById('calc-pmi');
    this.elements.loanAmount = document.getElementById('calc-loan-amount');
    this.elements.totalInterest = document.getElementById('calc-total-interest');
    this.elements.totalPaid = document.getElementById('calc-total-paid');
    this.elements.closingCosts = document.getElementById('calc-closing');
    this.elements.cashNeeded = document.getElementById('calc-cash-needed');

    // PMI elements
    this.elements.pmiField = document.querySelector('.mld-calc-pmi-field');
    this.elements.pmiBreakdownItem = document.querySelector('.mld-calc-breakdown-item.pmi');

    // Action buttons
    this.elements.saveBtn = document.getElementById('calc-save');
    this.elements.shareBtn = document.getElementById('calc-share');
    this.elements.printBtn = document.getElementById('calc-print');

    // Chart
    this.elements.chartCanvas = document.getElementById('calc-chart-canvas');
  }

  bindEvents() {
    // Input events
    this.elements.priceInput?.addEventListener('input', () => this.handlePriceChange());
    this.elements.downPercentInput?.addEventListener('input', () => this.handleDownPercentChange());
    this.elements.downAmountInput?.addEventListener('input', () => this.handleDownAmountChange());
    this.elements.rateInput?.addEventListener('input', () => this.calculate());
    this.elements.termSelect?.addEventListener('change', () => this.calculate());
    this.elements.propertyTaxInput?.addEventListener('input', () => this.calculate());
    this.elements.insuranceInput?.addEventListener('input', () => this.calculate());
    this.elements.hoaInput?.addEventListener('input', () => this.calculate());

    // Action buttons
    this.elements.saveBtn?.addEventListener('click', () => this.saveCalculation());
    this.elements.shareBtn?.addEventListener('click', () => this.shareCalculation());
    this.elements.printBtn?.addEventListener('click', () => this.printCalculation());
  }

  handlePriceChange() {
    const price = parseFloat(this.elements.priceInput.value) || 0;
    const downPercent = parseFloat(this.elements.downPercentInput.value) || 0;
    const downAmount = (price * downPercent) / 100;

    this.elements.downAmountInput.value = Math.round(downAmount);

    // Update property tax and insurance estimates
    this.elements.propertyTaxInput.value = Math.round(
      (price * this.options.propertyTaxRate) / 100 / 12
    );
    this.elements.insuranceInput.value = Math.round(
      (price * this.options.homeInsuranceRate) / 100 / 12
    );

    this.calculate();
  }

  handleDownPercentChange() {
    const price = parseFloat(this.elements.priceInput.value) || 0;
    const downPercent = parseFloat(this.elements.downPercentInput.value) || 0;
    const downAmount = (price * downPercent) / 100;

    this.elements.downAmountInput.value = Math.round(downAmount);

    // Show/hide PMI
    const showPMI = downPercent < 20;
    this.elements.pmiField.style.display = showPMI ? '' : 'none';
    this.elements.pmiBreakdownItem.style.display = showPMI ? '' : 'none';

    this.calculate();
  }

  handleDownAmountChange() {
    const price = parseFloat(this.elements.priceInput.value) || 0;
    const downAmount = parseFloat(this.elements.downAmountInput.value) || 0;
    const downPercent = price > 0 ? (downAmount / price) * 100 : 0;

    this.elements.downPercentInput.value = downPercent.toFixed(1);

    // Show/hide PMI
    const showPMI = downPercent < 20;
    this.elements.pmiField.style.display = showPMI ? '' : 'none';
    this.elements.pmiBreakdownItem.style.display = showPMI ? '' : 'none';

    this.calculate();
  }

  calculate() {
    // Get input values
    const price = parseFloat(this.elements.priceInput.value) || 0;
    const downPercent = parseFloat(this.elements.downPercentInput.value) || 0;
    const rate = parseFloat(this.elements.rateInput.value) || 0;
    const term = parseInt(this.elements.termSelect.value) || 30;
    const propertyTax = parseFloat(this.elements.propertyTaxInput.value) || 0;
    const insurance = parseFloat(this.elements.insuranceInput.value) || 0;
    const hoa = parseFloat(this.elements.hoaInput.value) || 0;

    // Calculate loan details
    const downPayment = (price * downPercent) / 100;
    const loanAmount = price - downPayment;
    const monthlyRate = rate / 100 / 12;
    const numPayments = term * 12;

    // Calculate monthly payment
    let monthlyPayment = 0;
    if (monthlyRate > 0 && loanAmount > 0) {
      monthlyPayment =
        (loanAmount * (monthlyRate * Math.pow(1 + monthlyRate, numPayments))) /
        (Math.pow(1 + monthlyRate, numPayments) - 1);
    } else if (loanAmount > 0) {
      monthlyPayment = loanAmount / numPayments;
    }

    // Calculate PMI if needed
    let pmi = 0;
    if (downPercent < 20 && loanAmount > 0) {
      pmi = (loanAmount * this.options.pmiRate) / 100 / 12;
    }

    // Calculate totals
    const totalMonthlyPayment = monthlyPayment + propertyTax + insurance + pmi + hoa;
    const totalInterest = monthlyPayment * numPayments - loanAmount;
    const totalPaid = monthlyPayment * numPayments;
    const closingCosts = (price * this.options.closingCostRate) / 100;
    const cashNeeded = downPayment + closingCosts;

    // Update state
    this.state = {
      monthlyPayment,
      principalAndInterest: monthlyPayment,
      propertyTax,
      homeInsurance: insurance,
      pmi,
      hoaFees: hoa,
      totalMonthlyPayment,
      downPayment,
      loanAmount,
      totalInterest,
      totalPaid,
      closingCosts,
      cashNeeded,
    };

    // Update UI
    this.updateDisplay();

    // Update chart
    if (this.options.enableChart) {
      this.updateChart();
    }

    // Calculate amortization schedule
    this.calculateAmortizationSchedule();

    // Trigger callback
    if (this.options.onChange) {
      this.options.onChange(this.state);
    }
  }

  updateDisplay() {
    const formatter = new Intl.NumberFormat(this.options.locale, {
      style: 'currency',
      currency: this.options.currency,
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    });

    // Update payment breakdown
    this.elements.totalPayment.textContent = formatter.format(this.state.totalMonthlyPayment);
    this.elements.principalInterest.textContent = formatter.format(this.state.principalAndInterest);
    this.elements.propertyTax.textContent = formatter.format(this.state.propertyTax);
    this.elements.insurance.textContent = formatter.format(this.state.homeInsurance);
    this.elements.pmiAmount.textContent = formatter.format(this.state.pmi);
    this.elements.pmiValue.textContent = formatter.format(this.state.pmi) + '/mo';
    this.elements.hoaAmount.textContent = formatter.format(this.state.hoaFees);

    // Update summary
    this.elements.loanAmount.textContent = formatter.format(this.state.loanAmount);
    this.elements.totalInterest.textContent = formatter.format(this.state.totalInterest);
    this.elements.totalPaid.textContent = formatter.format(this.state.totalPaid);
    this.elements.closingCosts.textContent = formatter.format(this.state.closingCosts);
    this.elements.cashNeeded.textContent = formatter.format(this.state.cashNeeded);

    // Show/hide HOA in breakdown
    const hoaBreakdownItem = this.elements.hoaAmount.parentElement;
    hoaBreakdownItem.style.display = this.state.hoaFees > 0 ? '' : 'none';
  }

  updateChart() {
    if (!this.elements.chartCanvas) return;

    const ctx = this.elements.chartCanvas.getContext('2d');

    // Create data for pie chart
    const data = [
      { label: 'Principal & Interest', value: this.state.principalAndInterest, color: '#0066FF' },
      { label: 'Property Tax', value: this.state.propertyTax, color: '#10B981' },
      { label: 'Insurance', value: this.state.homeInsurance, color: '#F59E0B' },
    ];

    if (this.state.pmi > 0) {
      data.push({ label: 'PMI', value: this.state.pmi, color: '#EF4444' });
    }

    if (this.state.hoaFees > 0) {
      data.push({ label: 'HOA', value: this.state.hoaFees, color: '#8B5CF6' });
    }

    // Clear canvas
    const width = (this.elements.chartCanvas.width = this.elements.chartCanvas.offsetWidth * 2);
    const height = (this.elements.chartCanvas.height = this.elements.chartCanvas.offsetHeight * 2);
    ctx.scale(2, 2);

    // Draw pie chart
    const centerX = width / 4;
    const centerY = height / 4;
    const radius = Math.min(centerX, centerY) - 20;

    let startAngle = -Math.PI / 2;
    const total = data.reduce((sum, item) => sum + item.value, 0);

    data.forEach((item) => {
      if (item.value <= 0) return;

      const angle = (item.value / total) * Math.PI * 2;

      // Draw slice
      ctx.beginPath();
      ctx.moveTo(centerX, centerY);
      ctx.arc(centerX, centerY, radius, startAngle, startAngle + angle);
      ctx.closePath();
      ctx.fillStyle = item.color;
      ctx.fill();

      // Draw label
      const labelAngle = startAngle + angle / 2;
      const labelX = centerX + Math.cos(labelAngle) * (radius * 0.7);
      const labelY = centerY + Math.sin(labelAngle) * (radius * 0.7);

      ctx.fillStyle = 'white';
      ctx.font = '12px sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';

      const percentage = ((item.value / total) * 100).toFixed(0) + '%';
      ctx.fillText(percentage, labelX, labelY);

      startAngle += angle;
    });

    // Draw legend
    let legendY = 20;
    ctx.textAlign = 'left';

    data.forEach((item) => {
      if (item.value <= 0) return;

      // Color box
      ctx.fillStyle = item.color;
      ctx.fillRect(width / 2 + 20, legendY - 6, 12, 12);

      // Label
      ctx.fillStyle = '#374151';
      ctx.font = '12px sans-serif';
      ctx.fillText(item.label, width / 2 + 40, legendY);

      // Value
      const formatter = new Intl.NumberFormat(this.options.locale, {
        style: 'currency',
        currency: this.options.currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      });
      ctx.fillStyle = '#6B7280';
      ctx.fillText(formatter.format(item.value), width / 2 + 140, legendY);

      legendY += 20;
    });
  }

  calculateAmortizationSchedule() {
    const loanAmount = this.state.loanAmount;
    const monthlyRate = (parseFloat(this.elements.rateInput.value) || 0) / 100 / 12;
    const numPayments = (parseInt(this.elements.termSelect.value) || 30) * 12;

    this.state.amortizationSchedule = [];
    let balance = loanAmount;

    for (let i = 1; i <= numPayments; i++) {
      const interestPayment = balance * monthlyRate;
      const principalPayment = this.state.principalAndInterest - interestPayment;
      balance -= principalPayment;

      this.state.amortizationSchedule.push({
        month: i,
        payment: this.state.principalAndInterest,
        principal: principalPayment,
        interest: interestPayment,
        balance: Math.max(0, balance),
      });
    }
  }

  saveCalculation() {
    const data = {
      ...this.state,
      inputs: {
        price: parseFloat(this.elements.priceInput.value),
        downPercent: parseFloat(this.elements.downPercentInput.value),
        rate: parseFloat(this.elements.rateInput.value),
        term: parseInt(this.elements.termSelect.value),
        propertyTax: parseFloat(this.elements.propertyTaxInput.value),
        insurance: parseFloat(this.elements.insuranceInput.value),
        hoa: parseFloat(this.elements.hoaInput.value),
      },
      timestamp: new Date().toISOString(),
    };

    // Save to localStorage
    const saved = JSON.parse(localStorage.getItem('mld_calculations') || '[]');
    saved.push(data);
    localStorage.setItem('mld_calculations', JSON.stringify(saved));

    // Show success message
    this.showToast('Calculation saved!');
  }

  shareCalculation() {
    const url = new URL(window.location.href);
    url.searchParams.set('price', this.elements.priceInput.value);
    url.searchParams.set('down', this.elements.downPercentInput.value);
    url.searchParams.set('rate', this.elements.rateInput.value);
    url.searchParams.set('term', this.elements.termSelect.value);

    if (navigator.share) {
      navigator.share({
        title: 'Mortgage Calculator',
        text: `Monthly payment: ${this.elements.totalPayment.textContent}`,
        url: url.toString(),
      });
    } else {
      navigator.clipboard.writeText(url.toString());
      this.showToast('Link copied to clipboard!');
    }
  }

  printCalculation() {
    window.print();
  }

  showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'mld-calc-toast';
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
      toast.classList.add('show');
    }, 10);

    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => {
        document.body.removeChild(toast);
      }, 300);
    }, 3000);
  }

  destroy() {
    this.elements = {};
    this.state = {};
    this.chart = null;
  }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = MLDCalculator;
} else {
  window.MLDCalculator = MLDCalculator;
}
