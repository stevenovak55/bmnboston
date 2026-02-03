//
//  NotificationService.swift
//  NotificationServiceExtension
//
//  Created by Claude Code on 2026-01-08.
//

import UserNotifications

/// Notification Service Extension for rich push notifications
/// Intercepts notifications with mutable-content: 1 to download and attach property images
class NotificationService: UNNotificationServiceExtension {

    var contentHandler: ((UNNotificationContent) -> Void)?
    var bestAttemptContent: UNMutableNotificationContent?

    // MARK: - Service Extension Entry Point

    override func didReceive(
        _ request: UNNotificationRequest,
        withContentHandler contentHandler: @escaping (UNNotificationContent) -> Void
    ) {
        self.contentHandler = contentHandler
        bestAttemptContent = (request.content.mutableCopy() as? UNMutableNotificationContent)

        guard let bestAttemptContent = bestAttemptContent else {
            contentHandler(request.content)
            return
        }

        // Extract image URL from payload
        let userInfo = request.content.userInfo

        // Check for image URL in payload (property photo, agent photo, etc.)
        let imageURLString = userInfo["image_url"] as? String
            ?? userInfo["photo_url"] as? String
            ?? userInfo["thumbnail_url"] as? String

        guard let urlString = imageURLString,
              let imageURL = URL(string: urlString) else {
            // No image URL provided, deliver notification as-is
            contentHandler(bestAttemptContent)
            return
        }

        // Download and attach the image
        downloadAndAttachImage(from: imageURL, to: bestAttemptContent) { modifiedContent in
            contentHandler(modifiedContent)
        }
    }

    // MARK: - Timeout Handler

    override func serviceExtensionTimeWillExpire() {
        // Called just before the extension is terminated by the system
        // Deliver best attempt content immediately
        if let contentHandler = contentHandler,
           let bestAttemptContent = bestAttemptContent {
            // Log timeout failure for debugging (v6.49.4)
            if let imageURL = extractImageURL(from: bestAttemptContent.userInfo) {
                logImageFailure(url: imageURL, reason: "timeout")
            }
            contentHandler(bestAttemptContent)
        }
    }

    // MARK: - Failure Logging (v6.49.4)

    /// Extract image URL from notification userInfo
    private func extractImageURL(from userInfo: [AnyHashable: Any]) -> String? {
        return userInfo["image_url"] as? String
            ?? userInfo["photo_url"] as? String
            ?? userInfo["thumbnail_url"] as? String
    }

    /// Log image download failures for debugging via App Groups
    private func logImageFailure(url: String, reason: String) {
        guard let defaults = UserDefaults(suiteName: "group.com.bmnboston.app") else { return }

        // Get existing failures or create empty array
        var failures = defaults.array(forKey: "notification_image_failures") as? [[String: String]] ?? []

        // Add new failure
        let failure: [String: String] = [
            "url": url,
            "reason": reason,
            "timestamp": ISO8601DateFormatter().string(from: Date())
        ]
        failures.append(failure)

        // Keep only last 20 failures to prevent unbounded growth
        if failures.count > 20 {
            failures = Array(failures.suffix(20))
        }

        defaults.set(failures, forKey: "notification_image_failures")
    }

    // MARK: - Image Download and Attachment

    private func downloadAndAttachImage(
        from url: URL,
        to content: UNMutableNotificationContent,
        completion: @escaping (UNMutableNotificationContent) -> Void
    ) {
        let task = URLSession.shared.downloadTask(with: url) { [weak self] localURL, response, error in
            guard let self = self else {
                completion(content)
                return
            }

            // Check for download errors
            if let error = error {
                self.logImageFailure(url: url.absoluteString, reason: "network_error: \(error.localizedDescription)")
                completion(content)
                return
            }

            guard let localURL = localURL,
                  let response = response as? HTTPURLResponse else {
                self.logImageFailure(url: url.absoluteString, reason: "no_response")
                completion(content)
                return
            }

            guard response.statusCode == 200 else {
                self.logImageFailure(url: url.absoluteString, reason: "http_\(response.statusCode)")
                completion(content)
                return
            }

            // Determine file extension from response or URL
            let fileExtension = self.determineFileExtension(from: response, url: url)

            // Create a unique file name for the attachment
            // Use App Group container instead of temp directory for persistence
            let uniqueFileName = UUID().uuidString + fileExtension
            let containerURL = FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: "group.com.bmnboston.app")
            let attachmentsDir = (containerURL ?? FileManager.default.temporaryDirectory).appendingPathComponent("NotificationAttachments")

            // Create attachments directory if needed
            try? FileManager.default.createDirectory(at: attachmentsDir, withIntermediateDirectories: true)

            // Clean up old attachments (keep only last 10)
            self.cleanupOldAttachments(in: attachmentsDir, keepCount: 10)

            let destinationURL = attachmentsDir.appendingPathComponent(uniqueFileName)

            do {
                // If file exists at destination, remove it first
                if FileManager.default.fileExists(atPath: destinationURL.path) {
                    try FileManager.default.removeItem(at: destinationURL)
                }

                // Move downloaded file to our temp location
                try FileManager.default.moveItem(at: localURL, to: destinationURL)

                // Create the attachment
                let attachment = try UNNotificationAttachment(
                    identifier: "property-image",
                    url: destinationURL,
                    options: nil
                )

                content.attachments = [attachment]

            } catch {
                // Failed to create attachment, deliver without image
                self.logImageFailure(url: url.absoluteString, reason: "attachment_error: \(error.localizedDescription)")
            }

            completion(content)
        }

        task.resume()
    }

    // MARK: - Helper Methods

    /// Clean up old attachment files to prevent storage bloat
    private func cleanupOldAttachments(in directory: URL, keepCount: Int) {
        guard let files = try? FileManager.default.contentsOfDirectory(
            at: directory,
            includingPropertiesForKeys: [.creationDateKey],
            options: .skipsHiddenFiles
        ) else { return }

        // Sort by creation date (oldest first)
        let sortedFiles = files.sorted { file1, file2 in
            let date1 = (try? file1.resourceValues(forKeys: [.creationDateKey]).creationDate) ?? Date.distantPast
            let date2 = (try? file2.resourceValues(forKeys: [.creationDateKey]).creationDate) ?? Date.distantPast
            return date1 < date2
        }

        // Delete oldest files if we have too many
        if sortedFiles.count > keepCount {
            let filesToDelete = sortedFiles.prefix(sortedFiles.count - keepCount)
            for file in filesToDelete {
                try? FileManager.default.removeItem(at: file)
            }
        }
    }

    private func determineFileExtension(from response: HTTPURLResponse, url: URL) -> String {
        // Try to get extension from Content-Type header
        if let mimeType = response.mimeType {
            switch mimeType.lowercased() {
            case "image/jpeg", "image/jpg":
                return ".jpg"
            case "image/png":
                return ".png"
            case "image/gif":
                return ".gif"
            case "image/webp":
                return ".webp"
            case "image/heic":
                return ".heic"
            default:
                break
            }
        }

        // Fall back to URL path extension
        let pathExtension = url.pathExtension.lowercased()
        if !pathExtension.isEmpty {
            return "." + pathExtension
        }

        // Default to JPEG (most common for property photos)
        return ".jpg"
    }
}
