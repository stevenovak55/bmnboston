//
//  TransitService.swift
//  BMNBoston
//
//  Service for fetching MBTA transit station data
//

import Foundation
import MapKit

/// Actor-based service for fetching transit station data
actor TransitService {
    static let shared = TransitService()

    // Cache transit stations by region key
    private var cache: [String: [TransitStation]] = [:]
    private let cacheExpiration: TimeInterval = 60 * 60 * 24  // 24 hours (stations don't change often)

    private init() {}

    /// Fetch transit stations within a map region
    /// - Parameter region: The visible map region
    /// - Returns: Array of transit stations in the region
    func fetchStations(in region: MKCoordinateRegion) async -> [TransitStation] {
        // For now, use static MBTA data
        // In the future, this could fetch from an API endpoint
        return getStaticMBTAStations().filter { station in
            isCoordinate(station.coordinate, inRegion: region)
        }
    }

    /// Fetch transit routes (polylines) for display on map
    /// - Returns: Array of transit routes with coordinates for each line
    func fetchRoutes() async -> [TransitRoute] {
        return getStaticMBTARoutes()
    }

    /// Check if a coordinate is within a map region
    private func isCoordinate(_ coordinate: CLLocationCoordinate2D, inRegion region: MKCoordinateRegion) -> Bool {
        let latDelta = region.span.latitudeDelta / 2
        let lngDelta = region.span.longitudeDelta / 2

        let minLat = region.center.latitude - latDelta
        let maxLat = region.center.latitude + latDelta
        let minLng = region.center.longitude - lngDelta
        let maxLng = region.center.longitude + lngDelta

        return coordinate.latitude >= minLat &&
               coordinate.latitude <= maxLat &&
               coordinate.longitude >= minLng &&
               coordinate.longitude <= maxLng
    }

    // MARK: - Static MBTA Data

    /// Static MBTA rapid transit stations (subway)
    /// Data sourced from MBTA GTFS
    private func getStaticMBTAStations() -> [TransitStation] {
        var stations: [TransitStation] = []

        // Red Line stations
        let redLineStations = [
            ("place-alfcl", "Alewife", 42.395428, -71.142483),
            ("place-davis", "Davis", 42.39674, -71.121815),
            ("place-portr", "Porter", 42.3884, -71.119149),
            ("place-harsq", "Harvard", 42.373362, -71.118956),
            ("place-cntsq", "Central", 42.365486, -71.103802),
            ("place-knncl", "Kendall/MIT", 42.36249079, -71.08617653),
            ("place-chmnl", "Charles/MGH", 42.361166, -71.070628),
            ("place-pktrm", "Park Street", 42.35639457, -71.0624242),
            ("place-dwnxg", "Downtown Crossing", 42.355518, -71.060225),
            ("place-sstat", "South Station", 42.352271, -71.055242),
            ("place-brdwy", "Broadway", 42.342622, -71.056967),
            ("place-andrw", "Andrew", 42.330154, -71.057655),
            ("place-jfk", "JFK/UMass", 42.320685, -71.052391),
            ("place-shmnl", "Savin Hill", 42.31129, -71.053331),
            ("place-fldcr", "Fields Corner", 42.300093, -71.061667),
            ("place-smmnl", "Shawmut", 42.29312583, -71.06573796),
            ("place-asmnl", "Ashmont", 42.284652, -71.064489),
            // Braintree branch
            ("place-nqncy", "North Quincy", 42.275275, -71.029583),
            ("place-wlsta", "Wollaston", 42.2665139, -71.0203369),
            ("place-qnctr", "Quincy Center", 42.251809, -71.005409),
            ("place-qamnl", "Quincy Adams", 42.233391, -71.007153),
            ("place-brntn", "Braintree", 42.2078543, -71.0011385)
        ]
        for (id, name, lat, lng) in redLineStations {
            stations.append(TransitStation(id: id, name: name, type: .subway, line: "Red Line", lat: lat, lng: lng))
        }

        // Orange Line stations
        let orangeLineStations = [
            ("place-ogmnl", "Oak Grove", 42.43668, -71.071097),
            ("place-mlmnl", "Malden Center", 42.426632, -71.07411),
            ("place-welln", "Wellington", 42.40237, -71.077082),
            ("place-astao", "Assembly", 42.392811, -71.077257),
            ("place-sull", "Sullivan Square", 42.383975, -71.076994),
            ("place-ccmnl", "Community College", 42.373622, -71.069533),
            ("place-north", "North Station", 42.365577, -71.06129),
            ("place-haecl", "Haymarket", 42.363021, -71.05829),
            ("place-state", "State", 42.358978, -71.057598),
            ("place-dwnxg", "Downtown Crossing", 42.355518, -71.060225),
            ("place-chncl", "Chinatown", 42.352547, -71.062752),
            ("place-tumnl", "Tufts Medical Center", 42.349662, -71.063917),
            ("place-bbsta", "Back Bay", 42.34735, -71.075727),
            ("place-masta", "Massachusetts Ave", 42.341512, -71.083423),
            ("place-ruMDL", "Ruggles", 42.336377, -71.088961),
            ("place-rcmnl", "Roxbury Crossing", 42.331397, -71.095451),
            ("place-jaksn", "Jackson Square", 42.323132, -71.099592),
            ("place-sbmnl", "Stony Brook", 42.317062, -71.104248),
            ("place-grnst", "Green Street", 42.310525, -71.107414),
            ("place-forhl", "Forest Hills", 42.300523, -71.113686)
        ]
        for (id, name, lat, lng) in orangeLineStations {
            stations.append(TransitStation(id: id, name: name, type: .subway, line: "Orange Line", lat: lat, lng: lng))
        }

        // Blue Line stations
        let blueLineStations = [
            ("place-wondl", "Wonderland", 42.41342, -70.991648),
            ("place-rbmnl", "Revere Beach", 42.407843, -70.992533),
            ("place-bmmnl", "Beachmont", 42.397542, -70.992319),
            ("place-sdmnl", "Suffolk Downs", 42.39050067, -70.99712259),
            ("place-orhte", "Orient Heights", 42.386867, -71.004736),
            ("place-wimnl", "Wood Island", 42.3796403, -71.02286539),
            ("place-apts", "Airport", 42.374262, -71.030395),
            ("place-mvbcl", "Maverick", 42.36911856, -71.03952958),
            ("place-aqucl", "Aquarium", 42.359784, -71.051652),
            ("place-state", "State", 42.358978, -71.057598),
            ("place-gover", "Government Center", 42.359705, -71.059215),
            ("place-bomnl", "Bowdoin", 42.361365, -71.062037)
        ]
        for (id, name, lat, lng) in blueLineStations {
            stations.append(TransitStation(id: id, name: name, type: .subway, line: "Blue Line", lat: lat, lng: lng))
        }

        // Green Line stations (main trunk + branches)
        let greenLineStations = [
            // Main trunk
            ("place-lech", "Lechmere", 42.370772, -71.076536),
            ("place-spmnl", "Science Park/West End", 42.366664, -71.067666),
            ("place-north", "North Station", 42.365577, -71.06129),
            ("place-haecl", "Haymarket", 42.363021, -71.05829),
            ("place-gover", "Government Center", 42.359705, -71.059215),
            ("place-pktrm", "Park Street", 42.35639457, -71.0624242),
            ("place-boyls", "Boylston", 42.35302, -71.06459),
            ("place-armnl", "Arlington", 42.351902, -71.070893),
            ("place-coecl", "Copley", 42.349974, -71.077447),
            ("place-hymnl", "Hynes Convention Center", 42.347888, -71.087903),
            ("place-kencl", "Kenmore", 42.348949, -71.095169),
            // B Branch
            ("place-bland", "Blandford Street", 42.349293, -71.100258),
            ("place-buecl", "Boston University East", 42.349735, -71.103889),
            ("place-bucen", "Boston University Central", 42.350082, -71.106865),
            ("place-buwst", "Boston University West", 42.350941, -71.113876),
            ("place-stplb", "St. Paul Street B", 42.351289, -71.116104),
            ("place-plsgr", "Pleasant Street", 42.351521, -71.118889),
            ("place-babck", "Babcock Street", 42.351616, -71.121263),
            ("place-brico", "Packards Corner", 42.351967, -71.125031),
            ("place-harvd", "Harvard Avenue", 42.350243, -71.131355),
            ("place-grigg", "Griggs Street", 42.348545, -71.134949),
            ("place-alsgr", "Allston Street", 42.348701, -71.137955),
            ("place-wrnst", "Warren Street", 42.348343, -71.140457),
            ("place-wascm", "Washington Street", 42.343864, -71.142853),
            ("place-sthld", "Sutherland Road", 42.341614, -71.146202),
            ("place-chswk", "Chiswick Road", 42.340805, -71.150711),
            ("place-chill", "Chestnut Hill Avenue", 42.338169, -71.15316),
            ("place-bcnwa", "South Street", 42.339015, -71.157661),
            ("place-lake", "Boston College", 42.340081, -71.166769),
            // C Branch
            ("place-smary", "Saint Mary's Street", 42.345974, -71.107353),
            ("place-hwsst", "Hawes Street", 42.344906, -71.111145),
            ("place-kntst", "Kent Street", 42.344074, -71.114197),
            ("place-stpul", "St. Paul Street C", 42.343327, -71.116997),
            ("place-cool", "Coolidge Corner", 42.342116, -71.121263),
            ("place-sumav", "Summit Avenue", 42.341023, -71.12561),
            ("place-bndhl", "Brandon Hall", 42.340023, -71.129082),
            ("place-fbkst", "Fairbanks Street", 42.339725, -71.131073),
            ("place-tapst", "Tappan Street", 42.338459, -71.138702),
            ("place-denrd", "Dean Road", 42.337807, -71.141853),
            ("place-engav", "Englewood Avenue", 42.336971, -71.14566),
            ("place-clmnl", "Cleveland Circle", 42.336142, -71.149326),
            // D Branch
            ("place-fenwy", "Fenway", 42.345403, -71.104213),
            ("place-longw", "Longwood", 42.341145, -71.110451),
            ("place-bvmnl", "Brookline Village", 42.332562, -71.116853),
            ("place-bcnfd", "Brookline Hills", 42.331316, -71.126683),
            ("place-rsmnl", "Beaconsfield", 42.335765, -71.140823),
            ("place-chhil", "Chestnut Hill", 42.326753, -71.164699),
            ("place-newto", "Newton Centre", 42.329443, -71.192414),
            ("place-newtn", "Newton Highlands", 42.321735, -71.206116),
            ("place-eliot", "Eliot", 42.319023, -71.216713),
            ("place-waban", "Waban", 42.325712, -71.230609),
            ("place-woodl", "Woodland", 42.332697, -71.243362),
            ("place-river", "Riverside", 42.337352, -71.252685),
            // E Branch
            ("place-prmnl", "Prudential", 42.345654, -71.081348),
            ("place-symcl", "Symphony", 42.342687, -71.085056),
            ("place-nuniv", "Northeastern University", 42.340401, -71.088806),
            ("place-mfa", "Museum of Fine Arts", 42.337711, -71.095512),
            ("place-lngmd", "Longwood Medical Area", 42.33596, -71.100052),
            ("place-brmnl", "Brigham Circle", 42.334229, -71.104609),
            ("place-fenwd", "Fenwood Road", 42.333706, -71.105728),
            ("place-mispk", "Mission Park", 42.333195, -71.109756),
            ("place-rvrwy", "Riverway", 42.331684, -71.111931),
            ("place-bckhl", "Back of the Hill", 42.330139, -71.111313),
            ("place-hsmnl", "Heath Street", 42.328316, -71.110252)
        ]
        for (id, name, lat, lng) in greenLineStations {
            // Determine which Green Line branch
            var line = "Green Line"
            if id.contains("lake") || id.contains("bcnwa") || id.contains("chill") || id.contains("chswk") ||
               id.contains("sthld") || id.contains("wascm") || id.contains("wrnst") || id.contains("alsgr") ||
               id.contains("grigg") || id.contains("harvd") || id.contains("brico") || id.contains("babck") ||
               id.contains("plsgr") || id.contains("stplb") || id.contains("buwst") || id.contains("bucen") ||
               id.contains("buecl") || id.contains("bland") {
                line = "Green Line B"
            } else if id.contains("clmnl") || id.contains("engav") || id.contains("denrd") || id.contains("tapst") ||
                      id.contains("fbkst") || id.contains("bndhl") || id.contains("sumav") || id.contains("cool") ||
                      id.contains("stpul") || id.contains("kntst") || id.contains("hwsst") || id.contains("smary") {
                line = "Green Line C"
            } else if id.contains("river") || id.contains("woodl") || id.contains("waban") || id.contains("eliot") ||
                      id.contains("newtn") || id.contains("newto") || id.contains("chhil") || id.contains("rsmnl") ||
                      id.contains("bcnfd") || id.contains("bvmnl") || id.contains("longw") || id.contains("fenwy") {
                line = "Green Line D"
            } else if id.contains("hsmnl") || id.contains("bckhl") || id.contains("rvrwy") || id.contains("mispk") ||
                      id.contains("fenwd") || id.contains("brmnl") || id.contains("lngmd") || id.contains("mfa") ||
                      id.contains("nuniv") || id.contains("symcl") || id.contains("prmnl") {
                line = "Green Line E"
            }
            stations.append(TransitStation(id: id, name: name, type: .subway, line: line, lat: lat, lng: lng))
        }

        // Silver Line stations (BRT)
        let silverLineStations = [
            ("place-wtcst", "World Trade Center", 42.34863, -71.04246),
            ("place-crtst", "Courthouse", 42.352614, -71.04685),
            ("place-sstat", "South Station", 42.352271, -71.055242)
        ]
        for (id, name, lat, lng) in silverLineStations {
            stations.append(TransitStation(id: id, name: name, type: .subway, line: "Silver Line", lat: lat, lng: lng))
        }

        // MARK: - Commuter Rail stations (GTFS stops.txt coordinates)
        // Data source: https://cdn.mbta.com/MBTA_GTFS.zip (stops.txt)

        // Newburyport/Rockport Line (from GTFS)
        let newburyportRockportStations = [
            ("place-ER-0362", "Newburyport", 42.797837, -70.877970),
            ("place-ER-0312", "Rowley", 42.726845, -70.859034),
            ("place-ER-0276", "Ipswich", 42.676921, -70.840589),
            ("place-ER-0227", "Hamilton/Wenham", 42.609212, -70.874801),
            ("place-ER-0208", "North Beverly", 42.583779, -70.883851),
            ("place-ER-0183", "Beverly", 42.547276, -70.885432),
            ("place-ER-0168", "Salem", 42.524792, -70.895876),
            ("place-ER-0128", "Swampscott", 42.473743, -70.922537),
            ("place-ER-0115", "Lynn", 42.462953, -70.945421),
            // Rockport Branch
            ("place-GB-0353", "Rockport", 42.655491, -70.627055),
            ("place-GB-0316", "Gloucester", 42.616799, -70.668345),
            ("place-GB-0296", "West Gloucester", 42.611933, -70.705417),
            ("place-GB-0254", "Manchester", 42.573687, -70.770090),
            ("place-GB-0229", "Beverly Farms", 42.561651, -70.811405),
            ("place-GB-0198", "Montserrat", 42.562171, -70.869254)
        ]
        for (id, name, lat, lng) in newburyportRockportStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Newburyport/Rockport", lat: lat, lng: lng))
        }

        // Haverhill Line (from GTFS)
        let haverhillStations = [
            ("place-WR-0329", "Haverhill", 42.773474, -71.086237),
            ("place-WR-0325", "Bradford", 42.766912, -71.088411),
            ("place-WR-0264", "Lawrence", 42.701806, -71.151980),
            ("place-WR-0228", "Andover", 42.658336, -71.144502),
            ("place-WR-0205", "Ballardvale", 42.627356, -71.159962),
            ("place-WR-0163", "North Wilmington", 42.571073, -71.160939),
            ("place-WR-0120", "Reading", 42.522210, -71.108294),
            ("place-WR-0099", "Wakefield", 42.502126, -71.075566),
            ("place-WR-0085", "Greenwood", 42.483005, -71.067247),
            ("place-WR-0075", "Melrose Highlands", 42.469464, -71.068297),
            ("place-WR-0067", "Melrose/Cedar Park", 42.458768, -71.069789),
            ("place-WR-0062", "Wyoming Hill", 42.451731, -71.069379)
        ]
        for (id, name, lat, lng) in haverhillStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Haverhill", lat: lat, lng: lng))
        }

        // Lowell Line (from GTFS)
        let lowellStations = [
            ("place-NHRML-0254", "Lowell", 42.635350, -71.314543),
            ("place-NHRML-0218", "North Billerica", 42.593248, -71.280995),
            ("place-NHRML-0152", "Wilmington", 42.546624, -71.174334),
            ("place-NHRML-0127", "Anderson/Woburn", 42.516987, -71.144475),
            ("place-NHRML-0116", "Mishawum", 42.504402, -71.137618),
            ("place-NHRML-0078", "Winchester Center", 42.451088, -71.137830),
            ("place-NHRML-0073", "Wedgemere", 42.444948, -71.140169),
            ("place-NHRML-0055", "West Medford", 42.421776, -71.133342)
        ]
        for (id, name, lat, lng) in lowellStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Lowell", lat: lat, lng: lng))
        }

        // Fitchburg Line (from GTFS)
        let fitchburgStations = [
            ("place-FR-3338", "Wachusett", 42.553477, -71.848488),
            ("place-FR-0494", "Fitchburg", 42.580720, -71.792611),
            ("place-FR-0451", "North Leominster", 42.539017, -71.739186),
            ("place-FR-0394", "Shirley", 42.545089, -71.648004),
            ("place-FR-0361", "Ayer", 42.559074, -71.588476),
            ("place-FR-0301", "Littleton/Route 495", 42.519236, -71.502643),
            ("place-FR-0253", "South Acton", 42.460375, -71.457744),
            ("place-FR-0219", "West Concord", 42.457043, -71.392892),
            ("place-FR-0201", "Concord", 42.456565, -71.357677),
            ("place-FR-0167", "Lincoln", 42.413641, -71.325512),
            ("place-FR-0147", "Silver Hill", 42.395625, -71.302357),
            ("place-FR-0137", "Hastings", 42.385755, -71.289203),
            ("place-FR-0132", "Kendal Green", 42.378970, -71.282411),
            ("place-FR-0115", "Brandeis/Roberts", 42.361898, -71.260065),
            ("place-FR-0098", "Waltham", 42.374296, -71.235615),
            ("place-FR-0074", "Waverley", 42.387600, -71.190744),
            ("place-FR-0064", "Belmont", 42.395896, -71.176190)
        ]
        for (id, name, lat, lng) in fitchburgStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Fitchburg", lat: lat, lng: lng))
        }

        // Worcester Line (from GTFS)
        let worcesterStations = [
            ("place-WML-0442", "Worcester", 42.261291, -71.794927),
            ("place-WML-0364", "Grafton", 42.246600, -71.685325),
            ("place-WML-0340", "Westborough", 42.269644, -71.647076),
            ("place-WML-0274", "Southborough", 42.267024, -71.524371),
            ("place-WML-0252", "Ashland", 42.261490, -71.482161),
            ("place-WML-0214", "Framingham", 42.276108, -71.420055),
            ("place-WML-0199", "West Natick", 42.283064, -71.391797),
            ("place-WML-0177", "Natick Center", 42.285719, -71.347133),
            ("place-WML-0147", "Wellesley Square", 42.297526, -71.294173),
            ("place-WML-0135", "Wellesley Hills", 42.310370, -71.277044),
            ("place-WML-0125", "Wellesley Farms", 42.323608, -71.272288),
            ("place-WML-0102", "Auburndale", 42.345833, -71.250373),
            ("place-WML-0091", "West Newton", 42.347878, -71.230528),
            ("place-WML-0081", "Newtonville", 42.351702, -71.205408),
            ("place-WML-0035", "Boston Landing", 42.357293, -71.139883),
            ("place-WML-0025", "Lansdowne", 42.347581, -71.099974)
        ]
        for (id, name, lat, lng) in worcesterStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Worcester", lat: lat, lng: lng))
        }

        // Franklin Line (from GTFS)
        let franklinStations = [
            ("place-FB-0303", "Forge Park/495", 42.089941, -71.439020),
            ("place-FB-0275", "Franklin", 42.083238, -71.396102),
            ("place-FB-0230", "Norfolk", 42.120694, -71.325217),
            ("place-FB-0191", "Walpole", 42.145477, -71.257790),
            ("place-FB-0177", "Plimptonville", 42.159123, -71.236125),
            ("place-FB-0166", "Windsor Gardens", 42.172127, -71.219366),
            ("place-FB-0148", "Norwood Central", 42.188775, -71.199665),
            ("place-FB-0143", "Norwood Depot", 42.196857, -71.196688),
            ("place-FB-0125", "Islington", 42.221050, -71.183961),
            ("place-FB-0118", "Dedham Corporate Center", 42.227079, -71.174254),
            ("place-FB-0109", "Endicott", 42.233249, -71.158647)
        ]
        for (id, name, lat, lng) in franklinStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Franklin", lat: lat, lng: lng))
        }

        // Providence/Stoughton Line (from GTFS)
        let providenceStations = [
            ("place-NEC-1659", "Wickford Junction", 41.581289, -71.491147),
            ("place-NEC-1768", "TF Green Airport", 41.726599, -71.442453),
            ("place-NEC-1851", "Providence", 41.829293, -71.413301),
            ("place-NEC-1891", "Pawtucket/Central Falls", 41.878762, -71.392000),
            ("place-NEC-1919", "South Attleboro", 41.897943, -71.354621),
            ("place-NEC-1969", "Attleboro", 41.940739, -71.285094),
            ("place-NEC-2040", "Mansfield", 42.032787, -71.219917),
            ("place-NEC-2108", "Sharon", 42.124553, -71.184468),
            ("place-NEC-2139", "Canton Junction", 42.163204, -71.153760),
            ("place-NEC-2173", "Route 128", 42.210308, -71.147134),
            ("place-NEC-2203", "Hyde Park", 42.255030, -71.125526)
        ]
        for (id, name, lat, lng) in providenceStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Providence/Stoughton", lat: lat, lng: lng))
        }

        // Middleborough/Lakeville Line (from GTFS)
        let middleboroughStations = [
            ("place-MM-0356", "Lakeville", 41.878210, -70.918444),
            ("place-MM-0277", "Bridgewater", 41.984916, -70.965370),
            ("place-MM-0219", "Campello", 42.060951, -71.011004),
            ("place-MM-0200", "Brockton", 42.084659, -71.016534),
            ("place-MM-0186", "Montello", 42.106555, -71.022001),
            ("place-MM-0150", "Holbrook/Randolph", 42.156343, -71.027371)
        ]
        for (id, name, lat, lng) in middleboroughStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Middleborough", lat: lat, lng: lng))
        }

        // Kingston Line (from GTFS)
        let kingstonStations = [
            ("place-KB-0351", "Kingston", 41.977620, -70.721709)
        ]
        for (id, name, lat, lng) in kingstonStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Kingston", lat: lat, lng: lng))
        }

        // Greenbush Line (from GTFS)
        let greenbushStations = [
            ("place-GRB-0276", "Greenbush", 42.178776, -70.746641),
            ("place-GRB-0233", "North Scituate", 42.219528, -70.788602),
            ("place-GRB-0199", "Cohasset", 42.244210, -70.837529),
            ("place-GRB-0183", "Nantasket Junction", 42.244959, -70.869205),
            ("place-GRB-0162", "West Hingham", 42.235838, -70.902708),
            ("place-GRB-0146", "East Weymouth", 42.219100, -70.921400),
            ("place-GRB-0118", "Weymouth Landing/East Braintree", 42.221503, -70.968152)
        ]
        for (id, name, lat, lng) in greenbushStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Greenbush", lat: lat, lng: lng))
        }

        // Fairmount Line (from GTFS)
        let fairmountStations = [
            ("place-DB-0095", "Readville", 42.238405, -71.133246),
            ("place-DB-2205", "Fairmount", 42.253638, -71.119270),
            ("place-DB-2222", "Blue Hill Avenue", 42.271466, -71.095782),
            ("place-DB-2230", "Morton Street", 42.280994, -71.085475),
            ("place-DB-2240", "Talbot Avenue", 42.292246, -71.078140),
            ("place-DB-2249", "Four Corners/Geneva", 42.305037, -71.076833),
            ("place-DB-2258", "Uphams Corner", 42.319125, -71.068627),
            ("place-DB-2265", "Newmarket", 42.327415, -71.065674)
        ]
        for (id, name, lat, lng) in fairmountStations {
            stations.append(TransitStation(id: id, name: name, type: .commuterRail, line: "Fairmount", lat: lat, lng: lng))
        }

        // Major hub stations (North Station and South Station - shared by multiple lines)
        stations.append(TransitStation(id: "place-north-cr", name: "North Station", type: .commuterRail, line: "Commuter Rail Hub", lat: 42.366389, lng: -71.062222))
        stations.append(TransitStation(id: "place-sstat-cr", name: "South Station", type: .commuterRail, line: "Commuter Rail Hub", lat: 42.352271, lng: -71.055242))
        stations.append(TransitStation(id: "place-bbsta-cr", name: "Back Bay", type: .commuterRail, line: "Commuter Rail Hub", lat: 42.34735, lng: -71.075727))
        stations.append(TransitStation(id: "place-rugg-cr", name: "Ruggles", type: .commuterRail, line: "Commuter Rail Hub", lat: 42.336377, lng: -71.088961))

        return stations
    }

    // MARK: - GTFS Route Data (Accurate Polylines from MBTA GTFS shapes.txt)

    /// MBTA route polylines from official GTFS data
    /// Data source: https://cdn.mbta.com/MBTA_GTFS.zip (shapes.txt)
    /// Polylines simplified using Douglas-Peucker algorithm for performance
    private func getStaticMBTARoutes() -> [TransitRoute] {
        var routes: [TransitRoute] = []

        // Red Line (Ashmont) (27 points from 294 original)
        routes.append(TransitRoute(
            id: "red_ashmont",
            name: "Red Line (Ashmont)",
            line: .red,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.396152, longitude: -71.142079),
                CLLocationCoordinate2D(latitude: 42.396356, longitude: -71.138293),
                CLLocationCoordinate2D(latitude: 42.397613, longitude: -71.137178),
                CLLocationCoordinate2D(latitude: 42.397974, longitude: -71.135204),
                CLLocationCoordinate2D(latitude: 42.397205, longitude: -71.124169),
                CLLocationCoordinate2D(latitude: 42.395609, longitude: -71.119693),
                CLLocationCoordinate2D(latitude: 42.390618, longitude: -71.118828),
                CLLocationCoordinate2D(latitude: 42.378027, longitude: -71.120084),
                CLLocationCoordinate2D(latitude: 42.375717, longitude: -71.118479),
                CLLocationCoordinate2D(latitude: 42.373459, longitude: -71.118821),
                CLLocationCoordinate2D(latitude: 42.372506, longitude: -71.115943),
                CLLocationCoordinate2D(latitude: 42.370040, longitude: -71.113001),
                CLLocationCoordinate2D(latitude: 42.363774, longitude: -71.100988),
                CLLocationCoordinate2D(latitude: 42.361104, longitude: -71.070265),
                CLLocationCoordinate2D(latitude: 42.357718, longitude: -71.065157),
                CLLocationCoordinate2D(latitude: 42.351393, longitude: -71.052468),
                CLLocationCoordinate2D(latitude: 42.349774, longitude: -71.052406),
                CLLocationCoordinate2D(latitude: 42.343350, longitude: -71.057116),
                CLLocationCoordinate2D(latitude: 42.329961, longitude: -71.056873),
                CLLocationCoordinate2D(latitude: 42.327699, longitude: -71.057967),
                CLLocationCoordinate2D(latitude: 42.322660, longitude: -71.052904),
                CLLocationCoordinate2D(latitude: 42.314492, longitude: -71.052121),
                CLLocationCoordinate2D(latitude: 42.308262, longitude: -71.054486),
                CLLocationCoordinate2D(latitude: 42.301099, longitude: -71.056059),
                CLLocationCoordinate2D(latitude: 42.299544, longitude: -71.063449),
                CLLocationCoordinate2D(latitude: 42.297112, longitude: -71.066255),
                CLLocationCoordinate2D(latitude: 42.284251, longitude: -71.063557)
            ]
        ))

        // Red Line (Braintree) (33 points from 408 original)
        routes.append(TransitRoute(
            id: "red_braintree",
            name: "Red Line (Braintree)",
            line: .red,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.396152, longitude: -71.142079),
                CLLocationCoordinate2D(latitude: 42.396356, longitude: -71.138293),
                CLLocationCoordinate2D(latitude: 42.397613, longitude: -71.137178),
                CLLocationCoordinate2D(latitude: 42.397974, longitude: -71.135204),
                CLLocationCoordinate2D(latitude: 42.397205, longitude: -71.124169),
                CLLocationCoordinate2D(latitude: 42.395609, longitude: -71.119693),
                CLLocationCoordinate2D(latitude: 42.390618, longitude: -71.118828),
                CLLocationCoordinate2D(latitude: 42.378027, longitude: -71.120084),
                CLLocationCoordinate2D(latitude: 42.375717, longitude: -71.118479),
                CLLocationCoordinate2D(latitude: 42.373459, longitude: -71.118821),
                CLLocationCoordinate2D(latitude: 42.372506, longitude: -71.115943),
                CLLocationCoordinate2D(latitude: 42.370040, longitude: -71.113001),
                CLLocationCoordinate2D(latitude: 42.363774, longitude: -71.100988),
                CLLocationCoordinate2D(latitude: 42.361104, longitude: -71.070265),
                CLLocationCoordinate2D(latitude: 42.357718, longitude: -71.065157),
                CLLocationCoordinate2D(latitude: 42.351393, longitude: -71.052468),
                CLLocationCoordinate2D(latitude: 42.349774, longitude: -71.052406),
                CLLocationCoordinate2D(latitude: 42.343350, longitude: -71.057116),
                CLLocationCoordinate2D(latitude: 42.329961, longitude: -71.056873),
                CLLocationCoordinate2D(latitude: 42.327573, longitude: -71.057963),
                CLLocationCoordinate2D(latitude: 42.322832, longitude: -71.052753),
                CLLocationCoordinate2D(latitude: 42.315200, longitude: -71.051873),
                CLLocationCoordinate2D(latitude: 42.303619, longitude: -71.055451),
                CLLocationCoordinate2D(latitude: 42.301309, longitude: -71.055345),
                CLLocationCoordinate2D(latitude: 42.281619, longitude: -71.033967),
                CLLocationCoordinate2D(latitude: 42.276856, longitude: -71.031190),
                CLLocationCoordinate2D(latitude: 42.253484, longitude: -71.006487),
                CLLocationCoordinate2D(latitude: 42.251174, longitude: -71.004894),
                CLLocationCoordinate2D(latitude: 42.247463, longitude: -71.003456),
                CLLocationCoordinate2D(latitude: 42.231472, longitude: -71.007053),
                CLLocationCoordinate2D(latitude: 42.229020, longitude: -71.006279),
                CLLocationCoordinate2D(latitude: 42.219134, longitude: -71.000256),
                CLLocationCoordinate2D(latitude: 42.208371, longitude: -71.001423)
            ]
        ))

        // Orange Line (17 points from 301 original)
        routes.append(TransitRoute(
            id: "orange",
            name: "Orange Line",
            line: .orange,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.300731, longitude: -71.114102),
                CLLocationCoordinate2D(latitude: 42.311567, longitude: -71.106832),
                CLLocationCoordinate2D(latitude: 42.317776, longitude: -71.103960),
                CLLocationCoordinate2D(latitude: 42.322875, longitude: -71.100028),
                CLLocationCoordinate2D(latitude: 42.328915, longitude: -71.097242),
                CLLocationCoordinate2D(latitude: 42.331856, longitude: -71.094924),
                CLLocationCoordinate2D(latitude: 42.347309, longitude: -71.076049),
                CLLocationCoordinate2D(latitude: 42.347481, longitude: -71.067527),
                CLLocationCoordinate2D(latitude: 42.348989, longitude: -71.064201),
                CLLocationCoordinate2D(latitude: 42.353618, longitude: -71.062304),
                CLLocationCoordinate2D(latitude: 42.358026, longitude: -71.057719),
                CLLocationCoordinate2D(latitude: 42.363321, longitude: -71.058165),
                CLLocationCoordinate2D(latitude: 42.371298, longitude: -71.065725),
                CLLocationCoordinate2D(latitude: 42.375918, longitude: -71.073634),
                CLLocationCoordinate2D(latitude: 42.380307, longitude: -71.076932),
                CLLocationCoordinate2D(latitude: 42.418976, longitude: -71.076807),
                CLLocationCoordinate2D(latitude: 42.436967, longitude: -71.070867)
            ]
        ))

        // Blue Line (12 points from 206 original)
        routes.append(TransitRoute(
            id: "blue",
            name: "Blue Line",
            line: .blue,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.413638, longitude: -70.991535),
                CLLocationCoordinate2D(latitude: 42.409661, longitude: -70.992685),
                CLLocationCoordinate2D(latitude: 42.399105, longitude: -70.991540),
                CLLocationCoordinate2D(latitude: 42.389453, longitude: -70.998328),
                CLLocationCoordinate2D(latitude: 42.384796, longitude: -71.010710),
                CLLocationCoordinate2D(latitude: 42.380506, longitude: -71.016808),
                CLLocationCoordinate2D(latitude: 42.379051, longitude: -71.024418),
                CLLocationCoordinate2D(latitude: 42.372659, longitude: -71.032145),
                CLLocationCoordinate2D(latitude: 42.372227, longitude: -71.036022),
                CLLocationCoordinate2D(latitude: 42.360115, longitude: -71.047883),
                CLLocationCoordinate2D(latitude: 42.358891, longitude: -71.057709),
                CLLocationCoordinate2D(latitude: 42.361180, longitude: -71.062395)
            ]
        ))

        // Green Line B (17 points from 275 original)
        routes.append(TransitRoute(
            id: "green_b",
            name: "Green Line B",
            line: .greenB,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.340076, longitude: -71.166810),
                CLLocationCoordinate2D(latitude: 42.339890, longitude: -71.159123),
                CLLocationCoordinate2D(latitude: 42.338020, longitude: -71.153317),
                CLLocationCoordinate2D(latitude: 42.341014, longitude: -71.150242),
                CLLocationCoordinate2D(latitude: 42.342486, longitude: -71.143890),
                CLLocationCoordinate2D(latitude: 42.348806, longitude: -71.139939),
                CLLocationCoordinate2D(latitude: 42.348255, longitude: -71.135719),
                CLLocationCoordinate2D(latitude: 42.350570, longitude: -71.130592),
                CLLocationCoordinate2D(latitude: 42.350715, longitude: -71.127075),
                CLLocationCoordinate2D(latitude: 42.352131, longitude: -71.124649),
                CLLocationCoordinate2D(latitude: 42.348962, longitude: -71.093277),
                CLLocationCoordinate2D(latitude: 42.347988, longitude: -71.091707),
                CLLocationCoordinate2D(latitude: 42.347838, longitude: -71.085472),
                CLLocationCoordinate2D(latitude: 42.352427, longitude: -71.068292),
                CLLocationCoordinate2D(latitude: 42.352502, longitude: -71.064808),
                CLLocationCoordinate2D(latitude: 42.355287, longitude: -71.063349),
                CLLocationCoordinate2D(latitude: 42.359379, longitude: -71.059357)
            ]
        ))

        // Green Line C (9 points from 190 original)
        routes.append(TransitRoute(
            id: "green_c",
            name: "Green Line C",
            line: .greenC,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.336152, longitude: -71.149186),
                CLLocationCoordinate2D(latitude: 42.339514, longitude: -71.134618),
                CLLocationCoordinate2D(latitude: 42.339887, longitude: -71.129266),
                CLLocationCoordinate2D(latitude: 42.348799, longitude: -71.096973),
                CLLocationCoordinate2D(latitude: 42.347838, longitude: -71.085472),
                CLLocationCoordinate2D(latitude: 42.352427, longitude: -71.068292),
                CLLocationCoordinate2D(latitude: 42.352481, longitude: -71.064880),
                CLLocationCoordinate2D(latitude: 42.355287, longitude: -71.063349),
                CLLocationCoordinate2D(latitude: 42.359379, longitude: -71.059357)
            ]
        ))

        // Green Line D (35 points from 527 original)
        routes.append(TransitRoute(
            id: "green_d",
            name: "Green Line D",
            line: .greenD,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.377119, longitude: -71.094326),
                CLLocationCoordinate2D(latitude: 42.374927, longitude: -71.081748),
                CLLocationCoordinate2D(latitude: 42.370578, longitude: -71.075403),
                CLLocationCoordinate2D(latitude: 42.366204, longitude: -71.066485),
                CLLocationCoordinate2D(latitude: 42.365627, longitude: -71.064332),
                CLLocationCoordinate2D(latitude: 42.366493, longitude: -71.061417),
                CLLocationCoordinate2D(latitude: 42.363191, longitude: -71.058281),
                CLLocationCoordinate2D(latitude: 42.360960, longitude: -71.057951),
                CLLocationCoordinate2D(latitude: 42.355287, longitude: -71.063349),
                CLLocationCoordinate2D(latitude: 42.352502, longitude: -71.064808),
                CLLocationCoordinate2D(latitude: 42.352427, longitude: -71.068292),
                CLLocationCoordinate2D(latitude: 42.347838, longitude: -71.085472),
                CLLocationCoordinate2D(latitude: 42.347998, longitude: -71.091797),
                CLLocationCoordinate2D(latitude: 42.348969, longitude: -71.094102),
                CLLocationCoordinate2D(latitude: 42.347639, longitude: -71.101425),
                CLLocationCoordinate2D(latitude: 42.346617, longitude: -71.101917),
                CLLocationCoordinate2D(latitude: 42.341432, longitude: -71.110169),
                CLLocationCoordinate2D(latitude: 42.333882, longitude: -71.114548),
                CLLocationCoordinate2D(latitude: 42.331668, longitude: -71.119143),
                CLLocationCoordinate2D(latitude: 42.331166, longitude: -71.125502),
                CLLocationCoordinate2D(latitude: 42.331891, longitude: -71.132571),
                CLLocationCoordinate2D(latitude: 42.335467, longitude: -71.139666),
                CLLocationCoordinate2D(latitude: 42.335944, longitude: -71.142667),
                CLLocationCoordinate2D(latitude: 42.334620, longitude: -71.150069),
                CLLocationCoordinate2D(latitude: 42.327767, longitude: -71.161483),
                CLLocationCoordinate2D(latitude: 42.326709, longitude: -71.164646),
                CLLocationCoordinate2D(latitude: 42.330301, longitude: -71.188851),
                CLLocationCoordinate2D(latitude: 42.329009, longitude: -71.193243),
                CLLocationCoordinate2D(latitude: 42.323645, longitude: -71.203867),
                CLLocationCoordinate2D(latitude: 42.318539, longitude: -71.209464),
                CLLocationCoordinate2D(latitude: 42.317671, longitude: -71.212538),
                CLLocationCoordinate2D(latitude: 42.318075, longitude: -71.214727),
                CLLocationCoordinate2D(latitude: 42.324883, longitude: -71.229110),
                CLLocationCoordinate2D(latitude: 42.328292, longitude: -71.234233),
                CLLocationCoordinate2D(latitude: 42.337302, longitude: -71.252370)
            ]
        ))

        // Green Line E (19 points from 363 original)
        routes.append(TransitRoute(
            id: "green_e",
            name: "Green Line E",
            line: .greenE,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.328341, longitude: -71.110095),
                CLLocationCoordinate2D(latitude: 42.331939, longitude: -71.111978),
                CLLocationCoordinate2D(latitude: 42.332760, longitude: -71.110745),
                CLLocationCoordinate2D(latitude: 42.333332, longitude: -71.106456),
                CLLocationCoordinate2D(latitude: 42.341081, longitude: -71.087026),
                CLLocationCoordinate2D(latitude: 42.346792, longitude: -71.079788),
                CLLocationCoordinate2D(latitude: 42.349486, longitude: -71.079188),
                CLLocationCoordinate2D(latitude: 42.352427, longitude: -71.068292),
                CLLocationCoordinate2D(latitude: 42.352532, longitude: -71.064751),
                CLLocationCoordinate2D(latitude: 42.355287, longitude: -71.063349),
                CLLocationCoordinate2D(latitude: 42.360960, longitude: -71.057951),
                CLLocationCoordinate2D(latitude: 42.362562, longitude: -71.058051),
                CLLocationCoordinate2D(latitude: 42.366493, longitude: -71.061417),
                CLLocationCoordinate2D(latitude: 42.365627, longitude: -71.064332),
                CLLocationCoordinate2D(latitude: 42.366303, longitude: -71.066764),
                CLLocationCoordinate2D(latitude: 42.373447, longitude: -71.080345),
                CLLocationCoordinate2D(latitude: 42.386305, longitude: -71.093407),
                CLLocationCoordinate2D(latitude: 42.391837, longitude: -71.104594),
                CLLocationCoordinate2D(latitude: 42.407654, longitude: -71.116767)
            ]
        ))

        // Fairmount Line (17 points from 270 original)
        routes.append(TransitRoute(
            id: "cr_fairmount",
            name: "Fairmount Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.237850, longitude: -71.133460),
                CLLocationCoordinate2D(latitude: 42.239554, longitude: -71.131068),
                CLLocationCoordinate2D(latitude: 42.246789, longitude: -71.126134),
                CLLocationCoordinate2D(latitude: 42.254491, longitude: -71.118556),
                CLLocationCoordinate2D(latitude: 42.262867, longitude: -71.105414),
                CLLocationCoordinate2D(latitude: 42.269931, longitude: -71.097977),
                CLLocationCoordinate2D(latitude: 42.273461, longitude: -71.093071),
                CLLocationCoordinate2D(latitude: 42.288127, longitude: -71.079240),
                CLLocationCoordinate2D(latitude: 42.291759, longitude: -71.078168),
                CLLocationCoordinate2D(latitude: 42.299541, longitude: -71.079323),
                CLLocationCoordinate2D(latitude: 42.302105, longitude: -71.078973),
                CLLocationCoordinate2D(latitude: 42.308323, longitude: -71.073461),
                CLLocationCoordinate2D(latitude: 42.315984, longitude: -71.069599),
                CLLocationCoordinate2D(latitude: 42.325473, longitude: -71.066824),
                CLLocationCoordinate2D(latitude: 42.336293, longitude: -71.060094),
                CLLocationCoordinate2D(latitude: 42.342912, longitude: -71.059976),
                CLLocationCoordinate2D(latitude: 42.351763, longitude: -71.054797)
            ]
        ))

        // Fitchburg Line (85 points from 1157 original)
        routes.append(TransitRoute(
            id: "cr_fitchburg",
            name: "Fitchburg Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.553477, longitude: -71.848488),
                CLLocationCoordinate2D(latitude: 42.555542, longitude: -71.845726),
                CLLocationCoordinate2D(latitude: 42.558118, longitude: -71.844438),
                CLLocationCoordinate2D(latitude: 42.562322, longitude: -71.843837),
                CLLocationCoordinate2D(latitude: 42.569797, longitude: -71.840812),
                CLLocationCoordinate2D(latitude: 42.580305, longitude: -71.825706),
                CLLocationCoordinate2D(latitude: 42.584587, longitude: -71.815213),
                CLLocationCoordinate2D(latitude: 42.585045, longitude: -71.812102),
                CLLocationCoordinate2D(latitude: 42.581269, longitude: -71.800343),
                CLLocationCoordinate2D(latitude: 42.580887, longitude: -71.793104),
                CLLocationCoordinate2D(latitude: 42.578639, longitude: -71.790359),
                CLLocationCoordinate2D(latitude: 42.574623, longitude: -71.788889),
                CLLocationCoordinate2D(latitude: 42.572592, longitude: -71.786380),
                CLLocationCoordinate2D(latitude: 42.571380, longitude: -71.783071),
                CLLocationCoordinate2D(latitude: 42.569159, longitude: -71.769983),
                CLLocationCoordinate2D(latitude: 42.561094, longitude: -71.755551),
                CLLocationCoordinate2D(latitude: 42.527344, longitude: -71.729745),
                CLLocationCoordinate2D(latitude: 42.523983, longitude: -71.724219),
                CLLocationCoordinate2D(latitude: 42.521973, longitude: -71.715343),
                CLLocationCoordinate2D(latitude: 42.522500, longitude: -71.712566),
                CLLocationCoordinate2D(latitude: 42.523877, longitude: -71.710698),
                CLLocationCoordinate2D(latitude: 42.533464, longitude: -71.705449),
                CLLocationCoordinate2D(latitude: 42.536290, longitude: -71.702731),
                CLLocationCoordinate2D(latitude: 42.541115, longitude: -71.693603),
                CLLocationCoordinate2D(latitude: 42.545470, longitude: -71.675055),
                CLLocationCoordinate2D(latitude: 42.545933, longitude: -71.669418),
                CLLocationCoordinate2D(latitude: 42.544574, longitude: -71.653077),
                CLLocationCoordinate2D(latitude: 42.545534, longitude: -71.645203),
                CLLocationCoordinate2D(latitude: 42.553479, longitude: -71.618806),
                CLLocationCoordinate2D(latitude: 42.559069, longitude: -71.595694),
                CLLocationCoordinate2D(latitude: 42.558861, longitude: -71.585093),
                CLLocationCoordinate2D(latitude: 42.555510, longitude: -71.570385),
                CLLocationCoordinate2D(latitude: 42.557943, longitude: -71.541757),
                CLLocationCoordinate2D(latitude: 42.557640, longitude: -71.538098),
                CLLocationCoordinate2D(latitude: 42.556205, longitude: -71.534734),
                CLLocationCoordinate2D(latitude: 42.537064, longitude: -71.511386),
                CLLocationCoordinate2D(latitude: 42.512212, longitude: -71.498970),
                CLLocationCoordinate2D(latitude: 42.498937, longitude: -71.488839),
                CLLocationCoordinate2D(latitude: 42.489307, longitude: -71.483209),
                CLLocationCoordinate2D(latitude: 42.479414, longitude: -71.474909),
                CLLocationCoordinate2D(latitude: 42.465341, longitude: -71.468539),
                CLLocationCoordinate2D(latitude: 42.462589, longitude: -71.465934),
                CLLocationCoordinate2D(latitude: 42.460651, longitude: -71.460721),
                CLLocationCoordinate2D(latitude: 42.459480, longitude: -71.446378),
                CLLocationCoordinate2D(latitude: 42.456303, longitude: -71.437808),
                CLLocationCoordinate2D(latitude: 42.455190, longitude: -71.431342),
                CLLocationCoordinate2D(latitude: 42.456025, longitude: -71.419645),
                CLLocationCoordinate2D(latitude: 42.454979, longitude: -71.403595),
                CLLocationCoordinate2D(latitude: 42.458114, longitude: -71.386064),
                CLLocationCoordinate2D(latitude: 42.457673, longitude: -71.361636),
                CLLocationCoordinate2D(latitude: 42.456812, longitude: -71.358187),
                CLLocationCoordinate2D(latitude: 42.454505, longitude: -71.355200),
                CLLocationCoordinate2D(latitude: 42.443191, longitude: -71.349282),
                CLLocationCoordinate2D(latitude: 42.430821, longitude: -71.338298),
                CLLocationCoordinate2D(latitude: 42.408780, longitude: -71.321967),
                CLLocationCoordinate2D(latitude: 42.406522, longitude: -71.320951),
                CLLocationCoordinate2D(latitude: 42.403100, longitude: -71.320819),
                CLLocationCoordinate2D(latitude: 42.401027, longitude: -71.319667),
                CLLocationCoordinate2D(latitude: 42.399224, longitude: -71.316684),
                CLLocationCoordinate2D(latitude: 42.395130, longitude: -71.299854),
                CLLocationCoordinate2D(latitude: 42.393583, longitude: -71.296205),
                CLLocationCoordinate2D(latitude: 42.382218, longitude: -71.285949),
                CLLocationCoordinate2D(latitude: 42.377652, longitude: -71.280811),
                CLLocationCoordinate2D(latitude: 42.373539, longitude: -71.274116),
                CLLocationCoordinate2D(latitude: 42.364894, longitude: -71.268224),
                CLLocationCoordinate2D(latitude: 42.363129, longitude: -71.265799),
                CLLocationCoordinate2D(latitude: 42.361933, longitude: -71.261315),
                CLLocationCoordinate2D(latitude: 42.362395, longitude: -71.257349),
                CLLocationCoordinate2D(latitude: 42.367154, longitude: -71.248059),
                CLLocationCoordinate2D(latitude: 42.371416, longitude: -71.243468),
                CLLocationCoordinate2D(latitude: 42.373046, longitude: -71.240352),
                CLLocationCoordinate2D(latitude: 42.375730, longitude: -71.228021),
                CLLocationCoordinate2D(latitude: 42.379918, longitude: -71.219378),
                CLLocationCoordinate2D(latitude: 42.387162, longitude: -71.191775),
                CLLocationCoordinate2D(latitude: 42.391834, longitude: -71.182783),
                CLLocationCoordinate2D(latitude: 42.395317, longitude: -71.178717),
                CLLocationCoordinate2D(latitude: 42.396018, longitude: -71.175595),
                CLLocationCoordinate2D(latitude: 42.395745, longitude: -71.153687),
                CLLocationCoordinate2D(latitude: 42.392684, longitude: -71.144379),
                CLLocationCoordinate2D(latitude: 42.388298, longitude: -71.119069),
                CLLocationCoordinate2D(latitude: 42.382157, longitude: -71.108470),
                CLLocationCoordinate2D(latitude: 42.379798, longitude: -71.099669),
                CLLocationCoordinate2D(latitude: 42.376383, longitude: -71.092675),
                CLLocationCoordinate2D(latitude: 42.373477, longitude: -71.072145),
                CLLocationCoordinate2D(latitude: 42.366431, longitude: -71.062503)
            ]
        ))

        // Franklin Line (34 points from 794 original)
        routes.append(TransitRoute(
            id: "cr_franklin",
            name: "Franklin Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.089894, longitude: -71.439581),
                CLLocationCoordinate2D(latitude: 42.090165, longitude: -71.435579),
                CLLocationCoordinate2D(latitude: 42.087811, longitude: -71.429376),
                CLLocationCoordinate2D(latitude: 42.087578, longitude: -71.416946),
                CLLocationCoordinate2D(latitude: 42.084615, longitude: -71.412233),
                CLLocationCoordinate2D(latitude: 42.079082, longitude: -71.408498),
                CLLocationCoordinate2D(latitude: 42.078239, longitude: -71.405829),
                CLLocationCoordinate2D(latitude: 42.078577, longitude: -71.403438),
                CLLocationCoordinate2D(latitude: 42.108034, longitude: -71.357866),
                CLLocationCoordinate2D(latitude: 42.136371, longitude: -71.283987),
                CLLocationCoordinate2D(latitude: 42.142771, longitude: -71.263161),
                CLLocationCoordinate2D(latitude: 42.155314, longitude: -71.240711),
                CLLocationCoordinate2D(latitude: 42.175966, longitude: -71.214502),
                CLLocationCoordinate2D(latitude: 42.183206, longitude: -71.202015),
                CLLocationCoordinate2D(latitude: 42.197172, longitude: -71.196646),
                CLLocationCoordinate2D(latitude: 42.206999, longitude: -71.195352),
                CLLocationCoordinate2D(latitude: 42.223199, longitude: -71.181898),
                CLLocationCoordinate2D(latitude: 42.226097, longitude: -71.176906),
                CLLocationCoordinate2D(latitude: 42.234462, longitude: -71.155227),
                CLLocationCoordinate2D(latitude: 42.236478, longitude: -71.137282),
                CLLocationCoordinate2D(latitude: 42.237949, longitude: -71.134153),
                CLLocationCoordinate2D(latitude: 42.257378, longitude: -71.124602),
                CLLocationCoordinate2D(latitude: 42.280221, longitude: -71.119680),
                CLLocationCoordinate2D(latitude: 42.293018, longitude: -71.118549),
                CLLocationCoordinate2D(latitude: 42.310588, longitude: -71.107250),
                CLLocationCoordinate2D(latitude: 42.318201, longitude: -71.103504),
                CLLocationCoordinate2D(latitude: 42.322745, longitude: -71.099932),
                CLLocationCoordinate2D(latitude: 42.328900, longitude: -71.097116),
                CLLocationCoordinate2D(latitude: 42.333345, longitude: -71.093002),
                CLLocationCoordinate2D(latitude: 42.347236, longitude: -71.076065),
                CLLocationCoordinate2D(latitude: 42.347493, longitude: -71.069002),
                CLLocationCoordinate2D(latitude: 42.345852, longitude: -71.059053),
                CLLocationCoordinate2D(latitude: 42.346792, longitude: -71.057534),
                CLLocationCoordinate2D(latitude: 42.351763, longitude: -71.054797)
            ]
        ))

        // Greenbush Line (46 points from 251 original)
        routes.append(TransitRoute(
            id: "cr_greenbush",
            name: "Greenbush Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.178559, longitude: -70.746543),
                CLLocationCoordinate2D(latitude: 42.180853, longitude: -70.745634),
                CLLocationCoordinate2D(latitude: 42.187991, longitude: -70.739679),
                CLLocationCoordinate2D(latitude: 42.197671, longitude: -70.741321),
                CLLocationCoordinate2D(latitude: 42.201851, longitude: -70.744021),
                CLLocationCoordinate2D(latitude: 42.213566, longitude: -70.764897),
                CLLocationCoordinate2D(latitude: 42.216864, longitude: -70.773735),
                CLLocationCoordinate2D(latitude: 42.219049, longitude: -70.787209),
                CLLocationCoordinate2D(latitude: 42.220588, longitude: -70.790513),
                CLLocationCoordinate2D(latitude: 42.226358, longitude: -70.794474),
                CLLocationCoordinate2D(latitude: 42.235447, longitude: -70.799026),
                CLLocationCoordinate2D(latitude: 42.240878, longitude: -70.804589),
                CLLocationCoordinate2D(latitude: 42.245191, longitude: -70.847295),
                CLLocationCoordinate2D(latitude: 42.244877, longitude: -70.879095),
                CLLocationCoordinate2D(latitude: 42.241920, longitude: -70.890696),
                CLLocationCoordinate2D(latitude: 42.241658, longitude: -70.895360),
                CLLocationCoordinate2D(latitude: 42.239844, longitude: -70.900751),
                CLLocationCoordinate2D(latitude: 42.238539, longitude: -70.901905),
                CLLocationCoordinate2D(latitude: 42.231931, longitude: -70.904112),
                CLLocationCoordinate2D(latitude: 42.220633, longitude: -70.918406),
                CLLocationCoordinate2D(latitude: 42.219506, longitude: -70.921026),
                CLLocationCoordinate2D(latitude: 42.220114, longitude: -70.925393),
                CLLocationCoordinate2D(latitude: 42.223765, longitude: -70.931387),
                CLLocationCoordinate2D(latitude: 42.229186, longitude: -70.936458),
                CLLocationCoordinate2D(latitude: 42.230470, longitude: -70.939850),
                CLLocationCoordinate2D(latitude: 42.229898, longitude: -70.952204),
                CLLocationCoordinate2D(latitude: 42.222277, longitude: -70.966060),
                CLLocationCoordinate2D(latitude: 42.221337, longitude: -70.969102),
                CLLocationCoordinate2D(latitude: 42.222582, longitude: -70.990154),
                CLLocationCoordinate2D(latitude: 42.221769, longitude: -71.001399),
                CLLocationCoordinate2D(latitude: 42.228548, longitude: -71.006078),
                CLLocationCoordinate2D(latitude: 42.231588, longitude: -71.007106),
                CLLocationCoordinate2D(latitude: 42.247435, longitude: -71.003515),
                CLLocationCoordinate2D(latitude: 42.251977, longitude: -71.005490),
                CLLocationCoordinate2D(latitude: 42.276969, longitude: -71.031471),
                CLLocationCoordinate2D(latitude: 42.281689, longitude: -71.034041),
                CLLocationCoordinate2D(latitude: 42.299469, longitude: -71.054062),
                CLLocationCoordinate2D(latitude: 42.301604, longitude: -71.055436),
                CLLocationCoordinate2D(latitude: 42.308157, longitude: -71.054335),
                CLLocationCoordinate2D(latitude: 42.314145, longitude: -71.051994),
                CLLocationCoordinate2D(latitude: 42.319561, longitude: -71.052062),
                CLLocationCoordinate2D(latitude: 42.323120, longitude: -71.052803),
                CLLocationCoordinate2D(latitude: 42.327570, longitude: -71.058154),
                CLLocationCoordinate2D(latitude: 42.329266, longitude: -71.059136),
                CLLocationCoordinate2D(latitude: 42.341930, longitude: -71.059975),
                CLLocationCoordinate2D(latitude: 42.351610, longitude: -71.054973)
            ]
        ))

        // Haverhill Line (40 points from 791 original)
        routes.append(TransitRoute(
            id: "cr_haverhill",
            name: "Haverhill Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.773401, longitude: -71.086258),
                CLLocationCoordinate2D(latitude: 42.768117, longitude: -71.086948),
                CLLocationCoordinate2D(latitude: 42.753591, longitude: -71.107710),
                CLLocationCoordinate2D(latitude: 42.736930, longitude: -71.115837),
                CLLocationCoordinate2D(latitude: 42.721182, longitude: -71.132687),
                CLLocationCoordinate2D(latitude: 42.718397, longitude: -71.133480),
                CLLocationCoordinate2D(latitude: 42.711012, longitude: -71.131803),
                CLLocationCoordinate2D(latitude: 42.706893, longitude: -71.133788),
                CLLocationCoordinate2D(latitude: 42.703370, longitude: -71.138979),
                CLLocationCoordinate2D(latitude: 42.701191, longitude: -71.157342),
                CLLocationCoordinate2D(latitude: 42.700057, longitude: -71.160007),
                CLLocationCoordinate2D(latitude: 42.698114, longitude: -71.161562),
                CLLocationCoordinate2D(latitude: 42.695085, longitude: -71.161280),
                CLLocationCoordinate2D(latitude: 42.674270, longitude: -71.144526),
                CLLocationCoordinate2D(latitude: 42.671200, longitude: -71.142906),
                CLLocationCoordinate2D(latitude: 42.667132, longitude: -71.142020),
                CLLocationCoordinate2D(latitude: 42.655624, longitude: -71.145467),
                CLLocationCoordinate2D(latitude: 42.637746, longitude: -71.158304),
                CLLocationCoordinate2D(latitude: 42.633834, longitude: -71.160126),
                CLLocationCoordinate2D(latitude: 42.630701, longitude: -71.160497),
                CLLocationCoordinate2D(latitude: 42.624086, longitude: -71.159378),
                CLLocationCoordinate2D(latitude: 42.620855, longitude: -71.159580),
                CLLocationCoordinate2D(latitude: 42.610589, longitude: -71.163298),
                CLLocationCoordinate2D(latitude: 42.581884, longitude: -71.168123),
                CLLocationCoordinate2D(latitude: 42.579880, longitude: -71.167872),
                CLLocationCoordinate2D(latitude: 42.576711, longitude: -71.165950),
                CLLocationCoordinate2D(latitude: 42.535753, longitude: -71.130034),
                CLLocationCoordinate2D(latitude: 42.525323, longitude: -71.115378),
                CLLocationCoordinate2D(latitude: 42.512871, longitude: -71.087332),
                CLLocationCoordinate2D(latitude: 42.507474, longitude: -71.080255),
                CLLocationCoordinate2D(latitude: 42.495441, longitude: -71.070319),
                CLLocationCoordinate2D(latitude: 42.486799, longitude: -71.069127),
                CLLocationCoordinate2D(latitude: 42.478185, longitude: -71.065463),
                CLLocationCoordinate2D(latitude: 42.462821, longitude: -71.070460),
                CLLocationCoordinate2D(latitude: 42.454996, longitude: -71.069079),
                CLLocationCoordinate2D(latitude: 42.434422, longitude: -71.071247),
                CLLocationCoordinate2D(latitude: 42.419003, longitude: -71.076754),
                CLLocationCoordinate2D(latitude: 42.379464, longitude: -71.076939),
                CLLocationCoordinate2D(latitude: 42.376968, longitude: -71.075529),
                CLLocationCoordinate2D(latitude: 42.366431, longitude: -71.062503)
            ]
        ))

        // Kingston Line (42 points from 1016 original)
        routes.append(TransitRoute(
            id: "cr_kingston",
            name: "Kingston Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 41.976603, longitude: -70.723432),
                CLLocationCoordinate2D(latitude: 41.979165, longitude: -70.719715),
                CLLocationCoordinate2D(latitude: 41.983742, longitude: -70.717130),
                CLLocationCoordinate2D(latitude: 41.990691, longitude: -70.717667),
                CLLocationCoordinate2D(latitude: 41.993104, longitude: -70.718559),
                CLLocationCoordinate2D(latitude: 41.995767, longitude: -70.722452),
                CLLocationCoordinate2D(latitude: 41.997687, longitude: -70.727909),
                CLLocationCoordinate2D(latitude: 42.002180, longitude: -70.752088),
                CLLocationCoordinate2D(latitude: 42.002538, longitude: -70.776332),
                CLLocationCoordinate2D(latitude: 42.004447, longitude: -70.801128),
                CLLocationCoordinate2D(latitude: 42.005464, longitude: -70.805618),
                CLLocationCoordinate2D(latitude: 42.043485, longitude: -70.881612),
                CLLocationCoordinate2D(latitude: 42.058861, longitude: -70.903745),
                CLLocationCoordinate2D(latitude: 42.062320, longitude: -70.907609),
                CLLocationCoordinate2D(latitude: 42.087519, longitude: -70.926942),
                CLLocationCoordinate2D(latitude: 42.115907, longitude: -70.937683),
                CLLocationCoordinate2D(latitude: 42.142249, longitude: -70.945540),
                CLLocationCoordinate2D(latitude: 42.158181, longitude: -70.955214),
                CLLocationCoordinate2D(latitude: 42.170200, longitude: -70.959574),
                CLLocationCoordinate2D(latitude: 42.186070, longitude: -70.972279),
                CLLocationCoordinate2D(latitude: 42.187747, longitude: -70.975597),
                CLLocationCoordinate2D(latitude: 42.189375, longitude: -70.983333),
                CLLocationCoordinate2D(latitude: 42.193878, longitude: -70.988528),
                CLLocationCoordinate2D(latitude: 42.197403, longitude: -70.998924),
                CLLocationCoordinate2D(latitude: 42.200005, longitude: -71.001926),
                CLLocationCoordinate2D(latitude: 42.202457, longitude: -71.002317),
                CLLocationCoordinate2D(latitude: 42.218214, longitude: -70.999988),
                CLLocationCoordinate2D(latitude: 42.221100, longitude: -71.000991),
                CLLocationCoordinate2D(latitude: 42.229973, longitude: -71.007018),
                CLLocationCoordinate2D(latitude: 42.232957, longitude: -71.007114),
                CLLocationCoordinate2D(latitude: 42.247684, longitude: -71.003567),
                CLLocationCoordinate2D(latitude: 42.252776, longitude: -71.006123),
                CLLocationCoordinate2D(latitude: 42.276761, longitude: -71.031356),
                CLLocationCoordinate2D(latitude: 42.281342, longitude: -71.033878),
                CLLocationCoordinate2D(latitude: 42.301119, longitude: -71.055358),
                CLLocationCoordinate2D(latitude: 42.304070, longitude: -71.055461),
                CLLocationCoordinate2D(latitude: 42.314218, longitude: -71.052093),
                CLLocationCoordinate2D(latitude: 42.319682, longitude: -71.052038),
                CLLocationCoordinate2D(latitude: 42.323554, longitude: -71.053071),
                CLLocationCoordinate2D(latitude: 42.328929, longitude: -71.058935),
                CLLocationCoordinate2D(latitude: 42.341817, longitude: -71.060020),
                CLLocationCoordinate2D(latitude: 42.350441, longitude: -71.054969)
            ]
        ))

        // Lowell Line (30 points from 599 original)
        routes.append(TransitRoute(
            id: "cr_lowell",
            name: "Lowell Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.635299, longitude: -71.314461),
                CLLocationCoordinate2D(latitude: 42.629011, longitude: -71.309136),
                CLLocationCoordinate2D(latitude: 42.615648, longitude: -71.290139),
                CLLocationCoordinate2D(latitude: 42.593272, longitude: -71.280990),
                CLLocationCoordinate2D(latitude: 42.589959, longitude: -71.277862),
                CLLocationCoordinate2D(latitude: 42.587935, longitude: -71.271632),
                CLLocationCoordinate2D(latitude: 42.589118, longitude: -71.261456),
                CLLocationCoordinate2D(latitude: 42.588246, longitude: -71.255459),
                CLLocationCoordinate2D(latitude: 42.567699, longitude: -71.208648),
                CLLocationCoordinate2D(latitude: 42.561496, longitude: -71.192542),
                CLLocationCoordinate2D(latitude: 42.552526, longitude: -71.179402),
                CLLocationCoordinate2D(latitude: 42.545933, longitude: -71.173614),
                CLLocationCoordinate2D(latitude: 42.527888, longitude: -71.151828),
                CLLocationCoordinate2D(latitude: 42.509285, longitude: -71.139646),
                CLLocationCoordinate2D(latitude: 42.494902, longitude: -71.133605),
                CLLocationCoordinate2D(latitude: 42.488296, longitude: -71.132808),
                CLLocationCoordinate2D(latitude: 42.482111, longitude: -71.127572),
                CLLocationCoordinate2D(latitude: 42.478538, longitude: -71.126401),
                CLLocationCoordinate2D(latitude: 42.475469, longitude: -71.126914),
                CLLocationCoordinate2D(latitude: 42.466960, longitude: -71.131739),
                CLLocationCoordinate2D(latitude: 42.444890, longitude: -71.140103),
                CLLocationCoordinate2D(latitude: 42.438716, longitude: -71.144923),
                CLLocationCoordinate2D(latitude: 42.435639, longitude: -71.145318),
                CLLocationCoordinate2D(latitude: 42.431451, longitude: -71.144328),
                CLLocationCoordinate2D(latitude: 42.409136, longitude: -71.117895),
                CLLocationCoordinate2D(latitude: 42.391829, longitude: -71.104478),
                CLLocationCoordinate2D(latitude: 42.386420, longitude: -71.093416),
                CLLocationCoordinate2D(latitude: 42.378965, longitude: -71.085077),
                CLLocationCoordinate2D(latitude: 42.378150, longitude: -71.077358),
                CLLocationCoordinate2D(latitude: 42.366431, longitude: -71.062503)
            ]
        ))

        // Needham Line (21 points from 408 original)
        routes.append(TransitRoute(
            id: "cr_needham",
            name: "Needham Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.293080, longitude: -71.236060),
                CLLocationCoordinate2D(latitude: 42.286150, longitude: -71.236356),
                CLLocationCoordinate2D(latitude: 42.274309, longitude: -71.239371),
                CLLocationCoordinate2D(latitude: 42.272869, longitude: -71.237825),
                CLLocationCoordinate2D(latitude: 42.280350, longitude: -71.178163),
                CLLocationCoordinate2D(latitude: 42.279049, longitude: -71.164001),
                CLLocationCoordinate2D(latitude: 42.286036, longitude: -71.151090),
                CLLocationCoordinate2D(latitude: 42.287265, longitude: -71.130755),
                CLLocationCoordinate2D(latitude: 42.289036, longitude: -71.127301),
                CLLocationCoordinate2D(latitude: 42.294961, longitude: -71.121589),
                CLLocationCoordinate2D(latitude: 42.299212, longitude: -71.114800),
                CLLocationCoordinate2D(latitude: 42.310588, longitude: -71.107250),
                CLLocationCoordinate2D(latitude: 42.318201, longitude: -71.103504),
                CLLocationCoordinate2D(latitude: 42.322745, longitude: -71.099932),
                CLLocationCoordinate2D(latitude: 42.328900, longitude: -71.097116),
                CLLocationCoordinate2D(latitude: 42.333345, longitude: -71.093002),
                CLLocationCoordinate2D(latitude: 42.347236, longitude: -71.076065),
                CLLocationCoordinate2D(latitude: 42.347493, longitude: -71.069002),
                CLLocationCoordinate2D(latitude: 42.345852, longitude: -71.059053),
                CLLocationCoordinate2D(latitude: 42.346792, longitude: -71.057534),
                CLLocationCoordinate2D(latitude: 42.351763, longitude: -71.054797)
            ]
        ))

        // Newburyport/Rockport Line (35 points from 650 original)
        routes.append(TransitRoute(
            id: "cr_newburyport",
            name: "Newburyport/Rockport Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.798203, longitude: -70.878224),
                CLLocationCoordinate2D(latitude: 42.694300, longitude: -70.850293),
                CLLocationCoordinate2D(latitude: 42.686121, longitude: -70.846551),
                CLLocationCoordinate2D(latitude: 42.679326, longitude: -70.841110),
                CLLocationCoordinate2D(latitude: 42.677357, longitude: -70.840520),
                CLLocationCoordinate2D(latitude: 42.658700, longitude: -70.849740),
                CLLocationCoordinate2D(latitude: 42.648394, longitude: -70.853181),
                CLLocationCoordinate2D(latitude: 42.620245, longitude: -70.870369),
                CLLocationCoordinate2D(latitude: 42.591783, longitude: -70.881603),
                CLLocationCoordinate2D(latitude: 42.581655, longitude: -70.884161),
                CLLocationCoordinate2D(latitude: 42.572514, longitude: -70.885278),
                CLLocationCoordinate2D(latitude: 42.547405, longitude: -70.885365),
                CLLocationCoordinate2D(latitude: 42.533181, longitude: -70.890157),
                CLLocationCoordinate2D(latitude: 42.524636, longitude: -70.895852),
                CLLocationCoordinate2D(latitude: 42.518015, longitude: -70.895869),
                CLLocationCoordinate2D(latitude: 42.509106, longitude: -70.897188),
                CLLocationCoordinate2D(latitude: 42.494768, longitude: -70.906214),
                CLLocationCoordinate2D(latitude: 42.487182, longitude: -70.912755),
                CLLocationCoordinate2D(latitude: 42.480536, longitude: -70.915518),
                CLLocationCoordinate2D(latitude: 42.476966, longitude: -70.918197),
                CLLocationCoordinate2D(latitude: 42.473089, longitude: -70.923620),
                CLLocationCoordinate2D(latitude: 42.455273, longitude: -70.961805),
                CLLocationCoordinate2D(latitude: 42.451122, longitude: -70.968317),
                CLLocationCoordinate2D(latitude: 42.408056, longitude: -71.001428),
                CLLocationCoordinate2D(latitude: 42.404811, longitude: -71.004609),
                CLLocationCoordinate2D(latitude: 42.395744, longitude: -71.023602),
                CLLocationCoordinate2D(latitude: 42.394707, longitude: -71.027518),
                CLLocationCoordinate2D(latitude: 42.398457, longitude: -71.048479),
                CLLocationCoordinate2D(latitude: 42.401479, longitude: -71.057266),
                CLLocationCoordinate2D(latitude: 42.401460, longitude: -71.061975),
                CLLocationCoordinate2D(latitude: 42.400169, longitude: -71.065354),
                CLLocationCoordinate2D(latitude: 42.392370, longitude: -71.075231),
                CLLocationCoordinate2D(latitude: 42.389376, longitude: -71.076720),
                CLLocationCoordinate2D(latitude: 42.377974, longitude: -71.076372),
                CLLocationCoordinate2D(latitude: 42.366468, longitude: -71.062539)
            ]
        ))

        // Providence/Stoughton Line (21 points from 475 original)
        routes.append(TransitRoute(
            id: "cr_providence",
            name: "Providence/Stoughton Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.124061, longitude: -71.103676),
                CLLocationCoordinate2D(latitude: 42.130801, longitude: -71.119033),
                CLLocationCoordinate2D(latitude: 42.133434, longitude: -71.121315),
                CLLocationCoordinate2D(latitude: 42.144601, longitude: -71.126019),
                CLLocationCoordinate2D(latitude: 42.147730, longitude: -71.128776),
                CLLocationCoordinate2D(latitude: 42.157377, longitude: -71.146780),
                CLLocationCoordinate2D(latitude: 42.162619, longitude: -71.153725),
                CLLocationCoordinate2D(latitude: 42.192007, longitude: -71.155484),
                CLLocationCoordinate2D(latitude: 42.256555, longitude: -71.124867),
                CLLocationCoordinate2D(latitude: 42.280221, longitude: -71.119680),
                CLLocationCoordinate2D(latitude: 42.293018, longitude: -71.118549),
                CLLocationCoordinate2D(latitude: 42.310588, longitude: -71.107250),
                CLLocationCoordinate2D(latitude: 42.318201, longitude: -71.103504),
                CLLocationCoordinate2D(latitude: 42.322745, longitude: -71.099932),
                CLLocationCoordinate2D(latitude: 42.328900, longitude: -71.097116),
                CLLocationCoordinate2D(latitude: 42.333345, longitude: -71.093002),
                CLLocationCoordinate2D(latitude: 42.347236, longitude: -71.076065),
                CLLocationCoordinate2D(latitude: 42.347493, longitude: -71.069002),
                CLLocationCoordinate2D(latitude: 42.345852, longitude: -71.059053),
                CLLocationCoordinate2D(latitude: 42.346792, longitude: -71.057534),
                CLLocationCoordinate2D(latitude: 42.351763, longitude: -71.054797)
            ]
        ))

        // Worcester Line (64 points from 1209 original)
        routes.append(TransitRoute(
            id: "cr_worcester",
            name: "Worcester Line",
            line: .commuterRail,
            coordinates: [
                CLLocationCoordinate2D(latitude: 42.260890, longitude: -71.794941),
                CLLocationCoordinate2D(latitude: 42.263431, longitude: -71.785139),
                CLLocationCoordinate2D(latitude: 42.271624, longitude: -71.773110),
                CLLocationCoordinate2D(latitude: 42.272049, longitude: -71.769862),
                CLLocationCoordinate2D(latitude: 42.271226, longitude: -71.766355),
                CLLocationCoordinate2D(latitude: 42.268499, longitude: -71.763789),
                CLLocationCoordinate2D(latitude: 42.263533, longitude: -71.763840),
                CLLocationCoordinate2D(latitude: 42.260558, longitude: -71.762500),
                CLLocationCoordinate2D(latitude: 42.255204, longitude: -71.755752),
                CLLocationCoordinate2D(latitude: 42.250000, longitude: -71.751400),
                CLLocationCoordinate2D(latitude: 42.240266, longitude: -71.749478),
                CLLocationCoordinate2D(latitude: 42.237605, longitude: -71.748152),
                CLLocationCoordinate2D(latitude: 42.234778, longitude: -71.745125),
                CLLocationCoordinate2D(latitude: 42.233780, longitude: -71.741230),
                CLLocationCoordinate2D(latitude: 42.235950, longitude: -71.722965),
                CLLocationCoordinate2D(latitude: 42.242529, longitude: -71.706060),
                CLLocationCoordinate2D(latitude: 42.244915, longitude: -71.688332),
                CLLocationCoordinate2D(latitude: 42.248680, longitude: -71.682129),
                CLLocationCoordinate2D(latitude: 42.251069, longitude: -71.674372),
                CLLocationCoordinate2D(latitude: 42.257070, longitude: -71.665020),
                CLLocationCoordinate2D(latitude: 42.265797, longitude: -71.655434),
                CLLocationCoordinate2D(latitude: 42.268083, longitude: -71.651825),
                CLLocationCoordinate2D(latitude: 42.274416, longitude: -71.623713),
                CLLocationCoordinate2D(latitude: 42.273845, longitude: -71.615212),
                CLLocationCoordinate2D(latitude: 42.269583, longitude: -71.603122),
                CLLocationCoordinate2D(latitude: 42.268576, longitude: -71.557883),
                CLLocationCoordinate2D(latitude: 42.265599, longitude: -71.544950),
                CLLocationCoordinate2D(latitude: 42.268088, longitude: -71.516117),
                CLLocationCoordinate2D(latitude: 42.265755, longitude: -71.507720),
                CLLocationCoordinate2D(latitude: 42.265296, longitude: -71.499058),
                CLLocationCoordinate2D(latitude: 42.262131, longitude: -71.489288),
                CLLocationCoordinate2D(latitude: 42.259774, longitude: -71.457172),
                CLLocationCoordinate2D(latitude: 42.262214, longitude: -71.450804),
                CLLocationCoordinate2D(latitude: 42.271844, longitude: -71.443473),
                CLLocationCoordinate2D(latitude: 42.274295, longitude: -71.438465),
                CLLocationCoordinate2D(latitude: 42.275322, longitude: -71.423073),
                CLLocationCoordinate2D(latitude: 42.283156, longitude: -71.392041),
                CLLocationCoordinate2D(latitude: 42.284070, longitude: -71.369862),
                CLLocationCoordinate2D(latitude: 42.283615, longitude: -71.359615),
                CLLocationCoordinate2D(latitude: 42.287282, longitude: -71.337888),
                CLLocationCoordinate2D(latitude: 42.293912, longitude: -71.321835),
                CLLocationCoordinate2D(latitude: 42.295893, longitude: -71.314989),
                CLLocationCoordinate2D(latitude: 42.296331, longitude: -71.298682),
                CLLocationCoordinate2D(latitude: 42.297513, longitude: -71.294086),
                CLLocationCoordinate2D(latitude: 42.310185, longitude: -71.277269),
                CLLocationCoordinate2D(latitude: 42.323566, longitude: -71.272073),
                CLLocationCoordinate2D(latitude: 42.330415, longitude: -71.272466),
                CLLocationCoordinate2D(latitude: 42.333706, longitude: -71.270986),
                CLLocationCoordinate2D(latitude: 42.335750, longitude: -71.268219),
                CLLocationCoordinate2D(latitude: 42.338958, longitude: -71.259284),
                CLLocationCoordinate2D(latitude: 42.344248, longitude: -71.253635),
                CLLocationCoordinate2D(latitude: 42.346034, longitude: -71.249666),
                CLLocationCoordinate2D(latitude: 42.348546, longitude: -71.223492),
                CLLocationCoordinate2D(latitude: 42.351538, longitude: -71.206193),
                CLLocationCoordinate2D(latitude: 42.358101, longitude: -71.178005),
                CLLocationCoordinate2D(latitude: 42.357111, longitude: -71.169834),
                CLLocationCoordinate2D(latitude: 42.358010, longitude: -71.145119),
                CLLocationCoordinate2D(latitude: 42.354391, longitude: -71.119703),
                CLLocationCoordinate2D(latitude: 42.349142, longitude: -71.108454),
                CLLocationCoordinate2D(latitude: 42.347606, longitude: -71.100046),
                CLLocationCoordinate2D(latitude: 42.347690, longitude: -71.068907),
                CLLocationCoordinate2D(latitude: 42.345913, longitude: -71.059223),
                CLLocationCoordinate2D(latitude: 42.346916, longitude: -71.057502),
                CLLocationCoordinate2D(latitude: 42.351502, longitude: -71.055086)
            ]
        ))

        return routes
    }
}
