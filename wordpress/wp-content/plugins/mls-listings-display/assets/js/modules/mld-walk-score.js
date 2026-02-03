/**
 * MLD Walk Score Module
 * Integrates Walk Score API for walkability, transit, and bike scores
 *
 * @version 1.0.0
 */

class MLDWalkScore {
  constructor(options = {}) {
    this.options = {
      apiKey: options.apiKey || '',
      container: options.container || null,
      address: options.address || '',
      lat: options.lat || null,
      lng: options.lng || null,
      enableTransit: options.enableTransit !== false,
      enableBike: options.enableBike !== false,
      showNearby: false,
      theme: options.theme || 'modern', // 'modern', 'classic', 'minimal'
      onScoreLoad: options.onScoreLoad || null,
      onError: options.onError || null,
    };

    this.scores = {
      walk: null,
      transit: null,
      bike: null,
    };

    this.nearby = [];
    this.isLoading = false;
    this.container = null;

    if (this.options.container) {
      this.init();
    }
  }

  init() {
    // Get container element
    this.container =
      typeof this.options.container === 'string'
        ? document.querySelector(this.options.container)
        : this.options.container;

    if (!this.container) {
      MLDLogger.error('Walk Score container not found');
      return;
    }

    // Validate required data
    if (!this.options.lat || !this.options.lng) {
      MLDLogger.error('Coordinates are required for Walk Score');
      this.showError('Location data missing');
      return;
    }

    // Show loading state
    this.showLoading();

    // Fetch scores
    this.fetchScores();
  }

  async fetchScores() {
    this.isLoading = true;

    try {
      // Use AJAX to call server-side endpoint
      const ajaxUrl = window.mldPropertyData?.ajaxUrl || '/wp-admin/admin-ajax.php';
      const nonce = window.mldPropertyData?.nonce || '';

      const formData = new FormData();
      formData.append('action', 'get_walk_score');
      formData.append('nonce', nonce);
      formData.append('address', this.options.address);
      formData.append('lat', this.options.lat);
      formData.append('lng', this.options.lng);
      formData.append('transit', this.options.enableTransit ? '1' : '0');
      formData.append('bike', this.options.enableBike ? '1' : '0');

      const response = await fetch(ajaxUrl, {
        method: 'POST',
        body: formData,
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();

      if (!result.success) {
        throw new Error(result.data || 'Failed to get Walk Score');
      }

      const data = result.data;

      if (data.status === 1) {
        // Success - extract scores
        this.scores = {
          walk: data.walkscore || null,
          transit: data.transit?.score || null,
          bike: data.bike?.score || null,
        };

        // Render the scores
        this.render();

        // Callback
        if (this.options.onScoreLoad) {
          this.options.onScoreLoad(this.scores);
        }
      } else {
        throw new Error(data.description || 'Failed to get Walk Score');
      }
    } catch (error) {
      MLDLogger.error('Walk Score error:', error);
      this.showError(error.message);

      if (this.options.onError) {
        this.options.onError(error);
      }
    } finally {
      this.isLoading = false;
    }
  }

  render() {
    if (!this.container) return;

    const themes = {
      modern: this.renderModernTheme(),
      classic: this.renderClassicTheme(),
      minimal: this.renderMinimalTheme(),
    };

    this.container.innerHTML = themes[this.options.theme] || themes.modern;
    this.attachEventListeners();
  }

  renderModernTheme() {
    return `
            <div class="mld-walk-score-modern">
                <div class="mld-ws-header">
                    <h3 class="mld-ws-title">Walkability & Transit</h3>
                    <a href="https://www.walkscore.com/how-it-works/" target="_blank" rel="noopener" class="mld-ws-info-link">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M8 5V8M8 11H8.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </a>
                </div>
                
                <div class="mld-ws-scores">
                    ${this.renderScoreCard('walk', 'Walk Score', '🚶', this.scores.walk)}
                    ${this.options.enableTransit ? this.renderScoreCard('transit', 'Transit Score', '🚌', this.scores.transit) : ''}
                    ${this.options.enableBike ? this.renderScoreCard('bike', 'Bike Score', '🚴', this.scores.bike) : ''}
                </div>
                
                
                <div class="mld-ws-footer">
                    <img src="https://cdn.walk.sc/images/api-logo.png" alt="Walk Score" class="mld-ws-logo">
                    <a href="https://www.walkscore.com" target="_blank" rel="noopener" class="mld-ws-link">Learn more at walkscore.com</a>
                </div>
            </div>
        `;
  }

  renderScoreCard(type, label, icon, score) {
    const rating = this.getScoreRating(score);
    const color = this.getScoreColor(score);

    return `
            <div class="mld-ws-score-card ${score === null ? 'unavailable' : ''}" data-score-type="${type}">
                <div class="mld-ws-score-icon">${icon}</div>
                <div class="mld-ws-score-details">
                    <div class="mld-ws-score-label">${label}</div>
                    ${
                      score !== null
                        ? `
                        <div class="mld-ws-score-value" style="color: ${color}">${score}</div>
                        <div class="mld-ws-score-rating">${rating}</div>
                    `
                        : `
                        <div class="mld-ws-score-unavailable">Not available</div>
                    `
                    }
                </div>
                ${
                  score !== null
                    ? `
                    <div class="mld-ws-score-gauge">
                        <div class="mld-ws-score-gauge-fill" style="width: ${score}%; background-color: ${color}"></div>
                    </div>
                `
                    : ''
                }
            </div>
        `;
  }

  renderClassicTheme() {
    return `
            <div class="mld-walk-score-classic">
                <table class="mld-ws-table">
                    <thead>
                        <tr>
                            <th colspan="3">Walk Score® Ratings</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${this.renderClassicRow('Walk Score', this.scores.walk)}
                        ${this.options.enableTransit ? this.renderClassicRow('Transit Score', this.scores.transit) : ''}
                        ${this.options.enableBike ? this.renderClassicRow('Bike Score', this.scores.bike) : ''}
                    </tbody>
                </table>
                <div class="mld-ws-classic-footer">
                    <a href="https://www.walkscore.com" target="_blank" rel="noopener">
                        <img src="https://cdn.walk.sc/images/api-logo-small.png" alt="Walk Score">
                    </a>
                </div>
            </div>
        `;
  }

  renderClassicRow(label, score) {
    const rating = this.getScoreRating(score);
    const color = this.getScoreColor(score);

    return `
            <tr>
                <td class="mld-ws-classic-label">${label}</td>
                <td class="mld-ws-classic-score" style="color: ${color}">
                    ${score !== null ? score : 'N/A'}
                </td>
                <td class="mld-ws-classic-rating">${score !== null ? rating : ''}</td>
            </tr>
        `;
  }

  renderMinimalTheme() {
    const walkScore = this.scores.walk;
    if (walkScore === null) {
      return '<div class="mld-walk-score-minimal unavailable">Walk Score not available</div>';
    }

    const color = this.getScoreColor(walkScore);
    const rating = this.getScoreRating(walkScore);

    return `
            <div class="mld-walk-score-minimal">
                <span class="mld-ws-minimal-label">Walk Score®</span>
                <span class="mld-ws-minimal-score" style="color: ${color}">${walkScore}</span>
                <span class="mld-ws-minimal-rating">${rating}</span>
            </div>
        `;
  }

  showLoading() {
    if (!this.container) return;

    this.container.innerHTML = `
            <div class="mld-walk-score-loading">
                <div class="mld-ws-spinner"></div>
                <p>Loading walkability scores...</p>
            </div>
        `;
  }

  showError(message) {
    if (!this.container) return;

    this.container.innerHTML = `
            <div class="mld-walk-score-error">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 8V12M12 16H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <p>Unable to load Walk Score</p>
                <small>${message}</small>
            </div>
        `;
  }

  getScoreRating(score) {
    if (score === null) return '';
    if (score >= 90) return "Walker's Paradise";
    if (score >= 70) return 'Very Walkable';
    if (score >= 50) return 'Somewhat Walkable';
    if (score >= 25) return 'Car-Dependent';
    return 'Car-Dependent';
  }

  getScoreColor(score) {
    if (score === null) return '#9CA3AF';
    if (score >= 90) return '#10B981'; // Green
    if (score >= 70) return '#3B82F6'; // Blue
    if (score >= 50) return '#F59E0B'; // Yellow
    if (score >= 25) return '#F97316'; // Orange
    return '#EF4444'; // Red
  }

  attachEventListeners() {
    // Score card hover effects
    const scoreCards = this.container.querySelectorAll('.mld-ws-score-card');
    scoreCards.forEach((card) => {
      card.addEventListener('mouseenter', () => {
        card.classList.add('hover');
      });
      card.addEventListener('mouseleave', () => {
        card.classList.remove('hover');
      });
    });

    // Info link tooltip
    const infoLink = this.container.querySelector('.mld-ws-info-link');
    if (infoLink) {
      infoLink.addEventListener('click', (e) => {
        e.preventDefault();
        window.open('https://www.walkscore.com/how-it-works/', '_blank');
      });
    }
  }

  // Public methods
  update(options) {
    Object.assign(this.options, options);
    this.init();
  }

  getScores() {
    return this.scores;
  }

  destroy() {
    if (this.container) {
      this.container.innerHTML = '';
    }
    this.scores = { walk: null, transit: null, bike: null };
    this.nearby = [];
  }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = MLDWalkScore;
} else {
  window.MLDWalkScore = MLDWalkScore;
}
