# Platform Audits

This directory contains audit reports and templates for the BMNBoston platform quality assurance process.

## Directory Structure

```
audits/
├── README.md                       # This file
├── templates/
│   └── audit-report-template.md    # Template for audit reports
└── [YYYY-MM-DD]-audit-report.md    # Individual audit reports
```

## Running an Audit

### Using the Platform Audit Agent

The recommended way to run audits is using the Platform Audit Agent in Claude Code:

1. Start a new Claude Code session
2. Run: `claude "Run a platform audit following the audit protocol"`
3. The audit will check versions, database health, and code quality

### Manual Audit Steps

If running manually, follow these phases:

1. **Pre-Audit Backup** - Create snapshot and git stash
2. **Version Sync** - Check all 4 components, all version locations
3. **Database Health** - Verify tables and record counts
4. **Health Tools** - Ensure verification tools are current (see [Rule 12](../AGENT_PROTOCOL.md#rule-12-update-health--verification-tools))
5. **Documentation** - Check CLAUDE.md and .context/ accuracy
6. **Code Quality** - Search for known anti-patterns
7. **Issue Resolution** - Fix with backups and testing
8. **Testing** - Run regression tests
9. **Report** - Generate audit report

### Health & Verification Tool Locations

| Tool | File | Purpose |
|------|------|---------|
| MLD Database Verify | `mls-listings-display/includes/class-mld-database-verify.php` | Verifies 40+ table schemas |
| MLD Health Dashboard | `mls-listings-display/admin/class-mld-health-dashboard.php` | System health overview in WP Admin |
| BMN Schools DB Manager | `bmn-schools/includes/class-database-manager.php` | Manages 14 school tables |
| SNAB Upgrader | `sn-appointment-booking/includes/class-snab-upgrader.php` | Appointment table migrations |

**Rule:** When adding/modifying database tables, update these tools per [AGENT_PROTOCOL Rule 12](../AGENT_PROTOCOL.md#rule-12-update-health--verification-tools).

## Audit Report Naming Convention

Reports should be named: `YYYY-MM-DD-audit-report.md`

Example: `2026-01-06-audit-report.md`

## Quick Health Check Commands

```bash
# Property search (must return > 0)
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'Total: {d.get(\"total\", 0)}')"

# School filter (must return > 1000)
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'School: {d.get(\"total\", 0)}')"

# Schools API health
curl -s "https://bmnboston.com/wp-json/bmn-schools/v1/health" | python3 -m json.tool
```

## Current Versions (Update After Each Release)

| Component | Version | Last Audit |
|-----------|---------|------------|
| iOS App | v236 | Jan 12, 2026 |
| MLS Listings Display | 6.57.0 | Jan 11, 2026 |
| BMN Schools | 0.6.38 | Jan 11, 2026 |
| SN Appointments | 1.9.0 | Jan 11, 2026 |

## Known Critical Issues to Check

1. **MLS Upgrader Version Mismatch** - `class-mld-upgrader.php` constants must match plugin version
2. **Year Rollover Bug** - No hardcoded `date('Y')` in school queries
3. **Listing ID vs Key** - URLs must use `listing_id`, not `listing_key`
4. **Nonces on Public Endpoints** - Causes 403 after 24 hours
5. **Timezone Issues** - Use `current_time()`, not `time()` in WordPress

## Audit Schedule

- **Full Audit:** Monthly
- **Version Check:** After every deployment
- **Database Health:** Weekly
- **API Health:** Daily (can be automated)
- **Documentation Review:** After major features

## Server Access

See `/.context/credentials/server-credentials.md` for SSH access to:
- bmnboston.com (port 57105)
- steve-novak.com (port 50594)
