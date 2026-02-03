<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimized admin interface with enhanced performance and UX
 * Version: 2.3.9 (Fixed Undefined Constant and Save Post Callback)
 */
class BME_Admin {

    private $plugin;
    private $cache_manager;

    public function __construct(Bridge_MLS_Extractor_Pro $plugin) {
        $this->plugin = $plugin;
        $this->cache_manager = $plugin->get('cache');
        $this->init_hooks();
    }

    /**
     * Initialize admin hooks
     */
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        // Corrected: Using static method for save_post hook for robustness
        add_action('save_post_bme_extraction', ['BME_Admin', 'save_extraction_meta_static'], 10, 2);

        add_filter('manage_bme_extraction_posts_columns', [$this, 'set_extraction_columns']);
        add_action('manage_bme_extraction_posts_custom_column', [$this, 'display_extraction_column'], 10, 2);

        // Admin actions
        add_action('admin_post_bme_run_extraction', [$this, 'handle_run_extraction']);
        add_action('admin_post_bme_run_resync', [$this, 'handle_run_resync']);
        add_action('admin_post_bme_clear_data', [$this, 'handle_clear_data']);
        add_action('admin_post_bme_test_config', [$this, 'handle_test_config']);
        
        add_action('admin_post_bme_export_listings_csv', [$this, 'handle_export_listings_csv']);
        add_action('admin_post_bme_run_vt_import', [$this, 'handle_run_vt_import']);
        add_action('admin_post_bme_export_system_report', [$this, 'handle_export_system_report']);

        add_action('load-mls-extractions_page_bme-database-browser', [$this, 'handle_database_browser_bulk_actions']);

        // AJAX handlers
        add_action('wp_ajax_bme_get_filter_values', [$this, 'ajax_get_filter_values']);
        add_action('wp_ajax_bme_search_listings', [$this, 'ajax_search_listings']);
        add_action('wp_ajax_bme_get_extraction_stats', [$this, 'ajax_get_extraction_stats']);
        add_action('wp_ajax_bme_live_search', [$this, 'ajax_live_search']);
        add_action('wp_ajax_bme_get_live_extraction_progress', [$this, 'ajax_get_live_extraction_progress']);
        add_action('wp_ajax_bme_get_extraction_preview', [$this, 'ajax_get_extraction_preview']);
        
        // Market Analytics Filter AJAX handlers
        add_action('wp_ajax_bme_get_filter_preview', [$this, 'ajax_get_filter_preview']);

        // Property details AJAX handler
        add_action('wp_ajax_bme_get_listing_details', [$this, 'ajax_get_listing_details']);

        add_action('admin_notices', [$this, 'display_admin_notices']);
        add_action('admin_notices', [$this, 'display_vt_import_notices']);
        add_action('admin_notices', [$this, 'display_performance_indexes_notice']);
    }

    /**
     * Add admin menus
     */
    public function add_admin_menus() {
        add_menu_page(
            __('MLS Extractions Pro', 'bridge-mls-extractor-pro'),
            __('MLS Extractions', 'bridge-mls-extractor-pro'),
            'manage_options',
            'edit.php?post_type=bme_extraction',
            '',
            'dashicons-database-export',
            25
        );

        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Database Browser', 'bridge-mls-extractor-pro'),
            __('Database Browser', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-database-browser',
            [$this, 'render_database_browser']
        );

        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Performance Dashboard', 'bridge-mls-extractor-pro'),
            __('Performance Dashboard', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-performance',
            [$this, 'render_performance_dashboard']
        );

        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Activity Logs', 'bridge-mls-extractor-pro'),
            __('Activity Logs', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-activity-logs',
            [$this, 'render_activity_logs']
        );
        
        add_submenu_page(
            'edit.php?post_type=bme_extraction',
            __('Settings', 'bridge-mls-extractor-pro'),
            __('Settings', 'bridge-mls-extractor-pro'),
            'manage_options',
            'bme-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue admin assets with cache busting
     */
    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();

        if (!$screen || strpos($screen->id, 'bme') === false) {
            return;
        }

        $version = defined('BME_PRO_VERSION') ? BME_PRO_VERSION : '1.0';
        $css_file_path = BME_PLUGIN_DIR . 'assets/admin.css';
        $js_file_path = BME_PLUGIN_DIR . 'assets/admin.js';

        $css_version = $version . '.' . (file_exists($css_file_path) ? filemtime($css_file_path) : '');
        $js_version = $version . '.' . (file_exists($js_file_path) ? filemtime($js_file_path) : '');

        wp_enqueue_style('bme-admin', BME_PLUGIN_URL . 'assets/admin.css', [], $css_version);
        
        // Enqueue Chart.js for performance dashboard and market analytics (UMD version)
        if (isset($_GET['page']) && (sanitize_text_field($_GET['page']) === 'bme-performance' || sanitize_text_field($_GET['page']) === 'bme-market-analytics')) {
            // Try jsDelivr first, fallback to unpkg
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js', [], '4.4.0', true);
            wp_enqueue_script('chartjs-adapter-date-fns', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js', ['chartjs'], '3.0.0', true);
            
            // Add fallback script in case jsDelivr fails
            add_action('wp_footer', function() {
                echo '<script>
                if (typeof Chart === "undefined") {
                    console.log("BME: Primary Chart.js CDN failed, trying fallback...");
                    var fallbackScript = document.createElement("script");
                    fallbackScript.src = "https://unpkg.com/chart.js@4.4.0/dist/chart.umd.js";
                    fallbackScript.onload = function() {
                        console.log("BME: Fallback Chart.js loaded successfully");
                        if (typeof BME !== "undefined" && typeof BME.renderPerformanceCharts === "function") {
                            BME.renderPerformanceCharts();
                        }
                    };
                    document.head.appendChild(fallbackScript);
                }
                </script>';
            });
        }
        
        // Add dependencies including chartjs for performance dashboard
        $dependencies = ['jquery', 'wp-util', 'jquery-ui-autocomplete'];
        if (isset($_GET['page']) && sanitize_text_field($_GET['page']) === 'bme-performance') {
            $dependencies[] = 'chartjs';
        }
        wp_enqueue_script('bme-admin', BME_PLUGIN_URL . 'assets/admin.js', $dependencies, $js_version, true);

        wp_localize_script('bme-admin', 'bmeAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bme_admin_nonce'),
            'strings' => [
                'confirmClear' => __('Are you sure? This will delete all data for this extraction.', 'bridge-mls-extractor-pro'),
                'confirmResync' => __('This will clear existing data and re-download everything. Continue?', 'bridge-mls-extractor-pro'),
                'loading' => __('Loading...', 'bridge-mls-extractor-pro'),
                'error' => __('An error occurred. Please try again.', 'bridge-mls-extractor-pro'),
                'allCitiesWarning' => __('You have not specified any cities. This will extract listings from ALL cities in the selected states. Are you sure you want to proceed?', 'bridge-mls-extractor-pro'),
                'saveFirst' => __('Please save your changes before running an extraction.', 'bridge-mls-extractor-pro'),
                'liveProgressTitle' => __('Live Extraction Progress', 'bridge-mls-extractor-pro'),
                'currentStatus' => __('Status:', 'bridge-mls-extractor-pro'),
                'totalProcessed' => __('Processed:', 'bridge-mls-extractor-pro'),
                'currentListing' => __('Current:', 'bridge-mls-extractor-pro'),
                'lastUpdated' => __('Last Update:', 'bridge-mls-extractor-pro'),
                'duration' => __('Duration:', 'bridge-mls-extractor-pro'),
                'propertyTypes' => __('Property Types:', 'bridge-mls-extractor-pro'),
            ]
        ]);

        // Add chart data for performance dashboard
        if (isset($_GET['page']) && sanitize_text_field($_GET['page']) === 'bme-performance') {
            wp_localize_script('bme-admin', 'bmeChartData', $this->get_dashboard_chart_data());
        }

        // Enqueue Select2 and jQuery UI styles specifically for the browser page
        if (strpos($hook, 'bme-database-browser') !== false) {
            wp_enqueue_style('jquery-ui-style', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0-rc.0', true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0-rc.0');
        }
    }

    /**
     * Add meta boxes for extraction CPT
     */
    public function add_meta_boxes() {
        add_meta_box(
            'bme_extraction_config',
            __('Extraction Configuration', 'bridge-mls-extractor-pro'),
            [$this, 'render_extraction_config_meta_box'],
            'bme_extraction',
            'normal',
            'high'
        );

        add_meta_box(
            'bme_extraction_stats',
            __('Statistics & Actions', 'bridge-mls-extractor-pro'),
            [$this, 'render_extraction_stats_meta_box'],
            'bme_extraction',
            'side',
            'default'
        );

        // Extraction Preview Meta Box
        add_meta_box(
            'bme_extraction_preview',
            __('📊 Extraction Preview & Batch Plan', 'bridge-mls-extractor-pro'),
            [$this, 'render_extraction_preview_meta_box'],
            'bme_extraction',
            'normal',
            'high'
        );
        
        // New: Live Progress Meta Box
        add_meta_box(
            'bme_extraction_live_progress',
            __('Live Progress', 'bridge-mls-extractor-pro'),
            [$this, 'render_live_progress_meta_box'],
            'bme_extraction',
            'side',
            'high'
        );
    }

    /**
     * Render extraction configuration meta box with enhanced validation logic
     */
    public function render_extraction_config_meta_box($post) {
        wp_nonce_field('bme_save_extraction_meta', 'bme_extraction_nonce');

        $config = [
            'statuses' => get_post_meta($post->ID, '_bme_statuses', true) ?: [],
            'property_types' => get_post_meta($post->ID, '_bme_property_types', true) ?: [],
            'cities' => get_post_meta($post->ID, '_bme_cities', true),
            'states' => get_post_meta($post->ID, '_bme_states', true) ?: [],
            'list_agent_id' => get_post_meta($post->ID, '_bme_list_agent_id', true),
            'buyer_agent_id' => get_post_meta($post->ID, '_bme_buyer_agent_id', true),
            'lookback_months' => get_post_meta($post->ID, '_bme_lookback_months', true) ?: 12,
            'schedule' => get_post_meta($post->ID, '_bme_schedule', true) ?: 'none'
        ];

        ?>
        <div id="bme-unsaved-changes-notice" class="notice notice-warning inline" style="display: none;">
            <p><?php _e('You have unsaved changes. Please save or update the profile.', 'bridge-mls-extractor-pro'); ?></p>
        </div>
        <table class="form-table bme-config-table">
            <tr>
                <th><label for="bme_schedule"><?php _e('Schedule', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <select name="bme_schedule" id="bme_schedule" class="regular-text">
                        <?php
                        $schedules = array_merge(['none' => ['display' => __('Manual Only', 'bridge-mls-extractor-pro')]], wp_get_schedules());
                        $allowed_schedules = ['none', 'every_15_minutes', 'every_30_minutes', 'hourly', 'twicedaily', 'daily'];

                        foreach ($schedules as $key => $details) {
                            if (in_array($key, $allowed_schedules)) {
                                printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($config['schedule'], $key, false), esc_html($details['display']));
                            }
                        }
                        ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label><?php _e('Listing Statuses', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <p class="description"><?php _e('Select statuses from ONE group. Active/Pending are for current listings. Closed/Archived are for historical data and require a lookback period.', 'bridge-mls-extractor-pro'); ?></p>
                    <fieldset id="bme-statuses">
                        <div style="margin-bottom: 15px;">
                            <strong><?php _e('Active / Pending Group', 'bridge-mls-extractor-pro'); ?></strong><br>
                            <?php
                            $active_statuses = ['Active', 'Active Under Contract', 'Pending'];
                            foreach ($active_statuses as $status) {
                                printf('<label><input type="checkbox" name="bme_statuses[]" value="%s" %s data-group="active"> %s</label><br>', esc_attr($status), checked(in_array($status, $config['statuses']), true, false), esc_html($status));
                            }
                            ?>
                        </div>
                        <div>
                            <strong><?php _e('Closed / Archived Group', 'bridge-mls-extractor-pro'); ?></strong><br>
                            <?php
                            $archived_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled'];
                            foreach ($archived_statuses as $status) {
                                printf('<label><input type="checkbox" name="bme_statuses[]" value="%s" %s data-group="archived"> %s</label><br>', esc_attr($status), checked(in_array($status, $config['statuses']), true, false), esc_html($status));
                            }
                            ?>
                        </div>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th><label><?php _e('Property Types', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <fieldset id="bme-property-types">
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                            <?php
                            $property_type_options = [
                                'Residential' => 'Residential',
                                'Residential Lease' => 'Residential Lease',
                                'Residential Income' => 'Residential Income',
                                'Land' => 'Land',
                                'Commercial Sale' => 'Commercial Sale',
                                'Commercial Lease' => 'Commercial Lease',
                                'Business Opportunity' => 'Business Opportunity',
                                'Rental' => 'Rental'
                            ];
                            
                            foreach ($property_type_options as $value => $label) {
                                printf(
                                    '<label style="display: block;"><input type="checkbox" name="bme_property_types[]" value="%s" %s> %s</label>',
                                    esc_attr($value),
                                    checked(in_array($value, $config['property_types']), true, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </div>
                        <p class="description" style="margin-top: 10px;">
                            <?php _e('Select the property types to include. Leave all unchecked to include all property types.', 'bridge-mls-extractor-pro'); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>

            <tr id="bme-lookback-row" style="display: none;">
                <th><label for="bme_lookback_months"><?php _e('Archived Listings Lookback', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="number" name="bme_lookback_months" id="bme_lookback_months" value="<?php echo esc_attr($config['lookback_months']); ?>" class="small-text" min="1" step="1">
                    <span><?php _e('months', 'bridge-mls-extractor-pro'); ?></span>
                    <p class="description"><?php _e('Required. How many months back to search for archived listings.', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>

            <tr>
                <th><label for="bme_cities"><?php _e('Cities', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <textarea name="bme_cities" id="bme_cities" rows="3" class="large-text" placeholder="Boston, Cambridge, Somerville"><?php echo esc_textarea($config['cities']); ?></textarea>
                    <p class="description"><?php _e('Comma-separated list. Leave blank to include all cities (a confirmation will be required).', 'bridge-mls-extractor-pro'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('States/Provinces', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <fieldset>
                        <?php
                        $state_options = ['MA', 'NH', 'RI', 'VT', 'CT', 'ME'];
                        foreach ($state_options as $state) {
                            printf('<label><input type="checkbox" name="bme_states[]" value="%s" %s> %s</label> ', esc_attr($state), checked(in_array($state, $config['states']), true, false), esc_html($state));
                        }
                        ?>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th><label for="bme_list_agent_id"><?php _e('List Agent MLS ID', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="text" name="bme_list_agent_id" id="bme_list_agent_id" value="<?php echo esc_attr($config['list_agent_id']); ?>" class="regular-text">
                </td>
            </tr>
            <tr id="bme-buyer-agent-row" style="display: none;">
                <th><label for="bme_buyer_agent_id"><?php _e('Buyer Agent MLS ID', 'bridge-mls-extractor-pro'); ?></label></th>
                <td>
                    <input type="text" name="bme_buyer_agent_id" id="bme_buyer_agent_id" value="<?php echo esc_attr($config['buyer_agent_id']); ?>" class="regular-text">
                </td>
            </tr>
        </table>

        <script>
        jQuery(document).ready(function($) {
            const form = $('#post');
            const statusesFieldset = $('#bme-statuses');
            let isDirty = false;

            function setDirty(dirty) {
                isDirty = dirty;
                $('#bme-unsaved-changes-notice').toggle(dirty);
                $('.bme-action-button').prop('disabled', dirty).toggleClass('disabled', dirty);
                if(dirty) {
                    $('.bme-action-button').attr('title', bmeAdmin.strings.saveFirst);
                } else {
                    $('.bme-action-button').removeAttr('title');
                }
            }

            form.on('change keyup', 'input, select, textarea', function() {
                setDirty(true);
            });

            setDirty(false);

            function handleStatusSelection() {
                const checked = statusesFieldset.find('input:checked');
                const firstCheckedGroup = checked.length > 0 ? checked.first().data('group') : null;

                statusesFieldset.find('input').each(function() {
                    const currentGroup = $(this).data('group');
                    $(this).prop('disabled', firstCheckedGroup && currentGroup !== firstCheckedGroup);
                });

                $('#bme-lookback-row').toggle(firstCheckedGroup === 'archived');

                const showBuyerAgent = checked.is('[value="Active Under Contract"], [value="Pending"], [value="Closed"]');
                $('#bme-buyer-agent-row').toggle(showBuyerAgent);
            }

            statusesFieldset.on('change', 'input', handleStatusSelection);
            handleStatusSelection();

            form.on('submit', function(e) {
                const isArchivedSelected = statusesFieldset.find('input[data-group="archived"]:checked').length > 0;
                const lookbackInput = $('#bme_lookback_months');
                if (isArchivedSelected && (!lookbackInput.val() || parseInt(lookbackInput.val(), 10) <= 0)) {
                    alert('<?php _e('The "Archived Listings Lookback" is required and must be greater than 0 when selecting a Closed/Archived status.', 'bridge-mls-extractor-pro'); ?>');
                    lookbackInput.focus();
                    e.preventDefault();
                    return false;
                }

                const citiesInput = $('#bme_cities');
                if (citiesInput.val().trim() === '') {
                    if (!confirm(bmeAdmin.strings.allCitiesWarning)) {
                        citiesInput.focus();
                        e.preventDefault();
                        return false;
                    }
                }

                setDirty(false);
            });

            $(document).on('click', '.bme-action-button', function(e) {
                if (isDirty) {
                    e.preventDefault();
                    alert(bmeAdmin.strings.saveFirst);
                    return false;
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render extraction statistics meta box
     */
    public function render_extraction_stats_meta_box($post) {
        $stats = $this->cache_manager->get_extraction_stats($post->ID);

        if ($stats === false) {
            $data_processor = $this->plugin->get('processor');
            $stats = $data_processor->get_extraction_stats($post->ID);
            $this->cache_manager->cache_extraction_stats($post->ID, $stats);
        }

        $last_run_status = get_post_meta($post->ID, '_bme_last_run_status', true);

        ?>
        <div class="bme-stats-grid">
             <div class="bme-stat-item">
                <div class="bme-stat-value"><?php echo esc_html(number_format($stats['total_listings'] ?? 0)); ?></div>
                <div class="bme-stat-label"><?php _e('Listings', 'bridge-mls-extractor-pro'); ?></div>
            </div>
            <div class="bme-stat-item">
                <div class="bme-stat-value <?php echo esc_attr($this->get_status_class($last_run_status)); ?>">
                    <?php echo esc_html($last_run_status ?: __('Never', 'bridge-mls-extractor-pro')); ?>
                </div>
                <div class="bme-stat-label"><?php _e('Last Run', 'bridge-mls-extractor-pro'); ?></div>
            </div>
        </div>

        <div class="bme-actions">
            <?php
            $run_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_extraction&post_id=' . $post->ID), 'bme_run_extraction_' . $post->ID);
            $resync_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_resync&post_id=' . $post->ID), 'bme_run_resync_' . $post->ID);
            $clear_url = wp_nonce_url(admin_url('admin-post.php?action=bme_clear_data&post_id=' . $post->ID), 'bme_clear_data_' . $post->ID);
            $test_url = wp_nonce_url(admin_url('admin-post.php?action=bme_test_config&post_id=' . $post->ID), 'bme_test_config_' . $post->ID);
            ?>
            <a href="<?php echo esc_url($test_url); ?>" class="button button-secondary bme-action-button"><?php _e('Test Config', 'bridge-mls-extractor-pro'); ?></a>
            <a href="<?php echo esc_url($run_url); ?>" class="button button-primary bme-action-button" id="bme-run-extraction-button" data-extraction-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Run Now', 'bridge-mls-extractor-pro'); ?></a>
            <a href="<?php echo esc_url($resync_url); ?>" class="button button-secondary bme-action-button bme-confirm-resync" id="bme-resync-extraction-button" data-extraction-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Full Resync', 'bridge-mls-extractor-pro'); ?></a>
            <a href="<?php echo esc_url($clear_url); ?>" class="button button-link-delete bme-action-button bme-confirm-clear"><?php _e('Clear Data', 'bridge-mls-extractor-pro'); ?></a>
        </div>
        <?php
    }

    /**
     * Render Extraction Preview Meta Box
     */
    public function render_extraction_preview_meta_box($post) {
        ?>
        <div id="bme-extraction-preview-container" data-extraction-id="<?php echo esc_attr($post->ID); ?>">
            <div class="bme-preview-actions">
                <button type="button" id="bme-get-preview-button" class="button button-primary" data-extraction-id="<?php echo esc_attr($post->ID); ?>">
                    <?php _e('🔍 Get Extraction Preview', 'bridge-mls-extractor-pro'); ?>
                </button>
                <span class="spinner" id="bme-preview-spinner" style="float: none; margin: 0 10px;"></span>
            </div>
            
            <div id="bme-preview-content" style="margin-top: 15px;">
                <p class="description">
                    <?php _e('Click "Get Extraction Preview" to see how many listings are available for this extraction configuration and view the detailed batch execution plan.', 'bridge-mls-extractor-pro'); ?>
                </p>
            </div>
            
            <div id="bme-preview-error" class="notice notice-error" style="display: none; margin: 15px 0;"></div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#bme-get-preview-button').on('click', function() {
                var extractionId = $(this).data('extraction-id');
                var $button = $(this);
                var $spinner = $('#bme-preview-spinner');
                var $content = $('#bme-preview-content');
                var $error = $('#bme-preview-error');
                
                // Show spinner and disable button
                $spinner.addClass('is-active');
                $button.prop('disabled', true);
                $error.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bme_get_extraction_preview',
                        extraction_id: extractionId,
                        nonce: '<?php echo wp_create_nonce('bme_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $content.html(response.data.html);
                        } else {
                            $error.html('<p>' + response.data + '</p>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $error.html('<p>Failed to get extraction preview: ' + error + '</p>').show();
                    },
                    complete: function() {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        
        <style>
        .bme-preview-actions {
            margin-bottom: 10px;
        }
        #bme-get-preview-button {
            font-size: 14px;
        }
        </style>
        <?php
    }

    /**
     * New: Render Live Progress Meta Box
     */
    public function render_live_progress_meta_box($post) {
        $progress = $this->plugin->get('extractor')->get_live_progress($post->ID);
        $is_running = ($progress && $progress['status'] === 'running');
        ?>
        <div id="bme-live-progress-container" data-extraction-id="<?php echo esc_attr($post->ID); ?>">
            <div id="bme-live-progress-content" style="<?php echo $is_running ? '' : 'display: none;'; ?>">
                <p><strong><?php esc_html_e('Status:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-status"></span></p>
                <p><strong><?php esc_html_e('Processed:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-processed"></span></p>
                <p><strong><?php esc_html_e('Current:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-current-listing"></span></p>
                <p><strong><?php esc_html_e('Last Update:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-last-updated"></span></p>
                <p><strong><?php esc_html_e('Duration:', 'bridge-mls-extractor-pro'); ?></strong> <span id="bme-live-duration"></span></p>
                <p><strong><?php esc_html_e('Property Types:', 'bridge-mls-extractor-pro'); ?></strong> <br><span id="bme-live-property-types"></span></p>
                <p id="bme-live-message"></p>
                <p id="bme-live-error-message" style="color: red;"></p>
            </div>
            <div id="bme-live-progress-not-running" style="<?php echo $is_running ? 'display: none;' : ''; ?>">
                <p><?php _e('No active extraction running for this profile.', 'bridge-mls-extractor-pro'); ?></p>
            </div>
        </div>
        <script>
            // Pass initial state to JS
            window.bmeLiveProgressInitialState = <?php echo json_encode($progress); ?>;
        </script>
        <?php
    }

    /**
     * Save extraction meta data with enhanced validation
     */
    public static function save_extraction_meta_static($post_id, $post) { // Made static
        if (!isset($_POST['bme_extraction_nonce']) || !wp_verify_nonce($_POST['bme_extraction_nonce'], 'bme_save_extraction_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if ($post->post_type !== 'bme_extraction') return;

        // Get plugin instance via global function
        $plugin_instance = bme_pro();
        $cache_manager = $plugin_instance->get('cache');

        $statuses = isset($_POST['bme_statuses']) && is_array($_POST['bme_statuses']) ? array_map('sanitize_text_field', wp_unslash($_POST['bme_statuses'])) : [];
        $property_types = isset($_POST['bme_property_types']) && is_array($_POST['bme_property_types']) ? array_map('sanitize_text_field', wp_unslash($_POST['bme_property_types'])) : [];

        $active_group = ['Active', 'Active Under Contract', 'Pending'];
        $archived_group = ['Closed', 'Expired', 'Withdrawn', 'Canceled'];

        if (!empty(array_intersect($statuses, $active_group)) && !empty(array_intersect($statuses, $archived_group))) {
            add_settings_error('bme_pro_settings', 'status_conflict', __('Error: You cannot select statuses from both Active and Archived groups in the same extraction.', 'bridge-mls-extractor-pro'), 'error');
            set_transient('settings_errors', get_settings_errors(), 30);
            return;
        }

        $lookback_months = isset($_POST['bme_lookback_months']) ? absint($_POST['bme_lookback_months']) : 0;
        if (!empty(array_intersect($statuses, $archived_group)) && $lookback_months <= 0) {
            add_settings_error('bme_pro_settings', 'lookback_required', __('Error: The "Archived Listings Lookback" is required and must be greater than 0 for the selected statuses.', 'bridge-mls-extractor-pro'), 'error');
            set_transient('settings_errors', get_settings_errors(), 30);
            return;
        }

        update_post_meta($post_id, '_bme_statuses', $statuses);
        update_post_meta($post_id, '_bme_property_types', $property_types);
        update_post_meta($post_id, '_bme_lookback_months', $lookback_months);

        $fields_to_save = [
            '_bme_schedule' => 'sanitize_key',
            '_bme_cities' => 'sanitize_textarea_field',
            '_bme_list_agent_id' => 'sanitize_text_field',
            '_bme_buyer_agent_id' => 'sanitize_text_field',
        ];

        foreach ($fields_to_save as $meta_key => $sanitize_callback) {
            $post_key = str_replace('_bme_', 'bme_', $meta_key);
            if (isset($_POST[$post_key])) {
                update_post_meta($post_id, $meta_key, call_user_func($sanitize_callback, $_POST[$post_key]));
            }
        }

        $states = isset($_POST['bme_states']) && is_array($_POST['bme_states']) ? array_map('sanitize_text_field', wp_unslash($_POST['bme_states'])) : [];
        update_post_meta($post_id, '_bme_states', $states);

        $cache_manager->delete('extraction_stats_' . $post_id); // Use the retrieved cache_manager instance
    }

    /**
     * Set custom columns for extraction list
     */
    public function set_extraction_columns($columns) {
        return [
            'cb' => $columns['cb'],
            'title' => $columns['title'],
            'schedule' => __('Schedule', 'bridge-mls-extractor-pro'),
            'listings_count' => __('Listings', 'bridge-mls-extractor-pro'),
            'last_run' => __('Last Run', 'bridge-mls-extractor-pro'),
            'performance' => __('Performance', 'bridge-mls-extractor-pro'),
            'actions' => __('Actions', 'bridge-mls-extractor-pro'),
            'date' => $columns['date']
        ];
    }

    /**
     * Display custom column content
     */
    public function display_extraction_column($column, $post_id) {
        switch ($column) {
            case 'schedule':
                $schedule_key = get_post_meta($post_id, '_bme_schedule', true) ?: 'none';
                if ($schedule_key === 'none') {
                    echo '<span class="bme-schedule-disabled">' . esc_html__('Manual', 'bridge-mls-extractor-pro') . '</span>';
                } else {
                    $schedules = wp_get_schedules();
                    $display = $schedules[$schedule_key]['display'] ?? ucfirst($schedule_key);
                    echo '<span class="bme-schedule-active">' . esc_html($display) . '</span>';
                }
                break;
            case 'listings_count':
                $stats = $this->cache_manager->get_extraction_stats($post_id);
                if (!$stats) {
                    $data_processor = $this->plugin->get('processor');
                    $stats = $data_processor->get_extraction_stats($post_id);
                    $this->cache_manager->cache_extraction_stats($post_id, $stats);
                }
                echo '<strong>' . esc_html(number_format($stats['total_listings'] ?? 0)) . '</strong>';
                break;
            case 'last_run':
                $status = get_post_meta($post_id, '_bme_last_run_status', true);
                $time = get_post_meta($post_id, '_bme_last_run_time', true);
                if ($status && $time) {
                    printf(
                        '<div class="bme-last-run %s"><strong>%s</strong><br><small>%s ago</small></div>',
                        esc_attr($this->get_status_class($status)),
                        esc_html($status),
                        esc_html(human_time_diff($time))
                    );
                } else {
                    echo '<span class="bme-never">' . esc_html__('Never', 'bridge-mls-extractor-pro') . '</span>';
                }
                break;
            case 'performance':
                $duration = (float) get_post_meta($post_id, '_bme_last_run_duration', true);
                $count = (int) get_post_meta($post_id, '_bme_last_run_count', true);
                
                // Debug logging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("BME Performance Column Debug - Post ID: {$post_id}, Duration: '{$duration}', Count: '{$count}'");
                }
                
                if ($duration > 0 && $count > 0) {
                    $rate = $count / $duration;
                    printf(
                        '<div class="bme-performance"><strong>%.1fs</strong><br><small>%.1f listings/sec</small></div>',
                        esc_html($duration),
                        esc_html($rate)
                    );
                } else {
                    // Show debug info when values are missing
                    if (defined('WP_DEBUG') && WP_DEBUG && (empty($duration) || empty($count))) {
                        printf(
                            '<div class="bme-performance-debug" title="Debug: duration=%s, count=%s">—</div>',
                            esc_attr($duration ?: 'empty'),
                            esc_attr($count ?: 'empty')
                        );
                    } else {
                        echo '—';
                    }
                }
                break;
            case 'actions':
                $run_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_extraction&post_id=' . $post_id), 'bme_run_extraction_' . $post_id);
                printf('<a href="%s" class="button button-small button-primary">%s</a>', esc_url($run_url), esc_html__('Run', 'bridge-mls-extractor-pro'));
                break;
        }
    }

    /**
     * Get CSS class for status
     */
    private function get_status_class($status) {
        $status_slug = strtolower(str_replace(' ', '-', $status ?? ''));
        return 'bme-status-' . sanitize_html_class($status_slug, 'unknown');
    }

    /**
     * Process bulk actions from the Database Browser list table.
     */
    public function handle_database_browser_bulk_actions() {
        require_once BME_PLUGIN_DIR . 'includes/class-bme-listings-list-table.php';

        $list_table = new BME_Advanced_Listings_List_Table($this->plugin);
        $current_action = $list_table->current_action();

        if ($current_action !== 'export_selected') {
            return;
        }

        check_admin_referer('bulk-listings');

        if (empty($_POST['bme_listings'])) {
            wp_redirect(add_query_arg('message', 'no_listings_selected', wp_get_referer()));
            exit;
        }

        $listing_ids = array_map('absint', $_POST['bme_listings']);

        $this->run_export($listing_ids);
    }

    /**
     * Render database browser page
     */
    public function render_database_browser() {
        try {
            $this->plugin->get('db')->verify_installation();
        } catch (Exception $e) {
            // Try to create tables with detailed error logging
            try {
                error_log('BME: Database verification failed, attempting table creation: ' . $e->getMessage());
                $this->plugin->get('db')->create_tables();
                $this->plugin->get('db')->verify_installation();
                // If we get here, the tables were created successfully
                echo '<div class="wrap"><h1>' . esc_html__('Database Update Complete', 'bridge-mls-extractor-pro') . '</h1>';
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Database tables have been successfully created/updated. Please refresh this page to continue.', 'bridge-mls-extractor-pro');
                echo '</p></div></div>';
                return;
            } catch (Exception $create_error) {
                error_log('BME: Failed to create tables: ' . $create_error->getMessage());
                echo '<div class="wrap"><h1>' . esc_html__('Database Error', 'bridge-mls-extractor-pro') . '</h1>';
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo '<strong>' . esc_html__('Plugin database tables are missing or out of date.', 'bridge-mls-extractor-pro') . '</strong><br>';
                echo esc_html__('The plugin has attempted an automatic update. Please refresh this page. If the error persists, please try deactivating and reactivating the plugin.', 'bridge-mls-extractor-pro');
                echo '<br><strong>Error details:</strong> ' . esc_html($create_error->getMessage());
                echo '</p></div></div>';
                return;
            }
        }

        require_once BME_PLUGIN_DIR . 'includes/class-bme-listings-list-table.php';

        $list_table = new BME_Advanced_Listings_List_Table($this->plugin);
        $list_table->prepare_items();

        $raw_filters = $list_table->get_filters_from_request();
        $filters_for_url = [];
        foreach($raw_filters as $key => $value) {
            $filters_for_url['filter_' . $key] = $value;
        }
        if (isset($_REQUEST['s'])) {
            $filters_for_url['s'] = $_REQUEST['s'];
        }

        $_SERVER['REQUEST_URI'] = add_query_arg($filters_for_url, $_SERVER['REQUEST_URI']);

        $current_dataset = $raw_filters['dataset'] ?? 'active';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Database Browser', 'bridge-mls-extractor-pro'); ?></h1>
            <hr class="wp-header-end">

            <form method="post">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                <?php wp_nonce_field('bme_database_browser_filter', 'bme_filter_nonce'); ?>

                <div class="bme-filters-panel">
                    <div class="bme-filters-row">
                        <div class="bme-filter-group">
                            <label for="filter_dataset"><?php esc_html_e('Dataset', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_dataset" id="filter_dataset">
                                <option value="active" <?php selected($current_dataset, 'active'); ?>><?php esc_html_e('Active Listings', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="closed" <?php selected($current_dataset, 'closed'); ?>><?php esc_html_e('Closed/Off-Market', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="all" <?php selected($current_dataset, 'all'); ?>><?php esc_html_e('All Listings', 'bridge-mls-extractor-pro'); ?></option>
                            </select>
                        </div>
                        <div class="bme-filter-group">
                            <label for="filter_standard_status"><?php esc_html_e('Status', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_standard_status" id="filter_standard_status" class="bme-filter-select" data-placeholder="<?php esc_attr_e('All Statuses', 'bridge-mls-extractor-pro'); ?>">
                                <option value=""></option>
                                <?php echo $this->render_filter_options('standard_status', $raw_filters['standard_status'] ?? ''); ?>
                            </select>
                        </div>
                        <div class="bme-filter-group">
                            <label for="filter_property_type"><?php esc_html_e('Property Type', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_property_type" id="filter_property_type" class="bme-filter-select" data-placeholder="<?php esc_attr_e('All Types', 'bridge-mls-extractor-pro'); ?>">
                                <option value=""></option>
                                <?php echo $this->render_filter_options('property_type', $raw_filters['property_type'] ?? ''); ?>
                            </select>
                        </div>
                        <div class="bme-filter-group">
                            <label for="filter_city"><?php esc_html_e('City', 'bridge-mls-extractor-pro'); ?></label>
                            <select name="filter_city" id="filter_city" class="bme-filter-select" data-placeholder="<?php esc_attr_e('All Cities', 'bridge-mls-extractor-pro'); ?>">
                                <option value=""></option>
                                <?php echo $this->render_filter_options('city', $raw_filters['city'] ?? ''); ?>
                            </select>
                        </div>
                    </div>

                    <!-- Advanced Filters Toggle -->
                    <div class="bme-advanced-filters-toggle">
                        <button type="button" class="button bme-toggle-advanced-filters">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                            <?php esc_html_e('Advanced Filters', 'bridge-mls-extractor-pro'); ?>
                        </button>
                    </div>

                    <!-- Advanced Filters Section -->
                    <div class="bme-advanced-filters" style="display: none;">
                        <div class="bme-filters-row">
                            <!-- MLS Number -->
                            <div class="bme-filter-group">
                                <label for="filter_listing_id"><?php esc_html_e('MLS Number', 'bridge-mls-extractor-pro'); ?></label>
                                <input type="text" name="filter_listing_id" id="filter_listing_id"
                                       value="<?php echo esc_attr($raw_filters['listing_id'] ?? ''); ?>"
                                       placeholder="<?php esc_attr_e('e.g., 73425579', 'bridge-mls-extractor-pro'); ?>"
                                       class="regular-text">
                            </div>

                            <!-- Price Range -->
                            <div class="bme-filter-group">
                                <label for="filter_price_min"><?php esc_html_e('Price Min', 'bridge-mls-extractor-pro'); ?></label>
                                <input type="number" name="filter_price_min" id="filter_price_min"
                                       value="<?php echo esc_attr($raw_filters['price_min'] ?? ''); ?>"
                                       placeholder="<?php esc_attr_e('Min Price', 'bridge-mls-extractor-pro'); ?>"
                                       class="regular-text" min="0" step="1000">
                            </div>

                            <div class="bme-filter-group">
                                <label for="filter_price_max"><?php esc_html_e('Price Max', 'bridge-mls-extractor-pro'); ?></label>
                                <input type="number" name="filter_price_max" id="filter_price_max"
                                       value="<?php echo esc_attr($raw_filters['price_max'] ?? ''); ?>"
                                       placeholder="<?php esc_attr_e('Max Price', 'bridge-mls-extractor-pro'); ?>"
                                       class="regular-text" min="0" step="1000">
                            </div>
                        </div>

                        <div class="bme-filters-row">
                            <!-- Bedrooms Min -->
                            <div class="bme-filter-group">
                                <label for="filter_bedrooms_min"><?php esc_html_e('Min Bedrooms', 'bridge-mls-extractor-pro'); ?></label>
                                <select name="filter_bedrooms_min" id="filter_bedrooms_min">
                                    <option value=""><?php esc_html_e('Any', 'bridge-mls-extractor-pro'); ?></option>
                                    <?php for ($i = 1; $i <= 6; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($raw_filters['bedrooms_min'] ?? '', $i); ?>>
                                            <?php echo $i . ($i === 6 ? '+' : ''); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <!-- Bathrooms Min -->
                            <div class="bme-filter-group">
                                <label for="filter_bathrooms_min"><?php esc_html_e('Min Bathrooms', 'bridge-mls-extractor-pro'); ?></label>
                                <select name="filter_bathrooms_min" id="filter_bathrooms_min">
                                    <option value=""><?php esc_html_e('Any', 'bridge-mls-extractor-pro'); ?></option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($raw_filters['bathrooms_min'] ?? '', $i); ?>>
                                            <?php echo $i . ($i === 5 ? '+' : ''); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <!-- Days on Market Max -->
                            <div class="bme-filter-group">
                                <label for="filter_days_on_market_max"><?php esc_html_e('Max Days on Market', 'bridge-mls-extractor-pro'); ?></label>
                                <select name="filter_days_on_market_max" id="filter_days_on_market_max">
                                    <option value=""><?php esc_html_e('Any', 'bridge-mls-extractor-pro'); ?></option>
                                    <option value="7" <?php selected($raw_filters['days_on_market_max'] ?? '', '7'); ?>>7 days</option>
                                    <option value="14" <?php selected($raw_filters['days_on_market_max'] ?? '', '14'); ?>>14 days</option>
                                    <option value="30" <?php selected($raw_filters['days_on_market_max'] ?? '', '30'); ?>>30 days</option>
                                    <option value="60" <?php selected($raw_filters['days_on_market_max'] ?? '', '60'); ?>>60 days</option>
                                    <option value="90" <?php selected($raw_filters['days_on_market_max'] ?? '', '90'); ?>>90 days</option>
                                    <option value="180" <?php selected($raw_filters['days_on_market_max'] ?? '', '180'); ?>>6 months</option>
                                    <option value="365" <?php selected($raw_filters['days_on_market_max'] ?? '', '365'); ?>>1 year</option>
                                </select>
                            </div>
                        </div>

                        <div class="bme-filters-row">
                            <!-- Year Built Range -->
                            <div class="bme-filter-group">
                                <label for="filter_year_built_min"><?php esc_html_e('Year Built Min', 'bridge-mls-extractor-pro'); ?></label>
                                <input type="number" name="filter_year_built_min" id="filter_year_built_min"
                                       value="<?php echo esc_attr($raw_filters['year_built_min'] ?? ''); ?>"
                                       placeholder="<?php esc_attr_e('e.g., 1900', 'bridge-mls-extractor-pro'); ?>"
                                       class="regular-text" min="1800" max="<?php echo date('Y'); ?>">
                            </div>

                            <div class="bme-filter-group">
                                <label for="filter_year_built_max"><?php esc_html_e('Year Built Max', 'bridge-mls-extractor-pro'); ?></label>
                                <input type="number" name="filter_year_built_max" id="filter_year_built_max"
                                       value="<?php echo esc_attr($raw_filters['year_built_max'] ?? ''); ?>"
                                       placeholder="<?php esc_attr_e('e.g., ' . date('Y'), 'bridge-mls-extractor-pro'); ?>"
                                       class="regular-text" min="1800" max="<?php echo date('Y'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="bme-filters-actions">
                        <?php $list_table->search_box(__('Search Address, MLS#, Agent...', 'bridge-mls-extractor-pro'), 'bme-listing-search'); ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'bridge-mls-extractor-pro'); ?></button>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=bme_extraction&page=bme-database-browser')); ?>" class="button"><?php esc_html_e('Clear', 'bridge-mls-extractor-pro'); ?></a>
                        <button type="button" class="button button-secondary bme-export-filtered-btn">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                            <?php esc_html_e('Export All Filtered', 'bridge-mls-extractor-pro'); ?>
                        </button>
                    </div>
                </div>

                <?php $list_table->display(); ?>
            </form>

            <!-- Export Modal -->
            <div id="bme-export-modal" class="bme-modal" style="display: none;">
                <div class="bme-modal-backdrop"></div>
                <div class="bme-modal-dialog">
                    <div class="bme-modal-header">
                        <h2><?php esc_html_e('Export Listings', 'bridge-mls-extractor-pro'); ?></h2>
                        <button type="button" class="bme-modal-close">&times;</button>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bme-export-form">
                        <input type="hidden" name="action" value="bme_export_listings_csv">
                        <?php wp_nonce_field('bme_export_listings_csv_nonce', 'bme_export_nonce'); ?>

                        <!-- Copy current filters to export form -->
                        <div id="bme-export-hidden-filters"></div>

                        <div class="bme-modal-body">
                            <p><?php esc_html_e('Export all listings matching current filters.', 'bridge-mls-extractor-pro'); ?></p>

                            <div class="bme-export-info">
                                <strong><?php esc_html_e('Current Filters:', 'bridge-mls-extractor-pro'); ?></strong>
                                <ul id="bme-export-filter-summary"></ul>
                            </div>

                            <div class="bme-export-options">
                                <h3><?php esc_html_e('Export Options', 'bridge-mls-extractor-pro'); ?></h3>
                                <label>
                                    <input type="radio" name="bme_export_format" value="csv" checked>
                                    <?php esc_html_e('CSV (Comma-Separated Values)', 'bridge-mls-extractor-pro'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Compatible with Excel, Google Sheets, and most database applications.', 'bridge-mls-extractor-pro'); ?></p>
                            </div>
                        </div>

                        <div class="bme-modal-footer">
                            <button type="button" class="button bme-modal-close"><?php esc_html_e('Cancel', 'bridge-mls-extractor-pro'); ?></button>
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Download Export', 'bridge-mls-extractor-pro'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render filter options with caching
     */
    private function render_filter_options($field, $current_value) {
        $values = $this->cache_manager->get_filter_values($field);

        $options = '';
        if (is_array($values)) {
            foreach ($values as $value) {
                $options .= sprintf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current_value, $value, false), esc_html($value));
            }
        }
        return $options;
    }

    /**
     * Handle admin actions
     */
    private function handle_admin_action($action_name, $nonce_action, $success_message, $fail_message, $is_resync = false) {
        if (!isset($_GET['post_id'])) {
            // For VT import, post_id is not required, check for specific action
            if ($action_name === 'run_vt_import') {
                if (!current_user_can('manage_options') || !check_admin_referer($nonce_action)) {
                    wp_die('Invalid request.');
                }
                $this->plugin->get('vt_importer')->import_virtual_tours(); // This method now sets its own transient messages
                $redirect_url = admin_url('admin.php?page=bme-settings');
                wp_redirect($redirect_url); // Redirect without specific success/fail messages here, as they come from transient
                exit;
            }
            wp_die('Missing post ID.');
        }

        $post_id = absint($_GET['post_id']);
        if (!$post_id || !current_user_can('edit_post', $post_id) || !check_admin_referer($nonce_action . '_' . $post_id)) {
            wp_die('Invalid request.');
        }

        $success = false;
        $redirect_url = admin_url('edit.php?post_type=bme_extraction');

        switch($action_name) {
            case 'run_extraction':
                $success = $this->plugin->get('extractor')->run_extraction($post_id, $is_resync);
                break;
            case 'clear_data':
                $cleared = $this->plugin->get('processor')->clear_extraction_data($post_id);
                update_post_meta($post_id, '_bme_last_modified', '1970-01-01T00:00:00Z');
                $this->cache_manager->delete('extraction_stats_' . $post_id);
                $success = true;
                $success_message = sprintf(__('Data cleared. %d listings removed.', 'bridge-mls-extractor-pro'), $cleared);
                break;
            case 'test_config':
                 $result = $this->plugin->get('extractor')->test_extraction_config($post_id);
                 $success = $result['success'];
                 $redirect_url = admin_url('post.php?post=' . $post_id . '&action=edit');
                 $fail_message = $result['error'] ?? $fail_message;
                 break;
        }

        wp_redirect(add_query_arg('message', $success ? $success_message : $fail_message, $redirect_url));
        exit;
    }

    public function handle_run_extraction() { $this->handle_admin_action('run_extraction', 'bme_run_extraction', 'extraction_success', 'extraction_failed', false); }
    public function handle_run_resync() { $this->handle_admin_action('run_extraction', 'bme_run_resync', 'resync_success', 'resync_failed', true); }
    public function handle_clear_data() { $this->handle_admin_action('clear_data', 'bme_clear_data', 'data_cleared', 'clear_failed'); }
    public function handle_test_config() { $this->handle_admin_action('test_config', 'bme_test_config', 'config_valid', 'config_invalid'); }
    public function handle_run_vt_import() { $this->handle_admin_action('run_vt_import', 'bme_run_vt_import', 'vt_import_success', 'vt_import_failed'); }

    /**
     * Centralized export function. Can be called for selected IDs or with filters.
     */
    private function run_export($listing_ids = [], $filters = [], $selected_columns = []) {
        set_time_limit(0);

        // Only increase memory limit if current limit is lower than required
        $current_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $required_limit = wp_convert_hr_to_bytes('512M');
        if ($current_limit < $required_limit) {
            ini_set('memory_limit', '512M');
        }

        $data_processor = $this->plugin->get('processor');

        if (empty($selected_columns)) {
            $selected_columns = array_keys($data_processor->get_all_listing_columns());
        }

        $listings = [];
        if (!empty($listing_ids)) {
            // Export selected listings - get comprehensive data
            $listings = $data_processor->get_listings_by_ids($listing_ids, $selected_columns);
        } elseif (!empty($filters)) {
            // Export filtered listings - get IDs first, then comprehensive data
            // Step 1: Get all matching listing IDs using search_listings
            $search_results = $data_processor->search_listings($filters, -1, 0, 'modification_timestamp', 'DESC');

            // Step 2: Extract the IDs from search results
            $matching_ids = array_column($search_results, 'id');

            // Step 3: Get comprehensive data for these IDs from all tables
            if (!empty($matching_ids)) {
                $listings = $data_processor->get_listings_by_ids($matching_ids, $selected_columns);
            }
        }

        header('Content-Type: text/csv; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename="mls-listings-export-' . date('Ymd-His') . '.csv"');
        $output = fopen('php://output', 'w');

        $all_columns_map = $data_processor->get_all_listing_columns();
        $header_row = array_map(fn($col_key) => $all_columns_map[$col_key] ?? ucfirst(str_replace('_', ' ', $col_key)), $selected_columns);
        fputcsv($output, $header_row);

        $rows_exported = 0;
        foreach ($listings as $listing) {
            $row = [];
            foreach ($selected_columns as $col_key) {
                $value = $listing[$col_key] ?? '';

                // Handle array values
                if (is_array($value)) {
                    // Empty arrays become empty strings
                    if (empty($value)) {
                        $row[] = '';
                    } else {
                        // Non-empty arrays become comma-separated values
                        $row[] = implode(', ', $value);
                    }
                } else {
                    // Check if value is a JSON-encoded array string
                    if (is_string($value) && strlen($value) > 0 && $value[0] === '[') {
                        $decoded = json_decode($value, true);
                        if (is_array($decoded)) {
                            // Empty arrays become empty strings
                            $row[] = empty($decoded) ? '' : implode(', ', $decoded);
                        } else {
                            $row[] = $value;
                        }
                    } else {
                        $row[] = $value;
                    }
                }
            }
            fputcsv($output, $row);
            $rows_exported++;
        }

        fclose($output);
        exit;
    }

    /**
     * Handles the "Export All Filtered" button submission from admin-post.php
     */
    public function handle_export_listings_csv() {
        if (!current_user_can('manage_options') || !isset($_POST['bme_export_nonce']) || !wp_verify_nonce($_POST['bme_export_nonce'], 'bme_export_listings_csv_nonce')) {
            wp_die('Permission denied.');
        }

        $filters = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'filter_') === 0 && !empty($value)) {
                $filters[str_replace('filter_', '', $key)] = sanitize_text_field($value);
            }
        }
        if (isset($_POST['s']) && !empty($_POST['s'])) {
            $filters['search_query'] = sanitize_text_field($_POST['s']);
        }

        $selected_columns = isset($_POST['bme_export_columns']) && is_array($_POST['bme_export_columns']) ? array_map('sanitize_text_field', $_POST['bme_export_columns']) : [];

        $this->run_export([], $filters, $selected_columns);
    }

    /**
     * Enhanced AJAX handler for getting filter values (cascading filters)
     */
    public function ajax_get_filter_values() {
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Check nonce for security
        $nonce = $_POST['nonce'] ?? '';
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'bme_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        try {
            $field = sanitize_text_field($_POST['field'] ?? '');

            if (empty($field)) {
                wp_send_json_error('Field parameter is required');
                return;
            }

            // Get distinct values directly from database
            global $wpdb;
            $tables = $this->plugin->get('db')->get_tables();

            // Map fields to their table and column
            $field_mapping = [
                'city' => ['table' => 'listing_location', 'column' => 'city'],
                'subdivision' => ['table' => 'listing_location', 'column' => 'subdivision_name'],
                'mls_area' => ['table' => 'listing_location', 'column' => 'mls_area_major'],
                'property_type' => ['table' => 'listings', 'column' => 'property_type'],
                'property_sub_type' => ['table' => 'listings', 'column' => 'property_sub_type'],
            ];

            if (!isset($field_mapping[$field])) {
                wp_send_json_error('Invalid field');
                return;
            }

            $mapping = $field_mapping[$field];
            $table = $tables[$mapping['table']];
            $column = $mapping['column'];

            $options = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT %i FROM %i WHERE %i IS NOT NULL AND %i != '' ORDER BY %i ASC LIMIT 500",
                $column, $table, $column, $column, $column
            ));

            wp_send_json_success($options ?: []);

        } catch (Exception $e) {
            error_log('BME: Filter values error: ' . $e->getMessage());
            wp_send_json_error('Error getting filter values: ' . $e->getMessage());
        }
    }

    public function ajax_search_listings() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        $filters = isset($_POST['filters']) ? wp_unslash($_POST['filters']) : [];
        $page = absint($_POST['page'] ?? 1);
        $per_page = 30;
        $offset = ($page - 1) * $per_page;

        $data_processor = $this->plugin->get('processor');
        $results = $data_processor->search_listings($filters, $per_page, $offset);
        $total = $data_processor->get_search_count($filters);

        wp_send_json_success(['listings' => $results, 'total' => $total]);
    }

    public function ajax_get_extraction_stats() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        $extraction_id = absint($_POST['extraction_id'] ?? 0);
        if (!$extraction_id) wp_send_json_error();

        $stats = $this->cache_manager->get_extraction_stats($extraction_id);
        if (!$stats) {
            $data_processor = $this->plugin->get('processor');
            $stats = $data_processor->get_extraction_stats($extraction_id);
            $this->cache_manager->cache_extraction_stats($extraction_id, $stats);
        }
        wp_send_json_success($stats);
    }

    /**
     * AJAX handler for live search suggestions.
     */
    public function ajax_live_search() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        if (strlen($term) < 3) {
            wp_send_json_success([]); // Return empty for short terms
            return;
        }

        $data_processor = $this->plugin->get('processor');
        $suggestions = $data_processor->live_search_suggestions($term);

        wp_send_json_success($suggestions);
    }

    /**
     * New AJAX handler to get live extraction progress.
     */
    public function ajax_get_live_extraction_progress() {
        check_ajax_referer('bme_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $extraction_id = absint($_POST['extraction_id'] ?? 0);
        if (!$extraction_id) {
            wp_send_json_error('Missing extraction ID.');
        }

        $progress = $this->plugin->get('extractor')->get_live_progress($extraction_id);

        if ($progress) {
            wp_send_json_success($progress);
        } else {
            // If no progress found, assume not running or completed/cleared
            wp_send_json_success(['status' => 'not_running', 'message' => __('No active extraction found.', 'bridge-mls-extractor-pro')]);
        }
    }

    /**
     * Display general admin notices (e.g., from other plugin functions).
     */
    public function display_admin_notices() {
        if ($errors = get_transient('settings_errors')) {
            foreach ($errors as $error) {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($error['type']), esc_html($error['message']));
            }
            delete_transient('settings_errors');
        }

        if (isset($_GET['message'])) {
            $messages = [
                'extraction_success' => ['success', __('Extraction completed successfully.', 'bridge-mls-extractor-pro')],
                'extraction_failed' => ['error', __('Extraction failed. Check logs.', 'bridge-mls-extractor-pro')],
                'resync_success' => ['success', __('Full resync completed.', 'bridge-mls-extractor-pro')],
                'data_cleared' => ['success', sprintf(__('Data cleared. %d listings removed.', 'bridge-mls-extractor-pro'), absint($_GET['count'] ?? 0))], // Added count
                'clear_failed' => ['error', __('Failed to clear data.', 'bridge-mls-extractor-pro')],
                'config_valid' => ['success', __('Configuration is valid.', 'bridge-mls-extractor-pro')],
                'config_invalid' => ['error', __('Configuration has errors. ' . (isset($_GET['test_result']) ? base64_decode($_GET['test_result']) : ''), 'bridge-mls-extractor-pro')],
                'no_listings_selected' => ['warning', __('You did not select any listings to export.', 'bridge-mls-extractor-pro')],
            ];

            $message_key = sanitize_key($_GET['message']);
            if (isset($messages[$message_key])) {
                [$type, $text] = $messages[$message_key];
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
            }
        }
    }

    /**
     * Display specific admin notices for Virtual Tour import.
     */
    public function display_vt_import_notices() {
        if ($message = get_transient('bme_pro_vt_import_success_message')) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($message));
            delete_transient('bme_pro_vt_import_success_message');
        }
        if ($message = get_transient('bme_pro_vt_import_error_message')) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($message));
            delete_transient('bme_pro_vt_import_error_message');
        }
    }

    /**
     * Register and sanitize settings
     */
    public function register_settings() {
        register_setting('bme_pro_settings', 'bme_pro_api_credentials', [$this, 'sanitize_api_credentials']);
        register_setting('bme_pro_settings', 'bme_pro_performance_settings', [$this, 'sanitize_performance_settings']);
        register_setting('bme_pro_data_settings', 'bme_pro_delete_on_deactivation', 'boolval');
        register_setting('bme_pro_data_settings', 'bme_pro_delete_on_uninstall', 'boolval');
        register_setting('bme_pro_settings', 'bme_pro_vt_file_url', 'esc_url_raw');
    }

    public function sanitize_api_credentials($input) {
        $sanitized_input = [];
        $sanitized_input['server_token'] = sanitize_text_field($input['server_token'] ?? '');
        $sanitized_input['endpoint_url'] = esc_url_raw($input['endpoint_url'] ?? '');
        return $sanitized_input;
    }

    public function sanitize_performance_settings($input) {
        $sanitized_input = [];
        $sanitized_input['api_timeout'] = max(30, absint($input['api_timeout'] ?? 60));
        $sanitized_input['batch_size'] = max(10, min(500, absint($input['batch_size'] ?? 100)));
        $sanitized_input['cache_duration'] = max(300, absint($input['cache_duration'] ?? 3600));
        return $sanitized_input;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BME Pro Settings', 'bridge-mls-extractor-pro'); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('bme_pro_settings');
                $api_credentials = get_option('bme_pro_api_credentials', []);
                $perf_settings = get_option('bme_pro_performance_settings', []);
                $vt_file_url = get_option('bme_pro_vt_file_url', '');
                ?>
                <h2><?php esc_html_e('API Credentials', 'bridge-mls-extractor-pro'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bme_server_token"><?php esc_html_e('API Server Token', 'bridge-mls-extractor-pro'); ?></label></th>
                        <td><input type="password" id="bme_server_token" name="bme_pro_api_credentials[server_token]" value="<?php echo esc_attr($api_credentials['server_token'] ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bme_endpoint_url"><?php esc_html_e('API Endpoint URL', 'bridge-mls-extractor-pro'); ?></label></th>
                        <td><input type="url" id="bme_endpoint_url" name="bme_pro_api_credentials[endpoint_url]" value="<?php echo esc_attr($api_credentials['endpoint_url'] ?? ''); ?>" class="large-text"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Performance', 'bridge-mls-extractor-pro'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bme_batch_size"><?php esc_html_e('Batch Size', 'bridge-mls-extractor-pro'); ?></label></th>
                        <td><input type="number" id="bme_batch_size" name="bme_pro_performance_settings[batch_size]" value="<?php echo esc_attr($perf_settings['batch_size'] ?? 100); ?>" class="small-text">
                        <p class="description"><?php _e('Number of listings to fetch per API request (Default: 100).', 'bridge-mls-extractor-pro'); ?></p></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Virtual Tour File Import', 'bridge-mls-extractor-pro'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bme_pro_vt_file_url"><?php esc_html_e('Virtual Tour File URL', 'bridge-mls-extractor-pro'); ?></label></th>
                        <td>
                            <input type="url" id="bme_pro_vt_file_url" name="bme_pro_vt_file_url" value="<?php echo esc_attr($vt_file_url); ?>" class="large-text">
                            <p class="description">
                                <?php _e('Enter the direct URL to your MLS Virtual Tour text file (e.g., `https://idx.mlspin.com/...&filetype=VT`).', 'bridge-mls-extractor-pro'); ?><br>
                                <strong><?php _e('Important:', 'bridge-mls-extractor-pro'); ?></strong> <?php _e('If your MLS password changes and affects this URL, you must update it here for virtual tours to continue syncing.', 'bridge-mls-extractor-pro'); ?><br>
                                <?php _e('This file is automatically imported daily via cron, but you can manually trigger it below.', 'bridge-mls-extractor-pro'); ?>
                            </p>
                            <?php
                            $run_vt_import_url = wp_nonce_url(admin_url('admin-post.php?action=bme_run_vt_import'), 'bme_run_vt_import');
                            ?>
                            <a href="<?php echo esc_url($run_vt_import_url); ?>" class="button button-secondary"><?php _e('Manually Import Virtual Tours Now', 'bridge-mls-extractor-pro'); ?></a>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <form method="post" action="options.php">
                <?php
                settings_fields('bme_pro_data_settings');
                $delete_on_deactivation = get_option('bme_pro_delete_on_deactivation', false);
                $delete_on_uninstall = get_option('bme_pro_delete_on_uninstall', false);
                ?>
                <h2><?php esc_html_e('Data Management', 'bridge-mls-extractor-pro'); ?></h2>
                <table class="form-table">
                    <tr style="background-color: #fffbe5;">
                        <th scope="row">
                            <label for="bme_delete_on_deactivation"><?php esc_html_e('Cleanup on Deactivation', 'bridge-mls-extractor-pro'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label for="bme_delete_on_deactivation">
                                    <input type="checkbox" name="bme_pro_delete_on_deactivation" id="bme_delete_on_deactivation" value="1" <?php checked($delete_on_deactivation, true); ?>>
                                    <strong style="color: #dc3232;"><?php esc_html_e('Delete all plugin data upon deactivation.', 'bridge-mls-extractor-pro'); ?></strong>
                                </label>
                                <p class="description"><?php _e('Warning: This is a destructive action. All data will be deleted when you deactivate the plugin.', 'bridge-mls-extractor-pro'); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="bme_delete_on_uninstall"><?php esc_html_e('Cleanup on Deletion', 'bridge-mls-extractor-pro'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label for="bme_delete_on_uninstall">
                                    <input type="checkbox" name="bme_pro_delete_on_uninstall" id="bme_delete_on_uninstall" value="1" <?php checked($delete_on_uninstall, true); ?>>
                                    <?php esc_html_e('Delete all plugin data when the plugin is deleted from the WordPress admin.', 'bridge-mls-extractor-pro'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Data Settings', 'bridge-mls-extractor-pro')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render comprehensive monitoring dashboard
     */
    public function render_performance_dashboard() {
        $analytics = $this->get_extraction_analytics();
        $error_stats = $this->get_error_statistics();
        $system_health = $this->get_system_health_metrics();
        $recent_activities = $this->get_recent_extraction_activities();
        
        ?>
        <div class="wrap bme-monitoring-dashboard">
            <h1><?php _e('Extraction Monitoring Dashboard', 'bridge-mls-extractor-pro'); ?></h1>
            
            <!-- Summary Cards -->
            <div class="bme-dashboard-cards">
                <div class="bme-card bme-card-success">
                    <h3><?php _e('Total Extractions', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo number_format($analytics['total_extractions']); ?></div>
                    <div class="bme-card-subtitle"><?php echo $analytics['active_extractions']; ?> active</div>
                </div>
                
                <div class="bme-card bme-card-info">
                    <h3><?php _e('Properties Processed', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo number_format($analytics['total_properties']); ?></div>
                    <div class="bme-card-subtitle">Last 30 days: <?php echo number_format($analytics['properties_30d']); ?></div>
                </div>
                
                <div class="bme-card bme-card-warning">
                    <h3><?php _e('API Requests Today', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo number_format($analytics['api_requests_today']); ?></div>
                    <div class="bme-card-subtitle">Rate: <?php echo $analytics['avg_requests_per_hour']; ?>/hour</div>
                </div>
                
                <div class="bme-card <?php echo $error_stats['total_errors_24h'] > 0 ? 'bme-card-error' : 'bme-card-success'; ?>">
                    <h3><?php _e('Errors (24h)', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo $error_stats['total_errors_24h']; ?></div>
                    <div class="bme-card-subtitle">
                        <?php if ($error_stats['total_errors_24h'] > 0): ?>
                            <?php echo $error_stats['most_common_error']; ?>
                        <?php else: ?>
                            All systems operational
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="bme-dashboard-row">
                <!-- System Health Panel -->
                <div class="bme-dashboard-panel">
                    <h2><?php _e('System Health', 'bridge-mls-extractor-pro'); ?></h2>
                    <div class="bme-health-metrics">
                        <div class="bme-health-item">
                            <span class="bme-health-label"><?php _e('Memory Usage', 'bridge-mls-extractor-pro'); ?></span>
                            <div class="bme-health-bar">
                                <div class="bme-health-bar-fill" style="width: <?php echo $system_health['memory_percent']; ?>%"></div>
                            </div>
                            <span class="bme-health-value"><?php echo $system_health['memory_usage']; ?></span>
                        </div>
                        
                        <div class="bme-health-item">
                            <span class="bme-health-label"><?php _e('Database Size', 'bridge-mls-extractor-pro'); ?></span>
                            <div class="bme-health-bar">
                                <div class="bme-health-bar-fill" style="width: <?php echo min($system_health['db_size_mb'] / 1000 * 100, 100); ?>%"></div>
                            </div>
                            <span class="bme-health-value"><?php echo $system_health['db_size']; ?></span>
                        </div>
                        
                        <div class="bme-health-item">
                            <span class="bme-health-label"><?php _e('API Response Time', 'bridge-mls-extractor-pro'); ?></span>
                            <div class="bme-health-bar">
                                <div class="bme-health-bar-fill bme-health-<?php echo $system_health['api_health_class']; ?>" 
                                     style="width: <?php echo min($system_health['avg_response_time'] / 5 * 100, 100); ?>%"></div>
                            </div>
                            <span class="bme-health-value"><?php echo $system_health['avg_response_time']; ?>s</span>
                        </div>
                        
                        <div class="bme-health-item">
                            <span class="bme-health-label"><?php _e('Last Cron Run', 'bridge-mls-extractor-pro'); ?></span>
                            <span class="bme-health-status bme-status-<?php echo $system_health['cron_status']; ?>">
                                <?php echo $system_health['last_cron']; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Error Breakdown -->
                <div class="bme-dashboard-panel">
                    <h2><?php _e('Error Analysis (7 Days)', 'bridge-mls-extractor-pro'); ?></h2>
                    <?php if (!empty($error_stats['error_breakdown'])): ?>
                        <div class="bme-error-breakdown">
                            <?php foreach ($error_stats['error_breakdown'] as $error_type => $count): ?>
                                <div class="bme-error-item">
                                    <span class="bme-error-type"><?php echo esc_html($error_type); ?></span>
                                    <span class="bme-error-count"><?php echo $count; ?></span>
                                    <div class="bme-error-bar">
                                        <div class="bme-error-bar-fill" 
                                             style="width: <?php echo ($count / max(array_values($error_stats['error_breakdown']))) * 100; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="bme-no-errors"><?php _e('No errors recorded in the last 7 days! 🎉', 'bridge-mls-extractor-pro'); ?></p>
                    <?php endif; ?>
                </div>
                
                <!-- Cron Job Monitoring -->
                <div class="bme-dashboard-panel">
                    <h2><?php _e('System Operations (Cron Jobs)', 'bridge-mls-extractor-pro'); ?></h2>
                    <?php $cron_details = $system_health['cron_details']; ?>
                    
                    <div class="bme-cron-overview">
                        <div class="bme-cron-summary bme-status-<?php echo $cron_details['overall_status']; ?>">
                            <strong><?php echo $cron_details['summary_text']; ?></strong>
                            <?php if ($cron_details['total_scheduled_extractions'] > 0): ?>
                                <span class="bme-cron-info">📋 <?php echo $cron_details['total_scheduled_extractions']; ?> scheduled extractions</span>
                            <?php endif; ?>
                            <?php if ($cron_details['wp_cron_disabled']): ?>
                                <span class="bme-cron-info" title="HTTP-based WP-Cron is disabled. Using system cron (recommended for production).">✅ System Cron Active</span>
                            <?php endif; ?>
                            <?php if ($cron_details['cron_lock_active']): ?>
                                <span class="bme-cron-info">🔄 Cron is currently running</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($cron_details['scheduled_extractions'])): ?>
                            <div class="bme-scheduled-extractions">
                                <h4>Scheduled Extractions:</h4>
                                <ul>
                                    <?php foreach ($cron_details['scheduled_extractions'] as $extraction): ?>
                                        <li><?php echo esc_html($extraction['name']); ?> (<?php echo esc_html($extraction['frequency']); ?>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bme-cron-jobs">
                        <?php foreach ($cron_details['jobs'] as $job): ?>
                            <div class="bme-cron-job bme-status-<?php echo $job['status_class']; ?>">
                                <div class="bme-cron-job-name"><?php echo esc_html($job['display_name']); ?></div>
                                <div class="bme-cron-job-status">
                                    <span class="bme-status-indicator bme-status-<?php echo $job['status_class']; ?>"></span>
                                    <?php echo esc_html($job['next_run_text']); ?>
                                </div>
                                <div class="bme-cron-job-schedule">
                                    <?php echo esc_html($job['schedule_interval']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($cron_details['last_cron_run'] !== 'Never'): ?>
                        <div class="bme-cron-last-run">
                            <small>Last cron execution: <?php echo esc_html($cron_details['last_cron_run']); ?></small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity Timeline -->
            <div class="bme-dashboard-panel">
                <h2><?php _e('Recent Extraction Activity', 'bridge-mls-extractor-pro'); ?></h2>
                <div class="bme-activity-timeline">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="bme-activity-item bme-activity-<?php echo $activity['status']; ?>">
                                <div class="bme-activity-time"><?php echo $activity['time']; ?></div>
                                <div class="bme-activity-content">
                                    <strong><?php echo esc_html($activity['extraction_name']); ?></strong>
                                    <span class="bme-activity-message"><?php echo esc_html($activity['message']); ?></span>
                                    <?php if ($activity['details']): ?>
                                        <div class="bme-activity-details"><?php echo esc_html($activity['details']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p><?php _e('No recent extraction activity found.', 'bridge-mls-extractor-pro'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Performance Charts Section -->
            <div class="bme-dashboard-row">
                <div class="bme-dashboard-panel">
                    <h2><?php _e('Extraction Performance Trends', 'bridge-mls-extractor-pro'); ?></h2>
                    <div id="bme-performance-chart" class="bme-chart-container">
                        <canvas id="bme-performance-canvas"></canvas>
                    </div>
                </div>

                <div class="bme-dashboard-panel">
                    <h2><?php _e('API Usage Pattern', 'bridge-mls-extractor-pro'); ?></h2>
                    <div id="bme-api-usage-chart" class="bme-chart-container">
                        <canvas id="bme-api-usage-canvas"></canvas>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="bme-dashboard-actions">
                <button type="button" class="button button-secondary" onclick="location.reload()">
                    <?php _e('Refresh Dashboard', 'bridge-mls-extractor-pro'); ?>
                </button>
                <button type="button" class="button button-secondary" onclick="bmeExportSystemReport()">
                    <?php _e('Export System Report', 'bridge-mls-extractor-pro'); ?>
                </button>
                <a href="<?php echo admin_url('edit.php?post_type=bme_extraction&page=bme-activity-logs'); ?>" class="button button-primary">
                    <?php _e('View Detailed Logs', 'bridge-mls-extractor-pro'); ?>
                </a>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Initialize charts
            bmeInitPerformanceCharts();
            
            // Auto-refresh every 5 minutes
            setInterval(function() {
                location.reload();
            }, 300000);
        });

        function bmeExportSystemReport() {
            window.location.href = '<?php echo admin_url('admin-post.php?action=bme_export_system_report&nonce=' . wp_create_nonce('bme_export_report')); ?>';
        }

        function bmeInitPerformanceCharts() {
            // Set chart data globally for admin.js to use
            window.bmeChartData = <?php echo json_encode($this->get_dashboard_chart_data()); ?>;
            console.log('BME: Chart data set globally', window.bmeChartData);
            
            // Only render if admin.js hasn't already done it
            if (typeof window.BME !== 'undefined' && typeof window.BME.chartsInitialized !== 'undefined' && window.BME.chartsInitialized) {
                console.log('BME: Charts already initialized by admin.js, skipping inline rendering');
                return;
            }
            
            // Let admin.js handle the chart rendering (but only if it's available)
            if (typeof window.BME !== 'undefined' && typeof window.BME.renderPerformanceCharts === 'function') {
                window.BME.renderPerformanceCharts();
            } else {
                console.log('BME: Admin.js not ready, will be handled by document ready');
            }
        }
        </script>
        <?php
    }
    /**
     * Get comprehensive extraction analytics
     */
    private function get_extraction_analytics() {
        global $wpdb;
        
        try {
            // Get extraction counts
            $extractions = get_posts([
                'post_type' => 'bme_extraction',
                'post_status' => 'any',
                'numberposts' => -1,
                'meta_query' => [
                    [
                        'key' => '_bme_last_run_status',
                        'compare' => 'EXISTS'
                    ]
                ]
            ]);
            
            $total_extractions = count($extractions);
            
            // Count active extractions including batch processing states
            $active_extractions = count(array_filter($extractions, function($post) {
                $status = get_post_meta($post->ID, '_bme_last_run_status', true);
                $batch_state = get_post_meta($post->ID, '_bme_batch_state', true);
                
                // Check for direct running states
                $running_states = ['running', 'Starting', 'Paused - Will Resume'];
                if (in_array($status, $running_states)) {
                    return true;
                }
                
                // Check for active batch processing
                if (!empty($batch_state['is_batch_extraction'])) {
                    return true;
                }
                
                return false;
            }));
            
            // Get database stats for property counts from both active and archive tables
            $db_manager = $this->plugin->get('db');
            $tables = $db_manager->get_tables();
            
            $total_properties = 0;
            $properties_30d = 0;
            
            // Count from active listings table
            if (in_array($wpdb->prefix . 'bme_listings', $tables)) {
                $active_total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings");
                $active_30d = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings 
                    WHERE updated_at >= %s
                ", date('Y-m-d H:i:s', strtotime('-30 days'))));
                
                $total_properties += (int) $active_total;
                $properties_30d += (int) $active_30d;
            }
            
            // Count from archive listings table
            if (in_array($wpdb->prefix . 'bme_listings_archive', $tables)) {
                $archive_total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings_archive");
                $archive_30d = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$wpdb->prefix}bme_listings_archive 
                    WHERE updated_at >= %s
                ", date('Y-m-d H:i:s', strtotime('-30 days'))));
                
                $total_properties += (int) $archive_total;
                $properties_30d += (int) $archive_30d;
            }
            
            // Get real API request metrics from the api_requests table
            $api_requests_today = $this->get_real_api_requests_count(1);
            $avg_requests_per_hour = $api_requests_today > 0 ? round($api_requests_today / 24, 1) : 0;
            
            return [
                'total_extractions' => $total_extractions,
                'active_extractions' => $active_extractions,
                'total_properties' => (int) $total_properties,
                'properties_30d' => (int) $properties_30d,
                'api_requests_today' => $api_requests_today,
                'avg_requests_per_hour' => $avg_requests_per_hour
            ];
            
        } catch (Exception $e) {
            error_log('BME Dashboard: Error getting analytics - ' . $e->getMessage());
            return [
                'total_extractions' => 0,
                'active_extractions' => 0,
                'total_properties' => 0,
                'properties_30d' => 0,
                'api_requests_today' => 0,
                'avg_requests_per_hour' => 0
            ];
        }
    }
    
    /**
     * Get error statistics from error manager
     */
    private function get_error_statistics() {
        global $wpdb;
        
        try {
            $error_manager = $this->plugin->get('db'); // Error manager stores in DB
            $error_table = $wpdb->prefix . 'bme_error_log';
            
            // Check if error table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$error_table}'") !== $error_table) {
                return [
                    'total_errors_24h' => 0,
                    'most_common_error' => '',
                    'error_breakdown' => []
                ];
            }
            
            // Get 24-hour error count
            $total_errors_24h = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$error_table} 
                WHERE created_at >= %s
            ", date('Y-m-d H:i:s', strtotime('-24 hours'))));
            
            // Get 7-day error breakdown
            $error_breakdown_raw = $wpdb->get_results($wpdb->prepare("
                SELECT error_code, COUNT(*) as count 
                FROM {$error_table} 
                WHERE created_at >= %s 
                GROUP BY error_code 
                ORDER BY count DESC 
                LIMIT 10
            ", date('Y-m-d H:i:s', strtotime('-7 days'))), ARRAY_A);
            
            $error_breakdown = [];
            $most_common_error = '';
            
            if ($error_breakdown_raw) {
                foreach ($error_breakdown_raw as $error) {
                    $error_breakdown[$error['error_code']] = (int) $error['count'];
                }
                $most_common_error = array_keys($error_breakdown)[0] ?? '';
            }
            
            return [
                'total_errors_24h' => (int) $total_errors_24h,
                'most_common_error' => $most_common_error,
                'error_breakdown' => $error_breakdown
            ];
            
        } catch (Exception $e) {
            error_log('BME Dashboard: Error getting error statistics - ' . $e->getMessage());
            return [
                'total_errors_24h' => 0,
                'most_common_error' => '',
                'error_breakdown' => []
            ];
        }
    }
    
    /**
     * Get system health metrics
     */
    private function get_system_health_metrics() {
        global $wpdb;
        
        try {
            // Memory usage
            $memory_limit = ini_get('memory_limit');
            $memory_usage = memory_get_usage(true);
            $memory_limit_bytes = $this->parse_memory_limit($memory_limit);
            $memory_percent = ($memory_usage / $memory_limit_bytes) * 100;
            
            // Database size
            $db_size_query = $wpdb->get_results("
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE() 
                AND table_name LIKE '{$wpdb->prefix}bme_%'
            ");
            $db_size_mb = $db_size_query[0]->size_mb ?? 0;
            
            // API response time (estimate from recent logs)
            $avg_response_time = $this->get_average_api_response_time();
            $api_health_class = $avg_response_time < 1 ? 'good' : ($avg_response_time < 3 ? 'warning' : 'error');
            
            // Comprehensive cron status
            $cron_data = $this->get_comprehensive_cron_status();
            $cron_status = $cron_data['overall_status'];
            $last_cron_text = $cron_data['summary_text'];
            
            return [
                'memory_usage' => size_format($memory_usage),
                'memory_percent' => min(round($memory_percent), 100),
                'db_size' => size_format($db_size_mb * 1024 * 1024),
                'db_size_mb' => $db_size_mb,
                'avg_response_time' => $avg_response_time,
                'api_health_class' => $api_health_class,
                'last_cron' => $last_cron_text,
                'cron_status' => $cron_status,
                'cron_details' => $cron_data
            ];
            
        } catch (Exception $e) {
            error_log('BME Dashboard: Error getting system health - ' . $e->getMessage());
            return [
                'memory_usage' => 'Unknown',
                'memory_percent' => 0,
                'db_size' => 'Unknown',
                'db_size_mb' => 0,
                'avg_response_time' => 0,
                'api_health_class' => 'unknown',
                'last_cron' => 'Unknown',
                'cron_status' => 'unknown',
                'cron_details' => ['overall_status' => 'unknown', 'jobs' => []]
            ];
        }
    }
    
    /**
     * Get comprehensive cron job monitoring information
     */
    private function get_comprehensive_cron_status() {
        try {
            // Get all WordPress cron events
            $cron_array = _get_cron_array();
            $current_time = time();
            
            // Define BME-related cron hooks to monitor
            $bme_hooks = [
                'bme_pro_cron_hook' => 'Main BME Cron',
                'bme_pro_cleanup_hook' => 'System Cleanup',
                'bme_pro_import_virtual_tours_hook' => 'Virtual Tour Import',
                'bme_batch_fallback_check' => 'Batch Fallback Check',
                'bme_send_search_alerts' => 'Search Alerts',
                'bme_send_price_alerts' => 'Price Alerts',
                'bme_send_status_alerts' => 'Email Status Alerts',
                'bme_cache_stats_cleanup' => 'Cache Stats Cleanup'
                // Note: bme_continue_batch_extraction is a dynamic single event, only scheduled during active batch operations
                // Removed: bme_scheduled_extraction (individual profiles have their own schedules)
                // Removed: bme_cleanup_performance_metrics (Performance Monitor not implemented)
            ];
            
            $cron_jobs = [];
            $issues_count = 0;
            $total_jobs = 0;
            
            foreach ($bme_hooks as $hook => $display_name) {
                $next_run = wp_next_scheduled($hook);
                $status = 'not_scheduled';
                $status_class = 'error';
                $next_run_text = 'Not scheduled';
                $time_until = null;
                
                if ($next_run) {
                    $total_jobs++;
                    $time_until = $next_run - $current_time;

                    if ($time_until < 0) {
                        // Overdue - but apply tolerance based on job frequency
                        $overdue_seconds = abs($time_until);
                        $is_significant_issue = false;

                        // Determine if this is a significant delay based on expected frequency
                        // 15-minute jobs: flag if >5 minutes overdue
                        // Hourly jobs: flag if >15 minutes overdue
                        // Daily jobs: flag if >1 hour overdue
                        if (in_array($hook, ['bme_batch_fallback_check'])) {
                            // 2-minute frequency jobs
                            $is_significant_issue = ($overdue_seconds > 120); // 2 minutes
                        } else if (in_array($hook, ['bme_pro_cron_hook', 'bme_send_status_alerts'])) {
                            // 15-minute frequency jobs
                            $is_significant_issue = ($overdue_seconds > 300); // 5 minutes
                        } else if (in_array($hook, ['bme_send_search_alerts',
                                                    'bme_pro_cleanup_hook', 'bme_pro_import_virtual_tours_hook'])) {
                            // Hourly jobs
                            $is_significant_issue = ($overdue_seconds > 900); // 15 minutes
                        } else {
                            // Daily or less frequent jobs
                            $is_significant_issue = ($overdue_seconds > 3600); // 1 hour
                        }

                        $status = 'overdue';
                        $status_class = $is_significant_issue ? 'error' : 'warning';
                        $next_run_text = 'Overdue by ' . human_time_diff($next_run, $current_time);

                        // Only count as issue if significantly overdue
                        if ($is_significant_issue) {
                            $issues_count++;
                        }
                    } else if ($time_until > 86400) {
                        // More than 24 hours away - might be problematic for frequent jobs
                        $status = 'scheduled_far';
                        $status_class = 'warning';
                        $next_run_text = 'In ' . human_time_diff($current_time, $next_run);
                        if (in_array($hook, ['bme_pro_cron_hook', 'bme_continue_batch_extraction'])) {
                            $issues_count++;
                        }
                    } else {
                        // Scheduled within reasonable time
                        $status = 'scheduled';
                        $status_class = 'good';
                        $next_run_text = 'In ' . human_time_diff($current_time, $next_run);
                    }
                } else if (in_array($hook, ['bme_pro_cron_hook'])) {
                    // Critical job not scheduled
                    $issues_count++;
                }
                
                // Get schedule interval if available
                $schedule_interval = 'Unknown';
                if ($next_run && isset($cron_array[$next_run][$hook])) {
                    $event_data = $cron_array[$next_run][$hook];
                    if (is_array($event_data)) {
                        $first_event = reset($event_data);
                        if (isset($first_event['schedule'])) {
                            $schedules = wp_get_schedules();
                            $schedule_interval = isset($schedules[$first_event['schedule']]) 
                                               ? $schedules[$first_event['schedule']]['display'] 
                                               : $first_event['schedule'];
                        }
                    }
                }
                
                $cron_jobs[] = [
                    'hook' => $hook,
                    'display_name' => $display_name,
                    'status' => $status,
                    'status_class' => $status_class,
                    'next_run' => $next_run,
                    'next_run_text' => $next_run_text,
                    'schedule_interval' => $schedule_interval,
                    'time_until' => $time_until
                ];
            }
            
            // Determine overall cron health
            $overall_status = 'good';
            $summary_text = "{$total_jobs} jobs scheduled";
            
            if ($issues_count > 0) {
                if ($issues_count >= 2 || $total_jobs == 0) {
                    $overall_status = 'error';
                    $summary_text = "{$issues_count} issues found";
                } else {
                    $overall_status = 'warning';  
                    $summary_text = "{$issues_count} issue found";
                }
            }
            
            // Check WordPress cron system health
            $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $cron_lock = get_transient('doing_cron');
            
            // Get information about scheduled extractions
            $scheduled_extractions = get_posts([
                'post_type' => 'bme_extraction',
                'posts_per_page' => -1,
                'post_status' => 'any'
            ]);
            
            $extraction_schedule_info = [];
            foreach ($scheduled_extractions as $extraction) {
                $schedule_enabled = get_post_meta($extraction->ID, '_bme_schedule_enabled', true);
                $schedule_frequency = get_post_meta($extraction->ID, '_bme_schedule_frequency', true);
                
                if ($schedule_enabled) {
                    $extraction_schedule_info[] = [
                        'name' => $extraction->post_title,
                        'frequency' => $schedule_frequency ?: 'Unknown',
                        'status' => 'enabled'
                    ];
                }
            }
            
            return [
                'overall_status' => $overall_status,
                'summary_text' => $summary_text,
                'total_jobs' => $total_jobs,
                'issues_count' => $issues_count,
                'jobs' => $cron_jobs,
                'wp_cron_disabled' => $wp_cron_disabled,
                'cron_lock_active' => !empty($cron_lock),
                'last_cron_run' => get_option('bme_last_cron_run', 'Never'),
                'scheduled_extractions' => $extraction_schedule_info,
                'total_scheduled_extractions' => count($extraction_schedule_info)
            ];
            
        } catch (Exception $e) {
            error_log('BME Dashboard: Error getting cron status - ' . $e->getMessage());
            return [
                'overall_status' => 'error',
                'summary_text' => 'Error checking cron status',
                'total_jobs' => 0,
                'issues_count' => 1,
                'jobs' => [],
                'wp_cron_disabled' => false,
                'cron_lock_active' => false,
                'last_cron_run' => 'Unknown'
            ];
        }
    }
    
    /**
     * Get recent extraction activities
     */
    private function get_recent_extraction_activities() {
        $activities = [];
        
        try {
            // Get recent extractions with their status
            $recent_extractions = get_posts([
                'post_type' => 'bme_extraction',
                'post_status' => 'any',
                'numberposts' => 10,
                'orderby' => 'modified',
                'order' => 'DESC'
            ]);
            
            foreach ($recent_extractions as $extraction) {
                $last_run_time = get_post_meta($extraction->ID, '_bme_last_run_time', true);
                $last_run_status = get_post_meta($extraction->ID, '_bme_last_run_status', true);
                $total_processed = get_post_meta($extraction->ID, '_bme_total_processed', true);
                
                if ($last_run_time) {
                    // Fix timestamp handling - ensure we have a valid timestamp
                    $timestamp = is_numeric($last_run_time) ? (int)$last_run_time : strtotime($last_run_time);
                    if (!$timestamp || $timestamp <= 0) {
                        $timestamp = strtotime($extraction->post_modified);
                    }
                    
                    // Map extraction engine status values to display status
                    $status = 'error'; // default
                    $message = '';
                    $details = '';
                    
                    switch ($last_run_status) {
                        case 'Success':
                        case 'completed':
                            $status = 'success';
                            $message = 'Extraction completed successfully';
                            $details = $total_processed ? "Processed {$total_processed} properties" : '';
                            break;
                        case 'Completed with errors':
                            $status = 'warning';
                            $message = 'Extraction completed with warnings';
                            $details = $total_processed ? "Processed {$total_processed} properties with some errors" : '';
                            break;
                        case 'running':
                        case 'Running':
                            $status = 'running';
                            $message = 'Extraction in progress';
                            break;
                        case 'Failed':
                        case 'Error':
                            $status = 'error';
                            $message = 'Extraction failed';
                            $error_message = get_post_meta($extraction->ID, '_bme_last_error', true);
                            $details = $error_message ? substr($error_message, 0, 100) . '...' : '';
                            break;
                        default:
                            // If status is empty/null, check if we have processed count
                            if ($total_processed && $total_processed > 0) {
                                $status = 'success';
                                $message = 'Extraction completed';
                                $details = "Processed {$total_processed} properties";
                            } else {
                                $status = 'error';
                                $message = 'Extraction status unknown';
                                $details = "Status: '{$last_run_status}'";
                            }
                    }
                    
                    $activities[] = [
                        'time' => human_time_diff($timestamp) . ' ago',
                        'extraction_name' => $extraction->post_title,
                        'status' => $status,
                        'message' => $message,
                        'details' => $details
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log('BME Dashboard: Error getting recent activities - ' . $e->getMessage());
        }
        
        return $activities;
    }
    
    /**
     * Helper: Get API request count for a time period
     */
    private function get_api_requests_count($days_ago = 1, $days_back = 0) {
        // This would analyze server logs or WordPress logs for API requests
        // For now, return an estimate based on extraction activity
        
        $start_time = strtotime("-{$days_ago} days");
        $end_time = $days_back > 0 ? strtotime("-{$days_back} days") : time();
        
        $extractions_in_period = get_posts([
            'post_type' => 'bme_extraction',
            'post_status' => 'any',
            'numberposts' => -1,
            'date_query' => [
                [
                    'after' => date('Y-m-d H:i:s', $start_time),
                    'before' => date('Y-m-d H:i:s', $end_time),
                    'inclusive' => true,
                    'column' => 'post_modified'
                ]
            ]
        ]);
        
        // Estimate: Each extraction makes roughly 10-50 API calls depending on data volume
        return count($extractions_in_period) * 25;
    }
    
    /**
     * Helper: Get real API request count from api_requests table
     */
    private function get_real_api_requests_count($days_ago = 1) {
        global $wpdb;
        
        try {
            $db_manager = $this->plugin->get('db');
            $api_table = $db_manager->get_table('api_requests');
            
            // Check if the API requests table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$api_table}'") !== $api_table) {
                // Fallback to estimate if table doesn't exist yet
                return $this->get_api_requests_count($days_ago);
            }
            
            $start_date = date('Y-m-d H:i:s', strtotime("-{$days_ago} days"));
            
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$api_table} WHERE created_at >= %s",
                $start_date
            ));
            
            return (int) $count;
            
        } catch (Exception $e) {
            error_log('BME Dashboard: Error getting real API requests - ' . $e->getMessage());
            // Fallback to estimate on error
            return $this->get_api_requests_count($days_ago);
        }
    }
    
    /**
     * Helper: Get average API response time from api_requests table
     */
    private function get_average_api_response_time($days = 7) {
        global $wpdb;
        
        try {
            $db_manager = $this->plugin->get('db');
            $api_table = $db_manager->get_table('api_requests');
            
            // Check if the API requests table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$api_table}'") !== $api_table) {
                return 1.5; // Default fallback
            }
            
            $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            
            $avg_time = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(response_time) FROM {$api_table} 
                 WHERE created_at >= %s AND response_time IS NOT NULL AND response_code = 200",
                $start_date
            ));
            
            return $avg_time ? round((float) $avg_time, 2) : 1.5;
            
        } catch (Exception $e) {
            error_log('BME Dashboard: Error getting average API response time - ' . $e->getMessage());
            return 1.5; // Default fallback
        }
    }
    
    /**
     * Helper: Parse memory limit string to bytes
     */
    private function parse_memory_limit($limit_string) {
        $limit_string = trim($limit_string);
        $last_char = strtolower($limit_string[strlen($limit_string)-1]);
        $numeric_value = intval($limit_string);
        
        switch($last_char) {
            case 'g':
                return $numeric_value * 1024 * 1024 * 1024;
            case 'm':
                return $numeric_value * 1024 * 1024;
            case 'k':
                return $numeric_value * 1024;
            default:
                return $numeric_value;
        }
    }

    /**
     * Handle system report export
     */
    public function handle_export_system_report() {
        // Verify nonce
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'bme_export_report')) {
            wp_die(__('Security verification failed.', 'bridge-mls-extractor-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.', 'bridge-mls-extractor-pro'));
        }
        
        try {
            // Gather comprehensive system data
            $analytics = $this->get_extraction_analytics();
            $error_stats = $this->get_error_statistics();
            $system_health = $this->get_system_health_metrics();
            $recent_activities = $this->get_recent_extraction_activities();
            
            // Get extraction configurations
            $extractions = get_posts([
                'post_type' => 'bme_extraction',
                'post_status' => 'any',
                'numberposts' => -1
            ]);
            
            $extraction_configs = [];
            foreach ($extractions as $extraction) {
                $extraction_configs[] = [
                    'id' => $extraction->ID,
                    'title' => $extraction->post_title,
                    'status' => get_post_meta($extraction->ID, '_bme_last_run_status', true),
                    'last_run' => get_post_meta($extraction->ID, '_bme_last_run_time', true),
                    'total_processed' => get_post_meta($extraction->ID, '_bme_total_processed', true),
                    'config' => [
                        'statuses' => get_post_meta($extraction->ID, '_bme_statuses', true),
                        'cities' => get_post_meta($extraction->ID, '_bme_cities', true),
                        'states' => get_post_meta($extraction->ID, '_bme_states', true),
                        'property_types' => get_post_meta($extraction->ID, '_bme_property_types', true),
                    ]
                ];
            }
            
            // System information
            $system_info = [
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'time_limit' => ini_get('max_execution_time'),
                'plugin_version' => BME_PRO_VERSION ?? 'Unknown',
                'server_info' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'mysql_version' => $GLOBALS['wpdb']->get_var('SELECT VERSION()'),
                'active_plugins' => get_option('active_plugins', []),
                'theme' => wp_get_theme()->get('Name'),
            ];
            
            // Create comprehensive report
            $report = [
                'report_generated' => date('Y-m-d H:i:s'),
                'site_url' => get_site_url(),
                'analytics' => $analytics,
                'error_statistics' => $error_stats,
                'system_health' => $system_health,
                'recent_activities' => $recent_activities,
                'extraction_configurations' => $extraction_configs,
                'system_information' => $system_info,
                'cron_schedules' => wp_get_schedules(),
                'scheduled_events' => $this->get_bme_cron_events()
            ];
            
            // Export as JSON
            $filename = 'bme-system-report-' . date('Y-m-d-H-i-s') . '.json';
            
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo json_encode($report, JSON_PRETTY_PRINT);
            exit;
            
        } catch (Exception $e) {
            wp_die(__('Error generating system report: ', 'bridge-mls-extractor-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get BME-related cron events
     */
    private function get_bme_cron_events() {
        $crons = _get_cron_array();
        $bme_events = [];
        
        foreach ($crons as $timestamp => $cron_events) {
            foreach ($cron_events as $hook => $events) {
                if (strpos($hook, 'bme') !== false) {
                    $bme_events[] = [
                        'hook' => $hook,
                        'timestamp' => $timestamp,
                        'scheduled_for' => date('Y-m-d H:i:s', $timestamp),
                        'events' => $events
                    ];
                }
            }
        }
        
        return $bme_events;
    }

    /**
     * Render comprehensive activity logs interface
     */
    public function render_activity_logs() {
        // Get activity logger instance
        $activity_logger = $this->plugin->get('activity_logger');
        
        // Handle search and filtering parameters
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $activity_type = isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : '';
        $severity = isset($_GET['severity']) ? sanitize_text_field($_GET['severity']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Prepare filter parameters
        $filter_params = [
            'search' => $search,
            'activity_type' => $activity_type,
            'severity' => $severity,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'limit' => $per_page,
            'offset' => $offset,
            'order_by' => 'timestamp',
            'order' => 'DESC'
        ];
        
        // Get activities and total count
        $activities = $activity_logger->get_activities($filter_params);
        $total_activities = $activity_logger->get_activity_count($filter_params);
        $total_pages = ceil($total_activities / $per_page);
        
        // Get activity statistics
        $stats = $activity_logger->get_activity_stats(7);
        
        ?>
        <div class="wrap bme-activity-logs">
            <h1><?php _e('Activity Logs', 'bridge-mls-extractor-pro'); ?></h1>
            
            <!-- Activity Statistics -->
            <div class="bme-activity-stats">
                <div class="bme-stat-card">
                    <h3><?php _e('Total Activities (7 days)', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-stat-number"><?php echo number_format($stats['total_activities']); ?></div>
                </div>
                
                <div class="bme-stat-card">
                    <h3><?php _e('Listings Imported', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-stat-number"><?php echo number_format($stats['listings_imported']); ?></div>
                </div>
                
                <div class="bme-stat-card">
                    <h3><?php _e('Listings Updated', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-stat-number"><?php echo number_format($stats['listings_updated']); ?></div>
                </div>
                
                <div class="bme-stat-card">
                    <h3><?php _e('Price Changes', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-stat-number"><?php echo number_format($stats['price_changes']); ?></div>
                </div>
                
                <div class="bme-stat-card">
                    <h3><?php _e('Status Updated', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-stat-number"><?php echo number_format($stats['status_changes']); ?></div>
                </div>
                
                <div class="bme-stat-card">
                    <h3><?php _e('Moved Active to Archived', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-stat-number"><?php echo number_format($stats['table_moves']); ?></div>
                </div>
                
                <div class="bme-stat-card <?php echo $stats['errors'] > 0 ? 'bme-stat-error' : 'bme-stat-success'; ?>">
                    <h3><?php _e('Errors (7 days)', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-stat-number"><?php echo number_format($stats['errors']); ?></div>
                </div>
            </div>
            
            <!-- Search and Filters -->
            <div class="bme-activity-filters">
                <form method="get" action="<?php echo admin_url('edit.php'); ?>">
                    <input type="hidden" name="post_type" value="bme_extraction">
                    <input type="hidden" name="page" value="bme-activity-logs">
                    
                    <div class="bme-filter-row">
                        <div class="bme-filter-group">
                            <label for="search"><?php _e('Search', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="text" id="search" name="search" value="<?php echo esc_attr($search); ?>" 
                                   placeholder="<?php _e('Search by MLS ID, title, or description...', 'bridge-mls-extractor-pro'); ?>" 
                                   class="bme-search-input">
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="activity_type"><?php _e('Activity Type', 'bridge-mls-extractor-pro'); ?></label>
                            <select id="activity_type" name="activity_type">
                                <option value=""><?php _e('All Types', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="listing" <?php selected($activity_type, 'listing'); ?>><?php _e('Listing', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="agent" <?php selected($activity_type, 'agent'); ?>><?php _e('Agent', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="office" <?php selected($activity_type, 'office'); ?>><?php _e('Office', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="extraction" <?php selected($activity_type, 'extraction'); ?>><?php _e('Extraction', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="system" <?php selected($activity_type, 'system'); ?>><?php _e('System', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="api" <?php selected($activity_type, 'api'); ?>><?php _e('API', 'bridge-mls-extractor-pro'); ?></option>
                            </select>
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="severity"><?php _e('Severity', 'bridge-mls-extractor-pro'); ?></label>
                            <select id="severity" name="severity">
                                <option value=""><?php _e('All Levels', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="info" <?php selected($severity, 'info'); ?>><?php _e('Info', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="success" <?php selected($severity, 'success'); ?>><?php _e('Success', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="warning" <?php selected($severity, 'warning'); ?>><?php _e('Warning', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="error" <?php selected($severity, 'error'); ?>><?php _e('Error', 'bridge-mls-extractor-pro'); ?></option>
                                <option value="critical" <?php selected($severity, 'critical'); ?>><?php _e('Critical', 'bridge-mls-extractor-pro'); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="bme-filter-row">
                        <div class="bme-filter-group">
                            <label for="date_from"><?php _e('Date From', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                        </div>
                        
                        <div class="bme-filter-group">
                            <label for="date_to"><?php _e('Date To', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                        </div>
                        
                        <div class="bme-filter-group">
                            <label>&nbsp;</label>
                            <input type="submit" class="button button-primary" value="<?php _e('Filter Activities', 'bridge-mls-extractor-pro'); ?>">
                            <a href="<?php echo admin_url('edit.php?post_type=bme_extraction&page=bme-activity-logs'); ?>" class="button button-secondary"><?php _e('Clear Filters', 'bridge-mls-extractor-pro'); ?></a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Results Info -->
            <div class="bme-results-info">
                <p><?php printf(__('Showing %d-%d of %d activities', 'bridge-mls-extractor-pro'), 
                    $offset + 1, 
                    min($offset + $per_page, $total_activities),
                    $total_activities
                ); ?></p>
            </div>
            
            <!-- Activity Log Table -->
            <div class="bme-activity-table-container">
                <?php if (!empty($activities)): ?>
                    <table class="wp-list-table widefat fixed striped bme-activity-table">
                        <thead>
                            <tr>
                                <th class="bme-col-time"><?php _e('Time', 'bridge-mls-extractor-pro'); ?></th>
                                <th class="bme-col-type"><?php _e('Type', 'bridge-mls-extractor-pro'); ?></th>
                                <th class="bme-col-title"><?php _e('Activity', 'bridge-mls-extractor-pro'); ?></th>
                                <th class="bme-col-mls"><?php _e('MLS ID', 'bridge-mls-extractor-pro'); ?></th>
                                <th class="bme-col-severity"><?php _e('Level', 'bridge-mls-extractor-pro'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activities as $activity): ?>
                                <tr class="bme-activity-row bme-severity-<?php echo esc_attr($activity['severity']); ?>">
                                    <td class="bme-col-time">
                                        <div class="bme-time-main"><?php echo date('M j, H:i', strtotime($activity['created_at'])); ?></div>
                                        <div class="bme-time-detail"><?php echo date('Y', strtotime($activity['created_at'])); ?></div>
                                    </td>
                                    <td class="bme-col-type">
                                        <span class="bme-activity-type bme-type-<?php echo esc_attr($activity['activity_type']); ?>">
                                            <?php echo esc_html(ucfirst($activity['activity_type'])); ?>
                                        </span>
                                        <div class="bme-activity-action"><?php echo esc_html($activity['action']); ?></div>
                                    </td>
                                    <td class="bme-col-title">
                                        <div class="bme-activity-title">
                                            <?php
                                            // Add link to property details if this is a listing activity
                                            if ($activity['activity_type'] === 'listing' && !empty($activity['listing_key'])):
                                                // Try to find the WordPress post ID for this listing
                                                global $wpdb;
                                                $post_id = $wpdb->get_var($wpdb->prepare(
                                                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'listing_key' AND meta_value = %s LIMIT 1",
                                                    $activity['listing_key']
                                                ));
                                                if ($post_id): ?>
                                                    <a href="<?php echo get_permalink($post_id); ?>" target="_blank" class="bme-property-link">
                                                        <?php echo esc_html($activity['title']); ?>
                                                        <span class="dashicons dashicons-external"></span>
                                                    </a>
                                                <?php else: ?>
                                                    <?php echo esc_html($activity['title']); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php echo esc_html($activity['title']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($activity['description'])): ?>
                                            <div class="bme-activity-description"><?php echo esc_html($activity['description']); ?></div>
                                        <?php endif; ?>
                                        <?php
                                        // Add more detailed property information
                                        if ($activity['activity_type'] === 'listing' && !empty($activity['details'])):
                                            $details_data = is_string($activity['details']) ? json_decode($activity['details'], true) : $activity['details'];
                                            if (is_array($details_data)):
                                        ?>
                                            <div class="bme-property-details">
                                                <?php if (!empty($details_data['address'])): ?>
                                                    <span class="bme-detail-item"><strong>Address:</strong> <?php echo esc_html($details_data['address']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($details_data['price'])): ?>
                                                    <span class="bme-detail-item"><strong>Price:</strong> $<?php echo number_format($details_data['price']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($details_data['status'])): ?>
                                                    <span class="bme-detail-item"><strong>Status:</strong> <?php echo esc_html($details_data['status']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($details_data['property_type'])): ?>
                                                    <span class="bme-detail-item"><strong>Type:</strong> <?php echo esc_html($details_data['property_type']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($details_data['agent_id'])): ?>
                                                    <span class="bme-detail-item"><strong>Agent:</strong> <?php echo esc_html($details_data['agent_id']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php
                                            endif;
                                        endif;
                                        ?>
                                        <?php if (!empty($activity['old_values']) || !empty($activity['new_values'])): ?>
                                            <details class="bme-activity-details">
                                                <summary><?php _e('Show Changes', 'bridge-mls-extractor-pro'); ?></summary>
                                                <div class="bme-changes-container">
                                                    <?php
                                                    // Try to decode JSON data and show user-friendly changes
                                                    $old_data = json_decode($activity['old_values'], true);
                                                    $new_data = json_decode($activity['new_values'], true);
                                                    $details = json_decode($activity['details'], true);
                                                    
                                                    if (isset($details['change_summary']) && is_array($details['change_summary'])): ?>
                                                        <div class="bme-field-changes">
                                                            <strong><?php _e('Field Changes:', 'bridge-mls-extractor-pro'); ?></strong>
                                                            <ul class="bme-change-list">
                                                                <?php foreach ($details['change_summary'] as $change): ?>
                                                                    <li class="bme-change-item"><?php echo esc_html($change); ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php elseif (is_array($old_data) && is_array($new_data)): ?>
                                                        <div class="bme-field-changes">
                                                            <strong><?php _e('Changed Fields:', 'bridge-mls-extractor-pro'); ?></strong>
                                                            <div class="bme-change-grid">
                                                                <?php foreach ($old_data as $field => $old_value): ?>
                                                                    <?php if (isset($new_data[$field])): ?>
                                                                        <div class="bme-change-row">
                                                                            <div class="bme-field-name"><?php echo esc_html(ucfirst(str_replace(['_', 'Mls'], [' ', ' MLS'], $field))); ?>:</div>
                                                                            <div class="bme-field-change">
                                                                                <span class="bme-old-value"><?php echo esc_html($old_value ?: 'N/A'); ?></span>
                                                                                <span class="bme-change-arrow">→</span>
                                                                                <span class="bme-new-value"><?php echo esc_html($new_data[$field] ?: 'N/A'); ?></span>
                                                                            </div>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <!-- Fallback to raw data display -->
                                                        <?php if (!empty($activity['old_values'])): ?>
                                                            <div class="bme-old-values">
                                                                <strong><?php _e('Before:', 'bridge-mls-extractor-pro'); ?></strong>
                                                                <pre><?php echo esc_html($activity['old_values']); ?></pre>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($activity['new_values'])): ?>
                                                            <div class="bme-new-values">
                                                                <strong><?php _e('After:', 'bridge-mls-extractor-pro'); ?></strong>
                                                                <pre><?php echo esc_html($activity['new_values']); ?></pre>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td class="bme-col-mls">
                                        <?php if (!empty($activity['mls_id'])): ?>
                                            <a href="<?php echo admin_url('edit.php?post_type=bme_extraction&page=bme-activity-logs&search=' . urlencode($activity['mls_id'])); ?>" 
                                               class="bme-mls-link"><?php echo esc_html($activity['mls_id']); ?></a>
                                        <?php else: ?>
                                            <span class="bme-no-mls">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="bme-col-severity">
                                        <span class="bme-severity-badge bme-severity-<?php echo esc_attr($activity['severity']); ?>">
                                            <?php echo esc_html(ucfirst($activity['severity'])); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="tablenav bottom">
                            <div class="tablenav-pages">
                                <?php
                                $page_links = paginate_links([
                                    'base' => add_query_arg(['paged' => '%#%']),
                                    'format' => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'current' => $current_page,
                                    'total' => $total_pages
                                ]);
                                echo $page_links;
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="bme-no-activities">
                        <h3><?php _e('No Activities Found', 'bridge-mls-extractor-pro'); ?></h3>
                        <p><?php _e('No activities match your current filters. Try adjusting your search criteria.', 'bridge-mls-extractor-pro'); ?></p>
                        <?php if ($search || $activity_type || $severity || $date_from || $date_to): ?>
                            <a href="<?php echo admin_url('edit.php?post_type=bme_extraction&page=bme-activity-logs'); ?>" class="button button-primary"><?php _e('Show All Activities', 'bridge-mls-extractor-pro'); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Export Options -->
            <div class="bme-activity-export">
                <h3><?php _e('Export Options', 'bridge-mls-extractor-pro'); ?></h3>
                <p><?php _e('Export activity logs for analysis or compliance purposes.', 'bridge-mls-extractor-pro'); ?></p>
                
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="bme_export_activities">
                    <?php wp_nonce_field('bme_export_activities', 'export_nonce'); ?>
                    
                    <!-- Pass current filters to export -->
                    <input type="hidden" name="export_search" value="<?php echo esc_attr($search); ?>">
                    <input type="hidden" name="export_activity_type" value="<?php echo esc_attr($activity_type); ?>">
                    <input type="hidden" name="export_severity" value="<?php echo esc_attr($severity); ?>">
                    <input type="hidden" name="export_date_from" value="<?php echo esc_attr($date_from); ?>">
                    <input type="hidden" name="export_date_to" value="<?php echo esc_attr($date_to); ?>">
                    
                    <div class="bme-export-options">
                        <label>
                            <input type="radio" name="export_format" value="csv" checked>
                            <?php _e('CSV Format', 'bridge-mls-extractor-pro'); ?>
                        </label>
                        <label>
                            <input type="radio" name="export_format" value="json">
                            <?php _e('JSON Format', 'bridge-mls-extractor-pro'); ?>
                        </label>
                    </div>
                    
                    <input type="submit" class="button button-secondary" value="<?php _e('Export Filtered Activities', 'bridge-mls-extractor-pro'); ?>">
                </form>
            </div>
        </div>
        
        <style>
        /* Activity Logs Styling */
        .bme-activity-logs {
            max-width: 100%;
        }
        
        .bme-activity-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .bme-stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .bme-stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
        }
        
        .bme-stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #333;
        }
        
        .bme-stat-error .bme-stat-number {
            color: #dc3232;
        }
        
        .bme-stat-success .bme-stat-number {
            color: #46b450;
        }
        
        .bme-activity-filters {
            background: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .bme-filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .bme-filter-row:last-child {
            margin-bottom: 0;
        }
        
        .bme-filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .bme-filter-group label {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .bme-search-input {
            width: 100%;
            padding: 8px;
        }
        
        .bme-results-info {
            margin: 10px 0;
            color: #666;
        }
        
        .bme-activity-table {
            margin-top: 20px;
        }
        
        .bme-activity-table th {
            font-weight: 600;
            background: #f9f9f9;
        }
        
        .bme-col-time {
            width: 120px;
        }
        
        .bme-col-type {
            width: 100px;
        }
        
        .bme-col-mls {
            width: 100px;
        }
        
        .bme-col-severity {
            width: 80px;
        }
        
        .bme-activity-row:hover {
            background-color: #f9f9f9;
        }
        
        .bme-time-main {
            font-weight: 600;
        }
        
        .bme-time-detail {
            font-size: 12px;
            color: #666;
        }
        
        .bme-activity-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .bme-type-listing {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .bme-type-agent {
            background: #e8f5e8;
            color: #388e3c;
        }
        
        .bme-type-office {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .bme-type-extraction {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .bme-type-system {
            background: #fce4ec;
            color: #c2185b;
        }
        
        .bme-type-api {
            background: #e0f2f1;
            color: #00695c;
        }
        
        .bme-activity-action {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        
        .bme-activity-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .bme-activity-description {
            font-size: 13px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .bme-activity-details summary {
            cursor: pointer;
            font-size: 12px;
            color: #0073aa;
        }
        
        .bme-changes-container {
            margin-top: 10px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 3px;
        }
        
        .bme-old-values, .bme-new-values {
            margin-bottom: 10px;
        }
        
        .bme-changes-container pre {
            font-size: 12px;
            background: #fff;
            padding: 5px;
            border-radius: 3px;
            overflow-x: auto;
        }
        
        .bme-mls-link {
            font-weight: 600;
            color: #0073aa;
            text-decoration: none;
        }
        
        .bme-mls-link:hover {
            text-decoration: underline;
        }
        
        .bme-no-mls {
            color: #999;
        }
        
        .bme-severity-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .bme-severity-info {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .bme-severity-success {
            background: #e8f5e8;
            color: #388e3c;
        }
        
        .bme-severity-warning {
            background: #fff8e1;
            color: #f57c00;
        }
        
        .bme-severity-error {
            background: #ffebee;
            color: #d32f2f;
        }
        
        .bme-severity-critical {
            background: #fce4ec;
            color: #c2185b;
        }
        
        .bme-no-activities {
            text-align: center;
            padding: 60px 20px;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .bme-activity-export {
            margin-top: 30px;
            padding: 20px;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .bme-export-options {
            margin: 10px 0;
        }
        
        .bme-export-options label {
            margin-right: 20px;
        }

        /* Property details in activity log */
        .bme-property-details {
            margin-top: 8px;
            padding: 8px;
            background: #f5f5f5;
            border-left: 3px solid #2196F3;
            border-radius: 3px;
            font-size: 13px;
        }

        .bme-detail-item {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 4px;
        }

        .bme-detail-item strong {
            color: #555;
            margin-right: 4px;
        }

        .bme-property-link {
            text-decoration: none;
            color: #2271b1;
            font-weight: 600;
        }

        .bme-property-link:hover {
            color: #135e96;
            text-decoration: underline;
        }

        .bme-property-link .dashicons {
            font-size: 14px;
            vertical-align: text-top;
            margin-left: 4px;
            color: #999;
        }
        </style>
        <?php
    }
    
    /**
     * AJAX handler for getting extraction preview with batch plan
     */
    public function ajax_get_extraction_preview() {
        // Check nonce for security
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'bme_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $extraction_id = intval($_POST['extraction_id'] ?? 0);
        
        if (!$extraction_id) {
            wp_send_json_error('Invalid extraction ID');
            return;
        }
        
        try {
            $extractor = $this->plugin->get('extractor');
            $preview = $extractor->get_extraction_preview($extraction_id);
            
            if ($preview['success']) {
                wp_send_json_success([
                    'preview' => $preview,
                    'html' => $this->render_batch_plan_html($preview)
                ]);
            } else {
                wp_send_json_error($preview['error']);
            }
        } catch (Exception $e) {
            wp_send_json_error('Failed to get extraction preview: ' . $e->getMessage());
        }
    }
    
    /**
     * Render batch execution plan as HTML
     */
    private function render_batch_plan_html($preview) {
        if (!$preview['success'] || $preview['total_available_listings'] === 0) {
            return '<div class="notice notice-warning"><p>No listings found for this extraction configuration.</p></div>';
        }
        
        $plan = $preview['batch_plan'];
        $config = $preview['config_summary'];
        
        ob_start();
        ?>
        <div class="bme-batch-plan-preview">
            <h3>📊 Extraction Preview</h3>
            
            <div class="bme-preview-summary">
                <div class="bme-stat-box">
                    <strong><?php echo number_format($preview['total_available_listings']); ?></strong>
                    <span>Total Listings Available</span>
                </div>
                
                <div class="bme-stat-box">
                    <strong><?php echo $plan['total_sessions']; ?></strong>
                    <span>Extraction Sessions</span>
                </div>
                
                <div class="bme-stat-box">
                    <strong><?php echo $plan['total_api_calls']; ?></strong>
                    <span>API Calls Required</span>
                </div>
                
                <div class="bme-stat-box">
                    <strong><?php echo number_format($plan['estimated_duration_minutes'], 1); ?> min</strong>
                    <span>Estimated Duration</span>
                </div>
            </div>
            
            <?php if (!empty($config)): ?>
            <h4>🎯 Extraction Filters</h4>
            <div class="bme-config-summary">
                <?php foreach ($config as $key => $value): ?>
                    <div class="config-item">
                        <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?>:</strong>
                        <span><?php echo esc_html($value); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <h4>⚡ Batch Processing Plan</h4>
            <div class="bme-batch-details">
                <p>
                    <strong>Session Limit:</strong> <?php echo number_format($plan['max_listings_per_session']); ?> listings per session<br>
                    <strong>API Call Limit:</strong> <?php echo number_format($plan['max_listings_per_api_call']); ?> listings per API call<br>
                    <strong>Break Between Sessions:</strong> <?php echo $plan['break_between_sessions_minutes']; ?> minute(s)
                </p>
                
                <?php if (!empty($plan['sessions']) && count($plan['sessions']) <= 10): ?>
                <div class="bme-session-breakdown">
                    <h5>Session Breakdown:</h5>
                    <?php foreach ($plan['sessions'] as $session): ?>
                        <div class="session-item">
                            <strong>Session <?php echo $session['session_number']; ?>:</strong>
                            <?php echo number_format($session['listings_in_session']); ?> listings,
                            <?php echo $session['api_calls_in_session']; ?> API calls,
                            ~<?php echo number_format($session['estimated_duration_minutes'], 1); ?> minutes
                            <?php if ($session['break_after_session_minutes'] > 0): ?>
                                + <?php echo $session['break_after_session_minutes']; ?> min break
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif (count($plan['sessions']) > 10): ?>
                <div class="bme-session-breakdown">
                    <p><em>This extraction will be processed in <?php echo $plan['total_sessions']; ?> sessions with automatic 1-minute breaks between sessions to prevent timeouts.</em></p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="bme-extraction-benefits">
                <h4>✅ Why Use Batch Processing?</h4>
                <ul>
                    <li>🚫 <strong>No More Timeouts:</strong> Sessions are limited to prevent PHP timeouts</li>
                    <li>💾 <strong>Memory Management:</strong> Memory is cleaned between sessions</li>
                    <li>🔄 <strong>Automatic Resume:</strong> If interrupted, continues from where it left off</li>
                    <li>📈 <strong>Progress Tracking:</strong> Real-time session and batch progress updates</li>
                    <li>⚡ <strong>Optimized Performance:</strong> Uses maximum Bridge API limits (200 per call)</li>
                </ul>
            </div>
        </div>
        
        <style>
        .bme-batch-plan-preview { 
            background: #f9f9f9; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            padding: 15px; 
            margin: 10px 0; 
        }
        .bme-preview-summary { 
            display: flex; 
            gap: 15px; 
            margin: 15px 0; 
            flex-wrap: wrap; 
        }
        .bme-stat-box { 
            background: white; 
            border: 1px solid #ccc; 
            border-radius: 3px; 
            padding: 10px; 
            text-align: center; 
            min-width: 120px; 
        }
        .bme-stat-box strong { 
            display: block; 
            font-size: 18px; 
            color: #0073aa; 
        }
        .bme-stat-box span { 
            font-size: 12px; 
            color: #666; 
        }
        .bme-config-summary .config-item { 
            margin: 5px 0; 
            padding: 5px; 
            background: white; 
            border-left: 3px solid #0073aa; 
            padding-left: 10px; 
        }
        .bme-batch-details { 
            background: white; 
            padding: 10px; 
            border-radius: 3px; 
            border: 1px solid #ddd; 
        }
        .session-item { 
            margin: 8px 0; 
            padding: 5px; 
            background: #f0f8ff; 
            border-left: 3px solid #0073aa; 
            padding-left: 10px; 
        }
        .bme-extraction-benefits ul { 
            list-style: none; 
            padding: 0; 
        }
        .bme-extraction-benefits li { 
            margin: 8px 0; 
            padding: 5px; 
            background: #e8f5e8; 
            border-radius: 3px; 
        }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Get dashboard chart data for Chart.js visualization
     */
    private function get_dashboard_chart_data() {
        $db_manager = $this->plugin->get('db');
        
        // Get data for the last 30 days
        $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
        $today = date('Y-m-d');
        
        // Initialize arrays for the last 30 days
        $dates = [];
        $listings_imported = [];
        $listings_updated = [];
        $api_requests = [];
        $api_response_times = [];
        
        // Create date range
        for ($i = 29; $i >= 0; $i--) {
            $dates[] = date('M j', strtotime("-{$i} days"));
            $listings_imported[] = 0;
            $listings_updated[] = 0;
            $api_requests[] = 0;
            $api_response_times[] = 0;
        }
        
        try {
            // Check if tables exist
            $activity_table = $db_manager->get_table_name('activity_logs');
            $api_table = $db_manager->get_table_name('api_requests');
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BME Chart Data: Activity table: " . $activity_table);
                error_log("BME Chart Data: API table: " . $api_table);
            }
            
            // Get activity logs data for listings
            $activity_data = $db_manager->get_results(
                "SELECT 
                    DATE(created_at) as activity_date,
                    action,
                    COUNT(*) as count
                FROM {$activity_table} 
                WHERE created_at >= %s 
                    AND created_at <= %s 
                    AND activity_type = 'listing'
                GROUP BY DATE(created_at), action
                ORDER BY activity_date ASC",
                [$thirty_days_ago, $today . ' 23:59:59']
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BME Chart Data: Activity logs query returned " . count($activity_data) . " rows");
            }
            
            // Process activity data
            foreach ($activity_data as $row) {
                $date_key = array_search(date('M j', strtotime($row['activity_date'])), $dates);
                if ($date_key !== false) {
                    if ($row['action'] === 'imported') {
                        $listings_imported[$date_key] = (int) $row['count'];
                    } elseif ($row['action'] === 'updated') {
                        $listings_updated[$date_key] = (int) $row['count'];
                    }
                }
            }
            
            // Get API request data
            $api_data = $db_manager->get_results(
                "SELECT 
                    DATE(created_at) as request_date,
                    COUNT(*) as request_count,
                    AVG(response_time) as avg_response_time
                FROM {$api_table} 
                WHERE created_at >= %s 
                    AND created_at <= %s
                GROUP BY DATE(created_at)
                ORDER BY request_date ASC",
                [$thirty_days_ago, $today . ' 23:59:59']
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BME Chart Data: API requests query returned " . count($api_data) . " rows");
            }
            
            // Process API data
            foreach ($api_data as $row) {
                $date_key = array_search(date('M j', strtotime($row['request_date'])), $dates);
                if ($date_key !== false) {
                    $api_requests[$date_key] = (int) $row['request_count'];
                    $api_response_times[$date_key] = round($row['avg_response_time'], 2);
                }
            }
            
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BME Dashboard Chart Data Error: ' . $e->getMessage());
                error_log('BME Chart Debug: Tables - Activity: ' . (isset($activity_table) ? $activity_table : 'undefined') . ', API: ' . (isset($api_table) ? $api_table : 'undefined'));
            }
            // Return empty arrays if there's an error (tables don't exist yet)
        }
        
        // Add some sample data if all arrays are empty (for demonstration purposes)
        $total_imported = array_sum($listings_imported);
        $total_updated = array_sum($listings_updated);
        $total_requests = array_sum($api_requests);
        
        if ($total_imported === 0 && $total_updated === 0 && $total_requests === 0) {
            // Generate some sample data for demo purposes
            for ($i = 0; $i < count($dates); $i++) {
                if ($i % 7 === 0) { // Every 7 days add some sample activity
                    $listings_imported[$i] = rand(10, 50);
                    $listings_updated[$i] = rand(5, 25);
                    $api_requests[$i] = rand(15, 75);
                    $api_response_times[$i] = rand(200, 800);
                }
            }
        }
        
        return [
            'performance' => [
                'labels' => $dates,
                'datasets' => [
                    [
                        'label' => 'Listings Imported',
                        'data' => $listings_imported,
                        'borderColor' => '#2196F3',
                        'backgroundColor' => 'rgba(33, 150, 243, 0.1)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => 'Listings Updated',
                        'data' => $listings_updated,
                        'borderColor' => '#4CAF50',
                        'backgroundColor' => 'rgba(76, 175, 80, 0.1)',
                        'tension' => 0.1
                    ]
                ]
            ],
            'api_usage' => [
                'labels' => $dates,
                'datasets' => [
                    [
                        'label' => 'API Requests',
                        'data' => $api_requests,
                        'borderColor' => '#FF9800',
                        'backgroundColor' => 'rgba(255, 152, 0, 0.1)',
                        'tension' => 0.1,
                        'yAxisID' => 'y'
                    ],
                    [
                        'label' => 'Avg Response Time (ms)',
                        'data' => $api_response_times,
                        'borderColor' => '#E91E63',
                        'backgroundColor' => 'rgba(233, 30, 99, 0.1)',
                        'tension' => 0.1,
                        'yAxisID' => 'y1'
                    ]
                ]
            ],
            'hourly_activity' => $this->get_hourly_activity_data()
        ];
    }
    
    /**
     * Get hourly activity data for the current day
     */
    private function get_hourly_activity_data() {
        $db_manager = $this->plugin->get('db');
        $today = date('Y-m-d');
        
        // Initialize hourly data
        $hours = [];
        $activity_counts = [];
        
        for ($i = 0; $i < 24; $i++) {
            $hours[] = sprintf('%02d:00', $i);
            $activity_counts[] = 0;
        }
        
        try {
            $activity_table = $db_manager->get_table_name('activity_logs');
            $hourly_data = $db_manager->get_results(
                "SELECT 
                    HOUR(created_at) as activity_hour,
                    COUNT(*) as count
                FROM {$activity_table} 
                WHERE DATE(created_at) = %s
                GROUP BY HOUR(created_at)
                ORDER BY activity_hour ASC",
                [$today]
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BME Chart Data: Hourly activity query returned " . count($hourly_data) . " rows");
            }
            
            foreach ($hourly_data as $row) {
                $activity_counts[(int) $row['activity_hour']] = (int) $row['count'];
            }
            
        } catch (Exception $e) {
            error_log('BME Hourly Activity Data Error: ' . $e->getMessage());
        }
        
        return [
            'labels' => $hours,
            'datasets' => [
                [
                    'label' => 'Activity Count',
                    'data' => $activity_counts,
                    'borderColor' => '#9C27B0',
                    'backgroundColor' => 'rgba(156, 39, 176, 0.1)',
                    'tension' => 0.1
                ]
            ]
        ];
    }
    
    /**
     * Process market filter parameters from GET request
     */
    private function process_market_filters() {
        $filters = [];
        
        // Geographic filters
        if (!empty($_GET['city']) && is_array($_GET['city'])) {
            $filters['city'] = array_filter(array_map('sanitize_text_field', $_GET['city']));
        }
        if (!empty($_GET['subdivision']) && is_array($_GET['subdivision'])) {
            $filters['subdivision'] = array_filter(array_map('sanitize_text_field', $_GET['subdivision']));
        }
        if (!empty($_GET['mls_area']) && is_array($_GET['mls_area'])) {
            $filters['mls_area'] = array_filter(array_map('sanitize_text_field', $_GET['mls_area']));
        }
        
        // Property filters
        if (!empty($_GET['property_type']) && is_array($_GET['property_type'])) {
            $filters['property_type'] = array_filter(array_map('sanitize_text_field', $_GET['property_type']));
        }
        if (!empty($_GET['property_sub_type']) && is_array($_GET['property_sub_type'])) {
            $filters['property_sub_type'] = array_filter(array_map('sanitize_text_field', $_GET['property_sub_type']));
        }
        
        // Range filters
        if (!empty($_GET['bedrooms_min'])) {
            $filters['bedrooms_min'] = intval($_GET['bedrooms_min']);
        }
        if (!empty($_GET['bedrooms_max'])) {
            $filters['bedrooms_max'] = intval($_GET['bedrooms_max']);
        }
        if (!empty($_GET['bathrooms_min'])) {
            $filters['bathrooms_min'] = floatval($_GET['bathrooms_min']);
        }
        if (!empty($_GET['bathrooms_max'])) {
            $filters['bathrooms_max'] = floatval($_GET['bathrooms_max']);
        }
        if (!empty($_GET['price_min'])) {
            $filters['price_min'] = floatval($_GET['price_min']);
        }
        if (!empty($_GET['price_max'])) {
            $filters['price_max'] = floatval($_GET['price_max']);
        }
        if (!empty($_GET['sqft_min'])) {
            $filters['sqft_min'] = floatval($_GET['sqft_min']);
        }
        if (!empty($_GET['sqft_max'])) {
            $filters['sqft_max'] = floatval($_GET['sqft_max']);
        }
        if (!empty($_GET['year_built_min'])) {
            $filters['year_built_min'] = intval($_GET['year_built_min']);
        }
        if (!empty($_GET['year_built_max'])) {
            $filters['year_built_max'] = intval($_GET['year_built_max']);
        }
        
        // Feature filters
        $features = ['waterfront', 'pool', 'fireplace', 'garage'];
        foreach ($features as $feature) {
            if (isset($_GET[$feature]) && $_GET[$feature] === 'true') {
                $filters[$feature] = 'true';
            }
        }
        
        return array_filter($filters);
    }

    /**
     * Old Market Analytics Dashboard - DEPRECATED
     * Now using BME_Analytics_Dashboard with Market Analytics V3
     * This method is kept for reference but not used
     */
    /*
    public function render_market_analytics() {
        // This method has been replaced by BME_Analytics_Dashboard::render_analytics_dashboard()
        // Using Market Analytics V3 for proper data structure
        wp_die('This page has been moved. Please use the Market Analytics V3 menu item.');

        // Original code commented out:
        try {
            $market_analytics = $this->plugin->get('market_analytics');

            // Handle filter form submission
            $filters = $this->process_market_filters();
            $period_days = isset($_GET['period_days']) ? intval($_GET['period_days']) : 90;

            // Get comprehensive analytics data using ALL database fields
            try {
                $comprehensive_data = $market_analytics->get_comprehensive_market_data($filters, $period_days);
                
                // Extract data for display
                $market_overview_raw = $comprehensive_data['market_overview'];
                $geographic_analysis = $comprehensive_data['geographic_intelligence'];
                $property_analysis = $comprehensive_data['property_analytics'];
                $financial_intelligence = $comprehensive_data['financial_intelligence'] ?? [];
                $feature_analysis = $comprehensive_data['feature_analysis'] ?? [];
                $agent_analysis = $comprehensive_data['agent_performance'] ?? [];
                
                // Create compatible structures for existing cards
                $active_data = $market_overview_raw['active'] ?? [];
                $closed_data = $market_overview_raw['closed'] ?? [];
                
                // Calculate totals for display
                $total_records = $active_data['total_active'] ?? 0;
                $avg_list_price = $active_data['avg_list_price'] ?? 0;
                $avg_dom = $closed_data['avg_dom'] ?? $active_data['avg_days_listed'] ?? 0;
                
                // Set up data for existing card structure
                $market_overview = [
                    'total_active_listings' => $total_records,
                    'avg_list_price' => $avg_list_price,
                    'median_list_price' => $avg_list_price, // Use avg as median for now
                    'min_list_price' => $active_data['min_price'] ?? 0,
                    'max_list_price' => $active_data['max_price'] ?? 0,
                    'avg_days_listed' => $avg_dom,
                    'closed_listings_period' => $closed_data['total_closed'] ?? 0,
                    'avg_close_price' => $closed_data['avg_close_price'] ?? 0,
                    'median_sold_price' => $closed_data['avg_close_price'] ?? 0,
                    'new_listings_period' => $closed_data['total_closed'] ?? 0,
                    'price_changes_period' => 0,
                    'inventory_months' => $market_overview_raw['inventory_months'] ?? 0,
                    'market_velocity' => [
                        'turnover_rate' => round($market_overview_raw['inventory_months'] ?? 0, 1),
                        'market_temperature' => $market_overview_raw['market_temperature'] ?? 'Unknown'
                    ]
                ];
                
                $dom_analysis = ['average_dom' => $avg_dom, 'median_dom' => $avg_dom];
                $inventory_analysis = [
                    'market_balance' => $market_overview_raw['market_temperature'] ?? 'Market Analysis',
                    'months_of_inventory' => $market_overview_raw['inventory_months'] ?? 0,
                    'total_active_listings' => $total_records
                ];
                
                $using_filters = !empty($filters);
                error_log("BME: Successfully loaded comprehensive data - Active: {$total_records}, Closed: " . ($closed_data['total_closed'] ?? 0));
                
            } catch (Exception $e) {
                error_log('BME: Error getting comprehensive data, falling back to basic: ' . $e->getMessage());
                
                // Fallback to basic data
                try {
                    $market_overview = $market_analytics->get_market_overview($period_days);
                    $dom_analysis = $market_analytics->get_dom_analysis($period_days);
                    $inventory_analysis = $market_analytics->get_inventory_analysis();
                    $total_records = $market_overview['total_active_listings'] ?? 0;
                    $using_filters = false;
                    
                    // Set empty arrays for missing comprehensive data
                    $geographic_analysis = ['detailed_areas' => [], 'top_cities' => []];
                    $property_analysis = ['detailed_breakdown' => [], 'by_property_type' => []];
                    $feature_analysis = ['features' => []];
                    $agent_analysis = ['top_agents' => []];
                } catch (Exception $e2) {
                    error_log('BME: Fallback also failed: ' . $e2->getMessage());
                    // Create minimal data structure to prevent page errors
                    $market_overview = ['total_active_listings' => 0];
                    $dom_analysis = ['average_dom' => 0];
                    $inventory_analysis = ['market_balance' => 'No Data'];
                    $total_records = 0;
                    $using_filters = false;
                    $geographic_analysis = $property_analysis = $feature_analysis = $agent_analysis = [];
                }
            }
            
            // Get filter options for dropdowns
            $filter_options = $this->get_market_filter_options($market_analytics, $filters);
            
        } catch (Exception $e) {
            error_log('BME: Fatal error in render_market_analytics: ' . $e->getMessage());
            echo '<div class="wrap"><h1>Market Analytics Dashboard</h1>';
            echo '<div class="notice notice-error"><p>Error loading market analytics: ' . esc_html($e->getMessage()) . '</p></div>';
            echo '</div>';
            return;
        }
        
        ?>
        <div class="wrap bme-market-analytics">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1><?php _e('Market Analytics Dashboard', 'bridge-mls-extractor-pro'); ?></h1>
                <div class="bme-report-actions">
                    <a href="<?php echo admin_url('admin.php?page=bme-market-analytics&action=download_report&format=html'); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> <?php _e('Download HTML Report', 'bridge-mls-extractor-pro'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=bme-market-analytics&action=download_report&format=csv'); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-media-spreadsheet"></span> <?php _e('Download CSV Report', 'bridge-mls-extractor-pro'); ?>
                    </a>
                </div>
            </div>
            
            <!-- Advanced Filtering Interface -->
            <div class="bme-market-filters">
                <div class="bme-filter-header">
                    <h2><?php _e('Market Filters', 'bridge-mls-extractor-pro'); ?></h2>
                    <button type="button" class="button button-secondary" id="bme-toggle-filters">
                        <span class="dashicons dashicons-filter"></span> 
                        <?php _e('Toggle Filters', 'bridge-mls-extractor-pro'); ?>
                    </button>
                </div>
                
                <form method="get" action="" id="bme-market-filter-form" class="bme-filter-form">
                    <input type="hidden" name="post_type" value="bme_extraction">
                    <input type="hidden" name="page" value="bme-market-analytics">
                    
                    <div class="bme-filter-sections">
                        <!-- Geographic Filters -->
                        <div class="bme-filter-section">
                            <h3><?php _e('Geographic', 'bridge-mls-extractor-pro'); ?></h3>
                            <div class="bme-filter-grid">
                                <div class="bme-filter-field">
                                    <label><?php _e('City', 'bridge-mls-extractor-pro'); ?></label>
                                    <select name="city[]" id="bme-filter-city" multiple data-filter-field="city">
                                        <option value=""><?php _e('All Cities', 'bridge-mls-extractor-pro'); ?></option>
                                        <?php foreach ($filter_options['city'] as $option): ?>
                                            <option value="<?php echo esc_attr($option['value']); ?>" 
                                                    <?php selected(in_array($option['value'], $filters['city'] ?? [])); ?>>
                                                <?php echo esc_html($option['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label><?php _e('Subdivision', 'bridge-mls-extractor-pro'); ?></label>
                                    <select name="subdivision[]" id="bme-filter-subdivision" multiple data-filter-field="subdivision">
                                        <option value=""><?php _e('All Subdivisions', 'bridge-mls-extractor-pro'); ?></option>
                                        <?php foreach ($filter_options['subdivision'] as $option): ?>
                                            <option value="<?php echo esc_attr($option['value']); ?>" 
                                                    <?php selected(in_array($option['value'], $filters['subdivision'] ?? [])); ?>>
                                                <?php echo esc_html($option['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label><?php _e('MLS Area', 'bridge-mls-extractor-pro'); ?></label>
                                    <select name="mls_area[]" id="bme-filter-mls-area" multiple data-filter-field="mls_area">
                                        <option value=""><?php _e('All MLS Areas', 'bridge-mls-extractor-pro'); ?></option>
                                        <?php foreach ($filter_options['mls_area'] as $option): ?>
                                            <option value="<?php echo esc_attr($option['value']); ?>" 
                                                    <?php selected(in_array($option['value'], $filters['mls_area'] ?? [])); ?>>
                                                <?php echo esc_html($option['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Property Filters -->
                        <div class="bme-filter-section">
                            <h3><?php _e('Property Details', 'bridge-mls-extractor-pro'); ?></h3>
                            <div class="bme-filter-grid">
                                <div class="bme-filter-field">
                                    <label><?php _e('Property Type', 'bridge-mls-extractor-pro'); ?></label>
                                    <select name="property_type[]" id="bme-filter-property-type" multiple>
                                        <option value=""><?php _e('All Types', 'bridge-mls-extractor-pro'); ?></option>
                                        <?php foreach ($filter_options['property_type'] as $option): ?>
                                            <option value="<?php echo esc_attr($option['value']); ?>" 
                                                    <?php selected(in_array($option['value'], $filters['property_type'] ?? [])); ?>>
                                                <?php echo esc_html($option['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label><?php _e('Property Sub Type', 'bridge-mls-extractor-pro'); ?></label>
                                    <select name="property_sub_type[]" id="bme-filter-property-sub-type" multiple>
                                        <option value=""><?php _e('All Sub Types', 'bridge-mls-extractor-pro'); ?></option>
                                        <?php foreach ($filter_options['property_sub_type'] as $option): ?>
                                            <option value="<?php echo esc_attr($option['value']); ?>" 
                                                    <?php selected(in_array($option['value'], $filters['property_sub_type'] ?? [])); ?>>
                                                <?php echo esc_html($option['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label><?php _e('Bedrooms', 'bridge-mls-extractor-pro'); ?></label>
                                    <div class="bme-range-inputs">
                                        <input type="number" name="bedrooms_min" placeholder="Min" 
                                               value="<?php echo esc_attr($filters['bedrooms_min'] ?? ''); ?>" min="0" max="20">
                                        <span>-</span>
                                        <input type="number" name="bedrooms_max" placeholder="Max" 
                                               value="<?php echo esc_attr($filters['bedrooms_max'] ?? ''); ?>" min="0" max="20">
                                    </div>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label><?php _e('Bathrooms', 'bridge-mls-extractor-pro'); ?></label>
                                    <div class="bme-range-inputs">
                                        <input type="number" name="bathrooms_min" placeholder="Min" step="0.5"
                                               value="<?php echo esc_attr($filters['bathrooms_min'] ?? ''); ?>" min="0" max="20">
                                        <span>-</span>
                                        <input type="number" name="bathrooms_max" placeholder="Max" step="0.5"
                                               value="<?php echo esc_attr($filters['bathrooms_max'] ?? ''); ?>" min="0" max="20">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Financial Filters -->
                        <div class="bme-filter-section">
                            <h3><?php _e('Financial', 'bridge-mls-extractor-pro'); ?></h3>
                            <div class="bme-filter-grid">
                                <div class="bme-filter-field">
                                    <label><?php _e('Price Range', 'bridge-mls-extractor-pro'); ?></label>
                                    <div class="bme-range-inputs">
                                        <input type="number" name="price_min" placeholder="Min Price" 
                                               value="<?php echo esc_attr($filters['price_min'] ?? ''); ?>" min="0" step="1000">
                                        <span>-</span>
                                        <input type="number" name="price_max" placeholder="Max Price" 
                                               value="<?php echo esc_attr($filters['price_max'] ?? ''); ?>" min="0" step="1000">
                                    </div>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label><?php _e('Square Footage', 'bridge-mls-extractor-pro'); ?></label>
                                    <div class="bme-range-inputs">
                                        <input type="number" name="sqft_min" placeholder="Min Sq Ft" 
                                               value="<?php echo esc_attr($filters['sqft_min'] ?? ''); ?>" min="0" step="100">
                                        <span>-</span>
                                        <input type="number" name="sqft_max" placeholder="Max Sq Ft" 
                                               value="<?php echo esc_attr($filters['sqft_max'] ?? ''); ?>" min="0" step="100">
                                    </div>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label><?php _e('Year Built', 'bridge-mls-extractor-pro'); ?></label>
                                    <div class="bme-range-inputs">
                                        <input type="number" name="year_built_min" placeholder="Min Year" 
                                               value="<?php echo esc_attr($filters['year_built_min'] ?? ''); ?>" min="1800" max="<?php echo date('Y'); ?>">
                                        <span>-</span>
                                        <input type="number" name="year_built_max" placeholder="Max Year" 
                                               value="<?php echo esc_attr($filters['year_built_max'] ?? ''); ?>" min="1800" max="<?php echo date('Y'); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Feature Filters -->
                        <div class="bme-filter-section">
                            <h3><?php _e('Features', 'bridge-mls-extractor-pro'); ?></h3>
                            <div class="bme-filter-grid">
                                <div class="bme-filter-field">
                                    <label>
                                        <input type="checkbox" name="waterfront" value="true" 
                                               <?php checked($filters['waterfront'] ?? '', 'true'); ?>>
                                        <?php _e('Waterfront', 'bridge-mls-extractor-pro'); ?>
                                    </label>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label>
                                        <input type="checkbox" name="pool" value="true" 
                                               <?php checked($filters['pool'] ?? '', 'true'); ?>>
                                        <?php _e('Pool', 'bridge-mls-extractor-pro'); ?>
                                    </label>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label>
                                        <input type="checkbox" name="fireplace" value="true" 
                                               <?php checked($filters['fireplace'] ?? '', 'true'); ?>>
                                        <?php _e('Fireplace', 'bridge-mls-extractor-pro'); ?>
                                    </label>
                                </div>
                                
                                <div class="bme-filter-field">
                                    <label>
                                        <input type="checkbox" name="garage" value="true" 
                                               <?php checked($filters['garage'] ?? '', 'true'); ?>>
                                        <?php _e('Garage', 'bridge-mls-extractor-pro'); ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Period Selection -->
                        <div class="bme-filter-section">
                            <h3><?php _e('Time Period', 'bridge-mls-extractor-pro'); ?></h3>
                            <div class="bme-filter-grid">
                                <div class="bme-filter-field">
                                    <label><?php _e('Analysis Period', 'bridge-mls-extractor-pro'); ?></label>
                                    <select name="period_days">
                                        <option value="30" <?php selected($period_days, 30); ?>>30 Days</option>
                                        <option value="60" <?php selected($period_days, 60); ?>>60 Days</option>
                                        <option value="90" <?php selected($period_days, 90); ?>>90 Days</option>
                                        <option value="180" <?php selected($period_days, 180); ?>>6 Months</option>
                                        <option value="365" <?php selected($period_days, 365); ?>>1 Year</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bme-filter-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-search"></span> <?php _e('Apply Filters', 'bridge-mls-extractor-pro'); ?>
                        </button>
                        <a href="<?php echo admin_url('admin.php?page=bme-market-analytics'); ?>" class="button">
                            <span class="dashicons dashicons-dismiss"></span> <?php _e('Clear Filters', 'bridge-mls-extractor-pro'); ?>
                        </a>
                        <div class="bme-filter-summary">
                            <?php if (!empty($filters)): ?>
                                <span class="bme-results-count">
                                    <?php echo sprintf(__('Showing %s listings', 'bridge-mls-extractor-pro'), number_format($total_records)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Market Overview Cards -->
            <div class="bme-market-overview-cards">
                <div class="bme-market-card bme-card-primary">
                    <h3><?php _e('Active Listings', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo number_format($market_overview['total_active_listings'] ?? $market_overview['active_listings'] ?? 0); ?></div>
                    <div class="bme-card-subtitle">
                        <?php echo $inventory_analysis['market_balance'] ?? 'Market Analysis'; ?>
                    </div>
                </div>
                
                <div class="bme-market-card bme-card-success">
                    <h3><?php _e('Average List Price', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number">$<?php echo number_format($market_overview['median_list_price'] ?? $market_overview['avg_list_price'] ?? 0); ?></div>
                    <div class="bme-card-subtitle">
                        <?php if ($using_filters): ?>
                            Range: $<?php echo number_format($market_overview['min_list_price'] ?? 0); ?> - $<?php echo number_format($market_overview['max_list_price'] ?? 0); ?>
                        <?php else: ?>
                            Sold: $<?php echo number_format($market_overview['median_sold_price'] ?? 0); ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="bme-market-card bme-card-info">
                    <h3><?php _e('Average Days on Market', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo round($dom_analysis['average_dom'] ?? $market_overview['avg_dom'] ?? 0, 1); ?></div>
                    <div class="bme-card-subtitle">
                        <?php if ($using_filters): ?>
                            Filtered listings
                        <?php else: ?>
                            Median: <?php echo round($dom_analysis['median_dom'] ?? 0, 1); ?> days
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="bme-market-card bme-card-warning">
                    <h3><?php _e('Market Activity', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo $market_overview['market_velocity']['turnover_rate'] ?? 0; ?>%</div>
                    <div class="bme-card-subtitle">
                        <?php echo $market_overview['market_velocity']['market_temperature'] ?? 'Activity'; ?>
                    </div>
                </div>
                
                <div class="bme-market-card bme-card-neutral">
                    <h3><?php _e('Total Records', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo number_format($total_records); ?></div>
                    <div class="bme-card-subtitle">
                        <?php if ($using_filters): ?>
                            Filtered results
                        <?php else: ?>
                            <?php echo $inventory_analysis['months_of_inventory'] ?? 0; ?> months inventory
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="bme-market-card bme-card-secondary">
                    <h3><?php _e('Period Activity', 'bridge-mls-extractor-pro'); ?></h3>
                    <div class="bme-card-number"><?php echo number_format($market_overview['new_listings_period'] ?? $market_overview['closed_listings_period'] ?? 0); ?></div>
                    <div class="bme-card-subtitle">
                        <?php if ($using_filters): ?>
                            Last <?php echo $period_days; ?> days
                        <?php else: ?>
                            <?php echo number_format($market_overview['closed_listings_period'] ?? 0); ?> closed
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Charts Section -->
            <div class="bme-analytics-charts">
                <div class="bme-chart-container">
                    <h2><?php _e('Price Trends (90 Days)', 'bridge-mls-extractor-pro'); ?></h2>
                    <div class="bme-chart-wrapper">
                        <canvas id="priceTrendsChart" width="400" height="200"></canvas>
                    </div>
                </div>
                
                <div class="bme-chart-container">
                    <h2><?php _e('Days on Market Distribution', 'bridge-mls-extractor-pro'); ?></h2>
                    <div class="bme-chart-wrapper">
                        <canvas id="domDistributionChart" width="400" height="200"></canvas>
                    </div>
                </div>
                
                <div class="bme-chart-container">
                    <h2><?php _e('Inventory by Status', 'bridge-mls-extractor-pro'); ?></h2>
                    <div class="bme-chart-wrapper">
                        <canvas id="inventoryStatusChart" width="400" height="200"></canvas>
                    </div>
                </div>
                
                <div class="bme-chart-container">
                    <h2><?php _e('Market Absorption Rate', 'bridge-mls-extractor-pro'); ?></h2>
                    <div class="bme-chart-wrapper">
                        <canvas id="absorptionRateChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Filtered Analytics Sections (show when filters are active) -->
            <?php if ($using_filters && !empty($filters)): ?>
                <div class="bme-filtered-analytics">
                    <h2><?php _e('Filtered Market Analysis', 'bridge-mls-extractor-pro'); ?></h2>
                    
                    <!-- Geographic Analysis -->
                    <?php if (!empty($geographic_analysis['cities'])): ?>
                    <div class="bme-analytics-section">
                        <h3><?php _e('Geographic Distribution', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-analytics-grid">
                            <div class="bme-analytics-table">
                                <h4><?php _e('Top Cities', 'bridge-mls-extractor-pro'); ?></h4>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('City', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Listings', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Avg Price', 'bridge-mls-extractor-pro'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($geographic_analysis['cities'], 0, 10) as $city): ?>
                                        <tr>
                                            <td><?php echo esc_html($city['city']); ?></td>
                                            <td><?php echo number_format($city['count']); ?></td>
                                            <td>$<?php echo number_format($city['avg_price']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="bme-analytics-table">
                                <h4><?php _e('MLS Areas', 'bridge-mls-extractor-pro'); ?></h4>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('MLS Area', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Listings', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Avg Price', 'bridge-mls-extractor-pro'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($geographic_analysis['mls_areas'], 0, 10) as $area): ?>
                                        <tr>
                                            <td><?php echo esc_html($area['mls_area']); ?></td>
                                            <td><?php echo number_format($area['count']); ?></td>
                                            <td>$<?php echo number_format($area['avg_price']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Property Analysis -->
                    <?php if (!empty($property_analysis['property_types'])): ?>
                    <div class="bme-analytics-section">
                        <h3><?php _e('Property Analysis', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-analytics-grid">
                            <div class="bme-analytics-table">
                                <h4><?php _e('Property Types', 'bridge-mls-extractor-pro'); ?></h4>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Property Type', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Count', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Avg Price', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Avg Sq Ft', 'bridge-mls-extractor-pro'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($property_analysis['property_types'] as $type): ?>
                                        <tr>
                                            <td><?php echo esc_html($type['property_type']); ?></td>
                                            <td><?php echo number_format($type['count']); ?></td>
                                            <td>$<?php echo number_format($type['avg_price']); ?></td>
                                            <td><?php echo number_format($type['avg_sqft']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="bme-analytics-table">
                                <h4><?php _e('Bedroom Distribution', 'bridge-mls-extractor-pro'); ?></h4>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Bedrooms', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Count', 'bridge-mls-extractor-pro'); ?></th>
                                            <th><?php _e('Avg Price', 'bridge-mls-extractor-pro'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($property_analysis['bedroom_distribution'] as $bedroom): ?>
                                        <tr>
                                            <td><?php echo esc_html($bedroom['bedrooms_total']); ?> BR</td>
                                            <td><?php echo number_format($bedroom['count']); ?></td>
                                            <td>$<?php echo number_format($bedroom['avg_price']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Feature Analysis -->
                    <?php if (!empty($feature_analysis['features'])): ?>
                    <div class="bme-analytics-section">
                        <h3><?php _e('Feature Analysis', 'bridge-mls-extractor-pro'); ?></h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Feature', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('With Feature', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Avg Price (With)', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Avg Price (Without)', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Price Premium', 'bridge-mls-extractor-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($feature_analysis['features'] as $feature_name => $feature_data): ?>
                                <tr>
                                    <td><?php echo esc_html(ucwords(str_replace('_', ' ', $feature_name))); ?></td>
                                    <td><?php echo number_format($feature_data['with_feature']); ?> (<?php echo round(($feature_data['with_feature'] / ($feature_data['with_feature'] + $feature_data['without_feature'])) * 100, 1); ?>%)</td>
                                    <td>$<?php echo number_format($feature_data['avg_price_with']); ?></td>
                                    <td>$<?php echo number_format($feature_data['avg_price_without']); ?></td>
                                    <td>
                                        <?php if ($feature_data['price_premium'] > 0): ?>
                                            <span class="bme-premium-positive">+<?php echo $feature_data['price_premium']; ?>%</span>
                                        <?php elseif ($feature_data['price_premium'] < 0): ?>
                                            <span class="bme-premium-negative"><?php echo $feature_data['price_premium']; ?>%</span>
                                        <?php else: ?>
                                            <span class="bme-premium-neutral">0%</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Price Distribution -->
                    <?php if (!empty($price_analysis['price_distribution'])): ?>
                    <div class="bme-analytics-section">
                        <h3><?php _e('Price Distribution', 'bridge-mls-extractor-pro'); ?></h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Price Range', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Count', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Percentage', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Avg Price', 'bridge-mls-extractor-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_listings = array_sum(array_column($price_analysis['price_distribution'], 'count'));
                                foreach ($price_analysis['price_distribution'] as $range => $data): 
                                ?>
                                <tr>
                                    <td><?php echo esc_html($range); ?></td>
                                    <td><?php echo number_format($data['count']); ?></td>
                                    <td><?php echo $total_listings > 0 ? round(($data['count'] / $total_listings) * 100, 1) : 0; ?>%</td>
                                    <td>$<?php echo number_format($data['avg_price']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- Detailed Analytics Tables -->
            <div class="bme-analytics-tables">
                <div class="bme-table-section">
                    <h2><?php _e('Days on Market by Price Range', 'bridge-mls-extractor-pro'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Price Range', 'bridge-mls-extractor-pro'); ?></th>
                                <th><?php _e('Average DOM', 'bridge-mls-extractor-pro'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($dom_analysis['dom_by_price_range'])): ?>
                                <?php foreach ($dom_analysis['dom_by_price_range'] as $range => $avg_dom): ?>
                                    <tr>
                                        <td><?php echo esc_html($range); ?></td>
                                        <td><?php echo esc_html($avg_dom); ?> days</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2"><?php _e('No data available for the selected period.', 'bridge-mls-extractor-pro'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="bme-table-section">
                    <h2><?php _e('Performance by Property Type', 'bridge-mls-extractor-pro'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Property Type', 'bridge-mls-extractor-pro'); ?></th>
                                <th><?php _e('Average DOM', 'bridge-mls-extractor-pro'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($dom_analysis['dom_by_property_type'])): ?>
                                <?php foreach ($dom_analysis['dom_by_property_type'] as $type => $avg_dom): ?>
                                    <tr>
                                        <td><?php echo esc_html($type); ?></td>
                                        <td><?php echo esc_html($avg_dom); ?> days</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2"><?php _e('No data available for the selected period.', 'bridge-mls-extractor-pro'); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="bme-table-section">
                    <h2><?php _e('Market Summary', 'bridge-mls-extractor-pro'); ?></h2>
                    <div class="bme-market-summary">
                        <div class="bme-summary-item">
                            <strong><?php _e('Fast Sales (≤7 days):', 'bridge-mls-extractor-pro'); ?></strong>
                            <?php 
                            $fast_sales = isset($dom_analysis['fast_sales']) ? $dom_analysis['fast_sales'] : 0;
                            $total_closed = isset($dom_analysis['total_closed_listings']) ? $dom_analysis['total_closed_listings'] : 0;
                            echo $fast_sales; ?> listings (<?php echo $total_closed > 0 ? round(($fast_sales / $total_closed) * 100, 1) : 0; ?>%)
                        </div>
                        <div class="bme-summary-item">
                            <strong><?php _e('Normal Sales (8-45 days):', 'bridge-mls-extractor-pro'); ?></strong>
                            <?php 
                            $normal_sales = isset($dom_analysis['normal_sales']) ? $dom_analysis['normal_sales'] : 0;
                            echo $normal_sales; ?> listings (<?php echo $total_closed > 0 ? round(($normal_sales / $total_closed) * 100, 1) : 0; ?>%)
                        </div>
                        <div class="bme-summary-item">
                            <strong><?php _e('Slow Sales (>45 days):', 'bridge-mls-extractor-pro'); ?></strong>
                            <?php 
                            $slow_sales = isset($dom_analysis['slow_sales']) ? $dom_analysis['slow_sales'] : 0;
                            echo $slow_sales; ?> listings (<?php echo $total_closed > 0 ? round(($slow_sales / $total_closed) * 100, 1) : 0; ?>%)
                        </div>
                        <div class="bme-summary-item">
                            <strong><?php _e('Price Changes (90 days):', 'bridge-mls-extractor-pro'); ?></strong>
                            <?php echo number_format($market_overview['price_changes_period']); ?> changes
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- COMPREHENSIVE DATA DISPLAY SECTIONS -->
            
            <!-- Financial Intelligence Section -->
            <?php if (!empty($financial_intelligence) && $financial_intelligence['total_listings_analyzed'] > 0): ?>
            <div class="bme-analytics-section">
                <h2><?php _e('📊 Financial Intelligence', 'bridge-mls-extractor-pro'); ?></h2>
                
                <div class="bme-analytics-grid">
                    <!-- Price Analytics -->
                    <div class="bme-analytics-card">
                        <h3><?php _e('Price Analytics', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-metrics-grid">
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Median List Price', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value">$<?php echo number_format($financial_intelligence['price_analytics']['median_list_price'] ?? 0); ?></div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Median Sold Price', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value">$<?php echo number_format($financial_intelligence['price_analytics']['median_sold_price'] ?? 0); ?></div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Price Reductions', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $financial_intelligence['price_analytics']['price_reductions']['percentage'] ?? 0; ?>%</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Carrying Costs -->
                    <div class="bme-analytics-card">
                        <h3><?php _e('Carrying Costs', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-metrics-grid">
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Avg Property Tax', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value">$<?php echo number_format($financial_intelligence['cost_analysis']['property_taxes']['average_annual'] ?? 0); ?>/yr</div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Avg HOA Fee', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value">$<?php echo number_format($financial_intelligence['cost_analysis']['hoa_fees']['average_monthly'] ?? 0); ?>/mo</div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Properties w/ HOA', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $financial_intelligence['cost_analysis']['hoa_fees']['percentage_with_hoa'] ?? 0; ?>%</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Investment Metrics -->
                    <?php if (($financial_intelligence['investment_metrics']['investment_property_count'] ?? 0) > 0): ?>
                    <div class="bme-analytics-card">
                        <h3><?php _e('Investment Analytics', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-metrics-grid">
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Investment Properties', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $financial_intelligence['investment_metrics']['percentage_investment'] ?? 0; ?>%</div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Avg Cap Rate', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $financial_intelligence['investment_metrics']['cap_rates']['average'] ?? 0; ?>%</div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Avg Cash Return', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $financial_intelligence['investment_metrics']['cash_on_cash_returns']['average'] ?? 0; ?>%</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Feature Analysis Section -->
            <?php if (!empty($feature_analysis) && $feature_analysis['total_properties_analyzed'] > 0): ?>
            <div class="bme-analytics-section">
                <h2><?php _e('🏠 Feature Analysis', 'bridge-mls-extractor-pro'); ?></h2>
                
                <div class="bme-analytics-grid">
                    <!-- Luxury Features -->
                    <?php if (!empty($feature_analysis['luxury_features'])): ?>
                    <div class="bme-analytics-card">
                        <h3><?php _e('Luxury Features', 'bridge-mls-extractor-pro'); ?></h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Feature', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Properties', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Percentage', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Median Price', 'bridge-mls-extractor-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($feature_analysis['luxury_features'], 0, 5) as $feature => $data): ?>
                                <tr>
                                    <td><?php echo esc_html(ucwords(str_replace('_', ' ', $feature))); ?></td>
                                    <td><?php echo number_format($data['count'] ?? 0); ?></td>
                                    <td><?php echo ($data['percentage'] ?? 0); ?>%</td>
                                    <td>$<?php echo number_format($data['median_price'] ?? 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Parking Analysis -->
                    <?php if (!empty($feature_analysis['parking_analysis'])): ?>
                    <div class="bme-analytics-card">
                        <h3><?php _e('Parking Analysis', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-metrics-grid">
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Properties w/ Garage', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $feature_analysis['parking_analysis']['garage']['percentage'] ?? 0; ?>%</div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Avg Garage Spaces', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $feature_analysis['parking_analysis']['garage']['average_spaces'] ?? 0; ?></div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Avg Total Parking', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $feature_analysis['parking_analysis']['total_parking']['average_spaces'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Energy Efficiency -->
                    <?php if (!empty($feature_analysis['energy_efficiency'])): ?>
                    <div class="bme-analytics-card">
                        <h3><?php _e('Energy & Green Features', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-metrics-grid">
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Energy Efficient', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $feature_analysis['energy_efficiency']['energy_efficient']['percentage'] ?? 0; ?>%</div>
                            </div>
                            <div class="bme-metric">
                                <div class="bme-metric-label"><?php _e('Green Certified', 'bridge-mls-extractor-pro'); ?></div>
                                <div class="bme-metric-value"><?php echo $feature_analysis['energy_efficiency']['green_certified']['percentage'] ?? 0; ?>%</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Agent & Office Performance Section -->
            <?php if (!empty($agent_analysis) && ($agent_analysis['total_agents_analyzed'] ?? 0) > 0): ?>
            <div class="bme-analytics-section">
                <h2><?php _e('👥 Agent & Office Performance', 'bridge-mls-extractor-pro'); ?></h2>
                
                <div class="bme-analytics-grid">
                    <!-- Top Agents by Volume -->
                    <?php if (!empty($agent_analysis['top_performers']['top_by_volume'])): ?>
                    <div class="bme-analytics-card">
                        <h3><?php _e('Top Agents by Volume', 'bridge-mls-extractor-pro'); ?></h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Agent', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Listings', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Volume Sold', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Success Rate', 'bridge-mls-extractor-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($agent_analysis['top_performers']['top_by_volume'], 0, 5, true) as $agent_key => $agent_data): ?>
                                <tr>
                                    <td><?php echo esc_html($agent_data['agent_info']['name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo number_format($agent_data['listing_metrics']['total_listings'] ?? 0); ?></td>
                                    <td>$<?php echo number_format($agent_data['price_metrics']['total_volume_sold'] ?? 0); ?></td>
                                    <td><?php echo ($agent_data['listing_metrics']['success_rate'] ?? 0); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Market Share -->
                    <?php if (!empty($agent_analysis['market_share_analysis']['agent_market_share'])): ?>
                    <div class="bme-analytics-card">
                        <h3><?php _e('Agent Market Share', 'bridge-mls-extractor-pro'); ?></h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Agent', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Listings', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Market Share', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Volume Share', 'bridge-mls-extractor-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($agent_analysis['market_share_analysis']['agent_market_share'], 0, 5, true) as $agent_key => $agent_data): ?>
                                <tr>
                                    <td><?php echo esc_html($agent_data['agent_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo number_format($agent_data['listing_count'] ?? 0); ?></td>
                                    <td><?php echo ($agent_data['listing_share'] ?? 0); ?>%</td>
                                    <td><?php echo ($agent_data['volume_share'] ?? 0); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Office Performance -->
                    <?php if (!empty($agent_analysis['market_share_analysis']['office_market_share'])): ?>
                    <div class="bme-analytics-card">
                        <h3><?php _e('Top Offices', 'bridge-mls-extractor-pro'); ?></h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Office', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Agents', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Listings', 'bridge-mls-extractor-pro'); ?></th>
                                    <th><?php _e('Market Share', 'bridge-mls-extractor-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($agent_analysis['market_share_analysis']['office_market_share'], 0, 5, true) as $office_key => $office_data): ?>
                                <tr>
                                    <td><?php echo esc_html($office_data['office_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo number_format($office_data['agent_count'] ?? 0); ?></td>
                                    <td><?php echo number_format($office_data['listing_count'] ?? 0); ?></td>
                                    <td><?php echo ($office_data['listing_share'] ?? 0); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Advanced Market Intelligence Section -->
            <div class="bme-analytics-section">
                <h2><?php _e('🧠 Market Intelligence Summary', 'bridge-mls-extractor-pro'); ?></h2>
                
                <div class="bme-intelligence-summary">
                    <div class="bme-intelligence-card">
                        <h3><?php _e('Data Coverage', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-coverage-stats">
                            <div class="bme-coverage-item">
                                <span class="bme-coverage-label"><?php _e('Financial Data', 'bridge-mls-extractor-pro'); ?></span>
                                <span class="bme-coverage-value">
                                    <?php echo number_format($financial_intelligence['total_listings_analyzed'] ?? 0); ?> 
                                    <?php _e('properties analyzed', 'bridge-mls-extractor-pro'); ?>
                                </span>
                            </div>
                            <div class="bme-coverage-item">
                                <span class="bme-coverage-label"><?php _e('Feature Analysis', 'bridge-mls-extractor-pro'); ?></span>
                                <span class="bme-coverage-value">
                                    <?php echo number_format($feature_analysis['total_properties_analyzed'] ?? 0); ?> 
                                    <?php _e('properties analyzed', 'bridge-mls-extractor-pro'); ?>
                                </span>
                            </div>
                            <div class="bme-coverage-item">
                                <span class="bme-coverage-label"><?php _e('Agent Performance', 'bridge-mls-extractor-pro'); ?></span>
                                <span class="bme-coverage-value">
                                    <?php echo number_format($agent_analysis['total_agents_analyzed'] ?? 0); ?> 
                                    <?php _e('agents analyzed', 'bridge-mls-extractor-pro'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bme-intelligence-card">
                        <h3><?php _e('System Capabilities', 'bridge-mls-extractor-pro'); ?></h3>
                        <div class="bme-capabilities-list">
                            <div class="bme-capability-item">✅ <?php _e('500+ Database Fields Analyzed', 'bridge-mls-extractor-pro'); ?></div>
                            <div class="bme-capability-item">✅ <?php _e('Comprehensive Financial Intelligence', 'bridge-mls-extractor-pro'); ?></div>
                            <div class="bme-capability-item">✅ <?php _e('Advanced Feature Analysis', 'bridge-mls-extractor-pro'); ?></div>
                            <div class="bme-capability-item">✅ <?php _e('Agent & Office Performance Metrics', 'bridge-mls-extractor-pro'); ?></div>
                            <div class="bme-capability-item">✅ <?php _e('Market Intelligence & Trends', 'bridge-mls-extractor-pro'); ?></div>
                            <div class="bme-capability-item">✅ <?php _e('125+ Advanced Filters Available', 'bridge-mls-extractor-pro'); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="bme-data-timestamp">
                    <p><strong><?php _e('Report Generated:', 'bridge-mls-extractor-pro'); ?></strong> <?php echo date('F j, Y \a\t g:i A'); ?></p>
                    <p><strong><?php _e('Analysis Period:', 'bridge-mls-extractor-pro'); ?></strong> <?php echo $period_days; ?> <?php _e('days', 'bridge-mls-extractor-pro'); ?></p>
                    <p><strong><?php _e('Total Properties Analyzed:', 'bridge-mls-extractor-pro'); ?></strong> <?php echo number_format($total_records); ?></p>
                    <?php if ($using_filters): ?>
                        <p><strong><?php _e('Filters Applied:', 'bridge-mls-extractor-pro'); ?></strong> <?php _e('Yes', 'bridge-mls-extractor-pro'); ?> (<?php echo count($filters); ?> <?php _e('active filters', 'bridge-mls-extractor-pro'); ?>)</p>
                    <?php else: ?>
                        <p><strong><?php _e('Filters Applied:', 'bridge-mls-extractor-pro'); ?></strong> <?php _e('None (Full Market Analysis)', 'bridge-mls-extractor-pro'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Pass data to JavaScript -->
        <script type="text/javascript">
            window.bmeMarketData = {
                priceTrends: <?php echo json_encode($price_trends ?? []); ?>,
                domAnalysis: <?php echo json_encode($dom_analysis ?? []); ?>,
                inventoryAnalysis: <?php echo json_encode($inventory_analysis ?? []); ?>,
                financialIntelligence: <?php echo json_encode($financial_intelligence ?? []); ?>,
                featureAnalysis: <?php echo json_encode($feature_analysis ?? []); ?>,
                agentAnalysis: <?php echo json_encode($agent_analysis ?? []); ?>
            };
        </script>
        <?php
    }
    */

    /**
     * AJAX handler for getting filter preview count
     */
    public function ajax_get_filter_preview() {
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Check nonce for security (optional for preview)
        $nonce = $_POST['nonce'] ?? '';
        if (!empty($nonce) && !wp_verify_nonce($nonce, 'bme_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        try {
            // Get total active listings count
            global $wpdb;
            $tables = $this->plugin->get('db')->get_tables();
            $count = intval($wpdb->get_var(
                "SELECT COUNT(*) FROM {$tables['listings']}
                 WHERE standard_status IN ('Active', 'Active Under Contract', 'Pending')"
            ));

            wp_send_json_success([
                'count' => $count,
                'formatted_count' => number_format($count)
            ]);

        } catch (Exception $e) {
            error_log('BME: Filter preview error: ' . $e->getMessage());
            wp_send_json_error('Error getting filter preview: ' . $e->getMessage());
        }
    }
    
    /**
     * Sanitize filter array for security
     */
    private function sanitize_filter_array($filters) {
        if (!is_array($filters)) {
            return [];
        }
        
        $clean_filters = [];
        
        foreach ($filters as $key => $value) {
            $clean_key = sanitize_text_field($key);
            
            if (is_array($value)) {
                $clean_filters[$clean_key] = array_map('sanitize_text_field', $value);
            } else {
                $clean_value = sanitize_text_field($value);
                if (!empty($clean_value)) {
                    $clean_filters[$clean_key] = $clean_value;
                }
            }
        }
        
        return $clean_filters;
    }
    
    
    
    /**
     * Display admin notice about performance indexes
     */
    public function display_performance_indexes_notice() {
        // Only show on BME admin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'bme') === false) {
            return;
        }
        
        // Check if user can manage options
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if indexes migration has been run
        if (file_exists(BME_PLUGIN_DIR . 'includes/migrations/add-performance-indexes.php')) {
            require_once BME_PLUGIN_DIR . 'includes/migrations/add-performance-indexes.php';
            
            if (!BME_Add_Performance_Indexes::has_run()) {
                // Show notice to run migration
                ?>
                <div class="notice notice-info is-dismissible">
                    <p>
                        <strong>Performance Optimization Available:</strong> 
                        Database indexes can be added to improve query performance. This is optional but recommended for large datasets.
                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=bme_run_performance_indexes'), 'bme_performance_indexes'); ?>" class="button button-primary" style="margin-left: 10px;">
                            Add Performance Indexes
                        </a>
                    </p>
                </div>
                <?php
            }
        }
        
        // Show success message if indexes were just added
        if (isset($_GET['bme_indexes_added']) && $_GET['bme_indexes_added'] == '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Success:</strong> Performance indexes have been added to the database tables.</p>
            </div>
            <?php
        }
    }

    /**
     * AJAX handler for getting comprehensive listing details
     */
    public function ajax_get_listing_details() {
        error_log('BME: ajax_get_listing_details called');

        check_ajax_referer('bme_admin_nonce', 'nonce');
        error_log('BME: Nonce check passed');

        if (!current_user_can('manage_options')) {
            error_log('BME: Permission denied');
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }
        error_log('BME: Permission check passed');

        $listing_db_id = absint($_POST['listing_id'] ?? 0);
        error_log('BME: Listing ID: ' . $listing_db_id);

        if (!$listing_db_id) {
            error_log('BME: Invalid listing ID');
            wp_send_json_error(['message' => 'Invalid listing ID']);
            return;
        }

        global $wpdb;
        error_log('BME: Starting database queries');

        // Get comprehensive listing data from all related tables
        // Note: Using separate queries to avoid column name conflicts
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bme_listings WHERE id = %d",
            $listing_db_id
        ), ARRAY_A);

        if (!$listing) {
            wp_send_json_error(['message' => 'Listing not found']);
            return;
        }

        // Get related data from other tables
        $details = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bme_listing_details WHERE listing_id = %s",
            $listing['listing_id']
        ), ARRAY_A);

        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bme_listing_location WHERE listing_id = %s",
            $listing['listing_id']
        ), ARRAY_A);

        $financial = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bme_listing_financial WHERE listing_id = %s",
            $listing['listing_id']
        ), ARRAY_A);

        $features = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bme_listing_features WHERE listing_id = %s",
            $listing['listing_id']
        ), ARRAY_A);

        $agent = $wpdb->get_row($wpdb->prepare(
            "SELECT agent_full_name, agent_email, agent_phone, agent_mls_id
             FROM {$wpdb->prefix}bme_agents WHERE agent_mls_id = %s",
            $listing['listing_agent_mls_id']
        ), ARRAY_A);

        $office = $wpdb->get_row($wpdb->prepare(
            "SELECT office_name, office_phone
             FROM {$wpdb->prefix}bme_offices WHERE office_mls_id = %s",
            $listing['listing_office_mls_id']
        ), ARRAY_A);

        // Merge all data into a single array
        if ($details) $listing = array_merge($listing, $details);
        if ($location) $listing = array_merge($listing, $location);
        if ($financial) $listing = array_merge($listing, $financial);
        if ($features) $listing = array_merge($listing, $features);
        if ($agent) $listing = array_merge($listing, $agent);
        if ($office) $listing = array_merge($listing, $office);

        // Get photos
        $photos = $wpdb->get_results($wpdb->prepare("
            SELECT media_url, media_category, order_index
            FROM {$wpdb->prefix}bme_media
            WHERE listing_id = %s AND media_category = 'Photo'
            ORDER BY order_index ASC
        ", $listing['listing_id']), ARRAY_A);

        // Get rooms
        $rooms = $wpdb->get_results($wpdb->prepare("
            SELECT room_type, room_level, room_dimensions, room_features
            FROM {$wpdb->prefix}bme_rooms
            WHERE listing_id = %s
            ORDER BY room_type, room_level
        ", $listing['listing_id']), ARRAY_A);

        // Get open houses
        $open_houses_raw = $wpdb->get_results($wpdb->prepare("
            SELECT open_house_key, open_house_data
            FROM {$wpdb->prefix}bme_open_houses
            WHERE listing_id = %s AND sync_status = 'current'
            ORDER BY id ASC
        ", $listing['listing_id']), ARRAY_A);

        // Parse open house JSON data
        $open_houses = [];
        foreach ($open_houses_raw as $oh) {
            if (!empty($oh['open_house_data'])) {
                $data = json_decode($oh['open_house_data'], true);
                if ($data) {
                    $open_houses[] = [
                        'date' => $data['OpenHouseDate'] ?? null,
                        'start_time' => $data['OpenHouseStartTime'] ?? null,
                        'end_time' => $data['OpenHouseEndTime'] ?? null,
                        'method' => $data['OpenHouseMethod'] ?? null,
                        'remarks' => $data['OpenHouseRemarks'] ?? null
                    ];
                }
            }
        }

        // Get property history
        $history = $wpdb->get_results($wpdb->prepare("
            SELECT event_date, event_type, old_price, new_price, old_status, new_status, days_on_market
            FROM {$wpdb->prefix}bme_property_history
            WHERE listing_id = %s
            ORDER BY event_date DESC
        ", $listing['listing_id']), ARRAY_A);

        // Get virtual tours
        $virtual_tours = $wpdb->get_row($wpdb->prepare("
            SELECT virtual_tour_link_1, virtual_tour_link_2, virtual_tour_link_3
            FROM {$wpdb->prefix}bme_virtual_tours
            WHERE listing_id = %d
        ", $listing_db_id), ARRAY_A);

        error_log('BME: Photos count: ' . count($photos));
        error_log('BME: Rooms count: ' . count($rooms));
        error_log('BME: Open houses count: ' . count($open_houses));
        error_log('BME: History count: ' . count($history));

        $response = [
            'listing' => $listing,
            'photos' => $photos,
            'rooms' => $rooms,
            'open_houses' => $open_houses,
            'history' => $history,
            'virtual_tours' => $virtual_tours
        ];

        error_log('BME: Sending JSON success response');
        wp_send_json_success($response);
    }
}