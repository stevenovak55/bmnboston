# BMN Schools Plugin

Massachusetts school data integration for real estate context.

## Quick Info

| Setting | Value |
|---------|-------|
| Version | 0.6.39 |
| API Namespace | `/wp-json/bmn-schools/v1` |
| Main File | `bmn-schools.php` |
| Current Phase | 7 - School Research Platform |

## Data Coverage

| Data Type | Records | Source |
|-----------|---------|--------|
| Schools | 2,636 | MassGIS |
| Districts | 342 | NCES EDGE |
| MCAS Scores | 44,213 | MA DESE |
| Demographics | 5,460 | E2C Hub |
| Sports | 8,114 | MIAA |
| Rankings | 4,930 | Calculated |

## Key Files

| File | Purpose |
|------|---------|
| `includes/class-rest-api.php` | REST API endpoints |
| `includes/class-ranking-calculator.php` | Score calculation |
| `includes/class-database-manager.php` | Schema management |
| `includes/class-school-pages.php` | Virtual page routing |

## Documentation

- [Data Sources](data-sources.md) - E2C Hub, DESE, MIAA data imports
- [Main CLAUDE.md](../../../CLAUDE.md) - Critical pitfalls and API testing

## Web Pages

Virtual pages for school research:
- Browse: https://bmnboston.com/schools/
- District: https://bmnboston.com/schools/{district-slug}/
- School: https://bmnboston.com/schools/{district-slug}/{school-slug}/

## Quick API Tests

```bash
# Schools near location
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26&radius=2"

# Glossary term
curl "https://bmnboston.com/wp-json/bmn-schools/v1/glossary/?term=mcas"

# Health check
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health"
```

## Ranking Weights

### Middle/High Schools

| Factor | Weight |
|--------|--------|
| MCAS Proficiency | 40% |
| Graduation Rate | 12% |
| MCAS Growth | 10% |
| AP Performance | 9% |
| MassCore | 8% |
| Attendance | 8% |
| Student-Teacher Ratio | 5% |
| Per-Pupil Spending | 4% |
| College Outcomes | 4% |

### Elementary Schools

| Factor | Weight |
|--------|--------|
| MCAS Proficiency | 45% |
| Attendance | 20% |
| MCAS Growth | 15% |
| Per-Pupil Spending | 12% |
| Student-Teacher Ratio | 8% |

## Version Updates

Update ALL 3 locations:
1. `version.json`
2. `bmn-schools.php` header
3. `BMN_SCHOOLS_VERSION` constant

## Critical Rules

1. **Never use `date('Y')` for rankings** - Query `MAX(year)` instead
2. **Schools need MCAS data to be ranked**
3. **Private schools excluded from district rankings**
