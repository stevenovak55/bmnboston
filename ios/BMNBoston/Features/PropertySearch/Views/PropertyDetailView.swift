//
//  PropertyDetailView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Bottom sheet design inspired by Zillow/Redfin
//
//  VERSION HISTORY (Critical features - do not remove without understanding):
//  - v212: Share URL uses mlsNumber instead of listing_key (line ~1956)
//  - v232: Collapsible section state management refactored
//  - v280: Open house calendar integration (Apple Calendar + Google Calendar)
//  - v281: Marketing version bump to 1.1 for App Store
//  - v305: Initial stack overflow fix by extracting sections to View structs
//  - v306: Complete fix - extract CollapsedContentView, ExpandedContentView, ActionButtonsView
//          to fully break the deep generic type hierarchy causing stack overflow
//
//  BACKUP: Before modifying this file, ensure backup exists in ios/backups/
//

import SwiftUI
import MapKit
import EventKit

// MARK: - Property Type Category (v302)
// Detects property category from propertyType and propertySubtype strings
// Used for smart defaults and section visibility

enum PropertyTypeCategory {
    case singleFamily
    case condoTownhouse
    case rental
    case multiFamilyInvestment
    case land
    case commercial

    /// Initialize from property type and subtype strings
    static func from(propertyType: String?, propertySubtype: String?) -> PropertyTypeCategory {
        let type = propertyType?.lowercased() ?? ""
        let subtype = propertySubtype?.lowercased() ?? ""

        // Check for rental first (contains "lease")
        if type.contains("lease") {
            return .rental
        }

        // Check for multi-family investment
        if type.contains("residential income") || type.contains("multi") {
            return .multiFamilyInvestment
        }

        // Check for land
        if type.contains("land") {
            return .land
        }

        // Check for commercial
        if type.contains("commercial") {
            return .commercial
        }

        // Check for condo/townhouse based on subtype
        let condoSubtypes = ["condominium", "condo", "townhouse", "cooperative", "coop"]
        if condoSubtypes.contains(where: { subtype.contains($0) }) {
            return .condoTownhouse
        }

        // Default to single family
        return .singleFamily
    }

    /// Sections that should be auto-expanded for this property type
    /// v313: Price History expanded by default for all property types
    /// v316+: Floor Layout expanded by default when data exists
    var defaultExpandedSections: Set<FactSection> {
        switch self {
        case .singleFamily:
            return [.priceHistory, .interior, .lotLand, .schools, .floorLayout]
        case .condoTownhouse:
            return [.priceHistory, .hoaCommunity, .interior, .schools, .floorLayout]
        case .rental:
            return [.priceHistory, .rentalDetails, .hoaCommunity, .utilities, .floorLayout]
        case .multiFamilyInvestment:
            return [.priceHistory, .investmentMetrics, .financial, .floorLayout]
        case .land:
            return [.priceHistory, .lotLand, .utilities]
        case .commercial:
            return [.priceHistory, .financial, .utilities]
        }
    }

    /// Sections that should be hidden for this property type
    var hiddenSections: Set<FactSection> {
        switch self {
        case .singleFamily:
            return [.rentalDetails, .investmentMetrics, .hoaCommunity]
        case .condoTownhouse:
            return [.rentalDetails, .investmentMetrics]
        case .rental:
            return [.investmentMetrics, .monthlyPayment, .financial, .disclosures]  // v308: Hide monthly payment for rentals
        case .multiFamilyInvestment:
            return [.rentalDetails, .hoaCommunity]
        case .land:
            return [.rentalDetails, .investmentMetrics, .interior, .exterior, .floorLayout, .parking, .hoaCommunity, .schools]
        case .commercial:
            return [.interior, .schools, .rentalDetails]
        }
    }
}

// MARK: - Fact Section (v302)
// Unified section ordering for all platforms

enum FactSection: String, CaseIterable {
    case priceHistory
    case previousSales  // v6.68.0: Previous sales at same address
    case openHouses
    case marketInsights  // v6.73.0: City market analytics
    case rentalDetails
    case investmentMetrics
    case monthlyPayment  // v308: Monthly Payment Calculator as collapsible section
    case interior
    case exterior
    case lotLand
    case floorLayout
    case parking
    case utilities
    case hoaCommunity
    case financial
    case schools
    case disclosures
    case additionalDetails

    /// SF Symbol icon for this section
    var icon: String {
        switch self {
        case .priceHistory: return "clock.arrow.circlepath"
        case .previousSales: return "house.and.flag.fill"
        case .openHouses: return "calendar.badge.clock"
        case .marketInsights: return "chart.bar.xaxis"
        case .rentalDetails: return "key.fill"
        case .investmentMetrics: return "chart.line.uptrend.xyaxis"
        case .monthlyPayment: return "creditcard.fill"
        case .interior: return "house.fill"
        case .exterior: return "building.2.fill"
        case .lotLand: return "leaf.fill"
        case .floorLayout: return "square.split.2x2.fill"
        case .parking: return "car.fill"
        case .utilities: return "bolt.fill"
        case .hoaCommunity: return "building.columns.fill"
        case .financial: return "dollarsign.circle.fill"
        case .schools: return "graduationcap.fill"
        case .disclosures: return "doc.text.fill"
        case .additionalDetails: return "info.circle.fill"
        }
    }

    /// Get dynamic title based on property type
    func title(for category: PropertyTypeCategory) -> String {
        switch self {
        case .priceHistory:
            return "Price & Status History"
        case .previousSales:
            return "Previous Sales at This Address"
        case .openHouses:
            return "Open Houses"
        case .marketInsights:
            return "Market Insights"
        case .rentalDetails:
            return "Rental Details"
        case .investmentMetrics:
            return "Investment Metrics"
        case .monthlyPayment:
            return "Monthly Payment"
        case .interior:
            return "Interior Features"
        case .exterior:
            return "Exterior & Structure"
        case .lotLand:
            return "Lot & Land"
        case .floorLayout:
            return "Floor Layout / Rooms"
        case .parking:
            return "Parking & Garage"
        case .utilities:
            return "Utilities & Systems"
        case .hoaCommunity:
            switch category {
            case .rental:
                return "Building & Pet Policy"
            default:
                return "HOA & Community"
            }
        case .financial:
            switch category {
            case .rental:
                return "Lease Terms"
            case .multiFamilyInvestment:
                return "Financial & Investment"
            default:
                return "Financial & Tax"
            }
        case .schools:
            return "Schools"
        case .disclosures:
            return "Disclosures"
        case .additionalDetails:
            return "Additional Details"
        }
    }
}

struct PropertyDetailView: View {
    let propertyId: String

    @State private var property: PropertyDetail?
    @State private var isLoading = true
    @State private var errorMessage: String?
    @State private var selectedImageIndex = 0
    @State private var showingImageGallery = false
    @State private var showingContactSheet = false
    @State private var showingShareSheet = false
    @State private var showingVirtualTour = false
    @State private var selectedVirtualTourUrl: String?
    @Environment(\.dismiss) private var dismiss

    // Bottom sheet state
    @State private var isExpanded: Bool = false
    @State private var dragOffset: CGFloat = 0

    // Visual feedback for drag handle
    @State private var showCloseIndicator: Bool = false
    @State private var overscrollOffset: CGFloat = 0

    // MLS Copy state
    @State private var mlsCopied: Bool = false

    // Appointment booking state
    @State private var showBookAppointment: Bool = false
    @StateObject private var appointmentViewModel = AppointmentViewModel()

    // Property History state
    @State private var propertyHistory: PropertyHistoryData?
    @State private var isLoadingHistory: Bool = false
    // historyExpanded now uses expandedSections via sectionBinding(.priceHistory)

    // Address History (Previous Sales at same address) - v6.68.0
    @State private var addressHistory: AddressHistoryData?
    @State private var isLoadingAddressHistory: Bool = false

    // My Agent state (Phase 5)
    @State private var myAgent: Agent?

    // Share with Clients state (Sprint 3)
    @State private var showShareWithClientsSheet: Bool = false
    @EnvironmentObject var authViewModel: AuthViewModel
    @EnvironmentObject var siteContactManager: SiteContactManager

    // Calendar action sheet state
    @State private var showCalendarActionSheet: Bool = false
    @State private var pendingCalendarEvent: (title: String, location: String, start: Date, end: Date)?

    // v6.69.0: Schedule Open House (agents only)
    @State private var showCreateOpenHouseSheet: Bool = false

    // v316: Full-screen floor layout modal
    @State private var showFloorLayoutModal: Bool = false

    // v365: Phone number action sheet for linked text
    @State private var showPhoneActionSheet: Bool = false
    @State private var selectedPhoneNumber: String?

    // v6.73.0: City Market Insights
    @State private var marketInsights: CityMarketInsights?
    @State private var isLoadingMarketInsights: Bool = false
    @State private var marketInsightsError: String?

    // v386: Quick CMA feature (agents only)
    @State private var showCMASheet: Bool = false

    // v302: Unified section state management using Set
    @State private var expandedSections: Set<FactSection> = []

    // Helper to create bindings for CollapsibleSection views
    private func sectionBinding(_ section: FactSection) -> Binding<Bool> {
        Binding(
            get: { expandedSections.contains(section) },
            set: { isExpanded in
                if isExpanded {
                    expandedSections.insert(section)
                } else {
                    expandedSections.remove(section)
                }
            }
        )
    }

    // v302: Computed property type category for smart defaults
    private var propertyCategory: PropertyTypeCategory {
        PropertyTypeCategory.from(
            propertyType: property?.propertyType,
            propertySubtype: property?.propertySubtype
        )
    }

    // v302: Apply smart defaults when property loads
    private func applySmartDefaults() {
        expandedSections = propertyCategory.defaultExpandedSections
    }

    // NOTE: Per CLAUDE.md Pitfall #31, no let statements in ViewBuilder closures
    // These constants extracted to avoid ViewBuilder type-checking issues
    private let sheetCollapsedHeight: CGFloat = 180

    private func sheetExpandedHeight(_ geometry: GeometryProxy) -> CGFloat {
        geometry.size.height - 20
    }

    var body: some View {
        GeometryReader { geometry in
            ZStack(alignment: .bottom) {
                // Background - Vertical scrolling photos
                if let property = property {
                    photoBackground(property: property, geometry: geometry, bottomPadding: sheetCollapsedHeight)
                } else if isLoading {
                    AppColors.shimmerBase
                        .overlay(ProgressView())
                } else {
                    AppColors.shimmerBase
                }

                // Bottom Sheet
                if isLoading {
                    bottomSheetSkeleton(collapsedHeight: sheetCollapsedHeight)
                } else if let error = errorMessage {
                    errorSheet(error: error, collapsedHeight: sheetCollapsedHeight)
                } else if let property = property {
                    bottomSheet(
                        property: property,
                        geometry: geometry,
                        collapsedHeight: sheetCollapsedHeight,
                        expandedHeight: sheetExpandedHeight(geometry)
                    )
                }
            }
            .ignoresSafeArea(edges: .bottom)
        }
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(.hidden, for: .navigationBar)
        .toolbar(.hidden, for: .tabBar)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                toolbarButton(icon: "square.and.arrow.up") {
                    showingShareSheet = true
                    Task { await trackShareClick() }
                }
            }
        }
        .sheet(isPresented: $showingShareSheet) {
            if let property = property {
                ShareSheet(items: shareItems(for: property))
            }
        }
        .sheet(isPresented: $showShareWithClientsSheet) {
            if let property = property {
                ShareWithClientsSheet(
                    listingKey: property.id,
                    propertyAddress: property.fullAddress
                )
            }
        }
        // v386: Quick CMA sheet (agents only)
        .sheet(isPresented: $showCMASheet) {
            if let property = property {
                CMASheet(property: property)
            }
        }
        .fullScreenCover(isPresented: $showingVirtualTour) {
            if let urlString = selectedVirtualTourUrl ?? property?.virtualTourUrl,
               let url = URL(string: urlString) {
                SafariView(url: url)
            }
        }
        .fullScreenCover(isPresented: $showingImageGallery) {
            if let property = property {
                ImageGalleryView(
                    images: property.imageURLs,
                    selectedIndex: $selectedImageIndex
                )
            }
        }
        .fullScreenCover(isPresented: $showFloorLayoutModal) {
            if let property = property {
                FloorLayoutModalView(
                    property: property,
                    rooms: property.rooms ?? [],
                    isPresented: $showFloorLayoutModal
                )
            }
        }
        .task {
            await loadProperty()
            await loadMyAgent()
            // Track property view for analytics (Sprint 5)
            await trackPropertyView()
        }
    }

    // MARK: - Analytics Tracking (Sprint 5)

    private func trackPropertyView() async {
        // Track for authenticated users (client analytics)
        await ActivityTracker.shared.trackPropertyView(
            listingKey: propertyId,
            city: property?.city
        )

        // Track for ALL users (public analytics - cross-platform)
        await PublicAnalyticsService.shared.trackPropertyView(
            listingId: propertyId,
            listingKey: propertyId,
            city: property?.city,
            price: property?.price,
            beds: property?.beds,
            baths: property?.baths
        )
    }

    private func trackShareClick() async {
        await PublicAnalyticsService.shared.trackShareClick(listingId: propertyId)
    }

    private func trackContactClick(type: String) async {
        await PublicAnalyticsService.shared.trackContactClick(listingId: propertyId, contactType: type)
    }

    private func trackScheduleClick() async {
        await PublicAnalyticsService.shared.trackScheduleClick(listingId: propertyId)
    }

    private func trackPhotoView(index: Int) async {
        await PublicAnalyticsService.shared.trackPhotoView(listingId: propertyId, photoIndex: index)
    }

    private func loadMyAgent() async {
        do {
            myAgent = try await AgentService.shared.fetchMyAgent()
        } catch {
            // Silently fail - myAgent section won't show
        }
    }

    // MARK: - Toolbar Button

    private func toolbarButton(icon: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.system(size: 16, weight: .semibold))
                .foregroundStyle(.white)
                .frame(width: 36, height: 36)
                .background(AppColors.shadowMedium)
                .clipShape(Circle())
        }
    }

    // MARK: - Photo Background

    private func photoBackground(property: PropertyDetail, geometry: GeometryProxy, bottomPadding: CGFloat) -> some View {
        ZStack(alignment: .topTrailing) {
            ScrollView(.vertical, showsIndicators: false) {
                LazyVStack(spacing: 0) {
                    ForEach(Array(property.imageURLs.enumerated()), id: \.offset) { index, url in
                    AsyncImage(url: url) { phase in
                        switch phase {
                        case .empty:
                            Rectangle()
                                .fill(AppColors.shimmerBase)
                                .frame(height: geometry.size.width * 0.75)
                                .overlay(ProgressView())
                        case .success(let image):
                            image
                                .resizable()
                                .aspectRatio(contentMode: .fill)
                                .frame(width: geometry.size.width)
                                .frame(minHeight: geometry.size.width * 0.6, maxHeight: geometry.size.width * 0.85)
                                .clipped()
                        case .failure:
                            Rectangle()
                                .fill(AppColors.shimmerBase)
                                .frame(height: geometry.size.width * 0.75)
                                .overlay(
                                    Image(systemName: "photo")
                                        .font(.largeTitle)
                                        .foregroundStyle(.secondary)
                                )
                        @unknown default:
                            EmptyView()
                        }
                    }
                    .onTapGesture {
                        selectedImageIndex = index
                        showingImageGallery = true
                    }
                }

                // Bottom spacer so last image stops at the sheet
                Color.clear
                    .frame(height: bottomPadding)
                }
            }
            .scrollDisabled(isExpanded) // Disable photo scroll when sheet is expanded
            .frame(height: geometry.size.height)

            // Photo count badge - NOTE: Per CLAUDE.md Pitfall #31, no let statements in ViewBuilder
            if getPhotoCount(property) > 1 {
                HStack(spacing: 4) {
                    Image(systemName: "photo.on.rectangle")
                        .font(.caption2)
                    Text("\(getPhotoCount(property)) Photos")
                        .font(.caption2)
                        .fontWeight(.medium)
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 6)
                .background(.ultraThinMaterial)
                .clipShape(Capsule())
                .padding(.top, 60) // Account for nav bar
                .padding(.trailing, 16)
            }
        }
    }

    // MARK: - Bottom Sheet

    private func bottomSheet(
        property: PropertyDetail,
        geometry: GeometryProxy,
        collapsedHeight: CGFloat,
        expandedHeight: CGFloat
    ) -> some View {
        // NOTE: Per CLAUDE.md Pitfall #31, no let statements in ViewBuilder closures
        // Calculations extracted to helper functions
        bottomSheetContent(
            property: property,
            collapsedHeight: collapsedHeight,
            expandedHeight: expandedHeight,
            currentHeight: calculateCurrentHeight(collapsedHeight: collapsedHeight, expandedHeight: expandedHeight),
            dismissProgress: calculateDismissProgress(collapsedHeight: collapsedHeight, expandedHeight: expandedHeight)
        )
    }

    private func calculateCurrentHeight(collapsedHeight: CGFloat, expandedHeight: CGFloat) -> CGFloat {
        let baseHeight = isExpanded ? expandedHeight : collapsedHeight
        let draggedHeight = baseHeight - dragOffset
        return min(expandedHeight, max(0, draggedHeight))
    }

    private func calculateDismissProgress(collapsedHeight: CGFloat, expandedHeight: CGFloat) -> CGFloat {
        let currentHeight = calculateCurrentHeight(collapsedHeight: collapsedHeight, expandedHeight: expandedHeight)
        return max(0, collapsedHeight - currentHeight) / collapsedHeight
    }

    @ViewBuilder
    private func bottomSheetContent(
        property: PropertyDetail,
        collapsedHeight: CGFloat,
        expandedHeight: CGFloat,
        currentHeight: CGFloat,
        dismissProgress: CGFloat
    ) -> some View {
        VStack(spacing: 0) {
            // Drag handle - always draggable to collapse/expand
            VStack(spacing: 8) {
                Capsule()
                    .fill(showCloseIndicator ? AppColors.brandTeal : AppColors.dragHandle)
                    .frame(width: showCloseIndicator ? 60 : 40, height: 5)
                    .animation(.spring(response: 0.2), value: showCloseIndicator)
                    .padding(.top, 10)

                Text(isExpanded
                     ? (showCloseIndicator ? "Release to collapse" : "Drag or tap to collapse")
                     : "Tap for details")
                    .font(.caption2)
                    .foregroundStyle(showCloseIndicator ? AppColors.brandTeal : .secondary)
                    .animation(.easeInOut(duration: 0.15), value: showCloseIndicator)
            }
            .frame(height: 50)
            .frame(maxWidth: .infinity)
            .offset(y: overscrollOffset * 0.3)
            .contentShape(Rectangle())
            .gesture(
                DragGesture(minimumDistance: 10)
                    .onChanged { value in
                        handleDragChanged(value)
                    }
                    .onEnded { value in
                        handleDragEnded(value)
                    }
            )
            .onTapGesture {
                withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                    isExpanded.toggle()
                }
                HapticManager.impact(.light)
            }

            // Content - show based on current visual height
            if currentHeight > collapsedHeight + 50 {
                expandedContent(property: property, maxHeight: currentHeight - 50)
            } else {
                collapsedContent(property: property)
                    .contentShape(Rectangle())
                    .gesture(
                        DragGesture(minimumDistance: 10)
                            .onChanged { value in
                                handleDragChanged(value)
                            }
                            .onEnded { value in
                                handleDragEnded(value)
                            }
                    )
                    .onTapGesture {
                        withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                            isExpanded = true
                        }
                    }
            }
        }
        .frame(height: max(50, currentHeight))
        .frame(maxWidth: .infinity)
        .background(
            RoundedRectangle(cornerRadius: 20)
                .fill(Color(.systemBackground))
                .shadow(color: AppColors.shadowMedium, radius: 10, y: -5)
        )
        .opacity(Double(1.0 - dismissProgress * 0.5))
        .animation(.interactiveSpring(response: 0.3, dampingFraction: 0.8), value: currentHeight)
        .sheet(isPresented: $showingContactSheet) {
            // For clients: show their assigned agent if they have one, otherwise team info
            // For agents: always nil (not applicable - they contact listing agent directly)
            // NOTE: Per CLAUDE.md Pitfall #31, no let statements in ViewBuilder closures
            ContactAgentSheet(property: property, assignedAgent: agentForContactSheet())
        }
        .sheet(isPresented: $showBookAppointment) {
            BookAppointmentView(viewModel: appointmentViewModel)
                .onDisappear {
                    // Reset the booking flow when sheet closes
                    appointmentViewModel.resetBookingFlow()
                }
        }
        .confirmationDialog("Add to Calendar", isPresented: $showCalendarActionSheet, titleVisibility: .visible) {
            Button("Apple Calendar") {
                if let event = pendingCalendarEvent {
                    addToAppleCalendar(title: event.title, location: event.location, start: event.start, end: event.end)
                }
            }
            Button("Google Calendar") {
                if let event = pendingCalendarEvent {
                    addToGoogleCalendar(title: event.title, location: event.location, start: event.start, end: event.end)
                }
            }
            Button("Cancel", role: .cancel) {}
        }
        // v365: Phone number action sheet (Call or Text)
        .confirmationDialog("Contact", isPresented: $showPhoneActionSheet, titleVisibility: .visible) {
            if let phone = selectedPhoneNumber {
                Button("Call \(formatPhoneForDisplay(phone))") {
                    if let url = URL(string: "tel:\(phone)") {
                        UIApplication.shared.open(url)
                    }
                }
                Button("Text \(formatPhoneForDisplay(phone))") {
                    if let url = URL(string: "sms:\(phone)") {
                        UIApplication.shared.open(url)
                    }
                }
            }
            Button("Cancel", role: .cancel) {
                selectedPhoneNumber = nil
            }
        }
        // v6.69.0: Schedule Open House sheet (agents only)
        .sheet(isPresented: $showCreateOpenHouseSheet) {
            NavigationStack {
                CreateOpenHouseView(
                    prefilledProperty: PropertyOpenHouseData(from: property),
                    onSave: { _ in
                        showCreateOpenHouseSheet = false
                    }
                )
            }
        }
    }

    // MARK: - Gesture Handlers

    private func handleDragChanged(_ value: DragGesture.Value) {
        if isExpanded {
            // When expanded, dragging handle down collapses
            if value.translation.height > 0 {
                // Show visual feedback
                if value.translation.height < 20 {
                    overscrollOffset = value.translation.height
                    if overscrollOffset > 15 && !showCloseIndicator {
                        showCloseIndicator = true
                        HapticManager.impact(.light)
                    }
                } else {
                    dragOffset = value.translation.height - 20
                }
            }
        } else {
            // Collapsed state - drag to expand or dismiss
            dragOffset = value.translation.height
        }
    }

    private func handleDragEnded(_ value: DragGesture.Value) {
        let velocity = value.predictedEndTranslation.height - value.translation.height

        // Animate back to rest
        withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
            dragOffset = 0
            overscrollOffset = 0
            showCloseIndicator = false
        }

        if isExpanded {
            // Collapse if dragged down enough
            if value.translation.height > 50 || velocity > 200 {
                withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                    isExpanded = false
                }
                HapticManager.impact(.medium)
            }
        } else {
            // Collapsed: check for dismiss or expand
            if value.translation.height > 100 || velocity > 400 {
                HapticManager.notification(.warning)
                dismiss()
            } else if value.translation.height < -50 || velocity < -200 {
                withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                    isExpanded = true
                }
                HapticManager.impact(.light)
            }
        }
    }

    // MARK: - Collapsed Content (Basic Info)

    // v305: Extracted to CollapsedContentView struct to break type chain
    private func collapsedContent(property: PropertyDetail) -> some View {
        CollapsedContentView(property: property)
    }

    // MARK: - Expanded Content (Full Details)
    // v305: Extracted to ExpandedContentView struct to break the deep generic type hierarchy
    // that was causing stack overflow during Swift type metadata resolution.
    // Complex sections are wrapped in AnyView to erase their types.

    private func expandedContent(property: PropertyDetail, maxHeight: CGFloat) -> some View {
        ExpandedContentView(
            property: property,
            maxHeight: maxHeight,
            mlsCopied: $mlsCopied,
            showingContactSheet: $showingContactSheet,
            showShareWithClientsSheet: $showShareWithClientsSheet,
            showBookAppointment: $showBookAppointment,
            showCreateOpenHouseSheet: $showCreateOpenHouseSheet,
            showingVirtualTour: $showingVirtualTour,
            selectedVirtualTourUrl: $selectedVirtualTourUrl,
            isUserAgent: authViewModel.currentUser?.isAgent == true,
            appointmentViewModel: appointmentViewModel,
            onAddToCalendar: addToCalendar,
            onTrackScheduleClick: trackScheduleClick,
            onTrackContactClick: trackContactClick,
            propertyHistoryContent: AnyView(propertyHistorySection(property)),
            previousSalesContent: AnyView(previousSalesSection(property)),
            marketInsightsContent: AnyView(marketInsightsSection(property)),
            factsAndFeaturesContent: AnyView(factsAndFeaturesSection(property)),
            paymentCalculatorContent: AnyView(paymentCalculatorSection(property)),
            agentSectionContent: AnyView(agentSection(property)),
            contactSectionContent: AnyView(contactSection)
        )
    }

    private func priceSection(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            // MLS Number with Copy Button
            if let mls = property.mlsNumber {
                Button {
                    UIPasteboard.general.string = mls
                    mlsCopied = true
                    HapticManager.impact(.light)
                    DispatchQueue.main.asyncAfter(deadline: .now() + 1.5) {
                        mlsCopied = false
                    }
                } label: {
                    HStack(spacing: 4) {
                        Text("MLS# \(mls)")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Image(systemName: mlsCopied ? "checkmark.circle.fill" : "doc.on.doc")
                            .font(.caption2)
                            .foregroundStyle(mlsCopied ? .green : .secondary)
                    }
                }
            }

            // Price Row
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 4) {
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text(property.formattedPrice)
                            .font(.title)
                            .fontWeight(.bold)

                        // Original price strikethrough if reduced
                        if property.isPriceReduced, let original = property.originalPrice {
                            Text(formatPrice(original))
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                                .strikethrough()
                        }
                    }

                    if let dom = property.dom, dom > 0 {
                        Text("\(dom) days on market")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }

                    // Price per sqft
                    if let pricePerSqft = property.pricePerSqft {
                        Text("$\(pricePerSqft)/sqft")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                Spacer()

                // Status badge
                VStack(alignment: .trailing, spacing: 4) {
                    Text(property.standardStatus.displayName)
                        .font(.caption)
                        .fontWeight(.semibold)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(statusColor(for: property.standardStatus).opacity(0.15))
                        .foregroundStyle(statusColor(for: property.standardStatus))
                        .clipShape(Capsule())

                    Text(property.propertySubtype ?? property.propertyType)
                        .font(.caption)
                        .fontWeight(.medium)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(AppColors.brandTeal.opacity(0.1))
                        .foregroundStyle(AppColors.brandTeal)
                        .clipShape(Capsule())
                }
            }

            // Status Tags Row
            statusTagsSection(property)

            // Sold Statistics (for closed/sold listings)
            if property.standardStatus.isSold, property.closePrice != nil {
                soldStatisticsSection(property)
            }

            // Address
            Text(property.fullAddress)
                .font(.subheadline)
                .foregroundStyle(.secondary)

            // Neighborhood
            if let neighborhood = property.neighborhood {
                Text(neighborhood)
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
        }
    }

    private func statusColor(for status: PropertyStatus) -> Color {
        switch status {
        case .active: return AppColors.activeStatus
        case .pending: return AppColors.pendingStatus
        case .sold, .closed: return AppColors.soldStatus
        case .withdrawn, .expired, .canceled: return Color.gray
        }
    }

    private func formatPrice(_ price: Int) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: price)) ?? "$\(price)"
    }

    private func formatPriceReduction(_ amount: Int) -> String {
        amount >= 1000 ? "-$\(amount / 1000)K" : "-$\(amount)"
    }

    /// Format phone number for display in action sheet (v365)
    private func formatPhoneForDisplay(_ phone: String) -> String {
        // If it's already formatted nicely, return as-is
        if phone.contains("-") || phone.contains("(") {
            return phone
        }
        // Format 10-digit numbers as (XXX) XXX-XXXX
        let digits = phone.replacingOccurrences(of: "[^0-9]", with: "", options: .regularExpression)
        if digits.count == 10 {
            let areaCode = digits.prefix(3)
            let middle = digits.dropFirst(3).prefix(3)
            let last = digits.dropFirst(6)
            return "(\(areaCode)) \(middle)-\(last)"
        } else if digits.count == 11 && digits.hasPrefix("1") {
            // Format 11-digit numbers starting with 1
            let areaCode = digits.dropFirst(1).prefix(3)
            let middle = digits.dropFirst(4).prefix(3)
            let last = digits.dropFirst(7)
            return "(\(areaCode)) \(middle)-\(last)"
        }
        return phone
    }

    // MARK: - Status Tags Section

    private func statusTagsSection(_ property: PropertyDetail) -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                // New Listing tag
                if property.isNewListing {
                    StatusTag(text: "New", color: .green)
                }

                // Price Reduced tag
                // NOTE: Per CLAUDE.md Pitfall #31, no let statements in ViewBuilder
                if property.isPriceReduced, let amount = property.priceReductionAmount {
                    StatusTag(text: formatPriceReduction(amount), color: .red)
                }

                // Open House tag
                if property.hasOpenHouse, let nextOH = property.nextOpenHouse {
                    StatusTag(text: "Open \(nextOH.formattedShort)", color: .orange)
                } else if property.hasOpenHouse {
                    StatusTag(text: "Open House", color: .orange)
                }

                // v6.64.0 / v284: Exclusive Listing badge (gold color)
                if property.isExclusive {
                    HStack(spacing: 4) {
                        Image(systemName: "star.fill")
                            .font(.caption2)
                        Text("Exclusive")
                            .font(.caption2)
                            .fontWeight(.semibold)
                    }
                    .padding(.horizontal, 10)
                    .padding(.vertical, 6)
                    .background(Color(red: 0.85, green: 0.65, blue: 0.13))  // Gold color
                    .foregroundStyle(.white)
                    .clipShape(Capsule())
                }

                // Property Highlights
                ForEach(property.highlightTags, id: \.rawValue) { highlight in
                    HStack(spacing: 4) {
                        Image(systemName: highlight.icon)
                        Text(highlight.rawValue)
                    }
                    .font(.caption2)
                    .fontWeight(.medium)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 6)
                    .background(Color(hex: highlight.color).opacity(0.15))
                    .foregroundStyle(Color(hex: highlight.color))
                    .clipShape(Capsule())
                }
            }
        }
    }

    // MARK: - Sold Statistics Section

    private func soldStatisticsSection(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Image(systemName: "checkmark.seal.fill")
                    .foregroundStyle(AppColors.soldStatus)
                Text("Sale Information")
                    .font(.subheadline)
                    .fontWeight(.semibold)
            }

            VStack(spacing: 8) {
                // Close Price
                if let closePrice = property.closePrice {
                    HStack {
                        Text("Sold Price")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(formatPrice(closePrice))
                            .font(.subheadline)
                            .fontWeight(.semibold)
                    }
                }

                // Close Date
                if let closeDate = property.closeDate {
                    HStack {
                        Text("Sold Date")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(formatDate(closeDate))
                            .font(.caption)
                    }
                }

                // List to Sale Ratio
                if let ratio = property.listToSaleRatio {
                    HStack {
                        Text("List to Sale Ratio")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(String(format: "%.1f%%", ratio))
                            .font(.caption)
                            .fontWeight(.medium)
                            .foregroundStyle(ratio >= 100 ? AppColors.activeStatus : AppColors.soldStatus)
                    }
                }

                // Sold Above/Below
                if let aboveBelow = property.soldAboveBelow, let priceDiff = property.priceDifference {
                    HStack {
                        Text("Compared to List")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        HStack(spacing: 4) {
                            Image(systemName: soldComparisonIcon(for: aboveBelow))
                                .font(.caption)
                            Text("\(soldComparisonLabel(for: aboveBelow)) asking (\(formatPriceChange(priceDiff)))")
                                .font(.caption)
                        }
                        .foregroundStyle(soldComparisonColor(for: aboveBelow))
                    }
                }
            }
            .padding()
            .background(AppColors.soldStatus.opacity(0.08))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    private func formatDate(_ dateString: String) -> String {
        let inputFormatter = DateFormatter()
        inputFormatter.dateFormat = "yyyy-MM-dd"
        if let date = inputFormatter.date(from: dateString) {
            let outputFormatter = DateFormatter()
            outputFormatter.dateFormat = "MMM d, yyyy"
            return outputFormatter.string(from: date)
        }
        return dateString
    }

    private func formatPriceChange(_ amount: Int) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let absAmount = abs(amount)
        return formatter.string(from: NSNumber(value: absAmount)) ?? "$\(absAmount)"
    }

    // v308: Enhanced time on market display
    /// v309: Enhanced time on market display with contextual formatting
    private func formatDaysOnMarket(_ days: Int) -> String {
        switch days {
        case 0:
            return "Listed today"
        case 1:
            return "1 day"
        case 2...6:
            return "\(days) days"
        case 7:
            return "1 week"
        case 8...13:
            return "\(days) days (~1 week)"
        case 14...20:
            let weeks = days / 7
            return "\(days) days (~\(weeks) weeks)"
        case 21...27:
            return "\(days) days (~3 weeks)"
        case 28...30:
            return "~1 month"
        case 31...59:
            let weeks = days / 7
            return "\(days) days (~\(weeks) weeks)"
        case 60...89:
            return "~2 months"
        case 90...179:
            let months = days / 30
            return "~\(months) months"
        case 180...364:
            let months = days / 30
            return "~\(months) months"
        default:
            if days >= 365 {
                let years = days / 365
                let remainingMonths = (days % 365) / 30
                if remainingMonths > 0 {
                    return "~\(years) year\(years > 1 ? "s" : ""), \(remainingMonths) mo"
                }
                return "~\(years) year\(years > 1 ? "s" : "")"
            }
            return "\(days) days"
        }
    }

    /// v309: Format time on market with optional hours for very recent listings
    private func formatGranularTimeOnMarket(days: Int, hours: Int? = nil) -> String {
        if days == 0 {
            if let hours = hours {
                if hours == 0 {
                    return "Just listed"
                } else if hours == 1 {
                    return "1 hour ago"
                } else if hours < 24 {
                    return "\(hours) hours ago"
                }
            }
            return "Listed today"
        }
        return formatDaysOnMarket(days)
    }

    // MARK: - Sold Comparison Helpers (Extracted from ViewBuilder)

    private func soldComparisonIcon(for aboveBelow: String) -> String {
        switch aboveBelow {
        case "above": return "arrow.up.circle.fill"
        case "below": return "arrow.down.circle.fill"
        default: return "equal.circle.fill"
        }
    }

    private func soldComparisonLabel(for aboveBelow: String) -> String {
        switch aboveBelow {
        case "above": return "Above"
        case "below": return "Below"
        default: return "At"
        }
    }

    private func soldComparisonColor(for aboveBelow: String) -> Color {
        switch aboveBelow {
        case "above": return AppColors.activeStatus
        case "below": return AppColors.soldStatus
        default: return .secondary
        }
    }

    // MARK: - Property History Section

    private func propertyHistorySection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: "Price & Status History",
            icon: "clock.arrow.circlepath",
            isExpanded: sectionBinding(.priceHistory)
        ) {
            VStack(alignment: .leading, spacing: 12) {
                if isLoadingHistory {
                    HStack {
                        ProgressView()
                            .scaleEffect(0.8)
                        Text("Loading history...")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 8)
                } else if let history = propertyHistory {
                    // Summary stats row
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Time on Market")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            // v6.67.2: Use granular time text when available
                            Text(history.timeOnMarketText ?? formatDaysOnMarket(history.daysOnMarket))
                                .font(.subheadline)
                                .fontWeight(.semibold)
                        }
                        Spacer()
                        if history.totalPriceChange != 0 {
                            VStack(alignment: .trailing, spacing: 2) {
                                Text("Price Change")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                HStack(spacing: 4) {
                                    Text(history.formattedPriceChange)
                                        .font(.subheadline)
                                        .fontWeight(.semibold)
                                        .foregroundStyle(history.totalPriceChange > 0 ? AppColors.activeStatus : AppColors.soldStatus)
                                    Text("(\(history.formattedPercentChange))")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                    .padding(.bottom, 4)

                    // v6.67.1: Market insights summary (price changes, status changes)
                    if let insights = history.marketInsightsSummary {
                        HStack(spacing: 4) {
                            Image(systemName: "chart.line.uptrend.xyaxis")
                                .font(.caption2)
                            Text(insights)
                                .font(.caption)
                        }
                        .foregroundStyle(.secondary)
                        .padding(.bottom, 8)
                    }

                    Divider()
                        .padding(.bottom, 8)

                    // Timeline with enhanced event display
                    ForEach(history.events) { event in
                        historyEventRow(event)
                    }
                } else {
                    Text("No history available")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .padding(.vertical, 8)
                }
            }
        }
        .onAppear {
            if propertyHistory == nil && !isLoadingHistory {
                Task {
                    await loadPropertyHistory(propertyId: property.id)
                }
            }
        }
    }

    /// v6.67.1: Enhanced history event row with status transitions and details
    private func historyEventRow(_ event: PropertyHistoryEvent) -> some View {
        HStack(alignment: .top, spacing: 12) {
            // Timeline icon (v6.67.1: now uses dynamic icon from event)
            Image(systemName: event.icon)
                .font(.system(size: 12))
                .foregroundStyle(Color(hex: event.color))
                .frame(width: 16, height: 16)
                .padding(.top, 2)

            VStack(alignment: .leading, spacing: 4) {
                // Event title row
                HStack {
                    Text(event.event)
                        .font(.subheadline)
                        .fontWeight(.medium)
                    Spacer()
                    if let formattedDateTime = event.formattedDateTime {
                        Text(formattedDateTime)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    } else if let formattedDate = event.formattedDate {
                        Text(formattedDate)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                // Status transition (e.g., "Active → Pending")
                if event.isStatusChange, let transition = event.statusTransition {
                    HStack(spacing: 4) {
                        Image(systemName: "arrow.right")
                            .font(.caption2)
                        Text(transition)
                            .font(.caption)
                    }
                    .foregroundStyle(.blue)
                }

                // v6.68.0: Agent change transition (e.g., "John Smith → Jane Doe")
                if event.isAgentChange, let transition = event.agentTransition {
                    HStack(spacing: 4) {
                        Image(systemName: "person.2.fill")
                            .font(.caption2)
                        Text(transition)
                            .font(.caption)
                    }
                    .foregroundStyle(Color(hex: "#8B5CF6"))  // Purple
                }

                // Price info
                if let price = event.price {
                    HStack(spacing: 6) {
                        Text(formatPrice(price))
                            .font(.caption)
                            .foregroundStyle(.primary)
                        if let change = event.change, change != 0 {
                            Text("(\(change > 0 ? "+" : "")\(formatPriceChange(change)))")
                                .font(.caption2)
                                .foregroundStyle(change > 0 ? AppColors.activeStatus : AppColors.soldStatus)
                        }
                        // Price per sqft if available
                        if let pricePerSqft = event.pricePerSqft, pricePerSqft > 0 {
                            Text("•")
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                            Text("$\(pricePerSqft)/sqft")
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                // Days on market at this event
                if let dom = event.daysOnMarket, dom > 0 {
                    Text("Day \(dom) on market")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }

                // Agent/Office info for significant events
                if let agent = event.agentName {
                    HStack(spacing: 4) {
                        Image(systemName: "person.fill")
                            .font(.caption2)
                        Text(agent)
                            .font(.caption2)
                        if let office = event.officeName {
                            Text("• \(office)")
                                .font(.caption2)
                        }
                    }
                    .foregroundStyle(.secondary)
                }

                // Event details/notes
                if let details = event.details, !details.isEmpty {
                    Text(details)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .italic()
                }
            }
        }
        .padding(.vertical, 4)
    }

    // MARK: - Previous Sales Section (v6.68.0)

    private func previousSalesSection(_ property: PropertyDetail) -> some View {
        Group {
            // Only show if there are previous sales OR we're still loading
            if isLoadingAddressHistory || (addressHistory?.hasPreviousSales == true) {
                CollapsibleSection(
                    title: "Previous Sales at This Address",
                    icon: "house.and.flag.fill",
                    isExpanded: sectionBinding(.previousSales)
                ) {
                    VStack(alignment: .leading, spacing: 12) {
                        if isLoadingAddressHistory {
                            HStack {
                                ProgressView()
                                    .scaleEffect(0.8)
                                Text("Loading previous sales...")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            .padding(.vertical, 8)
                        } else if let history = addressHistory, history.hasPreviousSales {
                            // Summary
                            Text("\(history.totalCount) previous sale\(history.totalCount > 1 ? "s" : "") at this address")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                                .padding(.bottom, 4)

                            // Sale cards
                            ForEach(history.previousSales) { sale in
                                previousSaleCard(sale)
                            }
                        }
                    }
                }
                .onAppear {
                    if addressHistory == nil && !isLoadingAddressHistory {
                        Task {
                            await loadAddressHistory(propertyId: property.id)
                        }
                    }
                }
            } else {
                EmptyView()
            }
        }
    }

    /// Card displaying a previous sale at the same address
    private func previousSaleCard(_ sale: PreviousSale) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            // Header with MLS# and status
            HStack {
                Text("MLS# \(sale.mlsNumber)")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Spacer()
                Text(sale.status)
                    .font(.caption)
                    .fontWeight(.medium)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 2)
                    .background(sale.status == "Sold" ? AppColors.activeStatus.opacity(0.15) : Color.gray.opacity(0.15))
                    .foregroundStyle(sale.status == "Sold" ? AppColors.activeStatus : .secondary)
                    .clipShape(Capsule())
            }

            // Price info
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    if let closePrice = sale.formattedClosePrice {
                        Text(closePrice)
                            .font(.headline)
                            .fontWeight(.semibold)
                        if let date = sale.closeDateFormatted {
                            Text("Sold \(date)")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    } else if let listPrice = sale.formattedListPrice {
                        Text(listPrice)
                            .font(.headline)
                            .fontWeight(.semibold)
                        if let date = sale.listDateFormatted {
                            Text("Listed \(date)")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                Spacer()

                // Sale details
                VStack(alignment: .trailing, spacing: 2) {
                    if let dom = sale.daysOnMarket {
                        Text("\(dom) days")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    if let changeText = sale.formattedPriceChange, let aboveBelow = sale.soldAboveOrBelow {
                        HStack(spacing: 2) {
                            Text(changeText)
                                .font(.caption)
                                .foregroundStyle(sale.priceChange ?? 0 >= 0 ? AppColors.activeStatus : AppColors.soldStatus)
                            Text(aboveBelow)
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
        }
        .padding(12)
        .background(Color(.systemGray6))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    private func loadPropertyHistory(propertyId: String) async {
        isLoadingHistory = true
        do {
            let data: PropertyHistoryData = try await APIClient.shared.request(
                .propertyHistory(id: propertyId)
            )
            propertyHistory = data
        } catch {
            // Silently fail - history is optional
            print("Failed to load property history: \(error)")
        }
        isLoadingHistory = false
    }

    /// Load address history (previous sales at same address) - v6.68.0
    private func loadAddressHistory(propertyId: String) async {
        isLoadingAddressHistory = true
        do {
            let response: AddressHistoryResponse = try await APIClient.shared.request(
                .addressHistory(id: propertyId)
            )
            addressHistory = response.data
        } catch {
            // Silently fail - address history is optional
            print("Failed to load address history: \(error)")
        }
        isLoadingAddressHistory = false
    }

    // MARK: - Market Insights Section (v6.73.0)

    /// Load city market insights for the property's city
    private func loadMarketInsights(city: String) async {
        guard !city.isEmpty else { return }
        isLoadingMarketInsights = true
        marketInsightsError = nil
        do {
            // Use requestRaw because /mld/v1/ endpoints return data directly without wrapper
            let insights: CityMarketInsights = try await APIClient.shared.requestRaw(
                .cityMarketInsights(city: city)
            )
            marketInsights = insights
        } catch {
            // Silently fail - market insights are optional
            print("Failed to load market insights: \(error)")
            marketInsightsError = "Unable to load market data"
        }
        isLoadingMarketInsights = false
    }

    /// Market Insights section showing city-level analytics
    private func marketInsightsSection(_ property: PropertyDetail) -> some View {
        let city = property.city
        return Group {
            if !city.isEmpty {
                CollapsibleSection(
                    title: FactSection.marketInsights.title(for: propertyCategory),
                    icon: FactSection.marketInsights.icon,
                    isExpanded: sectionBinding(.marketInsights)
                ) {
                    VStack(alignment: .leading, spacing: 12) {
                        if isLoadingMarketInsights {
                            HStack {
                                ProgressView()
                                    .scaleEffect(0.8)
                                Text("Loading market data for \(city)...")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            .padding(.vertical, 8)
                        } else if let insights = marketInsights, let summary = insights.citySummary {
                            // Market heat indicator
                            if let heat = insights.marketHeat, let classification = heat.classification {
                                HStack(spacing: 8) {
                                    Image(systemName: heat.icon)
                                        .foregroundStyle(colorForHeat(heat.colorName))
                                    Text("\(city) Market: \(classification)")
                                        .font(.subheadline)
                                        .fontWeight(.medium)
                                    Spacer()
                                    if let score = heat.score {
                                        Text("\(score)/100")
                                            .font(.caption)
                                            .foregroundStyle(.secondary)
                                    }
                                }
                                .padding(12)
                                .background(colorForHeat(heat.colorName).opacity(0.1))
                                .clipShape(RoundedRectangle(cornerRadius: 8))
                            }

                            // Key stats grid
                            LazyVGrid(columns: [
                                GridItem(.flexible()),
                                GridItem(.flexible())
                            ], spacing: 12) {
                                // Active listings
                                if let active = summary.activeCount {
                                    marketStatCard(
                                        value: "\(active)",
                                        label: "Active Listings",
                                        icon: "house.fill"
                                    )
                                }

                                // Median price
                                if let medianFormatted = summary.formattedMedianPrice {
                                    marketStatCard(
                                        value: medianFormatted,
                                        label: "Median Price",
                                        icon: "dollarsign.circle"
                                    )
                                }

                                // Avg DOM
                                if let domFormatted = summary.formattedAvgDOM {
                                    marketStatCard(
                                        value: domFormatted,
                                        label: "Avg Days on Market",
                                        icon: "clock"
                                    )
                                }

                                // Price per sqft
                                if let ppsf = summary.avgPricePerSqft {
                                    marketStatCard(
                                        value: "$\(Int(ppsf))/sqft",
                                        label: "Avg Price/Sqft",
                                        icon: "square.fill"
                                    )
                                }

                                // Months of supply
                                if let monthsFormatted = summary.formattedMonthsOfSupply {
                                    marketStatCard(
                                        value: monthsFormatted,
                                        label: "Months of Supply",
                                        icon: "calendar"
                                    )
                                }

                                // YoY price change
                                if let yoyFormatted = summary.formattedYoYPriceChange {
                                    marketStatCard(
                                        value: yoyFormatted,
                                        label: "Price Change (YoY)",
                                        icon: summary.yoyPriceChangePct ?? 0 >= 0 ? "arrow.up.right" : "arrow.down.right",
                                        valueColor: (summary.yoyPriceChangePct ?? 0) >= 0 ? AppColors.activeStatus : AppColors.soldStatus
                                    )
                                }
                            }

                            // Recent sales info
                            if let sold12m = summary.sold12m, sold12m > 0 {
                                HStack {
                                    Image(systemName: "checkmark.circle.fill")
                                        .foregroundStyle(AppColors.activeStatus)
                                    Text("\(sold12m) homes sold in the last 12 months")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                                .padding(.top, 4)
                            }
                        } else if let error = marketInsightsError {
                            Text(error)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                .onAppear {
                    if marketInsights == nil && !isLoadingMarketInsights {
                        Task {
                            await loadMarketInsights(city: city)
                        }
                    }
                }
            } else {
                EmptyView()
            }
        }
    }

    /// Single stat card for market insights
    private func marketStatCard(value: String, label: String, icon: String, valueColor: Color = .primary) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 4) {
                Image(systemName: icon)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Text(value)
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(valueColor)
            }
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(10)
        .background(Color(.systemGray6))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    /// Convert color name string to SwiftUI Color
    private func colorForHeat(_ colorName: String) -> Color {
        switch colorName {
        case "red": return .red
        case "orange": return .orange
        case "yellow": return .yellow
        case "blue": return .blue
        default: return .gray
        }
    }

    private func keyDetailsGrid(_ property: PropertyDetail) -> some View {
        LazyVGrid(columns: [
            GridItem(.flexible()),
            GridItem(.flexible()),
            GridItem(.flexible()),
            GridItem(.flexible())
        ], spacing: 16) {
            DetailItem(icon: "bed.double.fill", value: "\(property.beds)", label: "Beds")
            DetailItem(icon: "shower.fill", value: String(format: "%.0f", property.baths), label: "Baths")
            if let sqft = property.sqft {
                DetailItem(icon: "square.fill", value: sqft.formatted(), label: "Sqft")
            }
            if let year = property.yearBuilt {
                DetailItem(icon: "calendar", value: "\(year)", label: "Built")
            }
            if let lotSize = property.lotSize {
                DetailItem(icon: "leaf.fill", value: String(format: "%.2f", lotSize), label: "Acres")
            }
            if let garage = property.garageSpaces, garage > 0 {
                DetailItem(icon: "car.fill", value: "\(garage)", label: "Garage")
            }
        }
    }

    private func descriptionSection(_ description: String) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Description")
                .font(.headline)

            Text(description)
                .font(.body)
                .foregroundStyle(.secondary)
        }
    }

    private func featuresSection(_ features: [String]) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Features")
                .font(.headline)

            PropertyFlowLayout(spacing: 8) {
                // Use enumerated for unique IDs to prevent crash with duplicate feature strings
                ForEach(Array(features.enumerated()), id: \.offset) { _, feature in
                    Text(feature)
                        .font(.caption)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(AppColors.shimmerBase)
                        .clipShape(Capsule())
                }
            }
        }
    }

    private func mapSection(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Location")
                .font(.headline)

            if let location = property.location {
                PropertyLocationMapView(
                    coordinate: location,
                    title: property.fullAddress
                )
                .frame(height: 200)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }

    // MARK: - Open Houses Section

    private func openHousesSection(_ openHouses: [PropertyOpenHouse]) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Open Houses")
                .font(.headline)

            ForEach(openHouses) { openHouse in
                HStack {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(openHouse.formattedDate)
                            .font(.subheadline)
                            .fontWeight(.semibold)
                        Text(openHouse.formattedTimeRange)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        if let remarks = openHouse.remarks, !remarks.isEmpty {
                            Text(remarks)
                                .font(.caption2)
                                .foregroundStyle(.tertiary)
                        }
                    }

                    Spacer()

                    // Add to Calendar button
                    if let startDate = openHouse.startDate, let endDate = openHouse.endDate {
                        Button {
                            let location = property?.fullAddress ?? ""
                            addToCalendar(title: "Open House: \(property?.address ?? "Property")", location: location, start: startDate, end: endDate)
                        } label: {
                            Image(systemName: "calendar.badge.plus")
                                .font(.title3)
                                .foregroundStyle(AppColors.brandTeal)
                        }
                    }
                }
                .padding()
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }

    // MARK: - Calendar Integration (v280)
    // Added in v280: Open house calendar feature with Apple Calendar and Google Calendar options
    // Uses EventKit for Apple Calendar, URL scheme for Google Calendar

    private func addToCalendar(title: String, location: String, start: Date, end: Date) {
        pendingCalendarEvent = (title: title, location: location, start: start, end: end)
        showCalendarActionSheet = true
        HapticManager.impact(.medium)
    }

    // v280: Apple Calendar integration with iOS 16/17 API compatibility
    private func addToAppleCalendar(title: String, location: String, start: Date, end: Date) {
        let eventStore = EKEventStore()

        let saveEvent: (Bool) -> Void = { granted in
            DispatchQueue.main.async {
                if granted {
                    let event = EKEvent(eventStore: eventStore)
                    event.title = title
                    event.location = location
                    event.startDate = start
                    event.endDate = end
                    event.calendar = eventStore.defaultCalendarForNewEvents

                    do {
                        try eventStore.save(event, span: .thisEvent)
                        ToastManager.shared.success("Added to Apple Calendar")
                    } catch {
                        ToastManager.shared.error("Failed to add to calendar")
                    }
                } else {
                    ToastManager.shared.error("Calendar access denied. Enable in Settings.")
                }
            }
        }

        // Use appropriate API based on iOS version
        if #available(iOS 17.0, *) {
            eventStore.requestFullAccessToEvents { granted, _ in
                saveEvent(granted)
            }
        } else {
            eventStore.requestAccess(to: .event) { granted, _ in
                saveEvent(granted)
            }
        }
    }

    // v280: Google Calendar integration via URL scheme (opens in Safari/Google Calendar app)
    private func addToGoogleCalendar(title: String, location: String, start: Date, end: Date) {
        // Format dates for Google Calendar URL
        let dateFormatter = DateFormatter()
        dateFormatter.dateFormat = "yyyyMMdd'T'HHmmss"
        dateFormatter.timeZone = TimeZone.current

        let startString = dateFormatter.string(from: start)
        let endString = dateFormatter.string(from: end)

        // Build Google Calendar URL
        var components = URLComponents(string: "https://calendar.google.com/calendar/render")!
        components.queryItems = [
            URLQueryItem(name: "action", value: "TEMPLATE"),
            URLQueryItem(name: "text", value: title),
            URLQueryItem(name: "dates", value: "\(startString)/\(endString)"),
            URLQueryItem(name: "location", value: location),
            URLQueryItem(name: "details", value: "Open house for property at \(location)")
        ]

        if let url = components.url {
            UIApplication.shared.open(url)
        }
    }

    // MARK: - Facts & Features Section (Collapsible)
    // v304 FIX: Split into smaller functions using AnyView type erasure to prevent
    // stack overflow from excessive SwiftUI ViewBuilder type inference recursion.
    // See crash log: "Thread stack size exceeded due to excessive recursion"

    private func factsAndFeaturesSection(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("Facts & Features")
                .font(.headline)
                .padding(.bottom, 12)

            // Split into groups to reduce type complexity
            factsGroup1(property)
            factsGroup2(property)
            factsGroup3(property)
        }
    }

    // Group 1: Rental, Investment, Interior, Exterior
    private func factsGroup1(_ property: PropertyDetail) -> some View {
        Group {
            // Rental Details (for lease properties)
            if hasRentalDetails(property) && !propertyCategory.hiddenSections.contains(.rentalDetails) {
                rentalDetailsSection(property)
            }

            // Investment Metrics (for multi-family properties)
            if hasInvestmentMetrics(property) && !propertyCategory.hiddenSections.contains(.investmentMetrics) {
                investmentMetricsSection(property)
            }

            // Interior Features
            if hasInteriorFeatures(property) && !propertyCategory.hiddenSections.contains(.interior) {
                interiorFeaturesSection(property)
            }

            // Exterior Features
            if hasExteriorFeatures(property) && !propertyCategory.hiddenSections.contains(.exterior) {
                exteriorFeaturesSection(property)
            }
        }
    }

    // Group 2: Lot, Parking, HOA, Financial, Utilities
    private func factsGroup2(_ property: PropertyDetail) -> some View {
        Group {
            // Lot & Land
            if hasLotFeatures(property) && !propertyCategory.hiddenSections.contains(.lotLand) {
                lotFeaturesSection(property)
            }

            // Parking & Garage
            if hasParkingFeatures(property) && !propertyCategory.hiddenSections.contains(.parking) {
                parkingFeaturesSection(property)
            }

            // HOA & Community
            if hasHoaFeatures(property) && !propertyCategory.hiddenSections.contains(.hoaCommunity) {
                hoaFeaturesSection(property)
            }

            // Financial & Tax
            if hasFinancialFeatures(property) && !propertyCategory.hiddenSections.contains(.financial) {
                financialFeaturesSection(property)
            }

            // Utilities & Systems
            if hasUtilitiesFeatures(property) && !propertyCategory.hiddenSections.contains(.utilities) {
                utilitiesFeaturesSection(property)
            }
        }
    }

    // Group 3: Schools, Rooms, Disclosures, Additional
    private func factsGroup3(_ property: PropertyDetail) -> some View {
        Group {
            // Nearby Schools (dynamic from BMN Schools API)
            if !propertyCategory.hiddenSections.contains(.schools) {
                schoolsSection(property)
            }

            // Rooms / Floor Layout (v313: Also check if any room has meaningful data)
            // v351: Also check if we have adequate room data relative to property size
            if let rooms = property.rooms, !rooms.isEmpty, !propertyCategory.hiddenSections.contains(.floorLayout) {
                // Only show section if at least one room has meaningful data AND we have adequate coverage
                if rooms.contains(where: { roomHasMeaningfulData($0) }) && hasAdequateFloorData(property, rooms: rooms) {
                    floorLayoutSection(property, rooms: rooms)
                }
            }

            // Disclosures (MA-specific fields)
            if hasDisclosures(property) && !propertyCategory.hiddenSections.contains(.disclosures) {
                disclosuresSection(property)
            }

            // Additional Details
            if hasAdditionalDetails(property) && !propertyCategory.hiddenSections.contains(.additionalDetails) {
                additionalDetailsSection(property)
            }
        }
    }

    // MARK: - Individual Feature Sections (Type-Erased)

    private func interiorFeaturesSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.interior.title(for: propertyCategory),
            icon: FactSection.interior.icon,
            isExpanded: sectionBinding(.interior)
        ) {
            interiorFeaturesContent(property)
        }
    }

    private func interiorFeaturesContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            // Area Breakdown (moved from Additional Details per v314)
            if let aboveGrade = property.aboveGradeFinishedArea, aboveGrade > 0 {
                FeatureRow(label: "Above Grade Finished", value: "\(aboveGrade.formatted()) sqft")
            }
            if let belowGrade = property.belowGradeFinishedArea, belowGrade > 0 {
                FeatureRow(label: "Below Grade Finished", value: "\(belowGrade.formatted()) sqft")
            }

            // Heating with zones
            if let heating = property.heating {
                if let zones = property.heatZones, zones > 1 {
                    FeatureRow(label: "Heating", value: "\(heating) (\(zones) zones)")
                } else {
                    FeatureRow(label: "Heating", value: heating)
                }
            }
            // Cooling with zones
            if let cooling = property.cooling {
                if let zones = property.coolZones, zones > 1 {
                    FeatureRow(label: "Cooling", value: "\(cooling) (\(zones) zones)")
                } else {
                    FeatureRow(label: "Cooling", value: cooling)
                }
            }
            if let flooring = property.flooring { FeatureRow(label: "Flooring", value: flooring) }
            if let appliances = property.appliances { FeatureRow(label: "Appliances", value: appliances) }
            // Fireplace with count
            if let fireplaces = property.fireplacesTotal, fireplaces > 0 {
                if let features = property.fireplaceFeatures {
                    FeatureRow(label: "Fireplace", value: "\(fireplaces) (\(features))")
                } else {
                    FeatureRow(label: "Fireplaces", value: "\(fireplaces)")
                }
            } else if let fireplace = property.fireplaceFeatures {
                FeatureRow(label: "Fireplace", value: fireplace)
            }
            if let basement = property.basement { FeatureRow(label: "Basement", value: basement) }
            if let laundry = property.laundryFeatures { FeatureRow(label: "Laundry", value: laundry) }
            if let windows = property.windowFeatures { FeatureRow(label: "Windows", value: windows) }
            if let doors = property.doorFeatures { FeatureRow(label: "Doors", value: doors) }
            if let attic = property.attic { FeatureRow(label: "Attic", value: attic) }
            if let insulation = property.insulation { FeatureRow(label: "Insulation", value: insulation) }
            if let security = property.securityFeatures { FeatureRow(label: "Security", value: security) }
            if let accessibility = property.accessibilityFeatures { FeatureRow(label: "Accessibility", value: accessibility) }
            if let levels = property.levels { FeatureRow(label: "Levels", value: levels) }
            if let roomsTotal = property.roomsTotal { FeatureRow(label: "Total Rooms", value: "\(roomsTotal)") }
            if let interior = property.interiorFeatures { FeatureRow(label: "Other", value: interior) }
        }
    }

    private func exteriorFeaturesSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.exterior.title(for: propertyCategory),
            icon: FactSection.exterior.icon,
            isExpanded: sectionBinding(.exterior)
        ) {
            exteriorFeaturesContent(property)
        }
    }

    private func exteriorFeaturesContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if let construction = property.constructionMaterials { FeatureRow(label: "Construction", value: construction) }
            // Style field now includes Attached/Detached prefix (v315)
            let attachedPrefix = property.propertyAttachedYn ? "Attached" : "Detached"
            if let style = property.architecturalStyle {
                FeatureRow(label: "Style", value: "\(attachedPrefix) - \(style)")
            } else {
                FeatureRow(label: "Style", value: attachedPrefix)
            }
            if let roof = property.roof { FeatureRow(label: "Roof", value: roof) }
            if let foundation = property.foundationDetails { FeatureRow(label: "Foundation", value: foundation) }
            if let commonWalls = property.commonWalls { FeatureRow(label: "Common Walls", value: commonWalls) }
            if let exterior = property.exteriorFeatures { FeatureRow(label: "Exterior", value: exterior) }
            if let patio = property.patioAndPorchFeatures { FeatureRow(label: "Patio/Porch", value: patio) }
            if let pool = property.poolFeatures { FeatureRow(label: "Pool", value: pool) }
            if let spa = property.spaFeatures { FeatureRow(label: "Spa", value: spa) }
            if let waterfront = property.waterfrontFeatures { FeatureRow(label: "Waterfront", value: waterfront) }
            if let view = property.view { FeatureRow(label: "View", value: view) }
            if let fencing = property.fencing { FeatureRow(label: "Fencing", value: fencing) }
        }
    }

    private func lotFeaturesSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.lotLand.title(for: propertyCategory),
            icon: FactSection.lotLand.icon,
            isExpanded: sectionBinding(.lotLand)
        ) {
            lotFeaturesContent(property)
        }
    }

    private func lotFeaturesContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if let lotSize = property.formattedLotSize { FeatureRow(label: "Lot Size", value: lotSize) }
            if let sqft = property.lotSizeSquareFeet { FeatureRow(label: "Lot Sqft", value: "\(sqft.formatted()) sqft") }
            if let dimensions = property.lotSizeDimensions { FeatureRow(label: "Dimensions", value: dimensions) }
            if let features = property.lotFeatures { FeatureRow(label: "Features", value: features) }
            if let frontage = property.frontageType { FeatureRow(label: "Frontage Type", value: frontage) }
            if let frontageLength = property.frontageLength { FeatureRow(label: "Frontage Length", value: "\(frontageLength) ft") }
            if let road = property.roadSurfaceType { FeatureRow(label: "Road Surface", value: road) }
            if let topography = property.topography { FeatureRow(label: "Topography", value: topography) }
            if let vegetation = property.vegetation { FeatureRow(label: "Vegetation", value: vegetation) }
            // v309: Land area breakdown (for farm/rural properties)
            if let pasture = property.pastureArea, pasture > 0 {
                FeatureRow(label: "Pasture Area", value: String(format: "%.1f acres", pasture))
            }
            if let cultivated = property.cultivatedArea, cultivated > 0 {
                FeatureRow(label: "Cultivated Area", value: String(format: "%.1f acres", cultivated))
            }
            if let wooded = property.woodedArea, wooded > 0 {
                FeatureRow(label: "Wooded Area", value: String(format: "%.1f acres", wooded))
            }
            if property.horseYn { FeatureRow(label: "Horse Property", value: "Yes") }
            if let horseAmenities = property.horseAmenities { FeatureRow(label: "Horse Amenities", value: horseAmenities) }
            if property.landLeaseYn {
                FeatureRow(label: "Land Lease", value: "Yes")
                if let amount = property.landLeaseAmount { FeatureRow(label: "Lease Amount", value: "$\(Int(amount))") }
            }
        }
    }

    private func parkingFeaturesSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.parking.title(for: propertyCategory),
            icon: FactSection.parking.icon,
            isExpanded: sectionBinding(.parking)
        ) {
            parkingFeaturesContent(property)
        }
    }

    private func parkingFeaturesContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if let garage = property.garageSpaces, garage > 0 { FeatureRow(label: "Garage Spaces", value: "\(garage)") }
            if property.attachedGarageYn { FeatureRow(label: "Attached Garage", value: "Yes") }
            if let total = property.parkingTotal, total > 0 { FeatureRow(label: "Total Parking", value: "\(total)") }
            if let covered = property.coveredSpaces, covered > 0 { FeatureRow(label: "Covered Spaces", value: "\(covered)") }
            if let open = property.openParkingSpaces, open > 0 { FeatureRow(label: "Open Spaces", value: "\(open)") }
            if let carport = property.carportSpaces, carport > 0 { FeatureRow(label: "Carport Spaces", value: "\(carport)") }
            if let driveway = property.drivewaySurface { FeatureRow(label: "Driveway", value: driveway) }
            if let features = property.parkingFeatures { FeatureRow(label: "Features", value: features) }
        }
    }

    private func hoaFeaturesSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.hoaCommunity.title(for: propertyCategory),
            icon: FactSection.hoaCommunity.icon,
            isExpanded: sectionBinding(.hoaCommunity)
        ) {
            hoaFeaturesContent(property)
        }
    }

    // v314: Added masterAssociationFee and condoAssociationFee
    private func hoaFeaturesContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            // HOA Fees
            if let hoaFee = property.formattedHoaFee { FeatureRow(label: "HOA Fee", value: hoaFee) }
            if let fee2 = property.associationFee2, fee2 > 0 {
                FeatureRow(label: "2nd HOA Fee", value: "$\(Int(fee2))/\((property.associationFee2Frequency ?? "month").lowercased())")
            }
            if let masterFee = property.masterAssociationFee, masterFee > 0 {
                FeatureRow(label: "Master HOA Fee", value: "$\(Int(masterFee))")
            }
            if let condoFee = property.condoAssociationFee, condoFee > 0 {
                FeatureRow(label: "Condo Association Fee", value: "$\(Int(condoFee))")
            }
            if let optionalFee = property.optionalFee, optionalFee > 0 {
                let includesText = property.optionalFeeIncludes.map { " (\($0))" } ?? ""
                FeatureRow(label: "Optional Fee", value: "$\(Int(optionalFee))\(includesText)")
            }

            // What HOA Fee Includes
            if let includes = property.associationFeeIncludes, !includes.isEmpty {
                FeatureRow(label: "Fee Includes", value: includes)
            }

            // Association Details
            if let name = property.associationName { FeatureRow(label: "Association", value: name) }
            if let phone = property.associationPhone { FeatureRow(label: "HOA Phone", value: phone) }
            if let amenities = property.associationAmenities { FeatureRow(label: "Amenities", value: amenities) }
            if let community = property.communityFeatures { FeatureRow(label: "Community", value: community) }

            // Owner-Occupied Units
            if let ownerOccupied = property.ownerOccupiedUnits, ownerOccupied > 0 {
                FeatureRow(label: "Owner-Occupied Units", value: "\(ownerOccupied)")
            }

            // Pet Policy
            if let pets = property.petsAllowed { FeatureRow(label: "Pets", value: pets) }
            if let petRestrictions = property.petRestrictions { FeatureRow(label: "Pet Restrictions", value: petRestrictions) }

            // Senior Community
            if property.seniorCommunity { FeatureRow(label: "Senior Community", value: "Yes (55+)") }
        }
    }

    private func financialFeaturesSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.financial.title(for: propertyCategory),
            icon: FactSection.financial.icon,
            isExpanded: sectionBinding(.financial)
        ) {
            financialFeaturesContent(property)
        }
    }

    private func financialFeaturesContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if let tax = property.formattedTaxAnnual { FeatureRow(label: "Annual Tax", value: tax) }
            if let assessed = property.taxAssessedValue, assessed > 0 { FeatureRow(label: "Assessed Value", value: "$\(Int(assessed).formatted())") }
            if let pricePerSqft = property.pricePerSqft { FeatureRow(label: "Price/Sqft", value: "$\(pricePerSqft)") }
            if let parcel = property.parcelNumber { FeatureRow(label: "Parcel #", value: parcel) }
            if let zoning = property.zoning { FeatureRow(label: "Zoning", value: zoning) }
            if let zoningDesc = property.zoningDescription { FeatureRow(label: "Zoning Details", value: zoningDesc) }
        }
    }

    private func utilitiesFeaturesSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.utilities.title(for: propertyCategory),
            icon: FactSection.utilities.icon,
            isExpanded: sectionBinding(.utilities)
        ) {
            utilitiesFeaturesContent(property)
        }
    }

    // v314: Added greenSustainability and electricOnPropertyYn
    private func utilitiesFeaturesContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if let utilities = property.utilities { FeatureRow(label: "Utilities", value: utilities) }
            if let water = property.waterSource { FeatureRow(label: "Water", value: water) }
            if let sewer = property.sewer { FeatureRow(label: "Sewer", value: sewer) }
            if let electric = property.electric { FeatureRow(label: "Electric", value: electric) }
            if property.electricOnPropertyYn { FeatureRow(label: "Electric On Site", value: "Yes") }
            if let gas = property.gas { FeatureRow(label: "Gas", value: gas) }
            if let internet = property.internetType { FeatureRow(label: "Internet", value: internet) }
            if property.cableAvailableYn { FeatureRow(label: "Cable", value: "Available") }
            if let smart = property.smartHomeFeatures { FeatureRow(label: "Smart Home", value: smart) }
            if let energy = property.energyFeatures { FeatureRow(label: "Energy", value: energy) }
            // v309: Green building features
            if let green = property.greenBuildingCertification { FeatureRow(label: "Green Cert", value: green) }
            if let greenRating = property.greenCertificationRating { FeatureRow(label: "Green Rating", value: greenRating) }
            if let greenEfficient = property.greenEnergyEfficient { FeatureRow(label: "Energy Efficient", value: greenEfficient) }
            if let greenSustain = property.greenSustainability { FeatureRow(label: "Sustainability", value: greenSustain) }
        }
    }

    @ViewBuilder
    private func schoolsSection(_ property: PropertyDetail) -> some View {
        if let lat = property.latitude, let lng = property.longitude {
            NearbySchoolsSection(latitude: lat, longitude: lng, city: property.city)
        } else if hasSchoolsFeatures(property) {
            // Fallback to static MLS data if no coordinates
            CollapsibleSection(
                title: FactSection.schools.title(for: propertyCategory),
                icon: FactSection.schools.icon,
                isExpanded: sectionBinding(.schools)
            ) {
                schoolsFeaturesContent(property)
            }
        }
    }

    private func schoolsFeaturesContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if let district = property.schoolDistrict { FeatureRow(label: "District", value: district) }
            if let elementary = property.elementarySchool { FeatureRow(label: "Elementary", value: elementary) }
            if let middle = property.middleOrJuniorSchool { FeatureRow(label: "Middle", value: middle) }
            if let high = property.highSchool { FeatureRow(label: "High School", value: high) }
        }
    }

    // v316: Floor Layout section now uses interactive floor diagram
    // v316+: Added entry points for full-screen modal (tap + button)
    private func floorLayoutSection(_ property: PropertyDetail, rooms: [Room]) -> some View {
        CollapsibleSection(
            title: FactSection.floorLayout.title(for: propertyCategory),
            icon: FactSection.floorLayout.icon,
            isExpanded: sectionBinding(.floorLayout),
            trailingContent: {
                // View Full Layout button
                Button {
                    showFloorLayoutModal = true
                } label: {
                    HStack(spacing: 4) {
                        Image(systemName: "arrow.up.left.and.arrow.down.right")
                            .font(.system(size: 10, weight: .semibold))
                        Text("Full View")
                            .font(.caption2)
                            .fontWeight(.semibold)
                    }
                    .foregroundStyle(AppColors.brandTeal)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(AppColors.brandTeal.opacity(0.1))
                    .clipShape(Capsule())
                }
            }
        ) {
            floorLayoutContent(property, rooms: rooms)
                .contentShape(Rectangle())
                .onTapGesture {
                    showFloorLayoutModal = true
                }
        }
    }

    // Helper to check if a room has meaningful data beyond just the room type
    private func roomHasMeaningfulData(_ room: Room) -> Bool {
        room.level != nil || room.dimensions != nil || room.features != nil || room.description != nil
    }

    /// v351: Determines if there's enough room data to show a meaningful floor layout
    /// Compares rooms with level data to the expected room count from listing
    /// Hides floor layout for large properties with sparse room data
    private func hasAdequateFloorData(_ property: PropertyDetail, rooms: [Room]) -> Bool {
        // Count rooms that actually have floor level assignments
        let roomsWithLevel = rooms.filter { room in
            room.hasLevel == true || (room.level != nil && !room.level!.isEmpty)
        }.count

        // If we have very few rooms with level data, check against property size
        let beds = property.beds
        let baths = Int(ceil(property.baths))
        let expectedKeyRooms = beds + baths

        // Minimum threshold: at least 3 rooms with level, or half the expected rooms
        let minimumRooms = max(3, expectedKeyRooms / 2)

        // For very small units (studio, 1-bed), lower the threshold
        let adjustedMinimum = expectedKeyRooms <= 3 ? 2 : minimumRooms

        return roomsWithLevel >= adjustedMinimum
    }

    // v316: Interactive floor diagram with expandable floor segments
    // v316+: Smart property-aware diagram that adapts to property type
    private func floorLayoutContent(_ property: PropertyDetail, rooms: [Room]) -> some View {
        SmartFloorDiagramView(
            property: property,
            rooms: rooms
        )
    }

    private func additionalDetailsSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.additionalDetails.title(for: propertyCategory),
            icon: FactSection.additionalDetails.icon,
            isExpanded: sectionBinding(.additionalDetails)
        ) {
            additionalDetailsContent(property)
        }
    }

    // v313/v314: Expanded Additional Details section with more fields from database
    private func additionalDetailsContent(_ property: PropertyDetail) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            // Structure & Property Type Details
            if let structureType = property.structureType { FeatureRow(label: "Structure Type", value: structureType) }
            if let condition = property.propertyCondition { FeatureRow(label: "Condition", value: condition) }
            if let yearEffective = property.yearBuiltEffective { FeatureRow(label: "Effective Year Built", value: "\(yearEffective)") }
            if let yearSource = property.yearBuiltSource { FeatureRow(label: "Year Built Source", value: yearSource) }
            if let yearDetails = property.yearBuiltDetails { FeatureRow(label: "Year Built Details", value: yearDetails) }

            // Building Info
            if let building = property.buildingName { FeatureRow(label: "Building Name", value: building) }
            if let buildingFeatures = property.buildingFeatures { FeatureRow(label: "Building Features", value: buildingFeatures) }
            // Note: Attached field moved to Exterior & Structure section per v314
            if let foundationArea = property.foundationArea, foundationArea > 0 {
                FeatureRow(label: "Foundation Area", value: "\(foundationArea.formatted()) sqft")
            }

            // Area (Above/Below Grade moved to Interior Features per v314)
            if let totalArea = property.totalArea, totalArea > 0 {
                FeatureRow(label: "Total Area", value: "\(totalArea.formatted()) sqft")
            }

            // Level/Floor Details
            if let entryLevel = property.entryLevel { FeatureRow(label: "Entry Level", value: entryLevel) }
            if let entryLocation = property.entryLocation { FeatureRow(label: "Entry Location", value: entryLocation) }
            if let mainBeds = property.mainLevelBedrooms, mainBeds > 0 {
                FeatureRow(label: "Main Level Bedrooms", value: "\(mainBeds)")
            }
            if let mainBaths = property.mainLevelBathrooms, mainBaths > 0 {
                FeatureRow(label: "Main Level Bathrooms", value: "\(mainBaths)")
            }
            if let masterLevel = property.masterBedroomLevel { FeatureRow(label: "Primary Bedroom Level", value: masterLevel) }
            if let otherRooms = property.otherRooms { FeatureRow(label: "Other Rooms", value: otherRooms) }

            // Ownership & Occupancy
            if let ownership = property.ownership { FeatureRow(label: "Ownership", value: ownership) }
            if let occupant = property.occupantType { FeatureRow(label: "Occupant Type", value: occupant) }
            if let possession = property.possession { FeatureRow(label: "Possession", value: possession) }

            // Property Characteristics
            if property.yearRound { FeatureRow(label: "Year Round", value: "Yes") }
            if property.homeWarranty { FeatureRow(label: "Home Warranty", value: "Included") }
            if property.lenderOwned { FeatureRow(label: "Lender Owned", value: "Yes (Bank-Owned/REO)") }
            if let devStatus = property.developmentStatus { FeatureRow(label: "Development Status", value: devStatus) }

            // Waterfront Details (if applicable)
            if let waterBody = property.waterBodyName { FeatureRow(label: "Water Body", value: waterBody) }

            // Land/Lot Details
            if let numLots = property.numberOfLots, numLots > 1 {
                FeatureRow(label: "Number of Lots", value: "\(numLots)")
            }

            // Additional Parcels
            if property.additionalParcelsYn {
                FeatureRow(label: "Additional Parcels", value: "Yes")
                if let parcelsDesc = property.additionalParcelsDescription {
                    FeatureRow(label: "Parcels Description", value: parcelsDesc)
                }
            }

            // Inclusions & Exclusions
            if let inclusions = property.inclusions { FeatureRow(label: "Inclusions", value: inclusions) }
            if let exclusions = property.exclusions { FeatureRow(label: "Exclusions", value: exclusions) }

            // Listing Terms & Conditions
            if let disclosures = property.disclosures { FeatureRow(label: "Disclosures", value: disclosures) }
            if let terms = property.listingTerms { FeatureRow(label: "Listing Terms", value: terms) }
            if let special = property.specialListingConditions { FeatureRow(label: "Special Conditions", value: special) }
            if let roadResp = property.roadResponsibility { FeatureRow(label: "Road Responsibility", value: roadResp) }

            // Listing Info
            if let listingService = property.listingService { FeatureRow(label: "Listing Service", value: listingService) }
            if let showingDefer = property.showingDeferralDate { FeatureRow(label: "Showing Deferral Date", value: showingDefer) }
        }
    }

    // Helper functions to check if sections have content
    private func hasInteriorFeatures(_ p: PropertyDetail) -> Bool {
        p.heating != nil || p.cooling != nil || p.flooring != nil || p.appliances != nil ||
        p.fireplaceFeatures != nil || (p.fireplacesTotal ?? 0) > 0 || p.basement != nil ||
        p.laundryFeatures != nil || p.interiorFeatures != nil
    }

    private func hasExteriorFeatures(_ p: PropertyDetail) -> Bool {
        p.constructionMaterials != nil || p.architecturalStyle != nil || p.roof != nil ||
        p.foundationDetails != nil || p.exteriorFeatures != nil || p.patioAndPorchFeatures != nil ||
        p.poolFeatures != nil || p.spaFeatures != nil || p.waterfrontFeatures != nil ||
        p.view != nil || p.fencing != nil || p.commonWalls != nil ||
        true // Always show Exterior section so Attached field is visible (v315)
    }

    private func hasLotFeatures(_ p: PropertyDetail) -> Bool {
        p.lotSizeAcres != nil || p.lotSize != nil || p.lotSizeDimensions != nil || p.lotFeatures != nil
    }

    private func hasParkingFeatures(_ p: PropertyDetail) -> Bool {
        (p.garageSpaces ?? 0) > 0 || (p.parkingTotal ?? 0) > 0 || (p.coveredSpaces ?? 0) > 0 ||
        (p.openParkingSpaces ?? 0) > 0 || p.parkingFeatures != nil
    }

    private func hasHoaFeatures(_ p: PropertyDetail) -> Bool {
        p.hoaFee != nil || p.associationAmenities != nil || p.petsAllowed != nil || p.seniorCommunity
    }

    private func hasFinancialFeatures(_ p: PropertyDetail) -> Bool {
        p.taxAnnual != nil || p.pricePerSqft != nil || p.taxAssessedValue != nil ||
        p.parcelNumber != nil || p.zoning != nil
    }

    private func hasUtilitiesFeatures(_ p: PropertyDetail) -> Bool {
        p.utilities != nil || p.waterSource != nil || p.sewer != nil || p.electric != nil ||
        p.gas != nil || p.internetType != nil || p.cableAvailableYn || p.smartHomeFeatures != nil ||
        p.energyFeatures != nil || p.greenBuildingCertification != nil
    }

    private func hasSchoolsFeatures(_ p: PropertyDetail) -> Bool {
        p.schoolDistrict != nil || p.elementarySchool != nil || p.middleOrJuniorSchool != nil || p.highSchool != nil
    }

    // v313/v314: Expanded check to include all new Additional Details fields
    private func hasAdditionalDetails(_ p: PropertyDetail) -> Bool {
        // Structure & Property Type
        p.structureType != nil || p.propertyCondition != nil ||
        p.yearBuiltEffective != nil || p.yearBuiltSource != nil || p.yearBuiltDetails != nil ||
        // Building Info (Note: propertyAttachedYn moved to Exterior per v314)
        p.buildingName != nil || p.buildingFeatures != nil ||
        (p.foundationArea ?? 0) > 0 ||
        // Area (Note: Above/Below Grade moved to Interior per v314, but Total Area stays here)
        (p.totalArea ?? 0) > 0 ||
        // Level/Floor Details
        p.entryLevel != nil || p.entryLocation != nil ||
        (p.mainLevelBedrooms ?? 0) > 0 || (p.mainLevelBathrooms ?? 0) > 0 ||
        p.masterBedroomLevel != nil || p.otherRooms != nil ||
        // Ownership & Occupancy
        p.ownership != nil || p.occupantType != nil || p.possession != nil ||
        // Property Characteristics
        p.yearRound || p.homeWarranty || p.lenderOwned || p.developmentStatus != nil ||
        // Waterfront Details
        p.waterBodyName != nil ||
        // Land/Lot Details
        (p.numberOfLots ?? 0) > 1 ||
        // Additional Parcels
        p.additionalParcelsYn || p.additionalParcelsDescription != nil ||
        // Inclusions & Exclusions
        p.inclusions != nil || p.exclusions != nil ||
        // Listing Terms & Conditions
        p.disclosures != nil || p.listingTerms != nil || p.specialListingConditions != nil ||
        p.roadResponsibility != nil ||
        // Listing Info
        p.listingService != nil || p.showingDeferralDate != nil
    }

    // MARK: - Payment Calculator Section

    // v308: Monthly Payment Calculator wrapped in CollapsibleSection
    // Hidden for rental properties (lease listings don't need mortgage calculator)
    @ViewBuilder
    private func paymentCalculatorSection(_ property: PropertyDetail) -> some View {
        if !propertyCategory.hiddenSections.contains(.monthlyPayment) {
            CollapsibleSection(
                title: FactSection.monthlyPayment.title(for: propertyCategory),
                icon: FactSection.monthlyPayment.icon,
                isExpanded: sectionBinding(.monthlyPayment)
            ) {
                PaymentCalculatorContentView(
                    propertyPrice: property.price,
                    annualTax: property.taxAnnual,
                    hoaFee: property.hoaFee,
                    hoaFrequency: property.hoaFeeFrequency
                )
            }
        }
    }

    // MARK: - Listing Agent Section (Name/Office only for clients, full contact for agents)

    private func agentSection(_ property: PropertyDetail) -> some View {
        // NOTE: Per CLAUDE.md Pitfall #31, use helper instead of let in ViewBuilder
        agentSectionContent(property: property, isAgentUser: isCurrentUserAgent())
    }

    private func isCurrentUserAgent() -> Bool {
        authViewModel.currentUser?.isAgent == true
    }

    @ViewBuilder
    private func agentSectionContent(property: PropertyDetail, isAgentUser: Bool) -> some View {
        Group {
            if let agent = property.listingAgent {
                VStack(alignment: .leading, spacing: 12) {
                    Text("Listing Agent")
                        .font(.headline)

                    HStack(spacing: 16) {
                        // Agent photo
                        if let photoUrl = agent.photoUrl, let url = URL(string: photoUrl) {
                            AsyncImage(url: url) { phase in
                                switch phase {
                                case .success(let image):
                                    image
                                        .resizable()
                                        .aspectRatio(contentMode: .fill)
                                case .failure, .empty:
                                    agentPlaceholderSmall
                                @unknown default:
                                    agentPlaceholderSmall
                                }
                            }
                            .frame(width: 60, height: 60)
                            .clipShape(Circle())
                        } else {
                            agentPlaceholderSmall
                        }

                        VStack(alignment: .leading, spacing: 4) {
                            Text(agent.displayName)
                                .font(.subheadline)
                                .fontWeight(.semibold)

                            if let office = agent.officeName {
                                Text(office)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }

                            // Only show phone for agent users
                            if isAgentUser, let phone = agent.phone {
                                Text(phone)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }

                        Spacer()

                        // Quick action buttons - ONLY for agent users
                        if isAgentUser {
                            if let phone = agent.phone {
                                Button {
                                    let cleanPhone = phone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)
                                    if let url = URL(string: "tel:\(cleanPhone)") {
                                        UIApplication.shared.open(url)
                                    }
                                } label: {
                                    Image(systemName: "phone.fill")
                                        .font(.title3)
                                        .foregroundStyle(AppColors.brandTeal)
                                        .frame(width: 44, height: 44)
                                        .background(AppColors.brandTeal.opacity(0.1))
                                        .clipShape(Circle())
                                }
                            }

                            if let email = agent.email {
                                Button {
                                    if let url = URL(string: "mailto:\(email)") {
                                        UIApplication.shared.open(url)
                                    }
                                } label: {
                                    Image(systemName: "envelope.fill")
                                        .font(.title3)
                                        .foregroundStyle(AppColors.brandTeal)
                                        .frame(width: 44, height: 44)
                                        .background(AppColors.brandTeal.opacity(0.1))
                                        .clipShape(Circle())
                                }
                            }
                        }
                    }
                    .padding()
                    .background(Color(.secondarySystemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 12))

                    // Agent-specific: Call/Text Agent buttons and agent-only info
                    if isAgentUser {
                        // Call and Text Agent buttons - side by side
                        if let phone = agent.phone {
                            let cleanPhone = phone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)

                            HStack(spacing: 12) {
                                // Call Agent button
                                Button {
                                    if let url = URL(string: "tel:\(cleanPhone)") {
                                        UIApplication.shared.open(url)
                                    }
                                } label: {
                                    Label("Call Agent", systemImage: "phone.fill")
                                        .font(.headline)
                                        .frame(maxWidth: .infinity)
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(AppColors.brandTeal)

                                // Text Agent button
                                Button {
                                    if let url = URL(string: "sms:\(cleanPhone)") {
                                        UIApplication.shared.open(url)
                                    }
                                } label: {
                                    Label("Text Agent", systemImage: "message.fill")
                                        .font(.headline)
                                        .frame(maxWidth: .infinity)
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(AppColors.brandTeal)
                            }
                        }

                        // Agent-only info (remarks, showing instructions)
                        if let agentInfo = property.agentOnlyInfo {
                            agentOnlyInfoSection(agentInfo, property: property)
                        }

                        // v386: Quick CMA button (agents only)
                        Button {
                            showCMASheet = true
                        } label: {
                            HStack {
                                Image(systemName: "chart.bar.doc.horizontal.fill")
                                Text("Generate CMA")
                            }
                            .font(.headline)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 14)
                        }
                        .buttonStyle(.borderedProminent)
                        .tint(AppColors.brandTeal)
                    }
                }
            }
        }
    }

    // MARK: - Agent-Only Info Section (for agent users)

    private func hasAgentOnlyContent(_ agentInfo: AgentOnlyInfo) -> Bool {
        agentInfo.showingInstructions != nil ||
        agentInfo.privateRemarks != nil ||
        agentInfo.privateOfficeRemarks != nil
    }

    @ViewBuilder
    private func agentOnlyInfoSection(_ agentInfo: AgentOnlyInfo, property: PropertyDetail) -> some View {
        // NOTE: Per CLAUDE.md Pitfall #31, use helper instead of let in ViewBuilder
        // Extract ShowingTime parameters for linkedText
        // v366: Use CURRENT USER's MLS Agent ID (the agent viewing the property), not the listing agent's ID
        let mlsNumber = property.mlsNumber
        let agentMlsId = authViewModel.currentUser?.mlsAgentId

        if hasAgentOnlyContent(agentInfo) {
            VStack(alignment: .leading, spacing: 12) {
                Text("Agent Information")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)

                VStack(alignment: .leading, spacing: 16) {
                    // Showing Instructions (v365: auto-linked phone/email/ShowingTime)
                    if let instructions = agentInfo.showingInstructions, !instructions.isEmpty {
                        VStack(alignment: .leading, spacing: 4) {
                            Label("Showing Instructions", systemImage: "key.fill")
                                .font(.caption)
                                .fontWeight(.semibold)
                                .foregroundStyle(AppColors.brandTeal)
                            linkedText(instructions, mlsNumber: mlsNumber, agentMlsId: agentMlsId)
                        }
                    }

                    // Private Remarks (v365: auto-linked phone/email/ShowingTime)
                    if let remarks = agentInfo.privateRemarks, !remarks.isEmpty {
                        VStack(alignment: .leading, spacing: 4) {
                            Label("Agent Remarks", systemImage: "text.bubble.fill")
                                .font(.caption)
                                .fontWeight(.semibold)
                                .foregroundStyle(.orange)
                            linkedText(remarks, mlsNumber: mlsNumber, agentMlsId: agentMlsId)
                        }
                    }

                    // Private Office Remarks (v365: auto-linked phone/email/ShowingTime)
                    if let officeRemarks = agentInfo.privateOfficeRemarks, !officeRemarks.isEmpty {
                        VStack(alignment: .leading, spacing: 4) {
                            Label("Office Remarks", systemImage: "building.2.fill")
                                .font(.caption)
                                .fontWeight(.semibold)
                                .foregroundStyle(.purple)
                            linkedText(officeRemarks, mlsNumber: mlsNumber, agentMlsId: agentMlsId)
                        }
                    }
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }

    // MARK: - Linked Text View (v365: Auto-detect phone numbers, emails, and ShowingTime)

    /// A view that displays text with auto-detected phone numbers, emails, and ShowingTime as tappable links
    private func linkedText(_ text: String, mlsNumber: String? = nil, agentMlsId: String? = nil) -> some View {
        LinkedTextView(
            text: text,
            onPhoneTap: { phone in
                selectedPhoneNumber = phone
                showPhoneActionSheet = true
            },
            onEmailTap: { email in
                if let url = URL(string: "mailto:\(email)") {
                    UIApplication.shared.open(url)
                }
            },
            mlsNumber: mlsNumber,
            agentMlsId: agentMlsId
        )
    }

    private var agentPlaceholderSmall: some View {
        Circle()
            .fill(AppColors.brandTeal.opacity(0.2))
            .frame(width: 60, height: 60)
            .overlay(
                Image(systemName: "person.fill")
                    .font(.title2)
                    .foregroundStyle(AppColors.brandTeal)
            )
    }

    // MARK: - Contact Section (Shows assigned agent for clients, team info for guests)
    // Note: Agent users see listing agent contact in agentSection, so this is hidden for them

    @ViewBuilder
    private var contactSection: some View {
        // NOTE: Per CLAUDE.md Pitfall #31, use helper instead of let in ViewBuilder
        // Don't show for agent users - they have full listing agent contact above
        if !isCurrentUserAgent() {
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    Text(myAgent != nil ? "Your Agent" : "Contact Us")
                        .font(.headline)
                    Spacer()
                    Text("Ask about this property")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                if let agent = myAgent {
                    // Client has assigned agent - show their agent
                    CompactAgentCard(agent: agent)
                } else {
                    // No assigned agent - show team info
                    teamContactCard
                }
            }
        }
    }

    // Team contact card for clients without assigned agent
    // Uses SiteContactManager (via @EnvironmentObject) to get contact info from WordPress theme settings
    private var teamContactCard: some View {
        HStack(spacing: 16) {
            // Team avatar (from theme settings or placeholder)
            if let photoUrl = siteContactManager.photoUrl, let url = URL(string: photoUrl) {
                AsyncImage(url: url) { phase in
                    switch phase {
                    case .success(let image):
                        image
                            .resizable()
                            .aspectRatio(contentMode: .fill)
                    case .failure, .empty:
                        teamPlaceholder
                    @unknown default:
                        teamPlaceholder
                    }
                }
                .frame(width: 60, height: 60)
                .clipShape(Circle())
            } else {
                teamPlaceholder
            }

            VStack(alignment: .leading, spacing: 4) {
                Text(siteContactManager.name)
                    .font(.subheadline)
                    .fontWeight(.semibold)

                Text(siteContactManager.email)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            Spacer()

            // Quick contact buttons
            Button {
                let cleanPhone = siteContactManager.phone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)
                if let url = URL(string: "tel:\(cleanPhone)") {
                    UIApplication.shared.open(url)
                }
            } label: {
                Image(systemName: "phone.fill")
                    .font(.title3)
                    .foregroundStyle(AppColors.brandTeal)
                    .frame(width: 44, height: 44)
                    .background(AppColors.brandTeal.opacity(0.1))
                    .clipShape(Circle())
            }

            Button {
                if let url = URL(string: "mailto:\(siteContactManager.email)") {
                    UIApplication.shared.open(url)
                }
            } label: {
                Image(systemName: "envelope.fill")
                    .font(.title3)
                    .foregroundStyle(AppColors.brandTeal)
                    .frame(width: 44, height: 44)
                    .background(AppColors.brandTeal.opacity(0.1))
                    .clipShape(Circle())
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private var teamPlaceholder: some View {
        Circle()
            .fill(AppColors.brandTeal.opacity(0.2))
            .frame(width: 60, height: 60)
            .overlay(
                Image(systemName: "person.3.fill")
                    .font(.title2)
                    .foregroundStyle(AppColors.brandTeal)
            )
    }

    // MARK: - Loading & Error States

    private func bottomSheetSkeleton(collapsedHeight: CGFloat) -> some View {
        VStack(spacing: 0) {
            // Handle
            Capsule()
                .fill(AppColors.dragHandle)
                .frame(width: 40, height: 5)
                .padding(.top, 10)
                .padding(.bottom, 16)

            VStack(alignment: .leading, spacing: 12) {
                SkeletonShape(width: 150, height: 28, cornerRadius: 4)
                SkeletonShape(height: 20, cornerRadius: 4)
                SkeletonShape(width: 200, height: 16, cornerRadius: 4)
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(height: collapsedHeight)
        .frame(maxWidth: .infinity)
        .background(
            RoundedRectangle(cornerRadius: 20)
                .fill(Color(.systemBackground))
                .shadow(color: AppColors.shadowMedium, radius: 10, y: -5)
        )
    }

    private func errorSheet(error: String, collapsedHeight: CGFloat) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "exclamationmark.triangle")
                .font(.title)
                .foregroundStyle(.secondary)
            Text("Error loading property")
                .font(.headline)
            Text(error)
                .font(.caption)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
        }
        .padding()
        .frame(height: collapsedHeight + 50)
        .frame(maxWidth: .infinity)
        .background(
            RoundedRectangle(cornerRadius: 20)
                .fill(Color(.systemBackground))
                .shadow(color: AppColors.shadowMedium, radius: 10, y: -5)
        )
    }

    private func loadProperty() async {
        isLoading = true

        do {
            let data: PropertyDetail = try await APIClient.shared.request(
                .propertyDetail(id: propertyId)
            )
            property = data
            // Apply smart defaults after property loads
            applySmartDefaults()
        } catch let error as APIError {
            errorMessage = error.errorDescription
        } catch {
            errorMessage = "Failed to load property details"
        }

        isLoading = false
    }

    // MARK: - Virtual Tour Button

    // NOTE: Per CLAUDE.md Pitfall #31, no let statements in ViewBuilder closures
    // Split into wrapper + content to avoid let statement
    private func virtualTourSection(_ property: PropertyDetail) -> some View {
        virtualTourContent(tours: getVirtualTours(property))
    }

    @ViewBuilder
    private func virtualTourContent(tours: [String]) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            if tours.count == 1 {
                // Single tour - show simple button
                Button {
                    selectedVirtualTourUrl = tours.first
                    showingVirtualTour = true
                } label: {
                    HStack {
                        Image(systemName: "view.3d")
                            .font(.title2)
                        Text("Take Virtual Tour")
                            .font(.headline)
                        Spacer()
                        Image(systemName: "chevron.right")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    .padding()
                    .background(AppColors.brandTeal.opacity(0.1))
                    .foregroundStyle(AppColors.brandTeal)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            } else {
                // Multiple tours - show list
                Text("Virtual Tours")
                    .font(.headline)

                ForEach(Array(tours.enumerated()), id: \.offset) { index, tourUrl in
                    Button {
                        selectedVirtualTourUrl = tourUrl
                        showingVirtualTour = true
                    } label: {
                        HStack {
                            Image(systemName: "view.3d")
                                .font(.title3)
                            Text("Virtual Tour \(index + 1)")
                                .font(.subheadline)
                            Spacer()
                            Image(systemName: "arrow.up.right.square")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        .padding()
                        .background(AppColors.brandTeal.opacity(0.1))
                        .foregroundStyle(AppColors.brandTeal)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                }
            }
        }
    }

    private func getVirtualTours(_ property: PropertyDetail) -> [String] {
        var tours: [String] = []

        // Add primary virtual tour URL if exists
        if let primaryTour = property.virtualTourUrl {
            tours.append(primaryTour)
        }

        // Add additional tours from array (if not duplicates)
        if let additionalTours = property.virtualTours {
            for tour in additionalTours {
                if !tours.contains(tour) {
                    tours.append(tour)
                }
            }
        }

        return tours
    }

    // MARK: - Share Items

    private func shareItems(for property: PropertyDetail) -> [Any] {
        var items: [Any] = []

        let shareText = """
        Check out this property!

        \(property.formattedPrice)
        \(property.fullAddress)
        \(property.beds) bed, \(Int(property.baths)) bath

        View on \(AppConstants.companyName)
        """
        items.append(shareText)

        // v212 FIX: Use MLS number (not listing_key hash) for correct URL
        // Before v212, this used property.id which could be the listing_key hash,
        // resulting in broken URLs like /property/928c77fa.../
        // Correct URL format: /property/73464868/ (MLS number)
        let propertyId = property.mlsNumber ?? property.id
        if let url = AppConstants.propertyURL(id: propertyId) {
            items.append(url)
        }

        return items
    }

    // MARK: - Rental Details Section

    private func hasRentalDetails(_ p: PropertyDetail) -> Bool {
        let isLease = p.propertyType.lowercased().contains("lease")
        let hasRentalData = p.availabilityDate != nil || p.availableNow ||
                           p.leaseTerm != nil || p.rentIncludes != nil ||
                           p.securityDeposit != nil || p.firstMonthRequired ||
                           p.lastMonthRequired || p.referencesRequired ||
                           p.depositRequired || p.insuranceRequired
        return isLease && hasRentalData
    }

    // Helper to parse rent includes and filter out empty strings (prevents ForEach crash with duplicate IDs)
    private func parseRentIncludes(_ includes: String) -> [String] {
        includes.components(separatedBy: ",")
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
    }

    private func rentalDetailsSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.rentalDetails.title(for: propertyCategory),
            icon: FactSection.rentalDetails.icon,
            isExpanded: sectionBinding(.rentalDetails)
        ) {
            VStack(alignment: .leading, spacing: 12) {
                // v302: Pet Policy Badges at TOP (prominent display)
                petPolicyBadges(property)

                // Availability
                if property.availableNow {
                    HStack(spacing: 8) {
                        Image(systemName: "checkmark.circle.fill")
                            .foregroundStyle(.green)
                        Text("Available Now")
                            .font(.subheadline)
                            .fontWeight(.medium)
                            .foregroundStyle(.green)
                    }
                } else if let date = property.availabilityDate {
                    FeatureRow(label: "Available", value: date)
                }

                // Lease Term
                if let term = property.leaseTerm {
                    FeatureRow(label: "Lease Term", value: term)
                }

                // What's Included in Rent
                if let includes = property.rentIncludes, !includes.isEmpty {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("Rent Includes")
                            .font(.caption)
                            .fontWeight(.medium)
                            .foregroundStyle(.secondary)
                        // Display as tags - filter empty strings and use enumerated for unique IDs
                        PropertyFlowLayout(spacing: 6) {
                            ForEach(Array(parseRentIncludes(includes).enumerated()), id: \.offset) { _, item in
                                Text(item)
                                    .font(.caption)
                                    .padding(.horizontal, 8)
                                    .padding(.vertical, 4)
                                    .background(AppColors.brandTeal.opacity(0.1))
                                    .foregroundStyle(AppColors.brandTeal)
                                    .clipShape(Capsule())
                            }
                        }
                    }
                }

                Divider()

                // Move-in Requirements
                Text("Move-in Requirements")
                    .font(.caption)
                    .fontWeight(.medium)
                    .foregroundStyle(.secondary)

                if let deposit = property.securityDeposit, deposit > 0 {
                    FeatureRow(label: "Security Deposit", value: "$\(Int(deposit).formatted())")
                }

                // Show required items as badges
                HStack(spacing: 8) {
                    if property.firstMonthRequired {
                        requirementBadge("1st Month", icon: "dollarsign.circle.fill", color: .orange)
                    }
                    if property.lastMonthRequired {
                        requirementBadge("Last Month", icon: "dollarsign.circle.fill", color: .orange)
                    }
                    if property.depositRequired {
                        requirementBadge("Deposit", icon: "banknote.fill", color: .orange)
                    }
                }

                HStack(spacing: 8) {
                    if property.referencesRequired {
                        requirementBadge("References", icon: "doc.text.fill", color: .blue)
                    }
                    if property.insuranceRequired {
                        requirementBadge("Insurance", icon: "shield.fill", color: .blue)
                    }
                }

                // v302: Pet Policy Details (also in list for full text)
                if let petsAllowed = property.petsAllowed, !petsAllowed.isEmpty {
                    Divider()
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Pet Policy Details")
                            .font(.caption)
                            .fontWeight(.medium)
                            .foregroundStyle(.secondary)
                        Text(petsAllowed)
                            .font(.subheadline)
                    }
                }
            }
        }
    }

    // MARK: - Pet Policy Badges (v302)
    // NOTE: Per CLAUDE.md Pitfall #31, no let statements allowed in ViewBuilder closures

    private func hasPetInfo(_ property: PropertyDetail) -> Bool {
        guard let petsAllowed = property.petsAllowed else { return false }
        return !petsAllowed.isEmpty
    }

    private func petsAllowedLowercased(_ property: PropertyDetail) -> String {
        property.petsAllowed?.lowercased() ?? ""
    }

    private func showDogsOK(_ petsAllowed: String) -> Bool {
        petsAllowed.contains("dog") || petsAllowed == "yes"
    }

    private func showCatsOK(_ petsAllowed: String) -> Bool {
        petsAllowed.contains("cat") || petsAllowed == "yes"
    }

    private func showNoPets(_ petsAllowed: String) -> Bool {
        petsAllowed.contains("no") && !petsAllowed.contains("negotiable")
    }

    private func showPetsWithRestrictions(_ petsAllowed: String) -> Bool {
        petsAllowed.contains("restriction") || petsAllowed.contains("negotiable") || petsAllowed.contains("conditional")
    }

    @ViewBuilder
    private func petPolicyBadges(_ property: PropertyDetail) -> some View {
        if hasPetInfo(property) {
            HStack(spacing: 8) {
                // Dogs OK
                if showDogsOK(petsAllowedLowercased(property)) {
                    petBadge("Dogs OK", icon: "dog.fill", color: .green)
                }
                // Cats OK
                if showCatsOK(petsAllowedLowercased(property)) {
                    petBadge("Cats OK", icon: "cat.fill", color: .green)
                }
                // No Pets
                if showNoPets(petsAllowedLowercased(property)) {
                    petBadge("No Pets", icon: "nosign", color: .red)
                }
                // Pets w/ Restrictions or Negotiable
                if showPetsWithRestrictions(petsAllowedLowercased(property)) {
                    petBadge("With Restrictions", icon: "exclamationmark.triangle.fill", color: .orange)
                }
            }
            .padding(.bottom, 8)
        }
    }

    private func petBadge(_ text: String, icon: String, color: Color) -> some View {
        HStack(spacing: 4) {
            Image(systemName: icon)
                .font(.system(size: 12))
            Text(text)
                .font(.caption)
                .fontWeight(.medium)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .background(color.opacity(0.15))
        .foregroundStyle(color)
        .clipShape(Capsule())
    }

    private func requirementBadge(_ text: String, icon: String, color: Color) -> some View {
        HStack(spacing: 4) {
            Image(systemName: icon)
                .font(.system(size: 10))
            Text(text)
                .font(.caption2)
                .fontWeight(.medium)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(color.opacity(0.1))
        .foregroundStyle(color)
        .clipShape(Capsule())
    }

    // MARK: - Currency Formatting Helper (v302)

    private func formatInvestmentCurrency(_ amount: Double) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: amount)) ?? "$\(Int(amount))"
    }

    // MARK: - Unit Rent Total Helper (v302)
    // NOTE: Per CLAUDE.md Pitfall #31, no let statements allowed in ViewBuilder closures

    private func calculateTotalRent(_ units: [UnitRent]) -> Double {
        units.reduce(0) { $0 + $1.rent }
    }

    // MARK: - Photo Count Helper (v302)

    private func getPhotoCount(_ property: PropertyDetail) -> Int {
        property.photoCount ?? property.imageURLs.count
    }

    // MARK: - Contact Sheet Agent Helper (v302)

    private func agentForContactSheet() -> Agent? {
        // For clients: show their assigned agent if they have one, otherwise team info
        // For agents: always nil (not applicable - they contact listing agent directly)
        (authViewModel.currentUser?.isAgent == true) ? nil : myAgent
    }

    // MARK: - Investment Metrics Section (v302)

    private func hasInvestmentMetrics(_ p: PropertyDetail) -> Bool {
        let isMultiFamily = p.propertyType.lowercased().contains("residential income") ||
                           p.propertyType.lowercased().contains("multi")
        let hasData = (p.unitRents != nil && !p.unitRents!.isEmpty) ||
                     p.grossIncome != nil ||
                     p.netOperatingIncome != nil ||
                     p.capRate != nil
        return isMultiFamily && hasData
    }

    private func investmentMetricsSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.investmentMetrics.title(for: propertyCategory),
            icon: FactSection.investmentMetrics.icon,
            isExpanded: sectionBinding(.investmentMetrics)
        ) {
            VStack(alignment: .leading, spacing: 16) {
                // Unit Rent Breakdown Table
                if let units = property.unitRents, !units.isEmpty {
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Unit Rent Breakdown")
                            .font(.caption)
                            .fontWeight(.medium)
                            .foregroundStyle(.secondary)

                        // Table header
                        HStack {
                            Text("Unit")
                                .font(.caption)
                                .fontWeight(.semibold)
                                .frame(width: 50, alignment: .leading)
                            Text("Monthly Rent")
                                .font(.caption)
                                .fontWeight(.semibold)
                                .frame(maxWidth: .infinity, alignment: .leading)
                            Text("Lease")
                                .font(.caption)
                                .fontWeight(.semibold)
                                .frame(width: 100, alignment: .trailing)
                        }
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(Color(.systemGray6))

                        // Table rows
                        ForEach(units) { unit in
                            HStack {
                                Text("#\(unit.unit)")
                                    .font(.subheadline)
                                    .frame(width: 50, alignment: .leading)
                                Text(unit.formattedRent)
                                    .font(.subheadline)
                                    .fontWeight(.medium)
                                    .foregroundStyle(AppColors.brandTeal)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                Text(unit.lease ?? "—")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                    .frame(width: 100, alignment: .trailing)
                            }
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                        }

                        // Total Monthly Rent
                        HStack {
                            Text("Total")
                                .font(.subheadline)
                                .fontWeight(.semibold)
                            Spacer()
                            Text(formatInvestmentCurrency(calculateTotalRent(units)))
                                .font(.subheadline)
                                .fontWeight(.bold)
                                .foregroundStyle(AppColors.brandTeal)
                            Text("/mo")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        .padding(.horizontal, 8)
                        .padding(.vertical, 8)
                        .background(AppColors.brandTeal.opacity(0.1))
                        .clipShape(RoundedRectangle(cornerRadius: 6))
                    }

                    Divider()
                        .padding(.vertical, 4)
                }

                // Investment Performance Metrics
                VStack(alignment: .leading, spacing: 8) {
                    Text("Investment Performance")
                        .font(.caption)
                        .fontWeight(.medium)
                        .foregroundStyle(.secondary)

                    if let grossIncome = property.grossIncome, grossIncome > 0 {
                        HStack {
                            Text("Gross Income (Annual)")
                                .font(.subheadline)
                            Spacer()
                            Text(formatInvestmentCurrency(grossIncome))
                                .font(.subheadline)
                                .fontWeight(.medium)
                        }
                    }

                    // v308: Total Actual Rent (monthly rent actually being collected)
                    if let totalActualRent = property.totalActualRent, totalActualRent > 0 {
                        HStack {
                            Text("Total Actual Rent")
                                .font(.subheadline)
                            Spacer()
                            HStack(spacing: 2) {
                                Text(formatInvestmentCurrency(totalActualRent))
                                    .font(.subheadline)
                                    .fontWeight(.medium)
                                    .foregroundStyle(AppColors.brandTeal)
                                Text("/mo")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }

                    if let operatingExpense = property.operatingExpense, operatingExpense > 0 {
                        HStack {
                            Text("Operating Expenses")
                                .font(.subheadline)
                            Spacer()
                            Text("(\(formatInvestmentCurrency(operatingExpense)))")
                                .font(.subheadline)
                                .fontWeight(.medium)
                                .foregroundStyle(.red)
                        }
                    }

                    if let noi = property.netOperatingIncome, noi > 0 {
                        HStack {
                            Text("Net Operating Income")
                                .font(.subheadline)
                                .fontWeight(.medium)
                            Spacer()
                            Text(formatInvestmentCurrency(noi))
                                .font(.subheadline)
                                .fontWeight(.bold)
                                .foregroundStyle(.green)
                        }
                        .padding(.vertical, 4)
                    }

                    // Cap Rate with color coding
                    if let capRate = property.capRate, capRate > 0 {
                        HStack {
                            Text("Cap Rate")
                                .font(.subheadline)
                                .fontWeight(.medium)
                            Spacer()
                            Text(String(format: "%.2f%%", capRate))
                                .font(.headline)
                                .fontWeight(.bold)
                                .foregroundStyle(capRate >= 5 ? .green : .orange)
                        }
                        .padding(.horizontal, 12)
                        .padding(.vertical, 8)
                        .background((capRate >= 5 ? Color.green : Color.orange).opacity(0.1))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                }
            }
        }
    }

    // MARK: - Disclosures Section (v302)

    private func hasDisclosures(_ p: PropertyDetail) -> Bool {
        return p.leadPaint ||
               p.title5Compliant ||
               p.percTestDone ||
               p.percTestDate != nil ||
               p.shortSale ||
               p.lenderOwned
    }

    private func disclosuresSection(_ property: PropertyDetail) -> some View {
        CollapsibleSection(
            title: FactSection.disclosures.title(for: propertyCategory),
            icon: FactSection.disclosures.icon,
            isExpanded: sectionBinding(.disclosures)
        ) {
            VStack(alignment: .leading, spacing: 8) {
                // Lead Paint Disclosure (MA requirement for pre-1978 homes)
                if property.leadPaint {
                    HStack(spacing: 8) {
                        Image(systemName: "exclamationmark.triangle.fill")
                            .foregroundStyle(.orange)
                            .font(.system(size: 14))
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Lead Paint Disclosure")
                                .font(.subheadline)
                                .fontWeight(.medium)
                            Text("Property built before 1978 - lead paint disclosure required")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                // Perc Test (for septic systems)
                if property.percTestDone {
                    HStack(spacing: 8) {
                        Image(systemName: "checkmark.circle.fill")
                            .foregroundStyle(.green)
                            .font(.system(size: 14))
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Perc Test Completed")
                                .font(.subheadline)
                                .fontWeight(.medium)
                            if let date = property.percTestDate {
                                Text("Date: \(date)")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                } else if property.percTestDate != nil {
                    FeatureRow(label: "Perc Test Date", value: property.percTestDate!)
                }

                // Title 5 Compliance (MA septic system inspection)
                if property.title5Compliant {
                    HStack(spacing: 8) {
                        Image(systemName: "checkmark.seal.fill")
                            .foregroundStyle(.green)
                            .font(.system(size: 14))
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Title 5 Compliant")
                                .font(.subheadline)
                                .fontWeight(.medium)
                            Text("Septic system meets MA standards")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                // Short Sale
                if property.shortSale {
                    HStack(spacing: 8) {
                        Image(systemName: "exclamationmark.triangle.fill")
                            .foregroundStyle(.orange)
                            .font(.system(size: 14))
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Short Sale")
                                .font(.subheadline)
                                .fontWeight(.medium)
                            Text("Subject to lender approval")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                // Lender Owned / REO
                if property.lenderOwned {
                    HStack(spacing: 8) {
                        Image(systemName: "building.columns.fill")
                            .foregroundStyle(.blue)
                            .font(.system(size: 14))
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Lender Owned / REO")
                                .font(.subheadline)
                                .fontWeight(.medium)
                            Text("Bank-owned property")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
        }
    }

}

// MARK: - Extracted Section Views (v305)
// These are extracted from PropertyDetailView to break the deep generic type hierarchy
// that was causing stack overflow during Swift type metadata resolution.
// Each struct creates an opaque type boundary that prevents recursive type resolution.

// MARK: - CollapsedContentView (v305)
// Extracted to break type chain - must be a separate View struct

private struct CollapsedContentView: View {
    let property: PropertyDetail

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Price row - HStack with Spacer for full width
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 4) {
                    Text(property.formattedPrice)
                        .font(.title)
                        .fontWeight(.bold)

                    Text(property.propertySubtype ?? property.propertyType)
                        .font(.caption)
                        .fontWeight(.medium)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(AppColors.brandTeal.opacity(0.1))
                        .foregroundStyle(AppColors.brandTeal)
                        .clipShape(Capsule())
                }
                Spacer()
            }

            // Beds, Baths, Sqft
            HStack(spacing: 16) {
                Label("\(property.beds) bd", systemImage: "bed.double.fill")
                Label(String(format: "%.0f ba", property.baths), systemImage: "shower.fill")
                if let sqft = property.sqft {
                    Label("\(sqft.formatted()) sqft", systemImage: "square.fill")
                }
            }
            .font(.subheadline)
            .foregroundStyle(.secondary)

            // Address
            Text(property.fullAddress)
                .font(.subheadline)
                .foregroundStyle(.primary)
                .lineLimit(2)
        }
        .padding(.horizontal, 20)
        .padding(.bottom, 20)
    }
}

// MARK: - Mini Map Thumbnail (v311)
// Clean static map snapshot - no icons, no text, just the map

private struct MiniMapThumbnail: View {
    let coordinate: CLLocationCoordinate2D
    let size: CGSize  // v315: Accept custom size for vertical rectangle

    @State private var snapshotImage: UIImage?

    init(coordinate: CLLocationCoordinate2D, size: CGSize = CGSize(width: 100, height: 140)) {
        self.coordinate = coordinate
        self.size = size
    }

    var body: some View {
        Group {
            if let image = snapshotImage {
                Image(uiImage: image)
                    .resizable()
                    .aspectRatio(contentMode: .fill)
            } else {
                // Placeholder while loading
                Color(.systemGray5)
            }
        }
        .task {
            await generateSnapshot()
        }
    }

    private func generateSnapshot() async {
        let options = MKMapSnapshotter.Options()
        options.region = MKCoordinateRegion(
            center: coordinate,
            span: MKCoordinateSpan(latitudeDelta: 0.006, longitudeDelta: 0.006)  // Slightly tighter zoom
        )
        // Use the provided size for snapshot generation
        options.size = CGSize(width: size.width * 2, height: size.height * 2)  // 2x for retina
        options.mapType = .standard
        options.showsBuildings = true

        let snapshotter = MKMapSnapshotter(options: options)

        do {
            let snapshot = try await snapshotter.start()
            // Draw a small pin on the snapshot
            let finalImage = drawPin(on: snapshot)
            await MainActor.run {
                snapshotImage = finalImage
            }
        } catch {
            // Keep placeholder on error
            print("Map snapshot error: \(error)")
        }
    }

    // v315: Draw a small pin marker on the snapshot at the property location
    private func drawPin(on snapshot: MKMapSnapshotter.Snapshot) -> UIImage {
        let image = snapshot.image
        let point = snapshot.point(for: coordinate)

        UIGraphicsBeginImageContextWithOptions(image.size, true, image.scale)
        image.draw(at: .zero)

        // Draw a small pin - red circle with white border
        let pinSize: CGFloat = 12
        let pinRect = CGRect(
            x: point.x - pinSize / 2,
            y: point.y - pinSize / 2,
            width: pinSize,
            height: pinSize
        )

        // White border
        UIColor.white.setFill()
        UIBezierPath(ovalIn: pinRect.insetBy(dx: -2, dy: -2)).fill()

        // Red fill
        UIColor.systemRed.setFill()
        UIBezierPath(ovalIn: pinRect).fill()

        // Small white center dot
        UIColor.white.setFill()
        let centerDotSize: CGFloat = 4
        let centerRect = CGRect(
            x: point.x - centerDotSize / 2,
            y: point.y - centerDotSize / 2,
            width: centerDotSize,
            height: centerDotSize
        )
        UIBezierPath(ovalIn: centerRect).fill()

        let finalImage = UIGraphicsGetImageFromCurrentImageContext()
        UIGraphicsEndImageContext()

        return finalImage ?? image
    }
}

// MARK: - ExpandedContentView (v305)
// Extracted to break type chain - the entire expanded content in one struct

private struct ExpandedContentView: View {
    let property: PropertyDetail
    let maxHeight: CGFloat
    @Binding var mlsCopied: Bool
    @Binding var showingContactSheet: Bool
    @Binding var showShareWithClientsSheet: Bool
    @Binding var showBookAppointment: Bool
    @Binding var showCreateOpenHouseSheet: Bool  // v6.69.0: Schedule Open House
    @Binding var showingVirtualTour: Bool
    @Binding var selectedVirtualTourUrl: String?
    let isUserAgent: Bool
    let appointmentViewModel: AppointmentViewModel
    let onAddToCalendar: (String, String, Date, Date) -> Void
    let onTrackScheduleClick: () async -> Void
    let onTrackContactClick: (String) async -> Void
    let propertyHistoryContent: AnyView
    let previousSalesContent: AnyView  // v6.68.0: Previous sales at same address
    let marketInsightsContent: AnyView  // v6.73.0: City market analytics
    let factsAndFeaturesContent: AnyView
    let paymentCalculatorContent: AnyView
    let agentSectionContent: AnyView
    let contactSectionContent: AnyView

    var body: some View {
        // v381: Debug - use GeometryReader to get actual width and constrain content
        GeometryReader { geo in
            ScrollView {
                // v376: Removed VStack padding - each section applies its own padding
                // This allows StatusTagsSectionView to extend edge-to-edge
                VStack(alignment: .leading, spacing: 20) {
                // Price and basic info (handles its own padding internally for edge-to-edge chips)
                PriceSectionView(property: property, mlsCopied: $mlsCopied)

                Divider()
                    .padding(.horizontal, 20)

                // Key details grid
                KeyDetailsGridView(property: property)
                    .padding(.horizontal, 20)

                Divider()
                    .padding(.horizontal, 20)

                // Open Houses Section
                if property.hasOpenHouse, let openHouses = property.openHouses, !openHouses.isEmpty {
                    OpenHousesSectionView(
                        openHouses: openHouses,
                        propertyAddress: property.fullAddress,
                        onAddToCalendar: onAddToCalendar
                    )
                    .padding(.horizontal, 20)
                    Divider()
                        .padding(.horizontal, 20)
                }

                // Description
                if let description = property.description, !description.isEmpty {
                    DescriptionSectionView(description: description)
                        .padding(.horizontal, 20)
                    Divider()
                        .padding(.horizontal, 20)
                }

                // Price & Status History Section (moved after Description per v313)
                propertyHistoryContent
                    .padding(.horizontal, 20)

                // Previous Sales at This Address (v6.68.0)
                previousSalesContent
                    .padding(.horizontal, 20)

                // Market Insights (v6.73.0)
                marketInsightsContent
                    .padding(.horizontal, 20)

                Divider()
                    .padding(.horizontal, 20)

                // Features
                if let features = property.features, !features.isEmpty {
                    FeaturesSectionView(features: features)
                        .padding(.horizontal, 20)
                    Divider()
                        .padding(.horizontal, 20)
                }

                // Facts & Features (passed as AnyView)
                factsAndFeaturesContent
                    .padding(.horizontal, 20)

                // Payment Calculator (passed as AnyView)
                paymentCalculatorContent
                    .padding(.horizontal, 20)

                Divider()
                    .padding(.horizontal, 20)

                // Virtual Tour
                if property.virtualTourUrl != nil || (property.virtualTours?.isEmpty == false) {
                    VirtualTourSectionView(tours: getVirtualTours(from: property)) { tourUrl in
                        selectedVirtualTourUrl = tourUrl
                        showingVirtualTour = true
                    }
                    .padding(.horizontal, 20)
                    Divider()
                        .padding(.horizontal, 20)
                }

                // Map (Enhanced with full-screen modal, overlays)
                MapPreviewSection(property: property)
                    .padding(.horizontal, 20)

                // Agent Section (passed as AnyView)
                agentSectionContent
                    .padding(.horizontal, 20)

                // Contact Section (passed as AnyView)
                contactSectionContent
                    .padding(.horizontal, 20)

                // Action buttons
                ActionButtonsView(
                    property: property,
                    isUserAgent: isUserAgent,
                    appointmentViewModel: appointmentViewModel,
                    showShareWithClientsSheet: $showShareWithClientsSheet,
                    showBookAppointment: $showBookAppointment,
                    showCreateOpenHouseSheet: $showCreateOpenHouseSheet,
                    showingContactSheet: $showingContactSheet,
                    onTrackScheduleClick: onTrackScheduleClick,
                    onTrackContactClick: onTrackContactClick
                )
                .padding(.horizontal, 20)
            }
                .padding(.top, 8)
                .frame(width: geo.size.width, alignment: .leading)  // v381: Use exact geometry width
            }
            .frame(width: geo.size.width, height: maxHeight)  // v381: Constrain ScrollView to exact width
        }
    }

    private func getVirtualTours(from property: PropertyDetail) -> [String] {
        var tours: [String] = []
        if let url = property.virtualTourUrl {
            tours.append(url)
        }
        if let additionalTours = property.virtualTours {
            tours.append(contentsOf: additionalTours)
        }
        return tours
    }
}

// MARK: - ActionButtonsView (v305)
// Extracted to simplify ExpandedContentView

private struct ActionButtonsView: View {
    let property: PropertyDetail
    let isUserAgent: Bool
    let appointmentViewModel: AppointmentViewModel
    @Binding var showShareWithClientsSheet: Bool
    @Binding var showBookAppointment: Bool
    @Binding var showCreateOpenHouseSheet: Bool  // v6.69.0: Schedule Open House
    @Binding var showingContactSheet: Bool
    let onTrackScheduleClick: () async -> Void
    let onTrackContactClick: (String) async -> Void

    var body: some View {
        VStack(spacing: 12) {
            // Share with Clients button (agents only)
            if isUserAgent {
                Button {
                    showShareWithClientsSheet = true
                } label: {
                    HStack {
                        Image(systemName: "person.2.fill")
                        Text("Share with Clients")
                    }
                    .frame(maxWidth: .infinity)
                }
                .buttonStyle(PrimaryButtonStyle())
            }

            // Schedule Open House button (agents only) - v6.69.0
            if isUserAgent && property.standardStatus == .active {
                Button {
                    showCreateOpenHouseSheet = true
                } label: {
                    HStack {
                        Image(systemName: "house.lodge.fill")
                        Text("Schedule Open House")
                    }
                    .frame(maxWidth: .infinity)
                }
                .buttonStyle(SecondaryButtonStyle())
            }

            // Schedule Showing button
            if property.standardStatus == .active {
                if isUserAgent {
                    Button {
                        appointmentViewModel.listingId = property.id
                        appointmentViewModel.propertyAddress = property.fullAddress
                        showBookAppointment = true
                        Task { await onTrackScheduleClick() }
                    } label: {
                        HStack {
                            Image(systemName: "calendar.badge.plus")
                            Text("Schedule Showing")
                        }
                        .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(SecondaryButtonStyle())
                } else {
                    Button {
                        appointmentViewModel.listingId = property.id
                        appointmentViewModel.propertyAddress = property.fullAddress
                        showBookAppointment = true
                        Task { await onTrackScheduleClick() }
                    } label: {
                        HStack {
                            Image(systemName: "calendar.badge.plus")
                            Text("Schedule Showing")
                        }
                        .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(PrimaryButtonStyle())
                }
            }

            // Contact button
            if property.standardStatus == .active {
                Button {
                    showingContactSheet = true
                    Task { await onTrackContactClick("request_info") }
                } label: {
                    HStack {
                        Image(systemName: "envelope.fill")
                        Text("Request Information")
                    }
                    .frame(maxWidth: .infinity)
                }
                .buttonStyle(SecondaryButtonStyle())
            } else {
                Button {
                    showingContactSheet = true
                    Task { await onTrackContactClick("request_info") }
                } label: {
                    HStack {
                        Image(systemName: "envelope.fill")
                        Text("Request Information")
                    }
                    .frame(maxWidth: .infinity)
                }
                .buttonStyle(PrimaryButtonStyle())
            }
        }
        .padding(.top, 8)
        .padding(.bottom, 40)
    }
}

// MARK: - PriceSectionView

private struct PriceSectionView: View {
    let property: PropertyDetail
    @Binding var mlsCopied: Bool

    var body: some View {
        // v376: VStack without internal padding - StatusTagsSectionView extends edge-to-edge
        VStack(alignment: .leading, spacing: 12) {
            // MLS Number with Copy Button
            if let mls = property.mlsNumber {
                Button {
                    UIPasteboard.general.string = mls
                    mlsCopied = true
                    HapticManager.impact(.light)
                    DispatchQueue.main.asyncAfter(deadline: .now() + 1.5) {
                        mlsCopied = false
                    }
                } label: {
                    HStack(spacing: 4) {
                        Text("MLS# \(mls)")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Image(systemName: mlsCopied ? "checkmark.circle.fill" : "doc.on.doc")
                            .font(.caption2)
                            .foregroundStyle(mlsCopied ? .green : .secondary)
                    }
                }
                .padding(.horizontal, 20)  // v376: Individual padding
            }

            // v6.68.0: Data freshness indicator
            if let freshnessText = property.dataFreshnessText {
                HStack(spacing: 4) {
                    Image(systemName: "clock.arrow.circlepath")
                        .font(.caption2)
                    Text(freshnessText)
                        .font(.caption2)
                }
                .foregroundStyle(.tertiary)
                .padding(.horizontal, 20)  // v376: Individual padding
            }

            // Price Row
            HStack(alignment: .top, spacing: 12) {
                // Price info
                VStack(alignment: .leading, spacing: 4) {
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text(property.formattedPrice)
                            .font(.title)
                            .fontWeight(.bold)
                            .lineLimit(1)
                            .minimumScaleFactor(0.7)

                        // Original price strikethrough if reduced
                        if property.isPriceReduced, let original = property.originalPrice {
                            Text(Self.formatPrice(original))
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                                .strikethrough()
                                .lineLimit(1)
                        }
                    }

                    if let dom = property.dom, dom > 0 {
                        Text("\(dom) days on market")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }

                    // Price per sqft
                    if let pricePerSqft = property.pricePerSqft {
                        Text("$\(pricePerSqft)/sqft")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                Spacer()

                // Status badges
                VStack(alignment: .trailing, spacing: 4) {
                    Text(property.standardStatus.displayName)
                        .font(.caption)
                        .fontWeight(.semibold)
                        .lineLimit(1)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(Self.statusColor(for: property.standardStatus).opacity(0.15))
                        .foregroundStyle(Self.statusColor(for: property.standardStatus))
                        .clipShape(Capsule())

                    Text(property.propertySubtype ?? property.propertyType)
                        .font(.caption)
                        .fontWeight(.medium)
                        .lineLimit(1)
                        .minimumScaleFactor(0.7)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(AppColors.brandTeal.opacity(0.1))
                        .foregroundStyle(AppColors.brandTeal)
                        .clipShape(Capsule())
                }
            }
            .padding(.horizontal, 20)  // v376: Individual padding for price row

            // Status Tags Row - NO PADDING - extends edge-to-edge
            StatusTagsSectionView(property: property)

            // Sold Statistics (for closed/sold listings)
            if property.standardStatus.isSold, property.closePrice != nil {
                SoldStatisticsSectionView(property: property)
                    .padding(.horizontal, 20)  // v376: Individual padding
            }

            // Address
            Text(property.fullAddress)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .padding(.horizontal, 20)  // v376: Individual padding

            // Neighborhood
            if let neighborhood = property.neighborhood {
                Text(neighborhood)
                    .font(.caption)
                    .foregroundStyle(.tertiary)
                    .padding(.horizontal, 20)  // v376: Individual padding
            }
        }
    }

    private static func statusColor(for status: PropertyStatus) -> Color {
        switch status {
        case .active: return AppColors.activeStatus
        case .pending: return AppColors.pendingStatus
        case .sold, .closed: return AppColors.soldStatus
        case .withdrawn, .expired, .canceled: return Color.gray
        }
    }

    private static func formatPrice(_ price: Int) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: price)) ?? "$\(price)"
    }
}

// MARK: - StatusTagsSectionView

private struct StatusTagsSectionView: View {
    let property: PropertyDetail

    var body: some View {
        // v379: Wrapping flow layout instead of horizontal scroll - shows all chips
        StatusChipFlowLayout(spacing: 8) {
            // New Listing tag
            if property.isNewListing {
                StatusTag(text: "New", color: .green)
            }

            // Price Reduced tag
            if property.isPriceReduced, let amount = property.priceReductionAmount {
                StatusTag(text: Self.formatPriceReduction(amount), color: .red)
            }

            // Open House tag
            if property.hasOpenHouse, let nextOH = property.nextOpenHouse {
                StatusTag(text: "Open \(nextOH.formattedShort)", color: .orange)
            } else if property.hasOpenHouse {
                StatusTag(text: "Open House", color: .orange)
            }

            // v6.64.0 / v284: Exclusive Listing badge (gold color)
            if property.isExclusive {
                HStack(spacing: 4) {
                    Image(systemName: "star.fill")
                        .font(.caption2)
                    Text("Exclusive")
                        .font(.caption2)
                        .fontWeight(.semibold)
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 6)
                .background(Color(red: 0.85, green: 0.65, blue: 0.13))
                .foregroundStyle(.white)
                .clipShape(Capsule())
            }

            // Property Highlights
            ForEach(property.highlightTags, id: \.rawValue) { highlight in
                HStack(spacing: 4) {
                    Image(systemName: highlight.icon)
                    Text(highlight.rawValue)
                }
                .font(.caption2)
                .fontWeight(.medium)
                .padding(.horizontal, 10)
                .padding(.vertical, 6)
                .background(Color(hex: highlight.color).opacity(0.15))
                .foregroundStyle(Color(hex: highlight.color))
                .clipShape(Capsule())
            }
        }
        .padding(.horizontal, 20)
    }

    private static func formatPriceReduction(_ amount: Int) -> String {
        amount >= 1000 ? "-$\(amount / 1000)K" : "-$\(amount)"
    }
}

// MARK: - StatusChipFlowLayout (v379)
// A layout that wraps content to multiple lines like a word-wrapped text

private struct StatusChipFlowLayout: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let result = arrangeSubviews(proposal: proposal, subviews: subviews)
        return result.size
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let result = arrangeSubviews(proposal: proposal, subviews: subviews)
        for (index, position) in result.positions.enumerated() {
            subviews[index].place(
                at: CGPoint(x: bounds.minX + position.x, y: bounds.minY + position.y),
                proposal: ProposedViewSize(result.sizes[index])
            )
        }
    }

    private func arrangeSubviews(proposal: ProposedViewSize, subviews: Subviews) -> ArrangementResult {
        let maxWidth = proposal.width ?? .infinity
        var positions: [CGPoint] = []
        var sizes: [CGSize] = []
        var currentX: CGFloat = 0
        var currentY: CGFloat = 0
        var lineHeight: CGFloat = 0
        var totalHeight: CGFloat = 0
        var totalWidth: CGFloat = 0

        for subview in subviews {
            let size = subview.sizeThatFits(.unspecified)
            sizes.append(size)

            // Check if we need to wrap to next line
            if currentX + size.width > maxWidth && currentX > 0 {
                currentX = 0
                currentY += lineHeight + spacing
                lineHeight = 0
            }

            positions.append(CGPoint(x: currentX, y: currentY))
            lineHeight = max(lineHeight, size.height)
            currentX += size.width + spacing
            totalWidth = max(totalWidth, currentX - spacing)
        }

        totalHeight = currentY + lineHeight
        return ArrangementResult(
            size: CGSize(width: totalWidth, height: totalHeight),
            positions: positions,
            sizes: sizes
        )
    }

    private struct ArrangementResult {
        let size: CGSize
        let positions: [CGPoint]
        let sizes: [CGSize]
    }
}

// MARK: - SoldStatisticsSectionView

private struct SoldStatisticsSectionView: View {
    let property: PropertyDetail

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Image(systemName: "checkmark.seal.fill")
                    .foregroundStyle(AppColors.soldStatus)
                Text("Sale Information")
                    .font(.subheadline)
                    .fontWeight(.semibold)
            }

            VStack(spacing: 8) {
                // Close Price
                if let closePrice = property.closePrice {
                    HStack {
                        Text("Sold Price")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(Self.formatPrice(closePrice))
                            .font(.subheadline)
                            .fontWeight(.semibold)
                    }
                }

                // Close Date
                if let closeDate = property.closeDate {
                    HStack {
                        Text("Sold Date")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(Self.formatDate(closeDate))
                            .font(.caption)
                    }
                }

                // List to Sale Ratio
                if let ratio = property.listToSaleRatio {
                    HStack {
                        Text("List to Sale Ratio")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(String(format: "%.1f%%", ratio))
                            .font(.caption)
                            .fontWeight(.medium)
                            .foregroundStyle(ratio >= 100 ? AppColors.activeStatus : AppColors.soldStatus)
                    }
                }

                // Sold Above/Below
                if let aboveBelow = property.soldAboveBelow, let priceDiff = property.priceDifference {
                    HStack {
                        Text("Compared to List")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        HStack(spacing: 4) {
                            Image(systemName: Self.soldComparisonIcon(for: aboveBelow))
                                .font(.caption)
                            Text("\(Self.soldComparisonLabel(for: aboveBelow)) asking (\(Self.formatPriceChange(priceDiff)))")
                                .font(.caption)
                        }
                        .foregroundStyle(Self.soldComparisonColor(for: aboveBelow))
                    }
                }
            }
            .padding()
            .background(AppColors.soldStatus.opacity(0.08))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    private static func formatPrice(_ price: Int) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: price)) ?? "$\(price)"
    }

    private static func formatDate(_ dateString: String) -> String {
        let inputFormatter = DateFormatter()
        inputFormatter.dateFormat = "yyyy-MM-dd"
        if let date = inputFormatter.date(from: dateString) {
            let outputFormatter = DateFormatter()
            outputFormatter.dateFormat = "MMM d, yyyy"
            return outputFormatter.string(from: date)
        }
        return dateString
    }

    private static func formatPriceChange(_ amount: Int) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let absAmount = abs(amount)
        return formatter.string(from: NSNumber(value: absAmount)) ?? "$\(absAmount)"
    }

    private static func soldComparisonIcon(for aboveBelow: String) -> String {
        switch aboveBelow {
        case "above": return "arrow.up.circle.fill"
        case "below": return "arrow.down.circle.fill"
        default: return "equal.circle.fill"
        }
    }

    private static func soldComparisonLabel(for aboveBelow: String) -> String {
        switch aboveBelow {
        case "above": return "Above"
        case "below": return "Below"
        default: return "At"
        }
    }

    private static func soldComparisonColor(for aboveBelow: String) -> Color {
        switch aboveBelow {
        case "above": return AppColors.activeStatus
        case "below": return AppColors.soldStatus
        default: return .secondary
        }
    }
}

// MARK: - KeyDetailsGridView

private struct KeyDetailsGridView: View {
    let property: PropertyDetail
    @State private var showFullScreenMap = false

    // v315: Map thumbnail dimensions - tall vertical rectangle to match grid height
    private let mapWidth: CGFloat = 80
    private let mapHeight: CGFloat = 145

    var body: some View {
        HStack(alignment: .top, spacing: 10) {  // v315: Reduced spacing, top alignment
            // Left side - Property details
            LazyVGrid(columns: [
                GridItem(.flexible()),
                GridItem(.flexible()),
                GridItem(.flexible())
            ], spacing: 12) {
                DetailItem(icon: "bed.double.fill", value: "\(property.beds)", label: "Beds")
                DetailItem(icon: "shower.fill", value: String(format: "%.0f", property.baths), label: "Baths")
                if let sqft = property.sqft {
                    // Use custom component to show below grade info if available
                    SqftDetailItem(
                        totalSqft: sqft,
                        belowGradeSqft: property.belowGradeFinishedArea
                    )
                }
                if let year = property.yearBuilt {
                    DetailItem(icon: "calendar", value: "\(year)", label: "Built")
                }
                if let lotSizeAcres = property.lotSizeAcres, lotSizeAcres > 0 {
                    DetailItem(icon: "leaf.fill", value: String(format: "%.2f", lotSizeAcres), label: "Acres")
                } else if let lotSize = property.lotSize, lotSize > 0 {
                    let acres = lotSize / 43560.0
                    DetailItem(icon: "leaf.fill", value: String(format: "%.2f", acres), label: "Acres")
                }
                // Parking - always show total, with garage detail underneath if available
                ParkingDetailItem(
                    totalParking: property.parkingTotal ?? 0,
                    garageSpaces: property.garageSpaces
                )
            }
            .frame(maxWidth: .infinity)

            // Right side - Map thumbnail (v315: taller vertical rectangle with pin)
            if property.location != nil {
                Button {
                    showFullScreenMap = true
                } label: {
                    ZStack(alignment: .bottomTrailing) {
                        MiniMapThumbnail(
                            coordinate: property.location!,
                            size: CGSize(width: mapWidth, height: mapHeight)
                        )
                        .frame(width: mapWidth, height: mapHeight)
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                        .overlay(
                            RoundedRectangle(cornerRadius: 8)
                                .stroke(Color(.systemGray4), lineWidth: 1)
                        )

                        // Expand indicator
                        Image(systemName: "arrow.up.left.and.arrow.down.right")
                            .font(.system(size: 9, weight: .semibold))
                            .foregroundStyle(.white)
                            .padding(3)
                            .background(Color.black.opacity(0.5))
                            .clipShape(RoundedRectangle(cornerRadius: 3))
                            .padding(3)
                    }
                }
                .buttonStyle(.plain)
            }
        }
        .fullScreenCover(isPresented: $showFullScreenMap) {
            FullScreenPropertyMapView(property: property)
        }
    }
}

// MARK: - ParkingDetailItem

private struct ParkingDetailItem: View {
    let totalParking: Int
    let garageSpaces: Int?

    var body: some View {
        VStack(spacing: 2) {
            Image(systemName: "car.fill")
                .font(.title3)
                .foregroundStyle(AppColors.brandTeal)
            Text("\(totalParking)")
                .font(.subheadline)
                .fontWeight(.semibold)
            Text("Parking")
                .font(.caption2)
                .foregroundStyle(.secondary)
            // Show garage count underneath if available
            if let garage = garageSpaces, garage > 0 {
                Text("\(garage) Garage")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
    }
}

// MARK: - SqftDetailItem (v314)

private struct SqftDetailItem: View {
    let totalSqft: Int
    let belowGradeSqft: Int?

    var body: some View {
        VStack(spacing: 2) {
            Image(systemName: "square.fill")
                .font(.title3)
                .foregroundStyle(AppColors.brandTeal)
            Text(totalSqft.formatted())
                .font(.subheadline)
                .fontWeight(.semibold)
            Text("Sqft")
                .font(.caption2)
                .foregroundStyle(.secondary)
            // Show below grade sqft underneath if available
            if let belowGrade = belowGradeSqft, belowGrade > 0 {
                Text("\(belowGrade.formatted()) below grade")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
    }
}

// MARK: - DescriptionSectionView
// v313: Added "read more" truncation - initially shows 3 lines with expand option

private struct DescriptionSectionView: View {
    let description: String
    @State private var isExpanded: Bool = false

    // Approximate character limit for 3 lines of text
    private let truncationLength = 200

    private var shouldTruncate: Bool {
        description.count > truncationLength
    }

    private var displayText: String {
        if isExpanded || !shouldTruncate {
            return description
        }
        // Find a good breakpoint near the truncation length
        let endIndex = description.index(description.startIndex, offsetBy: min(truncationLength, description.count))
        let truncated = String(description[..<endIndex])
        // Try to break at last space to avoid cutting words
        if let lastSpace = truncated.lastIndex(of: " ") {
            return String(truncated[..<lastSpace]) + "..."
        }
        return truncated + "..."
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Description")
                .font(.headline)

            Text(displayText)
                .font(.body)
                .foregroundStyle(.secondary)
                .lineLimit(isExpanded ? nil : 4)

            if shouldTruncate {
                Button {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        isExpanded.toggle()
                    }
                } label: {
                    Text(isExpanded ? "Show less" : "Read more")
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundStyle(AppColors.brandTeal)
                }
            }
        }
    }
}

// MARK: - FeaturesSectionView

private struct FeaturesSectionView: View {
    let features: [String]

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Features")
                .font(.headline)

            PropertyFlowLayout(spacing: 8) {
                ForEach(Array(features.enumerated()), id: \.offset) { _, feature in
                    Text(feature)
                        .font(.caption)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(AppColors.shimmerBase)
                        .clipShape(Capsule())
                }
            }
        }
    }
}

// MARK: - OpenHousesSectionView

private struct OpenHousesSectionView: View {
    let openHouses: [PropertyOpenHouse]
    let propertyAddress: String
    let onAddToCalendar: (String, String, Date, Date) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Open Houses")
                .font(.headline)

            ForEach(openHouses) { openHouse in
                HStack {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(openHouse.formattedDate)
                            .font(.subheadline)
                            .fontWeight(.semibold)
                        Text(openHouse.formattedTimeRange)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        if let remarks = openHouse.remarks, !remarks.isEmpty {
                            Text(remarks)
                                .font(.caption2)
                                .foregroundStyle(.tertiary)
                        }
                    }

                    Spacer()

                    // Add to Calendar button
                    if let startDate = openHouse.startDate, let endDate = openHouse.endDate {
                        Button {
                            onAddToCalendar("Open House: Property", propertyAddress, startDate, endDate)
                        } label: {
                            Image(systemName: "calendar.badge.plus")
                                .font(.title3)
                                .foregroundStyle(AppColors.brandTeal)
                        }
                    }
                }
                .padding()
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }
}

// MARK: - MapSectionView

private struct MapSectionView: View {
    let property: PropertyDetail

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Location")
                .font(.headline)

            if let location = property.location {
                PropertyLocationMapView(
                    coordinate: location,
                    title: property.fullAddress
                )
                .frame(height: 200)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }
}

// MARK: - VirtualTourSectionView

private struct VirtualTourSectionView: View {
    let tours: [String]
    let onTourSelected: (String) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if tours.count == 1 {
                // Single tour - show simple button
                Button {
                    if let tour = tours.first {
                        onTourSelected(tour)
                    }
                } label: {
                    HStack {
                        Image(systemName: "view.3d")
                            .font(.title2)
                        Text("Take Virtual Tour")
                            .font(.headline)
                        Spacer()
                        Image(systemName: "chevron.right")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    .padding()
                    .background(AppColors.brandTeal.opacity(0.1))
                    .foregroundStyle(AppColors.brandTeal)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            } else {
                // Multiple tours - show list
                Text("Virtual Tours")
                    .font(.headline)

                ForEach(Array(tours.enumerated()), id: \.offset) { index, tourUrl in
                    Button {
                        onTourSelected(tourUrl)
                    } label: {
                        HStack {
                            Image(systemName: "view.3d")
                                .font(.title3)
                            Text("Virtual Tour \(index + 1)")
                                .font(.subheadline)
                            Spacer()
                            Image(systemName: "arrow.up.right.square")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        .padding()
                        .background(AppColors.brandTeal.opacity(0.1))
                        .foregroundStyle(AppColors.brandTeal)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                }
            }
        }
    }
}

// MARK: - iOS 16 Compatible Map View (Single Property Location)

struct PropertyLocationMapView: UIViewRepresentable {
    let coordinate: CLLocationCoordinate2D
    let title: String

    func makeUIView(context: Context) -> MKMapView {
        let mapView = MKMapView()
        mapView.isScrollEnabled = false
        mapView.isZoomEnabled = false
        return mapView
    }

    func updateUIView(_ mapView: MKMapView, context: Context) {
        let region = MKCoordinateRegion(
            center: coordinate,
            span: MKCoordinateSpan(latitudeDelta: 0.01, longitudeDelta: 0.01)
        )
        mapView.setRegion(region, animated: false)

        mapView.removeAnnotations(mapView.annotations)
        let annotation = MKPointAnnotation()
        annotation.coordinate = coordinate
        annotation.title = title
        mapView.addAnnotation(annotation)
    }
}

// MARK: - Supporting Views

struct DetailItem: View {
    let icon: String
    let value: String
    let label: String

    var body: some View {
        VStack(spacing: 4) {
            Image(systemName: icon)
                .font(.title3)
                .foregroundStyle(AppColors.primary)
            Text(value)
                .font(.headline)
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }
}

private struct PropertyFlowLayout: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let result = FlowResult(in: proposal.width ?? 0, subviews: subviews, spacing: spacing)
        return CGSize(width: proposal.width ?? 0, height: result.height)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let result = FlowResult(in: bounds.width, subviews: subviews, spacing: spacing)
        for (index, subview) in subviews.enumerated() {
            subview.place(at: CGPoint(x: bounds.minX + result.positions[index].x,
                                      y: bounds.minY + result.positions[index].y),
                         proposal: .unspecified)
        }
    }

    struct FlowResult {
        var positions: [CGPoint] = []
        var height: CGFloat = 0

        init(in width: CGFloat, subviews: Subviews, spacing: CGFloat) {
            var x: CGFloat = 0
            var y: CGFloat = 0
            var rowHeight: CGFloat = 0

            for subview in subviews {
                let size = subview.sizeThatFits(.unspecified)
                if x + size.width > width && x > 0 {
                    x = 0
                    y += rowHeight + spacing
                    rowHeight = 0
                }
                positions.append(CGPoint(x: x, y: y))
                rowHeight = max(rowHeight, size.height)
                x += size.width + spacing
            }
            height = y + rowHeight
        }
    }
}

struct ContactAgentSheet: View {
    let property: PropertyDetail
    let assignedAgent: Agent?  // User's assigned agent (for clients)
    @Environment(\.dismiss) var dismiss
    @EnvironmentObject var siteContactManager: SiteContactManager

    // Use assigned agent if available, otherwise team info
    // Note: Clients should NEVER contact listing agent directly - always assigned agent or team
    private var useAssignedAgent: Bool {
        assignedAgent != nil
    }

    private var contactName: String {
        if useAssignedAgent {
            return assignedAgent?.name ?? siteContactManager.name
        }
        // Fallback to team info from theme settings (not listing agent)
        return siteContactManager.name
    }

    private var contactEmail: String {
        if useAssignedAgent {
            return assignedAgent?.email ?? siteContactManager.email
        }
        return siteContactManager.email
    }

    private var contactPhone: String {
        if useAssignedAgent {
            return assignedAgent?.phone ?? siteContactManager.phone
        }
        return siteContactManager.phone
    }

    private var contactPhotoUrl: String? {
        if useAssignedAgent {
            return assignedAgent?.photoUrl
        }
        return siteContactManager.photoUrl
    }

    private var contactTitle: String {
        if useAssignedAgent {
            return "Your Agent"
        }
        // Use brokerage name if available, otherwise default
        return siteContactManager.brokerageName ?? "BMN Boston Team"
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 24) {
                    // Contact info section
                    VStack(spacing: 12) {
                        // Contact photo
                        if let photoUrl = contactPhotoUrl,
                           let url = URL(string: photoUrl) {
                            AsyncImage(url: url) { phase in
                                switch phase {
                                case .success(let image):
                                    image
                                        .resizable()
                                        .aspectRatio(contentMode: .fill)
                                case .failure, .empty:
                                    contactPlaceholder
                                @unknown default:
                                    contactPlaceholder
                                }
                            }
                            .frame(width: 80, height: 80)
                            .clipShape(Circle())
                        } else {
                            contactPlaceholder
                        }

                        Text(contactName)
                            .font(.title3)
                            .fontWeight(.semibold)

                        Text(contactTitle)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.top, 20)

                    // Property card
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Property")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .textCase(.uppercase)

                        VStack(alignment: .leading, spacing: 4) {
                            Text(property.fullAddress)
                                .font(.headline)
                            Text(property.formattedPrice)
                                .font(.subheadline)
                                .foregroundStyle(AppColors.brandTeal)
                        }
                        .padding()
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                    .padding(.horizontal)

                    // Contact buttons
                    VStack(spacing: 12) {
                        Button {
                            if let url = URL(string: "mailto:\(contactEmail)?subject=Inquiry: \(property.address)") {
                                UIApplication.shared.open(url)
                            }
                        } label: {
                            Label("Send Email", systemImage: "envelope.fill")
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(PrimaryButtonStyle())

                        Button {
                            let cleanPhone = contactPhone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)
                            if let url = URL(string: "tel:\(cleanPhone)") {
                                UIApplication.shared.open(url)
                            }
                        } label: {
                            Label("Call", systemImage: "phone.fill")
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(SecondaryButtonStyle())
                    }
                    .padding(.horizontal)

                    Spacer()
                }
            }
            .navigationTitle("Request Information")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") {
                        dismiss()
                    }
                }
            }
        }
    }

    private var contactPlaceholder: some View {
        Circle()
            .fill(AppColors.brandTeal.opacity(0.2))
            .frame(width: 80, height: 80)
            .overlay(
                Image(systemName: useAssignedAgent ? "person.fill" : "person.3.fill")
                    .font(.title)
                    .foregroundStyle(AppColors.brandTeal)
            )
    }
}

// MARK: - Share Sheet

struct ShareSheet: UIViewControllerRepresentable {
    let items: [Any]

    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: items, applicationActivities: nil)
    }

    func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}

// MARK: - Safari View (for Virtual Tours)

import SafariServices

struct SafariView: UIViewControllerRepresentable {
    let url: URL

    func makeUIViewController(context: Context) -> SFSafariViewController {
        let config = SFSafariViewController.Configuration()
        config.entersReaderIfAvailable = false
        let safariVC = SFSafariViewController(url: url, configuration: config)
        safariVC.preferredControlTintColor = UIColor(AppColors.brandTeal)
        return safariVC
    }

    func updateUIViewController(_ uiViewController: SFSafariViewController, context: Context) {}
}

// MARK: - Image Gallery View

struct ImageGalleryView: View {
    let images: [URL]
    @Binding var selectedIndex: Int
    @Environment(\.dismiss) var dismiss
    @State private var scale: CGFloat = 1.0
    @State private var lastScale: CGFloat = 1.0

    var body: some View {
        NavigationStack {
            ZStack {
                Color.black.ignoresSafeArea()

                TabView(selection: $selectedIndex) {
                    ForEach(Array(images.enumerated()), id: \.offset) { index, url in
                        ZoomableImageView(url: url)
                            .tag(index)
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))

                // Custom page indicator
                VStack {
                    Spacer()
                    HStack {
                        Spacer()
                        Text("\(selectedIndex + 1) / \(images.count)")
                            .font(.subheadline)
                            .fontWeight(.medium)
                            .foregroundStyle(.white)
                            .padding(.horizontal, 16)
                            .padding(.vertical, 8)
                            .background(.black.opacity(0.6))
                            .clipShape(Capsule())
                        Spacer()
                    }
                    .padding(.bottom, 40)
                }
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button {
                        dismiss()
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .font(.title2)
                            .foregroundStyle(.white.opacity(0.8))
                    }
                }
            }
            .toolbarBackground(.hidden, for: .navigationBar)
        }
    }
}

struct ZoomableImageView: View {
    let url: URL
    @State private var scale: CGFloat = 1.0
    @State private var lastScale: CGFloat = 1.0
    @State private var offset: CGSize = .zero
    @State private var lastOffset: CGSize = .zero

    private var isZoomed: Bool { scale > 1.01 }

    var body: some View {
        GeometryReader { geometry in
            AsyncImage(url: url) { phase in
                switch phase {
                case .empty:
                    ProgressView()
                        .tint(.white)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                case .success(let image):
                    image
                        .resizable()
                        .aspectRatio(contentMode: .fit)
                        .scaleEffect(scale)
                        .offset(offset)
                        .gesture(
                            MagnificationGesture()
                                .onChanged { value in
                                    let delta = value / lastScale
                                    lastScale = value
                                    scale = min(max(scale * delta, 1), 4)
                                }
                                .onEnded { _ in
                                    lastScale = 1.0
                                    if scale < 1.0 {
                                        withAnimation {
                                            scale = 1.0
                                        }
                                    }
                                }
                        )
                        .highPriorityGesture(
                            isZoomed ?
                            DragGesture()
                                .onChanged { value in
                                    offset = CGSize(
                                        width: lastOffset.width + value.translation.width,
                                        height: lastOffset.height + value.translation.height
                                    )
                                }
                                .onEnded { _ in
                                    lastOffset = offset
                                }
                            : nil
                        )
                        .onTapGesture(count: 2) {
                            withAnimation {
                                if scale > 1 {
                                    scale = 1
                                    offset = .zero
                                    lastOffset = .zero
                                } else {
                                    scale = 2
                                }
                            }
                        }
                        .onChange(of: scale) { newScale in
                            if newScale <= 1.01 {
                                withAnimation {
                                    offset = .zero
                                    lastOffset = .zero
                                }
                            }
                        }
                case .failure:
                    VStack {
                        Image(systemName: "photo")
                            .font(.largeTitle)
                            .foregroundStyle(.gray)
                        Text("Failed to load image")
                            .font(.caption)
                            .foregroundStyle(.gray)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                @unknown default:
                    EmptyView()
                }
            }
            .frame(width: geometry.size.width, height: geometry.size.height)
        }
    }
}

// MARK: - Scroll Offset Preference Key

private struct ScrollOffsetKey: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) {
        value = nextValue()
    }
}

// MARK: - Status Tag

struct StatusTag: View {
    let text: String
    let color: Color

    var body: some View {
        Text(text)
            .font(.caption2)
            .fontWeight(.semibold)
            .padding(.horizontal, 10)
            .padding(.vertical, 6)
            .background(color)
            .foregroundStyle(.white)
            .clipShape(Capsule())
    }
}

// MARK: - Collapsible Section

struct CollapsibleSection<Content: View, Trailing: View>: View {
    let title: String
    let icon: String
    @Binding var isExpanded: Bool
    @ViewBuilder let trailingContent: Trailing
    @ViewBuilder let content: Content

    // Convenience initializer without trailing content
    init(
        title: String,
        icon: String,
        isExpanded: Binding<Bool>,
        @ViewBuilder content: () -> Content
    ) where Trailing == EmptyView {
        self.title = title
        self.icon = icon
        self._isExpanded = isExpanded
        self.trailingContent = EmptyView()
        self.content = content()
    }

    // Full initializer with trailing content
    init(
        title: String,
        icon: String,
        isExpanded: Binding<Bool>,
        @ViewBuilder trailingContent: () -> Trailing,
        @ViewBuilder content: () -> Content
    ) {
        self.title = title
        self.icon = icon
        self._isExpanded = isExpanded
        self.trailingContent = trailingContent()
        self.content = content()
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Button {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        isExpanded.toggle()
                    }
                } label: {
                    HStack {
                        Image(systemName: icon)
                            .font(.subheadline)
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 24)

                        Text(title)
                            .font(.subheadline)
                            .fontWeight(.medium)
                            .foregroundStyle(.primary)

                        Spacer()
                    }
                }
                .buttonStyle(.plain)

                // Trailing content (e.g., "Full View" button)
                trailingContent

                Button {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        isExpanded.toggle()
                    }
                } label: {
                    Image(systemName: isExpanded ? "chevron.up" : "chevron.down")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .buttonStyle(.plain)
            }
            .padding(.vertical, 12)

            if isExpanded {
                content
                    .padding(.leading, 32)
                    .padding(.bottom, 12)
            }

            Divider()
        }
    }
}

// MARK: - Feature Row

struct FeatureRow: View {
    let label: String
    let value: String

    var body: some View {
        HStack(alignment: .top) {
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
                .frame(width: 100, alignment: .leading)

            Text(value)
                .font(.caption)
                .foregroundStyle(.primary)
                .multilineTextAlignment(.leading)

            Spacer()
        }
    }
}

// MARK: - Payment Calculator View

struct PaymentCalculatorView: View {
    let propertyPrice: Int
    let annualTax: Double?
    let hoaFee: Double?
    let hoaFrequency: String?

    @State private var downPaymentPercent: Double = 20
    @State private var interestRate: Double = 6.5
    @State private var loanTermYears: Int = 30
    @State private var isExpanded: Bool = false

    private var loanAmount: Double {
        Double(propertyPrice) * (1 - downPaymentPercent / 100)
    }

    private var monthlyPrincipalAndInterest: Double {
        let monthlyRate = interestRate / 100 / 12
        let numPayments = Double(loanTermYears * 12)

        if monthlyRate == 0 {
            return loanAmount / numPayments
        }

        let payment = loanAmount * (monthlyRate * pow(1 + monthlyRate, numPayments)) / (pow(1 + monthlyRate, numPayments) - 1)
        return payment
    }

    private var monthlyTax: Double {
        (annualTax ?? 0) / 12
    }

    private var monthlyInsurance: Double {
        // Estimate: 0.35% of property value annually
        Double(propertyPrice) * 0.0035 / 12
    }

    private var monthlyHOA: Double {
        guard let fee = hoaFee, fee > 0 else { return 0 }
        if let frequency = hoaFrequency?.lowercased() {
            if frequency.contains("annual") {
                return fee / 12
            } else if frequency.contains("quarter") {
                return fee / 3
            }
        }
        return fee // Assume monthly
    }

    private var totalMonthlyPayment: Double {
        monthlyPrincipalAndInterest + monthlyTax + monthlyInsurance + monthlyHOA
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Button {
                withAnimation(.easeInOut(duration: 0.2)) {
                    isExpanded.toggle()
                }
            } label: {
                HStack {
                    Image(systemName: "dollarsign.circle.fill")
                        .font(.title2)
                        .foregroundStyle(AppColors.brandTeal)

                    VStack(alignment: .leading, spacing: 2) {
                        Text("Est. Monthly Payment")
                            .font(.subheadline)
                            .foregroundStyle(.primary)

                        Text(formatCurrency(totalMonthlyPayment))
                            .font(.title2)
                            .fontWeight(.bold)
                            .foregroundStyle(AppColors.brandTeal)
                    }

                    Spacer()

                    Image(systemName: isExpanded ? "chevron.up" : "chevron.down")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .padding()
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .buttonStyle(.plain)

            if isExpanded {
                VStack(alignment: .leading, spacing: 16) {
                    // Adjustable inputs
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Down Payment: \(Int(downPaymentPercent))%")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Slider(value: $downPaymentPercent, in: 0...50, step: 5)
                            .tint(AppColors.brandTeal)
                    }

                    VStack(alignment: .leading, spacing: 8) {
                        Text("Interest Rate: \(String(format: "%.2f", interestRate))%")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Slider(value: $interestRate, in: 3...12, step: 0.25)
                            .tint(AppColors.brandTeal)
                    }

                    VStack(alignment: .leading, spacing: 8) {
                        Text("Loan Term: \(loanTermYears) years")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Picker("", selection: $loanTermYears) {
                            Text("15 yr").tag(15)
                            Text("20 yr").tag(20)
                            Text("30 yr").tag(30)
                        }
                        .pickerStyle(.segmented)
                    }

                    Divider()

                    // Breakdown
                    VStack(spacing: 8) {
                        PaymentBreakdownRow(label: "Principal & Interest", amount: monthlyPrincipalAndInterest)
                        PaymentBreakdownRow(label: "Property Tax", amount: monthlyTax)
                        PaymentBreakdownRow(label: "Insurance (est.)", amount: monthlyInsurance)
                        if monthlyHOA > 0 {
                            PaymentBreakdownRow(label: "HOA", amount: monthlyHOA)
                        }

                        Divider()

                        HStack {
                            Text("Total")
                                .font(.subheadline)
                                .fontWeight(.semibold)
                            Spacer()
                            Text(formatCurrency(totalMonthlyPayment))
                                .font(.subheadline)
                                .fontWeight(.bold)
                                .foregroundStyle(AppColors.brandTeal)
                        }
                    }
                }
                .padding()
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }

    private func formatCurrency(_ amount: Double) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: amount)) ?? "$\(Int(amount))"
    }
}

struct PaymentBreakdownRow: View {
    let label: String
    let amount: Double

    var body: some View {
        HStack {
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
            Spacer()
            Text(formatCurrency(amount))
                .font(.caption)
                .foregroundStyle(.primary)
        }
    }

    private func formatCurrency(_ amount: Double) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: amount)) ?? "$\(Int(amount))"
    }
}

// MARK: - Payment Calculator Content View (v308)
// Content-only version for use inside CollapsibleSection

struct PaymentCalculatorContentView: View {
    let propertyPrice: Int
    let annualTax: Double?
    let hoaFee: Double?
    let hoaFrequency: String?

    @State private var downPaymentPercent: Double = 20
    @State private var interestRate: Double = 6.5
    @State private var loanTermYears: Int = 30

    private var loanAmount: Double {
        Double(propertyPrice) * (1 - downPaymentPercent / 100)
    }

    private var monthlyPrincipalAndInterest: Double {
        let monthlyRate = interestRate / 100 / 12
        let numPayments = Double(loanTermYears * 12)

        if monthlyRate == 0 {
            return loanAmount / numPayments
        }

        let payment = loanAmount * (monthlyRate * pow(1 + monthlyRate, numPayments)) / (pow(1 + monthlyRate, numPayments) - 1)
        return payment
    }

    private var monthlyTax: Double {
        (annualTax ?? 0) / 12
    }

    private var monthlyInsurance: Double {
        // Estimate: 0.35% of property value annually
        Double(propertyPrice) * 0.0035 / 12
    }

    private var monthlyHOA: Double {
        guard let fee = hoaFee, fee > 0 else { return 0 }
        if let frequency = hoaFrequency?.lowercased() {
            if frequency.contains("annual") {
                return fee / 12
            } else if frequency.contains("quarter") {
                return fee / 3
            }
        }
        return fee // Assume monthly
    }

    private var totalMonthlyPayment: Double {
        monthlyPrincipalAndInterest + monthlyTax + monthlyInsurance + monthlyHOA
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Total payment header
            HStack {
                Text("Est. Monthly Payment")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                Spacer()
                Text(formatCurrency(totalMonthlyPayment))
                    .font(.title2)
                    .fontWeight(.bold)
                    .foregroundStyle(AppColors.brandTeal)
            }
            .padding(.bottom, 8)

            // Adjustable inputs
            VStack(alignment: .leading, spacing: 8) {
                Text("Down Payment: \(Int(downPaymentPercent))%")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Slider(value: $downPaymentPercent, in: 0...50, step: 5)
                    .tint(AppColors.brandTeal)
            }

            VStack(alignment: .leading, spacing: 8) {
                Text("Interest Rate: \(String(format: "%.2f", interestRate))%")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Slider(value: $interestRate, in: 3...12, step: 0.25)
                    .tint(AppColors.brandTeal)
            }

            VStack(alignment: .leading, spacing: 8) {
                Text("Loan Term: \(loanTermYears) years")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Picker("", selection: $loanTermYears) {
                    Text("15 yr").tag(15)
                    Text("20 yr").tag(20)
                    Text("30 yr").tag(30)
                }
                .pickerStyle(.segmented)
            }

            Divider()

            // Breakdown
            VStack(spacing: 8) {
                PaymentBreakdownRow(label: "Principal & Interest", amount: monthlyPrincipalAndInterest)
                PaymentBreakdownRow(label: "Property Tax", amount: monthlyTax)
                PaymentBreakdownRow(label: "Insurance (est.)", amount: monthlyInsurance)
                if monthlyHOA > 0 {
                    PaymentBreakdownRow(label: "HOA", amount: monthlyHOA)
                }

                Divider()

                HStack {
                    Text("Total")
                        .font(.subheadline)
                        .fontWeight(.semibold)
                    Spacer()
                    Text(formatCurrency(totalMonthlyPayment))
                        .font(.subheadline)
                        .fontWeight(.bold)
                        .foregroundStyle(AppColors.brandTeal)
                }
            }
        }
    }

    private func formatCurrency(_ amount: Double) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: amount)) ?? "$\(Int(amount))"
    }
}

// MARK: - LinkedTextView (v365: Auto-detect phone numbers and emails)

/// A text segment that can be plain text, a phone number, or an email
private enum TextSegment: Identifiable {
    case plain(String)
    case phone(String)
    case email(String)
    case showingTime(String)  // ShowingTime scheduling text

    var id: String {
        switch self {
        case .plain(let text): return "plain-\(text.hashValue)"
        case .phone(let phone): return "phone-\(phone)"
        case .email(let email): return "email-\(email)"
        case .showingTime(let text): return "showingtime-\(text.hashValue)"
        }
    }
}

/// Parses text and auto-detects phone numbers, emails, and ShowingTime scheduling, making them tappable
struct LinkedTextView: View {
    let text: String
    let onPhoneTap: (String) -> Void
    let onEmailTap: (String) -> Void
    var mlsNumber: String? = nil
    var agentMlsId: String? = nil

    private var segments: [TextSegment] {
        parseTextForLinks(text, mlsNumber: mlsNumber, agentMlsId: agentMlsId)
    }

    /// Generate ShowingTime scheduling URL
    private var showingTimeURL: URL? {
        guard let mlsNumber = mlsNumber, let agentMlsId = agentMlsId, !agentMlsId.isEmpty else {
            return nil
        }
        let urlString = "https://schedulingsso.showingtime.com/icons?siteid=PROP.MLSPIN.I&MLSID=MLSPIN&raid=\(agentMlsId)&listingid=\(mlsNumber)"
        return URL(string: urlString)
    }

    var body: some View {
        Text(buildAttributedText())
            .font(.subheadline)
            .foregroundStyle(.primary)
            .environment(\.openURL, OpenURLAction { url in
                handleURL(url)
            })
    }

    private func buildAttributedText() -> AttributedString {
        var result = AttributedString()

        for segment in segments {
            switch segment {
            case .plain(let text):
                result.append(AttributedString(text))

            case .phone(let phone):
                var phoneAttr = AttributedString(phone)
                phoneAttr.foregroundColor = .teal
                phoneAttr.underlineStyle = .single
                // Use a custom URL scheme for phone numbers
                let cleanPhone = phone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)
                phoneAttr.link = URL(string: "tel-action:\(cleanPhone)")
                result.append(phoneAttr)

            case .email(let email):
                var emailAttr = AttributedString(email)
                emailAttr.foregroundColor = .teal
                emailAttr.underlineStyle = .single
                emailAttr.link = URL(string: "mailto:\(email)")
                result.append(emailAttr)

            case .showingTime(let text):
                var stAttr = AttributedString(text)
                stAttr.foregroundColor = .teal
                stAttr.underlineStyle = .single
                // Use custom URL scheme for ShowingTime
                stAttr.link = URL(string: "showingtime-action:schedule")
                result.append(stAttr)
            }
        }

        return result
    }

    private func handleURL(_ url: URL) -> OpenURLAction.Result {
        if url.scheme == "tel-action" {
            // Extract phone number and trigger callback
            let phone = url.absoluteString.replacingOccurrences(of: "tel-action:", with: "")
            onPhoneTap(phone)
            return .handled
        } else if url.scheme == "mailto" {
            let email = url.absoluteString.replacingOccurrences(of: "mailto:", with: "")
            onEmailTap(email)
            return .handled
        } else if url.scheme == "showingtime-action" {
            // Open ShowingTime scheduling URL
            if let showingTimeURL = showingTimeURL {
                UIApplication.shared.open(showingTimeURL)
            }
            return .handled
        }
        return .systemAction
    }

    /// Parse text and extract phone numbers, emails, and ShowingTime references as segments
    private func parseTextForLinks(_ input: String, mlsNumber: String?, agentMlsId: String?) -> [TextSegment] {
        var segments: [TextSegment] = []
        var currentIndex = input.startIndex

        // Combined pattern for phone numbers, emails, and ShowingTime
        // Phone: matches formats like (617) 555-1234, 617-555-1234, 617.555.1234, 6175551234
        // Email: standard email format
        // ShowingTime: matches "ShowingTime", "Showing Time", "schedule with showingtime", etc.
        let phonePattern = #"(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}"#
        let emailPattern = #"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}"#

        // Only add ShowingTime pattern if we have both mlsNumber and agentMlsId
        let hasShowingTimeData = mlsNumber != nil && agentMlsId != nil && !agentMlsId!.isEmpty
        let showingTimePattern = hasShowingTimeData ? #"(?:schedule\s+(?:with\s+)?)?(?:showing\s*time|showingtime)(?:\+)?(?:\s+(?:schedule|for\s+showings?|showing\s+request))?"# : ""

        let combinedPattern: String
        if hasShowingTimeData {
            combinedPattern = "(\(phonePattern))|(\(emailPattern))|(\(showingTimePattern))"
        } else {
            combinedPattern = "(\(phonePattern))|(\(emailPattern))"
        }

        guard let regex = try? NSRegularExpression(pattern: combinedPattern, options: [.caseInsensitive]) else {
            return [.plain(input)]
        }

        let nsString = input as NSString
        let matches = regex.matches(in: input, options: [], range: NSRange(location: 0, length: nsString.length))

        for match in matches {
            let matchRange = Range(match.range, in: input)!

            // Add plain text before this match
            if currentIndex < matchRange.lowerBound {
                let plainText = String(input[currentIndex..<matchRange.lowerBound])
                if !plainText.isEmpty {
                    segments.append(.plain(plainText))
                }
            }

            // Add the matched text
            let matchedText = String(input[matchRange])
            let matchedLower = matchedText.lowercased()

            // Determine if it's a phone, email, or ShowingTime
            if matchedText.contains("@") {
                segments.append(.email(matchedText))
            } else if matchedLower.contains("showingtime") || matchedLower.contains("showing time") {
                segments.append(.showingTime(matchedText))
            } else {
                segments.append(.phone(matchedText))
            }

            currentIndex = matchRange.upperBound
        }

        // Add remaining plain text
        if currentIndex < input.endIndex {
            let remainingText = String(input[currentIndex...])
            if !remainingText.isEmpty {
                segments.append(.plain(remainingText))
            }
        }

        // If no matches, return the whole text as plain
        if segments.isEmpty {
            return [.plain(input)]
        }

        return segments
    }
}

#Preview {
    NavigationStack {
        PropertyDetailView(propertyId: "abc123")
            .environmentObject(AuthViewModel())
    }
}

