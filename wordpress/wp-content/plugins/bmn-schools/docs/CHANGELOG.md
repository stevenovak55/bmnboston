# BMN Schools - Changelog

All notable changes to this plugin will be documented in this file.

## [0.1.0] - 2025-12-19

### Added
- Initial plugin structure following BMN Boston patterns
- Main singleton class (`class-bmn-schools.php`)
- Database manager with 10 tables
- Activity logger for debugging
- Activator and deactivator classes
- Basic REST API endpoints
- Admin dashboard structure
- Comprehensive documentation structure

### Database Tables Created
1. `bmn_schools` - School directory
2. `bmn_school_districts` - District info
3. `bmn_school_locations` - Location mapping
4. `bmn_school_test_scores` - MCAS scores
5. `bmn_school_rankings` - Third-party ratings
6. `bmn_school_demographics` - Demographics
7. `bmn_school_features` - Programs/features
8. `bmn_school_attendance_zones` - School boundaries
9. `bmn_school_data_sources` - Sync tracking
10. `bmn_schools_activity_log` - Activity logging

### Notes
- Phase 1 (Foundation) of 6 phases
- Designed to support public and private schools
- Will store all historical MCAS data (10+ years)
