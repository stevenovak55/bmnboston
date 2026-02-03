<?php

if (!defined('ABSPATH')) {
    exit;
}

class BME_Property_Comparison {
    
    private $db_manager;
    private $cache_manager;
    private $max_comparisons = 4;
    
    public function __construct($db_manager, $cache_manager) {
        $this->db_manager = $db_manager;
        $this->cache_manager = $cache_manager;
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_ajax_bme_add_to_comparison', [$this, 'ajax_add_to_comparison']);
        add_action('wp_ajax_bme_remove_from_comparison', [$this, 'ajax_remove_from_comparison']);
        add_action('wp_ajax_bme_get_comparison_data', [$this, 'ajax_get_comparison_data']);
        add_action('wp_ajax_bme_clear_comparison', [$this, 'ajax_clear_comparison']);

        // Add support for non-logged-in users
        add_action('wp_ajax_nopriv_bme_add_to_comparison', [$this, 'ajax_add_to_comparison']);
        add_action('wp_ajax_nopriv_bme_remove_from_comparison', [$this, 'ajax_remove_from_comparison']);
        add_action('wp_ajax_nopriv_bme_get_comparison_data', [$this, 'ajax_get_comparison_data']);
        add_action('wp_ajax_nopriv_bme_clear_comparison', [$this, 'ajax_clear_comparison']);

        add_shortcode('bme_property_comparison', [$this, 'render_comparison_shortcode']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script(
            'bme-property-comparison',
            plugin_dir_url(__FILE__) . '../assets/js/property-comparison.js',
            ['jquery'],
            BME_PRO_VERSION,
            true
        );
        
        wp_enqueue_style(
            'bme-property-comparison',
            plugin_dir_url(__FILE__) . '../assets/css/property-comparison.css',
            [],
            BME_PRO_VERSION
        );
        
        wp_localize_script('bme-property-comparison', 'bme_comparison_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bme_comparison_nonce'),
            'max_comparisons' => $this->max_comparisons,
            'messages' => [
                'max_reached' => sprintf('You can compare up to %d properties at once.', $this->max_comparisons),
                'added_to_comparison' => 'Property added to comparison!',
                'removed_from_comparison' => 'Property removed from comparison.',
                'clear_confirmation' => 'Are you sure you want to clear all properties from comparison?'
            ]
        ]);
    }
    
    public function get_comparison_session_key() {
        if (is_user_logged_in()) {
            return 'bme_comparison_' . get_current_user_id();
        } else {
            if (!session_id()) {
                session_start();
            }
            return 'bme_comparison_' . session_id();
        }
    }
    
    public function add_to_comparison($property_id) {
        $session_key = $this->get_comparison_session_key();
        $comparison_data = $this->get_comparison_data();
        
        if (count($comparison_data) >= $this->max_comparisons) {
            throw new Exception(sprintf('Maximum of %d properties can be compared at once.', $this->max_comparisons));
        }
        
        if (in_array($property_id, $comparison_data)) {
            return true; // Already in comparison
        }
        
        $comparison_data[] = intval($property_id);
        
        return $this->cache_manager->set($session_key, $comparison_data, 3600);
    }
    
    public function remove_from_comparison($property_id) {
        $session_key = $this->get_comparison_session_key();
        $comparison_data = $this->get_comparison_data();
        
        $key = array_search(intval($property_id), $comparison_data);
        if ($key !== false) {
            unset($comparison_data[$key]);
            $comparison_data = array_values($comparison_data); // Reindex array
        }
        
        return $this->cache_manager->set($session_key, $comparison_data, 3600);
    }
    
    public function get_comparison_data() {
        $session_key = $this->get_comparison_session_key();
        $comparison_data = $this->cache_manager->get($session_key);
        
        return $comparison_data !== false ? $comparison_data : [];
    }
    
    public function clear_comparison() {
        $session_key = $this->get_comparison_session_key();
        return $this->cache_manager->delete($session_key);
    }
    
    public function get_comparison_properties() {
        $property_ids = $this->get_comparison_data();
        
        if (empty($property_ids)) {
            return [];
        }
        
        $cache_key = 'bme_comparison_properties_' . md5(implode(',', $property_ids));
        $cached = $this->cache_manager->get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        
        $listings_table = $this->db_manager->get_table('listings');
        $details_table = $this->db_manager->get_table('listing_details');
        $location_table = $this->db_manager->get_table('listing_location');
        $financial_table = $this->db_manager->get_table('listing_financial');
        $features_table = $this->db_manager->get_table('listing_features');
        $media_table = $this->db_manager->get_table('media');
        
        $placeholders = implode(',', array_fill(0, count($property_ids), '%d'));
        
        $query = "
            SELECT 
                l.id, l.mls_id, l.status, l.property_type, l.last_updated,
                d.address, d.city, d.state, d.zip_code, d.bedrooms, d.bathrooms, 
                d.sqft_total, d.lot_size, d.year_built, d.description,
                loc.latitude, loc.longitude, loc.neighborhood, loc.school_district,
                f.list_price, f.price_per_sqft, f.hoa_fees, f.property_taxes, 
                d.mlspin_market_time_property as days_on_market,
                feat.heating, feat.cooling, feat.parking, feat.basement, 
                feat.pool, feat.fireplace, feat.garage, feat.appliances,
                GROUP_CONCAT(m.file_path ORDER BY m.display_order) as images
            FROM {$listings_table} l
            LEFT JOIN {$details_table} d ON l.listing_id = d.listing_id
            LEFT JOIN {$location_table} loc ON l.listing_id = loc.listing_id  
            LEFT JOIN {$financial_table} f ON l.listing_id = f.listing_id
            LEFT JOIN {$features_table} feat ON l.listing_id = feat.listing_id
            LEFT JOIN {$media_table} m ON l.listing_id = m.listing_id AND m.media_type = 'image'
            WHERE l.listing_id IN ({$placeholders})
            GROUP BY l.listing_id
            ORDER BY FIELD(l.listing_id, {$placeholders})
        ";
        
        $all_params = array_merge($property_ids, $property_ids);
        $properties = $wpdb->get_results($wpdb->prepare($query, $all_params));
        
        foreach ($properties as &$property) {
            if ($property->images) {
                $property->images = explode(',', $property->images);
            } else {
                $property->images = [];
            }
            
            // Parse appliances if it's JSON
            if ($property->appliances && is_string($property->appliances)) {
                $property->appliances = json_decode($property->appliances, true) ?: [];
            }
        }
        
        $this->cache_manager->set($cache_key, $properties, 1800);
        
        return $properties;
    }
    
    public function is_in_comparison($property_id) {
        $comparison_data = $this->get_comparison_data();
        return in_array(intval($property_id), $comparison_data);
    }
    
    public function get_comparison_count() {
        return count($this->get_comparison_data());
    }
    
    public function ajax_add_to_comparison() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_comparison_nonce')) {
            wp_die('Security check failed');
        }
        
        $property_id = intval($_POST['property_id']);
        
        try {
            $this->add_to_comparison($property_id);
            wp_send_json_success([
                'count' => $this->get_comparison_count(),
                'message' => 'Property added to comparison!'
            ]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_remove_from_comparison() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_comparison_nonce')) {
            wp_die('Security check failed');
        }
        
        $property_id = intval($_POST['property_id']);
        
        $this->remove_from_comparison($property_id);
        wp_send_json_success([
            'count' => $this->get_comparison_count(),
            'message' => 'Property removed from comparison.'
        ]);
    }
    
    public function ajax_get_comparison_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_comparison_nonce')) {
            wp_die('Security check failed');
        }
        
        $properties = $this->get_comparison_properties();
        wp_send_json_success([
            'properties' => $properties,
            'count' => count($properties)
        ]);
    }
    
    public function ajax_clear_comparison() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_comparison_nonce')) {
            wp_die('Security check failed');
        }
        
        $this->clear_comparison();
        wp_send_json_success(['message' => 'Comparison cleared.']);
    }
    
    public function render_comparison_shortcode($atts) {
        $atts = shortcode_atts([
            'show_floating_widget' => true,
            'widget_position' => 'bottom-right'
        ], $atts);
        
        $properties = $this->get_comparison_properties();
        
        ob_start();
        ?>
        
        <?php if ($atts['show_floating_widget']): ?>
        <div class="bme-comparison-widget <?php echo esc_attr($atts['widget_position']); ?>" id="bme-comparison-widget">
            <div class="bme-widget-header">
                <span class="bme-widget-title">Compare Properties</span>
                <span class="bme-widget-count">(<span id="bme-comparison-count"><?php echo count($properties); ?></span>/<?php echo $this->max_comparisons; ?>)</span>
                <button class="bme-widget-toggle" id="bme-widget-toggle">
                    <span class="dashicons dashicons-arrow-up-alt2"></span>
                </button>
            </div>
            <div class="bme-widget-content" id="bme-widget-content">
                <div class="bme-widget-properties" id="bme-widget-properties">
                    <?php if (empty($properties)): ?>
                        <p class="bme-widget-empty">No properties selected for comparison.</p>
                    <?php else: ?>
                        <?php foreach ($properties as $property): ?>
                            <div class="bme-widget-property" data-property-id="<?php echo $property->id; ?>">
                                <img src="<?php echo !empty($property->images) ? esc_url($property->images[0]) : ''; ?>" 
                                     alt="<?php echo esc_attr($property->address); ?>" />
                                <div class="bme-widget-property-info">
                                    <div class="bme-widget-price">$<?php echo number_format($property->list_price); ?></div>
                                    <div class="bme-widget-address"><?php echo esc_html($property->address); ?></div>
                                </div>
                                <button class="bme-widget-remove" data-property-id="<?php echo $property->id; ?>">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="bme-widget-actions">
                    <?php if (!empty($properties)): ?>
                        <button class="bme-btn bme-btn-primary" id="bme-view-comparison">
                            Compare Properties
                        </button>
                        <button class="bme-btn bme-btn-secondary" id="bme-clear-comparison">
                            Clear All
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="bme-comparison-container" id="bme-comparison-container">
            <?php if (empty($properties)): ?>
                <div class="bme-no-comparison">
                    <h3>Property Comparison</h3>
                    <p>You haven't selected any properties to compare yet.</p>
                    <p><a href="<?php echo home_url('/property-search/'); ?>">Search properties</a> and click the comparison icon to add them here!</p>
                </div>
            <?php else: ?>
                <div class="bme-comparison-header">
                    <h3>Property Comparison (<?php echo count($properties); ?>/<?php echo $this->max_comparisons; ?>)</h3>
                    <button class="bme-btn bme-btn-secondary" id="bme-print-comparison">Print Comparison</button>
                </div>
                
                <div class="bme-comparison-table-wrapper">
                    <table class="bme-comparison-table">
                        <thead>
                            <tr>
                                <th class="bme-comparison-feature">Feature</th>
                                <?php foreach ($properties as $property): ?>
                                    <th class="bme-comparison-property">
                                        <div class="bme-property-header">
                                            <div class="bme-property-image">
                                                <?php if (!empty($property->images)): ?>
                                                    <img src="<?php echo esc_url($property->images[0]); ?>" 
                                                         alt="<?php echo esc_attr($property->address); ?>" />
                                                <?php endif; ?>
                                            </div>
                                            <div class="bme-property-info">
                                                <div class="bme-property-price">$<?php echo number_format($property->list_price); ?></div>
                                                <div class="bme-property-address"><?php echo esc_html($property->address); ?></div>
                                                <div class="bme-property-mls">MLS: <?php echo esc_html($property->mls_id); ?></div>
                                            </div>
                                            <button class="bme-remove-property" data-property-id="<?php echo $property->id; ?>">
                                                <span class="dashicons dashicons-no"></span>
                                            </button>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $comparison_fields = [
                                'Status' => 'status',
                                'Property Type' => 'property_type',
                                'Bedrooms' => 'bedrooms',
                                'Bathrooms' => 'bathrooms',
                                'Square Feet' => 'sqft_total',
                                'Lot Size' => 'lot_size',
                                'Year Built' => 'year_built',
                                'Price per Sq Ft' => 'price_per_sqft',
                                'Days on Market' => 'days_on_market',
                                'HOA Fees' => 'hoa_fees',
                                'Property Taxes' => 'property_taxes',
                                'City' => 'city',
                                'State' => 'state',
                                'ZIP Code' => 'zip_code',
                                'Neighborhood' => 'neighborhood',
                                'School District' => 'school_district',
                                'Heating' => 'heating',
                                'Cooling' => 'cooling',
                                'Parking' => 'parking',
                                'Garage' => 'garage',
                                'Basement' => 'basement',
                                'Pool' => 'pool',
                                'Fireplace' => 'fireplace'
                            ];
                            
                            foreach ($comparison_fields as $label => $field):
                            ?>
                                <tr>
                                    <td class="bme-feature-label"><?php echo esc_html($label); ?></td>
                                    <?php foreach ($properties as $property): ?>
                                        <td class="bme-feature-value">
                                            <?php
                                            $value = $property->$field ?? '';
                                            
                                            if ($field === 'sqft_total' || $field === 'lot_size') {
                                                echo $value ? number_format($value) . ' sq ft' : 'N/A';
                                            } elseif ($field === 'price_per_sqft') {
                                                echo $value ? '$' . number_format($value, 2) : 'N/A';
                                            } elseif ($field === 'hoa_fees' || $field === 'property_taxes') {
                                                echo $value ? '$' . number_format($value) : 'N/A';
                                            } elseif (in_array($field, ['pool', 'fireplace', 'basement'])) {
                                                echo $value ? 'Yes' : 'No';
                                            } else {
                                                echo $value ? esc_html($value) : 'N/A';
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Initialize comparison functionality
            BMEPropertyComparison.init();
        });
        </script>
        
        <?php
        return ob_get_clean();
    }
}