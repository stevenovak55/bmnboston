# Agent-Client Management System

Enterprise-grade agent-client portal for BMN Boston real estate platform.

## Quick Links

- [ROADMAP.md](./ROADMAP.md) - Sprint progress tracking
- [CHANGELOG.md](./CHANGELOG.md) - Version history
- [Session Logs](../../archive/session-logs/agent-client-system/) - Development session notes (archived)

## Overview

This system enables real estate agents to manage their clients, share properties, create searches, and track client engagement across iOS and web platforms.

## Current Status

**Version:** 6.57.0 (Sprint 6 Complete)
**Sprint:** 6 - Push Notifications & Polish (COMPLETE)
**Last Updated:** January 12, 2026

## iOS Integration Status

| Feature | Status | iOS Version | Notes |
|---------|--------|-------------|-------|
| App Open Tracking | ✅ Complete | v208 | `reportAppOpened()` in BMNBostonApp.swift |
| CLIENT_ACTIVITY Handling | ✅ Complete | v207 | Maps to `.agentActivity` notification type |
| Agent Models & Service | ✅ Complete | v136+ | `AgentService.swift`, `Agent.swift` |
| Notification Preferences | ✅ Complete | v207+ | `NotificationPreferencesView.swift` |
| Agent-Specific Prefs | 🔄 Server-side | - | Server returns types dynamically by user type |

## Key Components

### Platforms
- **iOS App** - Full agent/client features including push notifications
- **Web Dashboard** - `/my-dashboard/` Vue.js application
- **WordPress Admin** - Agent and client management pages

### Core Features (All Implemented)
1. ✅ Agent-Client relationships (Sprint 1)
2. ✅ Property sharing with notes (Sprint 3)
3. ✅ Agent-created saved searches (Sprint 4)
4. ✅ Comprehensive behavioral analytics (Sprint 5)
5. ✅ Push notifications (Sprint 6)
6. ✅ Engagement scoring (Sprint 5)

## Key Files

### WordPress Plugin (`mls-listings-display`)
| File | Purpose |
|------|---------|
| `includes/saved-searches/class-mld-agent-client-manager.php` | Core agent-client logic |
| `includes/saved-searches/class-mld-client-management-admin.php` | Admin UI handler |
| `includes/class-mld-mobile-rest-api.php` | iOS REST endpoints |
| `templates/client-dashboard.php` | Web dashboard Vue template |
| `includes/class-mld-activator.php` | Database schema |

### iOS App
| File | Purpose |
|------|---------|
| `Core/Models/Agent.swift` | Agent, AgentClient, AgentMetrics models |
| `Core/Services/AgentService.swift` | Agent API calls with caching |
| `Features/AgentClients/Views/MyClientsView.swift` | Agent's client list |
| `UI/Components/MyAgentCard.swift` | Client's agent display |
| `Core/Storage/NotificationStore.swift` | Notification handling including CLIENT_ACTIVITY |
| `Features/Notifications/Views/NotificationPreferencesView.swift` | User notification preferences |
| `App/BMNBostonApp.swift` | App open tracking (`reportAppOpened()`) |

### SNAB Plugin (Appointments)
| File | Purpose |
|------|---------|
| `includes/class-snab-rest-api.php` | Appointment API |
| `includes/class-snab-push-notifications.php` | Push notification system |

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_mld_agent_profiles` | Agent details |
| `wp_mld_agent_client_relationships` | Agent-client assignments |
| `wp_mld_admin_client_preferences` | Email notification prefs |
| `wp_mld_user_types` | User classification |
| `wp_mld_shared_properties` | Property sharing (Sprint 3) |
| `wp_mld_client_activity` | Behavioral tracking (Sprint 5) |
| `wp_mld_client_sessions` | Session management (Sprint 5) |
| `wp_mld_client_analytics_summary` | Daily aggregates (Sprint 5) |

## API Endpoints

### Agent Endpoints
```
GET  /mld-mobile/v1/agents              - List all agents
GET  /mld-mobile/v1/agents/{id}         - Get agent details
GET  /mld-mobile/v1/my-agent            - Get client's agent
GET  /mld-mobile/v1/agent/clients       - Get agent's clients
POST /mld-mobile/v1/agent/clients       - Create client
GET  /mld-mobile/v1/agent/metrics       - Get agent stats
```

### Analytics Endpoints (Sprint 5)
```
POST /mld-mobile/v1/analytics/track     - Track single event
POST /mld-mobile/v1/analytics/batch     - Batch track events
POST /mld-mobile/v1/analytics/session/start
POST /mld-mobile/v1/analytics/session/end
```

### Notification Endpoints (Sprint 6)
```
GET  /mld-mobile/v1/agent/notification-preferences  - Get agent notification prefs
PUT  /mld-mobile/v1/agent/notification-preferences  - Update agent notification prefs
POST /mld-mobile/v1/app/opened                       - Report app open (triggers agent notification)
GET  /mld-mobile/v1/notifications/history            - Get notification history
POST /mld-mobile/v1/notifications/{id}/read          - Mark notification as read
POST /mld-mobile/v1/notifications/{id}/dismiss       - Dismiss notification
```

## Quick Start

### Test Current System
```bash
# Get all agents
curl "https://bmnboston.com/wp-json/mld-mobile/v1/agents"

# Login and get token
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"agent@example.com","password":"xxx"}'

# Get agent's clients
curl "https://bmnboston.com/wp-json/mld-mobile/v1/agent/clients" \
  -H "Authorization: Bearer TOKEN"
```

### Development Workflow
1. Check [ROADMAP.md](./ROADMAP.md) for current sprint status
2. Read latest [session log](../../archive/session-logs/agent-client-system/)
3. Make changes following plan
4. Test on production
5. Update ROADMAP.md

## Plan Reference

Full implementation plan: `~/.claude/plans/gentle-launching-dijkstra.md`
