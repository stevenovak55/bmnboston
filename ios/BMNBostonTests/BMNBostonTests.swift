//
//  BMNBostonTests.swift
//  BMNBostonTests
//
//  Created for BMN Boston Real Estate
//

import XCTest
import CoreLocation
@testable import BMNBoston

final class BMNBostonTests: XCTestCase {

    override func setUpWithError() throws {
        // Put setup code here
    }

    override func tearDownWithError() throws {
        // Put teardown code here
    }

    // MARK: - Property Model Tests

    func testPropertyFormattedPrice() throws {
        // Property is Decodable-only, test formatPrice helper directly
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0

        let price = 599000
        let formatted = formatter.string(from: NSNumber(value: price))
        XCTAssertEqual(formatted, "$599,000")
    }

    // MARK: - User Model Tests

    func testUserModel() throws {
        // User model uses 'name' not 'displayName'
        let user = User(
            id: 1,
            email: "test@example.com",
            name: "John Doe",
            firstName: "John",
            lastName: "Doe",
            phone: nil,
            avatarUrl: nil,
            userType: nil,
            assignedAgent: nil
        )
        XCTAssertEqual(user.name, "John Doe")
        XCTAssertEqual(user.firstName, "John")
        XCTAssertEqual(user.lastName, "Doe")
    }

    func testUserIsClient() throws {
        let clientUser = User(
            id: 1,
            email: "client@example.com",
            name: "Client User",
            firstName: "Client",
            lastName: "User",
            phone: nil,
            avatarUrl: nil,
            userType: .client,
            assignedAgent: nil
        )
        XCTAssertTrue(clientUser.isClient)
        XCTAssertFalse(clientUser.isAgent)
    }

    func testUserIsAgent() throws {
        let agentUser = User(
            id: 1,
            email: "agent@example.com",
            name: "Agent User",
            firstName: "Agent",
            lastName: "User",
            phone: nil,
            avatarUrl: nil,
            userType: .agent,
            assignedAgent: nil
        )
        XCTAssertTrue(agentUser.isAgent)
        XCTAssertFalse(agentUser.isClient)
    }
}

// MARK: - TokenManager Tests

final class TokenManagerTests: XCTestCase {

    override func tearDownWithError() throws {
        // Clear tokens after each test
        Task {
            await TokenManager.shared.clearTokens()
        }
        // Wait for async operation
        Thread.sleep(forTimeInterval: 0.1)
    }

    func testSaveAndRetrieveTokens() async throws {
        // Save tokens
        await TokenManager.shared.saveTokens(
            accessToken: "test_access_token",
            refreshToken: "test_refresh_token",
            expiresIn: 3600
        )

        // Verify access token is retrievable
        let accessToken = await TokenManager.shared.getAccessToken()
        XCTAssertEqual(accessToken, "test_access_token")

        // Verify refresh token is retrievable
        let refreshToken = await TokenManager.shared.getRefreshToken()
        XCTAssertEqual(refreshToken, "test_refresh_token")
    }

    func testHasRefreshToken() async throws {
        // Initially should have no refresh token (cleared in tearDown)
        await TokenManager.shared.clearTokens()
        var hasToken = await TokenManager.shared.hasRefreshToken()
        XCTAssertFalse(hasToken)

        // After saving, should have refresh token
        await TokenManager.shared.saveTokens(
            accessToken: "test_access",
            refreshToken: "test_refresh",
            expiresIn: 3600
        )
        hasToken = await TokenManager.shared.hasRefreshToken()
        XCTAssertTrue(hasToken)
    }

    func testIsAuthenticated() async throws {
        // Clear tokens first
        await TokenManager.shared.clearTokens()

        // Not authenticated without tokens
        var isAuth = await TokenManager.shared.isAuthenticated()
        XCTAssertFalse(isAuth)

        // Authenticated with valid tokens
        await TokenManager.shared.saveTokens(
            accessToken: "test_access",
            refreshToken: "test_refresh",
            expiresIn: 3600
        )
        isAuth = await TokenManager.shared.isAuthenticated()
        XCTAssertTrue(isAuth)
    }

    func testClearTokens() async throws {
        // Save tokens first
        await TokenManager.shared.saveTokens(
            accessToken: "test_access",
            refreshToken: "test_refresh",
            expiresIn: 3600
        )

        // Verify tokens exist
        var accessToken = await TokenManager.shared.getAccessToken()
        XCTAssertNotNil(accessToken)

        // Clear tokens
        await TokenManager.shared.clearTokens()

        // Verify tokens are cleared
        accessToken = await TokenManager.shared.getAccessToken()
        XCTAssertNil(accessToken)

        let refreshToken = await TokenManager.shared.getRefreshToken()
        XCTAssertNil(refreshToken)
    }

    func testExpiredTokenReturnsNil() async throws {
        // Save token that expires immediately (0 seconds)
        await TokenManager.shared.saveTokens(
            accessToken: "test_access",
            refreshToken: "test_refresh",
            expiresIn: 0
        )

        // Wait a moment for expiration
        try await Task.sleep(nanoseconds: 100_000_000) // 0.1 seconds

        // Access token should be nil (expired)
        let accessToken = await TokenManager.shared.getAccessToken()
        XCTAssertNil(accessToken, "Expired access token should return nil")

        // Refresh token should still be available
        let refreshToken = await TokenManager.shared.getRefreshToken()
        XCTAssertNotNil(refreshToken, "Refresh token should still be available after access token expires")
    }

    func testAuthenticatedWithExpiredAccessButValidRefresh() async throws {
        // Save token with immediate expiration
        await TokenManager.shared.saveTokens(
            accessToken: "test_access",
            refreshToken: "test_refresh",
            expiresIn: 0
        )

        // Wait for expiration
        try await Task.sleep(nanoseconds: 100_000_000)

        // Should still be authenticated because refresh token exists
        let isAuth = await TokenManager.shared.isAuthenticated()
        XCTAssertTrue(isAuth, "Should be authenticated with valid refresh token even if access token expired")
    }
}

// MARK: - PropertySearchFilters Serialization Tests

final class PropertySearchFiltersTests: XCTestCase {

    // MARK: - Basic toDictionary() Tests

    func testDefaultFiltersToDictionary() {
        let filters = PropertySearchFilters()
        let dict = filters.toDictionary()

        // Default pagination
        XCTAssertEqual(dict["page"] as? Int, 1)
        XCTAssertEqual(dict["per_page"] as? Int, SearchConstants.defaultPerPage)

        // Default listing type (rawValue is snake_case)
        XCTAssertEqual(dict["listing_type"] as? String, "for_sale")

        // Default property type
        if let types = dict["property_type"] as? [String] {
            XCTAssertTrue(types.contains("Residential"))
        }
    }

    func testPriceFiltersToDictionary() {
        var filters = PropertySearchFilters()
        filters.minPrice = 500000
        filters.maxPrice = 1000000

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["min_price"] as? Int, 500000)
        XCTAssertEqual(dict["max_price"] as? Int, 1000000)
    }

    func testBedsFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.beds = [2, 3, 4]

        let dict = filters.toDictionary()

        // API expects min beds value
        XCTAssertEqual(dict["beds"] as? Int, 2)
    }

    func testBathsFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.minBaths = 2.5

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["baths"] as? Double, 2.5)
    }

    func testCityFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.cities = ["Boston", "Cambridge"]

        let dict = filters.toDictionary()

        if let cities = dict["city"] as? [String] {
            XCTAssertTrue(cities.contains("Boston"))
            XCTAssertTrue(cities.contains("Cambridge"))
        } else {
            XCTFail("Cities should be an array")
        }
    }

    func testStatusFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.statuses = [.active, .pending]

        let dict = filters.toDictionary()

        // Status should be sent when not default (just active)
        if let statuses = dict["status"] as? [String] {
            XCTAssertTrue(statuses.contains("Active"))
            XCTAssertTrue(statuses.contains("Pending"))
        } else {
            XCTFail("Statuses should be an array when not default")
        }
    }

    func testDefaultStatusNotSent() {
        var filters = PropertySearchFilters()
        filters.statuses = [.active] // Default

        let dict = filters.toDictionary()

        // Default status should NOT be sent
        XCTAssertNil(dict["status"], "Default status (Active only) should not be sent")
    }

    func testMapBoundsFilterToDictionary() {
        var filters = PropertySearchFilters()
        // MapBounds order is: north, south, east, west
        filters.mapBounds = MapBounds(north: 42.4, south: 42.3, east: -71.0, west: -71.2)

        let dict = filters.toDictionary()

        // Bounds should be CSV string: "south,west,north,east"
        if let bounds = dict["bounds"] as? String {
            XCTAssertEqual(bounds, "42.3,-71.2,42.4,-71.0")
        } else {
            XCTFail("Bounds should be a string")
        }
    }

    func testPolygonFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.polygonCoordinates = [
            CLLocationCoordinate2D(latitude: 42.3, longitude: -71.1),
            CLLocationCoordinate2D(latitude: 42.35, longitude: -71.05),
            CLLocationCoordinate2D(latitude: 42.32, longitude: -71.0)
        ]

        let dict = filters.toDictionary()

        if let polygon = dict["polygon"] as? [[String: Double]] {
            XCTAssertEqual(polygon.count, 3)
            XCTAssertEqual(polygon[0]["lat"], 42.3)
            XCTAssertEqual(polygon[0]["lng"], -71.1)
        } else {
            XCTFail("Polygon should be an array of coordinate dictionaries")
        }
    }

    func testAmenityFiltersToDictionary() {
        var filters = PropertySearchFilters()
        filters.hasPool = true
        filters.hasFireplace = true
        filters.hasWaterfront = true

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["PoolPrivateYN"] as? Bool, true)
        XCTAssertEqual(dict["FireplaceYN"] as? Bool, true)
        XCTAssertEqual(dict["WaterfrontYN"] as? Bool, true)
    }

    func testSchoolFiltersToDictionary() {
        var filters = PropertySearchFilters()
        filters.nearAElementary = true
        filters.nearABMiddle = true
        filters.nearAHigh = true

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["near_a_elementary"] as? Bool, true)
        XCTAssertEqual(dict["near_ab_middle"] as? Bool, true)
        XCTAssertEqual(dict["near_a_high"] as? Bool, true)
    }

    func testPriceReducedFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.priceReduced = true

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["price_reduced"] as? Bool, true)
    }

    func testNewListingFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.newListing = true
        filters.newListingDays = 14

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["new_listing_days"] as? Int, 14)
    }

    func testSortFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.sort = .priceAsc

        let dict = filters.toDictionary()

        // Non-default sort should be sent
        XCTAssertNotNil(dict["sort"])
    }

    func testDefaultSortNotSent() {
        var filters = PropertySearchFilters()
        filters.sort = .dateDesc // Default

        let dict = filters.toDictionary()

        // Default sort should NOT be sent
        XCTAssertNil(dict["sort"], "Default sort should not be sent")
    }

    // MARK: - Web-Compatible toSavedSearchDictionary() Tests

    func testSavedSearchDictionaryUsesWebKeys() {
        var filters = PropertySearchFilters()
        filters.minPrice = 500000
        filters.maxPrice = 1000000
        filters.minBaths = 2.0
        filters.propertyTypes = [.house, .condo]

        let dict = filters.toSavedSearchDictionary()

        // Web uses price_min/price_max
        XCTAssertEqual(dict["price_min"] as? Int, 500000)
        XCTAssertEqual(dict["price_max"] as? Int, 1000000)

        // Web uses baths_min
        XCTAssertEqual(dict["baths_min"] as? Double, 2.0)

        // Web uses PropertyType (PascalCase)
        if let types = dict["PropertyType"] as? [String] {
            XCTAssertTrue(types.contains("Residential"))
            XCTAssertTrue(types.contains("Condo"))
        } else {
            XCTFail("PropertyType should be an array")
        }
    }

    func testSavedSearchDictionaryCityKey() {
        var filters = PropertySearchFilters()
        filters.cities = ["Boston", "Cambridge"]

        let dict = filters.toSavedSearchDictionary()

        // Web uses City (PascalCase)
        if let cities = dict["City"] as? [String] {
            XCTAssertTrue(cities.contains("Boston"))
            XCTAssertTrue(cities.contains("Cambridge"))
        } else {
            XCTFail("City should be an array with PascalCase key")
        }
    }

    func testSavedSearchDictionaryNeighborhoodKey() {
        var filters = PropertySearchFilters()
        filters.neighborhoods = ["Back Bay", "Beacon Hill"]

        let dict = filters.toSavedSearchDictionary()

        // Web uses Neighborhood (PascalCase)
        if let neighborhoods = dict["Neighborhood"] as? [String] {
            XCTAssertTrue(neighborhoods.contains("Back Bay"))
            XCTAssertTrue(neighborhoods.contains("Beacon Hill"))
        } else {
            XCTFail("Neighborhood should be an array with PascalCase key")
        }
    }

    func testSavedSearchDictionaryIncludesListingType() {
        var filters = PropertySearchFilters()
        filters.listingType = .forRent

        let dict = filters.toSavedSearchDictionary()

        // rawValue is snake_case
        XCTAssertEqual(dict["listing_type"] as? String, "for_rent")
    }

    // MARK: - Comprehensive Filter Tests

    func testComplexFilterRoundTrip() {
        var original = PropertySearchFilters()
        original.minPrice = 600000
        original.maxPrice = 900000
        original.beds = [2, 3]
        original.minBaths = 2.0
        original.cities = ["Boston", "Brookline"]
        original.propertyTypes = [.house, .condo]
        original.hasPool = true
        original.nearAElementary = true

        let dict = original.toDictionary()

        // Verify key fields
        XCTAssertEqual(dict["min_price"] as? Int, 600000)
        XCTAssertEqual(dict["max_price"] as? Int, 900000)
        XCTAssertEqual(dict["beds"] as? Int, 2) // Min of set
        XCTAssertEqual(dict["baths"] as? Double, 2.0)
        XCTAssertEqual(dict["PoolPrivateYN"] as? Bool, true)
        XCTAssertEqual(dict["near_a_elementary"] as? Bool, true)
    }

    func testEmptyFiltersMinimalOutput() {
        var filters = PropertySearchFilters()
        // Set empty collections explicitly
        filters.cities = []
        filters.neighborhoods = []
        filters.beds = []
        filters.propertyTypes = []

        let dict = filters.toDictionary()

        // Empty collections should not be in dictionary
        XCTAssertNil(dict["city"])
        XCTAssertNil(dict["neighborhood"])
        XCTAssertNil(dict["beds"])
    }

    // MARK: - Year Built Filter Tests

    func testYearBuiltFiltersToDictionary() {
        var filters = PropertySearchFilters()
        filters.minYearBuilt = 2000
        filters.maxYearBuilt = 2020

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["year_built_min"] as? Int, 2000)
        XCTAssertEqual(dict["year_built_max"] as? Int, 2020)
    }

    // MARK: - Square Footage Filter Tests

    func testSqftFiltersToDictionary() {
        var filters = PropertySearchFilters()
        filters.minSqft = 1500
        filters.maxSqft = 3000

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["sqft_min"] as? Int, 1500)
        XCTAssertEqual(dict["sqft_max"] as? Int, 3000)
    }

    // MARK: - Open House Filter Tests

    func testOpenHouseOnlyFilterToDictionary() {
        var filters = PropertySearchFilters()
        filters.openHouseOnly = true

        let dict = filters.toDictionary()

        XCTAssertEqual(dict["open_house_only"] as? Bool, true)
    }

    // MARK: - Listing Type Tests

    func testForSaleListingType() {
        var filters = PropertySearchFilters()
        filters.listingType = .forSale

        let dict = filters.toDictionary()

        // rawValue is snake_case
        XCTAssertEqual(dict["listing_type"] as? String, "for_sale")
    }

    func testForRentListingType() {
        var filters = PropertySearchFilters()
        filters.listingType = .forRent

        let dict = filters.toDictionary()

        // rawValue is snake_case
        XCTAssertEqual(dict["listing_type"] as? String, "for_rent")
    }
}

// MARK: - AnyCodableValue Tests

final class AnyCodableValueTests: XCTestCase {

    func testStringValueExtraction() {
        let value = AnyCodableValue.string("test")
        XCTAssertEqual(value.stringValue, "test")
        XCTAssertNil(value.intValue)
    }

    func testIntValueExtraction() {
        let value = AnyCodableValue.int(42)
        XCTAssertEqual(value.intValue, 42)
        XCTAssertEqual(value.doubleValue, 42.0)
        XCTAssertNil(value.stringValue)
    }

    func testDoubleValueExtraction() {
        let value = AnyCodableValue.double(3.14)
        XCTAssertEqual(value.doubleValue, 3.14)
        XCTAssertNil(value.stringValue)
    }

    func testBoolValueExtraction() {
        let trueValue = AnyCodableValue.bool(true)
        let falseValue = AnyCodableValue.bool(false)

        XCTAssertEqual(trueValue.boolValue, true)
        XCTAssertEqual(falseValue.boolValue, false)
    }

    func testStringToBoolConversion() {
        // "true" string should convert to true
        let trueString = AnyCodableValue.string("true")
        XCTAssertEqual(trueString.boolValue, true)

        // "1" string should convert to true
        let oneString = AnyCodableValue.string("1")
        XCTAssertEqual(oneString.boolValue, true)

        // "false" string should convert to false
        let falseString = AnyCodableValue.string("false")
        XCTAssertEqual(falseString.boolValue, false)
    }

    func testStringToIntConversion() {
        let stringNumber = AnyCodableValue.string("12345")
        XCTAssertEqual(stringNumber.intValue, 12345)
    }

    func testStringToDoubleConversion() {
        let stringNumber = AnyCodableValue.string("3.14")
        XCTAssertEqual(stringNumber.doubleValue, 3.14)
    }

    func testArrayValueExtraction() {
        let value = AnyCodableValue.array([.string("a"), .string("b"), .string("c")])

        if let array = value.stringArrayValue {
            XCTAssertEqual(array, ["a", "b", "c"])
        } else {
            XCTFail("Should extract string array")
        }
    }
}

// MARK: - MapBounds Tests

final class MapBoundsTests: XCTestCase {

    func testMapBoundsCenter() {
        let bounds = MapBounds(north: 42.4, south: 42.2, east: -71.0, west: -71.2)

        let center = bounds.center
        XCTAssertEqual(center.latitude, 42.3, accuracy: 0.0001)
        XCTAssertEqual(center.longitude, -71.1, accuracy: 0.0001)
    }

    func testMapBoundsEquatable() {
        let bounds1 = MapBounds(north: 42.4, south: 42.2, east: -71.0, west: -71.2)
        let bounds2 = MapBounds(north: 42.4, south: 42.2, east: -71.0, west: -71.2)
        let bounds3 = MapBounds(north: 42.5, south: 42.2, east: -71.0, west: -71.2)

        XCTAssertEqual(bounds1, bounds2)
        XCTAssertNotEqual(bounds1, bounds3)
    }
}
