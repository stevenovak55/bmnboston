<?php

if (!defined('ABSPATH')) {
    exit;
}

class BME_Saved_Searches {
    
    private $db_manager;
    private $cache_manager;
    
    public function __construct($db_manager, $cache_manager) {
        $this->db_manager = $db_manager;
        $this->cache_manager = $cache_manager;
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // DEPRECATED v4.0.17: These features have been moved to MLS Listings Display (MLD) plugin
        // The MLD plugin now handles all saved searches and favorites functionality
        // Keeping AJAX handlers disabled to prevent duplicate functionality

        // add_action('wp_ajax_bme_save_search', [$this, 'ajax_save_search']);
        // add_action('wp_ajax_bme_delete_saved_search', [$this, 'ajax_delete_saved_search']);
        // add_action('wp_ajax_bme_load_saved_search', [$this, 'ajax_load_saved_search']);
        // add_action('wp_ajax_bme_get_saved_searches', [$this, 'ajax_get_saved_searches']);
        // add_action('wp_ajax_bme_toggle_search_alerts', [$this, 'ajax_toggle_search_alerts']);

        // add_action('wp_ajax_bme_add_favorite', [$this, 'ajax_add_favorite']);
        // add_action('wp_ajax_bme_remove_favorite', [$this, 'ajax_remove_favorite']);
        // add_action('wp_ajax_bme_get_favorites', [$this, 'ajax_get_favorites']);

        // DEPRECATED v4.0.17: Shortcodes moved to MLD plugin
        // Use [mld_saved_searches] and [mld_saved_properties] instead
        // add_shortcode('bme_saved_searches', [$this, 'render_saved_searches_shortcode']);
        // add_shortcode('bme_favorites', [$this, 'render_favorites_shortcode']);
    }
    
    public function save_search($user_id, $name, $criteria, $enable_alerts = false) {
        global $wpdb;
        
        try {
            $table_name = $this->db_manager->get_table('saved_searches');
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE user_id = %d AND name = %s",
                $user_id, $name
            ));
            
            if ($existing) {
                throw new Exception('A saved search with this name already exists.');
            }
            
            $data = [
                'user_id' => $user_id,
                'name' => sanitize_text_field($name),
                'criteria' => json_encode($criteria),
                'enable_alerts' => $enable_alerts ? 1 : 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];
            
            $result = $wpdb->insert($table_name, $data);
            
            if ($result === false) {
                throw new Exception('Failed to save search to database.');
            }
            
            $search_id = $wpdb->insert_id;
            
            $this->cache_manager->delete("saved_searches_user_{$user_id}");
            
            return $search_id;
            
        } catch (Exception $e) {
            error_log('BME Saved Searches Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function get_user_saved_searches($user_id, $include_criteria = false) {
        $cache_key = "saved_searches_user_{$user_id}" . ($include_criteria ? '_with_criteria' : '');
        $cached = $this->cache_manager->get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $table_name = $this->db_manager->get_table('saved_searches');
        
        $select_fields = 'id, name, enable_alerts, created_at, updated_at';
        if ($include_criteria) {
            $select_fields .= ', criteria';
        }
        
        $searches = $wpdb->get_results($wpdb->prepare(
            "SELECT {$select_fields} FROM {$table_name} 
             WHERE user_id = %d 
             ORDER BY updated_at DESC",
            $user_id
        ));
        
        if ($include_criteria) {
            foreach ($searches as &$search) {
                $search->criteria = json_decode($search->criteria, true);
            }
        }
        
        $this->cache_manager->set($cache_key, $searches, 1800);
        
        return $searches;
    }
    
    public function get_saved_search($search_id, $user_id = null) {
        global $wpdb;
        $table_name = $this->db_manager->get_table('saved_searches');
        
        $where_clause = "id = %d";
        $params = [$search_id];
        
        if ($user_id !== null) {
            $where_clause .= " AND user_id = %d";
            $params[] = $user_id;
        }
        
        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE {$where_clause}",
            $params
        ));
        
        if ($search) {
            $search->criteria = json_decode($search->criteria, true);
        }
        
        return $search;
    }
    
    public function update_saved_search($search_id, $user_id, $data) {
        global $wpdb;
        $table_name = $this->db_manager->get_table('saved_searches');
        
        $update_data = ['updated_at' => current_time('mysql')];
        
        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }
        
        if (isset($data['criteria'])) {
            $update_data['criteria'] = json_encode($data['criteria']);
        }
        
        if (isset($data['enable_alerts'])) {
            $update_data['enable_alerts'] = $data['enable_alerts'] ? 1 : 0;
        }
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $search_id, 'user_id' => $user_id]
        );
        
        $this->cache_manager->delete("saved_searches_user_{$user_id}");
        $this->cache_manager->delete("saved_searches_user_{$user_id}_with_criteria");
        
        return $result !== false;
    }
    
    public function delete_saved_search($search_id, $user_id) {
        global $wpdb;
        $table_name = $this->db_manager->get_table('saved_searches');
        
        $result = $wpdb->delete(
            $table_name,
            ['id' => $search_id, 'user_id' => $user_id]
        );
        
        $this->cache_manager->delete("saved_searches_user_{$user_id}");
        $this->cache_manager->delete("saved_searches_user_{$user_id}_with_criteria");
        
        return $result !== false;
    }
    
    public function add_favorite($user_id, $property_id, $notes = '') {
        global $wpdb;
        
        try {
            $table_name = $this->db_manager->get_table('favorites');
            
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE user_id = %d AND property_id = %d",
                $user_id, $property_id
            ));
            
            if ($existing) {
                return $existing;
            }
            
            $data = [
                'user_id' => $user_id,
                'property_id' => $property_id,
                'notes' => sanitize_textarea_field($notes),
                'created_at' => current_time('mysql')
            ];
            
            $result = $wpdb->insert($table_name, $data);
            
            if ($result === false) {
                throw new Exception('Failed to add favorite to database.');
            }
            
            $this->cache_manager->delete("favorites_user_{$user_id}");
            
            return $wpdb->insert_id;
            
        } catch (Exception $e) {
            error_log('BME Favorites Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function remove_favorite($user_id, $property_id) {
        global $wpdb;
        $table_name = $this->db_manager->get_table('favorites');
        
        $result = $wpdb->delete(
            $table_name,
            ['user_id' => $user_id, 'property_id' => $property_id]
        );
        
        $this->cache_manager->delete("favorites_user_{$user_id}");
        
        return $result !== false;
    }
    
    public function get_user_favorites($user_id, $limit = 50, $offset = 0) {
        $cache_key = "favorites_user_{$user_id}_{$limit}_{$offset}";
        $cached = $this->cache_manager->get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $favorites_table = $this->db_manager->get_table('favorites');
        $listings_table = $this->db_manager->get_table('listings');
        $details_table = $this->db_manager->get_table('listing_details');
        $financial_table = $this->db_manager->get_table('listing_financial');
        $media_table = $this->db_manager->get_table('media');
        
        $favorites = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, l.mls_id, l.status, l.last_updated,
                    d.address, d.bedrooms, d.bathrooms, d.sqft_total as sqft,
                    fin.list_price as price,
                    GROUP_CONCAT(m.file_path ORDER BY m.display_order) as images
             FROM {$favorites_table} f
             LEFT JOIN {$listings_table} l ON f.listing_id = l.listing_id
             LEFT JOIN {$details_table} d ON l.listing_id = d.listing_id
             LEFT JOIN {$financial_table} fin ON l.listing_id = fin.listing_id
             LEFT JOIN {$media_table} m ON l.listing_id = m.listing_id AND m.media_type = 'image'
             WHERE f.user_id = %d
             GROUP BY f.id
             ORDER BY f.created_at DESC
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ));
        
        foreach ($favorites as &$favorite) {
            if ($favorite->images) {
                $favorite->images = explode(',', $favorite->images);
            } else {
                $favorite->images = [];
            }
        }
        
        $this->cache_manager->set($cache_key, $favorites, 1800);
        
        return $favorites;
    }
    
    public function is_property_favorite($user_id, $property_id) {
        global $wpdb;
        $table_name = $this->db_manager->get_table('favorites');
        
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table_name} WHERE user_id = %d AND property_id = %d",
            $user_id, $property_id
        ));
    }
    
    public function get_searches_with_alerts_enabled() {
        global $wpdb;
        $table_name = $this->db_manager->get_table('saved_searches');
        
        return $wpdb->get_results(
            "SELECT * FROM {$table_name} WHERE enable_alerts = 1"
        );
    }

    /**
     * Sanitize search criteria recursively
     */
    private function sanitize_criteria($criteria) {
        if (!is_array($criteria)) {
            return [];
        }

        $sanitized = [];

        foreach ($criteria as $key => $value) {
            $key = sanitize_key($key);

            if (is_array($value)) {
                // Recursively sanitize arrays
                $sanitized[$key] = $this->sanitize_criteria($value);
            } elseif (is_bool($value)) {
                $sanitized[$key] = (bool) $value;
            } elseif (is_numeric($value)) {
                // Keep numeric values as-is but validate
                $sanitized[$key] = is_float($value) ? floatval($value) : intval($value);
            } else {
                // Sanitize text fields based on key
                if (in_array($key, ['min_price', 'max_price', 'min_beds', 'max_beds', 'min_baths', 'max_baths', 'min_sqft', 'max_sqft'])) {
                    $sanitized[$key] = intval($value);
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            }
        }

        return $sanitized;
    }

    public function ajax_save_search() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_search_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in to save searches.');
            return;
        }
        
        $user_id = get_current_user_id();
        $name = sanitize_text_field($_POST['name'] ?? '');

        // Properly sanitize criteria array/object
        $criteria = $_POST['criteria'] ?? [];
        if (is_string($criteria)) {
            // If criteria is JSON string, decode and re-encode to validate
            $decoded = json_decode($criteria, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('Invalid search criteria format.');
                return;
            }
            $criteria = $decoded;
        }

        // Sanitize criteria recursively
        $criteria = $this->sanitize_criteria($criteria);
        $enable_alerts = !empty($_POST['enable_alerts']);
        
        try {
            $search_id = $this->save_search($user_id, $name, $criteria, $enable_alerts);
            wp_send_json_success(['search_id' => $search_id]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_delete_saved_search() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_search_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $user_id = get_current_user_id();
        $search_id = intval($_POST['search_id']);
        
        $success = $this->delete_saved_search($search_id, $user_id);
        
        if ($success) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete saved search.');
        }
    }
    
    public function ajax_load_saved_search() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_search_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $user_id = get_current_user_id();
        $search_id = intval($_POST['search_id']);
        
        $search = $this->get_saved_search($search_id, $user_id);
        
        if ($search) {
            wp_send_json_success($search);
        } else {
            wp_send_json_error('Saved search not found.');
        }
    }
    
    public function ajax_get_saved_searches() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_search_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $user_id = get_current_user_id();
        $searches = $this->get_user_saved_searches($user_id);
        
        wp_send_json_success($searches);
    }
    
    public function ajax_toggle_search_alerts() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_search_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $user_id = get_current_user_id();
        $search_id = intval($_POST['search_id']);
        $enable_alerts = !empty($_POST['enable_alerts']);
        
        $success = $this->update_saved_search($search_id, $user_id, [
            'enable_alerts' => $enable_alerts
        ]);
        
        if ($success) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to update alert settings.');
        }
    }
    
    public function ajax_add_favorite() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_search_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $user_id = get_current_user_id();
        $property_id = intval($_POST['property_id']);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        try {
            $favorite_id = $this->add_favorite($user_id, $property_id, $notes);
            wp_send_json_success(['favorite_id' => $favorite_id]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_remove_favorite() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_search_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $user_id = get_current_user_id();
        $property_id = intval($_POST['property_id']);
        
        $success = $this->remove_favorite($user_id, $property_id);
        
        if ($success) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to remove favorite.');
        }
    }
    
    public function ajax_get_favorites() {
        if (!wp_verify_nonce($_POST['nonce'], 'bme_search_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $user_id = get_current_user_id();
        $limit = intval($_POST['limit'] ?? 50);
        $offset = intval($_POST['offset'] ?? 0);
        
        $favorites = $this->get_user_favorites($user_id, $limit, $offset);
        
        wp_send_json_success($favorites);
    }
    
    public function render_saved_searches_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 20,
            'show_alerts' => true
        ], $atts);
        
        if (!is_user_logged_in()) {
            return '<p>Please log in to view your saved searches.</p>';
        }
        
        $user_id = get_current_user_id();
        $searches = $this->get_user_saved_searches($user_id);
        
        ob_start();
        ?>
        <div class="bme-saved-searches-container">
            <div class="bme-saved-searches-header">
                <h3>Your Saved Searches</h3>
                <button class="bme-btn bme-btn-primary" id="bme-create-new-search">
                    Create New Search
                </button>
            </div>
            
            <?php if (empty($searches)): ?>
                <div class="bme-no-saved-searches">
                    <p>You haven't saved any searches yet.</p>
                    <p><a href="<?php echo home_url('/property-search/'); ?>">Start searching for properties</a> and save your favorite searches!</p>
                </div>
            <?php else: ?>
                <div class="bme-saved-searches-list">
                    <?php foreach ($searches as $search): ?>
                        <div class="bme-saved-search-item" data-search-id="<?php echo $search->id; ?>">
                            <div class="bme-search-info">
                                <h4><?php echo esc_html($search->name); ?></h4>
                                <div class="bme-search-meta">
                                    <span class="bme-search-date">
                                        Saved on <?php echo date('M j, Y', strtotime($search->created_at)); ?>
                                    </span>
                                    <?php if ($search->updated_at !== $search->created_at): ?>
                                        <span class="bme-search-updated">
                                            Updated <?php echo date('M j, Y', strtotime($search->updated_at)); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="bme-search-actions">
                                <?php if ($atts['show_alerts']): ?>
                                    <label class="bme-toggle-alerts">
                                        <input type="checkbox" 
                                               class="bme-alert-toggle" 
                                               <?php checked($search->enable_alerts); ?>
                                               data-search-id="<?php echo $search->id; ?>">
                                        <span class="bme-toggle-slider"></span>
                                        Email Alerts
                                    </label>
                                <?php endif; ?>
                                
                                <button class="bme-btn bme-btn-secondary bme-load-search" 
                                        data-search-id="<?php echo $search->id; ?>">
                                    Load Search
                                </button>
                                <button class="bme-btn bme-btn-danger bme-delete-search" 
                                        data-search-id="<?php echo $search->id; ?>">
                                    Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.bme-load-search').on('click', function() {
                const searchId = $(this).data('search-id');
                window.location.href = '<?php echo home_url('/property-search/'); ?>?load_search=' + searchId;
            });
            
            $('.bme-delete-search').on('click', function() {
                if (confirm('Are you sure you want to delete this saved search?')) {
                    const searchId = $(this).data('search-id');
                    const $item = $(this).closest('.bme-saved-search-item');
                    
                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'bme_delete_saved_search',
                        nonce: '<?php echo wp_create_nonce('bme_search_nonce'); ?>',
                        search_id: searchId
                    }, function(response) {
                        if (response.success) {
                            $item.fadeOut(300, function() {
                                $(this).remove();
                            });
                        } else {
                            alert('Failed to delete search: ' + response.data);
                        }
                    });
                }
            });
            
            $('.bme-alert-toggle').on('change', function() {
                const searchId = $(this).data('search-id');
                const enableAlerts = $(this).is(':checked');
                
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'bme_toggle_search_alerts',
                    nonce: '<?php echo wp_create_nonce('bme_search_nonce'); ?>',
                    search_id: searchId,
                    enable_alerts: enableAlerts
                }, function(response) {
                    if (!response.success) {
                        alert('Failed to update alert settings: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    public function render_favorites_shortcode($atts) {
        $atts = shortcode_atts([
            'limit' => 20,
            'columns' => 3
        ], $atts);
        
        if (!is_user_logged_in()) {
            return '<p>Please log in to view your favorite properties.</p>';
        }
        
        $user_id = get_current_user_id();
        $favorites = $this->get_user_favorites($user_id, $atts['limit']);
        
        ob_start();
        ?>
        <div class="bme-favorites-container">
            <div class="bme-favorites-header">
                <h3>Your Favorite Properties</h3>
            </div>
            
            <?php if (empty($favorites)): ?>
                <div class="bme-no-favorites">
                    <p>You haven't added any properties to your favorites yet.</p>
                    <p><a href="<?php echo home_url('/property-search/'); ?>">Browse properties</a> and click the heart icon to add favorites!</p>
                </div>
            <?php else: ?>
                <div class="bme-favorites-grid" style="grid-template-columns: repeat(<?php echo $atts['columns']; ?>, 1fr);">
                    <?php foreach ($favorites as $favorite): ?>
                        <div class="bme-favorite-item" data-property-id="<?php echo $favorite->property_id; ?>">
                            <div class="bme-favorite-image">
                                <?php 
                                $image_url = !empty($favorite->images) ? $favorite->images[0] : 
                                    plugin_dir_url(__FILE__) . '../assets/images/no-image.jpg';
                                ?>
                                <img src="<?php echo esc_url($image_url); ?>" 
                                     alt="<?php echo esc_attr($favorite->address); ?>" />
                                <button class="bme-remove-favorite" data-property-id="<?php echo $favorite->property_id; ?>">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                            
                            <div class="bme-favorite-details">
                                <div class="bme-favorite-price">$<?php echo number_format($favorite->price); ?></div>
                                <div class="bme-favorite-address"><?php echo esc_html($favorite->address); ?></div>
                                <div class="bme-favorite-specs">
                                    <span><?php echo $favorite->bedrooms; ?> bed</span>
                                    <span><?php echo $favorite->bathrooms; ?> bath</span>
                                    <span><?php echo number_format($favorite->sqft); ?> sqft</span>
                                </div>
                                <div class="bme-favorite-status status-<?php echo strtolower($favorite->status); ?>">
                                    <?php echo esc_html($favorite->status); ?>
                                </div>
                                <div class="bme-favorite-mls">MLS: <?php echo esc_html($favorite->mls_id); ?></div>
                                
                                <?php if (!empty($favorite->notes)): ?>
                                    <div class="bme-favorite-notes">
                                        <strong>Notes:</strong> <?php echo esc_html($favorite->notes); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="bme-favorite-saved">
                                    Saved on <?php echo date('M j, Y', strtotime($favorite->created_at)); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.bme-remove-favorite').on('click', function(e) {
                e.stopPropagation();
                
                if (confirm('Remove this property from your favorites?')) {
                    const propertyId = $(this).data('property-id');
                    const $item = $(this).closest('.bme-favorite-item');
                    
                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'bme_remove_favorite',
                        nonce: '<?php echo wp_create_nonce('bme_search_nonce'); ?>',
                        property_id: propertyId
                    }, function(response) {
                        if (response.success) {
                            $item.fadeOut(300, function() {
                                $(this).remove();
                            });
                        } else {
                            alert('Failed to remove favorite: ' + response.data);
                        }
                    });
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
}