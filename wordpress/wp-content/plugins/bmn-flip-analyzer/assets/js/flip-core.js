/**
 * FlipDashboard Core — Namespace and shared state.
 *
 * Loaded first. All other modules attach to window.FlipDashboard.
 */
window.FlipDashboard = {
    // Shared mutable state
    data: null,          // dashboard data (summary, results, cities) — set in flip-init.js
    chart: null,         // Chart.js instance — managed by flip-stats-chart.js
    activeReportId: null, // currently viewed report ID (null = latest/unsaved)

    // Module namespaces (populated by each file)
    helpers: {},
    stats: {},
    filters: {},
    detail: {},
    projections: {},
    ajax: {},
    analysisFilters: {},
    cities: {},
    reports: {},
    scoringWeights: {},
    rental: {},
};
