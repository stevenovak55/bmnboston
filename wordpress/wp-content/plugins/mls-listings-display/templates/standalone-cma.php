<?php
/**
 * Standalone CMA - View Template
 *
 * Displays an existing standalone CMA with the property details,
 * map, CMA section, and market analytics.
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 6.17.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get the CMA session data
$cma_session = get_query_var('cma_session');

if (!$cma_session) {
    // This shouldn't happen if routing is working correctly
    wp_die('CMA session not found', 'Error', array('response' => 404));
}

$subject_data = $cma_session['subject_property_data'];
$is_owner = is_user_logged_in() && get_current_user_id() == $cma_session['user_id'];
$is_anonymous = absint($cma_session['user_id']) === 0;

// Extract property details
$address = $subject_data['address'] ?? 'Unknown Address';
$city = $subject_data['city'] ?? '';
$state = $subject_data['state'] ?? 'MA';
$lat = floatval($subject_data['lat'] ?? 0);
$lng = floatval($subject_data['lng'] ?? 0);
$beds = intval($subject_data['beds'] ?? 0);
$baths = floatval($subject_data['baths'] ?? 0);
$sqft = intval($subject_data['sqft'] ?? 0);
$year_built = intval($subject_data['year_built'] ?? 0);
$price = floatval($subject_data['price'] ?? 0);
$property_type = $subject_data['property_type'] ?? 'Single Family Residence';
$garage_spaces = intval($subject_data['garage_spaces'] ?? 0);
$pool = !empty($subject_data['pool']);
$waterfront = !empty($subject_data['waterfront']);
$road_type = $subject_data['road_type'] ?? 'unknown';
$property_condition = $subject_data['property_condition'] ?? 'unknown';

// Get Google Maps API key using proper method
$google_maps_key = '';
if (class_exists('MLD_Settings')) {
    $google_maps_key = MLD_Settings::get_google_maps_api_key();
}

get_header();
?>

<div class="mld-standalone-cma-wrapper mld-standalone-cma-view-page">

    <!-- Header Section -->
    <header class="mld-scma-view-header">
        <div class="mld-scma-view-header-content">
            <div class="mld-scma-badge-row">
                <span class="mld-scma-type-badge">Standalone CMA</span>
                <?php if ($is_owner) : ?>
                    <span class="mld-scma-owner-badge">Your CMA</span>
                <?php endif; ?>
            </div>

            <h1 class="mld-scma-address"><?php echo esc_html($address); ?></h1>
            <p class="mld-scma-location"><?php echo esc_html($city . ', ' . $state); ?></p>

            <?php if ($is_owner) : ?>
            <div class="mld-scma-header-actions">
                <button id="scma-edit-btn" class="mld-scma-btn mld-scma-btn-small">Edit Details</button>
                <button id="scma-share-btn" class="mld-scma-btn mld-scma-btn-small mld-scma-btn-secondary">Share</button>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Property Summary Card -->
    <section class="mld-scma-summary-section">
        <div class="mld-scma-summary-card">
            <div class="mld-scma-stats-grid">
                <div class="mld-scma-stat">
                    <span class="stat-value"><?php echo esc_html($beds); ?></span>
                    <span class="stat-label">Beds</span>
                </div>
                <div class="mld-scma-stat">
                    <span class="stat-value"><?php echo esc_html($baths); ?></span>
                    <span class="stat-label">Baths</span>
                </div>
                <div class="mld-scma-stat">
                    <span class="stat-value"><?php echo number_format($sqft); ?></span>
                    <span class="stat-label">Sq Ft</span>
                </div>
                <?php if ($year_built > 0) : ?>
                <div class="mld-scma-stat">
                    <span class="stat-value"><?php echo esc_html($year_built); ?></span>
                    <span class="stat-label">Year Built</span>
                </div>
                <?php endif; ?>
                <div class="mld-scma-stat mld-scma-stat-price">
                    <span class="stat-value">$<?php echo number_format($price); ?></span>
                    <span class="stat-label">Est. Value</span>
                </div>
            </div>

            <!-- Additional Details -->
            <div class="mld-scma-details-row">
                <span class="mld-scma-detail">
                    <strong>Type:</strong> <?php echo esc_html($property_type); ?>
                </span>
                <?php if ($garage_spaces > 0) : ?>
                <span class="mld-scma-detail">
                    <strong>Garage:</strong> <?php echo esc_html($garage_spaces); ?> spaces
                </span>
                <?php endif; ?>
                <?php if ($pool) : ?>
                <span class="mld-scma-detail mld-scma-detail-feature">Pool</span>
                <?php endif; ?>
                <?php if ($waterfront) : ?>
                <span class="mld-scma-detail mld-scma-detail-feature">Waterfront</span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Claim Banner for Anonymous CMAs -->
    <?php if ($is_anonymous && is_user_logged_in()) : ?>
    <div class="mld-scma-claim-banner">
        <div class="claim-content">
            <strong>This CMA is not saved to any account.</strong>
            <p>Would you like to save it to your account?</p>
        </div>
        <button id="scma-claim-btn" class="mld-scma-btn mld-scma-btn-primary"
                data-session-id="<?php echo esc_attr($cma_session['id']); ?>">
            Save to My CMAs
        </button>
    </div>
    <?php elseif ($is_anonymous && !is_user_logged_in()) : ?>
    <div class="mld-scma-claim-banner mld-scma-login-banner">
        <div class="claim-content">
            <strong>Want to save this CMA?</strong>
            <p>Log in to save this CMA to your account for easy access later.</p>
        </div>
        <?php
        $current_url = home_url('/cma/' . $cma_session['standalone_slug'] . '/');
        $login_url = wp_login_url($current_url);
        ?>
        <a href="<?php echo esc_url($login_url); ?>" class="mld-scma-btn mld-scma-btn-primary">Log in to Save</a>
    </div>
    <?php endif; ?>

    <!-- Map Section -->
    <?php if ($lat != 0 && $lng != 0 && !empty($google_maps_key)) : ?>
    <section class="mld-scma-map-section">
        <h2>Location</h2>
        <div id="mld-scma-map"
             class="mld-scma-map"
             data-lat="<?php echo esc_attr($lat); ?>"
             data-lng="<?php echo esc_attr($lng); ?>"
             data-address="<?php echo esc_attr($address); ?>">
        </div>
    </section>
    <?php endif; ?>

    <!-- CMA Section (Main Feature) -->
    <section class="mld-scma-cma-section">
        <h2>Comparative Market Analysis</h2>
        <?php
        // Prepare subject property for the CMA component
        // This matches the format expected by mld_render_comparable_sales()
        $cma_subject = array(
            'mlsNumber' => $cma_session['subject_listing_id'],
            'lat' => $lat,
            'lng' => $lng,
            'price' => $price,
            'beds' => $beds,
            'baths' => $baths,
            'sqft' => $sqft,
            'propertyType' => $property_type,
            'yearBuilt' => $year_built,
            'garageSpaces' => $garage_spaces,
            'pool' => $pool,
            'waterfront' => $waterfront,
            'roadType' => $road_type,
            'propertyCondition' => $property_condition,
            'city' => $city,
            'state' => $state
        );

        // Include the comparable sales display with preloaded session data (v6.20.2)
        // This allows standalone CMAs to restore their saved comparables and settings
        if (function_exists('mld_render_comparable_sales')) {
            mld_render_comparable_sales($cma_subject, $cma_session);
        } else {
            // Fallback: try to include the file
            $cma_display_file = MLD_PLUGIN_PATH . 'includes/mld-comparable-sales-display.php';
            if (file_exists($cma_display_file)) {
                require_once $cma_display_file;
                if (function_exists('mld_render_comparable_sales')) {
                    mld_render_comparable_sales($cma_subject, $cma_session);
                }
            } else {
                echo '<p class="mld-scma-error">CMA component not available.</p>';
            }
        }
        ?>
    </section>

    <!-- Market Analytics Section -->
    <?php if (!empty($city)) : ?>
    <section class="mld-scma-analytics-section">
        <h2>Market Analytics - <?php echo esc_html($city); ?></h2>
        <?php
        if (class_exists('MLD_Analytics_Tabs')) {
            echo MLD_Analytics_Tabs::render_property_section(
                $city,
                $state,
                $property_type
            );
        } else {
            // Try to include the file
            $analytics_file = MLD_PLUGIN_PATH . 'includes/class-mld-analytics-tabs.php';
            if (file_exists($analytics_file)) {
                require_once $analytics_file;
                if (class_exists('MLD_Analytics_Tabs')) {
                    echo MLD_Analytics_Tabs::render_property_section($city, $state, $property_type);
                }
            }
        }
        ?>
    </section>
    <?php endif; ?>

    <!-- CMA Info Footer -->
    <footer class="mld-scma-footer">
        <div class="mld-scma-meta">
            <span>Created: <?php echo esc_html(date('M j, Y', strtotime($cma_session['created_at']))); ?></span>
            <?php if ($cma_session['updated_at'] !== $cma_session['created_at']) : ?>
            <span>Updated: <?php echo esc_html(date('M j, Y', strtotime($cma_session['updated_at']))); ?></span>
            <?php endif; ?>
        </div>
        <div class="mld-scma-share-url">
            <label>Share this CMA:</label>
            <input type="text" readonly value="<?php echo esc_url(home_url('/cma/' . $cma_session['standalone_slug'] . '/')); ?>"
                   id="scma-share-url" onclick="this.select();">
            <button type="button" id="scma-copy-url" class="mld-scma-btn mld-scma-btn-small">Copy</button>
        </div>
    </footer>

</div>

<!-- Edit Modal (for owners) -->
<?php if ($is_owner) : ?>
<div id="mld-scma-edit-modal" class="mld-scma-modal" style="display: none;">
    <div class="mld-scma-modal-overlay"></div>
    <div class="mld-scma-modal-content">
        <div class="mld-scma-modal-header">
            <h3>Edit CMA Details</h3>
            <button type="button" class="mld-scma-modal-close">&times;</button>
        </div>
        <div class="mld-scma-modal-body">
            <form id="mld-scma-edit-form">
                <input type="hidden" name="session_id" value="<?php echo esc_attr($cma_session['id']); ?>">

                <div class="mld-scma-field-group">
                    <label for="edit-session-name">CMA Name</label>
                    <input type="text" id="edit-session-name" name="session_name"
                           value="<?php echo esc_attr($cma_session['session_name']); ?>" class="mld-scma-input">
                </div>

                <div class="mld-scma-field-group">
                    <label for="edit-description">Notes / Description</label>
                    <textarea id="edit-description" name="description" rows="3"
                              class="mld-scma-textarea"><?php echo esc_textarea($cma_session['description']); ?></textarea>
                </div>

                <div class="mld-scma-modal-actions">
                    <button type="submit" class="mld-scma-btn mld-scma-btn-primary">Save Changes</button>
                    <button type="button" class="mld-scma-btn mld-scma-btn-secondary mld-scma-modal-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Share Modal -->
<div id="mld-scma-share-modal" class="mld-scma-modal" style="display: none;">
    <div class="mld-scma-modal-overlay"></div>
    <div class="mld-scma-modal-content mld-scma-modal-small">
        <div class="mld-scma-modal-header">
            <h3>Share CMA</h3>
            <button type="button" class="mld-scma-modal-close">&times;</button>
        </div>
        <div class="mld-scma-modal-body">
            <p>Share this CMA using the URL below:</p>
            <div class="mld-scma-share-input-wrapper">
                <input type="text" readonly value="<?php echo esc_url(home_url('/cma/' . $cma_session['standalone_slug'] . '/')); ?>"
                       id="scma-share-modal-url" class="mld-scma-input">
                <button type="button" id="scma-share-modal-copy" class="mld-scma-btn mld-scma-btn-primary">Copy</button>
            </div>
            <p class="share-note">Anyone with this link can view this CMA.</p>
        </div>
    </div>
</div>

<script>
// Initialize map - handles async Google Maps loading
function initStandaloneCMAMap() {
    var mapElement = document.getElementById('mld-scma-map');
    if (!mapElement) {
        console.log('[Standalone CMA] No map element found');
        return;
    }
    if (typeof google === 'undefined' || !google.maps) {
        console.log('[Standalone CMA] Google Maps not loaded yet');
        return;
    }
    if (mapElement.dataset.initialized === 'true') {
        console.log('[Standalone CMA] Map already initialized');
        return;
    }

    var lat = parseFloat(mapElement.dataset.lat);
    var lng = parseFloat(mapElement.dataset.lng);
    var address = mapElement.dataset.address;

    console.log('[Standalone CMA] Initializing map at:', lat, lng);

    if (lat && lng) {
        var map = new google.maps.Map(mapElement, {
            center: { lat: lat, lng: lng },
            zoom: 15,
            mapTypeControl: true,
            streetViewControl: true,
            fullscreenControl: true
        });

        new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: map,
            title: address
        });

        mapElement.dataset.initialized = 'true';
        console.log('[Standalone CMA] Map initialized successfully');
    }
}

// Try to init map on DOMContentLoaded (if Google Maps already loaded)
document.addEventListener('DOMContentLoaded', function() {
    initStandaloneCMAMap();

    // Copy URL functionality
    var copyButtons = document.querySelectorAll('#scma-copy-url, #scma-share-modal-copy');
    copyButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = this.previousElementSibling;
            input.select();
            document.execCommand('copy');
            var originalText = this.textContent;
            this.textContent = 'Copied!';
            setTimeout(function() {
                btn.textContent = originalText;
            }, 2000);
        });
    });

    // Modal functionality
    var editBtn = document.getElementById('scma-edit-btn');
    var shareBtn = document.getElementById('scma-share-btn');
    var editModal = document.getElementById('mld-scma-edit-modal');
    var shareModal = document.getElementById('mld-scma-share-modal');

    if (editBtn && editModal) {
        editBtn.addEventListener('click', function() {
            editModal.style.display = 'flex';
        });
    }

    if (shareBtn && shareModal) {
        shareBtn.addEventListener('click', function() {
            shareModal.style.display = 'flex';
        });
    }

    // Close modals
    document.querySelectorAll('.mld-scma-modal-close, .mld-scma-modal-overlay, .mld-scma-modal-cancel').forEach(function(el) {
        el.addEventListener('click', function() {
            document.querySelectorAll('.mld-scma-modal').forEach(function(modal) {
                modal.style.display = 'none';
            });
        });
    });
});

// Also listen for Google Maps ready event (fires when async load completes)
document.addEventListener('googleMapsReady', function() {
    console.log('[Standalone CMA] Google Maps ready event received');
    initStandaloneCMAMap();
});

// Fallback: try again after a short delay in case events were missed
setTimeout(function() {
    initStandaloneCMAMap();
}, 1500);
</script>

<?php get_footer(); ?>
