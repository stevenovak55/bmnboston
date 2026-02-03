//
//  MapPreviewSection.swift
//  BMNBoston
//
//  Small tappable satellite map preview for property details page
//  Tapping opens FullScreenPropertyMapView modal
//

import SwiftUI
import MapKit

/// Small tappable satellite map preview that opens full-screen modal on tap
struct MapPreviewSection: View {
    let property: PropertyDetail
    @State private var showFullScreenMap = false
    @State private var showDirectionsSheet = false

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Location")
                .font(.headline)

            // Tappable preview card
            Button {
                showFullScreenMap = true
            } label: {
                ZStack {
                    // Static satellite map preview
                    if let location = property.location {
                        StaticMapPreview(
                            coordinate: location,
                            title: property.fullAddress
                        )
                        .frame(height: 180)
                    } else {
                        // Fallback if no coordinates
                        Rectangle()
                            .fill(Color(.systemGray5))
                            .frame(height: 180)
                            .overlay {
                                VStack {
                                    Image(systemName: "map")
                                        .font(.largeTitle)
                                        .foregroundStyle(.secondary)
                                    Text("Location unavailable")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                    }

                    // "View Map" overlay button
                    VStack {
                        Spacer()
                        Text("View Map")
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(.white)
                            .padding(.horizontal, 16)
                            .padding(.vertical, 8)
                            .background(.ultraThinMaterial.opacity(0.9))
                            .clipShape(Capsule())
                        Spacer().frame(height: 20)
                    }
                }
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .buttonStyle(.plain)
            .disabled(property.location == nil)

            // Address below map
            HStack {
                Image(systemName: "mappin.circle.fill")
                    .foregroundStyle(AppColors.brandTeal)
                Text(property.fullAddress)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            // Lot size if available
            if let acres = property.lotSizeAcres, acres > 0 {
                HStack {
                    Image(systemName: "square.dashed")
                        .foregroundStyle(AppColors.brandTeal)
                    Text(String(format: "%.2f acre lot", acres))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            } else if let sqft = property.lotSize, sqft > 0 {
                HStack {
                    Image(systemName: "square.dashed")
                        .foregroundStyle(AppColors.brandTeal)
                    let formatter = NumberFormatter()
                    let _ = formatter.numberStyle = .decimal
                    Text("\(formatter.string(from: NSNumber(value: Int(sqft))) ?? String(Int(sqft))) sq ft lot")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            // Get Directions button
            if property.location != nil {
                Button {
                    showDirectionsSheet = true
                } label: {
                    HStack(spacing: 6) {
                        Image(systemName: "arrow.triangle.turn.up.right.diamond.fill")
                            .font(.system(size: 14))
                        Text("Get Directions")
                            .font(.subheadline.weight(.medium))
                    }
                    .foregroundStyle(AppColors.brandTeal)
                }
                .padding(.top, 4)
            }
        }
        .fullScreenCover(isPresented: $showFullScreenMap) {
            FullScreenPropertyMapView(property: property)
        }
        .confirmationDialog("Get Directions", isPresented: $showDirectionsSheet) {
            Button("Apple Maps") { openInAppleMaps() }
            Button("Google Maps") { openInGoogleMaps() }
            Button("Cancel", role: .cancel) { }
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
}

// MARK: - Static Satellite Map Preview (UIViewRepresentable)

/// Static satellite map preview that shows property location
struct StaticMapPreview: UIViewRepresentable {
    let coordinate: CLLocationCoordinate2D
    let title: String

    func makeUIView(context: Context) -> MKMapView {
        let mapView = MKMapView()
        mapView.mapType = .satellite
        mapView.isScrollEnabled = false
        mapView.isZoomEnabled = false
        mapView.isRotateEnabled = false
        mapView.isPitchEnabled = false
        mapView.showsUserLocation = false
        mapView.delegate = context.coordinator
        return mapView
    }

    func updateUIView(_ mapView: MKMapView, context: Context) {
        // Set region centered on property
        let region = MKCoordinateRegion(
            center: coordinate,
            span: MKCoordinateSpan(latitudeDelta: 0.005, longitudeDelta: 0.005)
        )
        mapView.setRegion(region, animated: false)

        // Add property annotation
        mapView.removeAnnotations(mapView.annotations)
        let annotation = MKPointAnnotation()
        annotation.coordinate = coordinate
        annotation.title = title
        mapView.addAnnotation(annotation)
    }

    func makeCoordinator() -> Coordinator {
        Coordinator()
    }

    class Coordinator: NSObject, MKMapViewDelegate {
        func mapView(_ mapView: MKMapView, viewFor annotation: MKAnnotation) -> MKAnnotationView? {
            guard !(annotation is MKUserLocation) else { return nil }

            let identifier = "PropertyPin"
            var annotationView = mapView.dequeueReusableAnnotationView(withIdentifier: identifier) as? MKMarkerAnnotationView

            if annotationView == nil {
                annotationView = MKMarkerAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                annotationView?.canShowCallout = false
            } else {
                annotationView?.annotation = annotation
            }

            annotationView?.markerTintColor = UIColor(AppColors.brandTeal)
            annotationView?.glyphImage = UIImage(systemName: "house.fill")

            return annotationView
        }
    }
}

// MARK: - Preview

#Preview {
    ScrollView {
        // Would need a mock PropertyDetail for preview
        Text("MapPreviewSection Preview")
    }
}
