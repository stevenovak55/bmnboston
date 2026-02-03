# Exclusive Listings - Feature Specification

## Overview

Exclusive Listings enables real estate agents to create and manage non-MLS listings that integrate seamlessly with the existing MLS data infrastructure. These listings appear identically in search results, maps, and property detail pages across both Web and iOS platforms.

## Key Requirements

### 1. Seamless Integration
- Exclusive listings populate the same BME database tables as MLS imports
- No special-case handling required in search queries
- Property detail pages render identically for MLS and exclusive listings

### 2. ID Strategy
- **Exclusive IDs**: Sequential starting from 1 (1, 2, 3, ...)
- **MLS IDs**: Start at ~60,000,000 (Bridge MLS assigned)
- **Detection**: `listing_id < 1,000,000` = exclusive listing

### 3. Platform Support
- **Web Admin**: Full CRUD via WordPress admin interface
- **iOS App**: Full CRUD with photo capture and upload
- **Search/Map**: Automatic inclusion in existing queries

### 4. Image Handling
- Storage: WordPress uploads directory (`wp-content/uploads/exclusive-listings/`)
- Processing: WordPress image editor for responsive sizes
- CDN: Kinsta CDN automatic caching

### 5. Archive Workflow
- Status change to "Closed" triggers automatic archive migration
- Archived listings move to `_archive` tables
- Mirrors MLS listing lifecycle behavior

## User Stories

### Agent (Web)
1. As an agent, I can create a new exclusive listing via WordPress admin
2. As an agent, I can upload multiple photos and set their order
3. As an agent, I can edit listing details and update status
4. As an agent, I can mark a listing as sold (auto-archives)

### Agent (iOS)
1. As an agent, I can view my exclusive listings in the iOS app
2. As an agent, I can create a new listing with property details
3. As an agent, I can capture photos from camera or select from library
4. As an agent, I can edit existing listings and reorder photos

### Consumer
1. As a consumer, I see exclusive listings in search results
2. As a consumer, I can filter by status (Active, Pending, Sold)
3. As a consumer, I can view exclusive listing detail pages
4. As a consumer, I can see exclusive listings on the map

## Data Model

### Required Fields
| Field | Type | Validation |
|-------|------|------------|
| property_type | ENUM | Residential, Commercial, Land, Multi-Family |
| property_sub_type | ENUM | Single Family, Condo, Townhouse, etc. |
| standard_status | ENUM | Active, Pending, Closed |
| list_price | DECIMAL | > 0 |
| street_number | VARCHAR(50) | Required |
| street_name | VARCHAR(100) | Required |
| city | VARCHAR(100) | Required |
| state_or_province | VARCHAR(2) | 2-letter code |
| postal_code | VARCHAR(10) | 5 or 9 digit |
| bedrooms_total | INT | >= 0 |
| bathrooms_total | DECIMAL(3,1) | >= 0 |

### System-Generated Fields
| Field | Generation |
|-------|------------|
| listing_id | Auto-increment sequence |
| listing_key | MD5 hash of ID + timestamp + agent |
| latitude/longitude | Geocoding service |
| modification_timestamp | WordPress current_time() |
| days_on_market | Calculated |

## API Endpoints

### REST API (WordPress)
```
GET    /exclusive-listings/v1/health                      - Health check (public)
GET    /mld-mobile/v1/exclusive-listings/options          - Get property types/statuses (public)
POST   /mld-mobile/v1/exclusive-listings                  - Create listing
GET    /mld-mobile/v1/exclusive-listings                  - List agent's listings
GET    /mld-mobile/v1/exclusive-listings/{id}             - Get single listing
PUT    /mld-mobile/v1/exclusive-listings/{id}             - Update listing
DELETE /mld-mobile/v1/exclusive-listings/{id}             - Delete/archive
GET    /mld-mobile/v1/exclusive-listings/{id}/photos      - Get photos
POST   /mld-mobile/v1/exclusive-listings/{id}/photos      - Upload photos
DELETE /mld-mobile/v1/exclusive-listings/{id}/photos/{id} - Delete photo
PUT    /mld-mobile/v1/exclusive-listings/{id}/photos/order - Reorder photos
```

## Security

### Authentication
- Web Admin: WordPress capability checks (agent role required)
- iOS API: JWT authentication (agent users only)

### Authorization
- Agents can only modify their own exclusive listings
- Admins can modify any exclusive listing
- Public can view active exclusive listings (read-only)

## Testing Criteria

### Functional
- [x] Create listing populates all 6 BME tables correctly ✓ (v1.1.0)
- [x] Listing appears in search results within 5 seconds ✓ (v1.1.0)
- [x] Property detail page renders without errors ✓ (v1.1.0)
- [x] Photo upload generates responsive sizes ✓ (v1.2.0 - verified in admin)
- [x] Web property page renders correctly ✓ (v1.2.2)
- [ ] Status change to "Closed" archives listing (Phase 3)

### Performance
- [x] ID generation handles concurrent requests ✓ (v1.0.0 - MySQL auto-increment)
- [x] Search query time unchanged with exclusive listings ✓ (v1.1.0)
- [x] Photo upload completes within 30 seconds ✓ (v1.2.0)

### Integration
- [x] iOS search includes exclusive listings ✓ (v1.2.2 - verified via API)
- [x] Map displays exclusive listing markers ✓ (v1.1.0 - coordinates populated)
- [x] Autocomplete finds exclusive listing addresses ✓ (v1.2.2)
- [x] Web property detail pages work ✓ (v1.2.2)
- [ ] Saved searches can match exclusive listings (needs testing)

### v1.2.2 Verification (2026-01-15)

**Production Test Listings:**
| ID | Address | City | Price | Photos | Status |
|----|---------|------|-------|--------|--------|
| 6 | 863 Main street | Reading | $1,625,000 | Yes | Active |
| 7 | 58 Oak street | Reading | $1,100,000 | 3 | Active |

**API Tests Passed:**
```
Health Check:      ✓ Status healthy, v1.2.2
Search (Reading):  ✓ Total 39, 2 exclusive found (IDs 6, 7)
Property Detail:   ✓ 58 Oak street, $1.1M, 3 photos
Autocomplete:      ✓ "58 Oak street, Reading" found
Options Endpoint:  ✓ property_sub_types grouped by type
```

**URLs Verified:**
- Property page: https://bmnboston.com/property/7/
- API detail: `/mld-mobile/v1/properties/04604afd43791c5a0a346e3e8ed747e1`
