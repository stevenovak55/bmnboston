//
//  OfflineOpenHouseStore.swift
//  BMNBoston
//
//  Manages offline storage and sync for Open House attendees
//  Created for BMN Boston Real Estate
//
//  VERSION: v6.69.1
//

import Foundation
import Network
import Combine

@MainActor
class OfflineOpenHouseStore: ObservableObject {
    static let shared = OfflineOpenHouseStore()

    // MARK: - Published State

    @Published var pendingAttendees: [OpenHouseAttendee] = []
    @Published var isOnline: Bool = true
    @Published var isSyncing: Bool = false
    @Published var lastSyncDate: Date?
    @Published var syncError: String?

    // MARK: - Computed Properties

    var hasPendingSync: Bool {
        !pendingAttendees.isEmpty
    }

    var pendingSyncCount: Int {
        pendingAttendees.count
    }

    // MARK: - Private Properties

    private let userDefaultsKey = "com.bmnboston.pendingAttendees"
    private let lastSyncKey = "com.bmnboston.lastAttendeeSync"
    private let networkMonitor = NWPathMonitor()
    private let monitorQueue = DispatchQueue(label: "com.bmnboston.networkMonitor")
    private var cancellables = Set<AnyCancellable>()

    // MARK: - Initialization

    private init() {
        loadPendingAttendees()
        startNetworkMonitoring()
    }

    deinit {
        networkMonitor.cancel()
    }

    // MARK: - Public Methods

    /// Save an attendee locally for later sync
    func saveAttendeeLocally(_ attendee: OpenHouseAttendee) {
        var attendeeToSave = attendee
        attendeeToSave.syncStatus = .pending
        pendingAttendees.append(attendeeToSave)
        persistPendingAttendees()

        // Try to sync immediately if online
        if isOnline {
            Task {
                await syncPendingAttendees()
            }
        }
    }

    /// Sync all pending attendees to the server
    func syncPendingAttendees() async {
        guard !pendingAttendees.isEmpty else { return }
        guard !isSyncing else { return }
        guard isOnline else { return }

        isSyncing = true
        syncError = nil

        // Group attendees by open house ID
        let groupedByOpenHouse = Dictionary(grouping: pendingAttendees) { $0.openHouseId }

        var syncedIds: Set<UUID> = []
        var failedIds: Set<UUID> = []

        for (openHouseId, attendees) in groupedByOpenHouse {
            do {
                let response = try await OpenHouseService.shared.syncAttendees(
                    openHouseId: openHouseId,
                    attendees: attendees
                )

                // Mark successfully synced attendees using actual local_uuid from response
                for syncedResult in response.synced {
                    if let localUUIDString = syncedResult.localUUID,
                       let uuid = UUID(uuidString: localUUIDString) {
                        syncedIds.insert(uuid)
                    }
                }

                // Mark failed attendees using actual local_uuid from error response
                for errorResult in response.errors {
                    if let localUUIDString = errorResult.localUUID,
                       let uuid = UUID(uuidString: localUUIDString) {
                        failedIds.insert(uuid)
                    }
                }

                // Log any discrepancies between sent and received
                if syncedIds.count + failedIds.count < attendees.count {
                    let sentUUIDs = Set(attendees.map { $0.localUUID })
                    let receivedUUIDs = syncedIds.union(failedIds)
                    let missingUUIDs = sentUUIDs.subtracting(receivedUUIDs)
                    if !missingUUIDs.isEmpty {
                        print("OfflineOpenHouseStore: \(missingUUIDs.count) attendees not in response - marking as failed")
                        failedIds.formUnion(missingUUIDs)
                    }
                }
            } catch {
                // Mark all attendees in this batch as failed
                for attendee in attendees {
                    failedIds.insert(attendee.localUUID)
                }
                syncError = "Failed to sync some attendees: \(error.localizedDescription)"
            }
        }

        // Update pending attendees list
        pendingAttendees = pendingAttendees.compactMap { attendee in
            if syncedIds.contains(attendee.localUUID) {
                return nil // Remove synced attendees
            } else if failedIds.contains(attendee.localUUID) {
                var failed = attendee
                failed.syncStatus = .failed
                return failed
            }
            return attendee
        }

        persistPendingAttendees()
        lastSyncDate = Date()
        UserDefaults.standard.set(lastSyncDate, forKey: lastSyncKey)
        isSyncing = false
    }

    /// Retry failed attendees
    func retryFailedAttendees() async {
        // Reset failed attendees to pending status
        pendingAttendees = pendingAttendees.map { attendee in
            if attendee.syncStatus == .failed {
                var updated = attendee
                updated.syncStatus = .pending
                return updated
            }
            return attendee
        }
        persistPendingAttendees()

        await syncPendingAttendees()
    }

    /// Clear all pending attendees (use with caution)
    func clearPendingAttendees() {
        pendingAttendees = []
        persistPendingAttendees()
    }

    /// Get pending attendees for a specific open house
    func getPendingAttendees(for openHouseId: Int) -> [OpenHouseAttendee] {
        pendingAttendees.filter { $0.openHouseId == openHouseId }
    }

    // MARK: - Private Methods

    private func loadPendingAttendees() {
        guard let data = UserDefaults.standard.data(forKey: userDefaultsKey) else {
            pendingAttendees = []
            return
        }

        do {
            let decoder = JSONDecoder()
            decoder.dateDecodingStrategy = .iso8601
            pendingAttendees = try decoder.decode([OpenHouseAttendee].self, from: data)

            // Clean up old synced attendees (older than 24 hours)
            let cutoffDate = Date().addingTimeInterval(-24 * 60 * 60)
            pendingAttendees = pendingAttendees.filter { attendee in
                attendee.syncStatus != .synced || attendee.signedInAt > cutoffDate
            }
            persistPendingAttendees()
        } catch {
            print("OfflineOpenHouseStore: Failed to load pending attendees: \(error)")
            pendingAttendees = []
        }

        // Load last sync date
        lastSyncDate = UserDefaults.standard.object(forKey: lastSyncKey) as? Date
    }

    private func persistPendingAttendees() {
        do {
            let encoder = JSONEncoder()
            encoder.dateEncodingStrategy = .iso8601
            let data = try encoder.encode(pendingAttendees)
            UserDefaults.standard.set(data, forKey: userDefaultsKey)
        } catch {
            print("OfflineOpenHouseStore: Failed to persist pending attendees: \(error)")
        }
    }

    private func startNetworkMonitoring() {
        networkMonitor.pathUpdateHandler = { [weak self] path in
            Task { @MainActor in
                let wasOffline = !(self?.isOnline ?? true)
                self?.isOnline = path.status == .satisfied

                // If we just came back online and have pending attendees, sync after debounce
                if wasOffline && (self?.isOnline ?? false) && (self?.hasPendingSync ?? false) {
                    // Wait 3 seconds to avoid rapid sync attempts on flaky networks
                    try? await Task.sleep(nanoseconds: 3_000_000_000)
                    guard self?.isOnline ?? false else { return }
                    await self?.syncPendingAttendees()
                }
            }
        }
        networkMonitor.start(queue: monitorQueue)
    }
}

// MARK: - Network Status View Helper

extension OfflineOpenHouseStore {
    var networkStatusMessage: String {
        if !isOnline {
            return "Offline - Attendees will sync when connected"
        } else if isSyncing {
            return "Syncing attendees..."
        } else if hasPendingSync {
            return "\(pendingSyncCount) attendee(s) pending sync"
        } else {
            return "All attendees synced"
        }
    }

    var networkStatusColor: String {
        if !isOnline {
            return "orange"
        } else if isSyncing {
            return "blue"
        } else if hasPendingSync {
            return "yellow"
        } else {
            return "green"
        }
    }
}
