/**
 * MLD Schools Compare & Trends Handler
 * School comparison and historical trend visualization
 *
 * @package MLS_Listings_Display
 * @since 6.54.0
 */
(function() {
    'use strict';

    // Configuration from WordPress
    const CONFIG = {
        apiBase: window.mldSchoolsConfig?.apiBase || '/wp-json/bmn-schools/v1',
        nonce: window.mldSchoolsConfig?.nonce || '',
        maxCompare: 5,
        minCompare: 2
    };

    // State
    const state = {
        selectedSchools: new Map(), // id -> {id, name, level}
        trendsCharts: [], // Chart.js instances
        currentTrendsSchool: null,
        currentTrendsData: null,
        currentSubject: 'all'
    };

    // Subject colors
    const SUBJECT_COLORS = {
        ela: { bg: 'rgba(59, 130, 246, 0.1)', border: '#3b82f6' },
        math: { bg: 'rgba(249, 115, 22, 0.1)', border: '#f97316' },
        science: { bg: 'rgba(34, 197, 94, 0.1)', border: '#22c55e' }
    };

    // Grade colors for comparison table
    const GRADE_COLORS = {
        'A+': '#15803d', 'A': '#22c55e', 'A-': '#4ade80',
        'B+': '#1d4ed8', 'B': '#3b82f6', 'B-': '#60a5fa',
        'C+': '#ca8a04', 'C': '#eab308', 'C-': '#facc15',
        'D+': '#c2410c', 'D': '#f97316', 'D-': '#fb923c',
        'F': '#dc2626'
    };

    /**
     * Initialize
     */
    function init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setup);
        } else {
            setup();
        }
    }

    /**
     * Set up event listeners
     */
    function setup() {
        // Checkbox selection for comparison
        document.addEventListener('change', handleCheckboxChange);

        // Trends button clicks
        document.addEventListener('click', handleClick);

        // Compare button
        const compareBtn = document.getElementById('mld-compare-btn');
        if (compareBtn) {
            compareBtn.addEventListener('click', openComparisonModal);
        }

        // Clear selection button
        const clearBtn = document.getElementById('mld-compare-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', clearSelection);
        }

        // Modal close handlers
        setupModalCloseHandlers();

        // Subject filter for trends
        setupSubjectFilter();

        // Keyboard handler for modals
        document.addEventListener('keydown', handleKeydown);
    }

    /**
     * Handle checkbox changes for school selection
     */
    function handleCheckboxChange(e) {
        if (!e.target.classList.contains('mld-compare-check')) return;

        const checkbox = e.target;
        const card = checkbox.closest('.mld-school-card');
        if (!card) return;

        const schoolId = parseInt(checkbox.value, 10);
        const schoolName = card.dataset.schoolName;
        const schoolLevel = card.dataset.schoolLevel;

        if (checkbox.checked) {
            if (state.selectedSchools.size >= CONFIG.maxCompare) {
                checkbox.checked = false;
                showToast(`Maximum ${CONFIG.maxCompare} schools for comparison`);
                return;
            }
            state.selectedSchools.set(schoolId, { id: schoolId, name: schoolName, level: schoolLevel });
            card.classList.add('mld-selected');
        } else {
            state.selectedSchools.delete(schoolId);
            card.classList.remove('mld-selected');
        }

        updateCompareBar();
    }

    /**
     * Handle click events
     */
    function handleClick(e) {
        // Trends button
        const trendsBtn = e.target.closest('.mld-trends-btn');
        if (trendsBtn) {
            e.preventDefault();
            e.stopPropagation();
            const schoolId = parseInt(trendsBtn.dataset.schoolId, 10);
            const schoolName = trendsBtn.dataset.schoolName;
            openTrendsModal(schoolId, schoolName);
            return;
        }

        // Modal overlay close
        if (e.target.classList.contains('mld-modal-overlay')) {
            closeAllModals();
            return;
        }

        // Modal close button
        if (e.target.classList.contains('mld-modal-close')) {
            closeAllModals();
            return;
        }

        // Retry buttons
        if (e.target.classList.contains('mld-retry-btn')) {
            const modal = e.target.closest('.mld-comparison-modal, .mld-trends-modal');
            if (modal?.id === 'mld-comparison-modal') {
                loadComparisonData();
            } else if (modal?.id === 'mld-trends-modal' && state.currentTrendsSchool) {
                loadTrendsData(state.currentTrendsSchool.id);
            }
            return;
        }
    }

    /**
     * Update the compare bar visibility and state
     */
    function updateCompareBar() {
        const bar = document.getElementById('mld-compare-bar');
        const btn = document.getElementById('mld-compare-btn');
        const countSpan = bar?.querySelector('.mld-compare-count');

        if (!bar) return;

        const count = state.selectedSchools.size;

        if (count === 0) {
            bar.style.display = 'none';
        } else {
            bar.style.display = 'flex';
            countSpan.textContent = `${count} school${count !== 1 ? 's' : ''} selected`;
            btn.disabled = count < CONFIG.minCompare;
        }
    }

    /**
     * Clear all selections
     */
    function clearSelection() {
        state.selectedSchools.clear();
        document.querySelectorAll('.mld-compare-check').forEach(cb => {
            cb.checked = false;
        });
        document.querySelectorAll('.mld-school-card.mld-selected').forEach(card => {
            card.classList.remove('mld-selected');
        });
        updateCompareBar();
    }

    /**
     * Set up modal close handlers
     */
    function setupModalCloseHandlers() {
        // Move modals to body for proper positioning
        ['mld-comparison-modal', 'mld-trends-modal'].forEach(id => {
            const modal = document.getElementById(id);
            if (modal && modal.parentNode !== document.body) {
                document.body.appendChild(modal);
            }
        });
    }

    /**
     * Set up subject filter for trends
     */
    function setupSubjectFilter() {
        const container = document.querySelector('.mld-subject-filter');
        if (!container) return;

        container.addEventListener('click', (e) => {
            const btn = e.target.closest('.mld-subject-btn');
            if (!btn) return;

            const subject = btn.dataset.subject;
            state.currentSubject = subject;

            // Update active state
            container.querySelectorAll('.mld-subject-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            // Re-render charts with filter
            if (state.currentTrendsData) {
                renderTrendsCharts(state.currentTrendsData);
            }
        });
    }

    /**
     * Handle keyboard events
     */
    function handleKeydown(e) {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    }

    /**
     * Close all modals
     */
    function closeAllModals() {
        ['mld-comparison-modal', 'mld-trends-modal'].forEach(id => {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('visible');
                modal.setAttribute('aria-hidden', 'true');
            }
        });
        document.body.style.overflow = '';

        // Destroy charts when closing trends modal
        destroyTrendsCharts();
    }

    /**
     * Open comparison modal
     */
    function openComparisonModal() {
        const modal = document.getElementById('mld-comparison-modal');
        if (!modal) return;

        showModalElement(modal);
        loadComparisonData();
    }

    /**
     * Load comparison data from API
     */
    async function loadComparisonData() {
        const modal = document.getElementById('mld-comparison-modal');
        if (!modal) return;

        const loading = modal.querySelector('.mld-comparison-loading');
        const error = modal.querySelector('.mld-comparison-error');
        const body = modal.querySelector('.mld-comparison-body');

        loading.style.display = 'flex';
        error.style.display = 'none';
        body.style.display = 'none';

        const ids = Array.from(state.selectedSchools.keys());

        try {
            const response = await fetch(`${CONFIG.apiBase}/schools/compare?ids=${ids.join(',')}`, {
                headers: {
                    'X-WP-Nonce': CONFIG.nonce
                }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const json = await response.json();
            if (!json.success || !json.data) throw new Error('Invalid response');

            loading.style.display = 'none';
            body.style.display = 'block';
            renderComparisonTable(json.data);

        } catch (err) {
            console.error('Comparison load error:', err);
            loading.style.display = 'none';
            error.style.display = 'flex';
            error.querySelector('.mld-error-message').textContent = 'Failed to load comparison data';
        }
    }

    /**
     * Render comparison table
     */
    function renderComparisonTable(data) {
        const header = document.getElementById('mld-comparison-header');
        const body = document.getElementById('mld-comparison-body');
        if (!header || !body) return;

        const schools = data.schools || [];
        if (schools.length === 0) return;

        // Build header row
        let headerHtml = '<tr><th class="mld-comparison-label"></th>';
        schools.forEach(school => {
            const levelIcon = getLevelIcon(school.level);
            headerHtml += `
                <th class="mld-comparison-school-header">
                    <div class="mld-school-header-icon">${levelIcon}</div>
                    <div class="mld-school-header-name">${escapeHtml(school.name)}</div>
                    <div class="mld-school-header-level">${escapeHtml(school.level || '')}</div>
                </th>
            `;
        });
        headerHtml += '</tr>';
        header.innerHTML = headerHtml;

        // Build body rows
        const rows = [
            { label: 'Rating', section: true },
            { label: 'Letter Grade', key: 'letter_grade', render: renderGradeBadge },
            { label: 'Composite Score', key: 'composite_score', render: v => v ? v.toFixed(1) : 'N/A' },
            { label: 'Basic Info', section: true },
            { label: 'Type', key: 'type', render: v => v || 'N/A' },
            { label: 'Grades', key: 'grades', render: v => v || 'N/A' },
            { label: 'City', key: 'city', render: v => v || 'N/A' },
            { label: 'Demographics', section: true },
            { label: 'Enrollment', key: 'demographics.enrollment', render: v => v ? v.toLocaleString() : 'N/A' },
            { label: 'Student:Teacher', key: 'student_teacher_ratio', render: v => v ? `${Math.round(v)}:1` : 'N/A' },
            { label: 'Avg Class Size', key: 'demographics.avg_class_size', render: v => v ? Math.round(v).toString() : 'N/A' },
            { label: 'Free/Reduced Lunch', key: 'demographics.free_reduced_lunch_pct', render: v => v != null ? `${Math.round(v)}%` : 'N/A' },
            { label: 'Test Scores (MCAS)', section: true },
            { label: 'ELA Proficient', key: 'test_scores.English Language Arts.proficient_pct', render: renderTestScore },
            { label: 'Math Proficient', key: 'test_scores.Mathematics.proficient_pct', render: renderTestScore },
            { label: 'Science Proficient', key: 'test_scores.Science.proficient_pct', render: renderTestScore },
            { label: 'State Ranking', section: true },
            { label: 'Percentile', key: 'ranking.percentile_rank', render: v => v ? `${v}th` : 'N/A' },
            { label: 'State Rank', key: 'ranking.state_rank', render: v => v ? `#${v}` : 'N/A' }
        ];

        let bodyHtml = '';
        rows.forEach(row => {
            if (row.section) {
                bodyHtml += `<tr class="mld-comparison-section"><td colspan="${schools.length + 1}">${escapeHtml(row.label)}</td></tr>`;
            } else {
                bodyHtml += `<tr><td class="mld-comparison-label">${escapeHtml(row.label)}</td>`;
                schools.forEach(school => {
                    const value = getNestedValue(school, row.key);
                    const rendered = row.render ? row.render(value) : (value ?? 'N/A');
                    bodyHtml += `<td class="mld-comparison-value">${rendered}</td>`;
                });
                bodyHtml += '</tr>';
            }
        });
        body.innerHTML = bodyHtml;
    }

    /**
     * Open trends modal
     */
    function openTrendsModal(schoolId, schoolName) {
        const modal = document.getElementById('mld-trends-modal');
        if (!modal) return;

        state.currentTrendsSchool = { id: schoolId, name: schoolName };
        state.currentSubject = 'all';

        // Reset subject filter
        const subjectBtns = modal.querySelectorAll('.mld-subject-btn');
        subjectBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.subject === 'all');
        });

        // Update title
        const titleSpan = document.getElementById('mld-trends-school-name');
        if (titleSpan) {
            titleSpan.textContent = schoolName;
        }

        showModalElement(modal);
        loadTrendsData(schoolId);
    }

    /**
     * Load trends data from API
     */
    async function loadTrendsData(schoolId) {
        const modal = document.getElementById('mld-trends-modal');
        if (!modal) return;

        const loading = modal.querySelector('.mld-trends-loading');
        const error = modal.querySelector('.mld-trends-error');
        const body = modal.querySelector('.mld-trends-body');

        loading.style.display = 'flex';
        error.style.display = 'none';
        body.style.display = 'none';

        try {
            const response = await fetch(`${CONFIG.apiBase}/schools/${schoolId}/trends`, {
                headers: {
                    'X-WP-Nonce': CONFIG.nonce
                }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const json = await response.json();
            if (!json.success || !json.data) throw new Error('Invalid response');

            state.currentTrendsData = json.data;

            loading.style.display = 'none';
            body.style.display = 'block';
            renderTrendsCharts(json.data);

        } catch (err) {
            console.error('Trends load error:', err);
            loading.style.display = 'none';
            error.style.display = 'flex';
            error.querySelector('.mld-error-message').textContent = 'Failed to load trend data';
        }
    }

    /**
     * Render trends charts
     */
    function renderTrendsCharts(data) {
        const container = document.getElementById('mld-trends-charts');
        if (!container) return;

        // Destroy existing charts
        destroyTrendsCharts();

        // Convert trends object to array format
        // API returns: { "English Language Arts": {...}, "Mathematics": {...} }
        // We need: [ { subject: "ELA", ...}, { subject: "Math", ...} ]
        const trendsObj = data.trends || {};
        const subjectMap = {
            'English Language Arts': 'ela',
            'Mathematics': 'math',
            'Science': 'science',
            'CIV': 'civ'
        };

        const trends = Object.entries(trendsObj).map(([fullName, trendData]) => ({
            subject: subjectMap[fullName] || fullName.toLowerCase(),
            fullName: fullName,
            ...trendData
        })).filter(t => ['ela', 'math', 'science'].includes(t.subject)); // Filter to main subjects

        state.currentTrendsData = trends;

        if (trends.length === 0) {
            container.innerHTML = `
                <div class="mld-no-trends">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="40" height="40">
                        <line x1="12" y1="20" x2="12" y2="10"></line>
                        <line x1="18" y1="20" x2="18" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="16"></line>
                    </svg>
                    <p>No trend data available</p>
                </div>
            `;
            return;
        }

        // Filter by subject if needed
        const filteredTrends = state.currentSubject === 'all'
            ? trends
            : trends.filter(t => t.subject === state.currentSubject);

        if (filteredTrends.length === 0) {
            container.innerHTML = `
                <div class="mld-no-trends">
                    <p>No ${state.currentSubject.toUpperCase()} data available</p>
                </div>
            `;
            return;
        }

        container.innerHTML = '';

        filteredTrends.forEach((trend, index) => {
            const chartWrapper = document.createElement('div');
            chartWrapper.className = 'mld-trend-card';

            const subject = trend.subject;
            const colors = SUBJECT_COLORS[subject] || SUBJECT_COLORS.ela;
            const trendDir = trend.trend || 'stable';
            const trendIcon = trendDir === 'up' ? '&#8593;' : (trendDir === 'down' ? '&#8595;' : '&#8594;');
            const trendColor = trendDir === 'up' ? '#22c55e' : (trendDir === 'down' ? '#ef4444' : '#6b7280');
            const displayName = { ela: 'English Language Arts', math: 'Mathematics', science: 'Science' }[subject] || trend.fullName;

            chartWrapper.innerHTML = `
                <div class="mld-trend-header">
                    <span class="mld-trend-subject" style="color: ${colors.border}">${displayName}</span>
                    <span class="mld-trend-badge" style="background: ${trendColor}20; color: ${trendColor}">
                        ${trendIcon} ${capitalize(trendDir)}
                    </span>
                </div>
                <div class="mld-trend-chart-container">
                    <canvas id="mld-trend-chart-${index}"></canvas>
                </div>
                <div class="mld-trend-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Proficient</th>
                                <th>Tested</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${(trend.data || []).slice().reverse().map(d => `
                                <tr>
                                    <td>${d.year}</td>
                                    <td>${d.proficient_pct != null ? Math.round(d.proficient_pct) + '%' : 'N/A'}</td>
                                    <td>${d.tested != null ? d.tested.toLocaleString() : 'N/A'}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;

            container.appendChild(chartWrapper);

            // Create Chart.js chart
            const canvas = document.getElementById(`mld-trend-chart-${index}`);
            if (canvas && window.Chart) {
                const chartData = (trend.data || []).filter(d => d.proficient_pct != null);
                const chart = new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: chartData.map(d => d.year.toString()),
                        datasets: [{
                            label: 'Proficient %',
                            data: chartData.map(d => d.proficient_pct),
                            borderColor: colors.border,
                            backgroundColor: colors.bg,
                            fill: true,
                            tension: 0.3,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                min: 0,
                                max: 100,
                                ticks: {
                                    callback: value => value + '%'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: context => `${Math.round(context.raw)}% Proficient`
                                }
                            }
                        }
                    }
                });
                state.trendsCharts.push(chart);
            }
        });
    }

    /**
     * Destroy all trends charts
     */
    function destroyTrendsCharts() {
        state.trendsCharts.forEach(chart => {
            try { chart.destroy(); } catch (e) {}
        });
        state.trendsCharts = [];
    }

    /**
     * Show a modal element
     */
    function showModalElement(modal) {
        modal.classList.add('visible');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        // Focus close button
        const closeBtn = modal.querySelector('.mld-modal-close');
        if (closeBtn) {
            setTimeout(() => closeBtn.focus(), 100);
        }
    }

    /**
     * Render grade badge HTML
     */
    function renderGradeBadge(grade) {
        if (!grade) return '<span class="mld-grade-na">N/A</span>';
        const color = GRADE_COLORS[grade] || '#6b7280';
        return `<span class="mld-grade-badge-small" style="background: ${color}">${escapeHtml(grade)}</span>`;
    }

    /**
     * Render test score with color
     */
    function renderTestScore(value) {
        if (value == null) return '<span class="mld-score-na">N/A</span>';
        const pct = Math.round(value);
        let color = '#ef4444'; // red
        if (pct >= 70) color = '#22c55e'; // green
        else if (pct >= 50) color = '#3b82f6'; // blue
        else if (pct >= 30) color = '#f97316'; // orange
        return `<span style="color: ${color}; font-weight: 500;">${pct}%</span>`;
    }

    /**
     * Get level icon SVG
     */
    function getLevelIcon(level) {
        const l = (level || '').toLowerCase();
        if (l.includes('elementary')) {
            return '<svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" style="color: #22c55e"><path d="M12 3L1 9l11 6l11-6l-11-6z"/><path d="M1 9v8l11 6l11-6V9"/></svg>';
        } else if (l.includes('middle')) {
            return '<svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" style="color: #3b82f6"><path d="M12 3L1 9l11 6l11-6l-11-6z"/><path d="M1 9v8l11 6l11-6V9"/></svg>';
        } else if (l.includes('high')) {
            return '<svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" style="color: #a855f7"><path d="M12 3L1 9l11 6l11-6l-11-6z"/><path d="M1 9v8l11 6l11-6V9"/></svg>';
        }
        return '<svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" style="color: #0891b2"><path d="M12 3L1 9l11 6l11-6l-11-6z"/></svg>';
    }

    /**
     * Get nested value from object using dot notation
     * Supports keys with spaces (e.g., "test_scores.English Language Arts.proficient_pct")
     */
    function getNestedValue(obj, path) {
        if (!path) return null;
        return path.split('.').reduce((acc, part) => acc?.[part], obj);
    }

    /**
     * Show toast message
     */
    function showToast(message) {
        // Simple toast - can be enhanced later
        const existing = document.querySelector('.mld-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = 'mld-toast';
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => toast.classList.add('visible'), 10);
        setTimeout(() => {
            toast.classList.remove('visible');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Capitalize first letter
     */
    function capitalize(str) {
        return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
    }

    // Initialize
    init();

})();
