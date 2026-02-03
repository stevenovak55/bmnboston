# Testing Guide

Comprehensive testing and change impact reference for all components.

---

## Pre-Deployment Checklist

Run before deploying ANY change:

- [ ] **Property Search**: Map loads, filters work, results display
- [ ] **Saved Searches**: Can save, load, and apply searches
- [ ] **Favorites**: Can add/remove favorites
- [ ] **Appointments**: Can book, cancel, reschedule
- [ ] **Authentication**: Login/logout works on iOS and web
- [ ] **School Filters**: School grade filter returns expected count (~1,600 for grade A)

---

## Quick Regression Commands

```bash
# Property search health
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'Total: {d.get(\"total\", 0)}')"

# School filter (CRITICAL after year rollover)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'School filter: {d.get(\"total\", 0)}')"
# Expected: 1000+, NOT 0

# Schools API health
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'Health: {d.get(\"status\", \"error\")}')"

# Appointments API health
curl "https://bmnboston.com/wp-json/snab/v1/appointment-types" | python3 -c "import sys,json; print(f'Types: {len(json.load(sys.stdin))}')"

# Sitemaps accessible
curl -s -o /dev/null -w "%{http_code}" "https://bmnboston.com/sitemap.xml"
# Expected: 200
```

---

## Change Impact Reference

When you modify a file, test these features to prevent regressions.

### iOS Files

#### PropertySearchViewModel.swift
Test on iOS device:
- [ ] Property search loads results
- [ ] Map displays properties correctly
- [ ] City filter applies and removes
- [ ] Multiple city selection works
- [ ] School grade filter (A/B/C) works
- [ ] Price filter applies correctly
- [ ] Beds/baths filter works
- [ ] Map bounds search updates on pan/zoom
- [ ] Sort options change results order
- [ ] Save search creates successfully
- [ ] Apply saved search restores filters

#### PropertyMapView.swift
Test on iOS device:
- [ ] Map renders without crash
- [ ] Property pins display correctly
- [ ] Pin clustering works at zoom levels
- [ ] Tap pin shows property callout
- [ ] Map bounds update triggers search
- [ ] City boundary polygon displays
- [ ] No Task self-cancellation issues

#### APIClient.swift
Test on iOS device:
- [ ] Login works
- [ ] Token refresh works (wait 15+ min)
- [ ] Guest mode browsing works
- [ ] Property search returns data
- [ ] Property detail loads
- [ ] Saved searches sync
- [ ] Favorites sync
- [ ] Appointments load

#### TokenManager.swift
Test on iOS device:
- [ ] Login stores tokens in Keychain
- [ ] Logout clears tokens
- [ ] Token refresh before expiry works
- [ ] Expired token triggers re-login
- [ ] App restart maintains login state

---

### WordPress: MLS Listings Display

#### class-mld-mobile-rest-api.php
Test with curl AND iOS app:

```bash
# Basic search
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1"

# City filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?city=Boston&per_page=1"

# School filter (CRITICAL - year rollover)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"

# Price filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?min_price=500000&per_page=1"

# Bounds filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?bounds=42.35,-71.1,42.37,-71.05&per_page=1"
```

On iOS:
- [ ] Property search works
- [ ] All filter types work
- [ ] Property detail loads
- [ ] Photos display correctly

#### class-mld-query.php
Test web map:
- [ ] Map loads properties
- [ ] City filter works
- [ ] Price filter works
- [ ] Beds/baths filter works
- [ ] School grade filter works
- [ ] Sort options work
- [ ] Pagination works

**CRITICAL:** This file handles WEB searches. If you also modified `class-mld-mobile-rest-api.php`, test BOTH.

#### class-mld-bmn-schools-integration.php
Test school filters on BOTH platforms:

```bash
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"
# Expected: 1000+ results
```

- [ ] School grade A filter returns results
- [ ] School grade B filter returns results
- [ ] Near top elementary works
- [ ] School info on property detail displays

**Year Rollover Check:**
- [ ] Query uses `MAX(year)` not `date('Y')`

---

### WordPress: BMN Schools

#### class-rest-api.php (bmn-schools)
Test with curl:

```bash
# Health check
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health"

# Schools near location
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26"

# School detail
curl "https://bmnboston.com/wp-json/bmn-schools/v1/schools/123"
```

- [ ] Health endpoint returns OK
- [ ] Nearby schools returns data
- [ ] School detail has rankings
- [ ] MCAS scores display

#### class-ranking-calculator.php
Test rankings:
- [ ] Composite scores calculate correctly
- [ ] Letter grades assign properly (A/B/C/D/F)
- [ ] No NULL rankings for schools with data
- [ ] School filters still work

---

### WordPress: SN Appointments

#### class-snab-rest-api.php
Test iOS app:
- [ ] Get availability works
- [ ] Book appointment works
- [ ] List appointments works
- [ ] Cancel appointment works
- [ ] Reschedule works

Verify Google Calendar:
- [ ] Booking creates Google event
- [ ] Cancel removes Google event
- [ ] Per-staff connection works

#### class-snab-frontend-ajax.php
Test web widget:
- [ ] Availability modal loads
- [ ] Time slots display
- [ ] Booking form submits
- [ ] Confirmation email sends

**CRITICAL:** This file handles WEB bookings. If you also modified `class-snab-rest-api.php`, test BOTH.

#### class-snab-google-calendar.php
Test calendar sync:
- [ ] New booking creates Google event
- [ ] Cancel removes Google event
- [ ] Reschedule updates Google event
- [ ] DateTime format includes seconds
- [ ] Per-staff tokens work (not global)

---

## Property Search Tests

### iOS App

```bash
# Test properties endpoint
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1"

# Test with city filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?city=Boston&per_page=1"

# Test with school filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"

# Test autocomplete
curl "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=boston"
```

### Web Map

1. Load https://bmnboston.com/search/
2. Verify map displays properties
3. Apply city filter, verify count changes
4. Apply school filter, verify count changes
5. Draw polygon, verify results filter

---

## School Integration Tests

```bash
# Property schools endpoint
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26&radius=2"

# Glossary endpoint
curl "https://bmnboston.com/wp-json/bmn-schools/v1/glossary/?term=mcas"

# Health check
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health"
```

---

## Appointment Tests

### Quick API Tests

```bash
# Get fresh token
TOKEN=$(curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"demo@bmnboston.com","password":"demo1234"}' | \
  python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])")

# Check appointments have can_cancel/can_reschedule
curl -s "https://bmnboston.com/wp-json/snab/v1/appointments" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool | grep -E '"can_cancel"|"can_reschedule"'
```

### Full Flow Tests

- [ ] **Book via iOS**: Create appointment → verify Google Calendar sync
- [ ] **Book via Web**: Create appointment → verify Google Calendar sync
- [ ] **Cancel**: Cancel appointment → verify removed from Google Calendar
- [ ] **Reschedule**: Reschedule appointment → verify updated in Google Calendar

---

## Year Rollover Test (Run on Jan 1)

This is critical after year transitions:

```bash
# Should return ~1,600 results, NOT 0
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"

# Should return school rankings
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26&radius=2" | grep "letter_grade"
```

If these return 0 or no grades, the year rollover bug has occurred.

---

## iOS Build Verification

```bash
cd ~/Development/BMNBoston/ios
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15' build
# Expected: BUILD SUCCEEDED
```

### Device Tests

1. Install app on device
2. Launch and verify no crash
3. Search for properties
4. Apply filters
5. View property detail
6. Check school information displays
7. Book an appointment

---

## Web-Specific Tests

### Page Load Tests

1. https://bmnboston.com/search/ - Map loads
2. https://bmnboston.com/schools/ - Browse page loads
3. https://bmnboston.com/schools/boston/ - District page loads

### Saved Search Tests

1. Create saved search on web
2. Verify it appears in saved searches
3. Apply saved search
4. Create saved search on iOS
5. Verify it works on web

---

## Post-Deployment Verification

After deploying to production:

- [ ] Clear browser cache and reload
- [ ] Test on iOS app (may need force quit)
- [ ] Check error logs for warnings
- [ ] Verify CSS/JS changes appear (if applicable)
- [ ] Run quick API tests above

---

## Dual Code Path Reminder

### Property Search
| Change Location | Also Test |
|-----------------|-----------|
| class-mld-mobile-rest-api.php | iOS app |
| class-mld-query.php | Web map |
| **Both** | Both platforms! |

### Appointments
| Change Location | Also Test |
|-----------------|-----------|
| class-snab-rest-api.php | iOS app |
| class-snab-frontend-ajax.php | Web widget |
| **Both** | Both platforms! |

---

## Monitoring

### Error Logs

```bash
# WordPress debug log
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "tail -50 ~/public/wp-content/debug.log"

# PHP error log
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "tail -50 /tmp/php_errors.log"
```

### Key Metrics to Watch

- Property count (should be ~7,400 active)
- School filter counts (should vary by grade)
- API response times (should be < 500ms)
