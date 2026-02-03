//
//  PropertyDetailMapView.swift
//  BMNBoston
//
//  Interactive UIViewRepresentable for property detail full-screen map
//  Supports: Map type toggle, school pins, transit pins, neighborhood boundary
//

import SwiftUI
import MapKit

/// Interactive map view for property details with overlay support
struct PropertyDetailMapView: UIViewRepresentable {
    let propertyCoordinate: CLLocationCoordinate2D
    let propertyTitle: String

    @Binding var mapType: MKMapType
    @Binding var showSchools: Bool
    @Binding var showTransit: Bool

    var schoolAnnotations: [MapSchool]
    var transitAnnotations: [TransitStation]
    var neighborhoodBoundary: MKPolygon?

    func makeUIView(context: Context) -> MKMapView {
        let mapView = MKMapView()
        mapView.delegate = context.coordinator
        mapView.isScrollEnabled = true
        mapView.isZoomEnabled = true
        mapView.isRotateEnabled = true
        mapView.isPitchEnabled = true
        mapView.showsUserLocation = true
        mapView.showsCompass = true
        mapView.showsScale = true

        // Set initial region centered on property
        let region = MKCoordinateRegion(
            center: propertyCoordinate,
            span: MKCoordinateSpan(latitudeDelta: 0.02, longitudeDelta: 0.02)
        )
        mapView.setRegion(region, animated: false)

        // Add property annotation
        let propertyAnnotation = PropertyLocationAnnotation(
            coordinate: propertyCoordinate,
            title: propertyTitle
        )
        mapView.addAnnotation(propertyAnnotation)

        return mapView
    }

    func updateUIView(_ mapView: MKMapView, context: Context) {
        // Update map type
        if mapView.mapType != mapType {
            mapView.mapType = mapType
        }

        // Update school annotations
        updateSchoolAnnotations(mapView)

        // Update transit annotations
        updateTransitAnnotations(mapView)

        // Update neighborhood boundary
        updateNeighborhoodBoundary(mapView)
    }

    func makeCoordinator() -> Coordinator {
        Coordinator(propertyCoordinate: propertyCoordinate)
    }

    // MARK: - Annotation Updates

    private func updateSchoolAnnotations(_ mapView: MKMapView) {
        let existingSchoolAnnotations = mapView.annotations.compactMap { $0 as? SchoolMapAnnotation }
        let existingIds = Set(existingSchoolAnnotations.map { $0.school.id })
        let newIds = showSchools ? Set(schoolAnnotations.map { $0.id }) : []

        if existingIds != newIds {
            // Remove old
            mapView.removeAnnotations(existingSchoolAnnotations)

            // Add new if showing
            if showSchools {
                for school in schoolAnnotations {
                    let annotation = SchoolMapAnnotation(school: school)
                    mapView.addAnnotation(annotation)
                }
            }
        }
    }

    private func updateTransitAnnotations(_ mapView: MKMapView) {
        let existingTransitAnnotations = mapView.annotations.compactMap { $0 as? TransitMapAnnotation }
        let existingIds = Set(existingTransitAnnotations.map { $0.station.id })
        let newIds = showTransit ? Set(transitAnnotations.map { $0.id }) : []

        if existingIds != newIds {
            // Remove old
            mapView.removeAnnotations(existingTransitAnnotations)

            // Add new if showing
            if showTransit {
                for station in transitAnnotations {
                    let annotation = TransitMapAnnotation(station: station)
                    mapView.addAnnotation(annotation)
                }
            }
        }
    }

    private func updateNeighborhoodBoundary(_ mapView: MKMapView) {
        // Remove existing boundary overlays
        let existingBoundaries = mapView.overlays.compactMap { $0 as? MKPolygon }
        mapView.removeOverlays(existingBoundaries)

        // Add new boundary if available
        if let boundary = neighborhoodBoundary {
            mapView.addOverlay(boundary, level: .aboveRoads)
        }
    }

    // MARK: - Coordinator

    class Coordinator: NSObject, MKMapViewDelegate {
        let propertyCoordinate: CLLocationCoordinate2D

        init(propertyCoordinate: CLLocationCoordinate2D) {
            self.propertyCoordinate = propertyCoordinate
        }

        func mapView(_ mapView: MKMapView, viewFor annotation: MKAnnotation) -> MKAnnotationView? {
            // User location - use default
            if annotation is MKUserLocation {
                return nil
            }

            // Property location annotation
            if let propertyAnnotation = annotation as? PropertyLocationAnnotation {
                return propertyMarkerView(for: propertyAnnotation, in: mapView)
            }

            // School annotation
            if let schoolAnnotation = annotation as? SchoolMapAnnotation {
                return schoolMarkerView(for: schoolAnnotation, in: mapView)
            }

            // Transit annotation
            if let transitAnnotation = annotation as? TransitMapAnnotation {
                return transitMarkerView(for: transitAnnotation, in: mapView)
            }

            return nil
        }

        // Property marker - teal house pin
        private func propertyMarkerView(for annotation: PropertyLocationAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "PropertyLocation"
            var view = mapView.dequeueReusableAnnotationView(withIdentifier: identifier) as? MKMarkerAnnotationView

            if view == nil {
                view = MKMarkerAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                view?.canShowCallout = true
            } else {
                view?.annotation = annotation
            }

            view?.markerTintColor = UIColor(AppColors.brandTeal)
            view?.glyphImage = UIImage(systemName: "house.fill")
            view?.displayPriority = .required

            return view!
        }

        // School marker - colored by level
        private func schoolMarkerView(for annotation: SchoolMapAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "SchoolLocation"
            var view = mapView.dequeueReusableAnnotationView(withIdentifier: identifier) as? MKMarkerAnnotationView

            if view == nil {
                view = MKMarkerAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                view?.canShowCallout = true
            } else {
                view?.annotation = annotation
            }

            // Color based on level
            let color: UIColor
            let glyph: String
            switch annotation.school.level?.lowercased() {
            case "elementary":
                color = .systemGreen
                glyph = "E"
            case "middle":
                color = .systemBlue
                glyph = "M"
            case "high":
                color = .systemPurple
                glyph = "H"
            default:
                color = .systemOrange
                glyph = "S"
            }

            view?.markerTintColor = color
            view?.glyphText = glyph
            view?.displayPriority = .defaultHigh

            return view!
        }

        // Transit marker - colored by line
        private func transitMarkerView(for annotation: TransitMapAnnotation, in mapView: MKMapView) -> MKAnnotationView {
            let identifier = "TransitLocation"
            var view = mapView.dequeueReusableAnnotationView(withIdentifier: identifier) as? MKMarkerAnnotationView

            if view == nil {
                view = MKMarkerAnnotationView(annotation: annotation, reuseIdentifier: identifier)
                view?.canShowCallout = true
            } else {
                view?.annotation = annotation
            }

            // Color based on MBTA line
            let color: UIColor
            if let line = MBTALine.from(annotation.station.line) {
                color = UIColor(hex: line.color) ?? .systemGray
            } else {
                color = .systemGray
            }

            view?.markerTintColor = color
            view?.glyphImage = UIImage(systemName: "tram.fill")
            view?.displayPriority = .defaultHigh

            return view!
        }

        // Overlay rendering for neighborhood boundary
        func mapView(_ mapView: MKMapView, rendererFor overlay: MKOverlay) -> MKOverlayRenderer {
            if let polygon = overlay as? MKPolygon {
                let renderer = MKPolygonRenderer(polygon: polygon)
                renderer.strokeColor = UIColor.systemBlue.withAlphaComponent(0.8)
                renderer.lineWidth = 2
                renderer.fillColor = UIColor.systemBlue.withAlphaComponent(0.1)
                renderer.lineDashPattern = [5, 5]
                return renderer
            }
            return MKOverlayRenderer(overlay: overlay)
        }
    }
}

// MARK: - Property Location Annotation

/// Annotation for the property location (main pin)
class PropertyLocationAnnotation: NSObject, MKAnnotation {
    let coordinate: CLLocationCoordinate2D
    let title: String?

    init(coordinate: CLLocationCoordinate2D, title: String) {
        self.coordinate = coordinate
        self.title = title
        super.init()
    }
}

// MARK: - School Map Annotation

/// Annotation for school pins on the map
class SchoolMapAnnotation: NSObject, MKAnnotation {
    let school: MapSchool

    var coordinate: CLLocationCoordinate2D {
        school.coordinate
    }

    var title: String? {
        school.name
    }

    var subtitle: String? {
        school.level?.capitalized ?? "School"
    }

    init(school: MapSchool) {
        self.school = school
        super.init()
    }
}

// MARK: - Transit Map Annotation

/// Annotation for transit station pins on the map
class TransitMapAnnotation: NSObject, MKAnnotation {
    let station: TransitStation

    var coordinate: CLLocationCoordinate2D {
        station.coordinate
    }

    var title: String? {
        station.name
    }

    var subtitle: String? {
        station.line ?? station.type.displayName
    }

    init(station: TransitStation) {
        self.station = station
        super.init()
    }
}

// NOTE: UIColor.init?(hex:) is defined in PropertyMapView.swift
// NOTE: MBTALine.from() is defined in TransitStation.swift

// MARK: - Preview

#Preview {
    PropertyDetailMapView(
        propertyCoordinate: CLLocationCoordinate2D(latitude: 42.3601, longitude: -71.0589),
        propertyTitle: "123 Main St, Boston",
        mapType: .constant(.hybrid),
        showSchools: .constant(false),
        showTransit: .constant(false),
        schoolAnnotations: [],
        transitAnnotations: [],
        neighborhoodBoundary: nil
    )
}
