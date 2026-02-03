# Property Search Filters

All supported filter parameters for the properties endpoint.

## Endpoint

```
GET /wp-json/mld-mobile/v1/properties
```

## Location Filters

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `city` | string/array | City name(s) | `city=Boston` or `city[]=Boston&city[]=Cambridge` |
| `zip` | string/array | ZIP code(s) | `zip=02101` |
| `neighborhood` | string/array | Neighborhood name(s) | `neighborhood=Back%20Bay` |
| `address` | string | Full address for exact match | `address=123%20Main%20Street` |
| `mls_number` | string | MLS number for exact match | `mls_number=12345678` |
| `street_name` | string | Street name for partial match | `street_name=Beacon` |
| `bounds` | string | Map bounds: `south,west,north,east` | `bounds=42.2,-71.2,42.4,-71.0` |
| `polygon` | array | Draw search coordinates | `polygon[0][lat]=42.35&polygon[0][lng]=-71.06` |

## Property Filters

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `property_type` | string | Property type | `Residential`, `Residential Lease`, `Commercial Sale`, `Land` |
| `property_sub_type` | string | Sub-type | `Single Family Residence`, `Condominium` |
| `status` | string | Listing status | `Active`, `Pending`, `Sold` |

## Price Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `min_price` | int | Minimum price |
| `max_price` | int | Maximum price |
| `price_reduced` | bool | Only price-reduced listings |

## Room Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `beds` | int | Minimum bedrooms |
| `baths` | float | Minimum bathrooms |

## Size Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `sqft_min` | int | Minimum square footage |
| `sqft_max` | int | Maximum square footage |
| `lot_size_min` | float | Minimum lot size (acres) |
| `lot_size_max` | float | Maximum lot size (acres) |

## Age Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `year_built_min` | int | Minimum year built |
| `year_built_max` | int | Maximum year built |

## Parking Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `garage_spaces_min` | int | Minimum garage spaces |
| `parking_total_min` | int | Minimum total parking |

## Time Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `new_listing_days` | int | Listed within X days |
| `max_dom` | int | Maximum days on market |

## Amenity Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `PoolPrivateYN` | bool | Has private pool |
| `WaterfrontYN` | bool | Waterfront property |
| `FireplaceYN` | bool | Has fireplace |
| `GarageYN` | bool | Has garage |
| `CoolingYN` | bool | Has cooling/AC |
| `SpaYN` | bool | Has spa |
| `ViewYN` | bool | Has view |
| `MLSPIN_WATERVIEW_FLAG` | bool | Has water view |
| `SeniorCommunityYN` | bool | Senior community |
| `has_virtual_tour` | bool | Has virtual tour |
| `has_basement` | bool | Has basement |
| `pet_friendly` | bool | Pet friendly |

## Special Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `open_house_only` | bool | Only listings with open houses |

## School Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `school_grade` | string | Minimum school grade: `A`, `B`, `C` |
| `near_top_elementary` | bool | Within 2mi of A-rated elementary |
| `near_top_high` | bool | Within 3mi of A-rated high school |
| `near_a_elementary` | bool | Alias for `near_top_elementary` |
| `school_district_id` | int | Specific school district ID |

**Note:** School filters use post-query filtering. API over-fetches 3x for consistent pagination.

## Sort Options

| Value | Description |
|-------|-------------|
| `price_asc` | Price low to high |
| `price_desc` | Price high to low |
| `list_date_asc` | Oldest first |
| `list_date_desc` | Newest first (default) |
| `beds_desc` | Most bedrooms first |
| `sqft_desc` | Largest first |

## Pagination

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 50 | Results per page (max 100) |

## Example Queries

```bash
# Active residential, 3+ beds, under $1M in Boston
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?\
property_type=Residential&\
status=Active&\
city=Boston&\
beds=3&\
max_price=1000000&\
per_page=10"

# A-rated school district, price reduced
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?\
school_grade=A&\
price_reduced=true&\
per_page=10"

# Map bounds search
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?\
bounds=42.35,-71.1,42.37,-71.05&\
per_page=50"
```

## Adding New Filters

When adding a new filter parameter:

1. **Read the parameter** (around line 650 in `class-mld-mobile-rest-api.php`):
   ```php
   $my_param = sanitize_text_field($request->get_param('my_param'));
   ```

2. **Add WHERE clause** (around line 780):
   ```php
   if (!empty($my_param)) {
       $where[] = "s.column_name = %s";
       $params[] = $my_param;
   }
   ```

3. **Update web path too** (in `class-mld-query.php`)

See [Code Paths](../../architecture/code-paths.md) for details.
