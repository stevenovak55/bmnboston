# Plugin Dependency Graph

How the WordPress plugins depend on each other.

**Last Updated:** January 21, 2026

---

## Visual Dependency Map

```
                    ┌────────────────────────────┐
                    │   Bridge MLS API           │
                    │   (External - Bridge.com)  │
                    └─────────────┬──────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│                                                                     │
│  ┌───────────────────────────────────────┐                          │
│  │  Bridge MLS Extractor Pro (v4.0.37)   │                          │
│  │  ─────────────────────────────────────│                          │
│  │  • Extracts property data             │                          │
│  │  • Populates bme_* tables (18 total)  │                          │
│  │  • Runs on cron schedule              │                          │
│  │  • No dependencies on other plugins   │                          │
│  └───────────────────┬───────────────────┘                          │
│                      │                                              │
│                      │ Writes to database                           │
│                      ▼                                              │
│  ┌───────────────────────────────────────┐                          │
│  │  Database Tables (bme_*)              │                          │
│  │  ─────────────────────────────────────│                          │
│  │  • bme_listing_summary (7,400 rows)   │                          │
│  │  • bme_listing_summary_archive (90K)  │                          │
│  │  • bme_listing_details                │                          │
│  │  • bme_media (photos)                 │                          │
│  │  • bme_agents, bme_offices            │                          │
│  └───────────────────┬───────────────────┘                          │
│                      │                                              │
│          ┌───────────┴───────────┐                                  │
│          │                       │                                  │
│          ▼                       │                                  │
│  ┌───────────────────────────────────────┐                          │
│  │  MLS Listings Display (v6.68.18)      │                          │
│  │  ─────────────────────────────────────│                          │
│  │  • REST API: /mld-mobile/v1/*         │                          │
│  │  • Web AJAX handlers                  │                          │
│  │  • Reads from: bme_* tables           │                          │
│  │  • Creates: wp_mld_saved_searches     │                          │
│  │  • JWT authentication                 │◄────────────────┐        │
│  └────────────────┬──────────────────────┘                 │        │
│                   │                                        │        │
│                   │ Integrates with                        │        │
│                   ▼                                        │        │
│  ┌───────────────────────────────────────┐                 │        │
│  │  BMN Schools (v0.6.39)                │                 │        │
│  │  ─────────────────────────────────────│                 │        │
│  │  • REST API: /bmn-schools/v1/*        │                 │        │
│  │  • School rankings & filters          │                 │        │
│  │  • Tables: bmn_* (13 tables)          │                 │        │
│  │  • E2C Hub data source                │                 │        │
│  │  • Provides: school grade filtering   │                 │        │
│  └───────────────────────────────────────┘                 │        │
│                                                            │        │
│                                                            │        │
│  ┌───────────────────────────────────────┐                 │        │
│  │  SN Appointment Booking (v1.9.5)      │                 │        │
│  │  ─────────────────────────────────────│                 │        │
│  │  • REST API: /snab/v1/*               │                 │        │
│  │  • Web booking widget                 │                 │        │
│  │  • Tables: snab_* (5 tables)          │─────────────────┘        │
│  │  • Google Calendar integration        │  Uses JWT secret         │
│  │  • Largely independent                │                          │
│  └───────────────────────────────────────┘                          │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
                    │
                    ▼
        ┌─────────────────────────────────┐
        │         Consumers               │
        │  ─────────────────────────────  │
        │  • iOS App (REST API)           │
        │  • Website (AJAX + Templates)   │
        │  • Admin Dashboard              │
        └─────────────────────────────────┘
```

---

## Dependency Details

### Bridge MLS Extractor Pro
| Aspect | Value |
|--------|-------|
| **Version** | 4.0.37 |
| **Dependencies** | None (source plugin) |
| **External API** | Bridge Interactive MLS |
| **Tables Created** | 18 (bme_* prefix) |
| **REST Namespace** | /bme/v1 (admin only) |

**What Depends On It:**
- MLS Listings Display (reads bme_* tables)

**Failure Impact:**
- If extraction stops: No new listings appear
- If tables empty: All property searches return 0 results

---

### MLS Listings Display
| Aspect | Value |
|--------|-------|
| **Version** | 6.68.18 |
| **Dependencies** | Bridge MLS Extractor (for data) |
| **Optional Deps** | BMN Schools (for school filters) |
| **Tables Created** | wp_mld_saved_searches, wp_mld_saved_search_alerts |
| **REST Namespace** | /mld-mobile/v1 |

**Key Dependencies:**

```php
// Reads from Bridge MLS Extractor tables
$wpdb->get_results("SELECT * FROM {$wpdb->prefix}bme_listing_summary ...");

// Integrates with BMN Schools (optional but recommended)
if (class_exists('MLD_BMN_Schools_Integration')) {
    $integration = new MLD_BMN_Schools_Integration();
    $integration->apply_school_filter($query, $filters);
}
```

**What Depends On It:**
- iOS App (REST API consumer)
- SN Appointments (uses JWT secret)

**Failure Impact:**
- If MLD deactivated: iOS app and web search completely broken
- If BME tables missing: All searches return 0 results

---

### BMN Schools
| Aspect | Value |
|--------|-------|
| **Version** | 0.6.39 |
| **Dependencies** | None (independent data source) |
| **External Data** | MA E2C Hub (Socrata API) |
| **Tables Created** | 13 (bmn_* prefix) |
| **REST Namespace** | /bmn-schools/v1 |

**Integration Points:**

```php
// MLD integrates with BMN Schools for filtering
class MLD_BMN_Schools_Integration {
    public function apply_school_filter($query, $filters) {
        // Queries bmn_school_rankings table
        // Returns filtered property IDs
    }
}
```

**What Depends On It:**
- MLS Listings Display (school filters, school info display)
- Theme templates (school pages)

**Failure Impact:**
- If BMN Schools deactivated: School filters return all properties (no filtering)
- If rankings table empty: School grade filters ineffective

---

### SN Appointment Booking
| Aspect | Value |
|--------|-------|
| **Version** | 1.9.5 |
| **Dependencies** | MLD (for JWT secret, soft dependency) |
| **External API** | Google Calendar API |
| **Tables Created** | 5 (snab_* prefix) |
| **REST Namespace** | /snab/v1 |

**Soft Dependency on MLD:**

```php
// Uses same JWT secret for token validation
// Defined in MLD: define('MLD_JWT_SECRET', '...');
// SNAB relies on this for iOS authentication
```

**What Depends On It:**
- iOS App (appointment features)
- Website (booking widget)

**Failure Impact:**
- If SNAB deactivated: Appointment features unavailable
- If Google token expires: Calendar sync fails (per-staff)
- If MLD JWT secret changes: iOS tokens invalidated

---

## Database Table Ownership

| Plugin | Tables | Row Count |
|--------|--------|-----------|
| **Bridge MLS Extractor** | bme_listings, bme_listing_summary, bme_listing_details, bme_media, etc. | ~100K total |
| **MLS Listings Display** | wp_mld_saved_searches, wp_mld_saved_search_alerts | ~500 |
| **BMN Schools** | bmn_schools, bmn_school_rankings, bmn_school_districts, etc. | ~100K total |
| **SN Appointments** | wp_snab_appointments, wp_snab_staff, wp_snab_availability_rules | ~1K |

---

## Cross-Plugin Data Flow

```
User searches properties with school_grade=A filter
                    │
                    ▼
┌────────────────────────────────────────┐
│  MLS Listings Display                  │
│  class-mld-mobile-rest-api.php         │
│                                        │
│  1. Parse school_grade filter          │
│  2. Call BMN Schools integration       │
└─────────────────┬──────────────────────┘
                  │
                  ▼
┌────────────────────────────────────────┐
│  MLD_BMN_Schools_Integration           │
│  class-mld-bmn-schools-integration.php │
│                                        │
│  3. Query bmn_school_rankings          │
│  4. Get A-grade school locations       │
│  5. Filter properties within 2 miles   │
└─────────────────┬──────────────────────┘
                  │
                  ▼
┌────────────────────────────────────────┐
│  Bridge MLS Extractor tables           │
│  bme_listing_summary                   │
│                                        │
│  6. Get properties matching filters    │
│  7. Return to user                     │
└────────────────────────────────────────┘
```

---

## Deployment Order

When deploying updates, follow this order:

### Order 1: Independent Updates (Any Order)
- Bridge MLS Extractor (if extraction logic only)
- BMN Schools (if school data only)
- SN Appointments (if booking only)

### Order 2: Dependent Updates
1. **BMN Schools** (if school integration changes)
2. **MLS Listings Display** (depends on BMN Schools schema)

### Order 3: Coordinated Updates
If changing shared interfaces (JWT, school filter API):
1. Update BMN Schools first (provider)
2. Update MLS Listings Display (consumer)
3. Test integration before deploying to production

---

## Version Compatibility

| MLD Version | Min BMN Schools | Notes |
|-------------|-----------------|-------|
| 6.31.11+ | 0.6.30+ | Year rollover fix, iOS enhancements |
| 6.30.10+ | 0.6.20+ | School filters added |
| 6.29.0+ | 0.6.10+ | Basic school integration |

| SNAB Version | Min MLD Version | Notes |
|--------------|-----------------|-------|
| 1.8.0+ | 6.31+ | JWT secret sharing |
| 1.7.0+ | 6.30+ | - |

---

## Failure Modes & Recovery

### Bridge MLS Extractor Fails
**Symptoms:** No new listings, stale data
**Recovery:**
1. Check cron schedule
2. Verify Bridge API credentials
3. Run manual extraction from admin

### BMN Schools Integration Fails
**Symptoms:** School filters return all properties, no school info
**Recovery:**
1. Check bmn_school_rankings table has data
2. Verify year column has current year data
3. Run school sync from admin

### MLD REST API Fails
**Symptoms:** iOS app shows errors, web map empty
**Recovery:**
1. Check PHP error logs
2. Verify database connection
3. Test with curl commands

### SNAB Booking Fails
**Symptoms:** Appointments not created, no Google events
**Recovery:**
1. Check per-staff Google token validity
2. Verify snab_appointments table writable
3. Test availability calculation
