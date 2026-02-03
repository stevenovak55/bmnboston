# BMN Schools Data Sources

Documentation for school data import sources and processes.

**Last Updated:** January 2, 2026

---

## Data Sources Overview

| Source | Dataset | Records | Update Frequency |
|--------|---------|---------|------------------|
| MA DESE / E2C Hub | MCAS Test Scores | ~44,000 | Annually (Fall) |
| MA DESE / E2C Hub | Enrollment Demographics | ~5,500 | Annually |
| MA DESE / E2C Hub | School Features | ~21,000 | Annually |
| MassGIS | School Locations | 2,636 | As needed |
| NCES EDGE | District Boundaries | 342 | As needed |

---

## E2C Hub (Primary Data Source)

### API Base URL

```
https://educationtocareer.data.mass.gov/resource/
```

### Datasets Used

| Dataset ID | Name | Purpose |
|------------|------|---------|
| `i9w6-niyt` | MCAS Achievement Results | Test scores (ELA, Math, Science) |
| `t8td-gens` | Enrollment Demographics | Student demographics |
| `evue-jvn7` | Class Size | Student-teacher ratios |
| `u6ki-t75i` | Educator Qualifications | Teacher certifications |
| `er3w-dyti` | Per-Pupil Spending | District expenditures |
| `t9ya-d7ak` | Educator Characteristics | Staff data |

### API Query Format (SoQL)

```bash
# Example: Get MCAS scores for 2024
curl "https://educationtocareer.data.mass.gov/resource/i9w6-niyt.json?\$where=year=2024&org_type=School&\$limit=1000"
```

### Rate Limits

- **Unauthenticated**: 1,000 requests/hour
- **App Token**: 10,000 requests/hour
- **Request delay**: 250ms between requests (plugin default)

---

## MCAS Test Scores

**Dataset:** `i9w6-niyt`

### Key Fields

| Field | Description |
|-------|-------------|
| `org_code` | 8-digit school identifier |
| `org_name` | School name |
| `year` | Academic year |
| `subject` | ELA, Math, Science |
| `grade` | Grade level or "All Grades" |
| `student_group` | "All Students" or demographic group |
| `m_e` | % Meeting or Exceeding Expectations |
| `exceeding` | % Exceeding Expectations |
| `meeting` | % Meeting Expectations |
| `partially_meeting` | % Partially Meeting |
| `not_meeting` | % Not Meeting |
| `avg_sgp` | Student Growth Percentile |

### Import Query

```php
$url = "https://educationtocareer.data.mass.gov/resource/i9w6-niyt.json";
$params = [
    '$where' => "year={$year} AND org_type='School' AND student_group='All Students'",
    '$limit' => 50000,
];
```

---

## School Features

Various datasets feed into the school features table:

### Graduation Rates (`t8td-gens`)

- 4-year graduation rate
- 5-year graduation rate
- Dropout rate

### Attendance

- Chronic absenteeism rate
- Average daily attendance

### AP Performance

- AP exam participation
- AP exam pass rate (3+)

### Staffing

- Student-teacher ratio
- % Experienced teachers
- % Licensed teachers

---

## Database Tables

### `wp_bmn_schools`

Core school information from MassGIS.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| name | VARCHAR | School name |
| org_code | VARCHAR | MA DESE 8-digit code |
| school_type | VARCHAR | Elementary/Middle/High |
| district_id | INT | FK to districts table |
| latitude | DECIMAL | Location |
| longitude | DECIMAL | Location |
| address | VARCHAR | Street address |
| city | VARCHAR | City |
| zip | VARCHAR | ZIP code |

### `wp_bmn_school_test_scores`

MCAS test score data by year.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| school_id | INT | FK to schools |
| year | INT | Academic year |
| subject | VARCHAR | ELA/Math/Science |
| grade | VARCHAR | Grade level |
| m_e | DECIMAL | % Meeting/Exceeding |
| avg_sgp | DECIMAL | Growth percentile |

### `wp_bmn_school_rankings`

Calculated rankings (output of ranking algorithm).

| Column | Type | Description |
|--------|------|-------------|
| school_id | INT | FK to schools |
| year | INT | Ranking year |
| composite_score | DECIMAL | 0-100 score |
| letter_grade | VARCHAR | A+, A, B+, etc. |
| percentile | INT | State percentile |
| data_completeness | DECIMAL | % of factors available |

### `wp_bmn_school_features`

Additional school metrics from various sources.

| Column | Type | Description |
|--------|------|-------------|
| school_id | INT | FK to schools |
| year | INT | Data year |
| category | VARCHAR | staffing/graduation/ap/etc. |
| metric_name | VARCHAR | Specific metric |
| metric_value | DECIMAL | Value |

---

## Import Process

### Manual Import (WP Admin)

1. Navigate to **Schools → Import Data**
2. Select data source (DESE/MCAS, etc.)
3. Choose year(s) to import
4. Click **Run Import**

### WP-CLI Import

```bash
# Import all MCAS data for 2024
wp bmn-schools import mcas --year=2024

# Import all available data
wp bmn-schools import all

# Recalculate rankings after import
wp bmn-schools recalculate-rankings
```

### Cron Job (Automatic)

Plugin schedules annual import check in September when new MCAS data is typically available.

---

## Caching Strategy

### Cache Layers

1. **Transients**: API responses cached 24 hours
2. **Database**: Imported data permanent until re-import
3. **Rankings Cache**: Recalculated on data change

### Cache Invalidation

```php
// Clear school cache
BMN_Schools_Cache_Manager::clear('school', $school_id);

// Clear all school caches
BMN_Schools_Cache_Manager::clear_all('schools');

// Rebuild rankings after data change
do_action('bmn_schools_data_updated');
```

---

## Year Rollover Considerations

**CRITICAL:** Never use `date('Y')` to get the data year.

```php
// WRONG - breaks on January 1
$year = date('Y');

// CORRECT - get latest year from data
$year = $wpdb->get_var("SELECT MAX(year) FROM {$rankings_table}");
```

See [Year Rollover Bug](../../troubleshooting/year-rollover-bug.md) for details.

---

## Data Freshness

| Data Type | Typical Release | Notes |
|-----------|-----------------|-------|
| MCAS Scores | September | Previous school year |
| Enrollment | October | Current school year |
| Graduation Rates | January | Previous cohort |
| District Spending | November | Previous fiscal year |

---

## Related Documentation

- [BMN Schools README](README.md) - Plugin overview
- [Critical Pitfalls](../../cross-cutting/critical-pitfalls.md) - Year rollover and school grade bugs
- [Year Rollover Bug](../../troubleshooting/year-rollover-bug.md) - Critical date handling
