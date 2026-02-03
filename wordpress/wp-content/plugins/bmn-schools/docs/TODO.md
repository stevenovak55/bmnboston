# BMN Schools - TODO

**Last Updated:** 2026-01-21

## Status: Phase 1 Complete

Phase 1 (Foundation) is **fully implemented** and deployed (v0.6.39).

### Completed Features
- [x] Plugin directory structure
- [x] Main plugin file (bmn-schools.php)
- [x] version.json
- [x] CLAUDE.md redirect
- [x] Documentation structure (docs/)
- [x] class-bmn-schools.php (singleton)
- [x] class-database-manager.php (10 tables)
- [x] class-activator.php
- [x] class-deactivator.php
- [x] class-logger.php
- [x] class-rest-api.php (161K lines - comprehensive API)
- [x] class-cache-manager.php
- [x] class-geocoder.php
- [x] class-integration.php (MLD plugin integration)
- [x] class-ranking-calculator.php (77K lines - MCAS scoring)
- [x] class-school-pages.php (district/school detail pages)
- [x] Admin dashboard
- [x] Data providers (NCES, DESE, etc.)
- [x] Tests

---

## Backlog (All Optional)

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
- [ ] School comparison tool
- [ ] Trend analysis (multi-year)
- [ ] Advanced autocomplete

### Phase 5: Platform Integration
- [ ] Enhanced iOS app Swift models
- [ ] Website template improvements
- [ ] Map overlays with boundaries

### Phase 6: Paid Enhancements
- [ ] SchoolDigger integration
- [ ] GreatSchools integration
- [ ] Niche data

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| v0.6.39 | 2026-01-21 | School district 404 fix - rewrite rules register on all requests |
| v0.6.38 | 2026-01-20 | Grade consistency and lookback bug fixes |
| v0.6.x | 2025-12 to 2026-01 | Phase 1 implementation |

---

*For full version history, see `.context/plugins/bmn-schools/version-history.md`*
