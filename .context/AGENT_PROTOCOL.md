# Agent Protocol

**MANDATORY RULES FOR ALL AI AGENTS WORKING ON THIS CODEBASE**

These rules are non-negotiable. Every AI agent (Claude Code, GitHub Copilot, Cursor, etc.) must follow this protocol to maintain system integrity and documentation quality.

---

## Rule 1: Read Before You Code

### Before ANY Code Change:

1. **Read the relevant documentation first**
   - Start with [Critical Pitfalls](../CLAUDE.md#40-critical-pitfalls)
   - Read plugin/feature docs for the area you're modifying
   - Check [File-to-Feature Index](architecture/file-feature-index.md) to understand all affected files

2. **Identify ALL code paths**
   - iOS and Web use different code paths (see [Code Paths](architecture/code-paths.md))
   - If modifying property search: update BOTH `class-mld-mobile-rest-api.php` AND `class-mld-query.php`
   - If modifying appointments: update BOTH `class-snab-rest-api.php` AND `class-snab-frontend-ajax.php`

3. **Check the Testing Guide**
   - Review [Testing Guide](cross-cutting/testing.md) for the file you're modifying
   - Know what tests to run before you start

---

## Rule 2: Update Documentation With Every Code Change

### Every code change MUST include documentation updates:

| If You... | Update These Docs |
|-----------|-------------------|
| Add a new feature | [Feature Parity](cross-cutting/feature-parity.md), [File-to-Feature Index](architecture/file-feature-index.md) |
| Add a new filter | [Filters Guide](plugins/mls-listings-display/filters.md) |
| Add a new API endpoint | [API Responses](plugins/mls-listings-display/api-responses.md) or relevant plugin docs |
| Fix a bug that taught a lesson | [Critical Pitfalls](../CLAUDE.md#40-critical-pitfalls) |
| Add a critical pitfall | [Critical Pitfalls](../CLAUDE.md#40-critical-pitfalls) |
| Change plugin dependencies | [Plugin Dependencies](architecture/plugin-dependencies.md) |
| Add iOS-only or Web-only feature | [Feature Parity](cross-cutting/feature-parity.md) |
| Change data flow | [Data Flows](architecture/data-flows.md) |
| Add/modify database table | See [Rule 12](#rule-12-update-health--verification-tools) |
| Add date/time handling code | See [Rule 13](#rule-13-timezone-consistency) |

### Documentation Quality Standards:

- Keep the same formatting as existing docs
- Include code examples where helpful
- Add version numbers for new features (e.g., "Added in v6.32.0")
- Update "Last Updated" dates when modifying docs

---

## Rule 3: Always Update Version Numbers

### When Releasing ANY Code Change:

**iOS App** - Update in `project.pbxproj`:
```
CURRENT_PROJECT_VERSION = N+1;
```
- There are **6 occurrences** - update ALL of them
- Use `replace_all` to ensure consistency

**MLS Listings Display** - Update in **3 locations**:
1. `version.json` → `"version": "X.Y.Z"` and `"last_updated"`
2. `mls-listings-display.php` line 6 → `Version: X.Y.Z`
3. `mls-listings-display.php` → `define('MLD_VERSION', 'X.Y.Z')`

**BMN Schools** - Update in **3 locations**:
1. `version.json` → `"version": "X.Y.Z"` and `"last_updated"`
2. `bmn-schools.php` line 6 → `Version: X.Y.Z`
3. `bmn-schools.php` → `define('BMN_SCHOOLS_VERSION', 'X.Y.Z')`

**SN Appointments** - Update in **4 locations**:
1. `version.json` → `"version": "X.Y.Z"` and `"last_updated"`
2. `sn-appointment-booking.php` line 6 → `Version: X.Y.Z`
3. `sn-appointment-booking.php` → `define('SNAB_VERSION', 'X.Y.Z')`
4. `includes/class-snab-upgrader.php` → `CURRENT_VERSION` constant

### Version Number Format:
- **Major.Minor.Patch** (e.g., 6.31.8)
- Increment patch for bug fixes
- Increment minor for new features
- Increment major for breaking changes

---

## Rule 4: Test ALL Changes Before Deployment

### Mandatory Testing Protocol:

#### Step 1: Run API Curl Tests
```bash
# Property search (must return results)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1" | jq '.total'

# School filter (must return 1000+, NOT 0)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1" | jq '.total'

# Schools API health
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health" | jq '.'

# Autocomplete
curl "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=boston" | jq 'length'
```

#### Step 2: Test on iOS Device
- Install app on physical device (iPhone 16 Pro)
- Test the specific feature you changed
- Test related features per [Testing Guide](cross-cutting/testing.md)

#### Step 3: Test on Web
- Load property map at bmnboston.com
- Apply relevant filters
- Verify results match iOS (for shared features)

#### Step 4: Verify Nothing Broke

**Core Regression Checklist:**
- [ ] Property search returns results
- [ ] Map displays properties
- [ ] Filters apply correctly
- [ ] Property detail loads with photos
- [ ] Saved searches work
- [ ] Favorites work (if logged in)
- [ ] School info displays on property detail
- [ ] Appointments can be booked (if relevant)

---

## Rule 5: Follow the Dual Code Path Rule

### CRITICAL: Never Assume One Path Is Enough

| Feature | iOS Path | Web Path |
|---------|----------|----------|
| Property Search | `class-mld-mobile-rest-api.php` | `class-mld-query.php` |
| Appointments | `class-snab-rest-api.php` | `class-snab-frontend-ajax.php` |
| Saved Searches | REST API endpoint | AJAX handler |
| Favorites | REST API endpoint | AJAX handler |

### The Rule:
> If you modify a feature that exists on both platforms, you MUST update BOTH code paths.

### Verification:
After making changes, ask yourself:
1. Does this feature exist on iOS? ✓ Test iOS
2. Does this feature exist on Web? ✓ Test Web
3. Did I update both code paths? ✓ Or explain why not

---

## Rule 6: Preserve the .context Structure

### Do NOT:
- Create documentation outside `.context/` folder
- Create new CLAUDE.md files in plugin folders
- Duplicate information across files
- Delete existing documentation without migrating content

### DO:
- Add new docs to appropriate `.context/` subfolder
- Update existing docs instead of creating new ones
- Cross-reference related documentation with links
- Keep each file focused on one topic

### Folder Structure (Preserve This):
```
.context/
├── getting-started/     # Setup, commands
├── architecture/        # System design, data flows
├── platforms/           # iOS, WordPress specifics
├── plugins/             # Plugin documentation
├── features/            # Feature documentation
├── cross-cutting/       # Auth, pitfalls, protocols
├── troubleshooting/     # Issues, debugging
└── archive/             # Version history
```

---

## Rule 7: Document Critical Pitfalls

### When You Encounter a Bug or Issue:

Add it to [Critical Pitfalls](../CLAUDE.md#40-critical-pitfalls) with:

```markdown
### Lesson #N: [Short Title]

**Date:** YYYY-MM-DD
**Severity:** High/Medium/Low
**Affected:** iOS/Web/Both

**What Happened:**
[Brief description of the bug]

**Root Cause:**
[Why it happened]

**Solution:**
[How it was fixed]

**Prevention:**
[How to prevent it in the future]
```

### When You Discover a Critical Pitfall:

Add it to [Critical Pitfalls](../CLAUDE.md#40-critical-pitfalls) with code examples.

---

## Rule 8: Respect the Year Rollover Bug

### NEVER Use `date('Y')` for Time-Series Data

```php
// WRONG - Will break on January 1st
$year = date('Y');
$rankings = $wpdb->get_results("SELECT * FROM rankings WHERE year = $year");

// CORRECT - Always get latest available year
$latest_year = $wpdb->get_var("SELECT MAX(year) FROM rankings");
$rankings = $wpdb->get_results("SELECT * FROM rankings WHERE year = $latest_year");
```

### After Any School-Related Changes:
Run this test to verify:
```bash
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1" | jq '.total'
# Must return 1000+, NOT 0
```

---

## Rule 9: Performance Matters

### Always Use Summary Tables

```php
// CORRECT: Fast (~200ms)
$wpdb->get_results("SELECT * FROM bme_listing_summary WHERE ...");

// WRONG: Slow (4-5 seconds)
$wpdb->get_results("SELECT * FROM bme_listings l
    LEFT JOIN bme_listing_details d ON l.listing_id = d.listing_id ...");
```

### Status-to-Table Routing:
- Active, Pending → `bme_listing_summary`
- Sold, Closed → `bme_listing_summary_archive`

---

## Rule 10: Security First

### NEVER:
- Commit credentials to version control
- Expose API keys in client-side code
- Skip input validation
- Trust user input without sanitization

### ALWAYS:
- Use prepared statements for SQL queries
- Sanitize and validate all inputs
- Use nonces for AJAX requests
- Verify JWT tokens on protected endpoints

---

## Rule 11: Maintain Documentation Integrity

### When Adding New Documentation:

1. **Update Navigation Files** - After adding any new .md file:
   - Update `.context/README.md` quick links table
   - Update `/CLAUDE.md` if it's a major pitfall or reference
   - Add cross-references from related existing docs

2. **Use Consistent Structure** - New feature docs should include:
   ```markdown
   # Feature Name

   ## Overview
   [What it does, why it exists]

   ## Key Files
   | File | Purpose |
   |------|---------|

   ## API Endpoints (if applicable)

   ## Database Tables (if applicable)

   ## Related Documentation
   - [Link to related docs]
   ```

3. **Verify Links Work** - After any documentation changes:
   ```bash
   # Search for references to the file you modified/created
   grep -r "filename.md" .context/
   ```

### Version Sync Across Files

These files contain version numbers that MUST stay in sync:

| File | What to Update |
|------|----------------|
| `.context/VERSIONS.md` | **Source of truth** - update this first |
| `/CLAUDE.md` | Quick reference version table |
| `~/CLAUDE.md` | Home directory quick reference |
| Plugin `version.json` files | Plugin source files |

**Rule:** When bumping versions, update `.context/VERSIONS.md` first (single source of truth), then update the quick reference tables in CLAUDE.md files.

### AI Instruction File Maintenance

When making significant changes, update these AI instruction files:

| File | When to Update |
|------|----------------|
| `/CLAUDE.md` | New pitfalls, new key files, architecture changes |
| `/.cursorrules` | New critical rules, key file location changes |
| `/.github/copilot-instructions.md` | Project structure changes, new rules |

### Documentation Review Checklist

After ANY documentation change:

- [ ] New files added to navigation (README.md, CLAUDE.md)
- [ ] Links in modified files still work
- [ ] Version numbers synced across all files
- [ ] No duplicate information created
- [ ] Cross-references added to related docs
- [ ] AI instruction files updated (if major change)

### Preventing Documentation Drift

1. **Single Source of Truth** - Each piece of information should exist in ONE place
   - Reference it from other docs, don't duplicate

2. **Date Your Updates** - Add "Last Updated: YYYY-MM-DD" to files you modify

3. **Archive Don't Delete** - Move outdated docs to `.context/archive/` instead of deleting

4. **Changelog for Major Changes** - Update `.context/archive/changelog/CHANGELOG.md` for:
   - New features
   - Breaking changes
   - Major bug fixes

---

## Rule 12: Update Health & Verification Tools

### Database Verification Tools Must Stay Current

When you add or modify database tables, you MUST update the verification tools so they can detect issues during audits.

### Active/Archive Schema Parity (CRITICAL)

BME listing tables use active/archive pairs (e.g., `bme_listings` / `bme_listings_archive`). **These pairs MUST have IDENTICAL schemas** for `INSERT INTO ... SELECT * FROM` to work:

```sql
-- This ONLY works if schemas are identical
INSERT INTO wp_bme_listings_archive SELECT * FROM wp_bme_listings WHERE listing_id = 12345;
```

**When adding columns to ANY BME table:**
1. Add the same column to BOTH the active AND archive version
2. Verify column count matches: `SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'TABLE_NAME'`

**Table pairs that must stay in sync (verified Jan 2026):**
| Table Pair | Columns |
|------------|---------|
| `bme_listings` / `_archive` | 74 |
| `bme_listing_details` / `_archive` | 100 |
| `bme_listing_location` / `_archive` | 28 |
| `bme_listing_financial` / `_archive` | 72 |
| `bme_listing_features` / `_archive` | 49 |
| `bme_listing_summary` / `_archive` | 48 |

See `.context/architecture/listing-data-mapping.md` for full schema documentation.

### MLD Database Verify (`class-mld-database-verify.php`)

**Update when:**
- Adding a new table to MLS Listings Display plugin
- Modifying table schema (adding/removing columns)
- Changing column types or constraints

**How to update:**
1. Find the `get_required_tables()` method
2. Add/update the table definition with correct SQL schema
3. Update the `@updated` comment at top of file with version and change description

```php
// Example: Adding a new table
'mld_new_feature' => array(
    'purpose' => 'Stores data for new feature',
    'category' => 'core',
    'sql' => "CREATE TABLE {$wpdb->prefix}mld_new_feature (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        -- columns here --
        PRIMARY KEY (id)
    ) $charset_collate;"
),
```

### MLD Health Dashboard (`admin/class-mld-health-dashboard.php`)

**Update when:**
- Adding new health checks or metrics
- Changing expected record count thresholds
- Adding new cron jobs that should be monitored

### BMN Schools Database Manager (`class-database-manager.php`)

**Update when:**
- Adding tables to BMN Schools plugin
- Modifying school-related table schemas
- Changing data import/export logic

### Verification Tool Update Checklist

| If You... | Update These Tools |
|-----------|-------------------|
| Add MLD table | `class-mld-database-verify.php` |
| Add Schools table | `class-database-manager.php` |
| Add SNAB table | `class-snab-upgrader.php` |
| Add health endpoint | `class-mld-health-dashboard.php` |
| Change expected counts | Health dashboard thresholds |
| Add new cron job | Health dashboard cron monitoring |

### After Updating Verification Tools

1. **Test the verification** - Run Database Verify in WP Admin to confirm new table appears
2. **Check health dashboard** - Verify new metrics display correctly
3. **Update audit template** - If adding major new checks, update `.context/audits/templates/audit-report-template.md`

### Why This Matters

If verification tools don't know about new tables:
- Audits won't detect missing tables
- Schema changes won't be validated
- Database issues go unnoticed until production breaks

**The Health Dashboard and Database Verify are your safety net. Keep them current.**

---

## Rule 13: Timezone Consistency

### WordPress Timezone is the Source of Truth

**WordPress is configured to `America/New_York` timezone.** All date/time handling must respect this.

### PHP: Always Use WordPress Time Functions

```php
// ❌ WRONG - Uses server timezone (often UTC), causes mismatches
$now = date('Y-m-d H:i:s');
$timestamp = time();
$threshold = strtotime('-5 minutes');

// ✅ CORRECT - Uses WordPress configured timezone
$now = current_time('mysql');
$timestamp = current_time('timestamp');
$threshold = current_time('timestamp') - (5 * 60);

// ✅ CORRECT - For DateTime objects
$datetime = new DateTime('now', wp_timezone());

// ✅ CORRECT - For comparisons with stored timestamps
$stored_time = strtotime($row->created_at);
$wp_now = current_time('timestamp');
if ($wp_now - $stored_time > 3600) { /* more than 1 hour ago */ }
```

### PHP Quick Reference

| Need | Wrong | Correct |
|------|-------|---------|
| Current datetime string | `date('Y-m-d H:i:s')` | `current_time('mysql')` |
| Current timestamp | `time()` | `current_time('timestamp')` |
| Format a date | `date('Y-m-d', $ts)` | `wp_date('Y-m-d', $ts)` |
| DateTime object | `new DateTime()` | `new DateTime('now', wp_timezone())` |
| Timezone object | `new DateTimeZone('UTC')` | `wp_timezone()` |
| Time ago calculation | `time() - $stored` | `current_time('timestamp') - $stored` |
| **Display stored timestamp** | `strtotime($row->date)` | `(new DateTime($row->date, wp_timezone()))->getTimestamp()` |

### JavaScript: Convert to WordPress Timezone for Display

```javascript
// ❌ WRONG - Uses browser's local timezone
const date = new Date(apiResponse.created_at);
const formatted = date.toLocaleString();

// ✅ CORRECT - Specify the WordPress timezone for display
const date = new Date(apiResponse.created_at);
const formatted = date.toLocaleString('en-US', {
    timeZone: 'America/New_York',
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
});
```

### iOS Swift: Server Returns America/New_York Times

The API returns dates in WordPress timezone. iOS should:

```swift
// API dates are in America/New_York timezone
// When displaying, convert to user's local timezone OR keep in Eastern

// For displaying in Eastern Time (matches WordPress):
let formatter = DateFormatter()
formatter.timeZone = TimeZone(identifier: "America/New_York")
formatter.dateStyle = .medium
formatter.timeStyle = .short
let displayString = formatter.string(from: serverDate)

// For comparing timestamps (server returns Eastern, compare in Eastern):
let easternTZ = TimeZone(identifier: "America/New_York")!
var calendar = Calendar.current
calendar.timeZone = easternTZ
```

### Database: Store in WordPress Timezone

When storing timestamps:

```php
// ❌ WRONG - Stores in server timezone
$wpdb->insert($table, array(
    'created_at' => date('Y-m-d H:i:s')
));

// ✅ CORRECT - Stores in WordPress timezone
$wpdb->insert($table, array(
    'created_at' => current_time('mysql')
));
```

### Displaying Stored Timestamps (CRITICAL)

**This is a common source of bugs.** When displaying a timestamp that was stored with `current_time('mysql')`, you MUST tell PHP that the string is in WordPress timezone, not UTC.

```php
// ❌ WRONG - strtotime() assumes UTC, wp_date() converts to WP timezone = 5 hour error!
echo wp_date('M j, Y g:i A', strtotime($row->created_at));
// Result: Shows 4:00 PM when it should show 9:00 PM

// ✅ CORRECT - Tell DateTime the string is already in WordPress timezone
$date = new DateTime($row->created_at, wp_timezone());
echo wp_date('M j, Y g:i A', $date->getTimestamp());
// Result: Shows correct time 9:00 PM
```

**Why this happens:**
1. `current_time('mysql')` stores `"2026-01-11 21:00:00"` (9 PM Eastern)
2. `strtotime("2026-01-11 21:00:00")` interprets this as UTC (9 PM UTC)
3. `wp_date()` converts UTC to Eastern, subtracting 5 hours
4. Result: Shows 4 PM instead of 9 PM

**The fix:** Create a DateTime object with the correct timezone FIRST, then get the Unix timestamp from that.

### API Responses: Use ISO 8601 with Timezone

When returning dates in API responses:

```php
// ❌ WRONG - No timezone info, ambiguous
return array('created_at' => $row->created_at);

// ✅ CORRECT - Include timezone offset
$date = new DateTime($row->created_at, wp_timezone());
return array('created_at' => $date->format('c')); // ISO 8601 with offset
// Returns: "2026-01-11T14:30:00-05:00"
```

### Common Timezone Bugs

| Bug | Cause | Fix |
|-----|-------|-----|
| Events appear 5 hours off | Used `time()` instead of `current_time()` | Replace with WordPress time functions |
| **Display shows wrong time** | Used `strtotime($row->field)` on WP-stored timestamp | Use `new DateTime($row->field, wp_timezone())` |
| "Created 5 hours ago" wrong | Compared `time()` with stored WP timestamp | Use `current_time('timestamp')` for comparison |
| JavaScript shows wrong time | Browser timezone differs from server | Specify `America/New_York` in JS formatting |
| Scheduled tasks run at wrong time | Used `date()` for cron scheduling | Use `current_time()` or `wp_schedule_event()` |

### Anti-Pattern Search

Before deploying any date/time code, search for these anti-patterns:

```bash
# Search for raw PHP time functions (should use WordPress equivalents)
grep -rn "time()" --include="*.php" wordpress/wp-content/plugins/
grep -rn "date('Y" --include="*.php" wordpress/wp-content/plugins/
grep -rn "strtotime(" --include="*.php" wordpress/wp-content/plugins/
grep -rn "new DateTime()" --include="*.php" wordpress/wp-content/plugins/

# These are OK:
# - current_time('timestamp')
# - current_time('mysql')
# - wp_date()
# - new DateTime('now', wp_timezone())
```

### When Raw PHP Time IS Acceptable

There are limited cases where raw PHP time functions are correct:

1. **Calculating durations** (not absolute times): `$elapsed = microtime(true) - $start`
2. **External API requirements** that expect UTC
3. **Caching keys** using timestamps (timezone doesn't matter)

In all other cases, use WordPress time functions.

---

## Rule 14: Maintain Consolidated Task Tracking

### After Completing a Feature:

1. Add summary entry to [COMPLETED_FEATURES.md](COMPLETED_FEATURES.md)
2. Archive original tracking file to `.context/archive/completed/`
3. Replace original with redirect stub if referenced elsewhere

### When Starting New Work:

1. Check [PENDING_TASKS.md](PENDING_TASKS.md) for context
2. Update status as you progress
3. Move to COMPLETED_FEATURES.md when done

### Format Standards:

- Use consistent date format: YYYY-MM-DD
- Include version numbers where applicable
- Link to detailed documentation
- Keep summaries scannable (bullet points, tables)

### Active Work Exception:

During active development (like BMN Schools Phase 1):
- Keep detailed tracking in feature-specific files
- Reference from `PENDING_TASKS.md` with "Active Tracking" note
- Do NOT duplicate content - maintain single source of truth
- Consolidate ONLY AFTER phase completes

### Task Tracking Files:

| File | Purpose |
|------|---------|
| [COMPLETED_FEATURES.md](COMPLETED_FEATURES.md) | Registry of all finished work |
| [PENDING_TASKS.md](PENDING_TASKS.md) | Consolidated pending items |
| `.context/archive/completed/` | Archived original tracking files |

---

## Rule 15: Git Commit Discipline (CRITICAL)

**Background:** Bug fixes have been lost multiple times because changes were not committed to git. This rule prevents that from happening again.

### Mandatory Commit Triggers

**ALWAYS commit immediately when:**

1. **User says "update documentation"** - This signals end of session
2. **User says "close out this session"** - Session is ending
3. **User says "wrap up"** or similar closing language
4. **After fixing a bug** - Bug fixes must be committed immediately
5. **Every 5 code file changes** - Don't accumulate too many uncommitted changes

### Tracking Code Changes

Keep a mental count of code files modified since last commit. When you reach 5 modified files:

```bash
# Check current uncommitted count
git status --short | wc -l

# If 5+ files modified, commit now
git add -A && git commit -m "Progress checkpoint: [brief description]"
```

### Session-End Commit Protocol

When user requests documentation update or session close:

```bash
# 1. Check for uncommitted changes
git status --short

# 2. If ANY changes exist, commit them
git add -A
git commit -m "$(cat <<'EOF'
Session checkpoint: [date] - [brief summary]

Changes:
- [list key changes]

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"

# 3. Attempt push (may require user auth)
git push origin main || echo "Push requires authentication - please push manually"

# 4. Verify clean state
git status
```

### Commit Message Format

**For progress checkpoints (every 5 changes):**
```
Progress: [brief description of work in progress]
```

**For bug fixes (commit immediately):**
```
Fix: [description of what was fixed]
```

**For session end:**
```
Session checkpoint: [date] - [summary of session work]
```

### Pre-Push Hook Reminder

A git pre-push hook is installed that warns about uncommitted changes. If you see this warning, commit before pushing.

### Verification Before Session End

Before ending ANY session, run:
```bash
git status
```

If output shows ANY modified files, you MUST commit them. Never end a session with uncommitted changes.

### Why This Matters

Uncommitted changes are lost when:
- User runs `git checkout` or `git reset`
- Machine restarts unexpectedly
- User pulls changes from remote
- Time passes and user forgets about local changes

**Committed code is safe. Uncommitted code will eventually be lost.**

---

## Pre-Deployment Checklist

Before EVERY deployment, verify:

```markdown
## Pre-Deployment Checklist

### Code Quality
- [ ] Code reviewed for security issues
- [ ] No hardcoded credentials or secrets
- [ ] Input validation in place
- [ ] Error handling implemented
- [ ] Timezone: Using `current_time()` not `time()`/`date()` (if date/time code)

### Documentation
- [ ] Relevant docs updated
- [ ] Version numbers bumped (all locations)
- [ ] Changelog updated (if major change)
- [ ] Feature parity matrix updated (if new feature)

### Testing
- [ ] Curl API tests pass
- [ ] iOS app tested on device
- [ ] Web tested in browser
- [ ] Core regression checklist passed
- [ ] Change impact tests passed

### Dual Code Paths
- [ ] iOS path updated (if applicable)
- [ ] Web path updated (if applicable)
- [ ] Both platforms tested

### Performance
- [ ] Using summary tables (not JOINs)
- [ ] No N+1 queries introduced
- [ ] Response times acceptable (<500ms)

### Health & Verification Tools (if database changes)
- [ ] `class-mld-database-verify.php` updated with new/modified tables
- [ ] Health dashboard updated (if new metrics needed)
- [ ] Audit template updated (if new checks added)
```

---

## Quick Reference Card

```
┌─────────────────────────────────────────────────────────────────────┐
│  AGENT PROTOCOL QUICK REFERENCE                                     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  BEFORE CODING:                                                     │
│  ✓ Read Critical Pitfalls                                           │
│  ✓ Check File-to-Feature Index                                      │
│  ✓ Identify all affected code paths                                 │
│                                                                     │
│  AFTER CODING:                                                      │
│  ✓ Update documentation                                             │
│  ✓ Bump version numbers (ALL locations)                             │
│  ✓ Update health/verification tools (if DB changes)                 │
│  ✓ Test with curl commands                                          │
│  ✓ Test on iOS device                                               │
│  ✓ Test on web                                                      │
│  ✓ Run regression checklist                                         │
│                                                                     │
│  GIT COMMITS (CRITICAL - prevents lost work):                       │
│  ✓ Commit after EVERY bug fix                                       │
│  ✓ Commit every 5 code file changes                                 │
│  ✓ Commit when user says "update docs" or "close session"           │
│  ✓ NEVER end session with uncommitted changes                       │
│                                                                     │
│  DUAL CODE PATHS (update BOTH):                                     │
│  • Property Search: REST API + class-mld-query.php                  │
│  • Appointments: REST API + class-snab-frontend-ajax.php            │
│                                                                     │
│  NEVER:                                                             │
│  ✗ Use date('Y') for time-series data                               │
│  ✗ Use time()/date() instead of current_time()                      │
│  ✗ Use JOINs instead of summary tables                              │
│  ✗ Skip testing                                                     │
│  ✗ Forget to update docs                                            │
│  ✗ Leave uncommitted changes at session end                         │
│                                                                     │
│  TASK TRACKING:                                                     │
│  • Completed features → COMPLETED_FEATURES.md                       │
│  • Pending tasks → PENDING_TASKS.md                                 │
│  • Archive originals → .context/archive/completed/                  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Enforcement

If you are an AI agent and you violate these rules:

1. **The violation will be caught** - The codebase maintainers review all changes
2. **Regressions will be traced back** - Git blame shows who introduced bugs
3. **Documentation drift will be noticed** - Outdated docs cause confusion
4. **Untested changes will break production** - Real users will be affected

**Follow this protocol every time. No exceptions.**
