import Foundation
import os.log

/// Debug logging utility that only logs in DEBUG builds
/// Replace print() calls with debugLog() to prevent logging in production
///
/// Usage:
///   debugLog("Message")           // Basic logging
///   debugLog("Value: \(value)")   // With interpolation
///
/// In production builds, these calls compile to no-ops with zero overhead
enum DebugLog {

    /// Log a debug message (only in DEBUG builds)
    /// - Parameters:
    ///   - message: The message to log
    ///   - file: Source file (auto-filled)
    ///   - function: Function name (auto-filled)
    ///   - line: Line number (auto-filled)
    static func log(_ message: @autoclosure () -> String, file: String = #file, function: String = #function, line: Int = #line) {
        #if DEBUG
        let fileName = (file as NSString).lastPathComponent
        let timestamp = ISO8601DateFormatter().string(from: Date())
        print("[\(timestamp)] [\(fileName):\(line)] \(message())")
        #endif
    }

    /// Log a debug message with a category prefix
    /// - Parameters:
    ///   - category: Category emoji/prefix (e.g., "🔔", "👤", "📱")
    ///   - message: The message to log
    static func log(_ category: String, _ message: @autoclosure () -> String) {
        #if DEBUG
        print("\(category) \(message())")
        #endif
    }
}

/// Convenience function for debug logging
/// Drop-in replacement for print() in debug contexts
///
/// Usage: debugLog("Message") instead of print("Message")
@inlinable
func debugLog(_ message: @autoclosure () -> String) {
    #if DEBUG
    print(message())
    #endif
}

/// Convenience function for categorized debug logging
/// Usage: debugLog("🔔", "Notification received")
@inlinable
func debugLog(_ category: String, _ message: @autoclosure () -> String) {
    #if DEBUG
    print("\(category) \(message())")
    #endif
}
