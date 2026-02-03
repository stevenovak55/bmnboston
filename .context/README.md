# BMN Boston Documentation

Quick navigation guide for all project documentation.

## Quick Jump

| Need | Go To |
|------|-------|
| **Critical pitfalls** | [../CLAUDE.md](../CLAUDE.md#40-critical-pitfalls) |
| **Current versions** | [VERSIONS.md](VERSIONS.md) |
| **Mandatory rules** | [AGENT_PROTOCOL.md](AGENT_PROTOCOL.md) |

## Documentation Hierarchy

1. **`~/CLAUDE.md`** - Home directory quick reference (for starting work)
2. **`CLAUDE.md`** (project root) - Comprehensive guide with all 40 pitfalls
3. **`.context/`** - Detailed documentation by topic (this directory)

---

## Start Here

1. **[Agent Protocol](AGENT_PROTOCOL.md)** - MANDATORY rules for all AI agents
2. **[Critical Pitfalls](../CLAUDE.md#40-critical-pitfalls)** - All 40 documented pitfalls
3. **[Getting Started](getting-started/)** - Setup and quick reference
4. **[Architecture](architecture/)** - System design and patterns

### Task Tracking
- **[Completed Features](COMPLETED_FEATURES.md)** - Registry of all finished work
- **[Pending Tasks](PENDING_TASKS.md)** - Consolidated list of all pending items

## Documentation Structure

```
.context/
├── COMPLETED_FEATURES.md   # Registry of all finished work
├── PENDING_TASKS.md        # Consolidated pending items
├── getting-started/        # Setup, commands, quick reference
├── architecture/           # System design, database, API patterns
├── platforms/
│   ├── ios/               # iOS app development
│   └── wordpress/         # WordPress & plugin development
├── plugins/
│   ├── mls-listings-display/   # Property search plugin
│   ├── bmn-schools/            # School data plugin
│   ├── sn-appointment-booking/ # Appointment booking plugin
│   └── bridge-mls-extractor/   # MLS data extraction
├── features/
│   ├── property-search/   # Map, filters, saved searches
│   ├── appointments/      # Booking, reschedule, cancel
│   └── schools/           # Rankings, glossary, sports
├── cross-cutting/         # Auth, pitfalls, lessons, testing
├── troubleshooting/       # Common issues, debugging
├── audits/                # Platform audit reports and templates
└── archive/
    ├── changelog/         # Full version history
    └── completed/         # Archived feature tracking files
```

## Quick Links by Task

| Task | Documentation |
|------|---------------|
| **Add a new filter** | [plugins/mls-listings-display/filters.md](plugins/mls-listings-display/filters.md) |
| **Build iOS app** | [platforms/ios/build-deploy.md](platforms/ios/build-deploy.md) |
| **Deploy to production** | [platforms/wordpress/deployment.md](platforms/wordpress/deployment.md) |
| **Debug an issue** | [troubleshooting/troubleshooting.md](troubleshooting/troubleshooting.md) |
| **Add school data** | [plugins/bmn-schools/data-sources.md](plugins/bmn-schools/data-sources.md) |
| **Fix appointment bug** | [plugins/sn-appointment-booking/booking-flow.md](plugins/sn-appointment-booking/booking-flow.md) |
| **Run platform audit** | [audits/README.md](audits/README.md) |

## Cross-Platform Development

| Resource | Purpose |
|----------|---------|
| **[Feature Parity Matrix](cross-cutting/feature-parity.md)** | iOS vs Web feature comparison |
| **[File-to-Feature Index](architecture/file-feature-index.md)** | Find implementation files quickly |
| **[Testing Guide](cross-cutting/testing.md)** | What to test after changes |
| **[Plugin Dependencies](architecture/plugin-dependencies.md)** | How plugins interact |
| **[API Response Schemas](plugins/mls-listings-display/api-responses.md)** | Actual API response formats |
| **[Data Flow Diagrams](architecture/data-flows.md)** | Visual data flow traces |

## Current Versions

See **[VERSIONS.md](VERSIONS.md)** for all component versions and version bump instructions.

## App Store

- **iOS App**: https://apps.apple.com/us/app/bmn-boston/id6745724401

## Feedback

If documentation is missing or unclear, add it! Follow the existing structure and keep each file focused on one topic.
