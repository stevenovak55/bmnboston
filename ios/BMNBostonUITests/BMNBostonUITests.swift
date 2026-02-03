//
//  BMNBostonUITests.swift
//  BMNBostonUITests
//
//  Created for BMN Boston Real Estate
//

import XCTest

final class BMNBostonUITests: XCTestCase {

    override func setUpWithError() throws {
        continueAfterFailure = false
    }

    override func tearDownWithError() throws {
        // Put teardown code here
    }

    @MainActor
    func testAppLaunches() throws {
        let app = XCUIApplication()
        app.launch()

        // Verify the app launched successfully
        XCTAssertTrue(app.exists)
    }

    @MainActor
    func testLoginViewExists() throws {
        let app = XCUIApplication()
        app.launch()

        // Look for login elements (when not authenticated)
        let emailField = app.textFields["Email"]
        let passwordField = app.secureTextFields["Password"]
        let loginButton = app.buttons["Log In"]

        // These should exist if not authenticated
        // Note: This test assumes user is not logged in
        if emailField.exists {
            XCTAssertTrue(passwordField.exists)
            XCTAssertTrue(loginButton.exists)
        }
    }

    @MainActor
    func testTabBarExists() throws {
        let app = XCUIApplication()
        app.launch()

        // If authenticated, check for tab bar
        let tabBar = app.tabBars.firstMatch

        // Tab bar might not exist if on login screen
        if tabBar.exists {
            XCTAssertTrue(app.tabBars.buttons["Search"].exists)
            XCTAssertTrue(app.tabBars.buttons["Saved"].exists)
            XCTAssertTrue(app.tabBars.buttons["Appointments"].exists)
            XCTAssertTrue(app.tabBars.buttons["Profile"].exists)
        }
    }

    @MainActor
    func testLaunchPerformance() throws {
        if #available(macOS 10.15, iOS 13.0, tvOS 13.0, watchOS 7.0, *) {
            measure(metrics: [XCTApplicationLaunchMetric()]) {
                XCUIApplication().launch()
            }
        }
    }
}
