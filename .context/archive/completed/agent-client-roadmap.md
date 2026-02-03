# Agent-Client System Roadmap

## Project Status: Sprint 6 Complete + iOS Integration Complete
**Last Updated:** 2026-01-12
**Current Version:** v6.57.0
**Next Focus:** Future enhancements (see bottom of document)

---

## Sprint Progress

### Sprint 1: Foundation & Database ✅ Complete (v6.33.0)
- [x] Database schema alignment
- [x] Agent-SNAB Staff Linkage
- [x] "Book with Your Agent" button

### Sprint 2: Web Dashboard Parity ✅ Complete (v6.33.1-v6.34.x)
- [x] Agent Features on Web Dashboard
  - [x] Add "Create Client" modal to My Clients tab
  - [x] Add agent metrics/stats panel
  - [x] Client search management
- [x] Improved Client Views
  - [x] Show agent notes on saved searches
  - [x] "Agent Pick" badges

### Sprint 3: Property Sharing ✅ Complete (v6.35.x)
- [x] Database: wp_mld_shared_properties table
- [x] Backend: class-mld-property-sharing.php
- [x] REST endpoints for sharing
- [x] iOS: Share button on property detail
- [x] Web: Share flow from agent dashboard
- [x] "From Your Agent" section for clients
- [x] Mark as interested/not interested

### Sprint 4: Agent-Created Searches ✅ Complete (v6.36.x)
- [x] created_by_agent_id on saved searches
- [x] REST endpoint: POST /agent/clients/{id}/searches
- [x] Batch search creation endpoint
- [x] "Agent Pick" badge on search cards
- [x] Agent notes display

### Sprint 5: Comprehensive Analytics ✅ Complete (v6.40.x-v6.42.x)
- [x] Database Tables
  - [x] wp_mld_client_activity (raw events)
  - [x] wp_mld_client_engagement_scores
  - [x] wp_mld_client_property_interest
- [x] Tracking Implementation
  - [x] iOS: ActivityTracker.swift (15+ event types)
  - [x] Web: mld-analytics-tracker.js
  - [x] REST: Analytics endpoints
- [x] Agent Dashboard
  - [x] Engagement score calculation (0-100)
  - [x] Client activity timeline with property enrichment
  - [x] Most viewed properties (2+ views)
  - [x] Client preferences/profile analytics
  - [x] Profile strength score (0-100)
- [x] Cron Jobs
  - [x] Hourly engagement score calculation
  - [x] Daily property interest aggregation

### Sprint 6: Push Notifications & Polish ✅ Complete (v6.43.0)
- [x] Agent Activity Notifications
  - [x] MLD_Agent_Activity_Notifier - Main orchestrator
  - [x] MLD_Agent_Notification_Preferences - Per-type toggles
  - [x] MLD_Agent_Notification_Email - Branded HTML emails
  - [x] MLD_Agent_Notification_Log - Delivery tracking
- [x] 5 Notification Triggers
  - [x] Client login (iOS/web)
  - [x] App open (with 2-hour debounce)
  - [x] Property favorited
  - [x] Saved search created
  - [x] Tour requested
- [x] REST API Endpoints
  - [x] GET /agent/notification-preferences
  - [x] PUT /agent/notification-preferences
  - [x] POST /app/opened
- [x] Database Tables
  - [x] wp_mld_agent_notification_preferences
  - [x] wp_mld_agent_notification_log
  - [x] wp_mld_client_app_opens

---

## iOS Integration ✅ Complete

All iOS integration work has been completed:

### 1. App Open Tracking ✅ (v208)
**File:** `BMNBostonApp.swift:167-184`
```swift
private func reportAppOpened() async {
    guard await TokenManager.shared.isAuthenticated() else { return }
    do {
        let _: EmptyResponse = try await APIClient.shared.request(.appOpened)
    } catch {
        // Silently fail - not critical
    }
}
```
Called from `handleScenePhaseChange()` when app becomes `.active`.

### 2. Notification Preferences UI ✅ (v207+)
**File:** `NotificationPreferencesView.swift`
- Server returns notification types dynamically based on user type
- Agent users receive agent-specific notification types
- Toggles for email/push per notification type

### 3. Push Notification Handling ✅ (v207)
**File:** `NotificationItem.swift:154-158`, `NotificationStore.swift`
- `CLIENT_ACTIVITY` maps to `.agentActivity` notification type
- Deep linking to Profile tab on tap
- Property-specific notifications include `listing_id` for navigation

---

## Version History

| Version | Date | Sprint | Status | Changes |
|---------|------|--------|--------|---------|
| 6.57.0 | 2026-01-12 | 6 | ✅ Complete | iOS integration complete (v207-v208) |
| 6.43.0 | 2026-01-04 | 6 | ✅ Complete | Agent activity notifications |
| 6.42.x | 2026-01-04 | 5 | ✅ Complete | Client preferences & profile analytics |
| 6.41.x | 2026-01-04 | 5 | ✅ Complete | Rich activity timeline, most viewed |
| 6.40.0 | 2026-01-04 | 5 | ✅ Complete | Engagement scoring, property interest |
| 6.39.x | 2026-01-04 | - | ✅ Complete | Public site analytics (parallel work) |
| 6.36.x | 2026-01-04 | 4 | ✅ Complete | Agent searches, batch creation |
| 6.35.x | 2026-01-03 | 3 | ✅ Complete | Property sharing |
| 6.34.x | 2026-01-03 | 2 | ✅ Complete | Web dashboard parity |
| 6.33.x | 2026-01-03 | 1-2 | ✅ Complete | Foundation, SNAB integration |

---

## Key Metrics

| Metric | Value | Target | Status |
|--------|-------|--------|--------|
| Agent Profiles | 1 | 3-5 | 🔄 |
| Active Clients | ~5 | 50+ | 🔄 |
| Database Schema | ✅ Aligned | Aligned | ✅ |
| SNAB Integration | ✅ Implemented | Full | ✅ |
| Property Sharing | ✅ Implemented | Full | ✅ |
| Analytics | ✅ Implemented | Full | ✅ |
| Notifications | ✅ Complete | Full (iOS+Backend) | ✅ |

---

## Future Enhancements

The core Agent-Client system is complete. Potential future enhancements include:

1. **Agent Dashboard Enhancements**
   - Smart notification timing (business hours only)
   - Notification digest mode (daily/weekly summaries)
   - Client engagement scoring improvements

2. **Client Experience**
   - In-app messaging with agent
   - Appointment scheduling from shared properties
   - Push notification when agent shares new property

3. **Analytics Expansion**
   - Client preference learning
   - Property recommendation engine
   - Agent performance metrics
