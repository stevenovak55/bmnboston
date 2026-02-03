//
//  FullScreenPropertyMapView.swift
//  BMNBoston
//
//  Full-screen interactive map modal for property details
//  Features: Get Directions, Map/Satellite/Hybrid toggle, 3D mode, Street View,
//            Schools/Transit/Area overlays
//

import SwiftUI
import MapKit

/// Full-screen modal map view with interactive features
struct FullScreenPropertyMapView: View {
    let property: PropertyDetail
    @Environment(\.dismiss) var dismiss

    // Map state
    @State private var mapType: MKMapType = .hybrid
    @State private var is3DEnabled = false
    @State private var showSchools = false
    @State private var showTransit = false
    @State private var showNeighborhood = false
    @State private var showDirectionsSheet = false

    // Look Around (Street View) state
    @State private var showLookAround = false
    @State private var lookAroundScene: MKLookAroundScene?
    @State private var isLoadingLookAround = false
    @State private var lookAroundAvailable = false

    // Data
    @State private var schoolAnnotations: [MapSchool] = []
    @State private var transitAnnotations: [TransitStation] = []
    @State private var neighborhoodBoundary: MKPolygon?
    @State private var isLoadingSchools = false
    @State private var isLoadingTransit = false

    // Computed map type that includes 3D mode
    private var effectiveMapType: MKMapType {
        if is3DEnabled {
            switch mapType {
            case .satellite, .satelliteFlyover:
                return .satelliteFlyover
            case .hybrid, .hybridFlyover:
                return .hybridFlyover
            default:
                return .hybridFlyover
            }
        } else {
            // Make sure we're not returning flyover types when 3D is disabled
            switch mapType {
            case .satelliteFlyover:
                return .satellite
            case .hybridFlyover:
                return .hybrid
            default:
                return mapType
            }
        }
    }

    var body: some View {
        ZStack {
            // Full-screen interactive map
            if let location = property.location {
                PropertyDetailMapView(
                    propertyCoordinate: location,
                    propertyTitle: property.fullAddress,
                    mapType: .constant(effectiveMapType),
                    showSchools: $showSchools,
                    showTransit: $showTransit,
                    schoolAnnotations: schoolAnnotations,
                    transitAnnotations: transitAnnotations,
                    neighborhoodBoundary: showNeighborhood ? neighborhoodBoundary : nil
                )
                .ignoresSafeArea()
            } else {
                Color(.systemBackground)
                    .ignoresSafeArea()
                    .overlay {
                        VStack {
                            Image(systemName: "map.fill")
                                .font(.system(size: 60))
                                .foregroundStyle(.secondary)
                            Text("Location unavailable")
                                .font(.headline)
                                .foregroundStyle(.secondary)
                        }
                    }
            }

            // Overlay controls
            VStack {
                // Top bar - Close and Directions
                topBar

                Spacer()

                // Bottom control panel
                bottomControlPanel
            }
        }
        .confirmationDialog("Get Directions", isPresented: $showDirectionsSheet) {
            Button("Apple Maps") { openInAppleMaps() }
            Button("Google Maps") { openInGoogleMaps() }
            Button("Cancel", role: .cancel) { }
        }
        .sheet(isPresented: $showLookAround) {
            if let scene = lookAroundScene {
                LookAroundPreviewSheet(scene: scene, propertyAddress: property.fullAddress)
            }
        }
        .task {
            await loadInitialData()
            await checkLookAroundAvailability()
        }
        .onChange(of: showSchools) { newValue in
            if newValue && schoolAnnotations.isEmpty {
                Task { await loadSchools() }
            }
        }
        .onChange(of: showTransit) { newValue in
            if newValue && transitAnnotations.isEmpty {
                Task { await loadTransit() }
            }
        }
        .onChange(of: showNeighborhood) { newValue in
            if newValue && neighborhoodBoundary == nil {
                Task { await loadNeighborhoodBoundary() }
            }
        }
    }

    // MARK: - Top Bar

    private var topBar: some View {
        HStack {
            // Close button
            Button {
                dismiss()
            } label: {
                Image(systemName: "xmark.circle.fill")
                    .font(.title)
                    .symbolRenderingMode(.hierarchical)
                    .foregroundStyle(.white)
                    .shadow(color: .black.opacity(0.3), radius: 2, y: 1)
            }

            Spacer()

            // Directions button
            if property.location != nil {
                Button {
                    showDirectionsSheet = true
                } label: {
                    Label("Directions", systemImage: "arrow.triangle.turn.up.right.diamond.fill")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.primary)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 8)
                        .background(.ultraThinMaterial)
                        .clipShape(Capsule())
                }
            }
        }
        .padding()
    }

    // MARK: - Bottom Control Panel

    private var bottomControlPanel: some View {
        VStack(spacing: 12) {
            // Map type picker with 3D toggle
            HStack(spacing: 8) {
                Picker("Map Type", selection: $mapType) {
                    Text("Standard").tag(MKMapType.standard)
                    Text("Satellite").tag(MKMapType.satellite)
                    Text("Hybrid").tag(MKMapType.hybrid)
                }
                .pickerStyle(.segmented)

                // 3D toggle button
                Button {
                    is3DEnabled.toggle()
                } label: {
                    Text("3D")
                        .font(.caption.weight(.semibold))
                        .frame(width: 40, height: 32)
                        .background(is3DEnabled ? AppColors.brandTeal : Color(.systemGray5))
                        .foregroundStyle(is3DEnabled ? .white : .primary)
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                }
            }

            // Toggle buttons row - first row
            HStack(spacing: 8) {
                OverlayToggleChip(
                    label: "Schools",
                    icon: "graduationcap.fill",
                    isOn: $showSchools,
                    isLoading: isLoadingSchools
                )
                OverlayToggleChip(
                    label: "Transit",
                    icon: "tram.fill",
                    isOn: $showTransit,
                    isLoading: isLoadingTransit
                )
                OverlayToggleChip(
                    label: "Area",
                    icon: "map",
                    isOn: $showNeighborhood,
                    isLoading: false
                )
            }

            // Second row - Street View
            if lookAroundAvailable || isLoadingLookAround {
                HStack(spacing: 8) {
                    Button {
                        Task { await openLookAround() }
                    } label: {
                        HStack(spacing: 4) {
                            if isLoadingLookAround {
                                ProgressView()
                                    .scaleEffect(0.7)
                            } else {
                                Image(systemName: "binoculars.fill")
                                    .font(.system(size: 12))
                            }
                            Text("Street View")
                                .font(.caption.weight(.medium))
                        }
                        .padding(.horizontal, 12)
                        .padding(.vertical, 8)
                        .background(AppColors.brandTeal)
                        .foregroundStyle(.white)
                        .clipShape(Capsule())
                    }
                    .disabled(isLoadingLookAround || !lookAroundAvailable)

                    Spacer()
                }
            }

            // Property info
            VStack(alignment: .leading, spacing: 4) {
                Text(property.fullAddress)
                    .font(.subheadline.weight(.semibold))

                HStack(spacing: 12) {
                    if let acres = property.lotSizeAcres, acres > 0 {
                        Label(String(format: "%.2f acres", acres), systemImage: "square.dashed")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }

                    if let neighborhood = property.neighborhood, !neighborhood.isEmpty {
                        Label(neighborhood, systemImage: "building.2")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding()
        .background(.ultraThinMaterial)
    }

    // MARK: - Data Loading

    private func loadInitialData() async {
        // Pre-load neighborhood boundary if city is available
        if !property.city.isEmpty {
            await loadNeighborhoodBoundary()
        }
    }

    private func loadSchools() async {
        guard let location = property.location else { return }

        isLoadingSchools = true
        defer { isLoadingSchools = false }

        do {
            let schoolsData = try await SchoolService.shared.fetchPropertySchools(
                latitude: location.latitude,
                longitude: location.longitude,
                radius: 2.0
            )

            // Convert NearbySchool to MapSchool for annotations
            var mapSchools: [MapSchool] = []
            mapSchools.append(contentsOf: schoolsData.schools.elementary.map { school in
                MapSchool(
                    id: school.id,
                    name: school.name,
                    level: "elementary",
                    type: nil,
                    lat: school.latitude,
                    lng: school.longitude
                )
            })
            mapSchools.append(contentsOf: schoolsData.schools.middle.map { school in
                MapSchool(
                    id: school.id,
                    name: school.name,
                    level: "middle",
                    type: nil,
                    lat: school.latitude,
                    lng: school.longitude
                )
            })
            mapSchools.append(contentsOf: schoolsData.schools.high.map { school in
                MapSchool(
                    id: school.id,
                    name: school.name,
                    level: "high",
                    type: nil,
                    lat: school.latitude,
                    lng: school.longitude
                )
            })

            await MainActor.run {
                schoolAnnotations = mapSchools
            }
        } catch {
            print("Failed to load schools: \(error)")
        }
    }

    private func loadTransit() async {
        guard let location = property.location else { return }

        isLoadingTransit = true
        defer { isLoadingTransit = false }

        // Create a region around the property (2 mile radius approximately)
        let region = MKCoordinateRegion(
            center: location,
            span: MKCoordinateSpan(latitudeDelta: 0.05, longitudeDelta: 0.05)
        )

        let stations = await TransitService.shared.fetchStations(in: region)

        await MainActor.run {
            transitAnnotations = stations
        }
    }

    private func loadNeighborhoodBoundary() async {
        let city = property.city
        guard !city.isEmpty else { return }

        let boundary = await CityBoundaryService.shared.boundaryForLocation(city)
        if let polygon = boundary {
            await MainActor.run {
                neighborhoodBoundary = polygon
                neighborhoodBoundary?.title = "cityBoundary"
            }
        }
    }

    // MARK: - Directions

    private func openInAppleMaps() {
        guard let coordinate = property.location else { return }

        let placemark = MKPlacemark(coordinate: coordinate)
        let mapItem = MKMapItem(placemark: placemark)
        mapItem.name = property.fullAddress
        mapItem.openInMaps(launchOptions: [
            MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving
        ])
    }

    private func openInGoogleMaps() {
        guard let coordinate = property.location else { return }

        let lat = coordinate.latitude
        let lng = coordinate.longitude
        let urlString = "comgooglemaps://?daddr=\(lat),\(lng)&directionsmode=driving"

        if let url = URL(string: urlString), UIApplication.shared.canOpenURL(url) {
            UIApplication.shared.open(url)
        } else {
            // Fallback to Google Maps web
            let webUrlString = "https://www.google.com/maps/dir/?api=1&destination=\(lat),\(lng)"
            if let webUrl = URL(string: webUrlString) {
                UIApplication.shared.open(webUrl)
            }
        }
    }

    // MARK: - Look Around (Street View)

    @MainActor
    private func checkLookAroundAvailability() async {
        guard let location = property.location else { return }

        isLoadingLookAround = true
        defer { isLoadingLookAround = false }

        do {
            let request = MKLookAroundSceneRequest(coordinate: location)
            let scene = try await request.scene
            lookAroundScene = scene
            lookAroundAvailable = scene != nil
        } catch {
            lookAroundAvailable = false
        }
    }

    @MainActor
    private func openLookAround() async {
        if lookAroundScene == nil {
            await checkLookAroundAvailability()
        }
        guard lookAroundScene != nil else { return }
        showLookAround = true
    }
}

// MARK: - Look Around Preview Sheet

struct LookAroundPreviewSheet: View {
    let scene: MKLookAroundScene
    let propertyAddress: String
    @Environment(\.dismiss) var dismiss

    var body: some View {
        NavigationStack {
            LookAroundPreview(initialScene: scene)
                .ignoresSafeArea()
                .navigationTitle("Street View")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .topBarLeading) {
                        Button {
                            dismiss()
                        } label: {
                            Image(systemName: "xmark.circle.fill")
                                .font(.title2)
                                .symbolRenderingMode(.hierarchical)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
        }
    }
}

// MARK: - Look Around Preview (UIViewControllerRepresentable)

struct LookAroundPreview: UIViewControllerRepresentable {
    let initialScene: MKLookAroundScene

    func makeUIViewController(context: Context) -> MKLookAroundViewController {
        let controller = MKLookAroundViewController(scene: initialScene)
        return controller
    }

    func updateUIViewController(_ uiViewController: MKLookAroundViewController, context: Context) {
        // Scene is set in makeUIViewController
    }
}

// MARK: - Overlay Toggle Chip

/// Toggle chip for map overlay controls
struct OverlayToggleChip: View {
    let label: String
    let icon: String
    @Binding var isOn: Bool
    var isLoading: Bool = false

    var body: some View {
        Button {
            isOn.toggle()
        } label: {
            HStack(spacing: 4) {
                if isLoading {
                    ProgressView()
                        .scaleEffect(0.7)
                } else {
                    Image(systemName: icon)
                        .font(.system(size: 12))
                }
                Text(label)
                    .font(.caption.weight(.medium))
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(isOn ? AppColors.brandTeal : Color(.systemGray5))
            .foregroundStyle(isOn ? .white : .primary)
            .clipShape(Capsule())
        }
        .disabled(isLoading)
    }
}

// MARK: - Preview

#Preview {
    Text("FullScreenPropertyMapView Preview")
}
