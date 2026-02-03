//
//  PropertyMapView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Matches MLD WordPress plugin map styling with drawing tools
//

import SwiftUI
import MapKit
import os.log

private let mapLogger = Logger(subsystem: "com.bmnboston.app", category: "PropertyMapView")

// MARK: - Adaptive UIColors for Map Components

extension UIColor {
    static var adaptiveBrandTeal: UIColor {
        UIColor { traitCollection in
            traitCollection.userInterfaceStyle == .dark
                ? UIColor(red: 34/255, green: 211/255, blue: 238/255, alpha: 1)  // #22D3EE
                : UIColor(red: 8/255, green: 145/255, blue: 178/255, alpha: 1)   // #0891B2
        }
    }

    static var adaptiveMarkerActive: UIColor {
        UIColor { traitCollection in
            traitCollection.userInterfaceStyle == .dark
                ? UIColor(red: 226/255, green: 232/255, blue: 240/255, alpha: 1) // #E2E8F0
                : UIColor(red: 74/255, green: 85/255, blue: 104/255, alpha: 1)   // #4A5568
        }
    }

    static var adaptiveMarkerBorder: UIColor {
        UIColor { traitCollection in
            traitCollection.userInterfaceStyle == .dark
                ? UIColor(red: 203/255, green: 213/255, blue: 224/255, alpha: 1) // #CBD5E0
                : UIColor(red: 45/255, green: 55/255, blue: 72/255, alpha: 1)    // #2D3748
        }
    }

    static var adaptiveMarkerArchived: UIColor {
        UIColor { traitCollection in
            traitCollection.userInterfaceStyle == .dark
                ? UIColor(red: 74/255, green: 85/255, blue: 104/255, alpha: 1)   // #4A5568
                : UIColor(red: 211/255, green: 211/255, blue: 211/255, alpha: 1) // #D3D3D3
        }
    }

    static var adaptiveMarkerArchivedBorder: UIColor {
        UIColor { traitCollection in
            traitCollection.userInterfaceStyle == .dark
                ? UIColor(red: 100/255, green: 116/255, blue: 139/255, alpha: 1) // #64748B
                : UIColor(red: 180/255, green: 180/255, blue: 180/255, alpha: 1) // #B4B4B4
        }
    }

    static var adaptiveMarkerArchivedText: UIColor {
        UIColor { traitCollection in
            traitCollection.userInterfaceStyle == .dark
                ? UIColor(red: 226/255, green: 232/255, blue: 240/255, alpha: 1) // #E2E8F0
                : UIColor(red: 74/255, green: 74/255, blue: 74/255, alpha: 1)    // #4A4A4A
        }
    }

    /// Initialize UIColor from hex string (e.g., "#DA291C" or "DA291C")
    convenience init?(hex: String) {
        var hexSanitized = hex.trimmingCharacters(in: .whitespacesAndNewlines)
        hexSanitized = hexSanitized.replacingOccurrences(of: "#", with: "")

        guard hexSanitized.count == 6 else { return nil }

        var rgb: UInt64 = 0
        guard Scanner(string: hexSanitized).scanHexInt64(&rgb) else { return nil }

        self.init(
            red: CGFloat((rgb & 0xFF0000) >> 16) / 255.0,
            green: CGFloat((rgb & 0x00FF00) >> 8) / 255.0,
            blue: CGFloat(rgb & 0x0000FF) / 255.0,
            alpha: 1.0
        )
    }
}

// MARK: - Drawing Mode

enum MapDrawingMode: String, CaseIterable, Identifiable {
    case none = "None"
    case polygon = "Tap Points"
    case freehand = "Freehand"

    var id: String { rawValue }

    var icon: String {
        switch self {
        case .none: return "hand.point.up.left"
        case .polygon: return "mappin.and.ellipse"
        case .freehand: return "scribble.variable"
        }
    }

    var instructionText: String {
        switch self {
        case .none: return ""
        case .polygon: return "Tap to add points. Drag to adjust."
        case .freehand: return "Draw your search area with your finger."
        }
    }

    /// Drawing modes only (excludes none)
    static var drawingModes: [MapDrawingMode] {
        [.polygon, .freehand]
    }
}

// MARK: - Marker State (matching web z-index system)

enum MarkerState {
    case normal      // z-index: 2
    case archived    // z-index: 1
    case hover       // z-index: 3-4
    case selected    // z-index: 5
    case popup       // z-index: 10000

    var zPriority: MKAnnotationViewZPriority {
        switch self {
        case .archived: return MKAnnotationViewZPriority(rawValue: 100)
        case .normal: return MKAnnotationViewZPriority(rawValue: 200)
        case .hover: return MKAnnotationViewZPriority(rawValue: 300)
        case .selected: return MKAnnotationViewZPriority(rawValue: 500)
        case .popup: return .max
        }
    }

    var scale: CGFloat {
        switch self {
        case .archived: return 0.9
        case .normal: return 1.0
        case .hover: return 1.05
        case .selected: return 1.15
        case .popup: return 1.2
        }
    }
}

// MARK: - Property Map View (iOS 16 Compatible with Drawing)

struct PropertyMapView: UIViewRepresentable {
    let properties: [Property]
    let onPropertySelected: (Property) -> Void
    let onFavoriteTap: (Property) -> Void
    @Binding var selectedAnnotation: PropertyAnnotation?
    @Binding var drawingMode: MapDrawingMode
    @Binding var polygonCoordinates: [CLLocationCoordinate2D]  // Current shape being drawn/edited
    @Binding var completedShapes: [[CLLocationCoordinate2D]]  // Previously completed shapes (multi-shape support)
    @Binding var freehandPoints: [CLLocationCoordinate2D]  // Points during freehand drawing
    @Binding var isActivelyDrawing: Bool  // True when user is actively drawing (finger down)
    @Binding var shouldCenterOnUser: Bool
    @Binding var targetMapRegion: MKCoordinateRegion?  // For auto-zoom on location filter
    @Binding var animateMapRegion: Bool  // Whether to animate the region change
    @Binding var mapType: MKMapType  // Map type (standard, satellite, hybrid)
    @Binding var cityBoundaries: [MKPolygon]  // City boundary overlays - binding for direct updates
    @Binding var boundariesVersion: Int  // Used to force UI update when boundaries change - MUST be binding!
    var cityPriceAnnotations: [NeighborhoodAnalytics] = []  // City price overlays for zoomed-out view
    var schoolAnnotations: [MapSchool] = []  // School pins for map overlay
    var transitAnnotations: [TransitStation] = []  // Transit station pins for map overlay
    var transitRoutes: [TransitRoute] = []  // Transit route polylines for map overlay
    var transitRoutesVersion: Int = 0  // Version counter to force updates when routes load
    var showTransit: Bool = false  // When true, shows transit overlays
    var onBoundsChanged: ((MapBounds) -> Void)?
    var onSchoolSelected: ((MapSchool) -> Void)?  // Callback when school pin is tapped
    var onTransitSelected: ((TransitStation) -> Void)?  // Callback when transit pin is tapped
    var recentlyViewedIds: [String] = []  // IDs of viewed properties for visual indicator

    func makeCoordinator() -> Coordinator {
        Coordinator(self)
    }

    func makeUIView(context: Context) -> MKMapView {
        let mapView = MKMapView()
        mapView.delegate = context.coordinator
        mapView.showsUserLocation = true
        mapView.showsCompass = true
        mapView.showsScale = true
        mapView.mapType = .standard

        // Store reference for location centering
        context.coordinator.mapView = mapView

        // Add tap gesture for drawing (tap-to-place mode)
        let tapGesture = UITapGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.handleMapTap(_:)))
        tapGesture.delegate = context.coordinator
        mapView.addGestureRecognizer(tapGesture)

        // Add pan gesture for freehand drawing (single finger only)
        let panGesture = UIPanGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.handlePanGesture(_:)))
        panGesture.delegate = context.coordinator
        panGesture.minimumNumberOfTouches = 1
        panGesture.maximumNumberOfTouches = 1
        mapView.addGestureRecognizer(panGesture)

        // Store reference to pan gesture for later use
        context.coordinator.panGesture = panGesture

        return mapView
    }

    func updateUIView(_ mapView: MKMapView, context: Context) {
        // Update coordinator's parent reference to get latest closures
        context.coordinator.parent = self

        // Update map type if changed
        if mapView.mapType != mapType {
            mapView.mapType = mapType
        }

        // Disable map interactions when in freehand mode (single finger draws, two fingers pan/zoom)
        // We handle this by disabling scroll/zoom and using our pan gesture for drawing
        if drawingMode == .freehand {
            // In freehand mode, the pan gesture handler will manage scroll/zoom state
            // Start with them enabled - they get disabled when drawing starts
            if !isActivelyDrawing {
                mapView.isScrollEnabled = true
                mapView.isZoomEnabled = true
            }
        } else {
            // Not in freehand mode - ensure map interactions are enabled
            mapView.isScrollEnabled = true
            mapView.isZoomEnabled = true
        }

        // CRITICAL: Set target region FIRST, before any annotation changes
        // This prevents the flash when 0 results come back and all pins are removed
        // Skip on first load - let fitMapToAnnotations handle initial positioning
        if let targetRegion = targetMapRegion, !context.coordinator.isFirstLoad {
            mapView.setRegion(targetRegion, animated: animateMapRegion)
            DispatchQueue.main.async {
                self.targetMapRegion = nil  // Clear after setting
                self.animateMapRegion = true  // Reset to default for next time
            }
        }

        // Get existing property annotations (not vertices or user location)
        let existingPropertyAnnotations = mapView.annotations.compactMap { $0 as? PropertyAnnotation }
        let existingPropertyIds = Set(existingPropertyAnnotations.flatMap { $0.properties.map { $0.id } })

        // Group properties by address for clustering
        let groupedProperties = groupPropertiesByAddress(properties)
        var newAnnotations: [PropertyAnnotation] = []
        var newPropertyIds = Set<String>()

        for (_, group) in groupedProperties {
            guard let firstProperty = group.first,
                  let location = firstProperty.location else { continue }

            let groupIds = Set(group.map { $0.id })
            newPropertyIds.formUnion(groupIds)

            if group.count > 1 {
                // Create cluster annotation for multiple units at same address
                let annotation = PropertyAnnotation(
                    properties: group,
                    coordinate: location,
                    isCluster: true
                )
                newAnnotations.append(annotation)
            } else {
                // Single property annotation
                let annotation = PropertyAnnotation(
                    properties: [firstProperty],
                    coordinate: location,
                    isCluster: false
                )
                newAnnotations.append(annotation)
            }
        }

        // Only update annotations if the property set has changed
        if existingPropertyIds != newPropertyIds {
            // Remove only property annotations that are no longer needed
            let annotationsToRemove = existingPropertyAnnotations.filter { annotation in
                let ids = Set(annotation.properties.map { $0.id })
                return !ids.isSubset(of: newPropertyIds)
            }
            mapView.removeAnnotations(annotationsToRemove)

            // Add only new annotations that don't already exist
            let annotationsToAdd = newAnnotations.filter { annotation in
                let ids = Set(annotation.properties.map { $0.id })
                return !ids.isSubset(of: existingPropertyIds)
            }
            mapView.addAnnotations(annotationsToAdd)
        }

        // Handle overlays separately - remove old ones first
        // Filter to only get user-drawn polygons (not city boundaries)
        let boundaryTypes = ["cityBoundary", "neighborhoodBoundary", "zipcodeBoundary"]
        let existingDrawnPolygons = mapView.overlays.compactMap { $0 as? MKPolygon }.filter { polygon in
            let title = polygon.title ?? ""
            return !boundaryTypes.contains(title) && title != "freehandPreview"
        }
        let existingVertexAnnotations = mapView.annotations.compactMap { $0 as? PolygonVertexAnnotation }

        // Update polygon overlays if current shape or completed shapes changed
        let currentShapeChanged = !coordinatesEqual(context.coordinator.lastPolygonCoordinates, polygonCoordinates)
        let completedShapesChanged = context.coordinator.lastCompletedShapes.count != completedShapes.count ||
            !zip(context.coordinator.lastCompletedShapes, completedShapes).allSatisfy { coordinatesEqual($0, $1) }

        if currentShapeChanged || completedShapesChanged {
            // Remove existing drawn polygons (not city boundaries)
            mapView.removeOverlays(existingDrawnPolygons)
            mapView.removeAnnotations(existingVertexAnnotations)
            context.coordinator.lastPolygonCoordinates = polygonCoordinates
            context.coordinator.lastCompletedShapes = completedShapes

            // Add completed shapes first (slightly different style - no vertices shown)
            for (shapeIndex, shape) in completedShapes.enumerated() {
                if !shape.isEmpty {
                    var coords = shape
                    let polygon = MKPolygon(coordinates: &coords, count: coords.count)
                    polygon.title = "completedShape"
                    polygon.subtitle = "\(shapeIndex)"
                    mapView.addOverlay(polygon)
                }
            }

            // Add current shape being edited (with vertex annotations)
            if !polygonCoordinates.isEmpty {
                var coords = polygonCoordinates
                let polygon = MKPolygon(coordinates: &coords, count: coords.count)
                polygon.title = "currentShape"
                mapView.addOverlay(polygon)

                // Only show vertex annotations for the current shape
                for (index, coord) in polygonCoordinates.enumerated() {
                    let vertexAnnotation = PolygonVertexAnnotation(coordinate: coord, index: index)
                    mapView.addAnnotation(vertexAnnotation)
                }
            }

            // Update midpoint annotations for adding new vertices
            if drawingMode != .none {
                context.coordinator.updateMidpointAnnotations(in: mapView)
            } else {
                // Remove midpoints when not in drawing mode
                let existingMidpoints = mapView.annotations.compactMap { $0 as? MidpointAnnotation }
                mapView.removeAnnotations(existingMidpoints)
                context.coordinator.midpointAnnotations.removeAll()
            }
        }

        // Update location boundary overlays (cities, neighborhoods, ZIP codes)
        let existingBoundaries = mapView.overlays.compactMap { $0 as? MKPolygon }.filter { polygon in
            boundaryTypes.contains(polygon.title ?? "")
        }
        let existingBoundaryKeys = Set(existingBoundaries.map { "\($0.title ?? ""):\($0.subtitle ?? "")" })
        let newBoundaryKeys = Set(cityBoundaries.map { "\($0.title ?? ""):\($0.subtitle ?? "")" })

        // Force update if version changed (handles edge cases where array comparison fails)
        let versionChanged = boundariesVersion != context.coordinator.lastBoundariesVersion
        let boundariesDiffer = existingBoundaries.count != cityBoundaries.count || existingBoundaryKeys != newBoundaryKeys

        // ALSO force clear if boundaries array is empty but overlays exist (safety check)
        let needsForceClear = cityBoundaries.isEmpty && !existingBoundaries.isEmpty

        if versionChanged || boundariesDiffer || needsForceClear {

            // Remove old boundaries
            mapView.removeOverlays(existingBoundaries)

            // Add new ones (at the bottom so they don't cover property pins)
            for boundary in cityBoundaries {
                mapView.insertOverlay(boundary, at: 0)
            }

            // Update last known version
            context.coordinator.lastBoundariesVersion = boundariesVersion
        }

        // Update city price annotations (for zoomed-out view)
        let existingCityPriceAnnotations = mapView.annotations.compactMap { $0 as? CityPriceAnnotation }
        let existingCityPriceNames = Set(existingCityPriceAnnotations.map { $0.analytics.name })
        let newCityPriceNames = Set(cityPriceAnnotations.map { $0.name })

        if existingCityPriceNames != newCityPriceNames {
            // Remove old city price annotations
            mapView.removeAnnotations(existingCityPriceAnnotations)
            // Add new ones
            for analytics in cityPriceAnnotations {
                let annotation = CityPriceAnnotation(analytics: analytics)
                mapView.addAnnotation(annotation)
            }
        }

        // Update school annotations
        let existingSchoolAnnotations = mapView.annotations.compactMap { $0 as? SchoolAnnotation }
        let existingSchoolIds = Set(existingSchoolAnnotations.map { $0.school.id })
        let newSchoolIds = Set(schoolAnnotations.map { $0.id })

        if existingSchoolIds != newSchoolIds {
            // Remove old school annotations
            mapView.removeAnnotations(existingSchoolAnnotations)
            // Add new ones
            for school in schoolAnnotations {
                let annotation = SchoolAnnotation(school: school)
                mapView.addAnnotation(annotation)
            }
        }

        // Update transit annotations
        let existingTransitAnnotations = mapView.annotations.compactMap { $0 as? TransitAnnotation }
        let existingTransitIds = Set(existingTransitAnnotations.map { $0.station.id })
        let newTransitIds = Set(transitAnnotations.map { $0.id })

        if existingTransitIds != newTransitIds {
            // Remove old transit annotations
            mapView.removeAnnotations(existingTransitAnnotations)
            // Add new ones
            for station in transitAnnotations {
                let annotation = TransitAnnotation(station: station)
                mapView.addAnnotation(annotation)
            }
        }

        // Update transit route overlays (polylines)
        let existingTransitRouteOverlays = mapView.overlays.compactMap { overlay -> MKPolyline? in
            guard let polyline = overlay as? MKPolyline,
                  let title = polyline.title,
                  title.hasPrefix("transitRoute:") else { return nil }
            return polyline
        }
        let existingRouteIds = Set(existingTransitRouteOverlays.compactMap { $0.title?.replacingOccurrences(of: "transitRoute:", with: "") })
        let newRouteIds = Set(transitRoutes.map { $0.id })

        // Add routes if: we have routes AND (IDs changed OR no existing overlays)
        let shouldAddRoutes = !transitRoutes.isEmpty && (existingRouteIds != newRouteIds || existingTransitRouteOverlays.isEmpty)

        if shouldAddRoutes {
            // Remove old transit route overlays
            mapView.removeOverlays(existingTransitRouteOverlays)

            // Add new transit route overlays above roads (so they're visible)
            for route in transitRoutes {
                let coordinates = route.coordinates
                guard !coordinates.isEmpty else { continue }
                let polyline = MKPolyline(coordinates: coordinates, count: coordinates.count)
                polyline.title = "transitRoute:\(route.id)"
                polyline.subtitle = route.line.rawValue  // Store line info for renderer
                mapView.addOverlay(polyline, level: .aboveRoads)
            }
        } else if transitRoutes.isEmpty && !existingTransitRouteOverlays.isEmpty {
            // Remove routes when transit is toggled off
            mapView.removeOverlays(existingTransitRouteOverlays)
        }

        // Update neighborhood label annotations (for zoomed-in neighborhood names)
        // Extract unique neighborhoods with their centroid coordinates
        let existingNeighborhoodAnnotations = mapView.annotations.compactMap { $0 as? NeighborhoodLabelAnnotation }
        let neighborhoodData = extractNeighborhoodCentroids(from: properties)
        let existingNeighborhoodNames = Set(existingNeighborhoodAnnotations.map { $0.name })
        let newNeighborhoodNames = Set(neighborhoodData.keys)

        if existingNeighborhoodNames != newNeighborhoodNames {
            // Remove old neighborhood annotations
            mapView.removeAnnotations(existingNeighborhoodAnnotations)
            // Add new ones
            for (name, data) in neighborhoodData {
                let annotation = NeighborhoodLabelAnnotation(
                    name: name,
                    coordinate: data.centroid,
                    listingCount: data.count
                )
                mapView.addAnnotation(annotation)
            }
        }

        // Fit map to show all annotations on first load
        if !newAnnotations.isEmpty && context.coordinator.isFirstLoad {
            context.coordinator.isFirstLoad = false
            fitMapToAnnotations(mapView, annotations: newAnnotations)
        }

        // Center on user location when requested
        if shouldCenterOnUser {
            DispatchQueue.main.async {
                self.shouldCenterOnUser = false
                context.coordinator.requestLocationAndCenter()
            }
        }

        // Note: targetMapRegion is handled at the START of updateUIView
        // to ensure region is set BEFORE annotations are removed (prevents flash on 0 results)
    }

    private func groupPropertiesByAddress(_ properties: [Property]) -> [String: [Property]] {
        var groups: [String: [Property]] = [:]
        for property in properties {
            // Use groupingAddress (street without unit) for clustering, fall back to address
            let streetAddress = property.groupingAddress ?? property.address
            let normalizedAddress = normalizeStreetAddress(streetAddress, city: property.city)
            if groups[normalizedAddress] == nil {
                groups[normalizedAddress] = []
            }
            groups[normalizedAddress]?.append(property)
        }
        return groups
    }

    /// Normalizes street address for consistent clustering
    /// Handles variations like "Street" vs "St", "Avenue" vs "Ave", periods, extra whitespace
    /// Also strips trailing suffixes entirely to handle missing suffixes (e.g., "135 Seaport" vs "135 Seaport Blvd")
    private func normalizeStreetAddress(_ address: String, city: String) -> String {
        var normalized = address.lowercased()

        // Remove periods (handles "St." vs "St", "Blvd." vs "Blvd")
        normalized = normalized.replacingOccurrences(of: ".", with: "")

        // Collapse multiple spaces to single space
        while normalized.contains("  ") {
            normalized = normalized.replacingOccurrences(of: "  ", with: " ")
        }

        // Trim whitespace
        normalized = normalized.trimmingCharacters(in: .whitespaces)

        // Normalize directional prefixes/suffixes FIRST (before stripping suffixes)
        let directionalReplacements: [(full: String, abbrev: String)] = [
            ("north", "n"),
            ("south", "s"),
            ("east", "e"),
            ("west", "w"),
            ("northeast", "ne"),
            ("northwest", "nw"),
            ("southeast", "se"),
            ("southwest", "sw")
        ]

        for (full, abbrev) in directionalReplacements {
            normalized = normalized.replacingOccurrences(
                of: "\\b\(full)\\b",
                with: abbrev,
                options: .regularExpression
            )
        }

        // Normalize number words to digits (handles "Pier Four" vs "Pier 4")
        let numberWords: [(word: String, digit: String)] = [
            ("one", "1"), ("two", "2"), ("three", "3"), ("four", "4"), ("five", "5"),
            ("six", "6"), ("seven", "7"), ("eight", "8"), ("nine", "9"), ("ten", "10"),
            ("first", "1st"), ("second", "2nd"), ("third", "3rd"), ("fourth", "4th"), ("fifth", "5th")
        ]

        for (word, digit) in numberWords {
            normalized = normalized.replacingOccurrences(
                of: "\\b\(word)\\b",
                with: digit,
                options: .regularExpression
            )
        }

        // STRIP trailing street suffixes entirely for clustering
        // This handles cases like "135 Seaport" vs "135 Seaport Blvd" - both become "135 seaport"
        let suffixesToStrip = [
            // Full words
            "street", "avenue", "boulevard", "drive", "road", "lane", "court", "place",
            "circle", "terrace", "highway", "parkway", "square", "way", "wharf", "alley",
            "trail", "crossing", "point", "path", "row", "walk",
            // Abbreviations
            "st", "ave", "blvd", "dr", "rd", "ln", "ct", "pl", "cir", "ter", "hwy",
            "pkwy", "sq", "wy", "whf", "aly", "trl", "xing", "pt", "pth"
        ]

        for suffix in suffixesToStrip {
            // Remove suffix at end of string (with word boundary before it)
            normalized = normalized.replacingOccurrences(
                of: "\\s+\(suffix)$",
                with: "",
                options: .regularExpression
            )
        }

        // Final trim
        normalized = normalized.trimmingCharacters(in: .whitespaces)

        return "\(normalized)-\(city.lowercased())"
    }

    private func coordinatesEqual(_ lhs: [CLLocationCoordinate2D], _ rhs: [CLLocationCoordinate2D]) -> Bool {
        guard lhs.count == rhs.count else { return false }
        for (l, r) in zip(lhs, rhs) {
            if l.latitude != r.latitude || l.longitude != r.longitude {
                return false
            }
        }
        return true
    }

    /// Extract unique neighborhoods from properties and calculate their centroid coordinates
    private func extractNeighborhoodCentroids(from properties: [Property]) -> [String: (centroid: CLLocationCoordinate2D, count: Int)] {
        var neighborhoodGroups: [String: [(lat: Double, lng: Double)]] = [:]

        for property in properties {
            // Use neighborhood if available, otherwise skip (or use city as fallback)
            guard let neighborhood = property.neighborhood, !neighborhood.isEmpty,
                  let lat = property.latitude, let lng = property.longitude else {
                continue
            }

            if neighborhoodGroups[neighborhood] == nil {
                neighborhoodGroups[neighborhood] = []
            }
            neighborhoodGroups[neighborhood]?.append((lat: lat, lng: lng))
        }

        // Calculate centroid for each neighborhood
        var result: [String: (centroid: CLLocationCoordinate2D, count: Int)] = [:]

        for (name, locations) in neighborhoodGroups {
            guard !locations.isEmpty else { continue }

            // Only show labels for neighborhoods with at least 2 listings
            guard locations.count >= 2 else { continue }

            let avgLat = locations.map(\.lat).reduce(0, +) / Double(locations.count)
            let avgLng = locations.map(\.lng).reduce(0, +) / Double(locations.count)

            result[name] = (
                centroid: CLLocationCoordinate2D(latitude: avgLat, longitude: avgLng),
                count: locations.count
            )
        }

        return result
    }

    private func fitMapToAnnotations(_ mapView: MKMapView, annotations: [PropertyAnnotation]) {
        let coordinates = annotations.map { $0.coordinate }
        let minLat = coordinates.map(\.latitude).min() ?? 42.36
        let maxLat = coordinates.map(\.latitude).max() ?? 42.36
        let minLng = coordinates.map(\.longitude).min() ?? -71.06
        let maxLng = coordinates.map(\.longitude).max() ?? -71.06

        let center = CLLocationCoordinate2D(
            latitude: (minLat + maxLat) / 2,
            longitude: (minLng + maxLng) / 2
        )

        let span = MKCoordinateSpan(
            latitudeDelta: max(0.02, (maxLat - minLat) * 1.3),
            longitudeDelta: max(0.02, (maxLng - minLng) * 1.3)
        )

        let region = MKCoordinateRegion(center: center, span: span)
        mapView.setRegion(region, animated: true)
    }

    // MARK: - Coordinator

    class Coordinator: NSObject, MKMapViewDelegate, UIGestureRecognizerDelegate, CLLocationManagerDelegate {
        var parent: PropertyMapView
        var isFirstLoad = true
        var regionChangeCount = 0  // Track region changes to skip initial ones
        var mapView: MKMapView?
        let locationManager = CLLocationManager()

        // Track overlay state to avoid unnecessary updates
        var lastPolygonCoordinates: [CLLocationCoordinate2D] = []
        var lastCompletedShapes: [[CLLocationCoordinate2D]] = []
        var lastBoundariesVersion: Int = -1  // Track boundary version for forced updates

        // Freehand drawing state
        var freehandOverlay: MKPolyline?
        var lastSampledPoint: CGPoint?
        let minimumPointDistance: CGFloat = 8.0  // Minimum pixels between sampled points

        // Midpoint annotations for adding vertices
        var midpointAnnotations: [MidpointAnnotation] = []

        // Shape overlays (for tap-to-select)
        var shapeOverlays: [MKPolygon] = []

        // Reference to pan gesture for freehand drawing
        var panGesture: UIPanGestureRecognizer?

        init(_ parent: PropertyMapView) {
            self.parent = parent
            super.init()
            locationManager.delegate = self
            // Required for requestLocation() to work
            locationManager.desiredAccuracy = kCLLocationAccuracyHundredMeters

            // Listen for clear boundaries notification
            NotificationCenter.default.addObserver(
                self,
                selector: #selector(handleClearBoundaries),
                name: .clearCityBoundaries,
                object: nil
            )
        }

        @objc func handleClearBoundaries() {
            mapLogger.info("Received clearCityBoundaries notification - clearing overlays")
            guard let mapView = mapView else {
                mapLogger.warning("mapView is nil, cannot clear boundaries")
                return
            }

            let boundaryTypes = ["cityBoundary", "neighborhoodBoundary", "zipcodeBoundary"]
            let boundaryOverlays = mapView.overlays.compactMap { $0 as? MKPolygon }.filter { polygon in
                boundaryTypes.contains(polygon.title ?? "")
            }

            if !boundaryOverlays.isEmpty {
                mapLogger.info("Removing \(boundaryOverlays.count) boundary overlays via notification")
                mapView.removeOverlays(boundaryOverlays)
            }
        }

        // Track if we're waiting to center on location
        private var pendingCenterOnUser = false

        func requestLocationAndCenter() {
            let status = locationManager.authorizationStatus
            switch status {
            case .notDetermined:
                pendingCenterOnUser = true
                locationManager.requestWhenInUseAuthorization()
            case .authorizedWhenInUse, .authorizedAlways:
                // Request fresh location instead of relying on map's cached location
                pendingCenterOnUser = true
                locationManager.requestLocation()
            case .denied, .restricted:
                // Post notification so UI can handle fallback (e.g., default to Boston)
                NotificationCenter.default.post(name: .locationPermissionDenied, object: nil)
            @unknown default:
                NotificationCenter.default.post(name: .locationPermissionDenied, object: nil)
            }
        }

        func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
            switch manager.authorizationStatus {
            case .authorizedWhenInUse, .authorizedAlways:
                if pendingCenterOnUser {
                    locationManager.requestLocation()
                }
            case .denied, .restricted:
                // User denied permission after prompt - post notification
                if pendingCenterOnUser {
                    pendingCenterOnUser = false
                    NotificationCenter.default.post(name: .locationPermissionDenied, object: nil)
                }
            default:
                break
            }
        }

        func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
            guard pendingCenterOnUser, let location = locations.last else { return }
            pendingCenterOnUser = false
            centerOnLocation(location.coordinate)
        }

        func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
            // If location request fails, try using map's cached location as fallback
            if pendingCenterOnUser {
                pendingCenterOnUser = false
                if let mapView = mapView,
                   let userLocation = mapView.userLocation.location {
                    centerOnLocation(userLocation.coordinate)
                }
            }
        }

        private func centerOnLocation(_ coordinate: CLLocationCoordinate2D) {
            guard let mapView = mapView else { return }

            let region = MKCoordinateRegion(
                center: coordinate,
                span: MKCoordinateSpan(latitudeDelta: 0.05, longitudeDelta: 0.05)
            )
            mapView.setRegion(region, animated: true)

            // Explicitly trigger bounds change to refresh listings
            // This is needed because regionDidChangeAnimated skips early region changes
            let bounds = MapBounds(
                north: region.center.latitude + region.span.latitudeDelta / 2,
                south: region.center.latitude - region.span.latitudeDelta / 2,
                east: region.center.longitude + region.span.longitudeDelta / 2,
                west: region.center.longitude - region.span.longitudeDelta / 2
            )

            // Delay slightly to let the map animation start
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                self.parent.onBoundsChanged?(bounds)
            }
        }

        // MARK: - Gesture Handling

        @objc func handleMapTap(_ gesture: UITapGestureRecognizer) {
            guard let mapView = gesture.view as? MKMapView else { return }

            let point = gesture.location(in: mapView)
            let coordinate = mapView.convert(point, toCoordinateFrom: mapView)

            switch parent.drawingMode {
            case .polygon:
                // Haptic feedback for vertex placement
                let impactFeedback = UIImpactFeedbackGenerator(style: .light)
                impactFeedback.impactOccurred()
                parent.polygonCoordinates.append(coordinate)
            case .freehand:
                // Freehand mode uses pan gesture, not tap
                break
            case .none:
                break
            }
        }

        @objc func handlePanGesture(_ gesture: UIPanGestureRecognizer) {
            guard parent.drawingMode == .freehand else { return }
            guard let mapView = gesture.view as? MKMapView else { return }

            let point = gesture.location(in: mapView)
            let coordinate = mapView.convert(point, toCoordinateFrom: mapView)

            switch gesture.state {
            case .began:
                // Mark as actively drawing via binding (SwiftUI updates UI accordingly)
                parent.isActivelyDrawing = true

                // Start new freehand drawing
                parent.freehandPoints = [coordinate]
                lastSampledPoint = point

                // Haptic feedback for start
                let impactFeedback = UIImpactFeedbackGenerator(style: .medium)
                impactFeedback.impactOccurred()

                // CRITICAL: Disable ALL map interactions during freehand drawing
                mapView.isScrollEnabled = false
                mapView.isZoomEnabled = false
                mapView.isPitchEnabled = false
                mapView.isRotateEnabled = false

            case .changed:
                // Sample points based on distance threshold
                if let lastPoint = lastSampledPoint {
                    let distance = hypot(point.x - lastPoint.x, point.y - lastPoint.y)
                    if distance >= minimumPointDistance {
                        parent.freehandPoints.append(coordinate)
                        lastSampledPoint = point

                        // Update the freehand preview overlay
                        updateFreehandOverlay(in: mapView)
                    }
                }

            case .ended, .cancelled:
                // Mark as no longer actively drawing via binding
                parent.isActivelyDrawing = false

                // Re-enable map gestures
                mapView.isScrollEnabled = true
                mapView.isZoomEnabled = true
                mapView.isPitchEnabled = true
                mapView.isRotateEnabled = true

                // Remove the preview overlay
                if let overlay = freehandOverlay {
                    mapView.removeOverlay(overlay)
                    freehandOverlay = nil
                }

                // Convert freehand to polygon if enough points
                if parent.freehandPoints.count >= 3 {
                    // Simplify the polygon using Douglas-Peucker algorithm
                    // Tolerance of 0.00002 (~2 meters) is more conservative to preserve freehand detail
                    let simplified = simplifyPolygon(parent.freehandPoints, tolerance: 0.00002)

                    if simplified.count >= 3 {
                        // Explicitly close the polygon by appending first point if not already closed
                        var closedPolygon = simplified
                        if let first = closedPolygon.first, let last = closedPolygon.last,
                           first.latitude != last.latitude || first.longitude != last.longitude {
                            closedPolygon.append(first)
                        }
                        parent.polygonCoordinates = closedPolygon

                        // Haptic feedback for completion
                        let notificationFeedback = UINotificationFeedbackGenerator()
                        notificationFeedback.notificationOccurred(.success)
                    }
                }

                // Clear freehand points
                parent.freehandPoints = []
                lastSampledPoint = nil

            default:
                break
            }
        }

        private func updateFreehandOverlay(in mapView: MKMapView) {
            // Remove existing overlay
            if let overlay = freehandOverlay {
                mapView.removeOverlay(overlay)
            }

            // Create new polyline from freehand points
            guard !parent.freehandPoints.isEmpty else { return }

            var coordinates = parent.freehandPoints
            let polyline = MKPolyline(coordinates: &coordinates, count: coordinates.count)
            polyline.title = "freehandPreview"
            mapView.addOverlay(polyline)
            freehandOverlay = polyline
        }

        /// Douglas-Peucker algorithm for polygon simplification
        private func simplifyPolygon(_ points: [CLLocationCoordinate2D], tolerance: Double) -> [CLLocationCoordinate2D] {
            guard points.count > 2 else { return points }

            var maxDistance: Double = 0
            var maxIndex = 0
            let start = points[0]
            let end = points[points.count - 1]

            for i in 1..<(points.count - 1) {
                let distance = perpendicularDistance(point: points[i], lineStart: start, lineEnd: end)
                if distance > maxDistance {
                    maxDistance = distance
                    maxIndex = i
                }
            }

            if maxDistance > tolerance {
                let left = simplifyPolygon(Array(points[0...maxIndex]), tolerance: tolerance)
                let right = simplifyPolygon(Array(points[maxIndex...]), tolerance: tolerance)
                return left + right.dropFirst()
            } else {
                return [start, end]
            }
        }

        /// Calculate perpendicular distance from a point to a line segment
        private func perpendicularDistance(point: CLLocationCoordinate2D, lineStart: CLLocationCoordinate2D, lineEnd: CLLocationCoordinate2D) -> Double {
            let dx = lineEnd.longitude - lineStart.longitude
            let dy = lineEnd.latitude - lineStart.latitude

            if dx == 0 && dy == 0 {
                // Line start and end are the same point
                return hypot(point.longitude - lineStart.longitude, point.latitude - lineStart.latitude)
            }

            let t = ((point.longitude - lineStart.longitude) * dx + (point.latitude - lineStart.latitude) * dy) / (dx * dx + dy * dy)
            let clampedT = max(0, min(1, t))

            let nearestLng = lineStart.longitude + clampedT * dx
            let nearestLat = lineStart.latitude + clampedT * dy

            return hypot(point.longitude - nearestLng, point.latitude - nearestLat)
        }

        /// Updates midpoint annotations between polygon vertices for adding new points
        func updateMidpointAnnotations(in mapView: MKMapView) {
            // Remove existing midpoint annotations
            let existingMidpoints = mapView.annotations.compactMap { $0 as? MidpointAnnotation }
            mapView.removeAnnotations(existingMidpoints)
            midpointAnnotations.removeAll()

            // Need at least 2 vertices to create midpoints
            guard parent.polygonCoordinates.count >= 2 else { return }

            // Create midpoint annotations for each edge
            for i in 0..<parent.polygonCoordinates.count {
                let start = parent.polygonCoordinates[i]
                let end = parent.polygonCoordinates[(i + 1) % parent.polygonCoordinates.count]

                // Use MKMapPoint for projection-aware midpoint calculation
                // This ensures midpoints appear at the visual center of edges on the map
                let startPoint = MKMapPoint(start)
                let endPoint = MKMapPoint(end)

                let midMapPoint = MKMapPoint(
                    x: (startPoint.x + endPoint.x) / 2,
                    y: (startPoint.y + endPoint.y) / 2
                )

                let midpoint = midMapPoint.coordinate

                let annotation = MidpointAnnotation(coordinate: midpoint, edgeIndex: i)
                midpointAnnotations.append(annotation)
                mapView.addAnnotation(annotation)
            }
        }

        func gestureRecognizerShouldBegin(_ gestureRecognizer: UIGestureRecognizer) -> Bool {
            // Only allow our pan gesture to begin when in freehand mode with single finger
            if gestureRecognizer === panGesture {
                // Check if we're in freehand mode and using single finger
                let isFreehand = parent.drawingMode == .freehand
                let touchCount = gestureRecognizer.numberOfTouches
                // Allow begin if in freehand mode (touch count is 0 at shouldBegin)
                return isFreehand
            }
            // Allow tap gesture in polygon mode only (not freehand - we use pan for that)
            if gestureRecognizer is UITapGestureRecognizer {
                return parent.drawingMode == .polygon
            }
            return true
        }

        func gestureRecognizer(_ gestureRecognizer: UIGestureRecognizer, shouldRecognizeSimultaneouslyWith otherGestureRecognizer: UIGestureRecognizer) -> Bool {
            // In freehand mode, our pan gesture should NOT recognize simultaneously with anything
            // This ensures our drawing gesture takes exclusive control
            if parent.drawingMode == .freehand {
                if gestureRecognizer === panGesture || otherGestureRecognizer === panGesture {
                    return false
                }
            }
            // Allow other gestures to work together when not in freehand mode
            return parent.drawingMode != .none
        }

        func gestureRecognizer(_ gestureRecognizer: UIGestureRecognizer, shouldRequireFailureOf otherGestureRecognizer: UIGestureRecognizer) -> Bool {
            // In freehand mode, map's built-in pan gestures should wait for our gesture to fail
            if parent.drawingMode == .freehand {
                if gestureRecognizer is UIPanGestureRecognizer && gestureRecognizer !== panGesture {
                    // Map's pan gesture should require our pan to fail first
                    return otherGestureRecognizer === panGesture
                }
            }
            return false
        }

        func gestureRecognizer(_ gestureRecognizer: UIGestureRecognizer, shouldBeRequiredToFailBy otherGestureRecognizer: UIGestureRecognizer) -> Bool {
            // In freehand mode, our pan gesture must be "required to fail" by other gestures
            // This gives our gesture priority - other gestures wait for ours
            // Since our gesture runs to .ended (never fails), map gestures stay blocked
            if parent.drawingMode == .freehand && gestureRecognizer === panGesture {
                return true
            }
            return false
        }

        // MARK: - Map Region Changes

        func mapView(_ mapView: MKMapView, regionDidChangeAnimated animated: Bool) {
            // Skip the first few region changes (initial setup)
            regionChangeCount += 1
            guard regionChangeCount > 2 else { return }

            let region = mapView.region
            let bounds = MapBounds(
                north: region.center.latitude + region.span.latitudeDelta / 2,
                south: region.center.latitude - region.span.latitudeDelta / 2,
                east: region.center.longitude + region.span.longitudeDelta / 2,
                west: region.center.longitude - region.span.longitudeDelta / 2
            )

            // Dispatch asynchronously to avoid "Publishing changes from within view updates"
            DispatchQueue.main.async {
                self.parent.onBoundsChanged?(bounds)
            }
        }

        // MARK: - Overlay Rendering

        func mapView(_ mapView: MKMapView, rendererFor overlay: MKOverlay) -> MKOverlayRenderer {
            if let polygon = overlay as? MKPolygon {
                let renderer = MKPolygonRenderer(polygon: polygon)

                // Location boundary polygons have distinctive styling by type
                switch polygon.title {
                case "cityBoundary":
                    // City boundaries: Blue
                    renderer.fillColor = UIColor.systemBlue.withAlphaComponent(0.08)
                    renderer.strokeColor = UIColor.systemBlue.withAlphaComponent(0.5)
                    renderer.lineWidth = 2.5
                    renderer.lineDashPattern = nil  // Solid line for real boundaries

                case "neighborhoodBoundary":
                    // Neighborhood boundaries: Purple
                    renderer.fillColor = UIColor.systemPurple.withAlphaComponent(0.1)
                    renderer.strokeColor = UIColor.systemPurple.withAlphaComponent(0.6)
                    renderer.lineWidth = 2
                    renderer.lineDashPattern = nil

                case "zipcodeBoundary":
                    // ZIP code boundaries: Green
                    renderer.fillColor = UIColor.systemGreen.withAlphaComponent(0.08)
                    renderer.strokeColor = UIColor.systemGreen.withAlphaComponent(0.5)
                    renderer.lineWidth = 2
                    renderer.lineDashPattern = nil

                case "completedShape":
                    // Completed search shapes (multi-shape support) - slightly more transparent
                    renderer.fillColor = UIColor.adaptiveBrandTeal.withAlphaComponent(0.15)
                    renderer.strokeColor = UIColor.adaptiveBrandTeal.withAlphaComponent(0.7)
                    renderer.lineWidth = 2
                    renderer.lineDashPattern = nil

                case "currentShape":
                    // Current shape being drawn/edited - full opacity
                    renderer.fillColor = UIColor.adaptiveBrandTeal.withAlphaComponent(0.25)
                    renderer.strokeColor = UIColor.adaptiveBrandTeal
                    renderer.lineWidth = 2.5
                    renderer.lineDashPattern = nil

                default:
                    // Legacy/fallback draw search polygon (user-drawn)
                    renderer.fillColor = UIColor.adaptiveBrandTeal.withAlphaComponent(0.2)
                    renderer.strokeColor = UIColor.adaptiveBrandTeal
                    renderer.lineWidth = 2
                }
                return renderer
            }

            if let circle = overlay as? MKCircle {
                let renderer = MKCircleRenderer(circle: circle)
                renderer.fillColor = UIColor.adaptiveBrandTeal.withAlphaComponent(0.15)
                renderer.strokeColor = UIColor.adaptiveBrandTeal
                renderer.lineWidth = 2
                renderer.lineDashPattern = [8, 4]
                return renderer
            }

            // Handle polyline overlays (transit routes and freehand preview)
            if let polyline = overlay as? MKPolyline {
                let renderer = MKPolylineRenderer(polyline: polyline)

                // Transit route polylines
                if let title = polyline.title, title.hasPrefix("transitRoute:") {
                    // Get line info from subtitle to determine color
                    let lineColor: UIColor
                    let lineWidth: CGFloat

                    if let lineName = polyline.subtitle, let mbtaline = MBTALine(rawValue: lineName) {
                        // Use MBTA line colors
                        switch mbtaline {
                        case .red:
                            lineColor = UIColor(red: 218/255, green: 41/255, blue: 28/255, alpha: 1)
                            lineWidth = 4.0
                        case .orange:
                            lineColor = UIColor(red: 237/255, green: 139/255, blue: 0/255, alpha: 1)
                            lineWidth = 4.0
                        case .blue:
                            lineColor = UIColor(red: 0/255, green: 61/255, blue: 165/255, alpha: 1)
                            lineWidth = 4.0
                        case .greenB, .greenC, .greenD, .greenE:
                            lineColor = UIColor(red: 0/255, green: 132/255, blue: 61/255, alpha: 1)
                            lineWidth = 4.0
                        case .silver:
                            lineColor = UIColor(red: 124/255, green: 135/255, blue: 142/255, alpha: 1)
                            lineWidth = 3.5
                        case .commuterRail:
                            lineColor = UIColor(red: 128/255, green: 39/255, blue: 108/255, alpha: 1)
                            lineWidth = 2.5
                        }
                    } else {
                        // Default fallback
                        lineColor = UIColor.systemGray
                        lineWidth = 3.0
                    }

                    renderer.strokeColor = lineColor
                    renderer.lineWidth = lineWidth
                    return renderer
                }

                // Freehand preview polyline
                if polyline.title == "freehandPreview" {
                    renderer.strokeColor = UIColor.adaptiveBrandTeal.withAlphaComponent(0.8)
                    renderer.lineWidth = 3
                    renderer.lineDashPattern = [5, 3]
                } else {
                    // Default polyline style
                    renderer.strokeColor = UIColor.adaptiveBrandTeal
                    renderer.lineWidth = 2
                }
                return renderer
            }

            return MKOverlayRenderer(overlay: overlay)
        }

        func mapView(_ mapView: MKMapView, viewFor annotation: MKAnnotation) -> MKAnnotationView? {
            // Handle user location
            if annotation is MKUserLocation {
                return nil
            }

            // Handle polygon vertex
            if let vertex = annotation as? PolygonVertexAnnotation {
                return createVertexView(for: vertex, in: mapView)
            }

            // Handle midpoint annotation (for adding vertices)
            if let midpoint = annotation as? MidpointAnnotation {
                return createMidpointView(for: midpoint, in: mapView)
            }

            // Handle city price annotation (zoomed-out overlays)
            if let cityAnnotation = annotation as? CityPriceAnnotation {
                return createCityPriceView(for: cityAnnotation, in: mapView)
            }

            // Handle school annotation
            if let schoolAnnotation = annotation as? SchoolAnnotation {
                return createSchoolView(for: schoolAnnotation, in: mapView)
            }

            // Handle neighborhood label annotation
            if let neighborhoodAnnotation = annotation as? NeighborhoodLabelAnnotation {
                return createNeighborhoodLabelView(for: neighborhoodAnnotation, in: mapView)
            }

            // Handle transit station annotation
            if let transitAnnotation = annotation as? TransitAnnotation {
                return createTransitView(for: transitAnnotation, in: mapView)
            }

            // Handle property annotation
            guard let propertyAnnotation = annotation as? PropertyAnnotation else {
                return nil
            }

            if propertyAnnotation.isCluster {
                return createClusterView(for: propertyAnnotation, in: mapView)
            } else {
                return createPriceMarkerView(for: propertyAnnotation, in: mapView)
            }
        }

        private func createVertexView(for annotation: PolygonVertexAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "PolygonVertex"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier)

            if annotationView == nil {
                annotationView = MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = false
                annotationView?.isDraggable = true
            } else {
                annotationView?.annotation = annotation
            }

            // Create vertex with larger touch target
            let touchSize: CGFloat = 44  // Minimum touch target size for accessibility
            let innerSize: CGFloat = 20  // Visual circle size

            let container = UIView(frame: CGRect(x: 0, y: 0, width: touchSize, height: touchSize))
            container.backgroundColor = .clear

            // Outer ring for visual touch area (semi-transparent)
            let outerRing = UIView(frame: CGRect(x: (touchSize - 32) / 2, y: (touchSize - 32) / 2, width: 32, height: 32))
            outerRing.backgroundColor = UIColor.adaptiveBrandTeal.withAlphaComponent(0.2)
            outerRing.layer.cornerRadius = 16
            container.addSubview(outerRing)

            // Inner circle (solid)
            let innerCircle = UIView(frame: CGRect(x: (touchSize - innerSize) / 2, y: (touchSize - innerSize) / 2, width: innerSize, height: innerSize))
            innerCircle.backgroundColor = UIColor.adaptiveBrandTeal
            innerCircle.layer.cornerRadius = innerSize / 2
            innerCircle.layer.borderWidth = 2.5
            innerCircle.layer.borderColor = UIColor.white.cgColor
            innerCircle.layer.shadowColor = UIColor.black.cgColor
            innerCircle.layer.shadowOffset = CGSize(width: 0, height: 1)
            innerCircle.layer.shadowOpacity = 0.3
            innerCircle.layer.shadowRadius = 2
            container.addSubview(innerCircle)

            // Add number label for first vertex
            if annotation.index == 0 {
                let numberLabel = UILabel(frame: innerCircle.bounds)
                numberLabel.text = "1"
                numberLabel.textColor = .white
                numberLabel.font = .systemFont(ofSize: 11, weight: .bold)
                numberLabel.textAlignment = .center
                innerCircle.addSubview(numberLabel)
            }

            annotationView?.image = container.asImage()
            annotationView?.centerOffset = CGPoint.zero
            annotationView?.zPriority = MKAnnotationViewZPriority(rawValue: 1000)  // Above other annotations

            return annotationView!
        }

        private func createMidpointView(for annotation: MidpointAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "MidpointVertex"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier)

            if annotationView == nil {
                annotationView = MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = false
            } else {
                annotationView?.annotation = annotation
            }

            // Create midpoint marker with "+" symbol
            let size: CGFloat = 24
            let container = UIView(frame: CGRect(x: 0, y: 0, width: size, height: size))

            // Circle background
            let circle = UIView(frame: container.bounds)
            circle.backgroundColor = UIColor.systemTeal.withAlphaComponent(0.7)
            circle.layer.cornerRadius = size / 2
            circle.layer.borderWidth = 2
            circle.layer.borderColor = UIColor.white.cgColor
            container.addSubview(circle)

            // Plus symbol
            let plusLabel = UILabel(frame: container.bounds)
            plusLabel.text = "+"
            plusLabel.textColor = .white
            plusLabel.font = .systemFont(ofSize: 16, weight: .bold)
            plusLabel.textAlignment = .center
            container.addSubview(plusLabel)

            annotationView?.image = container.asImage()
            annotationView?.centerOffset = CGPoint.zero
            annotationView?.zPriority = MKAnnotationViewZPriority(rawValue: 900)  // Below vertex annotations

            return annotationView!
        }

        private func createCityPriceView(for annotation: CityPriceAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "CityPrice"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier)

            if annotationView == nil {
                annotationView = MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = true
            } else {
                annotationView?.annotation = annotation
            }

            // Create city price label with bubble background
            let container = UIView()
            container.backgroundColor = .clear

            // Price bubble
            let bubbleWidth: CGFloat = 70
            let bubbleHeight: CGFloat = 28
            let bubble = UIView(frame: CGRect(x: 0, y: 0, width: bubbleWidth, height: bubbleHeight))
            bubble.backgroundColor = annotation.heatColor
            bubble.layer.cornerRadius = bubbleHeight / 2
            bubble.layer.shadowColor = UIColor.black.cgColor
            bubble.layer.shadowOffset = CGSize(width: 0, height: 2)
            bubble.layer.shadowOpacity = 0.3
            bubble.layer.shadowRadius = 4

            // Price label
            let priceLabel = UILabel(frame: bubble.bounds)
            priceLabel.text = annotation.priceLabel
            priceLabel.textColor = .white
            priceLabel.font = .systemFont(ofSize: 12, weight: .bold)
            priceLabel.textAlignment = .center
            bubble.addSubview(priceLabel)

            // City name label below
            let nameLabel = UILabel(frame: CGRect(x: -20, y: bubbleHeight + 2, width: bubbleWidth + 40, height: 16))
            nameLabel.text = annotation.analytics.name
            nameLabel.textColor = UIColor.label
            nameLabel.font = .systemFont(ofSize: 10, weight: .medium)
            nameLabel.textAlignment = .center
            nameLabel.backgroundColor = UIColor.systemBackground.withAlphaComponent(0.8)
            nameLabel.layer.cornerRadius = 4
            nameLabel.clipsToBounds = true

            container.frame = CGRect(x: 0, y: 0, width: bubbleWidth + 40, height: bubbleHeight + 20)
            bubble.center = CGPoint(x: container.bounds.midX, y: bubbleHeight / 2)
            nameLabel.center = CGPoint(x: container.bounds.midX, y: bubbleHeight + 10)

            container.addSubview(bubble)
            container.addSubview(nameLabel)

            annotationView?.image = container.asImage()
            annotationView?.centerOffset = CGPoint(x: 0, y: -bubbleHeight / 2)
            annotationView?.zPriority = MKAnnotationViewZPriority(rawValue: 50) // Below property markers

            return annotationView!
        }

        private func createSchoolView(for annotation: SchoolAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "SchoolMarker"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier)

            if annotationView == nil {
                annotationView = MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = true
            } else {
                annotationView?.annotation = annotation
            }

            // Create school marker with level-based color
            let size: CGFloat = 32
            let container = UIView(frame: CGRect(x: 0, y: 0, width: size, height: size))

            // Circle background
            let circle = UIView(frame: container.bounds)
            circle.backgroundColor = annotation.levelColor
            circle.layer.cornerRadius = size / 2
            circle.layer.borderWidth = 2
            circle.layer.borderColor = UIColor.white.cgColor
            circle.layer.shadowColor = UIColor.black.cgColor
            circle.layer.shadowOffset = CGSize(width: 0, height: 2)
            circle.layer.shadowOpacity = 0.3
            circle.layer.shadowRadius = 3
            container.addSubview(circle)

            // Level icon letter (E, M, H, S)
            let iconLabel = UILabel(frame: container.bounds)
            iconLabel.text = annotation.levelIcon
            iconLabel.textColor = .white
            iconLabel.font = .systemFont(ofSize: 14, weight: .bold)
            iconLabel.textAlignment = .center
            container.addSubview(iconLabel)

            annotationView?.image = container.asImage()
            annotationView?.centerOffset = CGPoint(x: 0, y: -size / 2)
            annotationView?.zPriority = MKAnnotationViewZPriority(rawValue: 75) // Between city prices and properties

            return annotationView!
        }

        private func createTransitView(for annotation: TransitAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "TransitMarker"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier)

            if annotationView == nil {
                annotationView = MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = true
            } else {
                annotationView?.annotation = annotation
            }

            // Create transit marker with MBTA line color
            let size: CGFloat = 28
            let container = UIView(frame: CGRect(x: 0, y: 0, width: size, height: size))

            // Circle background with line color
            let circle = UIView(frame: container.bounds)
            circle.backgroundColor = annotation.lineColor
            circle.layer.cornerRadius = size / 2
            circle.layer.borderWidth = 2
            circle.layer.borderColor = UIColor.white.cgColor
            circle.layer.shadowColor = UIColor.black.cgColor
            circle.layer.shadowOffset = CGSize(width: 0, height: 2)
            circle.layer.shadowOpacity = 0.3
            circle.layer.shadowRadius = 3
            container.addSubview(circle)

            // Transit icon (T for subway, train for commuter rail)
            let iconLabel = UILabel(frame: container.bounds)
            switch annotation.station.type {
            case .subway:
                iconLabel.text = "T"
            case .commuterRail:
                iconLabel.text = "🚆"
            case .bus:
                iconLabel.text = "🚌"
            case .ferry:
                iconLabel.text = "⛴"
            }
            iconLabel.textColor = .white
            iconLabel.font = .systemFont(ofSize: 14, weight: .bold)
            iconLabel.textAlignment = .center
            container.addSubview(iconLabel)

            annotationView?.image = container.asImage()
            annotationView?.centerOffset = CGPoint(x: 0, y: -size / 2)
            annotationView?.zPriority = MKAnnotationViewZPriority(rawValue: 70) // Below schools, above city prices

            return annotationView!
        }

        private func createNeighborhoodLabelView(for annotation: NeighborhoodLabelAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "NeighborhoodLabel"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier)

            if annotationView == nil {
                annotationView = MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = false
            } else {
                annotationView?.annotation = annotation
            }

            // Create a subtle text label for neighborhood name
            let labelWidth: CGFloat = 120
            let labelHeight: CGFloat = 20

            let container = UIView(frame: CGRect(x: 0, y: 0, width: labelWidth, height: labelHeight))
            container.backgroundColor = .clear

            // Neighborhood name label with subtle styling
            let nameLabel = UILabel(frame: container.bounds)
            nameLabel.text = annotation.name.uppercased()
            nameLabel.textColor = UIColor.secondaryLabel.withAlphaComponent(0.7)
            nameLabel.font = .systemFont(ofSize: 10, weight: .semibold)
            nameLabel.textAlignment = .center
            nameLabel.adjustsFontSizeToFitWidth = true
            nameLabel.minimumScaleFactor = 0.7

            // Add subtle text shadow for better readability on map
            nameLabel.layer.shadowColor = UIColor.systemBackground.cgColor
            nameLabel.layer.shadowOffset = CGSize(width: 0, height: 0)
            nameLabel.layer.shadowOpacity = 0.8
            nameLabel.layer.shadowRadius = 2

            container.addSubview(nameLabel)

            annotationView?.image = container.asImage()
            annotationView?.centerOffset = CGPoint.zero
            annotationView?.zPriority = MKAnnotationViewZPriority(rawValue: 25) // Below everything else

            return annotationView!
        }

        private func createPriceMarkerView(for annotation: PropertyAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "PriceMarker"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier)

            if annotationView == nil {
                annotationView = MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = false
            } else {
                annotationView?.annotation = annotation
            }

            // Determine marker state
            let property = annotation.property
            let isArchived = property?.standardStatus != .active
            let hasOpenHouse = property?.hasOpenHouse ?? false
            let state: MarkerState = isArchived ? .archived : .normal

            // Check if property has been viewed (for viewed indicator)
            let isViewed = property.map { parent.recentlyViewedIds.contains($0.id) } ?? false

            // v6.65.0: Check if exclusive listing
            let isExclusive = property?.isExclusive ?? false

            // Create price pill view matching web styling with favorite/price indicators
            let priceLabel = createPricePillLabel(
                text: annotation.priceText,
                isCluster: false,
                isArchived: isArchived,
                hasOpenHouse: hasOpenHouse,
                isFavorite: annotation.isFavorite,
                isPriceReduced: annotation.isPriceReduced,
                isPriceIncreased: annotation.isPriceIncreased,
                isViewed: isViewed,
                isExclusive: isExclusive
            )
            annotationView?.image = priceLabel.asImage()
            annotationView?.centerOffset = CGPoint(x: 0, y: -15)

            // Z-index based on state (viewed properties get slightly lower priority)
            annotationView?.zPriority = isViewed ? MKAnnotationViewZPriority(rawValue: state.zPriority.rawValue - 1) : state.zPriority

            // Add long-press gesture for Apple Maps/Street View (only add once)
            if annotationView?.gestureRecognizers?.contains(where: { $0 is UILongPressGestureRecognizer }) != true {
                let longPress = UILongPressGestureRecognizer(target: self, action: #selector(handleLongPressOnPin(_:)))
                longPress.minimumPressDuration = 0.5
                annotationView?.addGestureRecognizer(longPress)
            }

            return annotationView!
        }

        private func createClusterView(for annotation: PropertyAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "ClusterMarker"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier)

            if annotationView == nil {
                annotationView = MKAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = false
            } else {
                annotationView?.annotation = annotation
            }

            // Create cluster badge view matching web styling (teal)
            let unitCount = annotation.properties.count
            let clusterLabel = createPricePillLabel(
                text: "\(unitCount) Units",
                isCluster: true,
                isArchived: false,
                hasOpenHouse: false
            )
            annotationView?.image = clusterLabel.asImage()
            annotationView?.centerOffset = CGPoint(x: 0, y: -15)

            // Clusters have higher z-index
            annotationView?.zPriority = .max

            return annotationView!
        }

        private func createPricePillLabel(text: String, isCluster: Bool, isArchived: Bool, hasOpenHouse: Bool, isFavorite: Bool = false, isPriceReduced: Bool = false, isPriceIncreased: Bool = false, isViewed: Bool = false, isExclusive: Bool = false) -> UIView {
            let container = UIView()

            // Calculate width based on content - smaller font and dimensions
            let font = UIFont.systemFont(ofSize: 10, weight: .semibold)
            let textSize = (text as NSString).size(withAttributes: [.font: font])

            let padding: CGFloat = 10
            let pillHeight: CGFloat = 18
            var width = textSize.width + padding

            // Add space for open house icon
            if hasOpenHouse && !isCluster {
                width += 14 // Icon width + spacing
            }

            // Add space for favorite heart icon
            if isFavorite && !isCluster {
                width += 12 // Heart icon width + spacing
            }

            // Add space for price change arrow
            if (isPriceReduced || isPriceIncreased) && !isCluster {
                width += 10 // Arrow icon width + spacing
            }

            // v6.65.0: Calculate star banner dimensions for exclusive listings
            let starBannerHeight: CGFloat = isExclusive && !isCluster ? 14 : 0
            let starBannerSpacing: CGFloat = isExclusive && !isCluster ? 4 : 0
            let yOffset: CGFloat = starBannerHeight + starBannerSpacing  // Offset content down if exclusive
            let height = pillHeight  // Keep track of pill height for element positioning

            container.frame = CGRect(x: 0, y: 0, width: width, height: pillHeight + yOffset)

            // Create pill background view (offset down if exclusive)
            let pillView = UIView(frame: CGRect(x: 0, y: yOffset, width: width, height: pillHeight))

            // Background color based on state - favorites get pink/red border, exclusive get gold border
            if isCluster {
                pillView.backgroundColor = UIColor.adaptiveBrandTeal
                pillView.layer.borderWidth = 1.5
                pillView.layer.borderColor = UIColor.white.cgColor
            } else if isArchived {
                pillView.backgroundColor = UIColor.adaptiveMarkerArchived
                pillView.layer.borderWidth = 1
                pillView.layer.borderColor = UIColor.adaptiveMarkerArchivedBorder.cgColor
            } else if isFavorite {
                // Favorites get a subtle pink/red tint border
                pillView.backgroundColor = UIColor.adaptiveMarkerActive
                pillView.layer.borderWidth = 2
                pillView.layer.borderColor = UIColor.systemPink.cgColor
            } else if isExclusive {
                // v6.65.0: Exclusive listings get gold border
                pillView.backgroundColor = UIColor.adaptiveMarkerActive
                pillView.layer.borderWidth = 2
                pillView.layer.borderColor = UIColor(red: 0.85, green: 0.65, blue: 0.13, alpha: 1.0).cgColor
            } else {
                pillView.backgroundColor = UIColor.adaptiveMarkerActive
                pillView.layer.borderWidth = 1
                pillView.layer.borderColor = UIColor.adaptiveMarkerBorder.cgColor
            }

            pillView.layer.cornerRadius = 8
            pillView.clipsToBounds = false

            // Shadow on pill
            pillView.layer.shadowColor = UIColor.black.cgColor
            pillView.layer.shadowOffset = CGSize(width: 0, height: 1)
            pillView.layer.shadowOpacity = 0.15
            pillView.layer.shadowRadius = 3

            container.addSubview(pillView)

            var xOffset: CGFloat = padding/2

            // Add favorite heart icon if applicable (at start of pill)
            if isFavorite && !isCluster {
                let heartIcon = UIImageView(frame: CGRect(x: xOffset, y: (height - 9) / 2, width: 9, height: 9))
                heartIcon.image = UIImage(systemName: "heart.fill")?.withRenderingMode(.alwaysTemplate)
                heartIcon.tintColor = .systemPink
                pillView.addSubview(heartIcon)
                xOffset += 11
            }

            // Add open house icon if applicable
            if hasOpenHouse && !isCluster {
                let homeIcon = UIImageView(frame: CGRect(x: xOffset, y: (height - 10) / 2, width: 10, height: 10))
                homeIcon.image = UIImage(systemName: "house.fill")?.withRenderingMode(.alwaysTemplate)
                homeIcon.tintColor = .white
                pillView.addSubview(homeIcon)
                xOffset += 12
            }

            let label = UILabel()
            label.text = text
            label.font = font
            label.textColor = isArchived ? UIColor.adaptiveMarkerArchivedText : .white
            label.textAlignment = .center
            label.frame = CGRect(x: xOffset, y: 0, width: textSize.width, height: height)
            pillView.addSubview(label)
            xOffset += textSize.width + 2

            // Add price change arrow at end (green down for reduced, orange up for increased)
            if isPriceReduced && !isCluster {
                let arrowIcon = UIImageView(frame: CGRect(x: xOffset, y: (height - 8) / 2, width: 8, height: 8))
                arrowIcon.image = UIImage(systemName: "arrow.down")?.withRenderingMode(.alwaysTemplate)
                arrowIcon.tintColor = .systemGreen
                pillView.addSubview(arrowIcon)
            } else if isPriceIncreased && !isCluster {
                let arrowIcon = UIImageView(frame: CGRect(x: xOffset, y: (height - 8) / 2, width: 8, height: 8))
                arrowIcon.image = UIImage(systemName: "arrow.up")?.withRenderingMode(.alwaysTemplate)
                arrowIcon.tintColor = .systemOrange
                pillView.addSubview(arrowIcon)
            }

            // Add pointer triangle at bottom
            let pointerSize: CGFloat = 4
            let pointerPath = UIBezierPath()
            pointerPath.move(to: CGPoint(x: width/2 - pointerSize, y: pillHeight))
            pointerPath.addLine(to: CGPoint(x: width/2, y: pillHeight + pointerSize))
            pointerPath.addLine(to: CGPoint(x: width/2 + pointerSize, y: pillHeight))
            pointerPath.close()

            let pointerLayer = CAShapeLayer()
            pointerLayer.path = pointerPath.cgPath
            if isCluster {
                pointerLayer.fillColor = UIColor.adaptiveBrandTeal.cgColor
            } else if isArchived {
                pointerLayer.fillColor = UIColor.adaptiveMarkerArchived.cgColor
            } else {
                pointerLayer.fillColor = UIColor.adaptiveMarkerActive.cgColor
            }
            pillView.layer.addSublayer(pointerLayer)

            // Adjust container bounds to include pointer and star banner
            container.bounds = CGRect(x: 0, y: 0, width: width, height: pillHeight + pointerSize + yOffset)

            // Apply viewed indicator - reduce opacity for properties user has already viewed
            if isViewed && !isCluster {
                container.alpha = 0.55
            }

            // v6.65.0: Add star banner above the marker for exclusive listings
            if isExclusive && !isCluster {
                let starBannerWidth: CGFloat = 20
                let actualStarBannerHeight: CGFloat = 14

                let starBanner = UIView()
                starBanner.frame = CGRect(
                    x: (width - starBannerWidth) / 2,
                    y: 0,  // At top of container
                    width: starBannerWidth,
                    height: actualStarBannerHeight
                )
                starBanner.backgroundColor = UIColor(red: 0.85, green: 0.65, blue: 0.13, alpha: 1.0)
                starBanner.layer.cornerRadius = 7
                starBanner.layer.shadowColor = UIColor.black.cgColor
                starBanner.layer.shadowOffset = CGSize(width: 0, height: 1)
                starBanner.layer.shadowOpacity = 0.2
                starBanner.layer.shadowRadius = 2

                let starIcon = UIImageView(frame: CGRect(x: (starBannerWidth - 10) / 2, y: (actualStarBannerHeight - 10) / 2, width: 10, height: 10))
                starIcon.image = UIImage(systemName: "star.fill")?.withRenderingMode(.alwaysTemplate)
                starIcon.tintColor = .white
                starBanner.addSubview(starIcon)

                container.addSubview(starBanner)
            }

            return container
        }

        func mapView(_ mapView: MKMapView, didSelect view: MKAnnotationView) {
            // Handle midpoint annotation selection - insert new vertex
            if let midpointAnnotation = view.annotation as? MidpointAnnotation {
                // Deselect immediately to allow re-selection
                mapView.deselectAnnotation(midpointAnnotation, animated: false)

                // Insert new vertex at the midpoint position
                // Validate index to prevent edge cases with negative indices
                let insertIndex = midpointAnnotation.edgeIndex + 1
                if insertIndex >= 0 && insertIndex <= parent.polygonCoordinates.count {
                    // Haptic feedback for vertex insertion
                    let impactFeedback = UIImpactFeedbackGenerator(style: .medium)
                    impactFeedback.impactOccurred()

                    // Insert the new coordinate
                    parent.polygonCoordinates.insert(midpointAnnotation.coordinate, at: insertIndex)
                }
                return
            }

            // Handle school annotation selection
            if let schoolAnnotation = view.annotation as? SchoolAnnotation {
                // Animate selection
                UIView.animate(withDuration: 0.2, delay: 0, usingSpringWithDamping: 0.6, initialSpringVelocity: 0.5) {
                    view.transform = CGAffineTransform(scaleX: 1.2, y: 1.2)
                }
                // Trigger callback
                parent.onSchoolSelected?(schoolAnnotation.school)
                return
            }

            // Handle property annotation selection
            guard let annotation = view.annotation as? PropertyAnnotation else { return }

            // Animate selection with spring effect
            UIView.animate(withDuration: 0.2, delay: 0, usingSpringWithDamping: 0.6, initialSpringVelocity: 0.5) {
                view.transform = CGAffineTransform(scaleX: MarkerState.selected.scale, y: MarkerState.selected.scale)
            }

            // Update z-priority
            view.zPriority = MarkerState.selected.zPriority

            parent.selectedAnnotation = annotation
        }

        func mapView(_ mapView: MKMapView, didDeselect view: MKAnnotationView) {
            // Animate deselection
            UIView.animate(withDuration: 0.15) {
                view.transform = .identity
            }

            // Reset z-priority for property annotations
            if let annotation = view.annotation as? PropertyAnnotation {
                let isArchived = annotation.property?.standardStatus != .active
                view.zPriority = isArchived ? MarkerState.archived.zPriority : MarkerState.normal.zPriority
            }

            // Reset z-priority for school annotations
            if view.annotation is SchoolAnnotation {
                view.zPriority = MKAnnotationViewZPriority(rawValue: 75)
            }
        }

        // Handle dragging for polygon vertices
        func mapView(_ mapView: MKMapView, annotationView view: MKAnnotationView, didChange newState: MKAnnotationView.DragState, fromOldState oldState: MKAnnotationView.DragState) {
            guard let vertex = view.annotation as? PolygonVertexAnnotation,
                  vertex.index >= 0 && vertex.index < parent.polygonCoordinates.count else { return }

            switch newState {
            case .dragging:
                // Update polygon in real-time during drag for visual feedback
                parent.polygonCoordinates[vertex.index] = vertex.coordinate

            case .ending:
                // Final update when drag completes
                parent.polygonCoordinates[vertex.index] = vertex.coordinate

                // Haptic feedback on completion
                let impactFeedback = UIImpactFeedbackGenerator(style: .light)
                impactFeedback.impactOccurred()

            default:
                break
            }
        }

        // MARK: - Long Press to Open Apple Maps

        @objc func handleLongPressOnPin(_ gesture: UILongPressGestureRecognizer) {
            guard gesture.state == .began else { return }

            // Find the annotation view and its annotation
            guard let annotationView = gesture.view as? MKAnnotationView,
                  let annotation = annotationView.annotation as? PropertyAnnotation,
                  let property = annotation.property else { return }

            // Haptic feedback
            let generator = UIImpactFeedbackGenerator(style: .medium)
            generator.impactOccurred()

            // Open Apple Maps at this location (Look Around / Street View will be available if supported)
            guard let lat = property.latitude, let lng = property.longitude else { return }

            let coordinate = CLLocationCoordinate2D(latitude: lat, longitude: lng)
            let placemark = MKPlacemark(coordinate: coordinate)
            let mapItem = MKMapItem(placemark: placemark)
            mapItem.name = property.address

            // Open in Maps app - Look Around will be available automatically
            mapItem.openInMaps(launchOptions: [
                MKLaunchOptionsMapTypeKey: MKMapType.standard.rawValue
            ])
        }
    }
}

// MARK: - Polygon Vertex Annotation

class PolygonVertexAnnotation: NSObject, MKAnnotation {
    dynamic var coordinate: CLLocationCoordinate2D
    let index: Int
    var isSelected: Bool = false

    init(coordinate: CLLocationCoordinate2D, index: Int) {
        self.coordinate = coordinate
        self.index = index
        super.init()
    }
}

// MARK: - Midpoint Annotation (for adding vertices between existing ones)

class MidpointAnnotation: NSObject, MKAnnotation {
    let coordinate: CLLocationCoordinate2D
    let edgeIndex: Int  // Index of the edge (between vertex[edgeIndex] and vertex[edgeIndex+1])

    init(coordinate: CLLocationCoordinate2D, edgeIndex: Int) {
        self.coordinate = coordinate
        self.edgeIndex = edgeIndex
        super.init()
    }
}

// MARK: - Property Annotation

class PropertyAnnotation: NSObject, MKAnnotation {
    let properties: [Property]
    let coordinate: CLLocationCoordinate2D
    let isCluster: Bool

    var property: Property? {
        properties.first
    }

    var title: String? {
        if isCluster {
            return "\(properties.count) Units"
        }
        return property?.address
    }

    var subtitle: String? {
        guard let prop = property else { return nil }
        return "\(prop.beds) bd | \(Int(prop.baths)) ba | \(prop.formattedPrice)"
    }

    var priceText: String {
        guard let price = property?.price else { return "$0" }
        if price >= 1_000_000 {
            let millions = Double(price) / 1_000_000.0
            return String(format: "$%.1fM", millions)
        } else if price >= 10_000 {
            let thousands = price / 1000
            return "$\(thousands)K"
        } else if price >= 1_000 {
            // Show with decimal for $1K-$9.9K (e.g., $1,500 → "$1.5K")
            let thousands = Double(price) / 1000.0
            return String(format: "$%.1fK", thousands)
        } else {
            // Under $1K: round to nearest $100 and show actual price
            let hundreds = ((price + 50) / 100) * 100
            return "$\(hundreds)"
        }
    }

    // Favorite status for heart indicator
    var isFavorite: Bool {
        property?.isFavorite ?? false
    }

    // Price reduction status for arrow indicator
    var isPriceReduced: Bool {
        property?.isPriceReduced ?? false
    }

    // Check if price increased (rare but possible)
    var isPriceIncreased: Bool {
        guard let orig = property?.originalPrice, let curr = property?.price else { return false }
        return curr > orig
    }

    // Archived status (sold, pending, etc.)
    var isArchived: Bool {
        guard let status = property?.standardStatus else { return false }
        return status != .active
    }

    // Has open house scheduled
    var hasOpenHouse: Bool {
        property?.hasOpenHouse ?? false
    }

    init(properties: [Property], coordinate: CLLocationCoordinate2D, isCluster: Bool) {
        self.properties = properties
        self.coordinate = coordinate
        self.isCluster = isCluster
        super.init()
    }
}

// MARK: - City Price Annotation (for zoomed-out price overlays)

class CityPriceAnnotation: NSObject, MKAnnotation {
    let analytics: NeighborhoodAnalytics
    let coordinate: CLLocationCoordinate2D

    var title: String? {
        analytics.name
    }

    var subtitle: String? {
        "\(analytics.listingCount) listings"
    }

    var priceLabel: String {
        analytics.formattedMedianPrice
    }

    var heatColor: UIColor {
        switch analytics.marketHeat {
        case "hot": return UIColor.systemRed
        case "warm": return UIColor.systemOrange
        case "balanced": return UIColor.systemGreen
        case "cool": return UIColor.systemBlue
        case "cold": return UIColor.systemIndigo
        default: return UIColor.systemGray
        }
    }

    init(analytics: NeighborhoodAnalytics) {
        self.analytics = analytics
        self.coordinate = analytics.center.coordinate
        super.init()
    }
}

// MARK: - Neighborhood Label Annotation (for zoomed-in neighborhood names)

class NeighborhoodLabelAnnotation: NSObject, MKAnnotation {
    let name: String
    let coordinate: CLLocationCoordinate2D
    let listingCount: Int

    var title: String? { name }
    var subtitle: String? { nil }

    init(name: String, coordinate: CLLocationCoordinate2D, listingCount: Int) {
        self.name = name
        self.coordinate = coordinate
        self.listingCount = listingCount
        super.init()
    }
}

// MARK: - School Annotation

class SchoolAnnotation: NSObject, MKAnnotation {
    let school: MapSchool
    let coordinate: CLLocationCoordinate2D

    var title: String? {
        school.name
    }

    var subtitle: String? {
        school.level?.capitalized ?? "School"
    }

    /// Color based on school level
    var levelColor: UIColor {
        switch school.level {
        case "elementary": return UIColor.systemGreen
        case "middle": return UIColor.systemBlue
        case "high": return UIColor.systemPurple
        default: return UIColor.systemOrange
        }
    }

    /// Icon letter based on school level
    var levelIcon: String {
        switch school.level {
        case "elementary": return "E"
        case "middle": return "M"
        case "high": return "H"
        default: return "S"
        }
    }

    init(school: MapSchool) {
        self.school = school
        self.coordinate = school.coordinate
        super.init()
    }
}

// MARK: - Transit Annotation

class TransitAnnotation: NSObject, MKAnnotation {
    let station: TransitStation
    let coordinate: CLLocationCoordinate2D

    var title: String? {
        station.name
    }

    var subtitle: String? {
        station.line ?? station.type.displayName
    }

    /// Color based on MBTA line
    var lineColor: UIColor {
        guard let line = MBTALine.from(station.line) else {
            return UIColor.systemGray
        }
        return UIColor(hex: line.color) ?? UIColor.systemGray
    }

    init(station: TransitStation) {
        self.station = station
        self.coordinate = station.coordinate
        super.init()
    }
}

// MARK: - UIView to UIImage Extension

extension UIView {
    func asImage() -> UIImage {
        let format = UIGraphicsImageRendererFormat()
        format.scale = UIScreen.main.scale
        format.opaque = false

        let renderer = UIGraphicsImageRenderer(bounds: bounds, format: format)
        return renderer.image { context in
            layer.render(in: context.cgContext)
        }
    }
}

// MARK: - SwiftUI Wrapper with Bottom Card

struct PropertyMapViewWithCard: View {
    let properties: [Property]
    let onPropertySelected: (Property) -> Void
    let onFavoriteTap: (Property) -> Void
    var onHideTap: ((Property) -> Void)? = nil
    var onPolygonDrawn: (([CLLocationCoordinate2D]) -> Void)?
    var onMultipleShapesDrawn: (([[CLLocationCoordinate2D]]) -> Void)?  // For multi-shape search (OR logic)
    var onPolygonCleared: (() -> Void)?  // Called when all shapes are cleared to reset search
    var onBoundsChanged: ((MapBounds) -> Void)?
    var propertiesVersion: Int = 0  // Used to clear selection when properties change
    @Binding var targetMapRegion: MKCoordinateRegion?  // For auto-zoom on location filter
    @Binding var animateMapRegion: Bool  // Whether to animate the region change
    @Binding var cityBoundaries: [MKPolygon]  // City boundary overlays - binding for direct updates
    @Binding var boundariesVersion: Int  // Used to force UI update when boundaries change - MUST be binding!
    var cityPriceAnnotations: [NeighborhoodAnalytics] = []  // City price overlays for zoomed-out view
    var schoolAnnotations: [MapSchool] = []  // School pins for map overlay
    var onSchoolSelected: ((MapSchool) -> Void)?  // Callback when school pin is tapped
    var transitAnnotations: [TransitStation] = []  // Transit station pins for map overlay
    var transitRoutes: [TransitRoute] = []  // Transit route polylines for map overlay
    var transitRoutesVersion: Int = 0  // Version counter to force updates when routes load
    var onTransitSelected: ((TransitStation) -> Void)?  // Callback when transit pin is tapped
    var showTransit: Bool = false  // When true, shows transit overlays
    var autoSelectSingleResult: Bool = true  // Automatically select and show card when only 1 result
    var externalPolygonCoordinates: [CLLocationCoordinate2D]? = nil  // External polygon from saved search
    var recentlyViewedIds: [String] = []  // IDs of viewed properties for visual indicator

    @State private var selectedAnnotation: PropertyAnnotation?
    @State private var lastAutoSelectedId: String?  // Track to avoid re-selecting same property
    @State private var mapType: MKMapType = .standard
    @State private var drawingMode: MapDrawingMode = .none
    @State private var polygonCoordinates: [CLLocationCoordinate2D] = []
    @State private var freehandPoints: [CLLocationCoordinate2D] = []  // Points during freehand drawing
    @State private var isActivelyDrawing: Bool = false  // True when finger is down during drawing
    @State private var showDrawingControls: Bool = false
    @State private var shouldCenterOnUser: Bool = false
    @State private var lastPolygonUpdateTime: Date = Date()  // Track when polygon was last applied
    @State private var completedShapes: [[CLLocationCoordinate2D]] = []  // Previously completed shapes for multi-shape support

    var body: some View {
        let _ = mapLogger.info("PropertyMapViewWithCard body: cityBoundaries=\(self.cityBoundaries.count), version=\(self.boundariesVersion)")

        ZStack(alignment: .bottom) {
            PropertyMapView(
                properties: properties,
                onPropertySelected: onPropertySelected,
                onFavoriteTap: onFavoriteTap,
                selectedAnnotation: $selectedAnnotation,
                drawingMode: $drawingMode,
                polygonCoordinates: $polygonCoordinates,
                completedShapes: $completedShapes,
                freehandPoints: $freehandPoints,
                isActivelyDrawing: $isActivelyDrawing,
                shouldCenterOnUser: $shouldCenterOnUser,
                targetMapRegion: $targetMapRegion,
                animateMapRegion: $animateMapRegion,
                mapType: $mapType,
                cityBoundaries: $cityBoundaries,
                boundariesVersion: $boundariesVersion,
                cityPriceAnnotations: cityPriceAnnotations,
                schoolAnnotations: schoolAnnotations,
                transitAnnotations: transitAnnotations,
                transitRoutes: transitRoutes,
                transitRoutesVersion: transitRoutesVersion,
                showTransit: showTransit,
                onBoundsChanged: onBoundsChanged,
                onSchoolSelected: onSchoolSelected,
                onTransitSelected: onTransitSelected,
                recentlyViewedIds: recentlyViewedIds
            )
            .onAppear {
                // When map appears, ensure boundaries are synced
                // This handles the case where boundaries were cleared while in list mode
                if cityBoundaries.isEmpty {
                    mapLogger.info("Map appeared with empty boundaries - posting clear notification")
                    NotificationCenter.default.post(name: .clearCityBoundaries, object: nil)
                }
            }

            // Drawing toolbar overlay - only shown when drawing mode is active
            // Hide toolbar while actively drawing to give more screen space
            VStack {
                Spacer()

                HStack(alignment: .bottom) {
                    // Drawing toolbar (left side) - hide while actively drawing
                    if showDrawingControls && !isActivelyDrawing && drawingMode == .none {
                        DrawingToolbar(
                            drawingMode: $drawingMode,
                            vertexCount: polygonCoordinates.count,
                            completedShapesCount: completedShapes.count,
                            onClearDrawing: clearDrawing,
                            onApplyDrawing: applyDrawing,
                            onUndo: undoLastVertex
                        )
                        .transition(.move(edge: .leading).combined(with: .opacity))
                    }

                    // Show minimal "Done" button when actively in drawing mode
                    if showDrawingControls && drawingMode != .none && !isActivelyDrawing {
                        VStack(spacing: 8) {
                            // Mode indicator
                            Text(drawingMode.instructionText)
                                .font(.caption)
                                .foregroundStyle(.white)
                                .padding(.horizontal, 12)
                                .padding(.vertical, 6)
                                .background(Color.black.opacity(0.6))
                                .clipShape(Capsule())

                            // Shape/Point count indicator
                            if completedShapes.count > 0 || polygonCoordinates.count > 0 {
                                let totalShapes = completedShapes.count + (polygonCoordinates.count >= 3 ? 1 : 0)
                                let pointText = drawingMode == .polygon && polygonCoordinates.count > 0
                                    ? "\(polygonCoordinates.count) pts"
                                    : ""
                                let shapeText = totalShapes > 0
                                    ? "\(totalShapes) area\(totalShapes == 1 ? "" : "s")"
                                    : ""
                                let displayText = [shapeText, pointText].filter { !$0.isEmpty }.joined(separator: " • ")

                                if !displayText.isEmpty {
                                    Text(displayText)
                                        .font(.caption2)
                                        .foregroundStyle(.white)
                                        .padding(.horizontal, 10)
                                        .padding(.vertical, 4)
                                        .background(Color.black.opacity(0.5))
                                        .clipShape(Capsule())
                                }
                            }

                            HStack(spacing: 8) {
                                // Cancel button
                                Button {
                                    withAnimation {
                                        drawingMode = .none
                                        // Clear if polygon isn't complete
                                        if polygonCoordinates.count < 3 {
                                            polygonCoordinates = []
                                        }
                                    }
                                } label: {
                                    Text("Cancel")
                                        .font(.subheadline)
                                        .fontWeight(.medium)
                                        .foregroundStyle(.white)
                                        .padding(.horizontal, 16)
                                        .padding(.vertical, 10)
                                        .background(Color.black.opacity(0.6))
                                        .clipShape(Capsule())
                                }

                                // Add Another Area button (only when current polygon is complete)
                                if polygonCoordinates.count >= 3 {
                                    Button {
                                        withAnimation {
                                            addAnotherShape()
                                        }
                                    } label: {
                                        HStack(spacing: 4) {
                                            Image(systemName: "plus")
                                                .font(.system(size: 12, weight: .semibold))
                                            Text("Add Area")
                                                .font(.subheadline)
                                                .fontWeight(.medium)
                                        }
                                        .foregroundStyle(.white)
                                        .padding(.horizontal, 12)
                                        .padding(.vertical, 10)
                                        .background(Color.black.opacity(0.6))
                                        .clipShape(Capsule())
                                    }
                                }

                                // Done/Search button (only when at least one valid shape exists)
                                let hasValidShape = polygonCoordinates.count >= 3 || completedShapes.count > 0
                                if hasValidShape {
                                    Button {
                                        applyDrawing()
                                    } label: {
                                        let buttonText = completedShapes.count > 0 ? "Search" : "Done"
                                        Text(buttonText)
                                            .font(.subheadline)
                                            .fontWeight(.semibold)
                                            .foregroundStyle(.white)
                                            .padding(.horizontal, 16)
                                            .padding(.vertical, 10)
                                            .background(AppColors.brandTeal)
                                            .clipShape(Capsule())
                                    }
                                }
                            }
                        }
                        .transition(.opacity)
                    }

                    Spacer()
                }
                .padding(.horizontal, 16)
                .padding(.bottom, 100)  // Above property count display
            }
            // Listen for control notifications from unified panel
            .onReceive(NotificationCenter.default.publisher(for: .mapTypeChanged)) { notification in
                if let newMapType = notification.object as? MKMapType {
                    mapType = newMapType
                }
            }
            .onReceive(NotificationCenter.default.publisher(for: .centerOnUserLocation)) { _ in
                shouldCenterOnUser = true
            }
            .onReceive(NotificationCenter.default.publisher(for: .toggleDrawingTools)) { notification in
                if let show = notification.object as? Bool {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        showDrawingControls = show
                        if !show {
                            drawingMode = .none
                        }
                    }
                }
            }

            // Selected property card - positioned above tab bar
            if let annotation = selectedAnnotation {
                if annotation.isCluster {
                    // Multi-unit cluster card
                    MultiUnitCard(
                        properties: annotation.properties,
                        onPropertySelected: onPropertySelected,
                        onDismiss: { selectedAnnotation = nil }
                    )
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .padding(.horizontal)
                    .padding(.bottom, 90) // Above tab bar
                } else if let annotationProperty = annotation.property,
                          // Look up fresh property from properties array to get latest isFavorite state
                          let freshProperty = properties.first(where: { $0.id == annotationProperty.id }) ?? annotationProperty as Property? {
                    PropertyMapCard(
                        property: freshProperty,
                        onTap: { onPropertySelected(freshProperty) },
                        onFavoriteTap: { onFavoriteTap(freshProperty) },
                        onHideTap: onHideTap != nil ? { onHideTap?(freshProperty) } : nil,
                        onDismiss: { selectedAnnotation = nil }
                    )
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .padding(.horizontal)
                    .padding(.bottom, 90) // Above tab bar
                }
            }
        }
        .animation(.easeInOut(duration: 0.25), value: selectedAnnotation?.coordinate.latitude)
        .animation(.easeInOut(duration: 0.2), value: drawingMode)
        .onChange(of: propertiesVersion) { _ in
            // When properties change, handle auto-selection
            if autoSelectSingleResult && properties.count == 1,
               let property = properties.first,
               let lat = property.latitude,
               let lng = property.longitude,
               lastAutoSelectedId != property.id {
                // Auto-select the single result
                let coordinate = CLLocationCoordinate2D(latitude: lat, longitude: lng)
                let annotation = PropertyAnnotation(properties: [property], coordinate: coordinate, isCluster: false)
                selectedAnnotation = annotation
                lastAutoSelectedId = property.id

                // Also zoom to the property location
                targetMapRegion = MKCoordinateRegion(
                    center: coordinate,
                    span: MKCoordinateSpan(latitudeDelta: 0.01, longitudeDelta: 0.01)
                )
            } else if properties.count != 1 {
                // Clear selection when multiple results
                selectedAnnotation = nil
                lastAutoSelectedId = nil
            }
        }
        .onAppear {
            // Sync external polygon coordinates on appear (for saved searches)
            if let external = externalPolygonCoordinates, !external.isEmpty {
                mapLogger.info("Syncing external polygon coordinates on appear: \(external.count) points")
                polygonCoordinates = external
            }
        }
        .onChange(of: externalPolygonCoordinates) { newValue in
            // Sync external polygon coordinates when they change (for saved searches)
            if let external = newValue, !external.isEmpty {
                mapLogger.info("External polygon coordinates changed: \(external.count) points")
                polygonCoordinates = external
            } else if newValue == nil || newValue?.isEmpty == true {
                mapLogger.info("External polygon coordinates cleared")
                polygonCoordinates = []
            }
        }
        // Trigger search when polygon is adjusted (vertex dragged)
        .onChange(of: polygonCoordinates) { newCoords in
            // Only trigger search if:
            // 1. Not currently in drawing mode (polygon was already applied)
            // 2. Have a valid polygon (3+ vertices)
            // 3. Polygon was applied at least 0.5 seconds ago (debounce initial apply)
            let timeSinceApply = Date().timeIntervalSince(lastPolygonUpdateTime)
            if drawingMode == .none &&
               newCoords.count >= 3 &&
               timeSinceApply > 0.5 &&
               !isActivelyDrawing {
                // Vertex was dragged - trigger search with updated polygon
                mapLogger.info("Polygon adjusted - triggering search with \(newCoords.count) vertices")
                onPolygonDrawn?(newCoords)
            }
        }
        .animation(.easeInOut(duration: 0.2), value: isActivelyDrawing)
        .animation(.easeInOut(duration: 0.2), value: drawingMode)
    }

    private func clearDrawing() {
        polygonCoordinates = []
        completedShapes = []
        // Haptic feedback
        let impactFeedback = UIImpactFeedbackGenerator(style: .medium)
        impactFeedback.impactOccurred()
        // Notify that polygon search should be cleared and reset to bounds-based search
        onPolygonCleared?()
    }

    private func undoLastVertex() {
        guard !polygonCoordinates.isEmpty else { return }
        polygonCoordinates.removeLast()
        // Haptic feedback
        let impactFeedback = UIImpactFeedbackGenerator(style: .light)
        impactFeedback.impactOccurred()
    }

    /// Add current shape to completed shapes and prepare for another shape
    private func addAnotherShape() {
        guard polygonCoordinates.count >= 3 else { return }

        // Add current shape to completed shapes
        completedShapes.append(polygonCoordinates)
        polygonCoordinates = []

        // Haptic feedback
        let impactFeedback = UIImpactFeedbackGenerator(style: .medium)
        impactFeedback.impactOccurred()

        // Stay in drawing mode for next shape
        // drawingMode stays the same
    }

    /// Remove a completed shape by index
    private func deleteShape(at index: Int) {
        guard index >= 0 && index < completedShapes.count else { return }
        completedShapes.remove(at: index)

        // Haptic feedback
        let impactFeedback = UIImpactFeedbackGenerator(style: .light)
        impactFeedback.impactOccurred()

        // If no shapes remain, clear the polygon search and reset to bounds-based search
        if completedShapes.isEmpty && polygonCoordinates.count < 3 {
            onPolygonCleared?()
        } else {
            // Trigger search update with remaining shapes
            triggerSearchWithAllShapes()
        }
    }

    /// Trigger search with all drawn shapes
    private func triggerSearchWithAllShapes() {
        // Record time to debounce subsequent vertex drag triggers
        lastPolygonUpdateTime = Date()

        // Build array of separate shapes
        var allShapes: [[CLLocationCoordinate2D]] = completedShapes

        // Add current shape if valid
        if polygonCoordinates.count >= 3 {
            allShapes.append(polygonCoordinates)
        }

        guard !allShapes.isEmpty else { return }

        // Calculate bounding box for ALL shapes for map zoom
        let allCoords = allShapes.flatMap { $0 }
        let lats = allCoords.map { $0.latitude }
        let lngs = allCoords.map { $0.longitude }

        if let minLat = lats.min(), let maxLat = lats.max(),
           let minLng = lngs.min(), let maxLng = lngs.max() {
            let center = CLLocationCoordinate2D(
                latitude: (minLat + maxLat) / 2,
                longitude: (minLng + maxLng) / 2
            )
            let span = MKCoordinateSpan(
                latitudeDelta: (maxLat - minLat) * 1.3,  // 30% padding
                longitudeDelta: (maxLng - minLng) * 1.3
            )
            targetMapRegion = MKCoordinateRegion(center: center, span: span)
        }

        // Use appropriate callback based on number of shapes
        if allShapes.count == 1 {
            // Single shape - use original callback
            onPolygonDrawn?(allShapes[0])
        } else {
            // Multiple shapes - use multi-shape callback for OR logic search
            // Each shape will be searched separately and results combined
            onMultipleShapesDrawn?(allShapes)
        }
    }

    private func applyDrawing() {
        // If current shape is valid, add it to completed shapes
        if polygonCoordinates.count >= 3 {
            completedShapes.append(polygonCoordinates)
            polygonCoordinates = []
        }

        // Trigger search with all shapes
        triggerSearchWithAllShapes()

        showDrawingControls = false
        drawingMode = .none
    }
}

// MARK: - Drawing Toolbar

struct DrawingToolbar: View {
    @Binding var drawingMode: MapDrawingMode
    var vertexCount: Int = 0
    var completedShapesCount: Int = 0
    let onClearDrawing: () -> Void
    let onApplyDrawing: () -> Void
    let onUndo: (() -> Void)?

    init(drawingMode: Binding<MapDrawingMode>, vertexCount: Int = 0, completedShapesCount: Int = 0, onClearDrawing: @escaping () -> Void, onApplyDrawing: @escaping () -> Void, onUndo: (() -> Void)? = nil) {
        self._drawingMode = drawingMode
        self.vertexCount = vertexCount
        self.completedShapesCount = completedShapesCount
        self.onClearDrawing = onClearDrawing
        self.onApplyDrawing = onApplyDrawing
        self.onUndo = onUndo
    }

    /// Total number of items that can be cleared (shapes + current points)
    private var totalDrawnItems: Int {
        completedShapesCount + vertexCount
    }

    var body: some View {
        VStack(spacing: 10) {
            // Drawing mode buttons (only show drawing modes, not "none")
            ForEach(MapDrawingMode.drawingModes, id: \.self) { mode in
                Button {
                    withAnimation(.easeInOut(duration: 0.15)) {
                        drawingMode = mode
                    }
                    // Haptic feedback
                    let impactFeedback = UIImpactFeedbackGenerator(style: .light)
                    impactFeedback.impactOccurred()
                } label: {
                    HStack(spacing: 8) {
                        Image(systemName: mode.icon)
                            .font(.system(size: 16, weight: .medium))
                        Text(mode.rawValue)
                            .font(.subheadline)
                            .fontWeight(.medium)
                    }
                    .foregroundStyle(drawingMode == mode ? .white : AppColors.textSecondary)
                    .frame(maxWidth: .infinity)
                    .frame(height: 40)
                    .background(drawingMode == mode ? AppColors.brandTeal : Color(.secondarySystemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                    .overlay(
                        RoundedRectangle(cornerRadius: 8)
                            .stroke(drawingMode == mode ? Color.clear : AppColors.border, lineWidth: 1)
                    )
                }
                .accessibilityLabel("\(mode.rawValue) drawing mode\(drawingMode == mode ? ", selected" : "")")
                .accessibilityHint("Double tap to select \(mode.rawValue.lowercased()) drawing mode")
                .accessibilityAddTraits(drawingMode == mode ? [.isSelected] : [])
            }

            // Instruction text
            if drawingMode != .none {
                Text(drawingMode.instructionText)
                    .font(.caption)
                    .foregroundStyle(AppColors.textMuted)
                    .multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
                    .padding(.top, 2)
            }

            Divider()
                .padding(.vertical, 4)
                .accessibilityHidden(true)

            // Action buttons row
            HStack(spacing: 12) {
                // Undo button (only in polygon mode)
                if drawingMode == .polygon, let onUndo = onUndo {
                    Button {
                        onUndo()
                    } label: {
                        Image(systemName: "arrow.uturn.backward")
                            .font(.system(size: 16))
                            .foregroundStyle(vertexCount > 0 ? AppColors.textSecondary : AppColors.textMuted)
                            .frame(width: 36, height: 36)
                            .background(Color(.secondarySystemBackground))
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                    .disabled(vertexCount == 0)
                    .accessibilityLabel("Undo last point")
                    .accessibilityHint("Double tap to remove the last placed point")
                }

                // Clear button
                Button {
                    onClearDrawing()
                } label: {
                    Image(systemName: "trash")
                        .font(.system(size: 16))
                        .foregroundStyle(totalDrawnItems > 0 ? .red : AppColors.textMuted)
                        .frame(width: 36, height: 36)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                }
                .disabled(totalDrawnItems == 0)
                .accessibilityLabel("Clear all drawings")
                .accessibilityHint("Double tap to clear the drawn search area")
            }

            // Apply button - enabled if there's a valid current shape or completed shapes
            let canApply = vertexCount >= 3 || completedShapesCount > 0
            Button {
                onApplyDrawing()
            } label: {
                Text(completedShapesCount > 0 ? "Search Areas" : "Apply")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .frame(height: 40)
                    .background(canApply ? AppColors.brandTeal : AppColors.textMuted)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
            }
            .disabled(!canApply)
            .accessibilityLabel(completedShapesCount > 0 ? "Search all drawn areas" : "Apply search area")
            .accessibilityHint("Double tap to search within the drawn area")

            // Count indicator - shows shapes and/or points
            if totalDrawnItems > 0 {
                let shapesText = completedShapesCount > 0
                    ? "\(completedShapesCount) area\(completedShapesCount == 1 ? "" : "s")"
                    : ""
                let pointsText = vertexCount > 0
                    ? "\(vertexCount) pt\(vertexCount == 1 ? "" : "s")"
                    : ""
                let displayText = [shapesText, pointsText].filter { !$0.isEmpty }.joined(separator: " • ")
                Text(displayText)
                    .font(.caption2)
                    .foregroundStyle(AppColors.textMuted)
            }
        }
        .padding(12)
        .frame(width: 140)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: AppColors.shadowMedium, radius: 8, x: 0, y: 4)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Drawing toolbar")
    }
}

// MARK: - Map Controls Panel

struct MapControlsPanel: View {
    @Binding var mapType: MKMapType
    @Binding var showHeatmap: Bool
    let onLocationTap: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            // Heatmap toggle
            Button {
                showHeatmap.toggle()
                NotificationCenter.default.post(name: .toggleHeatmap, object: showHeatmap)
            } label: {
                Image(systemName: showHeatmap ? "map.circle.fill" : "map.circle")
                    .font(.system(size: 18))
                    .foregroundStyle(showHeatmap ? AppColors.brandTeal : AppColors.textSecondary)
                    .frame(width: 44, height: 44)
            }
            .accessibilityLabel("Price heatmap\(showHeatmap ? ", on" : ", off")")
            .accessibilityHint("Double tap to \(showHeatmap ? "hide" : "show") neighborhood price overlay")

            Divider()
                .frame(width: 30)
                .accessibilityHidden(true)

            // Satellite toggle
            Button {
                mapType = mapType == .standard ? .satellite : .standard
            } label: {
                Image(systemName: mapType == .satellite ? "map.fill" : "globe.americas.fill")
                    .font(.system(size: 18))
                    .foregroundStyle(AppColors.textSecondary)
                    .frame(width: 44, height: 44)
            }
            .accessibilityLabel(mapType == .satellite ? "Standard map view" : "Satellite view")
            .accessibilityHint("Double tap to switch to \(mapType == .satellite ? "standard" : "satellite") map")

            Divider()
                .frame(width: 30)
                .accessibilityHidden(true)

            // User location
            Button {
                onLocationTap()
            } label: {
                Image(systemName: "location.fill")
                    .font(.system(size: 18))
                    .foregroundStyle(AppColors.brandTeal)
                    .frame(width: 44, height: 44)
            }
            .accessibilityLabel("My location")
            .accessibilityHint("Double tap to center map on your current location")
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 8))
        .shadow(color: .black.opacity(0.15), radius: 4, x: 0, y: 2)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Map controls")
    }
}

// MARK: - Property Map Card

struct PropertyMapCard: View {
    let property: Property
    let onTap: () -> Void
    let onFavoriteTap: () -> Void
    var onHideTap: (() -> Void)? = nil
    let onDismiss: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            // Status tags row (if any)
            if property.isNewListing || property.isPriceReduced || property.hasOpenHouse || property.isSharedByAgent || property.isExclusive {
                HStack(spacing: 6) {
                    if property.isNewListing {
                        MapStatusTag(text: "New", color: .green)
                    }
                    if property.isPriceReduced, let amount = property.priceReductionAmount {
                        let formatted = amount >= 1000 ? "-$\(amount / 1000)K" : "-$\(amount)"
                        MapStatusTag(text: formatted, color: .red)
                    }
                    if property.hasOpenHouse {
                        MapStatusTag(text: "Open House", color: .orange)
                    }
                    // "Recommended by [Agent]" badge (v145 - Sprint 3 Property Sharing)
                    if property.isSharedByAgent {
                        HStack(spacing: 4) {
                            // Agent photo (small, circular)
                            if let photoUrlString = property.sharedByAgentPhoto,
                               let photoUrl = URL(string: photoUrlString) {
                                AsyncImage(url: photoUrl) { phase in
                                    if case .success(let image) = phase {
                                        image
                                            .resizable()
                                            .aspectRatio(contentMode: .fill)
                                    }
                                }
                                .frame(width: 16, height: 16)
                                .clipShape(Circle())
                                .overlay(Circle().stroke(Color.white.opacity(0.8), lineWidth: 1))
                            }
                            Text("Recommended by \(property.sharedByAgentName ?? "Agent")")
                                .font(.caption2)
                                .fontWeight(.semibold)
                        }
                        .padding(.leading, property.sharedByAgentPhoto != nil ? 3 : 6)
                        .padding(.trailing, 6)
                        .padding(.vertical, 3)
                        .background(Color.purple)
                        .foregroundStyle(.white)
                        .clipShape(Capsule())
                    }
                    // v6.65.0: Exclusive Listing badge with dynamic tag text (gold color)
                    if property.isExclusive {
                        HStack(spacing: 3) {
                            Image(systemName: "star.fill")
                                .font(.system(size: 10))
                            Text(property.exclusiveTag ?? "Exclusive")
                                .font(.caption2)
                                .fontWeight(.semibold)
                        }
                        .padding(.horizontal, 6)
                        .padding(.vertical, 3)
                        .background(Color(red: 0.85, green: 0.65, blue: 0.13))  // Gold color
                        .foregroundStyle(.white)
                        .clipShape(Capsule())
                    }
                    Spacer()
                }
            }

            HStack(spacing: 12) {
                // Tappable content area (image + details)
                HStack(spacing: 12) {
                    // Property image
                    AsyncImage(url: property.primaryImageURL) { phase in
                        switch phase {
                        case .empty:
                            Rectangle()
                                .fill(AppColors.shimmerBase)
                                .overlay(ProgressView())
                        case .success(let image):
                            image
                                .resizable()
                                .aspectRatio(contentMode: .fill)
                        case .failure:
                            Rectangle()
                                .fill(AppColors.shimmerBase)
                                .overlay(
                                    Image(systemName: "photo")
                                        .foregroundStyle(.secondary)
                                )
                        @unknown default:
                            EmptyView()
                        }
                    }
                    .frame(width: 140, height: 110)
                    .clipShape(RoundedRectangle(cornerRadius: 8))

                    // Property details
                    VStack(alignment: .leading, spacing: 4) {
                        HStack {
                            Text(property.formattedPrice)
                                .font(.headline)
                                .fontWeight(.bold)

                            if property.standardStatus != .active {
                                Text(property.standardStatus.displayName)
                                    .font(.caption2)
                                    .fontWeight(.semibold)
                                    .padding(.horizontal, 6)
                                    .padding(.vertical, 2)
                                    .background(mapStatusColor(for: property.standardStatus).opacity(0.15))
                                    .foregroundStyle(mapStatusColor(for: property.standardStatus))
                                    .clipShape(Capsule())
                            }
                        }

                        Text(property.address)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)

                        // MLS Number
                        if let mls = property.mlsNumber {
                            Text("MLS# \(mls)")
                                .font(.caption2)
                                .foregroundStyle(.tertiary)
                        }

                        HStack(spacing: 12) {
                            Label("\(property.beds) bd", systemImage: "bed.double.fill")
                            Label(property.formattedBathroomsDetailed + " ba", systemImage: "shower.fill")
                        }
                        .font(.caption)
                        .foregroundStyle(.secondary)

                        if let sqft = property.formattedSqft {
                            Text(sqft)
                                .font(.caption)
                                .foregroundStyle(.tertiary)
                        }
                    }
                }
                .contentShape(Rectangle())
                .onTapGesture {
                    onTap()
                }

                Spacer()

                // Actions (not part of tap gesture)
                VStack(spacing: 12) {
                    Button {
                        onDismiss()
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .font(.title3)
                            .foregroundStyle(.secondary)
                    }
                    .accessibilityLabel("Close property card")
                    .accessibilityHint("Double tap to dismiss this property card")

                    // Hide button
                    if let hideTap = onHideTap {
                        Button {
                            hideTap()
                        } label: {
                            Image(systemName: "eye.slash")
                                .font(.title3)
                                .foregroundStyle(.secondary)
                        }
                        .accessibilityLabel("Hide property")
                        .accessibilityHint("Double tap to hide this property from search results")
                    }

                    Button {
                        onFavoriteTap()
                    } label: {
                        Image(systemName: property.isFavorite ? "heart.fill" : "heart")
                            .font(.title3)
                            .foregroundStyle(property.isFavorite ? .red : .secondary)
                    }
                    .accessibilityLabel(property.isFavorite ? "Remove from favorites" : "Add to favorites")
                    .accessibilityHint("Double tap to \(property.isFavorite ? "remove from" : "add to") your saved properties")
                }
            }
        }
        .padding(12)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: AppColors.shadowMedium, radius: 10, x: 0, y: 4)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Property at \(property.address), \(property.formattedPrice), \(property.beds) bedrooms, \(Int(property.baths)) bathrooms")
        .accessibilityHint("Double tap to view property details")
    }

    private func mapStatusColor(for status: PropertyStatus) -> Color {
        switch status {
        case .active: return AppColors.activeStatus
        case .pending: return AppColors.pendingStatus
        case .sold, .closed: return AppColors.soldStatus
        case .withdrawn, .expired, .canceled: return Color.gray
        }
    }
}

// MARK: - Map Status Tag

struct MapStatusTag: View {
    let text: String
    let color: Color

    var body: some View {
        Text(text)
            .font(.caption2)
            .fontWeight(.semibold)
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
            .background(color)
            .foregroundStyle(.white)
            .clipShape(Capsule())
    }
}

// MARK: - Multi-Unit Card (for clusters)

struct MultiUnitCard: View {
    let properties: [Property]
    let onPropertySelected: (Property) -> Void
    let onDismiss: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            // Header
            HStack {
                Text("\(properties.count) Units at this location")
                    .font(.headline)

                Spacer()

                Button {
                    onDismiss()
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .font(.title3)
                        .foregroundStyle(.secondary)
                }
                .accessibilityLabel("Close multi-unit card")
                .accessibilityHint("Double tap to dismiss this card")
            }
            .padding()
            .accessibilityElement(children: .combine)
            .accessibilityLabel("\(properties.count) units at this location")

            Divider()

            // Scrollable list of properties
            ScrollView {
                VStack(spacing: 8) {
                    ForEach(properties, id: \.id) { property in
                        Button {
                            onPropertySelected(property)
                        } label: {
                            HStack(spacing: 12) {
                                AsyncImage(url: property.thumbnailURL) { phase in
                                    switch phase {
                                    case .success(let image):
                                        image
                                            .resizable()
                                            .aspectRatio(contentMode: .fill)
                                    default:
                                        Rectangle()
                                            .fill(AppColors.shimmerBase)
                                    }
                                }
                                .frame(width: 60, height: 45)
                                .clipShape(RoundedRectangle(cornerRadius: 6))

                                VStack(alignment: .leading, spacing: 2) {
                                    Text(property.formattedPrice)
                                        .font(.subheadline)
                                        .fontWeight(.semibold)
                                        .foregroundStyle(.primary)

                                    Text("\(property.beds) bd | \(Int(property.baths)) ba")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }

                                Spacer()

                                Image(systemName: "chevron.right")
                                    .font(.caption)
                                    .foregroundStyle(.tertiary)
                            }
                            .padding(.horizontal)
                            .padding(.vertical, 8)
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel("\(property.formattedPrice), \(property.beds) bedrooms, \(Int(property.baths)) bathrooms")
                        .accessibilityHint("Double tap to view property details")

                        if property.id != properties.last?.id {
                            Divider()
                                .padding(.leading, 84)
                                .accessibilityHidden(true)
                        }
                    }
                }
            }
            .frame(maxHeight: 200)
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: AppColors.shadowMedium, radius: 10, x: 0, y: 4)
        .accessibilityElement(children: .contain)
    }
}

// MARK: - School Info Card

struct SchoolInfoCard: View {
    let school: MapSchool
    let onDismiss: () -> Void

    /// Color based on school level
    private var levelColor: Color {
        switch school.level {
        case "elementary": return .green
        case "middle": return .blue
        case "high": return .purple
        default: return .orange
        }
    }

    /// Icon letter based on school level
    private var levelIcon: String {
        switch school.level {
        case "elementary": return "E"
        case "middle": return "M"
        case "high": return "H"
        default: return "S"
        }
    }

    /// Full level name
    private var levelName: String {
        switch school.level {
        case "elementary": return "Elementary School"
        case "middle": return "Middle School"
        case "high": return "High School"
        default: return "School"
        }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Header row
            HStack(alignment: .top, spacing: 12) {
                // Level badge
                ZStack {
                    Circle()
                        .fill(levelColor)
                        .frame(width: 44, height: 44)

                    Text(levelIcon)
                        .font(.title2)
                        .fontWeight(.bold)
                        .foregroundStyle(.white)
                }

                // School info
                VStack(alignment: .leading, spacing: 4) {
                    Text(school.name)
                        .font(.headline)
                        .fontWeight(.semibold)
                        .lineLimit(2)

                    Text(levelName)
                        .font(.subheadline)
                        .foregroundStyle(levelColor)

                    if let type = school.type {
                        Text(type.capitalized)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                Spacer()

                // Dismiss button
                Button {
                    onDismiss()
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .font(.title3)
                        .foregroundStyle(.secondary)
                }
                .accessibilityLabel("Close school card")
                .accessibilityHint("Double tap to dismiss this school card")
            }

            Divider()
                .accessibilityHidden(true)

            // Details row
            HStack(spacing: 16) {
                // Type badge
                if let type = school.type {
                    HStack(spacing: 4) {
                        Image(systemName: type == "public" ? "building.2.fill" : "building.fill")
                            .font(.caption)
                        Text(type.capitalized)
                            .font(.caption)
                            .fontWeight(.medium)
                    }
                    .foregroundStyle(.secondary)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 6)
                    .background(Color(.secondarySystemBackground))
                    .clipShape(Capsule())
                    .accessibilityLabel("\(type.capitalized) school")
                }

                Spacer()

                // Powered by BMN Schools
                Text("BMN Schools")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
                    .accessibilityHidden(true)
            }
        }
        .padding(16)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: AppColors.shadowMedium, radius: 10, x: 0, y: 4)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("\(school.name), \(levelName)\(school.type != nil ? ", \(school.type!.capitalized)" : "")")
    }
}

// Note: Property Hashable/Equatable conformance is defined in Property.swift
