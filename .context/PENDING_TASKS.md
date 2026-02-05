# Pending Tasks

All incomplete work items across the BMN Boston platform.

**Last Updated:** 2026-02-04

---

## Active Development

*No high-priority items currently in progress.*

---

## Backlog by Plugin

### BMN Schools - Future Enhancements

*Currently at Phase 7 (School Research Platform). Core functionality complete.*

| Enhancement | Priority | Effort | Description |
|-------------|----------|--------|-------------|
| Attendance Zone Boundaries | Medium | High | ATTOM API integration for zone mapping |
| School Comparison Tool | Low | Medium | Side-by-side school comparisons |
| Historical Data Trends | Low | Medium | MCAS score trends over years |
| Paid Data Integration | Low | Low | SchoolDigger, GreatSchools, Niche APIs |

**Full details:** `wordpress/wp-content/plugins/bmn-schools/docs/TODO.md`

---

### Agent-Client System - Future Enhancements

The core Agent-Client system is complete (v6.57.0). These are optional future improvements:

| Enhancement | Priority | Effort | Description |
|-------------|----------|--------|-------------|
| Smart notification timing | Low | Low | Business hours only delivery |
| Notification digest mode | Low | Medium | Daily/weekly summary emails |
| In-app messaging | Low | High | Direct agent-client chat |
| Appointment from shared | Low | Medium | Schedule showing from shared property |
| Client preference learning | Low | High | AI-based preference detection |
| Recommendation engine | Low | High | Property suggestions based on activity |

**Source:** `.context/features/agent-client-system/ROADMAP.md` (Future Enhancements section)

---

### MLS Listings Display - Future Enhancements

| Enhancement | Priority | Effort | Description |
|-------------|----------|--------|-------------|
| Chatbot export to PDF | Low | Medium | Save AI conversations |
| AI analytics dashboard | Low | Medium | Track API costs and usage |
| Multi-language support | Low | High | Translate property listings |
| Voice input/output | Low | High | Voice search and TTS |

**Source:** `wordpress/wp-content/plugins/mls-listings-display/FUTURE_ENHANCEMENTS.md` (if exists)

---

### Site Analytics - Optional Improvements

| Enhancement | Priority | Effort | Description |
|-------------|----------|--------|-------------|
| MaxMind GeoLite2 setup | Low | Low | Accurate geolocation (requires free license) |
| Export functionality | Low | Medium | CSV/PDF export from dashboard |
| ~~Custom date ranges~~ | ~~Done~~ | ~~v6.75.8~~ | ~~Completed Feb 4, 2026~~ |

---

## Priority Matrix

| Priority | Items | Notes |
|----------|-------|-------|
| **HIGH** | None | All major features complete |
| **MEDIUM** | BMN Schools Phase 2 (Data import) | Optional enhancement |
| **LOW** | All future enhancements | Nice-to-have, not urgent |

---

## How to Use This File

### When Starting Work

1. Check this file for context on current priorities
2. Reference the specific tracking file for detailed tasks
3. Update status as you progress

### When Completing Work

1. Mark items complete here OR in the source tracking file (not both)
2. When entire feature/phase completes, move to [COMPLETED_FEATURES.md](COMPLETED_FEATURES.md)
3. Archive the source tracking file

### Active Work Exception

During active development (like BMN Schools Phase 1):
- Keep detailed tracking in feature-specific files
- Reference from this file with "Active Tracking" note
- Do NOT duplicate content - maintain single source of truth
- Consolidate ONLY AFTER phase completes

---

## Related Documentation

- [COMPLETED_FEATURES.md](COMPLETED_FEATURES.md) - Finished work registry
- [AGENT_PROTOCOL.md](AGENT_PROTOCOL.md) - Development rules
- [archive/changelog/CHANGELOG.md](archive/changelog/CHANGELOG.md) - Version history
