# Project Overview

BMN Boston is a real estate application ecosystem for the Greater Boston area.

## Components

| Component | Technology | Purpose |
|-----------|------------|---------|
| **iOS App** | SwiftUI, iOS 16+ | Native mobile app for property search |
| **WordPress Backend** | PHP 8.0+ | REST APIs and web functionality |
| **MLS Listings Display** | WordPress Plugin | Property search, filters, saved searches |
| **BMN Schools** | WordPress Plugin | Massachusetts school data integration |
| **SN Appointment Booking** | WordPress Plugin | Google Calendar-integrated bookings |
| **Bridge MLS Extractor** | WordPress Plugin | MLS data extraction from Bridge API |

## Current Versions

See **[../VERSIONS.md](../VERSIONS.md)** for all component versions and version bump instructions.

## Repository Structure

```
~/Development/BMNBoston/
├── ios/                    # iOS App (Xcode project)
│   └── BMNBoston/
│       ├── App/            # App entry point, Environment.swift
│       ├── Core/           # Models, Networking, Services, Storage
│       ├── Features/       # Authentication, PropertySearch, Appointments, etc.
│       └── UI/             # Components, Styles
├── wordpress/
│   ├── docker/             # docker-compose.yml and config
│   └── wp-content/plugins/ # Custom plugins
├── shared/
│   └── scripts/            # start-dev.sh, stop-dev.sh, db scripts
├── docs/                   # Legacy documentation (being migrated)
└── .context/               # NEW: Organized documentation
```

## Key URLs

### Production
- **Website**: https://bmnboston.com
- **API Base**: https://bmnboston.com/wp-json/
- **iOS App Store**: https://apps.apple.com/us/app/bmn-boston/id6745724401
- **School Pages**: https://bmnboston.com/schools/

### Development
- **WordPress**: http://localhost:8080
- **phpMyAdmin**: http://localhost:8081
- **Mailhog**: http://localhost:8025

## API Namespaces

| Namespace | Plugin | Purpose |
|-----------|--------|---------|
| `/mld-mobile/v1` | MLS Listings Display | Property search, auth, favorites |
| `/bmn-schools/v1` | BMN Schools | School data, rankings, glossary |
| `/snab/v1` | SN Appointment Booking | Bookings, availability |
| `/bme/v1` | Bridge MLS Extractor | Admin MLS extraction |

## External Data Sources

| Source | Purpose | Update Frequency |
|--------|---------|------------------|
| Bridge Interactive API | MLS property data | Daily |
| MA E2C Hub (Socrata) | School metrics, MCAS | Annually |
| MIAA | High school sports | Annually |
| Nominatim | School geocoding | On demand |
| NCES EDGE | District boundaries | Static |

## Testing Environment

**IMPORTANT:** All testing is done against **PRODUCTION** (bmnboston.com), NOT localhost.

- **Demo Account**: demo@bmnboston.com / demo1234
- **Test Device**: iPhone 16 Pro (Device ID: `00008140-00161D3A362A801C`)
