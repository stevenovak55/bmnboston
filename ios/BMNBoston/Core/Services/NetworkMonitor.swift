//
//  NetworkMonitor.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Monitors network connectivity and provides offline detection
//

import Foundation
import Network

/// Monitors network connectivity status for offline detection
@MainActor
class NetworkMonitor: ObservableObject {
    static let shared = NetworkMonitor()

    /// Current connection status
    @Published var isConnected: Bool = true

    /// Connection type (wifi, cellular, wired, etc.)
    @Published var connectionType: NWInterface.InterfaceType?

    /// Whether we're on an expensive connection (cellular)
    @Published var isExpensive: Bool = false

    /// Whether the connection is constrained (low data mode)
    @Published var isConstrained: Bool = false

    private let monitor: NWPathMonitor
    private let queue = DispatchQueue(label: "com.bmnboston.networkmonitor")

    private init() {
        monitor = NWPathMonitor()
        startMonitoring()
    }

    deinit {
        monitor.cancel()
    }

    /// Start monitoring network changes
    func startMonitoring() {
        monitor.pathUpdateHandler = { [weak self] path in
            Task { @MainActor in
                self?.updateStatus(from: path)
            }
        }
        monitor.start(queue: queue)
    }

    /// Stop monitoring network changes
    nonisolated func stopMonitoring() {
        monitor.cancel()
    }

    private func updateStatus(from path: NWPath) {
        let wasConnected = isConnected

        isConnected = path.status == .satisfied
        isExpensive = path.isExpensive
        isConstrained = path.isConstrained

        // Determine connection type
        if path.usesInterfaceType(.wifi) {
            connectionType = .wifi
        } else if path.usesInterfaceType(.cellular) {
            connectionType = .cellular
        } else if path.usesInterfaceType(.wiredEthernet) {
            connectionType = .wiredEthernet
        } else {
            connectionType = nil
        }

        // Log connectivity changes
        if wasConnected != isConnected {
            #if DEBUG
            print("[NetworkMonitor] Connection status changed: \(isConnected ? "Online" : "Offline")")
            #endif
        }
    }

    /// Human-readable connection status
    var statusDescription: String {
        guard isConnected else { return "Offline" }

        switch connectionType {
        case .wifi:
            return "Wi-Fi"
        case .cellular:
            return isExpensive ? "Cellular (Metered)" : "Cellular"
        case .wiredEthernet:
            return "Ethernet"
        default:
            return "Connected"
        }
    }
}
