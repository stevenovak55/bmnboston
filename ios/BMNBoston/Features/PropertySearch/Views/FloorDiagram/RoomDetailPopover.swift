//
//  RoomDetailPopover.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Inline expandable room details with smooth animation
//

import SwiftUI

/// Room detail row that expands inline to show full details
struct RoomDetailPopover: View {
    let room: Room
    let isSelected: Bool
    let showDimensions: Bool
    let onTap: () -> Void

    private var roomName: String {
        formatRoomName(room.type ?? "Room")
    }

    private var roomCategory: RoomCategory {
        categorizeRoom(room)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Main row (always visible)
            mainRow
                .contentShape(Rectangle())
                .onTapGesture(perform: onTap)

            // Expanded detail section
            if isSelected {
                expandedDetails
                    .transition(.asymmetric(
                        insertion: .opacity.combined(with: .move(edge: .top)),
                        removal: .opacity
                    ))
            }
        }
        .animation(.spring(response: 0.25, dampingFraction: 0.8), value: isSelected)
        .background(isSelected ? AppColors.brandTeal.opacity(0.04) : Color.clear)
    }

    // MARK: - Main Row

    private var mainRow: some View {
        HStack(alignment: .top, spacing: 12) {
            // Room icon with animated background
            ZStack {
                Circle()
                    .fill(isSelected ? AppColors.brandTeal.opacity(0.2) : AppColors.brandTeal.opacity(0.1))
                    .frame(width: 32, height: 32)

                Image(systemName: roomCategory.iconName)
                    .font(.system(size: 14))
                    .foregroundStyle(AppColors.brandTeal)
            }

            // Room info
            VStack(alignment: .leading, spacing: 4) {
                HStack(alignment: .center) {
                    Text(roomName)
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundStyle(.primary)

                    Spacer()

                    // Dimensions badge (when enabled and not expanded)
                    if let dimensions = room.dimensions, !dimensions.isEmpty {
                        if showDimensions && !isSelected {
                            DimensionBadge(dimensions: dimensions)
                        }
                    }

                    // Expand/collapse indicator
                    Image(systemName: "chevron.right")
                        .font(.system(size: 10, weight: .semibold))
                        .foregroundStyle(.tertiary)
                        .rotationEffect(.degrees(isSelected ? 90 : 0))
                }

                // Features preview (when not expanded)
                if !isSelected, let features = room.features, !features.isEmpty {
                    Text(features)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
        }
        .padding(.vertical, 10)
        .padding(.horizontal, 14)
    }

    // MARK: - Expanded Details

    private var expandedDetails: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Divider
            Rectangle()
                .fill(AppColors.brandTeal.opacity(0.2))
                .frame(height: 1)
                .padding(.horizontal, 14)

            VStack(alignment: .leading, spacing: 10) {
                // Dimensions (always show when expanded)
                if let dimensions = room.dimensions, !dimensions.isEmpty {
                    RoomInfoRow(
                        icon: "ruler",
                        label: "Dimensions",
                        value: dimensions
                    )
                }

                // Full features
                if let features = room.features, !features.isEmpty {
                    RoomInfoRow(
                        icon: "list.bullet",
                        label: "Features",
                        value: features,
                        isMultiLine: true
                    )
                }

                // Description (if different from features)
                if let description = room.description, !description.isEmpty, description != room.features {
                    RoomInfoRow(
                        icon: "text.quote",
                        label: "Notes",
                        value: description,
                        isMultiLine: true,
                        isItalic: true
                    )
                }

                // Room level
                if let level = room.level, !level.isEmpty {
                    RoomInfoRow(
                        icon: "building.2",
                        label: "Level",
                        value: level
                    )
                }
            }
            .padding(.horizontal, 14)
            .padding(.bottom, 12)
        }
    }

    // MARK: - Helper Functions

    private func formatRoomName(_ name: String) -> String {
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

    private func categorizeRoom(_ room: Room) -> RoomCategory {
        guard let type = room.type?.lowercased() else { return .other }

        if type.contains("bed") || type.contains("master") || type.contains("primary") {
            return .bedroom
        }
        if type.contains("bath") || type.contains("shower") || type.contains("powder") {
            return .bathroom
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

        return .other
    }
}

// MARK: - Detail Row

private struct RoomInfoRow: View {
    let icon: String
    let label: String
    let value: String
    var isMultiLine: Bool = false
    var isItalic: Bool = false

    var body: some View {
        HStack(alignment: isMultiLine ? .top : .center, spacing: 10) {
            Image(systemName: icon)
                .font(.system(size: 11))
                .foregroundStyle(.secondary)
                .frame(width: 16)

            VStack(alignment: .leading, spacing: 2) {
                Text(label)
                    .font(.caption2)
                    .fontWeight(.semibold)
                    .foregroundStyle(.tertiary)

                if isItalic {
                    Text(value)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .italic()
                } else {
                    Text(value)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
    }
}

// MARK: - Preview

#Preview("Single Room - Collapsed") {
    VStack(spacing: 0) {
        RoomDetailPopover(
            room: Room(
                type: "Kitchen",
                level: "First",
                dimensions: "12 x 14",
                features: "Granite counters, stainless appliances, island",
                description: nil
            ),
            isSelected: false,
            showDimensions: true,
            onTap: {}
        )

        Divider()
            .padding(.leading, 56)

        RoomDetailPopover(
            room: Room(
                type: "Living Room",
                level: "First",
                dimensions: "18 x 20",
                features: "Hardwood floors, Fireplace, Bay window",
                description: "Open concept layout"
            ),
            isSelected: false,
            showDimensions: true,
            onTap: {}
        )
    }
    .background(Color(.systemBackground))
    .clipShape(RoundedRectangle(cornerRadius: 12))
    .overlay(
        RoundedRectangle(cornerRadius: 12)
            .stroke(Color.gray.opacity(0.3), lineWidth: 1)
    )
    .padding()
}

#Preview("Single Room - Expanded") {
    RoomDetailPopover(
        room: Room(
            type: "Bedroom1",
            level: "Second",
            dimensions: "15 x 14",
            features: "Walk-in Closet, Hardwood floors, Ceiling fan, Ensuite bath access",
            description: "Primary bedroom with stunning views"
        ),
        isSelected: true,
        showDimensions: true,
        onTap: {}
    )
    .background(Color(.systemBackground))
    .clipShape(RoundedRectangle(cornerRadius: 12))
    .overlay(
        RoundedRectangle(cornerRadius: 12)
            .stroke(Color.gray.opacity(0.3), lineWidth: 1)
    )
    .padding()
}
