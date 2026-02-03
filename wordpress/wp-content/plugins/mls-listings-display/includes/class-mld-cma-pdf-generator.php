<?php
/**
 * MLD CMA PDF Generator - Enhanced Mobile-Friendly Version
 *
 * Generates professional PDF CMA reports using TCPDF
 * Includes property comparables, adjustments, market analysis, and forecasts
 *
 * v2.0.7 - Hide low-confidence forecast data (Jan 31, 2026):
 * - Skip Market Analysis section if confidence score < 80%
 * - Skip Price Forecast section if confidence score <= 70%
 * - Skip Investment Analysis section if confidence score <= 70%
 * - Skip Market Overview narrative if confidence score <= 70%
 * - Prevents display of unreliable forecast data
 *
 * v2.0.6 - Removed blank pages (Jan 31, 2026):
 * - Market Analysis section now skips entirely if no forecast data
 * - Prevents blank "Market analysis requires sufficient historical data" page
 *
 * v2.0.5 - Added market trend charts (Jan 31, 2026):
 * - New Market Trends Charts section with 3 visual graphs
 * - Price trend line chart (12 months)
 * - Sales volume bar chart
 * - Days on market trend line chart
 * - Custom TCPDF chart rendering methods
 *
 * v2.0.4 - Cover page image overflow fix (Jan 31, 2026):
 * - Fixed hero image extending past 85mm boundary using clipping
 * - Image now vertically centered within clipped area
 *
 * v2.0.3 - PDF text overflow fixes (Jan 31, 2026):
 * - Fixed comparable card text running off page (reduced cell widths to fit 95mm)
 * - Abbreviated labels (BR/BA, mi, ac, Gar, Net Adj) to save space
 * - Limited adjustment factors to 2 items with truncation
 *
 * v2.0.2 - PDF formatting fixes (Jan 31, 2026):
 * - Fixed cover page stats row Y-position alignment (store initial Y before loop)
 * - Fixed comparable card grade badge/price overlap (stacked prices vertically)
 * - Fixed market conditions summary truncation (adaptive font + MultiCell)
 *
 * v2.0.1 - Database schema fix (Jan 31, 2026):
 * - Fixed is_primary column references (doesn't exist in wp_bme_media)
 * - Now uses main_photo_url from summary tables
 * - Removed references to non-existent wp_bme_media_archive table
 *
 * v2.0.0 - Major overhaul for mobile readability:
 * - 40-50% larger font sizes throughout
 * - Agent profile photo and full contact info on cover page
 * - Property photos for all comparables
 * - City market trends section
 * - More comprehensive property details
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load TCPDF if not already loaded
if (!class_exists('TCPDF')) {
    $tcpdf_path = plugin_dir_path(__FILE__) . '../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdf_path)) {
        require_once $tcpdf_path;
    } else {
        // TCPDF not installed - PDF generation will fail gracefully
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD CMA: TCPDF library not found. PDF generation will not work. Install via Composer: composer require tecnickcom/tcpdf');
        }
    }
}

class MLD_CMA_PDF_Generator {

    /**
     * Brand colors
     */
    private $primary_color = array(44, 90, 160); // #2c5aa0
    private $secondary_color = array(30, 66, 120); // #1e4278
    private $success_color = array(40, 167, 69); // #28a745
    private $text_color = array(51, 51, 51); // #333333
    private $light_gray = array(248, 249, 250); // #f8f9fa
    private $medium_gray = array(108, 117, 125); // #6c757d

    /**
     * Font sizes - MOBILE OPTIMIZED (40-50% larger than original)
     */
    private $font_sizes = array(
        'cover_title'    => 36,   // was 28
        'cover_address'  => 28,   // was 22
        'cover_location' => 20,   // was 16
        'section_header' => 22,   // was 16
        'subsection'     => 16,   // was 12
        'body'           => 14,   // was 10
        'body_small'     => 12,   // was 9
        'caption'        => 11,   // was 8
        'stat_value'     => 20,   // was 16
        'stat_label'     => 12,   // was 9
        'metric_label'   => 12,   // was 9
        'metric_value'   => 15,   // was 11
    );

    /**
     * PDF instance
     */
    private $pdf;

    /**
     * Report data
     */
    private $data;

    /**
     * Agent profile data
     */
    private $agent_profile;

    /**
     * Generate CMA PDF report
     *
     * @param array $cma_data CMA data from comparable sales
     * @param array $subject_property Subject property information
     * @param array $options Generation options (branding, agent info, etc.)
     * @return string PDF file path or false on failure
     */
    public function generate_report($cma_data, $subject_property, $options = array()) {
        $this->data = array(
            'cma' => $cma_data,
            'subject' => $subject_property,
            'options' => wp_parse_args($options, $this->get_default_options())
        );

        // Load full agent profile if we have a user ID
        $this->load_agent_profile();

        // Initialize PDF
        $this->initialize_pdf();

        // Generate report sections
        $this->add_cover_page();
        $this->add_executive_summary();
        $this->add_subject_property_section();
        $this->add_comparables_section();
        $this->add_city_market_trends_section();
        $this->add_market_charts_section();  // v2.0.5: Visual charts for market data
        $this->add_market_conditions_section();
        $this->add_market_analysis_section();
        $this->add_forecast_section();
        $this->add_investment_analysis_section();
        $this->add_disclaimer();

        // Save PDF
        return $this->save_pdf();
    }

    /**
     * Load full agent profile from database
     */
    private function load_agent_profile() {
        $this->agent_profile = null;

        // Try to get agent user ID from options
        $agent_user_id = $this->data['options']['agent_user_id'] ?? null;

        if (!$agent_user_id) {
            // Try to get current user if logged in
            $agent_user_id = get_current_user_id();
        }

        if ($agent_user_id) {
            // Use MLD_Agent_Client_Manager to get full profile
            if (class_exists('MLD_Agent_Client_Manager')) {
                $this->agent_profile = MLD_Agent_Client_Manager::get_agent_for_api($agent_user_id);
            }

            // If that fails, try to get from Team Member CPT
            if (empty($this->agent_profile) && class_exists('FFF_Custom_Post_Types')) {
                $team_member_id = FFF_Custom_Post_Types::get_team_member_for_user($agent_user_id);
                if ($team_member_id) {
                    $this->agent_profile = $this->get_team_member_profile($team_member_id);
                }
            }
        }

        // Merge any explicitly provided options (they take precedence)
        if (!empty($this->data['options']['agent_name'])) {
            if (!$this->agent_profile) {
                $this->agent_profile = array();
            }
            // Override with explicit options
            if (!empty($this->data['options']['agent_name'])) {
                $this->agent_profile['name'] = $this->data['options']['agent_name'];
            }
            if (!empty($this->data['options']['agent_email'])) {
                $this->agent_profile['email'] = $this->data['options']['agent_email'];
            }
            if (!empty($this->data['options']['agent_phone'])) {
                $this->agent_profile['phone'] = $this->data['options']['agent_phone'];
            }
            if (!empty($this->data['options']['agent_license'])) {
                $this->agent_profile['license_number'] = $this->data['options']['agent_license'];
            }
            if (!empty($this->data['options']['agent_photo'])) {
                $this->agent_profile['photo_url'] = $this->data['options']['agent_photo'];
            }
        }
    }

    /**
     * Get team member profile data
     */
    private function get_team_member_profile($team_member_id) {
        $post = get_post($team_member_id);
        if (!$post) {
            return null;
        }

        $profile = array(
            'name' => $post->post_title,
            'title' => get_post_meta($team_member_id, '_bne_position', true),
            'email' => get_post_meta($team_member_id, '_bne_email', true),
            'phone' => get_post_meta($team_member_id, '_bne_phone', true),
            'license_number' => get_post_meta($team_member_id, '_bne_license_number', true),
            'bio' => $post->post_content,
        );

        // Get featured image (profile photo)
        $thumbnail_id = get_post_thumbnail_id($team_member_id);
        if ($thumbnail_id) {
            $profile['photo_url'] = wp_get_attachment_image_url($thumbnail_id, 'medium');
        }

        // Get office info from theme options or customizer
        $profile['office_name'] = get_theme_mod('company_name', 'BMN Boston Real Estate');

        return $profile;
    }

    /**
     * Get default options
     *
     * @return array Default options
     */
    private function get_default_options() {
        return array(
            'report_title' => 'Comparative Market Analysis',
            'agent_name' => '',
            'agent_email' => '',
            'agent_phone' => '',
            'agent_license' => '',
            'agent_photo' => '',
            'agent_user_id' => null,
            'brokerage_name' => '',
            'brokerage_logo' => '',
            'report_date' => date('F j, Y'),
            'prepared_for' => '',
            'include_photos' => true,
            'include_forecast' => true,
            'include_investment' => true,
            'watermark' => ''
        );
    }

    /**
     * Initialize PDF document
     */
    private function initialize_pdf() {
        $this->pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);

        // Set document information
        $agent_name = $this->agent_profile['name'] ?? $this->data['options']['agent_name'] ?? 'Real Estate Professional';
        $this->pdf->SetCreator('MLS Listings Display Plugin');
        $this->pdf->SetAuthor($agent_name);
        $this->pdf->SetTitle('CMA Report - ' . ($this->data['subject']['address'] ?? 'Property'));
        $this->pdf->SetSubject('Comparative Market Analysis');

        // Set margins (slightly smaller for mobile)
        $this->pdf->SetMargins(12, 12, 12);
        $this->pdf->SetHeaderMargin(8);
        $this->pdf->SetFooterMargin(8);

        // Set auto page breaks
        $this->pdf->SetAutoPageBreak(true, 12);

        // Set default font - larger for mobile
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);

        // Remove default header/footer
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
    }

    /**
     * Add cover page with agent profile photo and full contact info
     */
    private function add_cover_page() {
        $this->pdf->AddPage();

        // Try to get subject property photo
        $photo_url = $this->get_subject_photo_url();
        $has_photo = false;

        if ($photo_url) {
            $temp_image = $this->download_image_temp($photo_url);
            if ($temp_image) {
                // v2.0.4: Large hero image at top - constrained to max height of 85mm
                $hero_width = 216;
                $hero_max_height = 85;

                // Get image dimensions to calculate aspect ratio
                $img_info = @getimagesize($temp_image);
                if ($img_info) {
                    $img_width = $img_info[0];
                    $img_height = $img_info[1];
                    $img_ratio = $img_width / $img_height;

                    // Calculate height when scaled to full width
                    $scaled_height = $hero_width / $img_ratio;

                    // Use clipping to prevent overflow if image is taller than max
                    if ($scaled_height > $hero_max_height) {
                        // Start clipping region
                        $this->pdf->StartTransform();
                        $this->pdf->Rect(0, 0, $hero_width, $hero_max_height, 'CNZ');
                        // Center the image vertically within the clip area
                        $y_offset = -($scaled_height - $hero_max_height) / 2;
                        $this->pdf->Image($temp_image, 0, $y_offset, $hero_width, $scaled_height, '', '', '', false, 300, '', false, false, 0);
                        $this->pdf->StopTransform();
                    } else {
                        // Image fits, render normally
                        $this->pdf->Image($temp_image, 0, 0, $hero_width, 0, '', '', '', false, 300, '', false, false, 0);
                    }
                } else {
                    // Fallback: render with fixed height (may distort)
                    $this->pdf->Image($temp_image, 0, 0, $hero_width, $hero_max_height, '', '', '', false, 300, '', false, false, 0);
                }

                // Dark overlay gradient effect
                $this->pdf->SetFillColor(0, 0, 0);
                $this->pdf->SetAlpha(0.35);
                $this->pdf->Rect(0, 55, 216, 30, 'F');
                $this->pdf->SetAlpha(1);
                @unlink($temp_image);
                $has_photo = true;
            }
        }

        if (!$has_photo) {
            // Fallback: solid color header
            $this->pdf->SetFillColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
            $this->pdf->Rect(0, 0, 216, 85, 'F');
        }

        // Brokerage logo (top left on photo)
        if (!empty($this->data['options']['brokerage_logo']) && file_exists($this->data['options']['brokerage_logo'])) {
            $this->pdf->Image($this->data['options']['brokerage_logo'], 12, 8, 45, 0, '', '', '', false, 300, '', false, false, 0);
        }

        // Report title with larger font
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['cover_title']);
        $this->pdf->SetXY(12, 92);
        $this->pdf->Cell(0, 12, $this->data['options']['report_title'], 0, 1, 'C');

        // Property address (large)
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['cover_address']);
        $this->pdf->SetTextColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->SetXY(12, 108);
        $address = $this->data['subject']['address'] ?? 'Subject Property';
        $this->pdf->Cell(0, 10, $address, 0, 1, 'C');

        // City, State, ZIP
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['cover_location']);
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $this->pdf->SetXY(12, 120);
        $location = ($this->data['subject']['city'] ?? '') . ', ' . ($this->data['subject']['state'] ?? '');
        if (!empty($this->data['subject']['postal_code'])) {
            $location .= ' ' . $this->data['subject']['postal_code'];
        }
        $this->pdf->Cell(0, 8, $location, 0, 1, 'C');

        // Key property stats row - larger fonts
        $this->pdf->SetY(134);
        $this->render_property_stats_row();

        // ===== AGENT PROFILE SECTION (NEW - PROMINENT) =====
        $y_agent = 160;
        $this->render_agent_profile_section($y_agent);

        // Report details box (Prepared For, Date)
        $y_details = 210;
        $this->pdf->SetFillColor($this->light_gray[0], $this->light_gray[1], $this->light_gray[2]);
        $this->pdf->RoundedRect(25, $y_details, 166, 42, 4, '1111', 'DF', array(), $this->light_gray);

        // Prepared For
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $this->pdf->SetXY(35, $y_details + 8);
        $this->pdf->Cell(50, 6, 'Prepared For:', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body'] + 1);
        $this->pdf->Cell(0, 6, $this->data['options']['prepared_for'] ?: 'Property Owner', 0, 1, 'L');

        // Report Date
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $this->pdf->SetX(35);
        $this->pdf->Cell(50, 6, 'Report Date:', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body'] + 1);
        $this->pdf->Cell(0, 6, $this->data['options']['report_date'], 0, 1, 'L');

        // MLS Number if available
        if (!empty($this->data['subject']['listing_id'])) {
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
            $this->pdf->SetX(35);
            $this->pdf->Cell(50, 6, 'MLS #:', 0, 0, 'L');
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body'] + 1);
            $this->pdf->Cell(0, 6, $this->data['subject']['listing_id'], 0, 1, 'L');
        }
    }

    /**
     * Render agent profile section on cover page
     */
    private function render_agent_profile_section($y_start) {
        // Background card
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor(230, 230, 230);
        $this->pdf->RoundedRect(25, $y_start, 166, 45, 4, '1111', 'DF');

        $agent_name = $this->agent_profile['name'] ?? $this->data['options']['agent_name'] ?? 'Your Real Estate Professional';
        $agent_title = $this->agent_profile['title'] ?? 'Licensed Real Estate Agent';
        $agent_phone = $this->agent_profile['phone'] ?? $this->data['options']['agent_phone'] ?? '';
        $agent_email = $this->agent_profile['email'] ?? $this->data['options']['agent_email'] ?? '';
        $agent_license = $this->agent_profile['license_number'] ?? $this->data['options']['agent_license'] ?? '';
        $agent_photo_url = $this->agent_profile['photo_url'] ?? $this->data['options']['agent_photo'] ?? '';
        $office_name = $this->agent_profile['office_name'] ?? $this->data['options']['brokerage_name'] ?? '';

        // Agent photo (left side)
        $photo_x = 30;
        $photo_y = $y_start + 5;
        $photo_size = 35;
        $has_agent_photo = false;

        if (!empty($agent_photo_url)) {
            $temp_photo = $this->download_image_temp($agent_photo_url);
            if ($temp_photo) {
                // Circular photo (simulated with square crop)
                $this->pdf->Image($temp_photo, $photo_x, $photo_y, $photo_size, $photo_size, '', '', '', false, 300, '', false, false, 0);
                @unlink($temp_photo);
                $has_agent_photo = true;
            }
        }

        if (!$has_agent_photo) {
            // Placeholder circle
            $this->pdf->SetFillColor(220, 220, 220);
            $this->pdf->Circle($photo_x + ($photo_size / 2), $photo_y + ($photo_size / 2), $photo_size / 2, 0, 360, 'F');
            $this->pdf->SetTextColor(150, 150, 150);
            $this->pdf->SetFont('helvetica', '', 20);
            $this->pdf->SetXY($photo_x, $photo_y + 10);
            $this->pdf->Cell($photo_size, 15, chr(64), 0, 0, 'C'); // @ symbol as placeholder
            $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        }

        // Agent info (right of photo)
        $content_x = $photo_x + $photo_size + 10;
        $content_width = 166 - $photo_size - 20;

        // Agent name - large
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['subsection'] + 2);
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $this->pdf->SetXY($content_x, $y_start + 6);
        $this->pdf->Cell($content_width, 6, $agent_name, 0, 1, 'L');

        // Title
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
        $this->pdf->SetXY($content_x, $y_start + 13);
        $this->pdf->Cell($content_width, 5, $agent_title, 0, 1, 'L');

        // Office name
        if (!empty($office_name)) {
            $this->pdf->SetXY($content_x, $y_start + 19);
            $this->pdf->Cell($content_width, 5, $office_name, 0, 1, 'L');
        }

        // Contact info row
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $contact_y = $y_start + 27;

        if (!empty($agent_phone)) {
            $this->pdf->SetXY($content_x, $contact_y);
            $this->pdf->Cell(60, 5, chr(9742) . ' ' . $agent_phone, 0, 0, 'L'); // Phone icon
        }

        if (!empty($agent_email)) {
            $this->pdf->SetXY($content_x, $contact_y + 6);
            $this->pdf->Cell($content_width, 5, chr(9993) . ' ' . $agent_email, 0, 1, 'L'); // Envelope icon
        }

        // License number
        if (!empty($agent_license)) {
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['caption']);
            $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
            $this->pdf->SetXY($content_x, $contact_y + 13);
            $this->pdf->Cell($content_width, 4, 'License #' . $agent_license, 0, 1, 'L');
        }

        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
    }

    /**
     * Render property stats row (beds, baths, sqft, price) - LARGER FONTS
     * v2.0.2: Fixed Y-position alignment - store initial Y before loop
     */
    private function render_property_stats_row() {
        $subject = $this->data['subject'];

        $stats = array();
        if (!empty($subject['beds'])) {
            $stats[] = array('icon' => 'bed', 'value' => $subject['beds'], 'label' => 'Beds');
        }
        if (!empty($subject['baths'])) {
            $stats[] = array('icon' => 'bath', 'value' => $subject['baths'], 'label' => 'Baths');
        }
        if (!empty($subject['sqft'])) {
            $stats[] = array('icon' => 'sqft', 'value' => number_format($subject['sqft']), 'label' => 'Sq Ft');
        }
        if (!empty($subject['price'])) {
            $stats[] = array('icon' => 'price', 'value' => '$' . number_format($subject['price']), 'label' => 'List Price');
        }

        if (empty($stats)) {
            return;
        }

        $stat_width = 186 / count($stats);
        $x_start = 15;

        // v2.0.2 Fix: Store initial Y position BEFORE loop to prevent drift
        $y_row = $this->pdf->GetY();

        foreach ($stats as $index => $stat) {
            $x = $x_start + ($index * $stat_width);

            // Value - use fixed Y position
            $this->pdf->SetXY($x, $y_row);
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['stat_value']);
            $this->pdf->SetTextColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
            $this->pdf->Cell($stat_width, 10, $stat['value'], 0, 0, 'C');

            // Label - fixed offset from initial Y
            $this->pdf->SetXY($x, $y_row + 10);
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['stat_label']);
            $this->pdf->SetTextColor(120, 120, 120);
            $this->pdf->Cell($stat_width, 5, $stat['label'], 0, 0, 'C');
        }

        // Reset Y position after the row
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $this->pdf->SetY($y_row + 18);
    }

    /**
     * Get subject property photo URL
     */
    private function get_subject_photo_url() {
        $subject = $this->data['subject'];

        // Check various possible photo fields
        if (!empty($subject['photo_url'])) {
            return $subject['photo_url'];
        }
        if (!empty($subject['main_photo'])) {
            return $subject['main_photo'];
        }
        if (!empty($subject['main_photo_url'])) {
            return $subject['main_photo_url'];
        }

        // Try to get from listing summary table first (has main_photo_url)
        if (!empty($subject['listing_id'])) {
            global $wpdb;

            // Check summary table for main_photo_url
            $photo = $wpdb->get_var($wpdb->prepare(
                "SELECT main_photo_url FROM {$wpdb->prefix}bme_listing_summary
                 WHERE listing_id = %s AND main_photo_url IS NOT NULL
                 LIMIT 1",
                $subject['listing_id']
            ));
            if ($photo) {
                return $photo;
            }

            // Also check archive summary
            $photo = $wpdb->get_var($wpdb->prepare(
                "SELECT main_photo_url FROM {$wpdb->prefix}bme_listing_summary_archive
                 WHERE listing_id = %s AND main_photo_url IS NOT NULL
                 LIMIT 1",
                $subject['listing_id']
            ));
            if ($photo) {
                return $photo;
            }

            // Fallback to media table (order_index is the ordering column)
            $photo = $wpdb->get_var($wpdb->prepare(
                "SELECT media_url FROM {$wpdb->prefix}bme_media
                 WHERE listing_id = %s AND media_category = 'Photo'
                 ORDER BY order_index ASC
                 LIMIT 1",
                $subject['listing_id']
            ));
            if ($photo) {
                return $photo;
            }
        }

        return null;
    }

    /**
     * Download image to temp file for PDF embedding
     */
    private function download_image_temp($url) {
        if (empty($url)) {
            return false;
        }

        // Get image content
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'sslverify' => false
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }

        // Determine extension from content type
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $ext = 'jpg';
        if (strpos($content_type, 'png') !== false) {
            $ext = 'png';
        } elseif (strpos($content_type, 'webp') !== false) {
            $ext = 'webp';
        }

        // Save to temp file
        $temp_file = wp_tempnam('cma_img_') . '.' . $ext;
        file_put_contents($temp_file, $body);

        // Verify it's a valid image
        $image_info = @getimagesize($temp_file);
        if (!$image_info) {
            @unlink($temp_file);
            return false;
        }

        return $temp_file;
    }

    /**
     * Add executive summary page - ENHANCED WITH LARGER FONTS
     */
    private function add_executive_summary() {
        $this->pdf->AddPage();
        $this->add_section_header('Executive Summary');

        $summary = $this->data['cma']['summary'] ?? array();
        $forecast = $this->data['cma']['forecast'] ?? array();

        // Get value estimates with fallbacks
        $estimated_value = $summary['estimated_value'] ?? array();
        $low_value = $estimated_value['low'] ?? 0;
        $high_value = $estimated_value['high'] ?? 0;

        // Estimated value box (prominent) - LARGER
        $this->pdf->SetFillColor($this->success_color[0], $this->success_color[1], $this->success_color[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['subsection']);

        $y = $this->pdf->GetY() + 5;
        $this->pdf->RoundedRect(12, $y, 192, 42, 4, '1111', 'F');

        $this->pdf->SetXY(12, $y + 8);
        $this->pdf->Cell(0, 8, 'Estimated Market Value', 0, 1, 'C');

        $this->pdf->SetFont('helvetica', 'B', 32); // Very large for mobile
        $this->pdf->SetXY(12, $y + 20);
        if ($low_value > 0 && $high_value > 0) {
            $value_range = '$' . number_format($low_value) . ' - $' . number_format($high_value);
        } else {
            $value_range = 'Insufficient Data';
        }
        $this->pdf->Cell(0, 14, $value_range, 0, 1, 'C');

        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $this->pdf->Ln(15);

        // Key metrics in a nice grid with boxes - LARGER
        $this->add_subsection_header('Key Statistics');

        // Get metric values with proper fallbacks
        $total_found = $summary['total_found'] ?? 0;
        $avg_price = $summary['avg_price'] ?? 0;
        $median_price = $summary['median_price'] ?? 0;
        $avg_dom = $summary['avg_dom'] ?? 0;
        $price_per_sqft = $summary['price_per_sqft']['avg'] ?? 0;
        $confidence = $estimated_value['confidence'] ?? 'N/A';

        $metrics = array(
            array('Total Comparables', $total_found > 0 ? $total_found : 'N/A'),
            array('Average Sold Price', $avg_price > 0 ? '$' . number_format($avg_price) : 'N/A'),
            array('Median Sold Price', $median_price > 0 ? '$' . number_format($median_price) : 'N/A'),
            array('Avg Days on Market', $avg_dom > 0 ? round($avg_dom) . ' days' : 'N/A'),
            array('Avg Price/Sq Ft', $price_per_sqft > 0 ? '$' . number_format($price_per_sqft) : 'N/A'),
            array('Confidence Level', ucfirst($confidence))
        );

        // Draw metrics in a 2x3 grid with background - LARGER BOXES
        $col_width = 94;
        $row_height = 24;
        $x_start = 12;
        $y_start = $this->pdf->GetY() + 3;

        foreach ($metrics as $index => $metric) {
            $col = $index % 2;
            $row = floor($index / 2);

            $x = $x_start + ($col * ($col_width + 4));
            $y = $y_start + ($row * ($row_height + 4));

            // Background box
            $this->pdf->SetFillColor($this->light_gray[0], $this->light_gray[1], $this->light_gray[2]);
            $this->pdf->RoundedRect($x, $y, $col_width, $row_height, 2, '1111', 'F');

            // Label
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['metric_label']);
            $this->pdf->SetTextColor(100, 100, 100);
            $this->pdf->SetXY($x + 6, $y + 4);
            $this->pdf->Cell($col_width - 12, 6, $metric[0], 0, 0, 'L');

            // Value
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['metric_value']);
            $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
            $this->pdf->SetXY($x + 6, $y + 12);
            $this->pdf->Cell($col_width - 12, 8, $metric[1], 0, 0, 'L');
        }

        $this->pdf->SetY($y_start + (3 * ($row_height + 4)) + 8);
        $this->pdf->Ln(5);

        // Weighted averaging explanation
        $this->add_weighted_value_section($summary);

        $this->pdf->Ln(10);

        // Market narrative (if forecast available and confidence > 70%)
        $forecast_confidence = $forecast['price_forecast']['confidence']['score'] ?? 0;
        if (!empty($forecast['price_forecast']['success']) && $forecast_confidence > 70) {
            $this->add_subsection_header('Market Overview');

            require_once plugin_dir_path(__FILE__) . 'class-mld-market-narrative.php';
            $narrative_gen = new MLD_Market_Narrative();
            $narrative = $narrative_gen->generate_executive_summary(
                $forecast,
                array(),
                $this->data['subject']['city'] ?? '',
                $this->data['subject']['state'] ?? ''
            );

            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
            $this->pdf->MultiCell(0, 6, $narrative, 0, 'L');
        }
    }

    /**
     * Add subject property section - ENHANCED WITH MORE DETAILS
     */
    private function add_subject_property_section() {
        $this->pdf->AddPage();
        $this->add_section_header('Subject Property Details');

        $subject = $this->data['subject'];

        // Property photo at top if available (maintain aspect ratio)
        $photo_url = $this->get_subject_photo_url();
        if ($photo_url) {
            $temp_image = $this->download_image_temp($photo_url);
            if ($temp_image) {
                $photo_max_width = 192;
                $photo_max_height = 70;
                $photo_x = 12;
                $photo_y = $this->pdf->GetY();

                // Get actual image dimensions to maintain aspect ratio
                $img_info = @getimagesize($temp_image);
                if ($img_info) {
                    $img_width = $img_info[0];
                    $img_height = $img_info[1];
                    $img_ratio = $img_width / $img_height;

                    // Calculate dimensions that fit within box while maintaining aspect ratio
                    $box_ratio = $photo_max_width / $photo_max_height;

                    if ($img_ratio > $box_ratio) {
                        // Image is wider than box - constrain by width
                        $photo_width = $photo_max_width;
                        $photo_height = $photo_max_width / $img_ratio;
                    } else {
                        // Image is taller than box - constrain by height
                        $photo_height = $photo_max_height;
                        $photo_width = $photo_max_height * $img_ratio;
                    }

                    // Center horizontally
                    $center_x = $photo_x + ($photo_max_width - $photo_width) / 2;

                    $this->pdf->Image($temp_image, $center_x, $photo_y, $photo_width, $photo_height, '', '', '', false, 300, '', false, false, 0);
                } else {
                    // Fallback - use width only
                    $this->pdf->Image($temp_image, $photo_x, $photo_y, $photo_max_width, 0, '', '', '', false, 300, '', false, false, 0);
                }
                @unlink($temp_image);
                $this->pdf->Ln(75);
            }
        }

        // Property details grid - MORE COMPREHENSIVE
        $details = array(
            array('Address', $subject['address'] ?? 'N/A'),
            array('City', $subject['city'] ?? 'N/A'),
            array('State', $subject['state'] ?? 'N/A'),
            array('Zip Code', $subject['postal_code'] ?? 'N/A'),
            array('List Price', isset($subject['price']) ? '$' . number_format($subject['price']) : 'N/A'),
            array('Bedrooms', $subject['beds'] ?? 'N/A'),
            array('Bathrooms', $subject['baths'] ?? 'N/A'),
            array('Square Feet', isset($subject['sqft']) ? number_format($subject['sqft']) : 'N/A'),
            array('Year Built', $subject['year_built'] ?? 'N/A'),
            array('Lot Size', isset($subject['lot_size']) ? number_format($subject['lot_size'], 2) . ' acres' : 'N/A'),
            array('Garage', ($subject['garage_spaces'] ?? 0) . ' spaces'),
            array('Pool', ($subject['pool'] ?? 0) ? 'Yes' : 'No'),
        );

        // Add additional details if available
        if (!empty($subject['property_type'])) {
            $details[] = array('Property Type', $subject['property_type']);
        }
        if (!empty($subject['style'])) {
            $details[] = array('Style', $subject['style']);
        }
        if (!empty($subject['stories'])) {
            $details[] = array('Stories', $subject['stories']);
        }
        if (!empty($subject['basement'])) {
            $details[] = array('Basement', $subject['basement']);
        }
        if (!empty($subject['heating'])) {
            $details[] = array('Heating', $subject['heating']);
        }
        if (!empty($subject['cooling'])) {
            $details[] = array('Cooling', $subject['cooling']);
        }

        $this->render_details_table($details);

        // Tax information if available
        if (!empty($subject['tax_annual_amount']) || !empty($subject['tax_assessed_value'])) {
            $this->pdf->Ln(8);
            $this->add_subsection_header('Tax Information');
            $tax_details = array();
            if (!empty($subject['tax_annual_amount'])) {
                $tax_details[] = array('Annual Taxes', '$' . number_format($subject['tax_annual_amount']));
            }
            if (!empty($subject['tax_assessed_value'])) {
                $tax_details[] = array('Assessed Value', '$' . number_format($subject['tax_assessed_value']));
            }
            if (!empty($subject['tax_year'])) {
                $tax_details[] = array('Tax Year', $subject['tax_year']);
            }
            $this->render_details_table($tax_details);
        }

        // HOA information if available
        if (!empty($subject['association_fee']) || !empty($subject['association_name'])) {
            $this->pdf->Ln(8);
            $this->add_subsection_header('HOA Information');
            $hoa_details = array();
            if (!empty($subject['association_fee'])) {
                $fee_freq = $subject['association_fee_frequency'] ?? 'Monthly';
                $hoa_details[] = array('HOA Fee', '$' . number_format($subject['association_fee']) . '/' . $fee_freq);
            }
            if (!empty($subject['association_name'])) {
                $hoa_details[] = array('Association', $subject['association_name']);
            }
            if (!empty($subject['association_fee_includes'])) {
                $includes = is_array($subject['association_fee_includes'])
                    ? implode(', ', $subject['association_fee_includes'])
                    : $subject['association_fee_includes'];
                $hoa_details[] = array('Fee Includes', $includes);
            }
            $this->render_details_table($hoa_details);
        }
    }

    /**
     * Add comparables section with photos - ENHANCED WITH MORE DETAILS
     */
    private function add_comparables_section() {
        $this->pdf->AddPage();
        $this->add_section_header('Comparable Properties');

        $comparables = $this->data['cma']['comparables'];

        // Show top 8 comparables (A and B grades first, then C)
        $top_comps = array_filter($comparables, function($comp) {
            return in_array($comp['comparability_grade'] ?? 'C', array('A', 'B', 'C'));
        });

        $top_comps = array_slice($top_comps, 0, 8);

        $comps_on_page = 0;
        foreach ($top_comps as $index => $comp) {
            // Check if we need a new page (2 cards per page for larger cards)
            if ($comps_on_page >= 2) {
                $this->pdf->AddPage();
                $comps_on_page = 0;
            }

            $this->render_comparable_card_with_photo($comp, $comps_on_page);
            $comps_on_page++;
        }
    }

    /**
     * Render comparable property card with photo - LARGER AND MORE DETAILED
     *
     * @param array $comp Comparable data
     * @param int $position Position on page (0 or 1)
     */
    private function render_comparable_card_with_photo($comp, $position) {
        $card_height = 115; // Increased from 75
        $y_start = 30 + ($position * ($card_height + 10));

        // Card background with subtle shadow effect
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor(220, 220, 220);
        $this->pdf->RoundedRect(12, $y_start, 192, $card_height, 4, '1111', 'DF');

        // Photo section (left side) - LARGER
        $photo_max_width = 75;
        $photo_max_height = $card_height - 12; // 103
        $photo_x = 18;
        $photo_y = $y_start + 6;

        // Draw light gray background for photo area first
        $this->pdf->SetFillColor(245, 245, 245);
        $this->pdf->RoundedRect($photo_x, $photo_y, $photo_max_width, $photo_max_height, 3, '1111', 'F');

        $photo_url = $this->get_comparable_photo_url($comp);
        $has_photo = false;

        if ($photo_url) {
            $temp_image = $this->download_image_temp($photo_url);
            if ($temp_image) {
                // Get actual image dimensions to maintain aspect ratio
                $img_info = @getimagesize($temp_image);
                if ($img_info) {
                    $img_width = $img_info[0];
                    $img_height = $img_info[1];
                    $img_ratio = $img_width / $img_height;

                    // Calculate dimensions that fit within box while maintaining aspect ratio
                    $box_ratio = $photo_max_width / $photo_max_height;

                    if ($img_ratio > $box_ratio) {
                        // Image is wider than box - constrain by width
                        $actual_width = $photo_max_width;
                        $actual_height = $photo_max_width / $img_ratio;
                    } else {
                        // Image is taller than box - constrain by height
                        $actual_height = $photo_max_height;
                        $actual_width = $photo_max_height * $img_ratio;
                    }

                    // Center the image within the photo area
                    $center_x = $photo_x + ($photo_max_width - $actual_width) / 2;
                    $center_y = $photo_y + ($photo_max_height - $actual_height) / 2;

                    $this->pdf->Image($temp_image, $center_x, $center_y, $actual_width, $actual_height, '', '', '', false, 300, '', false, false, 0);
                } else {
                    // Fallback if we can't get image size - use width only, let height auto-calculate
                    $this->pdf->Image($temp_image, $photo_x, $photo_y, $photo_max_width, 0, '', '', '', false, 300, '', false, false, 0);
                }
                @unlink($temp_image);
                $has_photo = true;
            }
        }

        if (!$has_photo) {
            // Show placeholder text on the gray background
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
            $this->pdf->SetTextColor(150, 150, 150);
            $this->pdf->SetXY($photo_x, $photo_y + ($photo_max_height / 2) - 4);
            $this->pdf->Cell($photo_max_width, 8, 'No Photo', 0, 0, 'C');
            $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        }

        // Content section (right side) - use fixed width based on photo_max_width
        $content_x = $photo_x + $photo_max_width + 8;
        $content_width = 192 - $photo_max_width - 22;

        // Grade badge (top right) - LARGER
        $grade = $comp['comparability_grade'] ?? 'C';
        $grade_color = $this->get_grade_color($grade);
        $this->pdf->SetFillColor($grade_color[0], $grade_color[1], $grade_color[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->RoundedRect(182, $y_start + 6, 18, 18, 3, '1111', 'F');
        $this->pdf->SetXY(182, $y_start + 10);
        $this->pdf->Cell(18, 10, $grade, 0, 0, 'C');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Address - LARGER
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body'] + 1);
        $this->pdf->SetXY($content_x, $y_start + 6);
        $address = $comp['unparsed_address'] ?? ($comp['address'] ?? 'Unknown Address');
        $this->pdf->Cell($content_width - 25, 7, substr($address, 0, 32), 0, 1, 'L');

        // City, State
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->SetXY($content_x, $y_start + 14);
        $this->pdf->Cell($content_width, 5, ($comp['city'] ?? '') . ', ' . ($comp['state'] ?? 'MA'), 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // v2.0.2 Fix: Stack Sold and Adjusted prices vertically to avoid grade badge overlap
        // Sold price row
        $list_price = $comp['list_price'] ?? ($comp['sold_price'] ?? 0);
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
        $this->pdf->SetXY($content_x, $y_start + 22);
        $this->pdf->Cell($content_width - 25, 6, 'Sold: $' . number_format($list_price), 0, 1, 'L');

        // Adjusted price row (below sold price)
        $adjusted_price = $comp['adjusted_price'] ?? $list_price;
        $this->pdf->SetTextColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->SetXY($content_x, $y_start + 29);
        $this->pdf->Cell($content_width - 25, 6, 'Adjusted: $' . number_format($adjusted_price), 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // v2.0.3 Fix: Adjusted cell widths to fit within content_width (~95mm)
        // Details row 1 (Y shifted +7mm for stacked prices)
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
        $this->pdf->SetXY($content_x, $y_start + 39);
        $beds = $comp['bedrooms_total'] ?? ($comp['beds'] ?? 'N/A');
        $baths = $comp['bathrooms_total'] ?? ($comp['baths'] ?? 'N/A');
        $sqft = $comp['building_area_total'] ?? ($comp['sqft'] ?? 0);
        $distance = $comp['distance_miles'] ?? 0;
        // Widths: 32 + 30 + 28 = 90mm (fits in 95mm content_width)
        $this->pdf->Cell(32, 6, $beds . 'BR / ' . $baths . 'BA', 0, 0, 'L');
        $this->pdf->Cell(30, 6, number_format($sqft) . ' sqft', 0, 0, 'L');
        $this->pdf->Cell(28, 6, number_format($distance, 1) . ' mi', 0, 1, 'L');

        // Details row 2 (Y shifted +7mm)
        $this->pdf->SetXY($content_x, $y_start + 47);
        $year = $comp['year_built'] ?? 'N/A';
        $dom = $comp['days_on_market'] ?? ($comp['dom'] ?? 'N/A');
        $status = $comp['standard_status'] ?? 'Closed';
        // Widths: 28 + 28 + 34 = 90mm
        $this->pdf->Cell(28, 6, 'Built: ' . $year, 0, 0, 'L');
        $this->pdf->Cell(28, 6, 'DOM: ' . $dom, 0, 0, 'L');
        $this->pdf->Cell(34, 6, $status, 0, 1, 'L');

        // Price per sqft & Sold date (Y shifted +7mm)
        $ppsf = 0;
        if ($sqft > 0 && $list_price > 0) {
            $ppsf = round($list_price / $sqft);
        }
        $this->pdf->SetXY($content_x, $y_start + 55);
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body_small']);
        // Widths: 35 + 55 = 90mm
        $this->pdf->Cell(35, 6, '$' . number_format($ppsf) . '/sqft', 0, 0, 'L');

        $sold_date = $comp['sold_date'] ?? ($comp['close_date'] ?? '');
        if (!empty($sold_date)) {
            $formatted_date = date('M j, Y', strtotime($sold_date));
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
            $this->pdf->Cell(55, 6, 'Sold: ' . $formatted_date, 0, 0, 'L');
        }

        // Additional property details (if available) (Y shifted +7mm)
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['caption']);
        $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
        $extra_details = array();

        if (!empty($comp['lot_size_area'])) {
            $extra_details[] = 'Lot: ' . number_format($comp['lot_size_area'], 2) . 'ac';
        }
        if (!empty($comp['garage_spaces']) && $comp['garage_spaces'] > 0) {
            $extra_details[] = 'Gar: ' . $comp['garage_spaces'];
        }
        if (!empty($comp['property_type'])) {
            $extra_details[] = $comp['property_type'];
        }

        if (!empty($extra_details)) {
            $this->pdf->SetXY($content_x, $y_start + 65);
            // Limit to 90mm width
            $details_text = implode(' | ', array_slice($extra_details, 0, 3));
            $this->pdf->Cell(90, 5, substr($details_text, 0, 45), 0, 1, 'L');
        }

        // Adjustments summary (bottom) (Y shifted +7mm)
        $adj_total = $comp['adjustments']['total_adjustment'] ?? 0;
        $this->pdf->SetXY($content_x, $y_start + 75);
        if ($adj_total != 0) {
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
            $sign = $adj_total >= 0 ? '+' : '';
            $adj_color = $adj_total >= 0 ? $this->success_color : array(220, 53, 69);
            $this->pdf->SetTextColor($adj_color[0], $adj_color[1], $adj_color[2]);
            $this->pdf->Cell(90, 5, 'Net Adj: ' . $sign . '$' . number_format(abs($adj_total)), 0, 1, 'L');
            $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        }

        // Top adjustment factors (Y shifted +7mm) - abbreviated to fit
        $adj_items = $comp['adjustments']['items'] ?? array();
        if (!empty($adj_items)) {
            // Only show top 2 adjustments to fit width
            $adj_items = array_slice($adj_items, 0, 2);
            $this->pdf->SetFont('helvetica', 'I', $this->font_sizes['caption']);
            $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
            $this->pdf->SetXY($content_x, $y_start + 83);
            $adj_texts = array();
            foreach ($adj_items as $adj) {
                $sign = $adj['adjustment'] >= 0 ? '+' : '';
                // Abbreviate feature names
                $feature = substr($adj['feature'], 0, 8);
                $adj_texts[] = $feature . ': ' . $sign . '$' . number_format($adj['adjustment']);
            }
            $adj_line = implode(' | ', $adj_texts);
            $this->pdf->Cell(90, 5, substr($adj_line, 0, 50), 0, 1, 'L');
            $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        }

        // MLS Number (Y shifted +7mm)
        $listing_id = $comp['listing_id'] ?? '';
        if (!empty($listing_id)) {
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['caption']);
            $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
            $this->pdf->SetXY($content_x, $y_start + 91);
            $this->pdf->Cell(0, 5, 'MLS# ' . $listing_id, 0, 1, 'L');
            $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        }
    }

    /**
     * Get comparable property photo URL
     */
    private function get_comparable_photo_url($comp) {
        // Check various possible photo fields
        if (!empty($comp['photo_url'])) {
            return $comp['photo_url'];
        }
        if (!empty($comp['main_photo'])) {
            return $comp['main_photo'];
        }
        if (!empty($comp['main_photo_url'])) {
            return $comp['main_photo_url'];
        }

        // Try to get from listing
        $listing_id = $comp['listing_id'] ?? null;
        if ($listing_id) {
            global $wpdb;

            // Try summary archive table first (comparables are typically closed sales)
            $photo = $wpdb->get_var($wpdb->prepare(
                "SELECT main_photo_url FROM {$wpdb->prefix}bme_listing_summary_archive
                 WHERE listing_id = %s AND main_photo_url IS NOT NULL
                 LIMIT 1",
                $listing_id
            ));

            if ($photo) {
                return $photo;
            }

            // Try active summary table
            $photo = $wpdb->get_var($wpdb->prepare(
                "SELECT main_photo_url FROM {$wpdb->prefix}bme_listing_summary
                 WHERE listing_id = %s AND main_photo_url IS NOT NULL
                 LIMIT 1",
                $listing_id
            ));

            if ($photo) {
                return $photo;
            }

            // Fallback to media table (order_index is the ordering column)
            $photo = $wpdb->get_var($wpdb->prepare(
                "SELECT media_url FROM {$wpdb->prefix}bme_media
                 WHERE listing_id = %s AND media_category = 'Photo'
                 ORDER BY order_index ASC
                 LIMIT 1",
                $listing_id
            ));

            if ($photo) {
                return $photo;
            }
        }

        return null;
    }

    /**
     * Add city market trends section - NEW SECTION
     */
    private function add_city_market_trends_section() {
        $city = $this->data['subject']['city'] ?? '';
        $state = $this->data['subject']['state'] ?? '';

        if (empty($city)) {
            return;
        }

        // Load market trends
        $trends_file = plugin_dir_path(__FILE__) . 'class-mld-market-trends.php';
        if (!file_exists($trends_file)) {
            return;
        }

        require_once $trends_file;

        if (!class_exists('MLD_Market_Trends')) {
            return;
        }

        $market_trends = new MLD_Market_Trends();
        $trends = $market_trends->get_city_trends($city, $state, 12);

        if (empty($trends) || !$trends['success']) {
            return;
        }

        $this->pdf->AddPage();
        $this->add_section_header('City Market Trends: ' . $city);

        // Summary stats at top
        $summary = $trends['summary'] ?? array();

        if (!empty($summary)) {
            $stats = array(
                array('Median Price', isset($summary['median_price']) ? '$' . number_format($summary['median_price']) : 'N/A'),
                array('Avg Days on Market', isset($summary['avg_dom']) ? round($summary['avg_dom']) . ' days' : 'N/A'),
                array('Total Sales (12 mo)', isset($summary['total_sales']) ? number_format($summary['total_sales']) : 'N/A'),
                array('Price Change YoY', isset($summary['price_change_pct']) ? $this->format_percentage($summary['price_change_pct']) : 'N/A'),
            );

            $this->add_subsection_header($city . ' Market Summary');

            // Draw stats in a row
            $col_width = 48;
            $x_start = 12;
            $y = $this->pdf->GetY() + 3;

            foreach ($stats as $index => $stat) {
                $x = $x_start + ($index * $col_width);

                // Background box
                $this->pdf->SetFillColor($this->light_gray[0], $this->light_gray[1], $this->light_gray[2]);
                $this->pdf->RoundedRect($x, $y, $col_width - 4, 28, 2, '1111', 'F');

                // Label
                $this->pdf->SetFont('helvetica', '', $this->font_sizes['caption']);
                $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
                $this->pdf->SetXY($x + 4, $y + 4);
                $this->pdf->Cell($col_width - 8, 5, $stat[0], 0, 0, 'C');

                // Value
                $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
                $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
                $this->pdf->SetXY($x + 4, $y + 12);
                $this->pdf->Cell($col_width - 8, 8, $stat[1], 0, 0, 'C');
            }

            $this->pdf->SetY($y + 35);
        }

        // Monthly trends if available
        $monthly = $trends['monthly'] ?? array();
        if (!empty($monthly)) {
            $this->add_subsection_header('Recent Monthly Activity');

            // Table header
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body_small']);
            $this->pdf->SetFillColor(230, 235, 240);
            $col_widths = array(40, 38, 38, 38, 38);
            $headers = array('Month', 'Median Price', 'Avg $/SqFt', 'Sales', 'Avg DOM');

            $x_start = 12;
            foreach ($headers as $idx => $header) {
                $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
                $this->pdf->Cell($col_widths[$idx], 10, $header, 1, 0, 'C', true);
            }
            $this->pdf->Ln();

            // Table rows (last 6 months)
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
            $recent_months = array_slice($monthly, -6);

            foreach ($recent_months as $month_data) {
                $month_label = date('M Y', strtotime($month_data['month'] . '-01'));
                $row_data = array(
                    $month_label,
                    '$' . number_format($month_data['median_price'] ?? 0),
                    '$' . number_format($month_data['price_per_sqft'] ?? 0),
                    number_format($month_data['sales_count'] ?? 0),
                    round($month_data['avg_dom'] ?? 0) . ' days',
                );

                foreach ($row_data as $idx => $data) {
                    $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
                    $this->pdf->Cell($col_widths[$idx], 9, $data, 1, 0, 'C');
                }
                $this->pdf->Ln();
            }
        }

        $this->pdf->Ln(10);

        // Price trend description
        if (!empty($trends['trend_description'])) {
            $this->add_subsection_header('Market Analysis');
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
            $this->pdf->MultiCell(0, 6, $trends['trend_description'], 0, 'L');
        }
    }

    /**
     * Add weighted value section to executive summary - LARGER
     */
    private function add_weighted_value_section($summary) {
        $estimated = $summary['estimated_value'] ?? array();

        // Check if we have weighted value data
        $mid_weighted = $estimated['mid_weighted'] ?? null;
        $mid_unweighted = $estimated['mid_unweighted'] ?? null;

        if ($mid_weighted === null || $mid_unweighted === null) {
            return;
        }

        $difference = $mid_weighted - $mid_unweighted;
        $weight_breakdown = $estimated['weight_breakdown'] ?? array();

        // Section header
        $this->add_subsection_header('Weighted Value Analysis');

        // Draw comparison box - LARGER
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
        $y = $this->pdf->GetY() + 2;

        // Light gray background
        $this->pdf->SetFillColor($this->light_gray[0], $this->light_gray[1], $this->light_gray[2]);
        $this->pdf->RoundedRect(12, $y, 192, 42, 3, '1111', 'F');

        // Weighted value (primary - emphasized)
        $this->pdf->SetXY(18, $y + 6);
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
        $this->pdf->Cell(85, 7, 'Weighted Estimate:', 0, 0, 'L');
        $this->pdf->SetTextColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['subsection']);
        $this->pdf->Cell(85, 7, '$' . number_format($mid_weighted), 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Simple average
        $this->pdf->SetXY(18, $y + 16);
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body_small']);
        $this->pdf->Cell(85, 6, 'Simple Average:', 0, 0, 'L');
        $this->pdf->Cell(85, 6, '$' . number_format($mid_unweighted), 0, 1, 'L');

        // Difference
        $this->pdf->SetXY(18, $y + 24);
        $this->pdf->Cell(85, 6, 'Difference:', 0, 0, 'L');
        $diff_color = $difference >= 0 ? $this->success_color : array(220, 53, 69);
        $this->pdf->SetTextColor($diff_color[0], $diff_color[1], $diff_color[2]);
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body_small']);
        $sign = $difference >= 0 ? '+' : '';
        $this->pdf->Cell(85, 6, $sign . '$' . number_format(abs($difference)), 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Methodology explanation
        $this->pdf->SetXY(18, $y + 33);
        $this->pdf->SetFont('helvetica', 'I', $this->font_sizes['caption']);
        $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
        $this->pdf->Cell(0, 5, 'Weights: A-grade = 2.0x, B-grade = 1.5x, C-grade = 1.0x, D-grade = 0.5x, F-grade = 0.25x', 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        $this->pdf->SetY($y + 46);

        // Weight breakdown table (if available and has items)
        if (!empty($weight_breakdown) && count($weight_breakdown) <= 10) {
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body_small']);
            $this->pdf->Cell(0, 6, 'Comparable Weighting Breakdown:', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['caption']);

            // Table header
            $this->pdf->SetFillColor(240, 244, 248);
            $this->pdf->Cell(75, 6, 'Address', 1, 0, 'L', true);
            $this->pdf->Cell(22, 6, 'Grade', 1, 0, 'C', true);
            $this->pdf->Cell(22, 6, 'Weight', 1, 0, 'C', true);
            $this->pdf->Cell(38, 6, 'Adj. Price', 1, 0, 'R', true);
            $this->pdf->Cell(35, 6, 'Contribution', 1, 1, 'R', true);

            // Table rows
            foreach ($weight_breakdown as $comp) {
                $address = substr($comp['address'] ?? 'N/A', 0, 38);
                $grade = $comp['grade'] ?? 'C';
                $weight = $comp['weight'] ?? 1.0;
                $adj_price = $comp['adjusted_price'] ?? 0;
                $contribution = $comp['weighted_contribution'] ?? 0;
                $is_override = !empty($comp['is_override']);

                $weight_display = number_format($weight, 2) . 'x';
                if ($is_override) {
                    $weight_display .= '*';
                }

                $this->pdf->Cell(75, 5, $address, 1, 0, 'L');
                $this->pdf->Cell(22, 5, $grade, 1, 0, 'C');
                $this->pdf->Cell(22, 5, $weight_display, 1, 0, 'C');
                $this->pdf->Cell(38, 5, '$' . number_format($adj_price), 1, 0, 'R');
                $this->pdf->Cell(35, 5, '$' . number_format($contribution), 1, 1, 'R');
            }

            // Legend for manual overrides
            $has_overrides = array_filter($weight_breakdown, function($c) {
                return !empty($c['is_override']);
            });

            if (!empty($has_overrides)) {
                $this->pdf->SetFont('helvetica', 'I', $this->font_sizes['caption'] - 1);
                $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
                $this->pdf->Cell(0, 5, '* Weight manually adjusted by analyst', 0, 1, 'L');
                $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
            }
        }
    }

    /**
     * Add market conditions section - ENHANCED
     */
    private function add_market_conditions_section() {
        // Get market conditions data
        $subject = $this->data['subject'];
        $city = $subject['city'] ?? '';
        $state = $subject['state'] ?? '';

        if (empty($city)) {
            return;
        }

        // Load market conditions
        require_once plugin_dir_path(__FILE__) . 'class-mld-market-conditions.php';
        $market_conditions = new MLD_Market_Conditions();
        $conditions = $market_conditions->get_market_conditions($city, $state, 'all', 12);

        if (!$conditions['success']) {
            return;
        }

        $this->pdf->AddPage();
        $this->add_section_header('Market Conditions');

        $market_health = $conditions['market_health'] ?? array();
        $inventory = $conditions['inventory'] ?? array();
        $dom_trends = $conditions['days_on_market'] ?? array();
        $list_sale_ratio = $conditions['list_to_sale_ratio'] ?? array();
        $price_trends = $conditions['price_trends'] ?? array();

        // v2.0.2 Fix: Market Status Box with adaptive font size and text wrapping
        $status_color = $this->hex_to_rgb($market_health['status_color'] ?? '#6b7280');
        $this->pdf->SetFillColor($status_color[0], $status_color[1], $status_color[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['subsection']);

        $y = $this->pdf->GetY() + 5;
        $summary_text = $market_health['summary'] ?? '';
        $summary_length = strlen($summary_text);

        // Calculate box height and font size based on text length
        if ($summary_length > 120) {
            $box_height = 38;  // Taller box for long text
            $summary_font_size = $this->font_sizes['body_small'];  // 12pt
        } elseif ($summary_length > 80) {
            $box_height = 35;
            $summary_font_size = $this->font_sizes['body'];  // 14pt
        } else {
            $box_height = 32;
            $summary_font_size = $this->font_sizes['body'];  // 14pt
        }

        $this->pdf->RoundedRect(12, $y, 192, $box_height, 4, '1111', 'F');

        // Title
        $this->pdf->SetXY(12, $y + 7);
        $this->pdf->Cell(192, 10, 'Market Status: ' . ($market_health['status'] ?? 'Unknown'), 0, 1, 'C');

        // Summary with adaptive font - use MultiCell for wrapping
        $this->pdf->SetFont('helvetica', '', $summary_font_size);
        $this->pdf->SetXY(18, $y + 18);
        $this->pdf->MultiCell(180, 5, $summary_text, 0, 'C');

        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        // Update the Ln() after to account for variable box height
        $this->pdf->SetY($y + $box_height + 6);

        // Key Metrics Table - LARGER
        $this->add_subsection_header('Key Market Metrics');

        $metrics = array(
            array('Inventory (Months of Supply)', ($inventory['months_of_supply'] ?? 'N/A') . ' months'),
            array('Active Listings', number_format($inventory['active_listings'] ?? 0)),
            array('Pending Listings', number_format($inventory['pending_listings'] ?? 0)),
            array('Avg Monthly Sales', number_format($inventory['avg_monthly_sales'] ?? 0, 1)),
            array('Average Days on Market', ($dom_trends['average'] ?? 'N/A') . ' days'),
            array('DOM Trend', ucfirst($dom_trends['trend']['direction'] ?? 'stable')),
            array('List-to-Sale Ratio', ($list_sale_ratio['average_percentage'] ?? 'N/A') . '%'),
            array('Annual Appreciation', ($price_trends['annualized_appreciation'] ?? 'N/A') . '%'),
        );

        $this->render_details_table($metrics);
        $this->pdf->Ln(12);

        // Market Factors
        if (!empty($market_health['factors'])) {
            $this->add_subsection_header('Market Factors');
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);

            foreach ($market_health['factors'] as $factor) {
                $this->pdf->SetX(18);
                $this->pdf->Cell(6, 7, chr(149), 0, 0, 'L'); // Bullet point
                $this->pdf->Cell(0, 7, $factor, 0, 1, 'L');
            }
            $this->pdf->Ln(8);
        }

        // Trend Descriptions
        $this->add_subsection_header('Trend Analysis');
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);

        if (!empty($dom_trends['trend_description'])) {
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
            $this->pdf->Cell(0, 7, 'Days on Market:', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
            $this->pdf->MultiCell(0, 6, $dom_trends['trend_description'], 0, 'L');
            $this->pdf->Ln(5);
        }

        if (!empty($list_sale_ratio['trend_description'])) {
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
            $this->pdf->Cell(0, 7, 'List-to-Sale Ratio:', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
            $this->pdf->MultiCell(0, 6, $list_sale_ratio['trend_description'], 0, 'L');
            $this->pdf->Ln(5);
        }

        if (!empty($price_trends['trend_description'])) {
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
            $this->pdf->Cell(0, 7, 'Price Trends:', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
            $this->pdf->MultiCell(0, 6, $price_trends['trend_description'], 0, 'L');
        }
    }

    /**
     * Convert hex color to RGB array
     */
    private function hex_to_rgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) == 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        );
    }

    /**
     * Add market analysis section - ENHANCED
     * v2.0.6: Don't add page if no forecast data available
     * v2.0.7: Hide section if confidence score is below 80%
     */
    private function add_market_analysis_section() {
        $forecast = $this->data['cma']['forecast'] ?? array();

        // v2.0.6: Check for data BEFORE adding page to avoid blank pages
        if (empty($forecast['price_forecast']['success'])) {
            return; // Skip this section entirely if no forecast data
        }

        // v2.0.7: Hide section if confidence is below 80%
        $confidence_score = $forecast['price_forecast']['confidence']['score'] ?? 0;
        if ($confidence_score < 80) {
            return; // Skip section - insufficient data confidence
        }

        $this->pdf->AddPage();
        $this->add_section_header('Market Analysis');

        $price_forecast = $forecast['price_forecast'];

        // Appreciation metrics - LARGER
        $this->add_subsection_header('Price Appreciation');

        $appreciation = $price_forecast['appreciation'];
        $metrics = array(
            array('3-Month Appreciation', $this->format_percentage($appreciation['3_month'])),
            array('6-Month Appreciation', $this->format_percentage($appreciation['6_month'])),
            array('12-Month Appreciation', $this->format_percentage($appreciation['12_month'])),
            array('Average Monthly', $this->format_percentage($appreciation['average_monthly'])),
        );

        $this->render_details_table($metrics);
        $this->pdf->Ln(8);

        // Momentum analysis
        $this->add_subsection_header('Market Momentum');

        $momentum = $price_forecast['momentum'];
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body'] + 1);
        $this->pdf->Cell(0, 8, 'Status: ' . ucfirst($momentum['status']), 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $this->pdf->MultiCell(0, 6, $momentum['description'], 0, 'L');
        $this->pdf->Ln(5);

        $momentum_details = array(
            array('Direction', ucfirst($momentum['direction'])),
            array('Strength', $momentum['strength'] . '%'),
            array('Recent Trend', $this->format_percentage($momentum['recent_change_pct'])),
            array('Long-term Trend', $this->format_percentage($momentum['longer_term_change_pct'])),
        );

        $this->render_details_table($momentum_details);
    }

    /**
     * Add forecast section - ENHANCED
     * v2.0.7: Hide section if confidence score is 70% or lower
     */
    private function add_forecast_section() {
        if (empty($this->data['options']['include_forecast'])) {
            return;
        }

        $forecast = $this->data['cma']['forecast'] ?? array();

        if (empty($forecast['price_forecast']['success'])) {
            return;
        }

        // v2.0.7: Hide section if confidence is 70% or lower
        $confidence_score = $forecast['price_forecast']['confidence']['score'] ?? 0;
        if ($confidence_score <= 70) {
            return; // Skip section - insufficient data confidence
        }

        $this->pdf->AddPage();
        $this->add_section_header('Price Forecast');

        $price_forecast = $forecast['price_forecast'];
        $forecast_data = $price_forecast['forecast'];

        // Forecast table - LARGER
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
        $this->pdf->SetFillColor(230, 235, 240);

        $col_widths = array(42, 42, 52, 52);
        $headers = array('Time Period', 'Predicted Price', 'Low Estimate', 'High Estimate');

        $x_start = 12;
        foreach ($headers as $idx => $header) {
            $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
            $this->pdf->Cell($col_widths[$idx], 10, $header, 1, 0, 'C', true);
        }
        $this->pdf->Ln();

        // Forecast rows
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        foreach ($forecast_data as $f) {
            $row_data = array(
                $f['months_ahead'] . ' months',
                '$' . number_format($f['predicted_price']),
                '$' . number_format($f['low_estimate']),
                '$' . number_format($f['high_estimate']),
            );

            foreach ($row_data as $idx => $data) {
                $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
                $this->pdf->Cell($col_widths[$idx], 9, $data, 1, 0, 'C');
            }
            $this->pdf->Ln();
        }

        $this->pdf->Ln(8);

        // Confidence level
        $confidence = $price_forecast['confidence'];
        $this->add_subsection_header('Forecast Confidence');

        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body'] + 1);
        $this->pdf->Cell(0, 8, 'Confidence Level: ' . ucfirst($confidence['level']) . ' (' . $confidence['score'] . '/100)', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $this->pdf->MultiCell(0, 6, $confidence['description'], 0, 'L');
    }

    /**
     * Add investment analysis section - ENHANCED
     * v2.0.7: Hide section if confidence score is 70% or lower
     */
    private function add_investment_analysis_section() {
        if (empty($this->data['options']['include_investment'])) {
            return;
        }

        $investment = $this->data['cma']['forecast']['investment_analysis'] ?? array();

        if (empty($investment['success'])) {
            return;
        }

        // v2.0.7: Hide section if forecast confidence is 70% or lower
        $forecast = $this->data['cma']['forecast'] ?? array();
        $confidence_score = $forecast['price_forecast']['confidence']['score'] ?? 0;
        if ($confidence_score <= 70) {
            return; // Skip section - insufficient data confidence
        }

        $this->pdf->AddPage();
        $this->add_section_header('Investment Analysis');

        // Annual appreciation rate - LARGER
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['subsection']);
        $this->pdf->Cell(0, 10, 'Projected Annual Appreciation: ' . $this->format_percentage($investment['annual_appreciation_rate']), 0, 1, 'L');
        $this->pdf->Ln(5);

        // Projected values table - LARGER
        $this->add_subsection_header('Projected Property Values');

        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
        $this->pdf->SetFillColor(230, 235, 240);

        $col_widths = array(42, 52, 52, 42);
        $headers = array('Time Period', 'Projected Value', 'Appreciation', 'Total %');

        $x_start = 12;
        foreach ($headers as $idx => $header) {
            $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
            $this->pdf->Cell($col_widths[$idx], 10, $header, 1, 0, 'C', true);
        }
        $this->pdf->Ln();

        // Projection rows
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $projected = $investment['projected_values'];

        foreach (array('1_year', '3_year', '5_year', '10_year') as $period) {
            if (!isset($projected[$period])) continue;

            $data = $projected[$period];
            $row_data = array(
                str_replace('_', ' ', ucfirst($period)),
                '$' . number_format($data['value']),
                '$' . number_format($data['appreciation']),
                $this->format_percentage($data['appreciation_pct']),
            );

            foreach ($row_data as $idx => $value) {
                $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
                $this->pdf->Cell($col_widths[$idx], 9, $value, 1, 0, 'C');
            }
            $this->pdf->Ln();
        }

        $this->pdf->Ln(8);

        // Risk assessment
        $this->add_subsection_header('Risk Assessment');

        $risk = $investment['risk_assessment'];
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body'] + 1);
        $this->pdf->Cell(0, 8, 'Risk Level: ' . ucfirst($risk['level']), 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $this->pdf->MultiCell(0, 6, $risk['description'], 0, 'L');
    }

    /**
     * Add disclaimer page - ENHANCED
     */
    private function add_disclaimer() {
        $this->pdf->AddPage();
        $this->add_section_header('Important Disclaimer');

        $disclaimer = "This Comparative Market Analysis (CMA) is provided for informational purposes only and is not an appraisal. The values and projections contained in this report are estimates based on available market data and statistical analysis. Actual property values may differ.\n\n";

        $disclaimer .= "Market forecasts and investment projections are based on historical trends and statistical models. Past performance does not guarantee future results. Real estate markets can be affected by numerous factors including economic conditions, interest rates, local development, and other variables that cannot be predicted with certainty.\n\n";

        $disclaimer .= "This report should not be relied upon as the sole basis for making real estate decisions. We recommend consulting with licensed professionals including real estate agents, appraisers, attorneys, and financial advisors before making any real estate transaction.\n\n";

        $brokerage = $this->agent_profile['office_name'] ?? $this->data['options']['brokerage_name'] ?? 'the preparer';
        $disclaimer .= "The information contained in this report has been obtained from sources believed to be reliable. However, " . $brokerage . " makes no warranty, express or implied, as to the accuracy or completeness of this information.\n\n";

        $prepared_for = $this->data['options']['prepared_for'] ?: 'the named recipient';
        $disclaimer .= "© " . date('Y') . " " . $brokerage . ". This report is confidential and prepared exclusively for " . $prepared_for . ".";

        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $this->pdf->MultiCell(0, 6, $disclaimer, 0, 'J');

        // Agent contact footer
        $this->pdf->Ln(15);
        $this->pdf->SetFillColor($this->light_gray[0], $this->light_gray[1], $this->light_gray[2]);
        $this->pdf->RoundedRect(12, $this->pdf->GetY(), 192, 35, 4, '1111', 'F');

        $agent_name = $this->agent_profile['name'] ?? $this->data['options']['agent_name'] ?? '';
        $agent_phone = $this->agent_profile['phone'] ?? $this->data['options']['agent_phone'] ?? '';
        $agent_email = $this->agent_profile['email'] ?? $this->data['options']['agent_email'] ?? '';

        if (!empty($agent_name)) {
            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body'] + 1);
            $this->pdf->SetXY(18, $this->pdf->GetY() + 8);
            $this->pdf->Cell(186, 7, 'Questions? Contact ' . $agent_name, 0, 1, 'C');

            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
            $contact_line = '';
            if (!empty($agent_phone)) {
                $contact_line .= $agent_phone;
            }
            if (!empty($agent_email)) {
                if (!empty($contact_line)) $contact_line .= '  |  ';
                $contact_line .= $agent_email;
            }
            if (!empty($contact_line)) {
                $this->pdf->SetX(18);
                $this->pdf->Cell(186, 7, $contact_line, 0, 1, 'C');
            }
        }
    }

    /**
     * Save PDF and return file path
     *
     * @return string|false File path or false on failure
     */
    private function save_pdf() {
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/cma-reports';

        // Create directory if it doesn't exist
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        $filename = 'cma-report-' . time() . '-' . wp_generate_password(8, false) . '.pdf';
        $filepath = $pdf_dir . '/' . $filename;

        try {
            $this->pdf->Output($filepath, 'F');
            return $filepath;
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PDF generation failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Helper: Add section header - LARGER
     */
    private function add_section_header($title) {
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['section_header']);
        $this->pdf->SetTextColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->Cell(0, 12, $title, 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Underline
        $this->pdf->SetDrawColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->SetLineWidth(0.6);
        $y = $this->pdf->GetY();
        $this->pdf->Line(12, $y, 204, $y);
        $this->pdf->Ln(6);
    }

    /**
     * Helper: Add subsection header - LARGER
     */
    private function add_subsection_header($title) {
        $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['subsection']);
        $this->pdf->Cell(0, 10, $title, 0, 1, 'L');
    }

    /**
     * Helper: Render details table - LARGER
     */
    private function render_details_table($details) {
        $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
        $col_width = 96;

        foreach ($details as $index => $detail) {
            $col = $index % 2;
            $row = floor($index / 2);

            if ($col == 0) {
                $this->pdf->SetX(12);
            }

            $this->pdf->SetFont('helvetica', 'B', $this->font_sizes['body']);
            $this->pdf->Cell($col_width / 2, 8, $detail[0] . ':', 0, 0, 'L');
            $this->pdf->SetFont('helvetica', '', $this->font_sizes['body']);
            $this->pdf->Cell($col_width / 2, 8, $detail[1], 0, $col == 1 ? 1 : 0, 'R');
        }

        // Handle odd number of items
        if (count($details) % 2 == 1) {
            $this->pdf->Ln();
        }
    }

    /**
     * Helper: Get grade color
     */
    private function get_grade_color($grade) {
        $colors = array(
            'A' => array(40, 167, 69),   // Green
            'B' => array(92, 184, 92),   // Light green
            'C' => array(255, 193, 7),   // Yellow
            'D' => array(253, 126, 20),  // Orange
            'F' => array(220, 53, 69),   // Red
        );
        return $colors[$grade] ?? array(128, 128, 128);
    }

    /**
     * Helper: Format percentage
     */
    private function format_percentage($value) {
        $sign = $value >= 0 ? '+' : '';
        return $sign . number_format($value, 1) . '%';
    }

    /**
     * Add market charts section with visual graphs
     * v2.0.5: New section with price trends, sales volume, and DOM charts
     */
    private function add_market_charts_section() {
        $city = $this->data['subject']['city'] ?? '';
        $state = $this->data['subject']['state'] ?? '';

        if (empty($city)) {
            return;
        }

        // Load market trends data
        $trends_file = plugin_dir_path(__FILE__) . 'class-mld-market-trends.php';
        if (!file_exists($trends_file)) {
            return;
        }

        require_once $trends_file;

        if (!class_exists('MLD_Market_Trends')) {
            return;
        }

        $market_trends = new MLD_Market_Trends();
        $monthly_data = $market_trends->calculate_monthly_trends($city, $state, 'all', 12);

        if (empty($monthly_data) || count($monthly_data) < 3) {
            return; // Need at least 3 months of data for meaningful charts
        }

        $this->pdf->AddPage();
        $this->add_section_header('Market Trends Charts: ' . $city);

        // Chart 1: Price Trend Line Chart
        $this->add_subsection_header('Average Sale Price Trend');
        $price_data = array_map(function($m) {
            return $m['avg_close_price'];
        }, $monthly_data);
        $labels = array_map(function($m) {
            return date('M', strtotime($m['month'] . '-01'));
        }, $monthly_data);

        $y_start = $this->pdf->GetY();
        $this->render_line_chart(
            $price_data,
            $labels,
            12,           // x position
            $y_start,     // y position
            180,          // width
            55,           // height
            $this->primary_color,
            '$%s',        // value format (currency)
            true          // show grid
        );

        $this->pdf->SetY($y_start + 62);

        // Chart 2: Sales Volume Bar Chart
        $this->add_subsection_header('Monthly Sales Volume');
        $volume_data = array_map(function($m) {
            return $m['sales_count'];
        }, $monthly_data);

        $y_start = $this->pdf->GetY();
        $this->render_bar_chart(
            $volume_data,
            $labels,
            12,           // x position
            $y_start,     // y position
            180,          // width
            50,           // height
            $this->success_color,
            '%d sales'    // value format
        );

        $this->pdf->SetY($y_start + 57);

        // Chart 3: Days on Market Trend
        $this->add_subsection_header('Average Days on Market');
        $dom_data = array_map(function($m) {
            return $m['avg_dom'];
        }, $monthly_data);

        $y_start = $this->pdf->GetY();
        $this->render_line_chart(
            $dom_data,
            $labels,
            12,           // x position
            $y_start,     // y position
            180,          // width
            50,           // height
            array(220, 53, 69), // Red color for DOM
            '%d days',    // value format
            true          // show grid
        );

        $this->pdf->SetY($y_start + 55);

        // Add chart legend/notes
        $this->pdf->SetFont('helvetica', 'I', $this->font_sizes['caption']);
        $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
        $this->pdf->Cell(0, 5, 'Data based on closed sales in ' . $city . ' over the past 12 months.', 0, 1, 'C');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
    }

    /**
     * Render a line chart using TCPDF drawing primitives
     *
     * @param array $data Numeric data points
     * @param array $labels X-axis labels
     * @param float $x X position
     * @param float $y Y position
     * @param float $width Chart width
     * @param float $height Chart height
     * @param array $color RGB color array
     * @param string $value_format sprintf format for values
     * @param bool $show_grid Show horizontal grid lines
     */
    private function render_line_chart($data, $labels, $x, $y, $width, $height, $color, $value_format = '%s', $show_grid = true) {
        if (empty($data)) {
            return;
        }

        $min_val = min($data);
        $max_val = max($data);
        $range = $max_val - $min_val;

        // Add 10% padding to range
        $padding = $range * 0.1;
        $min_val = max(0, $min_val - $padding);
        $max_val = $max_val + $padding;
        $range = $max_val - $min_val;

        if ($range == 0) {
            $range = 1; // Prevent division by zero
        }

        $chart_left = $x + 25;  // Leave room for Y-axis labels
        $chart_width = $width - 30;
        $chart_top = $y + 5;
        $chart_height = $height - 15; // Leave room for X-axis labels

        $point_count = count($data);
        $x_step = $chart_width / max(1, $point_count - 1);

        // Draw chart background
        $this->pdf->SetFillColor(250, 250, 250);
        $this->pdf->Rect($chart_left, $chart_top, $chart_width, $chart_height, 'F');

        // Draw grid lines
        if ($show_grid) {
            $this->pdf->SetDrawColor(230, 230, 230);
            $this->pdf->SetLineWidth(0.1);
            $grid_lines = 4;
            for ($i = 0; $i <= $grid_lines; $i++) {
                $grid_y = $chart_top + ($chart_height * $i / $grid_lines);
                $this->pdf->Line($chart_left, $grid_y, $chart_left + $chart_width, $grid_y);

                // Y-axis labels
                $grid_value = $max_val - ($range * $i / $grid_lines);
                $this->pdf->SetFont('helvetica', '', 7);
                $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
                $label_text = $this->format_chart_value($grid_value, $value_format);
                $this->pdf->SetXY($x, $grid_y - 2);
                $this->pdf->Cell(24, 4, $label_text, 0, 0, 'R');
            }
        }

        // Draw the line
        $this->pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $this->pdf->SetLineWidth(0.8);

        $points = array();
        foreach ($data as $i => $value) {
            $px = $chart_left + ($i * $x_step);
            $py = $chart_top + $chart_height - (($value - $min_val) / $range * $chart_height);
            $points[] = array($px, $py);

            if ($i > 0) {
                $this->pdf->Line($points[$i-1][0], $points[$i-1][1], $px, $py);
            }
        }

        // Draw data points
        $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
        foreach ($points as $point) {
            $this->pdf->Circle($point[0], $point[1], 1.2, 0, 360, 'F');
        }

        // Draw X-axis labels
        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
        $label_interval = max(1, floor($point_count / 6)); // Show ~6 labels max

        foreach ($labels as $i => $label) {
            if ($i % $label_interval == 0 || $i == $point_count - 1) {
                $lx = $chart_left + ($i * $x_step) - 5;
                $this->pdf->SetXY($lx, $chart_top + $chart_height + 1);
                $this->pdf->Cell(12, 4, $label, 0, 0, 'C');
            }
        }

        // Draw border
        $this->pdf->SetDrawColor(200, 200, 200);
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->Rect($chart_left, $chart_top, $chart_width, $chart_height, 'D');

        // Reset colors
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $this->pdf->SetDrawColor(0, 0, 0);
    }

    /**
     * Render a bar chart using TCPDF drawing primitives
     *
     * @param array $data Numeric data points
     * @param array $labels X-axis labels
     * @param float $x X position
     * @param float $y Y position
     * @param float $width Chart width
     * @param float $height Chart height
     * @param array $color RGB color array
     * @param string $value_format sprintf format for values
     */
    private function render_bar_chart($data, $labels, $x, $y, $width, $height, $color, $value_format = '%s') {
        if (empty($data)) {
            return;
        }

        $max_val = max($data);
        if ($max_val == 0) {
            $max_val = 1; // Prevent division by zero
        }

        $chart_left = $x + 20;  // Leave room for Y-axis labels
        $chart_width = $width - 25;
        $chart_top = $y + 5;
        $chart_height = $height - 15; // Leave room for X-axis labels

        $bar_count = count($data);
        $bar_width = ($chart_width / $bar_count) * 0.7;
        $bar_spacing = ($chart_width / $bar_count) * 0.3;

        // Draw chart background
        $this->pdf->SetFillColor(250, 250, 250);
        $this->pdf->Rect($chart_left, $chart_top, $chart_width, $chart_height, 'F');

        // Draw horizontal grid lines
        $this->pdf->SetDrawColor(230, 230, 230);
        $this->pdf->SetLineWidth(0.1);
        $grid_lines = 4;
        for ($i = 0; $i <= $grid_lines; $i++) {
            $grid_y = $chart_top + ($chart_height * $i / $grid_lines);
            $this->pdf->Line($chart_left, $grid_y, $chart_left + $chart_width, $grid_y);

            // Y-axis labels
            $grid_value = $max_val - ($max_val * $i / $grid_lines);
            $this->pdf->SetFont('helvetica', '', 7);
            $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
            $this->pdf->SetXY($x, $grid_y - 2);
            $this->pdf->Cell(19, 4, number_format($grid_value, 0), 0, 0, 'R');
        }

        // Draw bars
        $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
        foreach ($data as $i => $value) {
            $bar_height = ($value / $max_val) * $chart_height;
            $bx = $chart_left + ($i * ($bar_width + $bar_spacing)) + ($bar_spacing / 2);
            $by = $chart_top + $chart_height - $bar_height;

            $this->pdf->Rect($bx, $by, $bar_width, $bar_height, 'F');
        }

        // Draw X-axis labels
        $this->pdf->SetFont('helvetica', '', 7);
        $this->pdf->SetTextColor($this->medium_gray[0], $this->medium_gray[1], $this->medium_gray[2]);
        $label_interval = max(1, floor($bar_count / 6)); // Show ~6 labels max

        foreach ($labels as $i => $label) {
            if ($i % $label_interval == 0 || $i == $bar_count - 1) {
                $lx = $chart_left + ($i * ($bar_width + $bar_spacing)) + ($bar_spacing / 2) - 2;
                $this->pdf->SetXY($lx, $chart_top + $chart_height + 1);
                $this->pdf->Cell($bar_width + 4, 4, $label, 0, 0, 'C');
            }
        }

        // Draw border
        $this->pdf->SetDrawColor(200, 200, 200);
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->Rect($chart_left, $chart_top, $chart_width, $chart_height, 'D');

        // Reset colors
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $this->pdf->SetDrawColor(0, 0, 0);
    }

    /**
     * Format chart value based on format string
     *
     * @param float $value The value to format
     * @param string $format sprintf format string
     * @return string Formatted value
     */
    private function format_chart_value($value, $format) {
        if (strpos($format, '$') !== false) {
            // Currency format
            if ($value >= 1000000) {
                return '$' . number_format($value / 1000000, 1) . 'M';
            } elseif ($value >= 1000) {
                return '$' . number_format($value / 1000, 0) . 'K';
            } else {
                return '$' . number_format($value, 0);
            }
        } elseif (strpos($format, 'days') !== false) {
            return number_format($value, 0);
        } else {
            return sprintf($format, $value);
        }
    }
}
