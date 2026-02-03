# System Architecture Overview

## High-Level Architecture

```
┌─────────────────┐     ┌─────────────────────────────────────────┐
│                 │     │              WordPress                   │
│    iOS App      │────>│                                         │
│   (SwiftUI)     │     │  ┌─────────────────────────────────┐   │
│                 │     │  │     REST API Layer               │   │
└─────────────────┘     │  │  /wp-json/mld-mobile/v1         │   │
                        │  │  /wp-json/bmn-schools/v1        │   │
                        │  │  /wp-json/snab/v1               │   │
                        │  └─────────────────────────────────┘   │
┌─────────────────┐     │                                         │
│                 │     │  ┌─────────────────────────────────┐   │
│   Web Browser   │────>│  │     WordPress Plugins            │   │
│                 │     │  │  - MLS Listings Display          │   │
│                 │     │  │  - BMN Schools                   │   │
└─────────────────┘     │  │  - SN Appointment Booking        │   │
                        │  │  - Bridge MLS Extractor          │   │
                        │  └─────────────────────────────────┘   │
                        │                                         │
                        │  ┌─────────────────────────────────┐   │
                        │  │     MySQL Database               │   │
                        │  │  ~100 custom tables              │   │
                        │  └─────────────────────────────────┘   │
                        └─────────────────────────────────────────┘
                                          │
                                          ▼
                        ┌─────────────────────────────────────────┐
                        │         External Data Sources           │
                        │  - Bridge Interactive (MLS)             │
                        │  - MA E2C Hub (School data)             │
                        │  - Google Calendar API                  │
                        │  - Nominatim (Geocoding)                │
                        └─────────────────────────────────────────┘
```

## iOS App Architecture (MVVM)

```
┌───────────────────────────────────────────────────────────────┐
│                        iOS App                                 │
├───────────────────────────────────────────────────────────────┤
│  Views (SwiftUI)                                              │
│  ├── PropertyMapView, PropertyListView, PropertyDetailView   │
│  ├── LoginView, RegistrationView                              │
│  └── AppointmentBookingView, AppointmentListView              │
├───────────────────────────────────────────────────────────────┤
│  ViewModels                                                    │
│  ├── PropertySearchViewModel (manages search state)           │
│  ├── AuthViewModel (manages authentication)                   │
│  └── AppointmentViewModel (manages bookings)                  │
├───────────────────────────────────────────────────────────────┤
│  Services                                                      │
│  ├── APIClient (networking)                                   │
│  ├── TokenManager (JWT refresh)                               │
│  ├── SchoolService, AppointmentService                        │
│  └── GeocodingService, CityBoundaryService                    │
├───────────────────────────────────────────────────────────────┤
│  Models                                                        │
│  ├── Property, School, Appointment, SavedSearch               │
│  └── User, FilterOptions                                      │
├───────────────────────────────────────────────────────────────┤
│  Storage                                                       │
│  ├── KeychainManager (secure token storage)                   │
│  └── AppearanceManager (dark mode)                            │
└───────────────────────────────────────────────────────────────┘
```

## WordPress Plugin Architecture

```
┌───────────────────────────────────────────────────────────────┐
│                    WordPress Plugins                           │
├───────────────────────────────────────────────────────────────┤
│                                                                │
│  MLS Listings Display                                          │
│  ├── class-mld-mobile-rest-api.php (iOS endpoints)            │
│  ├── class-mld-query.php (Web query builder)                  │
│  ├── class-mld-bmn-schools-integration.php (School filters)   │
│  └── class-mld-sitemap-generator.php (SEO sitemaps)           │
│                                                                │
│  BMN Schools                                                   │
│  ├── class-rest-api.php (School endpoints)                    │
│  ├── class-ranking-calculator.php (Score calculation)         │
│  ├── class-database-manager.php (Schema management)           │
│  └── Data providers (E2C Hub, DESE, MIAA)                     │
│                                                                │
│  SN Appointment Booking                                        │
│  ├── class-snab-rest-api.php (iOS endpoints)                  │
│  ├── class-snab-frontend-ajax.php (Web endpoints)             │
│  ├── class-snab-google-calendar.php (Google sync)             │
│  └── class-snab-availability-service.php (Slot calculation)   │
│                                                                │
│  Bridge MLS Extractor                                          │
│  ├── class-bme-data-processor.php (MLS processing)            │
│  └── class-bme-extraction-engine.php (API integration)        │
│                                                                │
└───────────────────────────────────────────────────────────────┘
```

## Data Flow: Property Search

```
1. User applies filter on iOS
   │
   ▼
2. PropertySearchViewModel builds request
   │
   ▼
3. APIClient sends to /wp-json/mld-mobile/v1/properties
   │
   ▼
4. class-mld-mobile-rest-api.php handles request
   │
   ├─── Queries bme_listing_summary (fast, denormalized)
   │
   ├─── If school filters: calls apply_school_filter()
   │
   └─── Returns JSON response
   │
   ▼
5. APIClient decodes to [Property]
   │
   ▼
6. ViewModel updates @Published properties
   │
   ▼
7. SwiftUI re-renders views
```

## Database Organization

```
┌─────────────────────────────────────────────────────────────┐
│                    Database Tables (~100)                    │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Property Data (Bridge MLS Extractor)                        │
│  ├── bme_listing_summary (Active - ~7,400 rows)             │
│  ├── bme_listing_summary_archive (Sold - ~90,000 rows)      │
│  ├── bme_listing_details, bme_listing_location              │
│  └── bme_media (photos), bme_open_houses                    │
│                                                              │
│  School Data (BMN Schools)                                   │
│  ├── bmn_schools (2,636 schools)                            │
│  ├── bmn_school_districts (342 districts)                   │
│  ├── bmn_school_rankings, bmn_district_rankings             │
│  ├── bmn_school_test_scores (MCAS)                          │
│  └── bmn_school_sports (MIAA)                               │
│                                                              │
│  Appointment Data (SN Appointment Booking)                   │
│  ├── snab_staff, snab_appointment_types                     │
│  ├── snab_appointments, snab_availability_rules             │
│  └── snab_notifications_log                                  │
│                                                              │
│  User Data (MLS Listings Display)                            │
│  ├── mld_saved_searches                                      │
│  └── mld_favorites                                           │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Authentication Flow

```
iOS App                         WordPress
   │                               │
   │  POST /auth/login             │
   │  {email, password}            │
   │─────────────────────────────> │
   │                               │ Validate credentials
   │                               │ Generate JWT tokens
   │  {access_token, refresh_token}│
   │ <─────────────────────────────│
   │                               │
   │  (Store in Keychain)          │
   │                               │
   │  GET /properties              │
   │  Authorization: Bearer TOKEN  │
   │─────────────────────────────> │
   │                               │ Validate token
   │  {properties: [...]}          │
   │ <─────────────────────────────│
   │                               │
   │  (Token expires in 15 min)    │
   │                               │
   │  POST /auth/refresh           │
   │  {refresh_token}              │
   │─────────────────────────────> │
   │                               │ Validate refresh token
   │  {new_access_token}           │
   │ <─────────────────────────────│
   │                               │
```

Token Configuration:
- Access Token: 15 minutes
- Refresh Token: 7 days
