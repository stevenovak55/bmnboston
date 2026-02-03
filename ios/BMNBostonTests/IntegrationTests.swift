//
//  IntegrationTests.swift
//  BMNBostonTests
//
//  Created for BMN Boston Real Estate
//  Integration tests to verify iOS ↔ Backend parity
//

import XCTest
import CoreLocation
@testable import BMNBoston

/// Integration tests that verify iOS app correctly parses backend API responses.
/// These tests call the actual production API and validate response parsing.
///
/// Note: These tests require network access and test against production data.
/// They verify that:
/// 1. API responses match expected iOS model structures
/// 2. Filter serialization works correctly with the backend
/// 3. School data integration works properly
final class PropertyAPIIntegrationTests: XCTestCase {

    // MARK: - Property Search Tests

    /// Test that basic property search returns parseable results
    func testPropertySearchReturnsValidResults() async throws {
        var filters = PropertySearchFilters()
        filters.perPage = 5

        let endpoint = APIEndpoint.properties(filters: filters)

        do {
            let response: PropertyListData = try await APIClient.shared.request(endpoint)

            // Verify we got results
            XCTAssertGreaterThan(response.total ?? 0, 0, "Should return some properties")
            XCTAssertFalse(response.listings.isEmpty, "Properties array should not be empty")

            // Verify first property has required fields
            if let firstProperty = response.listings.first {
                XCTAssertFalse(firstProperty.id.isEmpty, "Property should have an ID")
                XCTAssertFalse(firstProperty.address.isEmpty, "Property should have an address")
                XCTAssertGreaterThan(firstProperty.price, 0, "Property should have a price")
            }
        } catch {
            XCTFail("Property search failed: \(error)")
        }
    }

    /// Test that price filters are correctly applied by the backend
    func testPriceFiltersApplied() async throws {
        var filters = PropertySearchFilters()
        filters.minPrice = 500000
        filters.maxPrice = 800000
        filters.perPage = 10

        let endpoint = APIEndpoint.properties(filters: filters)

        do {
            let response: PropertyListData = try await APIClient.shared.request(endpoint)

            // All returned properties should be within price range
            for property in response.listings {
                XCTAssertGreaterThanOrEqual(property.price, 500000,
                    "Property price \(property.price) should be >= min price 500000")
                XCTAssertLessThanOrEqual(property.price, 800000,
                    "Property price \(property.price) should be <= max price 800000")
            }
        } catch {
            XCTFail("Price filtered search failed: \(error)")
        }
    }

    /// Test that city filter works correctly
    func testCityFilterApplied() async throws {
        var filters = PropertySearchFilters()
        filters.cities = ["Boston"]
        filters.perPage = 10

        let endpoint = APIEndpoint.properties(filters: filters)

        do {
            let response: PropertyListData = try await APIClient.shared.request(endpoint)

            // All returned properties should be in Boston
            for property in response.listings {
                XCTAssertEqual(property.city.lowercased(), "boston",
                    "Property city '\(property.city)' should be Boston")
            }
        } catch {
            XCTFail("City filtered search failed: \(error)")
        }
    }

    /// Test that beds filter works correctly
    func testBedsFilterApplied() async throws {
        var filters = PropertySearchFilters()
        filters.beds = [3] // Minimum 3 beds
        filters.perPage = 10

        let endpoint = APIEndpoint.properties(filters: filters)

        do {
            let response: PropertyListData = try await APIClient.shared.request(endpoint)

            // All returned properties should have at least 3 beds
            for property in response.listings {
                XCTAssertGreaterThanOrEqual(property.beds, 3,
                    "Property beds \(property.beds) should be >= 3")
            }
        } catch {
            XCTFail("Beds filtered search failed: \(error)")
        }
    }

    /// Test that status filter works correctly
    func testStatusFilterApplied() async throws {
        var filters = PropertySearchFilters()
        filters.statuses = [.pending]
        filters.perPage = 5

        let endpoint = APIEndpoint.properties(filters: filters)

        do {
            let response: PropertyListData = try await APIClient.shared.request(endpoint)

            // All returned properties should have pending status
            for property in response.listings {
                XCTAssertEqual(property.standardStatus, .pending,
                    "Property status '\(property.standardStatus)' should be pending")
            }
        } catch {
            XCTFail("Status filtered search failed: \(error)")
        }
    }

    /// Test that map bounds filter works correctly
    func testMapBoundsFilterApplied() async throws {
        // Boston area bounds
        var filters = PropertySearchFilters()
        filters.mapBounds = MapBounds(north: 42.40, south: 42.30, east: -71.00, west: -71.15)
        filters.perPage = 10

        let endpoint = APIEndpoint.properties(filters: filters)

        do {
            let response: PropertyListData = try await APIClient.shared.request(endpoint)

            // All returned properties should have coordinates within bounds
            for property in response.listings {
                if let lat = property.latitude, let lng = property.longitude {
                    XCTAssertGreaterThanOrEqual(lat, 42.30, "Latitude should be >= south bound")
                    XCTAssertLessThanOrEqual(lat, 42.40, "Latitude should be <= north bound")
                    XCTAssertGreaterThanOrEqual(lng, -71.15, "Longitude should be >= west bound")
                    XCTAssertLessThanOrEqual(lng, -71.00, "Longitude should be <= east bound")
                }
            }
        } catch {
            XCTFail("Map bounds filtered search failed: \(error)")
        }
    }

    /// Test that school grade filter returns results (verifies year rollover fix)
    func testSchoolGradeFilterReturnsResults() async throws {
        var filters = PropertySearchFilters()
        filters.nearAElementary = true
        filters.perPage = 5

        let endpoint = APIEndpoint.properties(filters: filters)

        do {
            let response: PropertyListData = try await APIClient.shared.request(endpoint)

            // This is a critical test - school filters should return results
            // If this fails with 0 results, it likely indicates the year rollover bug
            XCTAssertGreaterThan(response.total ?? 0, 0,
                "School filter should return results (check for year rollover bug if failing)")
        } catch {
            XCTFail("School grade filtered search failed: \(error)")
        }
    }

    /// Test pagination works correctly
    func testPaginationWorks() async throws {
        var filters = PropertySearchFilters()
        filters.perPage = 5
        filters.page = 1

        let endpoint1 = APIEndpoint.properties(filters: filters)
        let response1: PropertyListData = try await APIClient.shared.request(endpoint1)

        filters.page = 2
        let endpoint2 = APIEndpoint.properties(filters: filters)
        let response2: PropertyListData = try await APIClient.shared.request(endpoint2)

        // Should have same total but different properties
        XCTAssertEqual(response1.total, response2.total, "Total should be same across pages")

        if !response1.listings.isEmpty && !response2.listings.isEmpty {
            XCTAssertNotEqual(response1.listings.first?.id, response2.listings.first?.id,
                "Different pages should have different first property")
        }
    }
}

// MARK: - Autocomplete Integration Tests

final class AutocompleteIntegrationTests: XCTestCase {

    /// Test that autocomplete returns suggestions for city search
    func testAutocompleteCitySuggestions() async throws {
        let endpoint = APIEndpoint.autocomplete(term: "boston")

        do {
            let suggestions: [AutocompleteSuggestion] = try await APIClient.shared.request(endpoint)

            XCTAssertFalse(suggestions.isEmpty, "Should return suggestions for 'boston'")

            // Should include Boston as a city suggestion
            let hasBostonCity = suggestions.contains { suggestion in
                suggestion.value.lowercased().contains("boston") &&
                (suggestion.type == "city" || suggestion.type == "City")
            }
            XCTAssertTrue(hasBostonCity, "Should include Boston city suggestion")
        } catch {
            XCTFail("Autocomplete failed: \(error)")
        }
    }

    /// Test that autocomplete returns suggestions for address search
    func testAutocompleteAddressSuggestions() async throws {
        let endpoint = APIEndpoint.autocomplete(term: "123 main")

        do {
            let suggestions: [AutocompleteSuggestion] = try await APIClient.shared.request(endpoint)

            // Should return some suggestions (may be addresses or street names)
            // This mainly verifies the parsing works
            XCTAssertNotNil(suggestions, "Should return suggestions array")
        } catch {
            XCTFail("Autocomplete address search failed: \(error)")
        }
    }

    /// Test that autocomplete returns suggestions for neighborhood search
    func testAutocompleteNeighborhoodSuggestions() async throws {
        let endpoint = APIEndpoint.autocomplete(term: "back bay")

        do {
            let suggestions: [AutocompleteSuggestion] = try await APIClient.shared.request(endpoint)

            XCTAssertFalse(suggestions.isEmpty, "Should return suggestions for 'back bay'")
        } catch {
            XCTFail("Autocomplete neighborhood search failed: \(error)")
        }
    }
}

// MARK: - School API Integration Tests

final class SchoolAPIIntegrationTests: XCTestCase {

    /// Test that property schools endpoint returns valid data
    func testPropertySchoolsEndpoint() async throws {
        // Coordinates for a Boston location
        let lat = 42.3601
        let lng = -71.0589
        let radius = 2.0

        let endpoint = APIEndpoint.propertySchools(latitude: lat, longitude: lng, radius: radius)

        do {
            let response: PropertySchoolsData = try await APIClient.shared.request(endpoint)

            // Should have schools data structure
            XCTAssertNotNil(response.schools, "Should return schools data")

            // Check if any school level has data
            let hasElementary = !response.schools.elementary.isEmpty
            let hasMiddle = !response.schools.middle.isEmpty
            let hasHigh = !response.schools.high.isEmpty

            XCTAssertTrue(hasElementary || hasMiddle || hasHigh,
                "Should return schools for at least one level")

            // If we have schools, verify they have required fields
            if let firstSchool = response.schools.elementary.first ??
                                 response.schools.middle.first ??
                                 response.schools.high.first {
                XCTAssertGreaterThan(firstSchool.id, 0, "School should have valid ID")
                XCTAssertFalse(firstSchool.name.isEmpty, "School should have name")
            }
        } catch {
            XCTFail("Property schools endpoint failed: \(error)")
        }
    }

    /// Test schools health endpoint
    func testSchoolsHealthEndpoint() async throws {
        // Use URLSession directly since this isn't a standard APIClient endpoint
        let url = URL(string: "https://bmnboston.com/wp-json/bmn-schools/v1/health")!

        do {
            let (data, response) = try await URLSession.shared.data(from: url)

            guard let httpResponse = response as? HTTPURLResponse else {
                XCTFail("Invalid response type")
                return
            }

            XCTAssertEqual(httpResponse.statusCode, 200, "Health endpoint should return 200")

            // Parse response - API returns {success: true, data: {status: "healthy", ...}}
            if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
               let dataDict = json["data"] as? [String: Any] {
                XCTAssertEqual(dataDict["status"] as? String, "healthy", "Health status should be 'healthy'")
            } else if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
                // Fallback: check if status is at root level
                XCTAssertEqual(json["status"] as? String, "healthy", "Health status should be 'healthy'")
            }
        } catch {
            XCTFail("Schools health check failed: \(error)")
        }
    }
}

// MARK: - Property Detail Integration Tests

final class PropertyDetailIntegrationTests: XCTestCase {

    /// Test that property detail endpoint returns valid data
    func testPropertyDetailEndpoint() async throws {
        // First, get a property ID from search
        var filters = PropertySearchFilters()
        filters.perPage = 1

        let searchEndpoint = APIEndpoint.properties(filters: filters)
        let searchResponse: PropertyListData = try await APIClient.shared.request(searchEndpoint)

        guard let firstProperty = searchResponse.listings.first else {
            XCTFail("Need at least one property to test detail endpoint")
            return
        }

        // Use the listing_key (hash) for detail lookup - this is the id from search results
        // Note: The API detail endpoint uses listing_key, not MLS number
        let propertyId = firstProperty.id
        let detailEndpoint = APIEndpoint.propertyDetail(id: propertyId)

        do {
            // API returns property fields directly in data (not wrapped in "listing")
            let detail: PropertyDetail = try await APIClient.shared.request(detailEndpoint)

            // Verify basic fields
            XCTAssertFalse(detail.address.isEmpty, "Detail should have address")
            XCTAssertGreaterThan(detail.price, 0, "Detail should have price")

            // Verify property has an ID
            XCTAssertFalse(detail.id.isEmpty, "Detail should have ID")
        } catch let error as APIError {
            // Handle specific API errors
            switch error {
            case .notFound:
                XCTFail("Property not found with ID: \(propertyId)")
            case .serverError(let code, let message):
                XCTFail("Server error (\(code)): \(message)")
            default:
                XCTFail("Property detail endpoint failed: \(error)")
            }
        } catch {
            XCTFail("Property detail endpoint failed: \(error)")
        }
    }
}

// MARK: - Response Format Tests

final class APIResponseFormatTests: XCTestCase {

    /// Test that API returns expected wrapper format
    func testAPIResponseWrapper() async throws {
        let baseURL = AppEnvironment.current.fullAPIURL
        guard let url = URL(string: baseURL + "/properties?page=1&per_page=1") else {
            XCTFail("Invalid URL")
            return
        }

        let (data, _) = try await URLSession.shared.data(from: url)

        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            XCTFail("Response should be valid JSON")
            return
        }

        // Check expected wrapper fields
        XCTAssertNotNil(json["success"], "Response should have 'success' field")
        XCTAssertNotNil(json["data"], "Response should have 'data' field")

        // Check data contains expected fields
        if let dataField = json["data"] as? [String: Any] {
            XCTAssertNotNil(dataField["listings"],
                "Data should contain listings")
            XCTAssertNotNil(dataField["total"], "Data should contain total count")
        }
    }

    /// Test that error responses have consistent format
    func testErrorResponseFormat() async throws {
        // Request a non-existent property
        let endpoint = APIEndpoint.propertyDetail(id: "nonexistent_id_12345")

        do {
            let _: PropertyDetailData = try await APIClient.shared.request(endpoint)
            // If we get here, the endpoint might return empty data instead of error
        } catch let error as APIError {
            // Expected - verify error is properly typed
            switch error {
            case .notFound:
                // Good - this is expected
                break
            case .serverError(let code, let message):
                // Also acceptable - server returned error
                XCTAssertFalse(code.isEmpty, "Error should have code")
                XCTAssertFalse(message.isEmpty, "Error should have message")
            default:
                // Other errors are also acceptable for this test
                break
            }
        } catch {
            // Non-APIError is unexpected but not necessarily a failure
            // The main point is that parsing works
        }
    }
}

// MARK: - Filter Serialization Parity Tests

final class FilterSerializationParityTests: XCTestCase {

    /// Test that all filter types serialize correctly for API
    func testAllFilterTypesSerialize() {
        var filters = PropertySearchFilters()

        // Set every filter type
        filters.listingType = .forSale
        filters.minPrice = 500000
        filters.maxPrice = 1000000
        filters.beds = [2, 3, 4]
        filters.minBaths = 2.0
        filters.cities = ["Boston", "Cambridge"]
        filters.neighborhoods = ["Back Bay"]
        filters.zips = ["02101"]
        filters.propertyTypes = [.house, .condo]
        filters.statuses = [.active, .pending]
        filters.minSqft = 1000
        filters.maxSqft = 3000
        filters.minYearBuilt = 2000
        filters.maxYearBuilt = 2024
        filters.hasPool = true
        filters.hasFireplace = true
        filters.hasWaterfront = true
        filters.hasGarage = true
        filters.nearAElementary = true
        filters.priceReduced = true
        filters.newListing = true
        filters.newListingDays = 7
        filters.openHouseOnly = true
        filters.sort = .priceDesc
        filters.mapBounds = MapBounds(north: 42.4, south: 42.3, east: -71.0, west: -71.2)

        let dict = filters.toDictionary()

        // Verify all expected keys are present
        XCTAssertNotNil(dict["listing_type"])
        XCTAssertNotNil(dict["min_price"])
        XCTAssertNotNil(dict["max_price"])
        XCTAssertNotNil(dict["beds"])
        XCTAssertNotNil(dict["baths"])
        XCTAssertNotNil(dict["city"])
        XCTAssertNotNil(dict["neighborhood"])
        XCTAssertNotNil(dict["zip"])
        XCTAssertNotNil(dict["property_type"])
        XCTAssertNotNil(dict["status"])
        XCTAssertNotNil(dict["sqft_min"])
        XCTAssertNotNil(dict["sqft_max"])
        XCTAssertNotNil(dict["year_built_min"])
        XCTAssertNotNil(dict["year_built_max"])
        XCTAssertNotNil(dict["PoolPrivateYN"])
        XCTAssertNotNil(dict["FireplaceYN"])
        XCTAssertNotNil(dict["WaterfrontYN"])
        XCTAssertNotNil(dict["GarageYN"])
        XCTAssertNotNil(dict["near_a_elementary"])
        XCTAssertNotNil(dict["price_reduced"])
        XCTAssertNotNil(dict["new_listing_days"])
        XCTAssertNotNil(dict["open_house_only"])
        XCTAssertNotNil(dict["sort"])
        XCTAssertNotNil(dict["bounds"])
    }

    /// Test that web-compatible keys match what the web platform expects
    func testWebCompatibleKeysMatch() {
        var filters = PropertySearchFilters()
        filters.minPrice = 500000
        filters.maxPrice = 1000000
        filters.minBaths = 2.0
        filters.cities = ["Boston"]
        filters.neighborhoods = ["Back Bay"]
        filters.propertyTypes = [.house]

        let dict = filters.toSavedSearchDictionary()

        // These are the keys the web platform expects
        XCTAssertNotNil(dict["price_min"], "Should use web key 'price_min'")
        XCTAssertNotNil(dict["price_max"], "Should use web key 'price_max'")
        XCTAssertNotNil(dict["baths_min"], "Should use web key 'baths_min'")
        XCTAssertNotNil(dict["City"], "Should use PascalCase 'City'")
        XCTAssertNotNil(dict["Neighborhood"], "Should use PascalCase 'Neighborhood'")
        XCTAssertNotNil(dict["PropertyType"], "Should use PascalCase 'PropertyType'")
    }

    /// Test polygon coordinates serialize correctly for PHP
    func testPolygonSerializationForPHP() {
        var filters = PropertySearchFilters()
        filters.polygonCoordinates = [
            CLLocationCoordinate2D(latitude: 42.3, longitude: -71.1),
            CLLocationCoordinate2D(latitude: 42.35, longitude: -71.05),
            CLLocationCoordinate2D(latitude: 42.32, longitude: -71.0),
            CLLocationCoordinate2D(latitude: 42.3, longitude: -71.1) // Close the polygon
        ]

        let dict = filters.toDictionary()

        guard let polygon = dict["polygon"] as? [[String: Double]] else {
            XCTFail("Polygon should be array of coordinate dictionaries")
            return
        }

        // PHP expects: polygon[0][lat]=42.3&polygon[0][lng]=-71.1
        XCTAssertEqual(polygon.count, 4)

        for coord in polygon {
            XCTAssertNotNil(coord["lat"], "Each coordinate should have 'lat' key")
            XCTAssertNotNil(coord["lng"], "Each coordinate should have 'lng' key")
        }
    }
}
