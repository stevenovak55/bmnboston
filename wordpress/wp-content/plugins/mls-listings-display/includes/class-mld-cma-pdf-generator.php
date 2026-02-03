<?php
/**
 * MLD CMA PDF Generator
 *
 * Generates professional PDF CMA reports using TCPDF
 * Includes property comparables, adjustments, market analysis, and forecasts
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

    /**
     * PDF instance
     */
    private $pdf;

    /**
     * Report data
     */
    private $data;

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

        // Initialize PDF
        $this->initialize_pdf();

        // Generate report sections
        $this->add_cover_page();
        $this->add_executive_summary();
        $this->add_subject_property_section();
        $this->add_comparables_section();
        $this->add_market_conditions_section(); // v6.18.0
        $this->add_market_analysis_section();
        $this->add_forecast_section();
        $this->add_investment_analysis_section();
        $this->add_disclaimer();

        // Save PDF
        return $this->save_pdf();
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
        $this->pdf->SetCreator('MLS Listings Display Plugin');
        $this->pdf->SetAuthor($this->data['options']['agent_name'] ?: 'Real Estate Professional');
        $this->pdf->SetTitle('CMA Report - ' . ($this->data['subject']['address'] ?? 'Property'));
        $this->pdf->SetSubject('Comparative Market Analysis');

        // Set margins
        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->SetHeaderMargin(10);
        $this->pdf->SetFooterMargin(10);

        // Set auto page breaks
        $this->pdf->SetAutoPageBreak(true, 15);

        // Set font
        $this->pdf->SetFont('helvetica', '', 10);

        // Remove default header/footer
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
    }

    /**
     * Add cover page
     */
    private function add_cover_page() {
        $this->pdf->AddPage();

        // Background color block
        $this->pdf->SetFillColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->Rect(0, 0, 216, 80, 'F');

        // Brokerage logo (if provided)
        if (!empty($this->data['options']['brokerage_logo']) && file_exists($this->data['options']['brokerage_logo'])) {
            $this->pdf->Image($this->data['options']['brokerage_logo'], 15, 15, 50, 0, '', '', '', false, 300, '', false, false, 0);
        }

        // Report title
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 32);
        $this->pdf->SetXY(15, 100);
        $this->pdf->Cell(0, 20, $this->data['options']['report_title'], 0, 1, 'C');

        // Property address
        $this->pdf->SetFont('helvetica', '', 18);
        $this->pdf->SetXY(15, 125);
        $address = $this->data['subject']['address'] ?? 'Subject Property';
        $this->pdf->Cell(0, 10, $address, 0, 1, 'C');

        // City, State
        $this->pdf->SetFont('helvetica', '', 14);
        $this->pdf->SetXY(15, 140);
        $location = ($this->data['subject']['city'] ?? '') . ', ' . ($this->data['subject']['state'] ?? '');
        $this->pdf->Cell(0, 10, $location, 0, 1, 'C');

        // Reset text color
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Report details box
        $this->pdf->SetFillColor(245, 245, 245);
        $this->pdf->SetFont('helvetica', '', 11);
        $y_start = 170;

        $this->pdf->RoundedRect(40, $y_start, 136, 40, 3, '1111', 'DF', array(), array(245, 245, 245));

        $this->pdf->SetXY(50, $y_start + 5);
        $this->pdf->Cell(60, 6, 'Prepared For:', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 6, $this->data['options']['prepared_for'] ?: 'Property Owner', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 11);
        $this->pdf->SetX(50);
        $this->pdf->Cell(60, 6, 'Report Date:', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 6, $this->data['options']['report_date'], 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 11);
        $this->pdf->SetX(50);
        $this->pdf->Cell(60, 6, 'Prepared By:', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 6, $this->data['options']['agent_name'] ?: 'Licensed Agent', 0, 1, 'L');

        if (!empty($this->data['options']['agent_license'])) {
            $this->pdf->SetFont('helvetica', '', 9);
            $this->pdf->SetX(50);
            $this->pdf->Cell(60, 6, 'License:', 0, 0, 'L');
            $this->pdf->Cell(0, 6, $this->data['options']['agent_license'], 0, 1, 'L');
        }

        // Agent contact info at bottom
        if (!empty($this->data['options']['agent_phone']) || !empty($this->data['options']['agent_email'])) {
            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->SetXY(15, 240);
            $contact_parts = array();
            if (!empty($this->data['options']['agent_phone'])) {
                $contact_parts[] = 'Phone: ' . $this->data['options']['agent_phone'];
            }
            if (!empty($this->data['options']['agent_email'])) {
                $contact_parts[] = 'Email: ' . $this->data['options']['agent_email'];
            }
            $this->pdf->Cell(0, 6, implode(' | ', $contact_parts), 0, 1, 'C');
        }
    }

    /**
     * Add executive summary page
     */
    private function add_executive_summary() {
        $this->pdf->AddPage();
        $this->add_section_header('Executive Summary');

        $summary = $this->data['cma']['summary'];
        $forecast = $this->data['cma']['forecast'] ?? array();

        // Estimated value box (prominent)
        $this->pdf->SetFillColor($this->success_color[0], $this->success_color[1], $this->success_color[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 16);

        $y = $this->pdf->GetY() + 5;
        $this->pdf->RoundedRect(15, $y, 186, 30, 3, '1111', 'F');

        $this->pdf->SetXY(15, $y + 5);
        $this->pdf->Cell(0, 8, 'Estimated Market Value', 0, 1, 'C');

        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->SetXY(15, $y + 15);
        $value_range = '$' . number_format($summary['estimated_value']['low']) . ' - $' . number_format($summary['estimated_value']['high']);
        $this->pdf->Cell(0, 10, $value_range, 0, 1, 'C');

        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $this->pdf->Ln(10);

        // Key metrics grid
        $this->pdf->SetFont('helvetica', '', 10);
        $metrics = array(
            array('Total Comparables', $summary['total_found']),
            array('Average Price', '$' . number_format($summary['avg_price'])),
            array('Median Price', '$' . number_format($summary['median_price'])),
            array('Avg Days on Market', round($summary['avg_dom'])),
            array('Avg Price/SqFt', '$' . number_format($summary['price_per_sqft']['avg'])),
            array('Confidence Level', ucfirst($summary['estimated_value']['confidence']))
        );

        $col_width = 93;
        $row_height = 10;
        $x_start = 15;
        $y_start = $this->pdf->GetY();

        foreach ($metrics as $index => $metric) {
            $col = $index % 2;
            $row = floor($index / 2);

            $x = $x_start + ($col * $col_width);
            $y = $y_start + ($row * $row_height);

            $this->pdf->SetXY($x, $y);
            $this->pdf->SetFont('helvetica', 'B', 10);
            $this->pdf->Cell($col_width / 2, $row_height, $metric[0] . ':', 0, 0, 'L');
            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->Cell($col_width / 2, $row_height, $metric[1], 0, 0, 'R');
        }

        $this->pdf->Ln(15);

        // Weighted averaging explanation (v6.19.0)
        $this->add_weighted_value_section($summary);

        $this->pdf->Ln(10);

        // Market narrative (if forecast available)
        if (!empty($forecast['price_forecast']['success'])) {
            $this->add_subsection_header('Market Overview');

            require_once plugin_dir_path(__FILE__) . 'class-mld-market-narrative.php';
            $narrative_gen = new MLD_Market_Narrative();
            $narrative = $narrative_gen->generate_executive_summary(
                $forecast,
                array(),
                $this->data['subject']['city'] ?? '',
                $this->data['subject']['state'] ?? ''
            );

            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->MultiCell(0, 5, $narrative, 0, 'L');
        }
    }

    /**
     * Add subject property section
     */
    private function add_subject_property_section() {
        $this->pdf->AddPage();
        $this->add_section_header('Subject Property Details');

        $subject = $this->data['subject'];

        // Property details grid
        $details = array(
            array('Address', $subject['address'] ?? 'N/A'),
            array('City', $subject['city'] ?? 'N/A'),
            array('State', $subject['state'] ?? 'N/A'),
            array('Zip Code', $subject['postal_code'] ?? 'N/A'),
            array('Price', isset($subject['price']) ? '$' . number_format($subject['price']) : 'N/A'),
            array('Bedrooms', $subject['beds'] ?? 'N/A'),
            array('Bathrooms', $subject['baths'] ?? 'N/A'),
            array('Square Feet', isset($subject['sqft']) ? number_format($subject['sqft']) : 'N/A'),
            array('Year Built', $subject['year_built'] ?? 'N/A'),
            array('Lot Size', isset($subject['lot_size']) ? number_format($subject['lot_size'], 2) . ' acres' : 'N/A'),
            array('Garage', ($subject['garage_spaces'] ?? 0) . ' spaces'),
            array('Pool', ($subject['pool'] ?? 0) ? 'Yes' : 'No'),
        );

        $this->render_details_table($details);
    }

    /**
     * Add comparables section
     */
    private function add_comparables_section() {
        $this->pdf->AddPage();
        $this->add_section_header('Comparable Properties');

        $comparables = $this->data['cma']['comparables'];

        // Show top 6 comparables (A and B grades)
        $top_comps = array_filter($comparables, function($comp) {
            return in_array($comp['comparability_grade'], array('A', 'B'));
        });

        $top_comps = array_slice($top_comps, 0, 6);

        foreach ($top_comps as $index => $comp) {
            if ($index > 0 && $index % 2 == 0) {
                $this->pdf->AddPage();
            }

            $this->render_comparable_card($comp, $index % 2);
        }
    }

    /**
     * Render comparable property card
     *
     * @param array $comp Comparable data
     * @param int $position Position on page (0 = top, 1 = bottom)
     */
    private function render_comparable_card($comp, $position) {
        $y_start = $position == 0 ? $this->pdf->GetY() : ($this->pdf->GetY() + 5);
        $card_height = 120;

        // Card background
        $this->pdf->SetFillColor(250, 250, 250);
        $this->pdf->RoundedRect(15, $y_start, 186, $card_height, 2, '1111', 'DF', array(), array(250, 250, 250));

        // Grade badge
        $grade_color = $this->get_grade_color($comp['comparability_grade']);
        $this->pdf->SetFillColor($grade_color[0], $grade_color[1], $grade_color[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->SetXY(175, $y_start + 5);
        $this->pdf->Cell(20, 12, $comp['comparability_grade'], 0, 0, 'C', true);

        // Reset text color
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Address
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->SetXY(20, $y_start + 5);
        $this->pdf->Cell(150, 6, $comp['unparsed_address'], 0, 1, 'L');

        // City, State
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->SetX(20);
        $this->pdf->Cell(150, 5, $comp['city'] . ', ' . $comp['state'], 0, 1, 'L');

        // Details grid
        $y_details = $y_start + 20;
        $details = array(
            array('List Price', '$' . number_format($comp['list_price'])),
            array('Adjusted Price', '$' . number_format($comp['adjusted_price'])),
            array('Beds/Baths', $comp['bedrooms_total'] . ' / ' . $comp['bathrooms_total']),
            array('Square Feet', number_format($comp['building_area_total'])),
            array('Year Built', $comp['year_built']),
            array('Distance', $comp['distance_miles'] . ' mi'),
            array('Status', $comp['standard_status']),
            array('Days on Market', $comp['days_on_market']),
        );

        $col_width = 90;
        foreach ($details as $idx => $detail) {
            $col = $idx % 2;
            $row = floor($idx / 2);

            $x = 20 + ($col * $col_width);
            $y = $y_details + ($row * 6);

            $this->pdf->SetXY($x, $y);
            $this->pdf->SetFont('helvetica', 'B', 9);
            $this->pdf->Cell(40, 5, $detail[0] . ':', 0, 0, 'L');
            $this->pdf->SetFont('helvetica', '', 9);
            $this->pdf->Cell(45, 5, $detail[1], 0, 0, 'R');
        }

        // Adjustments summary
        $y_adj = $y_details + 30;
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->SetXY(20, $y_adj);
        $this->pdf->Cell(0, 5, 'Total Adjustments: $' . number_format($comp['adjustments']['total_adjustment']), 0, 1, 'L');

        // Top 3 adjustments
        $this->pdf->SetFont('helvetica', '', 8);
        $adj_items = array_slice($comp['adjustments']['items'], 0, 3);
        foreach ($adj_items as $adj) {
            $this->pdf->SetX(25);
            $sign = $adj['adjustment'] >= 0 ? '+' : '';
            $this->pdf->Cell(0, 4, $adj['feature'] . ': ' . $sign . '$' . number_format($adj['adjustment']), 0, 1, 'L');
        }

        if ($position == 0) {
            $this->pdf->SetY($y_start + $card_height + 5);
        }
    }

    /**
     * Add weighted value section to executive summary
     *
     * Explains weighted averaging methodology and shows comparison
     * between weighted and unweighted values.
     *
     * @since 6.19.0
     * @param array $summary Summary statistics from CMA
     */
    private function add_weighted_value_section($summary) {
        $estimated = $summary['estimated_value'] ?? array();

        // Check if we have weighted value data
        $mid_weighted = $estimated['mid_weighted'] ?? null;
        $mid_unweighted = $estimated['mid_unweighted'] ?? null;

        if ($mid_weighted === null || $mid_unweighted === null) {
            return; // No weighted data available
        }

        $difference = $mid_weighted - $mid_unweighted;
        $weight_breakdown = $estimated['weight_breakdown'] ?? array();

        // Section header
        $this->add_subsection_header('Weighted Value Analysis');

        // Draw comparison box
        $this->pdf->SetFont('helvetica', '', 9);
        $y = $this->pdf->GetY() + 2;

        // Light gray background
        $this->pdf->SetFillColor(248, 249, 250);
        $this->pdf->RoundedRect(15, $y, 186, 35, 2, '1111', 'F');

        // Weighted value (primary - emphasized)
        $this->pdf->SetXY(20, $y + 5);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->Cell(80, 6, 'Weighted Estimate:', 0, 0, 'L');
        $this->pdf->SetTextColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(80, 6, '$' . number_format($mid_weighted), 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Simple average
        $this->pdf->SetXY(20, $y + 13);
        $this->pdf->SetFont('helvetica', '', 9);
        $this->pdf->Cell(80, 5, 'Simple Average:', 0, 0, 'L');
        $this->pdf->Cell(80, 5, '$' . number_format($mid_unweighted), 0, 1, 'L');

        // Difference
        $this->pdf->SetXY(20, $y + 20);
        $this->pdf->Cell(80, 5, 'Difference:', 0, 0, 'L');
        $diff_color = $difference >= 0 ? $this->success_color : array(220, 53, 69);
        $this->pdf->SetTextColor($diff_color[0], $diff_color[1], $diff_color[2]);
        $this->pdf->SetFont('helvetica', 'B', 9);
        $sign = $difference >= 0 ? '+' : '';
        $this->pdf->Cell(80, 5, $sign . '$' . number_format(abs($difference)), 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Methodology explanation
        $this->pdf->SetXY(20, $y + 28);
        $this->pdf->SetFont('helvetica', 'I', 7);
        $this->pdf->SetTextColor(108, 117, 125);
        $this->pdf->Cell(0, 4, 'Weights: A-grade = 2.0x, B-grade = 1.5x, C-grade = 1.0x, D-grade = 0.5x, F-grade = 0.25x', 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        $this->pdf->SetY($y + 38);

        // Weight breakdown table (if available and has items)
        if (!empty($weight_breakdown) && count($weight_breakdown) <= 10) {
            $this->pdf->SetFont('helvetica', 'B', 8);
            $this->pdf->Cell(0, 5, 'Comparable Weighting Breakdown:', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', 7);

            // Table header
            $this->pdf->SetFillColor(240, 244, 248);
            $this->pdf->Cell(70, 5, 'Address', 1, 0, 'L', true);
            $this->pdf->Cell(20, 5, 'Grade', 1, 0, 'C', true);
            $this->pdf->Cell(20, 5, 'Weight', 1, 0, 'C', true);
            $this->pdf->Cell(35, 5, 'Adj. Price', 1, 0, 'R', true);
            $this->pdf->Cell(35, 5, 'Contribution', 1, 1, 'R', true);

            // Table rows
            foreach ($weight_breakdown as $comp) {
                $address = substr($comp['address'] ?? 'N/A', 0, 35);
                $grade = $comp['grade'] ?? 'C';
                $weight = $comp['weight'] ?? 1.0;
                $adj_price = $comp['adjusted_price'] ?? 0;
                $contribution = $comp['weighted_contribution'] ?? 0;
                $is_override = !empty($comp['is_override']);

                $weight_display = number_format($weight, 2) . 'x';
                if ($is_override) {
                    $weight_display .= '*';
                }

                $this->pdf->Cell(70, 4, $address, 1, 0, 'L');
                $this->pdf->Cell(20, 4, $grade, 1, 0, 'C');
                $this->pdf->Cell(20, 4, $weight_display, 1, 0, 'C');
                $this->pdf->Cell(35, 4, '$' . number_format($adj_price), 1, 0, 'R');
                $this->pdf->Cell(35, 4, '$' . number_format($contribution), 1, 1, 'R');
            }

            // Legend for manual overrides
            $has_overrides = array_filter($weight_breakdown, function($c) {
                return !empty($c['is_override']);
            });

            if (!empty($has_overrides)) {
                $this->pdf->SetFont('helvetica', 'I', 6);
                $this->pdf->SetTextColor(108, 117, 125);
                $this->pdf->Cell(0, 4, '* Weight manually adjusted by analyst', 0, 1, 'L');
                $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
            }
        }
    }

    /**
     * Add market conditions section
     *
     * Displays comprehensive market conditions analysis including
     * inventory levels, days on market, list-to-sale ratios, and price trends.
     *
     * @since 6.18.0
     */
    private function add_market_conditions_section() {
        // Get market conditions data
        $subject = $this->data['subject'];
        $city = $subject['city'] ?? '';
        $state = $subject['state'] ?? '';

        if (empty($city)) {
            return; // Skip if no city available
        }

        // Load market conditions
        require_once plugin_dir_path(__FILE__) . 'class-mld-market-conditions.php';
        $market_conditions = new MLD_Market_Conditions();
        $conditions = $market_conditions->get_market_conditions($city, $state, 'all', 12);

        if (!$conditions['success']) {
            return; // Skip if no data
        }

        $this->pdf->AddPage();
        $this->add_section_header('Market Conditions');

        $market_health = $conditions['market_health'] ?? array();
        $inventory = $conditions['inventory'] ?? array();
        $dom_trends = $conditions['days_on_market'] ?? array();
        $list_sale_ratio = $conditions['list_to_sale_ratio'] ?? array();
        $price_trends = $conditions['price_trends'] ?? array();

        // Market Status Box
        $status_color = $this->hex_to_rgb($market_health['status_color'] ?? '#6b7280');
        $this->pdf->SetFillColor($status_color[0], $status_color[1], $status_color[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 14);

        $y = $this->pdf->GetY() + 5;
        $this->pdf->RoundedRect(15, $y, 186, 25, 3, '1111', 'F');
        $this->pdf->SetXY(15, $y + 5);
        $this->pdf->Cell(186, 8, 'Market Status: ' . ($market_health['status'] ?? 'Unknown'), 0, 1, 'C');

        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->SetXY(15, $y + 15);
        $this->pdf->Cell(186, 6, $market_health['summary'] ?? '', 0, 1, 'C');

        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);
        $this->pdf->Ln(15);

        // Key Metrics Table
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
        $this->pdf->Ln(10);

        // Market Factors
        if (!empty($market_health['factors'])) {
            $this->add_subsection_header('Market Factors');
            $this->pdf->SetFont('helvetica', '', 10);

            foreach ($market_health['factors'] as $factor) {
                $this->pdf->SetX(20);
                $this->pdf->Cell(5, 6, chr(149), 0, 0, 'L'); // Bullet point
                $this->pdf->Cell(0, 6, $factor, 0, 1, 'L');
            }
            $this->pdf->Ln(5);
        }

        // Trend Descriptions
        $this->add_subsection_header('Trend Analysis');
        $this->pdf->SetFont('helvetica', '', 10);

        if (!empty($dom_trends['trend_description'])) {
            $this->pdf->SetFont('helvetica', 'B', 10);
            $this->pdf->Cell(0, 6, 'Days on Market:', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->MultiCell(0, 5, $dom_trends['trend_description'], 0, 'L');
            $this->pdf->Ln(3);
        }

        if (!empty($list_sale_ratio['trend_description'])) {
            $this->pdf->SetFont('helvetica', 'B', 10);
            $this->pdf->Cell(0, 6, 'List-to-Sale Ratio:', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->MultiCell(0, 5, $list_sale_ratio['trend_description'], 0, 'L');
            $this->pdf->Ln(3);
        }

        if (!empty($price_trends['trend_description'])) {
            $this->pdf->SetFont('helvetica', 'B', 10);
            $this->pdf->Cell(0, 6, 'Price Trends:', 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->MultiCell(0, 5, $price_trends['trend_description'], 0, 'L');
        }
    }

    /**
     * Convert hex color to RGB array
     *
     * @param string $hex Hex color code
     * @return array RGB values
     * @since 6.18.0
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
     * Add market analysis section
     */
    private function add_market_analysis_section() {
        $this->pdf->AddPage();
        $this->add_section_header('Market Analysis');

        $forecast = $this->data['cma']['forecast'] ?? array();

        if (empty($forecast['price_forecast']['success'])) {
            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->Cell(0, 6, 'Market analysis requires sufficient historical data.', 0, 1, 'L');
            return;
        }

        $price_forecast = $forecast['price_forecast'];

        // Appreciation metrics
        $this->add_subsection_header('Price Appreciation');

        $appreciation = $price_forecast['appreciation'];
        $metrics = array(
            array('3-Month Appreciation', $this->format_percentage($appreciation['3_month'])),
            array('6-Month Appreciation', $this->format_percentage($appreciation['6_month'])),
            array('12-Month Appreciation', $this->format_percentage($appreciation['12_month'])),
            array('Average Monthly', $this->format_percentage($appreciation['average_monthly'])),
        );

        $this->render_details_table($metrics);
        $this->pdf->Ln(5);

        // Momentum analysis
        $this->add_subsection_header('Market Momentum');

        $momentum = $price_forecast['momentum'];
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 6, 'Status: ' . ucfirst($momentum['status']), 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, $momentum['description'], 0, 'L');
        $this->pdf->Ln(3);

        $momentum_details = array(
            array('Direction', ucfirst($momentum['direction'])),
            array('Strength', $momentum['strength'] . '%'),
            array('Recent Trend', $this->format_percentage($momentum['recent_change_pct'])),
            array('Long-term Trend', $this->format_percentage($momentum['longer_term_change_pct'])),
        );

        $this->render_details_table($momentum_details);
    }

    /**
     * Add forecast section
     */
    private function add_forecast_section() {
        if (empty($this->data['options']['include_forecast'])) {
            return;
        }

        $forecast = $this->data['cma']['forecast'] ?? array();

        if (empty($forecast['price_forecast']['success'])) {
            return;
        }

        $this->pdf->AddPage();
        $this->add_section_header('Price Forecast');

        $price_forecast = $forecast['price_forecast'];
        $forecast_data = $price_forecast['forecast'];

        // Forecast table
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->SetFillColor(230, 230, 230);

        $col_widths = array(40, 40, 50, 50);
        $headers = array('Time Period', 'Predicted Price', 'Low Estimate', 'High Estimate');

        $x_start = 15;
        foreach ($headers as $idx => $header) {
            $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
            $this->pdf->Cell($col_widths[$idx], 8, $header, 1, 0, 'C', true);
        }
        $this->pdf->Ln();

        // Forecast rows
        $this->pdf->SetFont('helvetica', '', 10);
        foreach ($forecast_data as $f) {
            $row_data = array(
                $f['months_ahead'] . ' months',
                '$' . number_format($f['predicted_price']),
                '$' . number_format($f['low_estimate']),
                '$' . number_format($f['high_estimate']),
            );

            foreach ($row_data as $idx => $data) {
                $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
                $this->pdf->Cell($col_widths[$idx], 7, $data, 1, 0, 'C');
            }
            $this->pdf->Ln();
        }

        $this->pdf->Ln(5);

        // Confidence level
        $confidence = $price_forecast['confidence'];
        $this->add_subsection_header('Forecast Confidence');

        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 6, 'Confidence Level: ' . ucfirst($confidence['level']) . ' (' . $confidence['score'] . '/100)', 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, $confidence['description'], 0, 'L');
    }

    /**
     * Add investment analysis section
     */
    private function add_investment_analysis_section() {
        if (empty($this->data['options']['include_investment'])) {
            return;
        }

        $investment = $this->data['cma']['forecast']['investment_analysis'] ?? array();

        if (empty($investment['success'])) {
            return;
        }

        $this->pdf->AddPage();
        $this->add_section_header('Investment Analysis');

        // Annual appreciation rate
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(0, 8, 'Projected Annual Appreciation: ' . $this->format_percentage($investment['annual_appreciation_rate']), 0, 1, 'L');
        $this->pdf->Ln(3);

        // Projected values table
        $this->add_subsection_header('Projected Property Values');

        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->SetFillColor(230, 230, 230);

        $col_widths = array(40, 50, 50, 40);
        $headers = array('Time Period', 'Projected Value', 'Appreciation', 'Total %');

        $x_start = 15;
        foreach ($headers as $idx => $header) {
            $this->pdf->SetX($x_start + array_sum(array_slice($col_widths, 0, $idx)));
            $this->pdf->Cell($col_widths[$idx], 8, $header, 1, 0, 'C', true);
        }
        $this->pdf->Ln();

        // Projection rows
        $this->pdf->SetFont('helvetica', '', 10);
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
                $this->pdf->Cell($col_widths[$idx], 7, $value, 1, 0, 'C');
            }
            $this->pdf->Ln();
        }

        $this->pdf->Ln(5);

        // Risk assessment
        $this->add_subsection_header('Risk Assessment');

        $risk = $investment['risk_assessment'];
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 6, 'Risk Level: ' . ucfirst($risk['level']), 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, $risk['description'], 0, 'L');
    }

    /**
     * Add disclaimer page
     */
    private function add_disclaimer() {
        $this->pdf->AddPage();
        $this->add_section_header('Important Disclaimer');

        $disclaimer = "This Comparative Market Analysis (CMA) is provided for informational purposes only and is not an appraisal. The values and projections contained in this report are estimates based on available market data and statistical analysis. Actual property values may differ.\n\n";

        $disclaimer .= "Market forecasts and investment projections are based on historical trends and statistical models. Past performance does not guarantee future results. Real estate markets can be affected by numerous factors including economic conditions, interest rates, local development, and other variables that cannot be predicted with certainty.\n\n";

        $disclaimer .= "This report should not be relied upon as the sole basis for making real estate decisions. We recommend consulting with licensed professionals including real estate agents, appraisers, attorneys, and financial advisors before making any real estate transaction.\n\n";

        $disclaimer .= "The information contained in this report has been obtained from sources believed to be reliable. However, " . ($this->data['options']['brokerage_name'] ?: 'the preparer') . " makes no warranty, express or implied, as to the accuracy or completeness of this information.\n\n";

        $disclaimer .= "© " . date('Y') . " " . ($this->data['options']['brokerage_name'] ?: 'All Rights Reserved') . ". This report is confidential and prepared exclusively for " . ($this->data['options']['prepared_for'] ?: 'the named recipient') . ".";

        $this->pdf->SetFont('helvetica', '', 9);
        $this->pdf->MultiCell(0, 5, $disclaimer, 0, 'J');
    }

    /**
     * Save PDF and return file path
     *
     * @return string File path or false on failure
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
     * Helper: Add section header
     */
    private function add_section_header($title) {
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->SetTextColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->Cell(0, 10, $title, 0, 1, 'L');
        $this->pdf->SetTextColor($this->text_color[0], $this->text_color[1], $this->text_color[2]);

        // Underline
        $this->pdf->SetDrawColor($this->primary_color[0], $this->primary_color[1], $this->primary_color[2]);
        $this->pdf->SetLineWidth(0.5);
        $y = $this->pdf->GetY();
        $this->pdf->Line(15, $y, 201, $y);
        $this->pdf->Ln(5);
    }

    /**
     * Helper: Add subsection header
     */
    private function add_subsection_header($title) {
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(0, 8, $title, 0, 1, 'L');
    }

    /**
     * Helper: Render details table
     */
    private function render_details_table($details) {
        $this->pdf->SetFont('helvetica', '', 10);
        $col_width = 93;

        foreach ($details as $index => $detail) {
            $col = $index % 2;
            $row = floor($index / 2);

            if ($col == 0) {
                $this->pdf->SetX(15);
            }

            $this->pdf->SetFont('helvetica', 'B', 10);
            $this->pdf->Cell($col_width / 2, 6, $detail[0] . ':', 0, 0, 'L');
            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->Cell($col_width / 2, 6, $detail[1], 0, $col == 1 ? 1 : 0, 'R');
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
}
