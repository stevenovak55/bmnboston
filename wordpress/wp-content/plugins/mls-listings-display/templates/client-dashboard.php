<?php
/**
 * Client Dashboard Template
 *
 * Vue.js-powered dashboard for clients to manage saved searches,
 * favorites, and agent interactions.
 *
 * @package MLS_Listings_Display
 * @since 6.32.1
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
?>

<div id="mld-client-dashboard" class="mld-dashboard" v-cloak>
    <!-- Navigation -->
    <nav class="mld-dashboard__nav">
        <div class="mld-dashboard__nav-inner">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                @click="currentTab = tab.id"
                :class="['mld-dashboard__nav-item', { 'is-active': currentTab === tab.id }]"
            >
                <span class="mld-dashboard__nav-icon" v-html="tab.icon"></span>
                <span class="mld-dashboard__nav-label">{{ tab.label }}</span>
                <span v-if="tab.badge" class="mld-dashboard__nav-badge">{{ tab.badge }}</span>
            </button>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="mld-dashboard__content">
        <!-- Loading State -->
        <div v-if="loading" class="mld-dashboard__loading">
            <div class="mld-spinner"></div>
            <p>Loading your dashboard...</p>
        </div>

        <!-- Overview Tab -->
        <section v-else-if="currentTab === 'overview'" class="mld-dashboard__section">
            <h1 class="mld-dashboard__title">Welcome back, {{ user.firstName }}!</h1>

            <!-- Agent Metrics Panel (for agents only) -->
            <div v-if="isAgent && agentMetrics" class="mld-agent-metrics">
                <h2 class="mld-agent-metrics__heading">📊 Your Client Portfolio</h2>
                <div class="mld-dashboard__stats mld-dashboard__stats--agent">
                    <div class="mld-stat-card mld-stat-card--highlight" @click="currentTab = 'clients'">
                        <div class="mld-stat-card__value">{{ agentMetrics.total_clients }}</div>
                        <div class="mld-stat-card__label">Total Clients</div>
                    </div>
                    <div class="mld-stat-card mld-stat-card--success">
                        <div class="mld-stat-card__value">{{ agentMetrics.active_clients }}</div>
                        <div class="mld-stat-card__label">Active This Week</div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-card__value">{{ agentMetrics.total_searches }}</div>
                        <div class="mld-stat-card__label">Client Searches</div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-card__value">{{ agentMetrics.total_favorites }}</div>
                        <div class="mld-stat-card__label">Client Favorites</div>
                    </div>
                </div>
                <div v-if="agentMetrics.active_clients > 0" class="mld-agent-metrics__tip">
                    💡 <strong>{{ agentMetrics.active_clients }}</strong> client{{ agentMetrics.active_clients === 1 ? ' is' : 's are' }} actively searching. Consider reaching out!
                </div>
            </div>

            <!-- Agent Referral Link Section (v6.52.0) -->
            <div v-if="isAgent && referralData" class="mld-referral-card">
                <div class="mld-referral-card__header">
                    <h3 class="mld-referral-card__title">🔗 Your Referral Link</h3>
                    <div v-if="referralData.stats" class="mld-referral-card__stats">
                        <span class="mld-referral-card__stat">
                            <strong>{{ referralData.stats.total_signups }}</strong> signups
                        </span>
                        <span class="mld-referral-card__stat">
                            <strong>{{ referralData.stats.this_month }}</strong> this month
                        </span>
                    </div>
                </div>
                <div class="mld-referral-card__body">
                    <p class="mld-referral-card__description">
                        Share this link with potential clients. When they sign up, they'll be automatically assigned to you.
                    </p>
                    <div class="mld-referral-card__link-wrapper">
                        <input
                            type="text"
                            :value="referralData.referral_url"
                            readonly
                            class="mld-referral-card__input"
                            @click="$event.target.select()"
                        />
                        <button
                            @click="copyReferralLink"
                            :class="['mld-btn', 'mld-btn--primary', { 'mld-btn--success': referralCopied }]"
                        >
                            <span v-if="referralCopied">✓ Copied!</span>
                            <span v-else>📋 Copy Link</span>
                        </button>
                    </div>
                    <p class="mld-referral-card__code">
                        Your referral code: <code>{{ referralData.referral_code }}</code>
                    </p>
                </div>
            </div>

            <!-- Quick Stats (for clients) -->
            <div v-if="!isAgent" class="mld-dashboard__stats">
                <div class="mld-stat-card" @click="currentTab = 'searches'">
                    <div class="mld-stat-card__value">{{ savedSearches.length }}</div>
                    <div class="mld-stat-card__label">Saved Searches</div>
                </div>
                <div class="mld-stat-card" @click="currentTab = 'favorites'">
                    <div class="mld-stat-card__value">{{ favorites.length }}</div>
                    <div class="mld-stat-card__label">Favorites</div>
                </div>
                <div class="mld-stat-card">
                    <div class="mld-stat-card__value">{{ totalNewListings }}</div>
                    <div class="mld-stat-card__label">New Matches</div>
                </div>
            </div>

            <!-- Agent Card (if assigned) -->
            <div v-if="agent" class="mld-agent-card mld-agent-card--featured">
                <div class="mld-agent-card__header">
                    <span class="mld-agent-card__badge">Your Agent</span>
                </div>
                <div class="mld-agent-card__body">
                    <img v-if="agent.photo_url" :src="agent.photo_url" :alt="agent.name" class="mld-agent-card__photo">
                    <div v-else class="mld-agent-card__photo mld-agent-card__photo--placeholder">
                        {{ agent.name.charAt(0) }}
                    </div>
                    <div class="mld-agent-card__info">
                        <h3 class="mld-agent-card__name">{{ agent.name }}</h3>
                        <p class="mld-agent-card__title">{{ agent.title }}</p>
                        <p v-if="agent.office_name" class="mld-agent-card__office">{{ agent.office_name }}</p>
                    </div>
                </div>
                <div class="mld-agent-card__actions">
                    <a v-if="agent.phone" :href="'tel:' + agent.phone.replace(/[^0-9+]/g, '')" class="mld-btn mld-btn--primary">
                        📞 {{ agent.phone }}
                    </a>
                    <a v-if="agent.email" :href="'mailto:' + agent.email" class="mld-btn mld-btn--secondary">
                        ✉️ Email
                    </a>
                    <a v-if="agent.snab_staff_id" :href="homeUrl + '/book/?staff=' + agent.snab_staff_id" class="mld-btn mld-btn--outline">
                        📅 Schedule Showing
                    </a>
                    <a v-if="agent.profile_url" :href="agent.profile_url" class="mld-btn mld-btn--ghost">
                        View Profile
                    </a>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="mld-dashboard__recent">
                <h2>Recent Activity</h2>
                <div v-if="recentActivity.length === 0" class="mld-empty-state mld-empty-state--small">
                    <p>No recent activity</p>
                </div>
                <ul v-else class="mld-activity-list">
                    <li v-for="activity in recentActivity.slice(0, 5)" :key="activity.id" class="mld-activity-item">
                        <span class="mld-activity-item__icon" v-html="activity.icon"></span>
                        <div class="mld-activity-item__content">
                            <span class="mld-activity-item__text">{{ activity.text }}</span>
                            <span class="mld-activity-item__time">{{ formatTimeAgo(activity.date) }}</span>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Saved Searches Tab -->
        <section v-else-if="currentTab === 'searches'" class="mld-dashboard__section">
            <div class="mld-dashboard__header">
                <h1 class="mld-dashboard__title">Saved Searches</h1>
                <a :href="searchUrl" class="mld-btn mld-btn--primary">+ New Search</a>
            </div>

            <div v-if="savedSearches.length === 0" class="mld-empty-state">
                <div class="mld-empty-state__icon">🔍</div>
                <h3>No saved searches yet</h3>
                <p>Save a search to get notified when new listings match your criteria.</p>
                <a :href="searchUrl" class="mld-btn mld-btn--primary">Start Searching</a>
            </div>

            <div v-else class="mld-search-grid">
                <div v-for="search in savedSearches" :key="search.id" class="mld-search-card">
                    <div class="mld-search-card__header">
                        <h3 class="mld-search-card__name">{{ search.name }}</h3>
                        <span v-if="search.is_agent_recommended" class="mld-search-card__badge">Agent Pick</span>
                    </div>
                    <div class="mld-search-card__body">
                        <p class="mld-search-card__summary">{{ formatSearchCriteria(search) }}</p>
                        <div class="mld-search-card__meta">
                            <span class="mld-search-card__count">{{ search.match_count || 0 }} matches</span>
                            <span class="mld-search-card__frequency">{{ formatFrequency(search.notification_frequency) }}</span>
                        </div>
                    </div>
                    <div class="mld-search-card__footer">
                        <a :href="getSearchUrl(search)" class="mld-btn mld-btn--small mld-btn--primary">View Results</a>
                        <button @click="toggleSearchPause(search)" class="mld-btn mld-btn--small mld-btn--outline">
                            {{ search.is_active ? 'Pause' : 'Resume' }}
                        </button>
                        <button @click="confirmDeleteSearch(search)" class="mld-btn mld-btn--small mld-btn--ghost mld-btn--danger">
                            Delete
                        </button>
                    </div>
                    <div v-if="search.agent_notes" class="mld-search-card__notes">
                        <strong>Agent Note:</strong> {{ search.agent_notes }}
                    </div>
                </div>
            </div>
        </section>

        <!-- Favorites Tab -->
        <section v-else-if="currentTab === 'favorites'" class="mld-dashboard__section">
            <h1 class="mld-dashboard__title">Favorite Properties</h1>

            <div v-if="favorites.length === 0" class="mld-empty-state">
                <div class="mld-empty-state__icon">❤️</div>
                <h3>No favorites yet</h3>
                <p>Save properties you love to compare them later.</p>
                <a :href="searchUrl" class="mld-btn mld-btn--primary">Browse Properties</a>
            </div>

            <div v-else class="mld-property-grid">
                <div v-for="property in favorites" :key="property.listing_id || property.id" class="mld-property-card">
                    <a :href="homeUrl + '/property/' + (property.listing_id || property.mls_number || property.id) + '/'" class="mld-property-card__image">
                        <img :src="property.photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'" :alt="property.address">
                        <span v-if="property.status" class="mld-property-card__status-badge">{{ property.status }}</span>
                    </a>
                    <div class="mld-property-card__body">
                        <div class="mld-property-card__price">${{ formatPrice(property.price || property.list_price) }}</div>
                        <h3 class="mld-property-card__address">{{ property.address }}</h3>
                        <p class="mld-property-card__location">{{ property.city }}, {{ property.state }} {{ property.zip }}</p>
                        <div class="mld-property-card__details">
                            <span v-if="property.beds">{{ property.beds }} bd</span>
                            <span v-if="property.baths">{{ property.baths }} ba</span>
                            <span v-if="property.sqft">{{ formatNumber(property.sqft) }} sqft</span>
                        </div>
                    </div>
                    <div class="mld-property-card__actions">
                        <a :href="homeUrl + '/property/' + (property.listing_id || property.mls_number || property.id) + '/'" class="mld-btn mld-btn--small mld-btn--primary">
                            View
                        </a>
                        <button @click="removeFavorite(property)" class="mld-btn mld-btn--small mld-btn--danger">
                            Remove
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Hidden Properties Tab -->
        <section v-else-if="currentTab === 'hidden'" class="mld-dashboard__section">
            <h1 class="mld-dashboard__title">Hidden Properties</h1>

            <div v-if="hiddenLoading" class="mld-dashboard__loading">
                <div class="mld-spinner"></div>
                <p>Loading hidden properties...</p>
            </div>

            <div v-else-if="hiddenProperties.length === 0" class="mld-empty-state">
                <div class="mld-empty-state__icon">👁️</div>
                <h3>No hidden properties</h3>
                <p>Properties you hide during search will appear here.</p>
                <a :href="searchUrl" class="mld-btn mld-btn--primary">Browse Properties</a>
            </div>

            <div v-else class="mld-property-grid">
                <div v-for="property in hiddenProperties" :key="property.listing_id || property.id" class="mld-property-card">
                    <a :href="homeUrl + '/property/' + (property.listing_id || property.mls_number || property.id) + '/'" class="mld-property-card__image">
                        <img :src="property.photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'" :alt="property.address">
                        <span class="mld-property-card__status-badge mld-property-card__status-badge--hidden">Hidden</span>
                    </a>
                    <div class="mld-property-card__body">
                        <div class="mld-property-card__price">${{ formatPrice(property.price || property.list_price) }}</div>
                        <h3 class="mld-property-card__address">{{ property.address }}</h3>
                        <p class="mld-property-card__location">{{ property.city }}, {{ property.state }} {{ property.zip }}</p>
                        <div class="mld-property-card__details">
                            <span v-if="property.beds">{{ property.beds }} bd</span>
                            <span v-if="property.baths">{{ property.baths }} ba</span>
                            <span v-if="property.sqft">{{ formatNumber(property.sqft) }} sqft</span>
                        </div>
                    </div>
                    <div class="mld-property-card__actions">
                        <a :href="homeUrl + '/property/' + (property.listing_id || property.mls_number || property.id) + '/'" class="mld-btn mld-btn--small mld-btn--primary">
                            View
                        </a>
                        <button @click="unhideProperty(property)" class="mld-btn mld-btn--small mld-btn--secondary">
                            Unhide
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Appointments Tab -->
        <section v-else-if="currentTab === 'appointments'" class="mld-dashboard__section">
            <div class="mld-dashboard__header">
                <h1 class="mld-dashboard__title">Appointments</h1>
                <a :href="bookingUrl" class="mld-btn mld-btn--primary">+ Book Appointment</a>
            </div>

            <div v-if="appointmentsLoading" class="mld-dashboard__loading">
                <div class="mld-spinner"></div>
                <p>Loading appointments...</p>
            </div>

            <template v-else>
                <!-- Upcoming Appointments -->
                <div class="mld-appointments-section">
                    <h2 class="mld-section-title">Upcoming Appointments</h2>

                    <div v-if="upcomingAppointments.length === 0" class="mld-empty-state mld-empty-state--compact">
                        <div class="mld-empty-state__icon">📅</div>
                        <h3>No upcoming appointments</h3>
                        <p>Book a showing to see your properties in person.</p>
                        <a :href="bookingUrl" class="mld-btn mld-btn--primary">Book Appointment</a>
                    </div>

                    <div v-else class="mld-appointment-grid">
                        <div v-for="appointment in upcomingAppointments" :key="appointment.id"
                             :class="['mld-appointment-card', 'mld-appointment-card--' + appointment.status]">
                            <div class="mld-appointment-card__header">
                                <span class="mld-appointment-card__type" :style="{ backgroundColor: appointment.type_color || '#0891B2' }">
                                    {{ appointment.type_name || 'Appointment' }}
                                </span>
                                <span :class="['mld-appointment-card__status', 'mld-appointment-card__status--' + appointment.status]">
                                    {{ appointment.status }}
                                </span>
                            </div>
                            <div class="mld-appointment-card__body">
                                <div class="mld-appointment-card__datetime">
                                    <span class="mld-appointment-card__date">{{ formatAppointmentDate(appointment.appointment_date) }}</span>
                                    <span class="mld-appointment-card__time">{{ formatAppointmentTime(appointment.start_time) }}</span>
                                </div>
                                <p v-if="appointment.staff_name" class="mld-appointment-card__staff">
                                    👤 With {{ appointment.staff_name }}
                                </p>
                                <p v-if="appointment.location" class="mld-appointment-card__location">
                                    📍 {{ appointment.location }}
                                </p>
                                <p v-if="appointment.notes" class="mld-appointment-card__notes">
                                    {{ appointment.notes }}
                                </p>
                            </div>
                            <div class="mld-appointment-card__actions">
                                <button @click="openRescheduleModal(appointment)" class="mld-btn mld-btn--small mld-btn--outline">
                                    Reschedule
                                </button>
                                <button @click="openCancelModal(appointment)" class="mld-btn mld-btn--small mld-btn--ghost mld-btn--danger">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Past Appointments (Collapsible) -->
                <div v-if="pastAppointments.length > 0" class="mld-appointments-section mld-appointments-section--past">
                    <button @click="showPastAppointments = !showPastAppointments" class="mld-collapsible-header">
                        <span>Past Appointments ({{ pastAppointments.length }})</span>
                        <span class="mld-collapsible-arrow" :class="{ 'is-open': showPastAppointments }">▼</span>
                    </button>

                    <div v-if="showPastAppointments" class="mld-appointment-grid mld-appointment-grid--past">
                        <div v-for="appointment in pastAppointments" :key="appointment.id"
                             class="mld-appointment-card mld-appointment-card--past">
                            <div class="mld-appointment-card__header">
                                <span class="mld-appointment-card__type mld-appointment-card__type--past">
                                    {{ appointment.type_name || 'Appointment' }}
                                </span>
                                <span :class="['mld-appointment-card__status', 'mld-appointment-card__status--' + appointment.status]">
                                    {{ appointment.status }}
                                </span>
                            </div>
                            <div class="mld-appointment-card__body">
                                <div class="mld-appointment-card__datetime">
                                    <span class="mld-appointment-card__date">{{ formatAppointmentDate(appointment.appointment_date) }}</span>
                                    <span class="mld-appointment-card__time">{{ formatAppointmentTime(appointment.start_time) }}</span>
                                </div>
                                <p v-if="appointment.staff_name" class="mld-appointment-card__staff">
                                    👤 With {{ appointment.staff_name }}
                                </p>
                                <p v-if="appointment.location" class="mld-appointment-card__location">
                                    📍 {{ appointment.location }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </section>

        <!-- From My Agent Tab (for clients) -->
        <section v-else-if="currentTab === 'from-agent' && !isAgent" class="mld-dashboard__section">
            <h1 class="mld-dashboard__title">Properties From Your Agent</h1>
            <p class="mld-dashboard__subtitle" v-if="agent">Hand-picked by {{ agent.name }}</p>

            <div v-if="sharedLoading" class="mld-dashboard__loading">
                <div class="mld-spinner"></div>
                <p>Loading shared properties...</p>
            </div>

            <div v-else-if="sharedProperties.length === 0" class="mld-empty-state">
                <div class="mld-empty-state__icon">🎁</div>
                <h3>No properties shared yet</h3>
                <p>When your agent finds properties perfect for you, they'll appear here.</p>
            </div>

            <div v-else class="mld-shared-properties">
                <div v-for="share in sharedProperties" :key="share.id" class="mld-shared-card">
                    <div class="mld-shared-card__header">
                        <div class="mld-shared-card__agent-info" v-if="share.agent">
                            <img v-if="share.agent.photo_url" :src="share.agent.photo_url" :alt="share.agent.name" class="mld-shared-card__agent-photo">
                            <div v-else class="mld-shared-card__agent-photo mld-shared-card__agent-photo--placeholder">
                                {{ share.agent.name ? share.agent.name.charAt(0) : '?' }}
                            </div>
                            <div class="mld-shared-card__agent-details">
                                <span class="mld-shared-card__agent-name">{{ share.agent.name }}</span>
                                <span class="mld-shared-card__shared-date">Shared {{ formatSharedDate(share.shared_at) }}</span>
                            </div>
                        </div>
                        <span v-if="!share.viewed_at" class="mld-shared-card__badge mld-shared-card__badge--new">New</span>
                        <span v-else-if="share.client_response === 'interested'" class="mld-shared-card__badge mld-shared-card__badge--interested">Interested</span>
                        <span v-else-if="share.client_response === 'not_interested'" class="mld-shared-card__badge mld-shared-card__badge--pass">Passed</span>
                    </div>

                    <div v-if="share.agent_note" class="mld-shared-card__note">
                        <span class="mld-shared-card__note-icon">💬</span>
                        <p>"{{ share.agent_note }}"</p>
                    </div>

                    <div v-if="share.property" class="mld-shared-card__property">
                        <a :href="homeUrl + '/property/' + (share.property.listing_id || share.property.id) + '/'" class="mld-shared-card__property-image">
                            <img :src="share.property.photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'" :alt="share.property.address">
                        </a>
                        <div class="mld-shared-card__property-info">
                            <div class="mld-shared-card__property-price">${{ formatPrice(share.property.price) }}</div>
                            <h3 class="mld-shared-card__property-address">{{ share.property.address }}</h3>
                            <p class="mld-shared-card__property-location">{{ share.property.city }}, {{ share.property.state }} {{ share.property.zip }}</p>
                            <div class="mld-shared-card__property-details">
                                <span v-if="share.property.beds">{{ share.property.beds }} bd</span>
                                <span v-if="share.property.baths">{{ share.property.baths }} ba</span>
                                <span v-if="share.property.sqft">{{ formatNumber(share.property.sqft) }} sqft</span>
                            </div>
                        </div>
                    </div>

                    <div class="mld-shared-card__actions">
                        <a :href="homeUrl + '/property/' + (share.property?.listing_id || share.listing_id || share.listing_key) + '/'" class="mld-btn mld-btn--primary">View Property</a>
                        <button
                            v-if="share.client_response !== 'interested'"
                            @click="respondToSharedProperty(share, 'interested')"
                            class="mld-btn mld-btn--success"
                        >
                            👍 Interested
                        </button>
                        <button
                            v-if="share.client_response !== 'not_interested'"
                            @click="respondToSharedProperty(share, 'not_interested')"
                            class="mld-btn mld-btn--outline"
                        >
                            Pass
                        </button>
                        <button @click="dismissSharedProperty(share)" class="mld-btn mld-btn--ghost mld-btn--danger">
                            Dismiss
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- My Agent Tab (for clients) -->
        <section v-else-if="currentTab === 'agent' && !isAgent" class="mld-dashboard__section">
            <h1 class="mld-dashboard__title">My Agent</h1>

            <div v-if="!agent" class="mld-empty-state">
                <div class="mld-empty-state__icon">👤</div>
                <h3>No agent assigned yet</h3>
                <p>You'll be connected with an agent who can help you find your perfect home.</p>
            </div>

            <div v-else class="mld-agent-profile">
                <div class="mld-agent-profile__header">
                    <img v-if="agent.photo_url" :src="agent.photo_url" :alt="agent.name" class="mld-agent-profile__photo">
                    <div v-else class="mld-agent-profile__photo mld-agent-profile__photo--placeholder">
                        {{ agent.name.charAt(0) }}
                    </div>
                    <div class="mld-agent-profile__intro">
                        <h2 class="mld-agent-profile__name">{{ agent.name }}</h2>
                        <p class="mld-agent-profile__title">{{ agent.title }}</p>
                        <p v-if="agent.office_name" class="mld-agent-profile__office">{{ agent.office_name }}</p>
                    </div>
                </div>

                <div class="mld-agent-profile__contact">
                    <a v-if="agent.phone" :href="'tel:' + agent.phone.replace(/[^0-9+]/g, '')" class="mld-btn mld-btn--primary mld-btn--large">
                        📞 Call {{ agent.phone }}
                    </a>
                    <a v-if="agent.email" :href="'mailto:' + agent.email" class="mld-btn mld-btn--secondary mld-btn--large">
                        ✉️ Email {{ agent.name.split(' ')[0] }}
                    </a>
                    <a v-if="agent.snab_staff_id" :href="homeUrl + '/book-appointment/?staff=' + agent.snab_staff_id" class="mld-btn mld-btn--outline mld-btn--large">
                        📅 Schedule a Showing
                    </a>
                </div>

                <div v-if="agent.bio" class="mld-agent-profile__bio">
                    <h3>About {{ agent.name.split(' ')[0] }}</h3>
                    <p>{{ agent.bio }}</p>
                </div>
            </div>
        </section>

        <!-- My Clients Tab (for agents) -->
        <section v-else-if="currentTab === 'clients' && isAgent" class="mld-dashboard__section">
            <div class="mld-dashboard__header">
                <h1 class="mld-dashboard__title">My Clients</h1>
                <button @click="openCreateClientModal" class="mld-btn mld-btn--primary">+ Add Client</button>
            </div>

            <!-- Loading state -->
            <div v-if="clientsLoading" class="mld-dashboard__loading">
                <div class="mld-spinner"></div>
                <p>Loading clients...</p>
            </div>

            <!-- Client Detail View -->
            <div v-else-if="selectedClient" class="mld-client-detail">
                <button @click="deselectClient" class="mld-btn mld-btn--ghost mld-client-detail__back">
                    ← Back to Client List
                </button>

                <div class="mld-client-detail__header">
                    <div class="mld-client-detail__avatar">
                        {{ selectedClient.first_name ? selectedClient.first_name.charAt(0) : selectedClient.email.charAt(0) }}
                    </div>
                    <div class="mld-client-detail__info">
                        <h2>{{ selectedClient.first_name }} {{ selectedClient.last_name }}</h2>
                        <p class="mld-client-detail__email">{{ selectedClient.email }}</p>
                        <p v-if="selectedClient.phone" class="mld-client-detail__phone">{{ selectedClient.phone }}</p>
                    </div>
                    <div class="mld-client-detail__actions">
                        <button @click="openSharePropertyModal(selectedClient)" class="mld-btn mld-btn--primary">
                            🎁 Share Property
                        </button>
                        <a v-if="selectedClient.phone" :href="'tel:' + selectedClient.phone.replace(/[^0-9+]/g, '')" class="mld-btn mld-btn--secondary">
                            📞 Call
                        </a>
                        <a :href="'mailto:' + selectedClient.email" class="mld-btn mld-btn--ghost">
                            ✉️ Email
                        </a>
                    </div>
                </div>

                <!-- Client Stats -->
                <div class="mld-dashboard__stats mld-client-detail__stats">
                    <div class="mld-stat-card">
                        <div class="mld-stat-card__value">{{ clientSearches.length }}</div>
                        <div class="mld-stat-card__label">Saved Searches</div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-card__value">{{ clientFavorites.length }}</div>
                        <div class="mld-stat-card__label">Favorites</div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-card__value">{{ clientHidden.length }}</div>
                        <div class="mld-stat-card__label">Hidden</div>
                    </div>
                </div>

                <!-- Client's Saved Searches -->
                <div class="mld-client-detail__section">
                    <h3>Saved Searches</h3>
                    <div v-if="clientSearches.length === 0" class="mld-empty-state mld-empty-state--small">
                        <p>No saved searches yet</p>
                    </div>
                    <div v-else class="mld-search-grid mld-search-grid--compact">
                        <div v-for="search in clientSearches" :key="search.id" class="mld-search-card mld-search-card--compact">
                            <div class="mld-search-card__header">
                                <h4 class="mld-search-card__name">{{ search.name }}</h4>
                            </div>
                            <div class="mld-search-card__body">
                                <p class="mld-search-card__summary">{{ formatSearchCriteria(search) }}</p>
                                <span class="mld-search-card__count">{{ search.last_matched_count || 0 }} matches</span>
                            </div>
                            <div class="mld-search-card__footer">
                                <a :href="getSearchUrl(search)" class="mld-btn mld-btn--small mld-btn--primary">View</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Client's Favorites -->
                <div class="mld-client-detail__section">
                    <h3>Favorite Properties</h3>
                    <div v-if="clientFavorites.length === 0" class="mld-empty-state mld-empty-state--small">
                        <p>No favorites yet</p>
                    </div>
                    <div v-else class="mld-property-grid mld-property-grid--compact">
                        <div v-for="property in clientFavorites.slice(0, 6)" :key="property.listing_id || property.id" class="mld-property-card mld-property-card--compact">
                            <a :href="homeUrl + '/property/' + (property.listing_id || property.mls_number || property.id) + '/'" class="mld-property-card__image">
                                <img :src="property.photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'" :alt="property.address">
                            </a>
                            <div class="mld-property-card__body">
                                <div class="mld-property-card__price">${{ formatPrice(property.list_price || property.price) }}</div>
                                <h4 class="mld-property-card__address">{{ property.address }}</h4>
                                <p class="mld-property-card__location">{{ property.city }}</p>
                            </div>
                        </div>
                    </div>
                    <p v-if="clientFavorites.length > 6" class="mld-client-detail__more">
                        + {{ clientFavorites.length - 6 }} more favorites
                    </p>
                </div>

                <!-- Client's Hidden Properties -->
                <div class="mld-client-detail__section">
                    <h3>Hidden Properties</h3>
                    <div v-if="clientHidden.length === 0" class="mld-empty-state mld-empty-state--small">
                        <p>No hidden properties</p>
                    </div>
                    <div v-else class="mld-property-grid mld-property-grid--compact">
                        <div v-for="property in clientHidden.slice(0, 6)" :key="property.listing_id || property.id" class="mld-property-card mld-property-card--compact mld-property-card--hidden">
                            <a :href="homeUrl + '/property/' + (property.listing_id || property.mls_number || property.id) + '/'" class="mld-property-card__image">
                                <img :src="property.photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'" :alt="property.address">
                                <span class="mld-property-card__hidden-badge">Hidden</span>
                            </a>
                            <div class="mld-property-card__body">
                                <div class="mld-property-card__price">${{ formatPrice(property.list_price || property.price) }}</div>
                                <h4 class="mld-property-card__address">{{ property.address }}</h4>
                                <p class="mld-property-card__location">{{ property.city }}</p>
                            </div>
                        </div>
                    </div>
                    <p v-if="clientHidden.length > 6" class="mld-client-detail__more">
                        + {{ clientHidden.length - 6 }} more hidden
                    </p>
                </div>
            </div>

            <!-- Client List View -->
            <div v-else>
                <div v-if="clients.length === 0" class="mld-empty-state">
                    <div class="mld-empty-state__icon">👥</div>
                    <h3>No clients yet</h3>
                    <p>Add your first client to help them find their perfect home.</p>
                    <button @click="openCreateClientModal" class="mld-btn mld-btn--primary">Add Client</button>
                </div>

                <div v-else class="mld-client-grid">
                    <div v-for="client in clients" :key="client.id" class="mld-client-card">
                        <div class="mld-client-card__main" @click="selectClient(client)">
                            <div class="mld-client-card__avatar">
                                {{ client.first_name ? client.first_name.charAt(0) : client.email.charAt(0) }}
                            </div>
                            <div class="mld-client-card__info">
                                <h3 class="mld-client-card__name">
                                    {{ client.first_name || '' }} {{ client.last_name || '' }}
                                    <span v-if="!client.first_name && !client.last_name">{{ client.email }}</span>
                                </h3>
                                <p class="mld-client-card__email">{{ client.email }}</p>
                            </div>
                            <div class="mld-client-card__stats">
                                <span class="mld-client-card__stat" title="Saved Searches">
                                    🔍 {{ client.searches_count || 0 }}
                                </span>
                                <span class="mld-client-card__stat" title="Favorites">
                                    ❤️ {{ client.favorites_count || 0 }}
                                </span>
                            </div>
                            <div class="mld-client-card__arrow">→</div>
                        </div>
                        <button @click.stop="openSharePropertyModal(client)" class="mld-client-card__share-btn" title="Share a property with this client">
                            🎁 Share Property
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Client Insights Tab (for agents) - v6.40.0 -->
        <section v-else-if="currentTab === 'insights' && isAgent" class="mld-dashboard__section">
            <div class="mld-dashboard__header">
                <h1 class="mld-dashboard__title">Client Insights</h1>
                <div class="mld-insights-sort">
                    <label>Sort by:</label>
                    <select v-model="insightsSortBy" class="mld-select mld-select--inline">
                        <option value="engagement_score">Engagement Score</option>
                        <option value="last_activity">Last Activity</option>
                        <option value="properties_viewed">Properties Viewed</option>
                        <option value="name">Name</option>
                    </select>
                    <button @click="insightsSortOrder = insightsSortOrder === 'desc' ? 'asc' : 'desc'" class="mld-btn mld-btn--ghost mld-btn--small">
                        {{ insightsSortOrder === 'desc' ? '↓' : '↑' }}
                    </button>
                </div>
            </div>

            <!-- Loading state -->
            <div v-if="clientAnalyticsLoading" class="mld-dashboard__loading">
                <div class="mld-spinner"></div>
                <p>Loading client insights...</p>
            </div>

            <!-- Summary Cards -->
            <div v-else-if="clientAnalyticsSummary" class="mld-insights-summary">
                <div class="mld-insights-card mld-insights-card--highlight">
                    <div class="mld-insights-card__value">{{ clientAnalyticsSummary.highly_engaged || 0 }}</div>
                    <div class="mld-insights-card__label">Highly Engaged</div>
                    <div class="mld-insights-card__help">Score 70+</div>
                </div>
                <div class="mld-insights-card mld-insights-card--success">
                    <div class="mld-insights-card__value">{{ clientAnalyticsSummary.active_this_week || 0 }}</div>
                    <div class="mld-insights-card__label">Active This Week</div>
                </div>
                <div class="mld-insights-card mld-insights-card--warning">
                    <div class="mld-insights-card__value">{{ clientAnalyticsSummary.needs_attention || 0 }}</div>
                    <div class="mld-insights-card__label">Needs Attention</div>
                    <div class="mld-insights-card__help">No activity 7+ days</div>
                </div>
                <div class="mld-insights-card">
                    <div class="mld-insights-card__value">{{ clientAnalyticsSummary.total_clients || 0 }}</div>
                    <div class="mld-insights-card__label">Total Clients</div>
                </div>
            </div>

            <!-- Client Detail View -->
            <div v-if="selectedClientInsights" class="mld-insights-detail">
                <button @click="closeClientInsights" class="mld-btn mld-btn--ghost mld-insights-detail__back">
                    ← Back to Client List
                </button>

                <div class="mld-insights-detail__header">
                    <div class="mld-insights-detail__client">
                        <div class="mld-insights-detail__avatar">
                            {{ selectedClientInsights.name ? selectedClientInsights.name.charAt(0) : '?' }}
                        </div>
                        <div class="mld-insights-detail__info">
                            <h2>{{ selectedClientInsights.name }}</h2>
                            <p class="mld-insights-detail__last-active">
                                Last active: {{ selectedClientInsights.days_since_activity === 0 ? 'Today' : selectedClientInsights.days_since_activity + ' days ago' }}
                            </p>
                        </div>
                    </div>
                    <div :class="['mld-insights-score', 'mld-insights-score--large', getEngagementScoreClass(selectedClientInsights.engagement_score)]">
                        <div class="mld-insights-score__value">{{ Math.round(selectedClientInsights.engagement_score || 0) }}</div>
                        <div class="mld-insights-score__label">Engagement</div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="mld-insights-detail__stats">
                    <div class="mld-stat-card">
                        <div class="mld-stat-card__value">{{ selectedClientInsights.quick_stats?.properties_viewed_7d || 0 }}</div>
                        <div class="mld-stat-card__label">Properties (7d)</div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-card__value">{{ selectedClientInsights.quick_stats?.searches_7d || 0 }}</div>
                        <div class="mld-stat-card__label">Searches (7d)</div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-card__value">{{ selectedClientInsights.quick_stats?.favorites_count || 0 }}</div>
                        <div class="mld-stat-card__label">Favorites</div>
                    </div>
                </div>

                <div v-if="insightsDetailLoading" class="mld-dashboard__loading mld-dashboard__loading--small">
                    <div class="mld-spinner mld-spinner--small"></div>
                    <p>Loading details...</p>
                </div>

                <div v-else class="mld-insights-detail__content">
                    <!-- Top Property Interests -->
                    <div class="mld-insights-detail__section">
                        <h3>Top Property Interests</h3>
                        <div v-if="selectedClientPropertyInterests.length === 0" class="mld-empty-state mld-empty-state--small">
                            <p>No property interests tracked yet</p>
                        </div>
                        <div v-else class="mld-property-interest-list">
                            <div v-for="prop in selectedClientPropertyInterests.slice(0, 5)" :key="prop.listing_id" class="mld-property-interest">
                                <a :href="homeUrl + '/property/' + prop.listing_id + '/'" class="mld-property-interest__image">
                                    <img :src="prop.main_photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'" :alt="prop.street_name">
                                </a>
                                <div class="mld-property-interest__info">
                                    <div class="mld-property-interest__address">{{ prop.street_number }} {{ prop.street_name }}</div>
                                    <div class="mld-property-interest__location">{{ prop.city }}</div>
                                    <div class="mld-property-interest__price">${{ formatPrice(prop.list_price) }}</div>
                                </div>
                                <div class="mld-property-interest__metrics">
                                    <div class="mld-property-interest__score" :class="getEngagementScoreClass(prop.interest_score)">
                                        {{ Math.round(prop.interest_score || 0) }}
                                    </div>
                                    <div class="mld-property-interest__details">
                                        <span v-if="prop.view_count">{{ prop.view_count }} views</span>
                                        <span v-if="prop.favorited" class="mld-tag mld-tag--success">Favorited</span>
                                        <span v-if="prop.calculator_used" class="mld-tag mld-tag--info">Used Calculator</span>
                                        <span v-if="prop.contact_clicked" class="mld-tag mld-tag--warning">Contacted</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Most Viewed Properties (v6.41.3) -->
                    <div class="mld-insights-detail__section">
                        <h3>🔥 Most Viewed Properties</h3>
                        <p class="mld-section-subtitle">Properties viewed 2+ times — highest interest</p>
                        <div v-if="selectedClientMostViewed.length === 0" class="mld-empty-state mld-empty-state--small">
                            <p>No properties viewed multiple times yet</p>
                        </div>
                        <div v-else class="mld-most-viewed-list">
                            <div v-for="prop in selectedClientMostViewed" :key="prop.listing_id" class="mld-most-viewed-item">
                                <div class="mld-most-viewed-item__rank">
                                    <span class="mld-view-count">{{ prop.view_count }}</span>
                                    <span class="mld-view-label">views</span>
                                </div>
                                <a :href="homeUrl + '/property/' + prop.listing_id + '/'" class="mld-most-viewed-item__content">
                                    <img :src="prop.photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'"
                                         :alt="prop.address"
                                         class="mld-most-viewed-item__photo">
                                    <div class="mld-most-viewed-item__info">
                                        <div class="mld-most-viewed-item__address">{{ prop.address }}</div>
                                        <div class="mld-most-viewed-item__city">{{ prop.city }}, {{ prop.state }}</div>
                                        <div class="mld-most-viewed-item__meta">
                                            <span class="mld-most-viewed-item__price">${{ formatPrice(prop.price) }}</span>
                                            <span class="mld-most-viewed-item__details" v-if="prop.beds || prop.baths">
                                                • {{ prop.beds }} bd | {{ prop.baths }} ba
                                            </span>
                                        </div>
                                        <div class="mld-most-viewed-item__dates">
                                            <span>First: {{ formatTimeAgo(prop.first_viewed) }}</span>
                                            <span>Last: {{ formatTimeAgo(prop.last_viewed) }}</span>
                                        </div>
                                    </div>
                                </a>
                                <div class="mld-most-viewed-item__status" v-if="prop.status !== 'Active'">
                                    <span class="mld-status-badge" :class="'mld-status-badge--' + prop.status.toLowerCase()">
                                        {{ prop.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Client Preferences Profile (v6.42.0) -->
                    <div v-if="selectedClientPreferences" class="mld-insights-detail__section mld-preferences-section">
                        <h3>📊 Client Profile</h3>
                        <p class="mld-section-subtitle">What this client is looking for based on their activity</p>

                        <!-- Profile Strength Meter -->
                        <div class="mld-profile-strength">
                            <div class="mld-profile-strength__header">
                                <span class="mld-profile-strength__label">Profile Strength</span>
                                <span class="mld-profile-strength__value" :class="'mld-profile-strength__value--' + selectedClientPreferences.profile_strength?.label?.toLowerCase().replace(' ', '-')">
                                    {{ selectedClientPreferences.profile_strength?.label }}
                                </span>
                            </div>
                            <div class="mld-profile-strength__bar">
                                <div class="mld-profile-strength__fill" :style="{ width: (selectedClientPreferences.profile_strength?.score || 0) + '%' }"></div>
                            </div>
                            <div class="mld-profile-strength__components">
                                <span title="Views">👁 {{ selectedClientPreferences.profile_strength?.components?.views || 0 }}</span>
                                <span title="Unique Properties">🏠 {{ selectedClientPreferences.profile_strength?.components?.unique_properties || 0 }}</span>
                                <span title="Favorites">❤️ {{ selectedClientPreferences.profile_strength?.components?.favorites || 0 }}</span>
                                <span title="Searches">🔍 {{ selectedClientPreferences.profile_strength?.components?.searches || 0 }}</span>
                            </div>
                        </div>

                        <!-- Location Preferences -->
                        <div v-if="selectedClientPreferences.location_preferences?.top_cities?.length > 0" class="mld-pref-card">
                            <h4>📍 Preferred Locations</h4>
                            <div class="mld-pref-grid">
                                <div class="mld-pref-col">
                                    <h5>Top Cities</h5>
                                    <div v-for="city in selectedClientPreferences.location_preferences.top_cities" :key="city.name" class="mld-pref-bar">
                                        <span class="mld-pref-bar__label">{{ city.name }}</span>
                                        <div class="mld-pref-bar__track">
                                            <div class="mld-pref-bar__fill mld-pref-bar__fill--blue" :style="{ width: city.percentage + '%' }"></div>
                                        </div>
                                        <span class="mld-pref-bar__value">{{ city.percentage }}%</span>
                                    </div>
                                </div>
                                <div v-if="selectedClientPreferences.location_preferences.top_neighborhoods?.length > 0" class="mld-pref-col">
                                    <h5>Top Neighborhoods</h5>
                                    <div v-for="n in selectedClientPreferences.location_preferences.top_neighborhoods.slice(0, 3)" :key="n.name" class="mld-pref-bar">
                                        <span class="mld-pref-bar__label">{{ n.name }}</span>
                                        <div class="mld-pref-bar__track">
                                            <div class="mld-pref-bar__fill mld-pref-bar__fill--green" :style="{ width: n.percentage + '%' }"></div>
                                        </div>
                                        <span class="mld-pref-bar__value">{{ n.percentage }}%</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Property Characteristics -->
                        <div v-if="selectedClientPreferences.property_preferences" class="mld-pref-card">
                            <h4>🏠 Property Preferences</h4>
                            <div class="mld-pref-stats">
                                <!-- Price Range -->
                                <div v-if="selectedClientPreferences.property_preferences.price?.average" class="mld-pref-stat">
                                    <span class="mld-pref-stat__icon">💰</span>
                                    <div class="mld-pref-stat__content">
                                        <span class="mld-pref-stat__label">Price Range</span>
                                        <span class="mld-pref-stat__value">
                                            ${{ formatPrice(selectedClientPreferences.property_preferences.price.min) }}
                                            - ${{ formatPrice(selectedClientPreferences.property_preferences.price.max) }}
                                        </span>
                                        <span class="mld-pref-stat__detail">
                                            Avg: ${{ formatPrice(selectedClientPreferences.property_preferences.price.average) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Bedrooms -->
                                <div v-if="selectedClientPreferences.property_preferences.bedrooms?.average" class="mld-pref-stat">
                                    <span class="mld-pref-stat__icon">🛏️</span>
                                    <div class="mld-pref-stat__content">
                                        <span class="mld-pref-stat__label">Bedrooms</span>
                                        <span class="mld-pref-stat__value">
                                            {{ selectedClientPreferences.property_preferences.bedrooms.most_common }} beds preferred
                                        </span>
                                        <span class="mld-pref-stat__detail">
                                            Range: {{ selectedClientPreferences.property_preferences.bedrooms.min }}-{{ selectedClientPreferences.property_preferences.bedrooms.max }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Bathrooms -->
                                <div v-if="selectedClientPreferences.property_preferences.bathrooms?.average" class="mld-pref-stat">
                                    <span class="mld-pref-stat__icon">🚿</span>
                                    <div class="mld-pref-stat__content">
                                        <span class="mld-pref-stat__label">Bathrooms</span>
                                        <span class="mld-pref-stat__value">
                                            {{ selectedClientPreferences.property_preferences.bathrooms.most_common }} baths preferred
                                        </span>
                                        <span class="mld-pref-stat__detail">
                                            Range: {{ selectedClientPreferences.property_preferences.bathrooms.min }}-{{ selectedClientPreferences.property_preferences.bathrooms.max }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Square Footage -->
                                <div v-if="selectedClientPreferences.property_preferences.sqft?.average" class="mld-pref-stat">
                                    <span class="mld-pref-stat__icon">📐</span>
                                    <div class="mld-pref-stat__content">
                                        <span class="mld-pref-stat__label">Square Footage</span>
                                        <span class="mld-pref-stat__value">
                                            {{ selectedClientPreferences.property_preferences.sqft.average.toLocaleString() }} avg sqft
                                        </span>
                                        <span class="mld-pref-stat__detail">
                                            Range: {{ selectedClientPreferences.property_preferences.sqft.min.toLocaleString() }}-{{ selectedClientPreferences.property_preferences.sqft.max.toLocaleString() }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Garage -->
                                <div v-if="selectedClientPreferences.property_preferences.garage?.average !== null" class="mld-pref-stat">
                                    <span class="mld-pref-stat__icon">🚗</span>
                                    <div class="mld-pref-stat__content">
                                        <span class="mld-pref-stat__label">Parking</span>
                                        <span class="mld-pref-stat__value">
                                            {{ selectedClientPreferences.property_preferences.garage.most_common }} garage spaces preferred
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Property Types -->
                            <div v-if="selectedClientPreferences.property_preferences.property_types?.length > 0" class="mld-pref-types">
                                <h5>Property Types</h5>
                                <div class="mld-pref-types__list">
                                    <span v-for="pt in selectedClientPreferences.property_preferences.property_types.slice(0, 4)" :key="pt.type" class="mld-pref-type-badge">
                                        {{ pt.type }} <small>({{ pt.percentage }}%)</small>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Engagement Patterns -->
                        <div v-if="selectedClientPreferences.engagement_stats" class="mld-pref-card">
                            <h4>⏰ Activity Patterns</h4>
                            <div class="mld-engagement-grid">
                                <div class="mld-engagement-stat">
                                    <span class="mld-engagement-stat__value">{{ selectedClientPreferences.engagement_stats.total_views }}</span>
                                    <span class="mld-engagement-stat__label">Total Views</span>
                                </div>
                                <div class="mld-engagement-stat">
                                    <span class="mld-engagement-stat__value">{{ selectedClientPreferences.engagement_stats.unique_properties }}</span>
                                    <span class="mld-engagement-stat__label">Unique Properties</span>
                                </div>
                                <div class="mld-engagement-stat">
                                    <span class="mld-engagement-stat__value">{{ selectedClientPreferences.engagement_stats.favorites_count }}</span>
                                    <span class="mld-engagement-stat__label">Favorites</span>
                                </div>
                                <div class="mld-engagement-stat">
                                    <span class="mld-engagement-stat__value">{{ selectedClientPreferences.engagement_stats.saved_searches }}</span>
                                    <span class="mld-engagement-stat__label">Saved Searches</span>
                                </div>
                            </div>
                            <div v-if="selectedClientPreferences.engagement_stats.most_active_days?.length > 0" class="mld-active-times">
                                <p>
                                    <strong>Most active:</strong>
                                    {{ selectedClientPreferences.engagement_stats.most_active_days.map(d => d.day).join(', ') }}
                                    <span v-if="selectedClientPreferences.engagement_stats.most_active_hours?.length > 0">
                                        around {{ formatHour(selectedClientPreferences.engagement_stats.most_active_hours[0]) }}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Timeline -->
                    <div class="mld-insights-detail__section">
                        <h3>Recent Activity</h3>
                        <div v-if="selectedClientTimeline.length === 0" class="mld-empty-state mld-empty-state--small">
                            <p>No recent activity</p>
                        </div>
                        <div v-else class="mld-activity-timeline mld-activity-timeline--rich">
                            <div v-for="activity in selectedClientTimeline" :key="activity.id" class="mld-activity-timeline__item" :class="{ 'mld-activity-timeline__item--with-property': activity.property }">
                                <div class="mld-activity-timeline__icon" :class="getActivityIconClass(activity.activity_type)">
                                    {{ getActivityIcon(activity.activity_type) }}
                                </div>
                                <div class="mld-activity-timeline__content">
                                    <div class="mld-activity-timeline__header">
                                        <span class="mld-activity-timeline__action">{{ activity.description || formatActivityType(activity.activity_type) }}</span>
                                        <span class="mld-activity-timeline__time">{{ formatTimeAgo(activity.created_at) }}</span>
                                    </div>

                                    <!-- Property Card for property-related activities -->
                                    <div v-if="activity.property" class="mld-activity-property">
                                        <a :href="homeUrl + '/property/' + activity.property.listing_id + '/'" class="mld-activity-property__link">
                                            <img :src="activity.property.photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'"
                                                 :alt="activity.property.address"
                                                 class="mld-activity-property__photo">
                                            <div class="mld-activity-property__info">
                                                <div class="mld-activity-property__address">{{ activity.property.address }}</div>
                                                <div class="mld-activity-property__city">{{ activity.property.city }}, {{ activity.property.state }} {{ activity.property.zip }}</div>
                                                <div class="mld-activity-property__meta">
                                                    <span class="mld-activity-property__price">${{ formatPrice(activity.property.price) }}</span>
                                                    <span class="mld-activity-property__details" v-if="activity.property.beds || activity.property.baths">
                                                        • {{ activity.property.beds }} bd | {{ activity.property.baths }} ba
                                                        <span v-if="activity.property.sqft"> | {{ activity.property.sqft.toLocaleString() }} sqft</span>
                                                    </span>
                                                </div>
                                                <span v-if="activity.property.status !== 'Active'" class="mld-activity-property__status" :class="'mld-activity-property__status--' + activity.property.status.toLowerCase()">
                                                    {{ activity.property.status }}
                                                </span>
                                            </div>
                                        </a>
                                    </div>

                                    <!-- Platform badge -->
                                    <div class="mld-activity-timeline__footer">
                                        <span class="mld-activity-timeline__platform" :class="'mld-activity-timeline__platform--' + (activity.platform || 'unknown').toLowerCase()">
                                            {{ activity.platform === 'iOS' ? '📱 iOS App' : activity.platform === 'web' ? '🌐 Web' : activity.platform || 'Unknown' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Client List -->
            <div v-else-if="!clientAnalyticsLoading && sortedClientAnalytics.length > 0" class="mld-insights-list">
                <div v-for="client in sortedClientAnalytics" :key="client.id" class="mld-insights-client" @click="selectClientForInsights(client)">
                    <div class="mld-insights-client__main">
                        <div class="mld-insights-client__avatar">
                            {{ client.name ? client.name.charAt(0) : '?' }}
                        </div>
                        <div class="mld-insights-client__info">
                            <h3 class="mld-insights-client__name">{{ client.name }}</h3>
                            <p class="mld-insights-client__last-active">
                                {{ client.days_since_activity === 0 ? 'Active today' : client.days_since_activity === 1 ? 'Active yesterday' : client.days_since_activity + ' days ago' }}
                            </p>
                        </div>
                    </div>

                    <div class="mld-insights-client__stats">
                        <div class="mld-insights-client__stat" title="Properties viewed (7d)">
                            🏠 {{ client.quick_stats?.properties_viewed_7d || 0 }}
                        </div>
                        <div class="mld-insights-client__stat" title="Searches (7d)">
                            🔍 {{ client.quick_stats?.searches_7d || 0 }}
                        </div>
                        <div class="mld-insights-client__stat" title="Favorites">
                            ❤️ {{ client.quick_stats?.favorites_count || 0 }}
                        </div>
                    </div>

                    <div :class="['mld-insights-score', getEngagementScoreClass(client.engagement_score)]">
                        <div class="mld-insights-score__value">{{ Math.round(client.engagement_score || 0) }}</div>
                        <span :class="['mld-insights-score__trend', getScoreTrendClass(client.score_trend)]">
                            {{ getScoreTrendIcon(client.score_trend) }}
                        </span>
                    </div>

                    <div class="mld-insights-client__arrow">→</div>
                </div>
            </div>

            <div v-else-if="!clientAnalyticsLoading" class="mld-empty-state">
                <div class="mld-empty-state__icon">📊</div>
                <h3>No client activity yet</h3>
                <p>Client engagement data will appear here as your clients use the platform.</p>
            </div>
        </section>

        <!-- Settings Tab -->
        <section v-else-if="currentTab === 'settings'" class="mld-dashboard__section">
            <h1 class="mld-dashboard__title">Settings</h1>

            <div class="mld-settings">
                <div class="mld-settings__group">
                    <h2 class="mld-settings__heading">Email Preferences</h2>

                    <div class="mld-setting">
                        <label class="mld-setting__label">
                            <input type="checkbox" v-model="emailPrefs.digest_enabled" @change="saveEmailPrefs">
                            <span>Enable digest emails</span>
                        </label>
                        <p class="mld-setting__help">Combine all your saved search alerts into one email</p>
                    </div>

                    <div class="mld-setting" v-if="emailPrefs.digest_enabled">
                        <label class="mld-setting__label">Digest Frequency</label>
                        <select v-model="emailPrefs.digest_frequency" @change="saveEmailPrefs" class="mld-select">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>

                    <div class="mld-setting" v-if="emailPrefs.digest_enabled">
                        <label class="mld-setting__label">Preferred Time</label>
                        <select v-model="emailPrefs.digest_time" @change="saveEmailPrefs" class="mld-select">
                            <option value="06:00:00">6:00 AM</option>
                            <option value="07:00:00">7:00 AM</option>
                            <option value="08:00:00">8:00 AM</option>
                            <option value="09:00:00">9:00 AM</option>
                            <option value="10:00:00">10:00 AM</option>
                            <option value="12:00:00">12:00 PM</option>
                            <option value="17:00:00">5:00 PM</option>
                            <option value="18:00:00">6:00 PM</option>
                            <option value="19:00:00">7:00 PM</option>
                        </select>
                    </div>

                    <div class="mld-setting">
                        <label class="mld-setting__label">
                            <input type="checkbox" v-model="emailPrefs.global_pause" @change="saveEmailPrefs">
                            <span>Pause all email notifications</span>
                        </label>
                        <p class="mld-setting__help">Temporarily stop receiving all property alerts</p>
                    </div>

                    <div class="mld-setting">
                        <label class="mld-setting__label">Timezone</label>
                        <select v-model="emailPrefs.timezone" @change="saveEmailPrefs" class="mld-select">
                            <option value="America/New_York">Eastern Time</option>
                            <option value="America/Chicago">Central Time</option>
                            <option value="America/Denver">Mountain Time</option>
                            <option value="America/Los_Angeles">Pacific Time</option>
                        </select>
                    </div>
                </div>

                <div class="mld-settings__group" v-if="emailStats">
                    <h2 class="mld-settings__heading">Email Analytics</h2>
                    <div class="mld-settings__stats">
                        <div class="mld-mini-stat">
                            <span class="mld-mini-stat__value">{{ emailStats.total_sent }}</span>
                            <span class="mld-mini-stat__label">Emails sent (30 days)</span>
                        </div>
                        <div class="mld-mini-stat">
                            <span class="mld-mini-stat__value">{{ emailStats.open_rate }}%</span>
                            <span class="mld-mini-stat__label">Open rate</span>
                        </div>
                        <div class="mld-mini-stat">
                            <span class="mld-mini-stat__value">{{ emailStats.total_clicks }}</span>
                            <span class="mld-mini-stat__label">Link clicks</span>
                        </div>
                    </div>
                </div>

                <!-- Delete Account Section (Apple App Store Guideline 5.1.1(v) compliance) -->
                <div class="mld-settings__group mld-settings__group--danger">
                    <h2 class="mld-settings__heading">Delete Account</h2>
                    <p class="mld-settings__description">
                        Permanently delete your account and all associated data. This action cannot be undone.
                    </p>
                    <button @click="openDeleteAccountModal" class="mld-btn mld-btn--danger">
                        Delete My Account
                    </button>
                </div>
            </div>
        </section>
    </main>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="mld-modal" @click.self="showDeleteModal = false">
        <div class="mld-modal__content">
            <h3>Delete Saved Search?</h3>
            <p>Are you sure you want to delete "{{ searchToDelete?.name }}"? This cannot be undone.</p>
            <div class="mld-modal__actions">
                <button @click="showDeleteModal = false" class="mld-btn mld-btn--secondary">Cancel</button>
                <button @click="deleteSearch" class="mld-btn mld-btn--danger">Delete</button>
            </div>
        </div>
    </div>

    <!-- Create Client Modal (for agents) -->
    <div v-if="showCreateClientModal" class="mld-modal" @click.self="closeCreateClientModal">
        <div class="mld-modal__content mld-modal__content--form">
            <div class="mld-modal__header">
                <h3>Add New Client</h3>
                <button @click="closeCreateClientModal" class="mld-modal__close">&times;</button>
            </div>
            <form @submit.prevent="createClient" class="mld-form">
                <div class="mld-form__group">
                    <label for="client-email" class="mld-form__label">Email *</label>
                    <input
                        type="email"
                        id="client-email"
                        v-model="newClient.email"
                        class="mld-form__input"
                        placeholder="client@example.com"
                        required
                    >
                </div>
                <div class="mld-form__row">
                    <div class="mld-form__group">
                        <label for="client-first-name" class="mld-form__label">First Name *</label>
                        <input
                            type="text"
                            id="client-first-name"
                            v-model="newClient.first_name"
                            class="mld-form__input"
                            placeholder="John"
                            required
                        >
                    </div>
                    <div class="mld-form__group">
                        <label for="client-last-name" class="mld-form__label">Last Name</label>
                        <input
                            type="text"
                            id="client-last-name"
                            v-model="newClient.last_name"
                            class="mld-form__input"
                            placeholder="Doe"
                        >
                    </div>
                </div>
                <div class="mld-form__group">
                    <label for="client-phone" class="mld-form__label">Phone</label>
                    <input
                        type="tel"
                        id="client-phone"
                        v-model="newClient.phone"
                        class="mld-form__input"
                        placeholder="(555) 123-4567"
                    >
                </div>
                <div class="mld-form__group mld-form__group--checkbox">
                    <label class="mld-form__checkbox-label">
                        <input
                            type="checkbox"
                            v-model="newClient.send_notification"
                            class="mld-form__checkbox"
                        >
                        <span>Send welcome email to client</span>
                    </label>
                </div>
                <div class="mld-modal__actions">
                    <button type="button" @click="closeCreateClientModal" class="mld-btn mld-btn--secondary">Cancel</button>
                    <button type="submit" class="mld-btn mld-btn--primary">Add Client</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cancel Appointment Modal -->
    <div v-if="showCancelModal" class="mld-modal" @click.self="closeCancelModal">
        <div class="mld-modal__content">
            <h3>Cancel Appointment?</h3>
            <p>Are you sure you want to cancel this appointment?</p>
            <div v-if="appointmentToCancel" class="mld-modal__appointment-preview">
                <strong>{{ appointmentToCancel.type_name || 'Appointment' }}</strong><br>
                {{ formatAppointmentDate(appointmentToCancel.appointment_date) }} at {{ formatAppointmentTime(appointmentToCancel.start_time) }}
            </div>
            <div class="mld-modal__actions">
                <button @click="closeCancelModal" class="mld-btn mld-btn--secondary">Keep Appointment</button>
                <button @click="confirmCancelAppointment" class="mld-btn mld-btn--danger">Cancel Appointment</button>
            </div>
        </div>
    </div>

    <!-- Reschedule Appointment Modal -->
    <div v-if="showRescheduleModal" class="mld-modal" @click.self="closeRescheduleModal">
        <div class="mld-modal__content mld-modal__content--form">
            <div class="mld-modal__header">
                <h3>Reschedule Appointment</h3>
                <button @click="closeRescheduleModal" class="mld-modal__close">&times;</button>
            </div>

            <div v-if="appointmentToReschedule" class="mld-modal__appointment-preview">
                <strong>{{ appointmentToReschedule.type_name || 'Appointment' }}</strong><br>
                <span class="mld-text-muted">Current: {{ formatAppointmentDate(appointmentToReschedule.appointment_date) }} at {{ formatAppointmentTime(appointmentToReschedule.start_time) }}</span>
            </div>

            <div class="mld-form">
                <div class="mld-form__group">
                    <label class="mld-form__label">Select New Date</label>
                    <input
                        type="date"
                        v-model="rescheduleDate"
                        @change="loadRescheduleSlots"
                        :min="getMinDate()"
                        class="mld-form__input"
                    >
                </div>

                <div v-if="rescheduleSlotsLoading" class="mld-time-slots-loading">
                    <div class="mld-spinner mld-spinner--small"></div>
                    <span>Loading available times...</span>
                </div>

                <div v-else-if="rescheduleDate && rescheduleSlots.length > 0" class="mld-form__group">
                    <label class="mld-form__label">Select Time</label>
                    <div class="mld-time-slots">
                        <button
                            v-for="slot in rescheduleSlots"
                            :key="slot.time || slot"
                            type="button"
                            :class="['mld-time-slot', { 'is-selected': selectedSlot === slot || selectedSlot === (slot.time || slot) }]"
                            @click="selectTimeSlot(slot)"
                        >
                            {{ slot.display || formatAppointmentTime(slot.time || slot) }}
                        </button>
                    </div>
                </div>

                <div v-else-if="rescheduleDate && rescheduleSlots.length === 0" class="mld-empty-state mld-empty-state--compact">
                    <p>No available times on this date. Please select a different date.</p>
                </div>
            </div>

            <div class="mld-modal__actions">
                <button type="button" @click="closeRescheduleModal" class="mld-btn mld-btn--secondary">Cancel</button>
                <button
                    type="button"
                    @click="confirmReschedule"
                    :disabled="!rescheduleDate || !selectedSlot"
                    :class="['mld-btn', 'mld-btn--primary', { 'mld-btn--disabled': !rescheduleDate || !selectedSlot }]"
                >
                    Confirm Reschedule
                </button>
            </div>
        </div>
    </div>

    <!-- Share Property Modal (for agents) -->
    <div v-if="showSharePropertyModal && isAgent" class="mld-modal" @click.self="closeSharePropertyModal">
        <div class="mld-modal__content mld-modal__content--share">
            <div class="mld-modal__header">
                <h3>Share Property with Client</h3>
                <button @click="closeSharePropertyModal" class="mld-modal__close">&times;</button>
            </div>

            <div class="mld-share-modal">
                <!-- Selected Client -->
                <div class="mld-share-modal__section">
                    <label class="mld-share-modal__label">Client</label>
                    <div v-if="shareTargetClient" class="mld-share-modal__selected">
                        <div class="mld-share-modal__selected-item">
                            <div class="mld-share-modal__avatar">
                                {{ shareTargetClient.first_name ? shareTargetClient.first_name.charAt(0) : shareTargetClient.email.charAt(0) }}
                            </div>
                            <div class="mld-share-modal__info">
                                <span class="mld-share-modal__name">{{ shareTargetClient.first_name }} {{ shareTargetClient.last_name }}</span>
                                <span class="mld-share-modal__email">{{ shareTargetClient.email }}</span>
                            </div>
                            <button v-if="shareModalMode === 'select-client'" @click="shareTargetClient = null" class="mld-share-modal__remove">&times;</button>
                        </div>
                    </div>
                    <div v-else-if="shareModalMode === 'select-client'" class="mld-share-modal__list">
                        <div
                            v-for="client in clients"
                            :key="client.id"
                            class="mld-share-modal__option"
                            @click="selectClientForShare(client)"
                        >
                            <div class="mld-share-modal__avatar">
                                {{ client.first_name ? client.first_name.charAt(0) : client.email.charAt(0) }}
                            </div>
                            <div class="mld-share-modal__info">
                                <span class="mld-share-modal__name">{{ client.first_name || '' }} {{ client.last_name || '' }}</span>
                                <span class="mld-share-modal__email">{{ client.email }}</span>
                            </div>
                        </div>
                        <div v-if="clients.length === 0" class="mld-share-modal__empty">
                            No clients yet. Add a client first.
                        </div>
                    </div>
                </div>

                <!-- Selected Property -->
                <div class="mld-share-modal__section">
                    <label class="mld-share-modal__label">Property</label>
                    <div v-if="shareTargetProperty" class="mld-share-modal__selected">
                        <div class="mld-share-modal__property">
                            <img :src="shareTargetProperty.photo_url || shareTargetProperty.main_photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'" :alt="shareTargetProperty.address" class="mld-share-modal__property-img">
                            <div class="mld-share-modal__property-info">
                                <span class="mld-share-modal__property-price">${{ formatPrice(shareTargetProperty.price || shareTargetProperty.list_price) }}</span>
                                <span class="mld-share-modal__property-address">{{ shareTargetProperty.address || (shareTargetProperty.street_number + ' ' + shareTargetProperty.street_name) }}</span>
                                <span class="mld-share-modal__property-location">{{ shareTargetProperty.city }}, {{ shareTargetProperty.state || shareTargetProperty.state_or_province }}</span>
                            </div>
                            <button v-if="shareModalMode === 'select-property'" @click="shareTargetProperty = null" class="mld-share-modal__remove">&times;</button>
                        </div>
                    </div>
                    <div v-else-if="shareModalMode === 'select-property'" class="mld-share-modal__search">
                        <input
                            type="text"
                            v-model="propertySearchQuery"
                            @input="searchProperties"
                            placeholder="Search by address, city, or MLS#"
                            class="mld-form__input"
                        >
                        <div v-if="propertySearchLoading" class="mld-share-modal__loading">
                            <div class="mld-spinner mld-spinner--small"></div>
                            <span>Searching...</span>
                        </div>
                        <div v-else-if="propertySearchResults.length > 0" class="mld-share-modal__results">
                            <div
                                v-for="property in propertySearchResults"
                                :key="property.id || property.listing_key"
                                class="mld-share-modal__property mld-share-modal__property--option"
                                @click="selectPropertyForShare(property)"
                            >
                                <img :src="property.photo_url || property.main_photo_url || '/wp-content/plugins/mls-listings-display/assets/images/no-photo.jpg'" :alt="property.address" class="mld-share-modal__property-img">
                                <div class="mld-share-modal__property-info">
                                    <span class="mld-share-modal__property-price">${{ formatPrice(property.price || property.list_price) }}</span>
                                    <span class="mld-share-modal__property-address">{{ property.address }}</span>
                                    <span class="mld-share-modal__property-location">{{ property.city }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-else-if="propertySearchQuery && propertySearchQuery.length >= 2" class="mld-share-modal__empty">
                            No properties found. Try a different search.
                        </div>
                    </div>
                </div>

                <!-- Note -->
                <div class="mld-share-modal__section">
                    <label class="mld-share-modal__label">Add a note (optional)</label>
                    <textarea
                        v-model="shareNote"
                        placeholder="I think this property would be perfect for you!"
                        class="mld-form__textarea"
                        rows="3"
                    ></textarea>
                </div>
            </div>

            <div class="mld-modal__actions">
                <button type="button" @click="closeSharePropertyModal" class="mld-btn mld-btn--secondary">Cancel</button>
                <button
                    type="button"
                    @click="sharePropertyWithClient"
                    :disabled="!shareTargetClient || !shareTargetProperty || shareSending"
                    :class="['mld-btn', 'mld-btn--primary', { 'mld-btn--disabled': !shareTargetClient || !shareTargetProperty }]"
                >
                    <span v-if="shareSending">Sending...</span>
                    <span v-else>Share Property</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Account Confirmation Modal (Apple App Store Guideline 5.1.1(v) compliance) -->
    <div v-if="showDeleteAccountModal" class="mld-modal mld-modal--danger" @click.self="closeDeleteAccountModal">
        <div class="mld-modal__content mld-modal__content--delete-account">
            <div class="mld-modal__header mld-modal__header--danger">
                <div class="mld-modal__icon mld-modal__icon--danger">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <h3>Delete Account</h3>
                <button @click="closeDeleteAccountModal" class="mld-modal__close" :disabled="deleteAccountLoading">&times;</button>
            </div>

            <div class="mld-modal__body">
                <div class="mld-delete-account-warning">
                    <p><strong>This action will permanently delete:</strong></p>
                    <ul>
                        <li>Your account and profile information</li>
                        <li>All saved searches and notifications</li>
                        <li>Saved and hidden properties</li>
                        <li>Appointment history</li>
                        <li>All activity and preferences</li>
                    </ul>
                    <p class="mld-delete-account-warning__emphasis">This action cannot be undone.</p>
                </div>

                <div class="mld-form__group">
                    <label class="mld-form__label">Type <strong>DELETE</strong> to confirm:</label>
                    <input
                        type="text"
                        v-model="deleteAccountConfirmText"
                        class="mld-form__input"
                        placeholder="DELETE"
                        :disabled="deleteAccountLoading"
                        autocomplete="off"
                    >
                </div>

                <div v-if="deleteAccountError" class="mld-alert mld-alert--error">
                    {{ deleteAccountError }}
                </div>
            </div>

            <div class="mld-modal__actions">
                <button
                    type="button"
                    @click="closeDeleteAccountModal"
                    class="mld-btn mld-btn--secondary"
                    :disabled="deleteAccountLoading"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="deleteAccount"
                    :disabled="!canDeleteAccount"
                    :class="['mld-btn', 'mld-btn--danger', { 'mld-btn--disabled': !canDeleteAccount }]"
                >
                    <span v-if="deleteAccountLoading">Deleting...</span>
                    <span v-else>Delete My Account</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div v-if="toast.show" :class="['mld-toast', 'mld-toast--' + toast.type]">
        {{ toast.message }}
    </div>
</div>

<style>
[v-cloak] { display: none; }
</style>
