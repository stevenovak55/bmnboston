//
//  RoomDetailRow.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Room information display for expanded floor segments
//

import SwiftUI

/// Displays detailed information about a single room
struct RoomDetailRow: View {
    let room: Room

    private var roomName: String {
        formatRoomName(room.type ?? "Room")
    }

    private var roomCategory: RoomCategory {
        categorizeRoom(room)
    }

    /// Whether this is a special room (in-law, bonus room, etc. from interior_features)
    private var isSpecialRoom: Bool {
        room.isSpecial == true
    }

    /// Whether the room's level was inferred from context, not MLS data
    private var hasInferredLevel: Bool {
        room.levelInferred == true
    }

    /// Icon color based on room type
    private var iconColor: Color {
        isSpecialRoom ? .purple : AppColors.brandTeal
    }

    /// Icon background color based on room type
    private var iconBackgroundColor: Color {
        isSpecialRoom ? Color.purple.opacity(0.1) : AppColors.brandTeal.opacity(0.1)
    }

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            // Room icon with subtle background
            ZStack {
                Circle()
                    .fill(iconBackgroundColor)
                    .frame(width: 32, height: 32)

                Image(systemName: specialRoomIcon ?? roomCategory.iconName)
                    .font(.system(size: 14))
                    .foregroundStyle(iconColor)
            }

            // Room info
            VStack(alignment: .leading, spacing: 4) {
                // Room name and dimensions row
                HStack(alignment: .center) {
                    HStack(spacing: 6) {
                        Text(roomName)
                            .font(.subheadline)
                            .fontWeight(.medium)
                            .foregroundStyle(.primary)

                        // Special room badge
                        if isSpecialRoom {
                            Text("Special")
                                .font(.caption2)
                                .padding(.horizontal, 6)
                                .padding(.vertical, 2)
                                .background(Color.purple.opacity(0.15))
                                .foregroundStyle(.purple)
                                .clipShape(RoundedRectangle(cornerRadius: 4))
                        }

                        // Inferred level indicator
                        if hasInferredLevel {
                            Image(systemName: "arrow.triangle.branch")
                                .font(.system(size: 10))
                                .foregroundStyle(.secondary)
                                .help("Floor level inferred from property data")
                        }
                    }

                    Spacer()

                    // Dimensions badge
                    if let dimensions = room.dimensions, !dimensions.isEmpty {
                        DimensionBadge(dimensions: dimensions)
                    }
                }

                // Features (if any)
                if let features = room.features, !features.isEmpty {
                    Text(features)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(2)
                }

                // Description (if any and different from features)
                if let description = room.description, !description.isEmpty,
                   description != room.features {
                    Text(description)
                        .font(.caption)
                        .foregroundStyle(.tertiary)
                        .italic()
                }
            }
        }
        .padding(.vertical, 10)
        .padding(.horizontal, 14)
    }

    /// Returns a special icon for certain room types
    private var specialRoomIcon: String? {
        guard isSpecialRoom, let type = room.type?.lowercased() else { return nil }

        if type.contains("in-law") || type.contains("inlaw") { return "person.2.fill" }
        if type.contains("bonus") { return "plus.rectangle.fill" }
        if type.contains("media") || type.contains("game") { return "tv.fill" }
        if type.contains("exercise") || type.contains("gym") { return "figure.run" }
        if type.contains("sun") { return "sun.max.fill" }
        if type.contains("mud") { return "boot.fill" }
        if type.contains("au pair") { return "person.2.fill" }

        return nil
    }

    // MARK: - Helper Functions

    /// Formats room name for display (e.g., "Bedroom1" -> "Bedroom 1")
    private func formatRoomName(_ name: String) -> String {
        // Insert space before trailing numbers
        var result = ""
        var lastWasLetter = false

        for char in name {
            if char.isNumber && lastWasLetter {
                result += " "
            }
            result += String(char)
            lastWasLetter = char.isLetter
        }

        return result
    }

    /// Categorizes a room by its type string
    private func categorizeRoom(_ room: Room) -> RoomCategory {
        // v6.68.19: Check for special room flag first
        if room.isSpecial == true {
            return .special
        }

        guard let type = room.type?.lowercased() else { return .other }

        // v6.68.19: Check bathroom FIRST to avoid "Master Bathroom" being categorized as bedroom
        if type.contains("bath") || type.contains("shower") || type.contains("powder") {
            return .bathroom
        }
        // v6.68.20: Added "suite" for "Primary Suite", "Master Suite" room types
        if type.contains("bed") || type.contains("master") || type.contains("primary") || type.contains("suite") {
            return .bedroom
        }
        if type.contains("kitchen") {
            return .kitchen
        }
        if type.contains("living") || type.contains("family") || type.contains("great") {
            return .livingRoom
        }
        if type.contains("dining") {
            return .diningRoom
        }
        if type.contains("office") || type.contains("study") || type.contains("den") {
            return .office
        }
        if type.contains("garage") {
            return .garage
        }
        if type.contains("laundry") || type.contains("utility") || type.contains("mud") {
            return .utility
        }
        if type.contains("basement") || type.contains("storage") {
            return .storage
        }

        // Check for special room types by name even if isSpecial flag is not set
        if type.contains("bonus") || type.contains("in-law") || type.contains("inlaw") ||
           type.contains("au pair") || type.contains("media") || type.contains("game") ||
           type.contains("exercise") || type.contains("gym") || type.contains("sun room") {
            return .special
        }

        return .other
    }
}

// MARK: - Preview

#Preview {
    VStack(spacing: 0) {
        RoomDetailRow(room: Room(
            type: "Kitchen",
            level: "First",
            dimensions: "12 x 14",
            features: "Flooring - Tile, Upgraded Cabinets, Granite Counters",
            description: nil
        ))

        Divider()
            .padding(.leading, 56)

        RoomDetailRow(room: Room(
            type: "Bedroom1",
            level: "Second",
            dimensions: "15 x 12",
            features: "Hardwood, Walk-in Closet",
            description: "Primary bedroom with ensuite"
        ))

        Divider()
            .padding(.leading, 56)

        RoomDetailRow(room: Room(
            type: "Bathroom1",
            level: "Second",
            dimensions: "8 x 6",
            features: "Full Bath, Tile",
            description: nil
        ))

        Divider()
            .padding(.leading, 56)

        RoomDetailRow(room: Room(
            type: "Living Room",
            level: "First",
            dimensions: "18 x 20",
            features: "Hardwood floors, Fireplace, Bay window",
            description: "Open concept layout"
        ))
    }
    .background(AppColors.secondaryBackground)
    .clipShape(RoundedRectangle(cornerRadius: 12))
    .padding()
}
