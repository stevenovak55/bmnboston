//
//  FloorSegmentView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Individual floor segment with collapsed/expanded states
//

import SwiftUI

/// Displays a single floor level with expandable room details
struct FloorSegmentView: View {
    let floorData: FloorData
    @Binding var isExpanded: Bool
    let isFirst: Bool
    let isLast: Bool
    var displayNameOverride: String? = nil  // For condos: override with actual floor level
    var badgeTextOverride: String? = nil    // For condos: override badge text (e.g., "9")

    /// The floor name to display (uses override if provided)
    private var displayName: String {
        displayNameOverride ?? floorData.level.displayName
    }

    var body: some View {
        VStack(spacing: 0) {
            // Collapsed header (always visible)
            collapsedHeader
                .contentShape(Rectangle())
                .onTapGesture {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        isExpanded.toggle()
                    }
                    HapticManager.impact(.light)
                }

            // Expanded content (rooms list)
            if isExpanded {
                expandedContent
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .background(backgroundColor)
    }

    // MARK: - Collapsed Header

    private var collapsedHeader: some View {
        HStack(spacing: 10) {
            // Floor level badge
            FloorLevelBadge(level: floorData.level, textOverride: badgeTextOverride)

            // Floor name and summary
            VStack(alignment: .leading, spacing: 2) {
                Text(displayName)
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)
                    .lineLimit(1)
                    .minimumScaleFactor(0.8)

                // Room summary on second line when collapsed
                if !isExpanded {
                    Text(floorData.roomSummary)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            .layoutPriority(1)

            Spacer()

            // Room type icons (collapsed only)
            if !isExpanded {
                roomIconsRow
            }

            // Expand/collapse indicator
            ExpandCollapseIndicator(isExpanded: isExpanded)
                .padding(.leading, 4)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
    }

    // MARK: - Room Icons Row

    private var roomIconsRow: some View {
        HStack(spacing: 5) {
            ForEach(floorData.roomTypeIcons.prefix(4), id: \.type) { item in
                HStack(spacing: 2) {
                    RoomTypeIcon(category: item.type, size: 13)
                    if item.count > 1 {
                        Text("\(item.count)")
                            .font(.system(size: 10, weight: .medium))
                            .foregroundStyle(.tertiary)
                    }
                }
            }
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(Color.gray.opacity(0.08))
        .clipShape(Capsule())
    }

    // MARK: - Expanded Content

    private var expandedContent: some View {
        VStack(spacing: 0) {
            // Divider with accent color when expanded
            Rectangle()
                .fill(FloorDiagramColors.accentBorderColor)
                .frame(height: 1)
                .padding(.horizontal, 14)

            ForEach(Array(floorData.rooms.enumerated()), id: \.element.id) { index, room in
                RoomDetailRow(room: room)

                // Divider between rooms (not after last)
                if index < floorData.rooms.count - 1 {
                    Rectangle()
                        .fill(Color.gray.opacity(0.15))
                        .frame(height: 1)
                        .padding(.leading, 58) // Align with room text after icon
                }
            }
        }
        .background(FloorDiagramColors.expandedBackground)
    }

    // MARK: - Styling Helpers

    private var backgroundColor: Color {
        if isExpanded {
            return FloorDiagramColors.expandedBackground
        }
        return FloorDiagramColors.backgroundColor(for: floorData.level)
    }
}

// MARK: - Preview

#Preview("Collapsed States") {
    VStack(spacing: 0) {
        FloorSegmentView(
            floorData: FloorData(
                level: .second,
                rooms: [
                    Room(type: "Bedroom1", level: "Second", dimensions: "15 x 12", features: "Hardwood", description: nil),
                    Room(type: "Bedroom2", level: "Second", dimensions: "12 x 10", features: "Carpet", description: nil),
                    Room(type: "Bathroom1", level: "Second", dimensions: "8 x 6", features: "Full Bath", description: nil)
                ]
            ),
            isExpanded: .constant(false),
            isFirst: true,
            isLast: false
        )

        Rectangle()
            .fill(Color.gray.opacity(0.3))
            .frame(height: 1)

        FloorSegmentView(
            floorData: FloorData(
                level: .first,
                rooms: [
                    Room(type: "Kitchen", level: "First", dimensions: "12 x 14", features: "Granite", description: nil),
                    Room(type: "Living Room", level: "First", dimensions: "18 x 20", features: "Fireplace", description: nil)
                ]
            ),
            isExpanded: .constant(false),
            isFirst: false,
            isLast: true
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

#Preview("Expanded State") {
    VStack(spacing: 0) {
        FloorSegmentView(
            floorData: FloorData(
                level: .first,
                rooms: [
                    Room(type: "Kitchen", level: "First", dimensions: "12 x 14", features: "Granite counters, stainless appliances", description: nil),
                    Room(type: "Living Room", level: "First", dimensions: "18 x 20", features: "Hardwood, Fireplace", description: "Open concept layout"),
                    Room(type: "Dining Room", level: "First", dimensions: "12 x 12", features: "Hardwood floors", description: nil),
                    Room(type: "Bathroom1", level: "First", dimensions: "6 x 5", features: "Half Bath", description: nil)
                ]
            ),
            isExpanded: .constant(true),
            isFirst: true,
            isLast: true
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
