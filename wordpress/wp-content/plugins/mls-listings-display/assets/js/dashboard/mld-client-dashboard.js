/**
 * MLD Client Dashboard - Vue.js 3 Application
 *
 * Client-facing dashboard for saved searches, favorites, agent info,
 * and email preferences.
 *
 * @package MLS_Listings_Display
 * @since 6.32.1
 */

(function() {
    'use strict';

    // Wait for Vue to be available
    if (typeof Vue === 'undefined') {
        console.error('MLD Client Dashboard: Vue.js not loaded');
        return;
    }

    // Initialize function
    function initDashboard() {
        try {
            const appElement = document.getElementById('mld-client-dashboard');
            if (!appElement) {
                console.error('MLD Dashboard: App element #mld-client-dashboard not found');
                return;
            }

            const config = window.mldDashboardConfig || {};
            const { createApp, ref, reactive, computed, onMounted, watch } = Vue;

        const app = createApp({
            setup() {
                // State
                const loading = ref(true);
                const currentTab = ref('overview');
                const user = reactive(config.user || {});
                const agent = ref(config.agent || null);
                const isAgent = ref(config.isAgent || false);
                const userType = ref(config.userType || 'client');

                // Data
                const savedSearches = ref([]);
                const favorites = ref([]);
                const hiddenProperties = ref([]);
                const hiddenLoading = ref(false);
                const recentActivity = ref([]);

                // Appointments data
                const appointments = ref([]);
                const appointmentsLoading = ref(false);
                const showCancelModal = ref(false);
                const showRescheduleModal = ref(false);
                const appointmentToCancel = ref(null);
                const appointmentToReschedule = ref(null);
                const rescheduleDate = ref('');
                const rescheduleSlots = ref([]);
                const rescheduleSlotsLoading = ref(false);
                const selectedSlot = ref(null);

                // Agent-specific data (My Clients)
                const clients = ref([]);
                const clientsLoading = ref(false);
                const selectedClient = ref(null);
                const clientSearches = ref([]);
                const clientFavorites = ref([]);
                const clientHidden = ref([]);
                const agentMetrics = ref(null);

                // v6.40.0 - Client Insights (Analytics)
                const clientAnalytics = ref([]);
                const clientAnalyticsLoading = ref(false);
                const clientAnalyticsSummary = ref(null);
                const selectedClientInsights = ref(null);
                const selectedClientPropertyInterests = ref([]);
                const selectedClientTimeline = ref([]);
                const selectedClientMostViewed = ref([]);
                const selectedClientPreferences = ref(null);
                const insightsDetailLoading = ref(false);
                const insightsSortBy = ref('engagement_score');
                const insightsSortOrder = ref('desc');

                // v6.52.0 - Agent Referral Link System
                const referralData = ref(null);
                const referralLoading = ref(false);
                const referralCopied = ref(false);

                // Shared Properties (From My Agent) - for clients
                const sharedProperties = ref([]);
                const sharedLoading = ref(false);
                const sharedUnviewedCount = ref(0);

                // Create client form
                const showCreateClientModal = ref(false);
                const newClient = reactive({
                    email: '',
                    first_name: '',
                    last_name: '',
                    phone: '',
                    send_notification: true
                });

                // Share Property Modal (for agents)
                const showSharePropertyModal = ref(false);
                const shareModalMode = ref('select-client'); // 'select-client' or 'select-property'
                const shareTargetClient = ref(null);
                const shareTargetProperty = ref(null);
                const shareNote = ref('');
                const shareSending = ref(false);
                const propertySearchQuery = ref('');
                const propertySearchResults = ref([]);
                const propertySearchLoading = ref(false);
                const emailPrefs = reactive({
                    digest_enabled: false,
                    digest_frequency: 'daily',
                    digest_time: '09:00:00',
                    preferred_format: 'html',
                    global_pause: false,
                    timezone: 'America/New_York'
                });
                const emailStats = ref(null);

                // UI State
                const showDeleteModal = ref(false);
                const searchToDelete = ref(null);

                // Account Deletion (Apple App Store Guideline 5.1.1(v) compliance)
                const showDeleteAccountModal = ref(false);
                const deleteAccountConfirmText = ref('');
                const deleteAccountLoading = ref(false);
                const deleteAccountError = ref(null);

                const toast = reactive({
                    show: false,
                    message: '',
                    type: 'success'
                });

                // Config
                const homeUrl = config.homeUrl || '';
                const searchUrl = config.searchUrl || '/search/';
                const snabRestUrl = config.snabRestUrl || '/wp-json/snab/v1/';
                const bookingUrl = config.bookingUrl || '/book-appointment/';

                // Tabs configuration - different tabs for agents vs clients
                const tabs = computed(() => {
                    const baseTabs = [
                        { id: 'overview', label: 'Overview', icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>' }
                    ];

                    // Icons
                    const eyeSlashIcon = '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/></svg>';
                    const calendarIcon = '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>';
                    const settingsIcon = '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>';

                    if (isAgent.value) {
                        // Agent tabs: Overview, My Clients, Client Insights, Team Searches, Favorites, Hidden, Appointments, Settings
                        const chartIcon = '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg>';
                        const highlyEngaged = clientAnalyticsSummary.value?.highly_engaged || 0;

                        baseTabs.push(
                            { id: 'clients', label: 'My Clients', icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>', badge: clients.value.length || null },
                            { id: 'insights', label: 'Client Insights', icon: chartIcon, badge: highlyEngaged || null },
                            { id: 'searches', label: 'Team Searches', icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>' },
                            { id: 'favorites', label: 'Favorites', icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd"/></svg>', badge: favorites.value.length || null },
                            { id: 'hidden', label: 'Hidden', icon: eyeSlashIcon, badge: hiddenProperties.value.length || null },
                            { id: 'appointments', label: 'Appointments', icon: calendarIcon, badge: upcomingAppointments.value.length || null },
                            { id: 'settings', label: 'Settings', icon: settingsIcon }
                        );
                    } else {
                        // Client tabs: Overview, Saved Searches, Favorites, Hidden, Appointments, From Agent, My Agent, Settings
                        const giftIcon = '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M5 5a3 3 0 015-2.236A3 3 0 0114.83 6H16a2 2 0 110 4h-5V9a1 1 0 10-2 0v1H4a2 2 0 110-4h1.17C5.06 5.687 5 5.35 5 5zm4 1V5a1 1 0 10-1 1h1zm3 0a1 1 0 10-1-1v1h1z" clip-rule="evenodd"/><path d="M9 11H3v5a2 2 0 002 2h4v-7zM11 18h4a2 2 0 002-2v-5h-6v7z"/></svg>';
                        baseTabs.push(
                            { id: 'searches', label: 'Saved Searches', icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>', badge: savedSearches.value.length || null },
                            { id: 'favorites', label: 'Favorites', icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd"/></svg>', badge: favorites.value.length || null },
                            { id: 'hidden', label: 'Hidden', icon: eyeSlashIcon, badge: hiddenProperties.value.length || null },
                            { id: 'appointments', label: 'Appointments', icon: calendarIcon, badge: upcomingAppointments.value.length || null },
                            { id: 'from-agent', label: 'From Agent', icon: giftIcon, badge: sharedUnviewedCount.value || null },
                            { id: 'agent', label: 'My Agent', icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>' },
                            { id: 'settings', label: 'Settings', icon: settingsIcon }
                        );
                    }

                    return baseTabs;
                });

                // Computed
                const totalNewListings = computed(() => {
                    return savedSearches.value.reduce((sum, s) => sum + (s.new_count || 0), 0);
                });

                // Appointments computed
                const upcomingAppointments = computed(() => {
                    if (!Array.isArray(appointments.value)) return [];
                    const now = new Date();
                    return appointments.value.filter(a => {
                        const apptDate = new Date(a.appointment_date + 'T' + a.start_time);
                        return apptDate >= now && a.status !== 'cancelled' && a.status !== 'no_show';
                    });
                });

                const pastAppointments = computed(() => {
                    if (!Array.isArray(appointments.value)) return [];
                    const now = new Date();
                    return appointments.value.filter(a => {
                        const apptDate = new Date(a.appointment_date + 'T' + a.start_time);
                        return apptDate < now || a.status === 'cancelled' || a.status === 'no_show';
                    });
                });

                const showPastAppointments = ref(false);

                // API helpers
                async function apiRequest(endpoint, options = {}) {
                    const url = config.restUrl + endpoint;
                    const headers = {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': config.nonce
                    };

                    try {
                        const response = await fetch(url, {
                            ...options,
                            headers: { ...headers, ...options.headers },
                            credentials: 'same-origin'
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.message || 'Request failed');
                        }

                        return data.success !== false ? (data.data || data) : data;
                    } catch (error) {
                        console.error('API Error:', error);
                        throw error;
                    }
                }

                // Data loading
                async function loadDashboardData() {
                    loading.value = true;
                    try {
                        // Load data in parallel
                        const [searchesData, favoritesData, hiddenData, prefsData] = await Promise.all([
                            apiRequest('saved-searches').catch(() => []),
                            apiRequest('favorites').catch(() => []),
                            apiRequest('hidden').catch(() => []),
                            apiRequest('email-preferences').catch(() => null)
                        ]);

                        savedSearches.value = Array.isArray(searchesData) ? searchesData : (searchesData.searches || searchesData.data || []);
                        favorites.value = Array.isArray(favoritesData) ? favoritesData : (favoritesData.properties || favoritesData.favorites || []);
                        hiddenProperties.value = Array.isArray(hiddenData) ? hiddenData : (hiddenData.properties || hiddenData.hidden || []);

                        if (prefsData && prefsData.preferences) {
                            Object.assign(emailPrefs, prefsData.preferences);
                            emailStats.value = prefsData.stats || null;
                        }

                        // Build activity from searches
                        buildRecentActivity();

                        // Load appointments separately (different API namespace)
                        loadAppointments();

                    } catch (error) {
                        console.error('Failed to load dashboard data:', error);
                        showToast('Failed to load dashboard data', 'error');
                    } finally {
                        loading.value = false;
                    }
                }

                function buildRecentActivity() {
                    const activities = [];

                    // Add saved searches as activity
                    savedSearches.value.forEach(search => {
                        if (search.created_at) {
                            activities.push({
                                id: 'search-' + search.id,
                                type: 'search',
                                icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>',
                                text: 'Saved search "' + search.name + '"',
                                date: new Date(search.created_at)
                            });
                        }
                        if (search.new_count > 0) {
                            activities.push({
                                id: 'match-' + search.id,
                                type: 'match',
                                icon: '<svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6z"/></svg>',
                                text: search.new_count + ' new listing' + (search.new_count > 1 ? 's' : '') + ' in "' + search.name + '"',
                                date: new Date()
                            });
                        }
                    });

                    // Sort by date, newest first
                    activities.sort((a, b) => b.date - a.date);
                    recentActivity.value = activities;
                }

                // Search actions
                async function toggleSearchPause(search) {
                    try {
                        await apiRequest('saved-searches/' + search.id, {
                            method: 'PUT',
                            body: JSON.stringify({ is_active: !search.is_active })
                        });

                        search.is_active = !search.is_active;
                        showToast(search.is_active ? 'Search resumed' : 'Search paused', 'success');
                    } catch (error) {
                        showToast('Failed to update search', 'error');
                    }
                }

                function confirmDeleteSearch(search) {
                    searchToDelete.value = search;
                    showDeleteModal.value = true;
                }

                async function deleteSearch() {
                    if (!searchToDelete.value) return;

                    try {
                        await apiRequest('saved-searches/' + searchToDelete.value.id, {
                            method: 'DELETE'
                        });

                        savedSearches.value = savedSearches.value.filter(s => s.id !== searchToDelete.value.id);
                        showToast('Search deleted', 'success');
                    } catch (error) {
                        showToast('Failed to delete search', 'error');
                    } finally {
                        showDeleteModal.value = false;
                        searchToDelete.value = null;
                    }
                }

                function getSearchUrl(search) {
                    if (search.search_url) {
                        return search.search_url;
                    }
                    // Build URL from filters
                    return buildSearchUrlFromFilters(search.filters || {});
                }

                function buildSearchUrlFromFilters(filters) {
                    const params = [];
                    const f = filters;

                    // Handle both iOS and web filter formats
                    if (f.city || f.City) params.push('City=' + encodeURIComponent(f.city || f.City));
                    if (f.min_price || f.price_min) params.push('price_min=' + (f.min_price || f.price_min));
                    if (f.max_price || f.price_max) params.push('price_max=' + (f.max_price || f.price_max));
                    if (f.beds || f.min_beds) params.push('beds=' + (f.beds || f.min_beds));
                    if (f.baths || f.min_baths) params.push('baths=' + (f.baths || f.min_baths));
                    if (f.property_type || f.PropertyType) params.push('PropertyType=' + encodeURIComponent(f.property_type || f.PropertyType));

                    return searchUrl + (params.length ? '#' + params.join('&') : '');
                }

                // Favorites actions
                async function removeFavorite(property) {
                    // Use listing_id (MLS number) for API calls
                    const propertyId = property.listing_id || property.mls_number || property.id;
                    const listingKey = property.listing_key || property.id;
                    try {
                        await apiRequest('favorites/' + propertyId, {
                            method: 'DELETE'
                        });

                        // Filter out the removed property - check all possible ID fields
                        favorites.value = favorites.value.filter(f => {
                            const fId = f.listing_id || f.mls_number || f.id;
                            return fId !== propertyId;
                        });
                        showToast('Removed from favorites', 'success');

                        // Track analytics event
                        document.dispatchEvent(new CustomEvent('mld:favorite', {
                            detail: { listingKey: listingKey, added: false, city: property.city }
                        }));
                    } catch (error) {
                        console.error('Failed to remove favorite:', error);
                        showToast('Failed to remove favorite', 'error');
                    }
                }

                // Email preferences
                async function saveEmailPrefs() {
                    try {
                        await apiRequest('email-preferences', {
                            method: 'POST',
                            body: JSON.stringify(emailPrefs)
                        });
                        showToast('Preferences saved', 'success');
                    } catch (error) {
                        showToast('Failed to save preferences', 'error');
                    }
                }

                // Account Deletion (Apple App Store Guideline 5.1.1(v) compliance)
                function openDeleteAccountModal() {
                    deleteAccountConfirmText.value = '';
                    deleteAccountError.value = null;
                    showDeleteAccountModal.value = true;
                }

                function closeDeleteAccountModal() {
                    showDeleteAccountModal.value = false;
                    deleteAccountConfirmText.value = '';
                    deleteAccountError.value = null;
                }

                const canDeleteAccount = computed(() => {
                    return deleteAccountConfirmText.value.toUpperCase() === 'DELETE' && !deleteAccountLoading.value;
                });

                async function deleteAccount() {
                    if (!canDeleteAccount.value) return;

                    deleteAccountLoading.value = true;
                    deleteAccountError.value = null;

                    try {
                        await apiRequest('auth/delete-account', {
                            method: 'DELETE'
                        });
                        // Account deleted successfully - redirect to home page
                        showToast('Your account has been deleted', 'success');
                        // Wait briefly for toast to show, then redirect
                        setTimeout(() => {
                            window.location.href = homeUrl || '/';
                        }, 1500);
                    } catch (error) {
                        deleteAccountLoading.value = false;
                        deleteAccountError.value = error.message || 'Failed to delete account. Please try again.';
                    }
                }

                // Hidden Properties
                async function loadHiddenProperties() {
                    hiddenLoading.value = true;
                    try {
                        const data = await apiRequest('hidden');
                        hiddenProperties.value = Array.isArray(data) ? data : (data.hidden || data.properties || []);
                    } catch (error) {
                        console.error('Failed to load hidden properties:', error);
                    } finally {
                        hiddenLoading.value = false;
                    }
                }

                async function unhideProperty(property) {
                    // Use listing_id (MLS number) for API calls
                    const propertyId = property.listing_id || property.mls_number || property.id;
                    const listingKey = property.listing_key || property.id;
                    try {
                        await apiRequest('hidden/' + propertyId, {
                            method: 'DELETE'
                        });
                        // Filter out the unhidden property - check all possible ID fields
                        hiddenProperties.value = hiddenProperties.value.filter(p => {
                            const pId = p.listing_id || p.mls_number || p.id;
                            return pId !== propertyId;
                        });
                        showToast('Property unhidden', 'success');

                        // Track analytics event
                        document.dispatchEvent(new CustomEvent('mld:hidden', {
                            detail: { listingKey: listingKey, added: false, city: property.city }
                        }));
                    } catch (error) {
                        console.error('Failed to unhide property:', error);
                        showToast('Failed to unhide property', 'error');
                    }
                }

                // Appointments
                async function loadAppointments() {
                    appointmentsLoading.value = true;
                    try {
                        const response = await fetch(snabRestUrl + 'appointments', {
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': config.nonce
                            },
                            credentials: 'same-origin'
                        });
                        const data = await response.json();
                        // SNAB API returns: { success: true, data: { appointments: [...] } }
                        let appts = data.data?.appointments || data.appointments || data.data || [];
                        if (!Array.isArray(appts)) appts = [];

                        // Map field names to match template expectations
                        appointments.value = appts.map(a => ({
                            ...a,
                            appointment_date: a.date || a.appointment_date,
                            location: a.property_address || a.location,
                            notes: a.client_notes || a.notes
                        }));
                    } catch (error) {
                        console.error('Failed to load appointments:', error);
                    } finally {
                        appointmentsLoading.value = false;
                    }
                }

                function openCancelModal(appointment) {
                    appointmentToCancel.value = appointment;
                    showCancelModal.value = true;
                }

                function closeCancelModal() {
                    showCancelModal.value = false;
                    appointmentToCancel.value = null;
                }

                async function confirmCancelAppointment() {
                    if (!appointmentToCancel.value) return;

                    try {
                        await fetch(snabRestUrl + 'appointments/' + appointmentToCancel.value.id, {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': config.nonce
                            },
                            credentials: 'same-origin'
                        });
                        showCancelModal.value = false;
                        showToast('Appointment cancelled', 'success');
                        await loadAppointments();
                    } catch (error) {
                        showToast('Failed to cancel appointment', 'error');
                    }
                }

                function openRescheduleModal(appointment) {
                    appointmentToReschedule.value = appointment;
                    rescheduleDate.value = '';
                    rescheduleSlots.value = [];
                    selectedSlot.value = null;
                    showRescheduleModal.value = true;
                }

                function closeRescheduleModal() {
                    showRescheduleModal.value = false;
                    appointmentToReschedule.value = null;
                    rescheduleDate.value = '';
                    rescheduleSlots.value = [];
                    selectedSlot.value = null;
                }

                async function loadRescheduleSlots() {
                    if (!rescheduleDate.value || !appointmentToReschedule.value) return;

                    rescheduleSlotsLoading.value = true;
                    try {
                        // Pass start_date and end_date for the selected date
                        const response = await fetch(
                            snabRestUrl + 'appointments/' + appointmentToReschedule.value.id + '/reschedule-slots?start_date=' + rescheduleDate.value + '&end_date=' + rescheduleDate.value,
                            {
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': config.nonce
                                },
                                credentials: 'same-origin'
                            }
                        );
                        const data = await response.json();
                        // API returns slots as object keyed by date: {'2026-01-13': [{value, label}, ...]}
                        // Extract slots for the selected date as an array
                        const slotsObj = data.data?.slots || data.slots || {};
                        rescheduleSlots.value = slotsObj[rescheduleDate.value] || [];
                    } catch (error) {
                        console.error('Failed to load slots:', error);
                        rescheduleSlots.value = [];
                    } finally {
                        rescheduleSlotsLoading.value = false;
                    }
                }

                function selectTimeSlot(slot) {
                    selectedSlot.value = slot;
                }

                async function confirmReschedule() {
                    if (!appointmentToReschedule.value || !rescheduleDate.value || !selectedSlot.value) return;

                    // Extract time from slot (handles both {value: '09:00'} and string formats)
                    const slotTime = typeof selectedSlot.value === 'object'
                        ? (selectedSlot.value.value || selectedSlot.value.time)
                        : selectedSlot.value;

                    try {
                        await fetch(snabRestUrl + 'appointments/' + appointmentToReschedule.value.id + '/reschedule', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': config.nonce
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                date: rescheduleDate.value,
                                time: slotTime
                            })
                        });
                        showRescheduleModal.value = false;
                        showToast('Appointment rescheduled', 'success');
                        await loadAppointments();
                    } catch (error) {
                        showToast('Failed to reschedule appointment', 'error');
                    }
                }

                function formatAppointmentDate(date) {
                    if (!date) return '';
                    const d = new Date(date);
                    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
                }

                function formatAppointmentTime(time) {
                    if (!time) return '';
                    // Handle both string ('09:00') and object ({value: '09:00', label: '9:00 AM'}) formats
                    if (typeof time === 'object') {
                        // If object has a label, use it directly
                        if (time.label) return time.label;
                        // Otherwise extract the value
                        time = time.value || time.time || '';
                    }
                    if (!time || typeof time !== 'string') return '';
                    const [hours, minutes] = time.split(':');
                    const h = parseInt(hours, 10);
                    const ampm = h >= 12 ? 'PM' : 'AM';
                    const h12 = h % 12 || 12;
                    return h12 + ':' + minutes + ' ' + ampm;
                }

                function getMinDate() {
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    return tomorrow.toISOString().split('T')[0];
                }

                // Agent-specific methods
                async function loadAgentClients() {
                    if (!isAgent.value) return;

                    clientsLoading.value = true;
                    try {
                        const data = await apiRequest('agent/clients');
                        clients.value = Array.isArray(data) ? data : (data.clients || []);
                    } catch (error) {
                        console.error('Failed to load clients:', error);
                        showToast('Failed to load clients', 'error');
                    } finally {
                        clientsLoading.value = false;
                    }
                }

                async function loadAgentMetrics() {
                    if (!isAgent.value) return;

                    try {
                        const data = await apiRequest('agent/metrics');
                        agentMetrics.value = data;
                    } catch (error) {
                        console.error('Failed to load agent metrics:', error);
                    }
                }

                // v6.52.0 - Load agent referral link data
                async function loadReferralData() {
                    if (!isAgent.value) return;

                    referralLoading.value = true;
                    try {
                        const data = await apiRequest('agent/referral-link');
                        referralData.value = data;
                    } catch (error) {
                        console.error('Failed to load referral data:', error);
                    } finally {
                        referralLoading.value = false;
                    }
                }

                // v6.52.0 - Copy referral link to clipboard
                async function copyReferralLink() {
                    if (!referralData.value?.referral_url) return;

                    try {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            await navigator.clipboard.writeText(referralData.value.referral_url);
                        } else {
                            // Fallback for older browsers
                            const textarea = document.createElement('textarea');
                            textarea.value = referralData.value.referral_url;
                            document.body.appendChild(textarea);
                            textarea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textarea);
                        }
                        referralCopied.value = true;
                        showToast('Referral link copied to clipboard!', 'success');
                        setTimeout(() => { referralCopied.value = false; }, 2000);
                    } catch (error) {
                        console.error('Failed to copy referral link:', error);
                        showToast('Failed to copy link', 'error');
                    }
                }

                async function selectClient(client) {
                    selectedClient.value = client;

                    // Load client details in parallel
                    try {
                        const [searches, favorites, hidden] = await Promise.all([
                            apiRequest('agent/clients/' + client.id + '/searches').catch(() => []),
                            apiRequest('agent/clients/' + client.id + '/favorites').catch(() => []),
                            apiRequest('agent/clients/' + client.id + '/hidden').catch(() => [])
                        ]);

                        clientSearches.value = Array.isArray(searches) ? searches : (searches.searches || []);
                        clientFavorites.value = Array.isArray(favorites) ? favorites : (favorites.favorites || []);
                        clientHidden.value = Array.isArray(hidden) ? hidden : (hidden.hidden || []);
                    } catch (error) {
                        console.error('Failed to load client details:', error);
                        showToast('Failed to load client details', 'error');
                    }
                }

                function deselectClient() {
                    selectedClient.value = null;
                    clientSearches.value = [];
                    clientFavorites.value = [];
                    clientHidden.value = [];
                }

                async function createClient() {
                    if (!newClient.email || !newClient.first_name) {
                        showToast('Email and first name are required', 'error');
                        return;
                    }

                    try {
                        const data = await apiRequest('agent/clients', {
                            method: 'POST',
                            body: JSON.stringify({
                                email: newClient.email,
                                first_name: newClient.first_name,
                                last_name: newClient.last_name,
                                phone: newClient.phone,
                                send_notification: newClient.send_notification
                            })
                        });

                        showToast('Client created successfully', 'success');
                        showCreateClientModal.value = false;
                        resetNewClientForm();

                        // Reload clients list
                        await loadAgentClients();
                    } catch (error) {
                        showToast(error.message || 'Failed to create client', 'error');
                    }
                }

                function resetNewClientForm() {
                    newClient.email = '';
                    newClient.first_name = '';
                    newClient.last_name = '';
                    newClient.phone = '';
                    newClient.send_notification = true;
                }

                function openCreateClientModal() {
                    resetNewClientForm();
                    showCreateClientModal.value = true;
                }

                function closeCreateClientModal() {
                    showCreateClientModal.value = false;
                    resetNewClientForm();
                }

                // v6.40.0 - Client Insights (Analytics) methods
                async function loadClientAnalytics() {
                    if (!isAgent.value) return;

                    clientAnalyticsLoading.value = true;
                    try {
                        const data = await apiRequest('agent/clients/analytics/summary');
                        clientAnalytics.value = data.clients || [];
                        clientAnalyticsSummary.value = data.summary || null;
                    } catch (error) {
                        console.error('Failed to load client analytics:', error);
                    } finally {
                        clientAnalyticsLoading.value = false;
                    }
                }

                async function selectClientForInsights(client) {
                    selectedClientInsights.value = client;
                    insightsDetailLoading.value = true;

                    try {
                        // Load property interests, activity timeline, most viewed, and preferences in parallel
                        const [propertyInterests, timeline, mostViewed, preferences] = await Promise.all([
                            apiRequest('agent/clients/' + client.id + '/property-interests').catch(() => []),
                            apiRequest('agent/clients/' + client.id + '/activity?per_page=20').catch(() => []),
                            apiRequest('agent/clients/' + client.id + '/most-viewed?min_views=2&limit=10').catch(() => []),
                            apiRequest('agent/clients/' + client.id + '/preferences').catch(() => null)
                        ]);

                        selectedClientPropertyInterests.value = Array.isArray(propertyInterests)
                            ? propertyInterests
                            : (propertyInterests.properties || propertyInterests.data || []);

                        selectedClientTimeline.value = Array.isArray(timeline)
                            ? timeline
                            : (timeline.activities || timeline.data || []);

                        selectedClientMostViewed.value = Array.isArray(mostViewed)
                            ? mostViewed
                            : (mostViewed.properties || mostViewed.data || []);

                        // Preferences returns a nested object
                        selectedClientPreferences.value = preferences || null;
                    } catch (error) {
                        console.error('Failed to load client insights details:', error);
                    } finally {
                        insightsDetailLoading.value = false;
                    }
                }

                function closeClientInsights() {
                    selectedClientInsights.value = null;
                    selectedClientPropertyInterests.value = [];
                    selectedClientTimeline.value = [];
                    selectedClientMostViewed.value = [];
                    selectedClientPreferences.value = null;
                }

                // Computed for sorted client analytics
                const sortedClientAnalytics = computed(() => {
                    if (!clientAnalytics.value || !Array.isArray(clientAnalytics.value)) {
                        return [];
                    }

                    const sorted = [...clientAnalytics.value];
                    sorted.sort((a, b) => {
                        let aVal, bVal;

                        switch (insightsSortBy.value) {
                            case 'engagement_score':
                                aVal = a.engagement_score || 0;
                                bVal = b.engagement_score || 0;
                                break;
                            case 'last_activity':
                                aVal = a.last_activity ? new Date(a.last_activity).getTime() : 0;
                                bVal = b.last_activity ? new Date(b.last_activity).getTime() : 0;
                                break;
                            case 'name':
                                aVal = (a.name || '').toLowerCase();
                                bVal = (b.name || '').toLowerCase();
                                break;
                            case 'properties_viewed':
                                aVal = a.quick_stats?.properties_viewed_7d || 0;
                                bVal = b.quick_stats?.properties_viewed_7d || 0;
                                break;
                            default:
                                aVal = 0;
                                bVal = 0;
                        }

                        if (insightsSortOrder.value === 'asc') {
                            return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
                        } else {
                            return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
                        }
                    });

                    return sorted;
                });

                function setInsightsSort(field) {
                    if (insightsSortBy.value === field) {
                        insightsSortOrder.value = insightsSortOrder.value === 'desc' ? 'asc' : 'desc';
                    } else {
                        insightsSortBy.value = field;
                        insightsSortOrder.value = 'desc';
                    }
                }

                function getEngagementScoreClass(score) {
                    if (score >= 70) return 'score-high';
                    if (score >= 40) return 'score-medium';
                    return 'score-low';
                }

                function getScoreTrendIcon(trend) {
                    if (trend === 'rising') return '↑';
                    if (trend === 'falling') return '↓';
                    return '–';
                }

                function getScoreTrendClass(trend) {
                    if (trend === 'rising') return 'trend-up';
                    if (trend === 'falling') return 'trend-down';
                    return 'trend-stable';
                }

                function formatActivityType(type) {
                    const typeMap = {
                        'property_view': 'Viewed property',
                        'search_run': 'Ran search',
                        'filter_used': 'Applied filter',
                        'favorite_add': 'Added to favorites',
                        'favorite_remove': 'Removed from favorites',
                        'photo_view': 'Viewed photos',
                        'calculator_use': 'Used calculator',
                        'contact_click': 'Clicked contact',
                        'schedule_click': 'Scheduled showing',
                        'login': 'Logged in',
                        'page_view': 'Viewed page'
                    };
                    return typeMap[type] || type.replace(/_/g, ' ');
                }

                function getActivityIcon(type) {
                    const iconMap = {
                        'property_view': '🏠',
                        'favorite_add': '❤️',
                        'favorite_remove': '💔',
                        'hidden_add': '🙈',
                        'hidden_remove': '👁️',
                        'search_run': '🔍',
                        'filter_used': '⚙️',
                        'photo_view': '📷',
                        'photo_lightbox_open': '🖼️',
                        'calculator_use': '🧮',
                        'contact_click': '📞',
                        'schedule_click': '📅',
                        'property_share': '📤',
                        'school_info_view': '🎓',
                        'similar_homes_click': '🏘️',
                        'map_interaction': '🗺️',
                        'save_search': '💾',
                        'alert_created': '🔔',
                        'login': '🔐',
                        'logout': '🚪',
                        'page_view': '📄',
                        'app_open': '📱',
                        'session_start': '▶️',
                        'session_end': '⏹️'
                    };
                    return iconMap[type] || '📌';
                }

                function getActivityIconClass(type) {
                    // Property-related activities
                    if (['property_view', 'photo_view', 'photo_lightbox_open', 'similar_homes_click'].includes(type)) {
                        return 'activity-view';
                    }
                    // Engagement/interest activities
                    if (['favorite_add', 'hidden_add', 'save_search', 'alert_created'].includes(type)) {
                        return 'activity-engage';
                    }
                    // Removal activities
                    if (['favorite_remove', 'hidden_remove'].includes(type)) {
                        return 'activity-remove';
                    }
                    // Contact/scheduling activities
                    if (['contact_click', 'schedule_click', 'property_share'].includes(type)) {
                        return 'activity-contact';
                    }
                    // Search activities
                    if (['search_run', 'filter_used', 'map_interaction'].includes(type)) {
                        return 'activity-search';
                    }
                    // Tool usage
                    if (['calculator_use', 'school_info_view'].includes(type)) {
                        return 'activity-tool';
                    }
                    // Session activities
                    if (['login', 'logout', 'app_open', 'session_start', 'session_end'].includes(type)) {
                        return 'activity-session';
                    }
                    return 'activity-other';
                }

                // Share Property methods (for agents to share with clients)
                function openSharePropertyModal(client = null, property = null) {
                    if (client) {
                        // Opening from client list - need to search for property
                        shareModalMode.value = 'select-property';
                        shareTargetClient.value = client;
                        shareTargetProperty.value = null;
                    } else if (property) {
                        // Opening from property page - need to select client
                        shareModalMode.value = 'select-client';
                        shareTargetProperty.value = property;
                        shareTargetClient.value = null;
                    }
                    shareNote.value = '';
                    propertySearchQuery.value = '';
                    propertySearchResults.value = [];
                    showSharePropertyModal.value = true;
                }

                function closeSharePropertyModal() {
                    showSharePropertyModal.value = false;
                    shareTargetClient.value = null;
                    shareTargetProperty.value = null;
                    shareNote.value = '';
                    propertySearchQuery.value = '';
                    propertySearchResults.value = [];
                }

                function selectClientForShare(client) {
                    shareTargetClient.value = client;
                }

                function selectPropertyForShare(property) {
                    shareTargetProperty.value = property;
                    propertySearchResults.value = []; // Clear search results
                }

                async function searchProperties() {
                    if (!propertySearchQuery.value || propertySearchQuery.value.length < 2) {
                        propertySearchResults.value = [];
                        return;
                    }

                    propertySearchLoading.value = true;
                    try {
                        const query = propertySearchQuery.value.trim();

                        // Use autocomplete endpoint to get suggestions first
                        const autocomplete = await apiRequest('search/autocomplete?term=' + encodeURIComponent(query));
                        const suggestions = autocomplete.data || autocomplete || [];

                        // Build search params based on autocomplete results or fallback to direct search
                        let params = 'per_page=10';

                        if (suggestions.length > 0) {
                            // Use first suggestion to determine search type
                            const suggestion = suggestions[0];
                            const value = suggestion.value;
                            const type = suggestion.type;

                            if (type === 'City') {
                                params += '&city=' + encodeURIComponent(value);
                            } else if (type === 'ZIP Code') {
                                params += '&zip=' + encodeURIComponent(value);
                            } else if (type === 'Street Name') {
                                params += '&street_name=' + encodeURIComponent(value);
                            } else if (type === 'Address') {
                                params += '&address=' + encodeURIComponent(value);
                            } else if (type === 'MLS Number') {
                                params += '&mls_number=' + encodeURIComponent(value);
                            } else if (type === 'Neighborhood') {
                                params += '&neighborhood=' + encodeURIComponent(value);
                            } else {
                                // Fallback to city search
                                params += '&city=' + encodeURIComponent(query);
                            }
                        } else {
                            // No suggestions - try direct search by city
                            params += '&city=' + encodeURIComponent(query);
                        }

                        const response = await apiRequest('properties?' + params);
                        // Response structure: { success: true, data: { listings: [...] } }
                        const listings = response.listings || response.data?.listings || response.data || [];
                        propertySearchResults.value = Array.isArray(listings) ? listings : [];
                    } catch (error) {
                        console.error('Property search failed:', error);
                        propertySearchResults.value = [];
                    } finally {
                        propertySearchLoading.value = false;
                    }
                }

                async function sharePropertyWithClient() {
                    if (!shareTargetClient.value || !shareTargetProperty.value) {
                        showToast('Please select both a client and a property', 'error');
                        return;
                    }

                    shareSending.value = true;
                    try {
                        // Get listing key - property cards use 'id' which is the listing_key hash
                        const listingKey = shareTargetProperty.value.id ||
                                          shareTargetProperty.value.listing_key;

                        // Get client ID - could be client_id or user_id depending on source
                        const clientId = shareTargetClient.value.client_id ||
                                        shareTargetClient.value.user_id ||
                                        shareTargetClient.value.id;

                        await apiRequest('shared-properties', {
                            method: 'POST',
                            body: JSON.stringify({
                                client_ids: [parseInt(clientId)],
                                listing_keys: [listingKey],
                                note: shareNote.value || ''
                            })
                        });

                        showToast('Property shared successfully! Client will be notified.', 'success');
                        closeSharePropertyModal();
                    } catch (error) {
                        console.error('Failed to share property:', error);
                        showToast('Failed to share property', 'error');
                    } finally {
                        shareSending.value = false;
                    }
                }

                // Shared Properties methods (From My Agent)
                async function loadSharedProperties() {
                    if (isAgent.value) return; // Only for clients

                    sharedLoading.value = true;
                    try {
                        const data = await apiRequest('shared-properties');
                        sharedProperties.value = Array.isArray(data) ? data : (data.properties || data.data || []);

                        // Count unviewed
                        sharedUnviewedCount.value = sharedProperties.value.filter(p => !p.viewed_at).length;
                    } catch (error) {
                        console.error('Failed to load shared properties:', error);
                    } finally {
                        sharedLoading.value = false;
                    }
                }

                async function respondToSharedProperty(share, response) {
                    try {
                        await apiRequest('shared-properties/' + share.id, {
                            method: 'PUT',
                            body: JSON.stringify({ response: response })
                        });

                        // Update local state
                        const idx = sharedProperties.value.findIndex(p => p.id === share.id);
                        if (idx !== -1) {
                            sharedProperties.value[idx].client_response = response;
                        }

                        const responseText = response === 'interested' ? 'Marked as interested' : 'Marked as not interested';
                        showToast(responseText, 'success');
                    } catch (error) {
                        console.error('Failed to respond to shared property:', error);
                        showToast('Failed to update response', 'error');
                    }
                }

                async function dismissSharedProperty(share) {
                    try {
                        await apiRequest('shared-properties/' + share.id, {
                            method: 'DELETE'
                        });

                        // Remove from local state
                        sharedProperties.value = sharedProperties.value.filter(p => p.id !== share.id);
                        showToast('Property dismissed', 'success');
                    } catch (error) {
                        console.error('Failed to dismiss shared property:', error);
                        showToast('Failed to dismiss property', 'error');
                    }
                }

                function formatSharedDate(dateStr) {
                    if (!dateStr) return '';
                    const date = new Date(dateStr);
                    const now = new Date();
                    const diffMs = now - date;
                    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

                    if (diffDays === 0) return 'Today';
                    if (diffDays === 1) return 'Yesterday';
                    if (diffDays < 7) return diffDays + ' days ago';

                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }

                // Formatting helpers
                function formatPrice(price) {
                    if (!price) return '0';
                    return new Intl.NumberFormat('en-US').format(price);
                }

                function formatNumber(num) {
                    if (!num) return '0';
                    return new Intl.NumberFormat('en-US').format(num);
                }

                function formatTimeAgo(date) {
                    if (!date) return '';
                    const now = new Date();
                    const diff = now - new Date(date);
                    const seconds = Math.floor(diff / 1000);
                    const minutes = Math.floor(seconds / 60);
                    const hours = Math.floor(minutes / 60);
                    const days = Math.floor(hours / 24);

                    if (days > 0) return days + ' day' + (days > 1 ? 's' : '') + ' ago';
                    if (hours > 0) return hours + ' hour' + (hours > 1 ? 's' : '') + ' ago';
                    if (minutes > 0) return minutes + ' min' + (minutes > 1 ? 's' : '') + ' ago';
                    return 'Just now';
                }

                function formatSearchCriteria(search) {
                    const filters = search.filters || {};
                    const parts = [];

                    const city = filters.city || filters.City;
                    const propType = filters.property_type || filters.PropertyType || 'Residential';
                    const minPrice = filters.min_price || filters.price_min;
                    const maxPrice = filters.max_price || filters.price_max;
                    const beds = filters.beds || filters.min_beds;

                    if (city) parts.push(city);
                    parts.push(propType);
                    if (minPrice || maxPrice) {
                        const priceStr = '$' + formatPrice(minPrice || 0) + ' - $' + formatPrice(maxPrice || 'No max');
                        parts.push(priceStr);
                    }
                    if (beds) parts.push(beds + '+ beds');

                    return parts.join(' | ') || 'All properties';
                }

                function formatFrequency(freq) {
                    const map = {
                        'instant': 'Instant',
                        'daily': 'Daily',
                        'weekly': 'Weekly',
                        'none': 'No alerts'
                    };
                    return map[freq] || freq || 'Daily';
                }

                function formatHour(hour) {
                    if (hour === null || hour === undefined) return '';
                    hour = parseInt(hour);
                    if (hour === 0) return '12 AM';
                    if (hour === 12) return '12 PM';
                    if (hour < 12) return hour + ' AM';
                    return (hour - 12) + ' PM';
                }

                // Toast notifications
                function showToast(message, type = 'success') {
                    toast.message = message;
                    toast.type = type;
                    toast.show = true;

                    setTimeout(() => {
                        toast.show = false;
                    }, 3000);
                }

                // Handle URL hash for tab navigation
                function handleHashChange() {
                    const hash = window.location.hash.slice(1);
                    if (hash && tabs.value.some(t => t.id === hash)) {
                        currentTab.value = hash;
                    }
                }

                watch(currentTab, (newTab) => {
                    window.location.hash = newTab;
                });

                // Lifecycle
                onMounted(() => {
                    handleHashChange();
                    window.addEventListener('hashchange', handleHashChange);
                    loadDashboardData();

                    // Load agent-specific data if user is an agent
                    if (isAgent.value) {
                        loadAgentClients();
                        loadAgentMetrics();
                        loadClientAnalytics(); // v6.40.0 - Load client insights
                        loadReferralData(); // v6.52.0 - Load referral link data
                    } else {
                        // Load client-specific data
                        loadSharedProperties();
                    }
                });

                return {
                    // State
                    loading,
                    currentTab,
                    user,
                    agent,
                    isAgent,
                    userType,
                    savedSearches,
                    favorites,
                    hiddenProperties,
                    hiddenLoading,
                    recentActivity,
                    emailPrefs,
                    emailStats,
                    showDeleteModal,
                    searchToDelete,
                    toast,

                    // Account deletion state (Apple App Store Guideline 5.1.1(v))
                    showDeleteAccountModal,
                    deleteAccountConfirmText,
                    deleteAccountLoading,
                    deleteAccountError,
                    canDeleteAccount,

                    // Appointments state
                    appointments,
                    appointmentsLoading,
                    upcomingAppointments,
                    pastAppointments,
                    showPastAppointments,
                    showCancelModal,
                    showRescheduleModal,
                    appointmentToCancel,
                    appointmentToReschedule,
                    rescheduleDate,
                    rescheduleSlots,
                    rescheduleSlotsLoading,
                    selectedSlot,

                    // Agent-specific state
                    clients,
                    clientsLoading,
                    selectedClient,
                    clientSearches,
                    clientFavorites,
                    clientHidden,
                    agentMetrics,
                    showCreateClientModal,
                    newClient,

                    // v6.52.0 - Agent Referral Link state
                    referralData,
                    referralLoading,
                    referralCopied,

                    // v6.40.0 - Client Insights state
                    clientAnalytics,
                    clientAnalyticsLoading,
                    clientAnalyticsSummary,
                    selectedClientInsights,
                    selectedClientPropertyInterests,
                    selectedClientTimeline,
                    selectedClientMostViewed,
                    selectedClientPreferences,
                    insightsDetailLoading,
                    insightsSortBy,
                    insightsSortOrder,
                    sortedClientAnalytics,

                    // Computed
                    tabs,
                    totalNewListings,
                    homeUrl,
                    searchUrl,
                    bookingUrl,

                    // Methods
                    toggleSearchPause,
                    confirmDeleteSearch,
                    deleteSearch,
                    getSearchUrl,
                    removeFavorite,
                    saveEmailPrefs,

                    // Account deletion methods (Apple App Store Guideline 5.1.1(v))
                    openDeleteAccountModal,
                    closeDeleteAccountModal,
                    deleteAccount,

                    formatPrice,
                    formatNumber,
                    formatTimeAgo,
                    formatSearchCriteria,
                    formatFrequency,
                    formatHour,

                    // Hidden properties methods
                    loadHiddenProperties,
                    unhideProperty,

                    // Appointments methods
                    loadAppointments,
                    openCancelModal,
                    closeCancelModal,
                    confirmCancelAppointment,
                    openRescheduleModal,
                    closeRescheduleModal,
                    loadRescheduleSlots,
                    selectTimeSlot,
                    confirmReschedule,
                    formatAppointmentDate,
                    formatAppointmentTime,
                    getMinDate,

                    // Agent methods
                    loadAgentClients,
                    loadAgentMetrics,
                    loadReferralData,
                    copyReferralLink,
                    selectClient,
                    deselectClient,
                    createClient,
                    openCreateClientModal,
                    closeCreateClientModal,

                    // v6.40.0 - Client Insights methods
                    loadClientAnalytics,
                    selectClientForInsights,
                    closeClientInsights,
                    setInsightsSort,
                    getEngagementScoreClass,
                    getScoreTrendIcon,
                    getScoreTrendClass,
                    formatActivityType,
                    getActivityIcon,
                    getActivityIconClass,

                    // Share property modal (for agents)
                    showSharePropertyModal,
                    shareModalMode,
                    shareTargetClient,
                    shareTargetProperty,
                    shareNote,
                    shareSending,
                    propertySearchQuery,
                    propertySearchResults,
                    propertySearchLoading,
                    openSharePropertyModal,
                    closeSharePropertyModal,
                    selectClientForShare,
                    selectPropertyForShare,
                    searchProperties,
                    sharePropertyWithClient,

                    // Shared properties state
                    sharedProperties,
                    sharedLoading,
                    sharedUnviewedCount,

                    // Shared properties methods
                    loadSharedProperties,
                    respondToSharedProperty,
                    dismissSharedProperty,
                    formatSharedDate
                };
            }
        });

        app.mount('#mld-client-dashboard');

        } catch (error) {
            console.error('MLD Dashboard: FATAL ERROR during initialization:', error);
            console.error('MLD Dashboard: Error stack:', error.stack);
        }
    }

    // Initialize - handle both cases: DOM already ready or not yet ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboard);
    } else {
        // DOM is already ready, initialize immediately
        initDashboard();
    }
})();
