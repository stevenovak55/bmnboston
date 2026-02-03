# Agent-Client Collaboration System - Handoff Documentation

**Date:** January 2-3, 2026
**Session:** Phases 1, 2, 3 & 4 Implementation
**Next Phase:** Phase 5 (iOS App Updates)

---

## Executive Summary

Implemented the backend infrastructure and client web dashboard for a Zillow/Redfin-competitive agent-client collaboration system for BMN Boston. Phases 1, 2, 3, and 4 are complete - all API endpoints and the Vue.js client dashboard are tested and deployed to production.

---

## Completed Work

### Phase 1: User Type System ✅

**New Database Tables:**
- `wp_mld_user_types` - Tracks user types (client/agent/admin)

**New Files Created:**
- `includes/class-mld-user-type-manager.php` (~300 lines)
  - `get_user_type($user_id)` - Get user's type
  - `set_user_type($user_id, $type)` - Set user's type
  - `is_client($user_id)` / `is_agent($user_id)` / `is_admin($user_id)`
  - `promote_to_agent($user_id, $promoted_by, $profile_data)`
  - `demote_to_client($agent_id, $demoted_by)`

**Database Columns Added to `wp_mld_agent_profiles`:**
- `title` VARCHAR(100)
- `social_links` JSON
- `service_areas` TEXT
- `snab_staff_id` BIGINT UNSIGNED
- `email_signature` TEXT
- `custom_greeting` TEXT

**New REST API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/mld-mobile/v1/agents` | List all active agents |
| GET | `/mld-mobile/v1/agents/{id}` | Get agent profile |
| GET | `/mld-mobile/v1/my-agent` | Get client's assigned agent |

**Enhanced Endpoints:**
- `GET /users/me` - Now includes `user_type` and `assigned_agent` fields

---

### Phase 2: Collaborative Saved Searches ✅

**New Database Tables:**
- `wp_mld_saved_search_activity` - Activity log for saved searches

**Database Columns Added to `wp_mld_saved_searches`:**
- `created_by_user_id` BIGINT UNSIGNED
- `last_modified_by_user_id` BIGINT UNSIGNED
- `last_modified_at` DATETIME
- `is_agent_recommended` TINYINT(1)
- `agent_notes` TEXT
- `cc_agent_on_notify` TINYINT(1)

**New Files Created:**
- `includes/saved-searches/class-mld-saved-search-collaboration.php` (~400 lines)
  - `is_agent_for_client($agent_user_id, $client_user_id)`
  - `get_agent_clients_with_searches($agent_user_id)`
  - `get_client_searches($agent_user_id, $client_user_id)`
  - `create_search_for_client($agent_user_id, $client_user_id, $search_data)`
  - `update_search($search_id, $user_id, $update_data)`
  - `get_activity_log($search_id, $limit)`
  - `get_agent_metrics($agent_user_id, $period)`
  - `can_access_search($search_id, $user_id)`
  - `log_activity($search_id, $user_id, $action_type, $details)`

**New REST API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/agent/clients` | List agent's clients with search counts |
| GET | `/agent/clients/{id}/searches` | Get client's saved searches |
| POST | `/agent/clients/{id}/searches` | Create search for client |
| GET | `/agent/searches` | All client searches (filterable) |
| GET | `/agent/metrics` | Agent dashboard metrics |
| GET | `/saved-searches/{id}/activity` | Activity log for a search |
| POST | `/agent/clients/{id}/assign` | Assign client to agent |
| DELETE | `/agent/clients/{id}/assign` | Unassign client from agent |

**Enhanced Endpoints:**
- `GET /saved-searches` - Now includes collaboration fields
- `POST /saved-searches` - Supports `for_client_id` parameter

---

### Phase 3: Email System Overhaul ✅

**New Database Tables:**
- `wp_mld_user_email_preferences` - User email preferences (digest, format, timezone)
- `wp_mld_email_analytics` - Email tracking (opens, clicks)

**New Files Created:**
- `includes/saved-searches/class-mld-email-template-engine.php` (~900 lines)
  - Modular email builder with component system
  - `render($template_name, $data)` - Render complete email
  - `set_agent($agent)` - Set agent for co-branding
  - `set_client($client)` - Set client data
  - `set_theme($config)` - Custom theme colors/branding
  - Component methods: `component_header()`, `component_agent_card()`, `component_property_card()`, `component_footer()`, `component_market_stats()`, `component_cta_button()`
  - Template types: `single-alert`, `daily-digest`, `weekly-roundup`, `welcome`, `agent-intro`
  - Email analytics: `record_send()`, `record_open()`, `record_click()`
  - Click/open tracking with unique email IDs

- `includes/saved-searches/class-mld-digest-processor.php` (~500 lines)
  - `collect_for_user($user_id, $frequency)` - Collect pending changes
  - `deduplicate_properties($collected)` - Remove duplicates across searches
  - `build_digest($user_id, $type)` - Build digest email data
  - `process_pending_digests($frequency)` - Cron job handler
  - `get_user_digest_stats($user_id)` - Stats for preferences UI
  - Highlight detection (best price drops, newest listings)
  - Market trend calculation

**New REST API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/email-preferences` | Get user's email preferences |
| POST | `/email-preferences` | Update email preferences |
| GET | `/email/track/open?eid=xxx` | Track email opens (returns 1x1 GIF) |
| GET | `/email/track/click?eid=xxx&url=xxx` | Track clicks and redirect |

**Email Preferences Fields:**
- `digest_enabled` - Enable daily/weekly digest emails
- `digest_frequency` - 'daily' or 'weekly'
- `digest_time` - Preferred send time (e.g., '09:00:00')
- `preferred_format` - 'html' or 'plain'
- `global_pause` - Pause all notifications
- `timezone` - User timezone (e.g., 'America/New_York')
- `unsubscribed_at` - Timestamp if unsubscribed

**Agent Co-Branding Features:**
- Agent photo (circular, 80x80)
- Agent name and title
- Agent phone and email with clickable buttons
- Custom greeting message
- Contact buttons (Call Me, Email Me)
- Agent info in footer

---

### Phase 4: Client Web Dashboard ✅

**New Files Created:**

- `includes/class-mld-client-dashboard.php` (~160 lines)
  - `init()` - Register shortcode and enqueue hooks
  - `maybe_enqueue_assets()` - Conditional asset loading
  - `enqueue_assets()` - Load Vue.js, CSS, JS with config
  - `render_shortcode()` - Render dashboard or login prompt
  - `render_login_prompt()` - Show login for unauthenticated users

- `templates/client-dashboard.php` (~330 lines)
  - Vue.js 3 template with 5 tabs: Overview, Searches, Favorites, Agent, Settings
  - Overview: Stats cards, agent card, recent activity
  - Searches: Grid of saved searches with pause/delete actions
  - Favorites: Property card grid with remove action
  - Agent: Full agent profile with contact buttons
  - Settings: Email preferences with digest options
  - Modal: Delete confirmation
  - Toast: Success/error notifications

- `assets/js/dashboard/mld-client-dashboard.js` (~450 lines)
  - Vue.js 3 Composition API app
  - State management: loading, currentTab, user, agent, savedSearches, favorites
  - API helpers: `apiRequest()` with nonce auth
  - Data loading: `loadDashboardData()` with parallel fetches
  - Search actions: `toggleSearchPause()`, `confirmDeleteSearch()`, `deleteSearch()`
  - Favorites: `removeFavorite()`
  - Email preferences: `saveEmailPrefs()`
  - Formatters: `formatPrice()`, `formatTimeAgo()`, `formatSearchCriteria()`
  - Hash-based tab navigation

- `assets/css/dashboard/mld-client-dashboard.css` (~700 lines)
  - CSS custom properties for theming
  - Responsive navigation with mobile support
  - Stat cards, agent cards, search cards, property cards
  - Settings form with checkboxes and selects
  - Modal and toast components
  - Mobile-first responsive breakpoints

**Shortcode:** `[mld_client_dashboard]`

**URL Structure:**
```
/my-dashboard/                 # Overview (default)
/my-dashboard/#overview        # Overview
/my-dashboard/#searches        # Saved searches
/my-dashboard/#favorites       # Favorite properties
/my-dashboard/#agent           # My agent
/my-dashboard/#settings        # Email preferences
```

**Features:**
- Tab navigation with hash-based routing
- Real-time stats (saved searches count, favorites count, new matches)
- Agent card with contact buttons (phone, email, schedule showing)
- Saved searches with pause/resume and delete
- Favorites grid with property cards
- Email preference management (digest, frequency, time, timezone)
- Email analytics display (sends, opens, clicks)
- Toast notifications for actions
- Login prompt for unauthenticated users
- Mobile-responsive design

---

## Files Modified

| File | Changes |
|------|---------|
| `mls-listings-display.php` | Added requires for new classes, version bump to 6.32.1 |
| `version.json` | Updated to 6.32.1 |
| `includes/class-mld-mobile-rest-api.php` | Added ~20 new endpoints and handlers (~600 lines added) |
| `includes/saved-searches/class-mld-saved-search-database.php` | Added email preferences and analytics tables |
| `includes/saved-searches/class-mld-agent-client-manager.php` | Added `assign_agent_to_client()`, `get_agent_by_user_id()`, SNAB linking |

---

## Current Test Data

**Users:**
- User ID 1 (`mail@steve-novak.com`) - Agent
- User ID 5 (`s.novak55@gmail.com`) - Client assigned to Agent 1

**Test Assignment:**
- Agent 1 has Client 5 assigned
- Client 5 has 1 saved search created by Agent 1
- User 1 has email preferences with daily digest at 9:00 AM

---

## API Testing Commands

```bash
# Get fresh token (on server)
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 "cd ~/public && php -r \"
require_once 'wp-load.php';
require_once 'wp-content/plugins/mls-listings-display/mls-listings-display.php';
\\\$reflection = new ReflectionMethod('MLD_Mobile_REST_API', 'generate_jwt');
\\\$reflection->setAccessible(true);
echo \\\$reflection->invoke(null, 1, 'access');
\""

# Test endpoints (replace TOKEN)
TOKEN="your_token_here"

# List agents
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/agents"

# Get my agent (as client)
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/my-agent" -H "Authorization: Bearer $TOKEN"

# List my clients (as agent)
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/agent/clients" -H "Authorization: Bearer $TOKEN"

# Get agent metrics
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/agent/metrics" -H "Authorization: Bearer $TOKEN"

# Get email preferences
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/email-preferences" -H "Authorization: Bearer $TOKEN"

# Update email preferences
curl -s -X POST "https://bmnboston.com/wp-json/mld-mobile/v1/email-preferences" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"digest_enabled":true,"digest_frequency":"daily","digest_time":"09:00:00"}'

# Get saved searches (for dashboard)
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/saved-searches" -H "Authorization: Bearer $TOKEN"

# Get favorites (for dashboard)
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/favorites" -H "Authorization: Bearer $TOKEN"
```

---

## Remaining Phases

### Phase 5: iOS App Updates
- Update User and SavedSearch models
- Create Agent model and AgentService
- Update SavedSearchDetailView with agent badge
- Create MyAgentCard component
- Update PropertyDetailView for agent contact

### Phase 6: WordPress Admin Dashboard
- Create agent dashboard page
- User type management UI
- Agent-client assignment interface
- Email template preview

---

## Deployment Commands

```bash
# Upload file
sshpass -p 'cFDIB2uPBj5LydX' scp -P 57105 /path/to/file.php \
  stevenovakcom@35.236.219.140:~/public/wp-content/plugins/mls-listings-display/includes/

# Invalidate opcache
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "touch ~/public/wp-content/plugins/mls-listings-display/includes/*.php"

# Fix file permissions (required after SCP upload)
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "chmod 644 ~/public/wp-content/plugins/mls-listings-display/path/to/file.php"
```

---

## To Use the Dashboard

1. Create a WordPress page at `/my-dashboard/`
2. Add the shortcode `[mld_client_dashboard]` to the page content
3. Users must be logged in to access the dashboard
4. Non-logged-in users see a login prompt

---

## Version Information

| Component | Version |
|-----------|---------|
| MLS Listings Display | 6.32.1 |
| BMN Schools | 0.6.36 |
| SN Appointments | 1.8.0 |
| iOS App | v135 |
