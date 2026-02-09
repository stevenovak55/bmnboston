//
//  AppointmentBadgeStore.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Badge count manager for Appointments tab
//

import SwiftUI

@MainActor
class AppointmentBadgeStore: ObservableObject {
    static let shared = AppointmentBadgeStore()

    @Published var upcomingCount: Int = 0

    private init() {}
}
