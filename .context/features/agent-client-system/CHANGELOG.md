# Agent-Client System Changelog

All notable changes to the Agent-Client Management System will be documented in this file.

## [6.43.0] - 2026-01-04 - AGENT CLIENT ACTIVITY NOTIFICATIONS

### Added
- **Real-time activity notifications** - Agents receive email AND push notifications when clients:
  - Log in to their profile (iOS or web)
  - Open the iOS app (with 2-hour debounce to avoid spam)
  - Favorite a property
  - Create a saved search
  - Request a tour/showing

- **Per-type notification preferences** - Agents can enable/disable each notification type independently for both email and push channels

- **New REST API endpoints**:
  - `GET /agent/notification-preferences` - Get agent's notification settings
  - `PUT /agent/notification-preferences` - Update notification settings
  - `POST /app/opened` - iOS app reports launch (triggers agent notification)

- **New notification classes**:
  - `MLD_Agent_Notification_Preferences` - Per-type toggle management
  - `MLD_Agent_Notification_Log` - Notification delivery tracking
  - `MLD_Agent_Notification_Email` - Branded HTML email builder
  - `MLD_Agent_Activity_Notifier` - Main orchestrator with event hooks

- **New database tables**:
  - `wp_mld_agent_notification_preferences` - Per-agent, per-type settings
  - `wp_mld_agent_notification_log` - Notification delivery tracking
  - `wp_mld_client_app_opens` - 2-hour debounce for app opens

### Modified
- `class-mld-mobile-rest-api.php` - Added hooks for login, favorite, saved search events
- `class-mld-saved-search-database.php` - Added new table definitions (DB_VERSION 1.6.0)
- `class-mld-push-notifications.php` - Added `send_activity_notification()` method
- `class-snab-rest-api.php` - Added hook for tour request notifications

### iOS Integration Required
1. Call `POST /app/opened` on app launch
2. Add notification preferences UI in agent settings
3. Handle `CLIENT_ACTIVITY` push notification category

---

## [6.42.0-6.42.1] - 2026-01-04

### Added
- Client preferences/profile analytics
- Location preferences (top cities, neighborhoods, ZIPs)
- Property preferences (beds, baths, sqft, price, garage, property types)
- Engagement patterns (activity by hour/day, favorites, searches)
- Profile strength score (0-100) with component breakdown
- REST API endpoint `/agent/clients/{id}/preferences`

### Fixed
- Property preferences now populate correctly
- Removed subdivision_name from summary table queries

---

## [6.41.0-6.41.3] - 2026-01-04

### Added
- "Most Viewed Properties" section in Client Insights dashboard
- Rich activity timeline with property addresses, photos, prices
- Activity icons and descriptions for 25+ activity types
- REST API endpoint `/agent/clients/{id}/most-viewed`

### Fixed
- Activity enrichment now works for iOS activities (listing_key hash lookup)

---

## [6.40.0] - 2026-01-04

### Added
- Client engagement scoring system (0-100 scale)
- Property interest tracking with weighted scores
- "Client Insights" tab for agents in dashboard
- Engagement score trend analysis (rising/falling/stable)
- Daily/hourly cron jobs for engagement score and property interest aggregation

---

## [Pre-6.33.0] - 2026-01-03

### Foundation
- Initial system audit completed
- iOS "My Clients" tab for agents
- iOS "My Agent" card for clients
- Web dashboard with Vue.js
- WordPress admin pages (Agents, Clients)
- Team Member CPT sync to agent profiles
- Client creation from iOS and admin

### Known Issues
- Database schema in activator.php doesn't match actual table structure
- No SNAB integration (agents not linked to booking staff)
- Web dashboard missing client creation
- No property sharing capability
- No behavioral analytics tracking

---

## Version Planning

### 6.33.0 - Foundation (Sprint 1)
- Fix database schema mismatches
- Add SNAB staff linkage to agents
- Add "Book with Your Agent" button
- Multi-agent admin filtering

### 6.34.0 - Web Parity (Sprint 2)
- Client creation on web dashboard
- Agent metrics panel
- Agent notes on searches

### 6.35.0 - Property Sharing (Sprint 3)
- wp_mld_shared_properties table
- Share property REST endpoint
- iOS share flow
- Web share flow

### 6.36.0 - Agent Searches (Sprint 4)
- Agent creates search for client
- Agent notes on searches
- "Agent Pick" badges

### 6.37.0 - Analytics (Sprint 5)
- wp_mld_client_activity table
- wp_mld_client_sessions table
- wp_mld_client_analytics_summary table
- iOS activity tracker
- Web activity tracker
- Engagement scoring

### 6.38.0 - Notifications (Sprint 6)
- Push notification system
- Smart alerts based on analytics
- Admin analytics dashboard
