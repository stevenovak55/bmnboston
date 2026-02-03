# BMN Schools - TODO

## Current Sprint: Phase 1 - Foundation

### In Progress
- [ ] Create all core class files
  - Status: Main plugin file created, working on includes/
  - Blocker: None

### Up Next
- [ ] Test plugin activation on production server
- [ ] Verify all 10 tables are created correctly
- [ ] Implement basic `/schools` endpoint
- [ ] Create admin dashboard UI

### Phase 1 Checklist
- [x] Plugin directory structure
- [x] Main plugin file (bmn-schools.php)
- [x] version.json
- [x] CLAUDE.md
- [x] Documentation structure (docs/)
- [ ] class-bmn-schools.php (singleton)
- [ ] class-database-manager.php (10 tables)
- [ ] class-activator.php
- [ ] class-deactivator.php
- [ ] class-logger.php
- [ ] class-rest-api.php
- [ ] class-admin.php
- [ ] Admin dashboard view
- [ ] Test activation/deactivation

---

## Backlog

### Phase 2: Massachusetts Data + History
- [ ] NCES CCD data importer
- [ ] NCES EDGE boundary fetcher
- [ ] MA DESE MCAS importer (all years)
- [ ] MassGIS school locations
- [ ] Boston Public Schools data
- [ ] Demographics import

### Phase 3: Attendance Zone Boundaries
- [ ] Evaluate ATTOM API (30-day trial)
- [ ] Implement attendance zone storage
- [ ] Point-in-polygon queries
- [ ] Map overlay support

### Phase 4: Enhanced Features
- [ ] Caching layer
- [ ] Autocomplete search
- [ ] School comparison
- [ ] Trend analysis

### Phase 5: Platform Integration
- [ ] MLS plugin hooks
- [ ] iOS app Swift models
- [ ] Website templates
- [ ] Map overlays

### Phase 6: Paid Enhancements
- [ ] SchoolDigger integration
- [ ] GreatSchools integration
- [ ] Niche data

---

## Completed (Recent)
- [x] Initial plan created and approved - 2025-12-19
- [x] Plugin directory structure created - 2025-12-19
- [x] Main plugin file created - 2025-12-19
- [x] Documentation files created - 2025-12-19
