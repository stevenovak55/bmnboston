<?php
/**
 * MLD Market Report Generator
 *
 * Generates professional PDF market analytics reports using TCPDF
 * Includes market trends, price analysis, DOM metrics, and forecasts
 * Falls back to HTML export if TCPDF is not available
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.12.5
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load TCPDF if not already loaded
if (!class_exists('TCPDF')) {
    $tcpdf_path = plugin_dir_path(__FILE__) . '../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdf_path)) {
        require_once $tcpdf_path;
    }
}

class MLD_Market_Report_Generator {

    /**
     * Brand colors
     */
    private $primary_color = array(44, 90, 160);   // #2c5aa0
    private $secondary_color = array(30, 66, 120); // #1e4278
    private $success_color = array(40, 167, 69);   // #28a745
    private $warning_color = array(255, 193, 7);   // #ffc107
    private $danger_color = array(220, 53, 69);    // #dc3545
    private $text_color = array(51, 51, 51);       // #333333

    /**
     * PDF instance
     */
    private $pdf;

    /**
     * Report data
     */
    private $data;

    /**
     * Check if PDF generation is available
     *
     * @return bool
     */
    public static function is_pdf_available() {
        return class_exists('TCPDF');
    }

    /**
     * Generate market analytics report
     *
     * @param string $city City name
     * @param string $state State abbreviation
     * @param string $property_type Property type filter
     * @param int $months Number of months for analysis
     * @param array $options Report options
     * @return string|array PDF file path, HTML content, or error
     */
    public function generate_report($city, $state, $property_type = 'all', $months = 12, $options = array()) {
        // Load required classes
        require_once plugin_dir_path(__FILE__) . 'class-mld-market-trends.php';
        require_once plugin_dir_path(__FILE__) . 'class-mld-extended-analytics.php';

        // Gather all market data
        $this->data = $this->gather_market_data($city, $state, $property_type, $months);
        $this->data['options'] = wp_parse_args($options, $this->get_default_options());
        $this->data['city'] = $city;
        $this->data['state'] = $state;
        $this->data['property_type'] = $property_type;
        $this->data['months'] = $months;

        // Check if we should generate PDF or HTML
        $format = isset($options['format']) ? $options['format'] : 'pdf';

        if ($format === 'html' || !self::is_pdf_available()) {
            return $this->generate_html_report();
        }

        return $this->generate_pdf_report();
    }

    /**
     * Get default options
     *
     * @return array
     */
    private function get_default_options() {
        return array(
            'report_title' => 'Market Analytics Report',
            'agent_name' => '',
            'agent_email' => '',
            'agent_phone' => '',
            'agent_license' => '',
            'brokerage_name' => '',
            'brokerage_logo' => '',
            'report_date' => date('F j, Y'),
            'prepared_for' => '',
            'include_charts' => true,
            'include_forecast' => true,
            'include_agents' => true,
            'include_features' => true,
            'format' => 'pdf'
        );
    }

    /**
     * Gather all market data for the report
     *
     * @param string $city
     * @param string $state
     * @param string $property_type
     * @param int $months
     * @return array
     */
    private function gather_market_data($city, $state, $property_type, $months) {
        $trends = new MLD_Market_Trends();

        return array(
            'market_summary' => $trends->get_market_summary($city, $state, $property_type, $months),
            'monthly_trends' => $trends->calculate_monthly_trends($city, $state, $property_type, $months),
            'quarterly_trends' => $trends->calculate_quarterly_trends($city, $state, $property_type, (int) ceil($months / 3)),
            'yoy_comparison' => $trends->calculate_yoy_comparison($city, $state, $property_type),
            'appreciation' => $trends->calculate_appreciation_rate($city, $state, $property_type, $months),
            'city_summary' => MLD_Extended_Analytics::get_city_summary($city, $state),
            'market_heat' => MLD_Extended_Analytics::get_market_heat_index($city, $state),
            'supply_demand' => MLD_Extended_Analytics::get_supply_demand_metrics($city, $state),
            'dom_analysis' => MLD_Extended_Analytics::get_dom_analysis($city, $state, $property_type, $months),
            'price_by_bedrooms' => MLD_Extended_Analytics::get_price_by_bedrooms($city, $state, $months),
            'property_characteristics' => MLD_Extended_Analytics::get_property_characteristics($city, $state, $months),
            'feature_premiums' => MLD_Extended_Analytics::get_all_feature_premiums($city, $state, $property_type, $months),
            'top_agents' => MLD_Extended_Analytics::get_top_agents($city, $state, 10, $months),
            'financial_analysis' => MLD_Extended_Analytics::get_financial_analysis($city, $state, $property_type, $months)
        );
    }

    /**
     * Generate PDF report using TCPDF
     *
     * @return string|array File path or error
     */
    private function generate_pdf_report() {
        if (!class_exists('TCPDF')) {
            return array('error' => 'TCPDF library not available. Please install via Composer: composer require tecnickcom/tcpdf');
        }

        try {
            $this->initialize_pdf();

            $this->add_cover_page();
            $this->add_executive_summary();
            $this->add_market_overview_section();
            $this->add_price_trends_section();
            $this->add_dom_analysis_section();
            $this->add_supply_demand_section();

            if ($this->data['options']['include_features']) {
                $this->add_feature_premiums_section();
            }

            if ($this->data['options']['include_agents']) {
                $this->add_agent_performance_section();
            }

            $this->add_property_characteristics_section();
            $this->add_disclaimer();

            return $this->save_pdf();
        } catch (Exception $e) {
            return array('error' => 'PDF generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize TCPDF
     */
    private function initialize_pdf() {
        $this->pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);

        // Set document properties
        $this->pdf->SetCreator('MLS Listings Display');
        $this->pdf->SetAuthor($this->data['options']['agent_name'] ?: 'Real Estate Professional');
        $this->pdf->SetTitle($this->data['options']['report_title']);
        $this->pdf->SetSubject('Market Analytics Report - ' . $this->data['city'] . ', ' . $this->data['state']);

        // Remove default header/footer
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(true);

        // Set margins
        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->SetAutoPageBreak(true, 20);

        // Set font
        $this->pdf->SetFont('helvetica', '', 10);
    }

    /**
     * Add cover page
     */
    private function add_cover_page() {
        $this->pdf->AddPage();

        // Title
        $this->pdf->SetFont('helvetica', 'B', 28);
        $this->pdf->SetTextColorArray($this->primary_color);
        $this->pdf->Ln(40);
        $this->pdf->Cell(0, 15, $this->data['options']['report_title'], 0, 1, 'C');

        // City/State
        $this->pdf->SetFont('helvetica', '', 20);
        $this->pdf->SetTextColorArray($this->secondary_color);
        $this->pdf->Cell(0, 12, $this->data['city'] . ', ' . $this->data['state'], 0, 1, 'C');

        // Property type
        if ($this->data['property_type'] !== 'all') {
            $this->pdf->SetFont('helvetica', '', 14);
            $this->pdf->Cell(0, 8, $this->data['property_type'] . ' Properties', 0, 1, 'C');
        }

        // Date
        $this->pdf->Ln(10);
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->SetTextColorArray($this->text_color);
        $this->pdf->Cell(0, 8, $this->data['options']['report_date'], 0, 1, 'C');

        // Market Heat Badge
        if (!empty($this->data['market_heat']) && isset($this->data['market_heat']['heat_index'])) {
            $this->pdf->Ln(20);
            $heat = $this->data['market_heat'];
            $this->pdf->SetFont('helvetica', 'B', 16);
            $this->pdf->Cell(0, 10, 'Market Health Score: ' . $heat['heat_index'] . ' - ' . $heat['classification'], 0, 1, 'C');
        }

        // Agent info
        if (!empty($this->data['options']['agent_name'])) {
            $this->pdf->Ln(30);
            $this->pdf->SetFont('helvetica', '', 12);
            $this->pdf->Cell(0, 6, 'Prepared by:', 0, 1, 'C');
            $this->pdf->SetFont('helvetica', 'B', 14);
            $this->pdf->Cell(0, 8, $this->data['options']['agent_name'], 0, 1, 'C');

            if (!empty($this->data['options']['brokerage_name'])) {
                $this->pdf->SetFont('helvetica', '', 11);
                $this->pdf->Cell(0, 6, $this->data['options']['brokerage_name'], 0, 1, 'C');
            }
        }

        // Prepared for
        if (!empty($this->data['options']['prepared_for'])) {
            $this->pdf->Ln(10);
            $this->pdf->SetFont('helvetica', '', 12);
            $this->pdf->Cell(0, 6, 'Prepared for: ' . $this->data['options']['prepared_for'], 0, 1, 'C');
        }
    }

    /**
     * Add executive summary
     */
    private function add_executive_summary() {
        $this->pdf->AddPage();
        $this->add_section_header('Executive Summary');

        $summary = $this->data['market_summary'];
        $heat = $this->data['market_heat'];
        $appreciation = $this->data['appreciation'];

        if (isset($summary['error'])) {
            $this->pdf->SetFont('helvetica', '', 11);
            $this->pdf->MultiCell(0, 6, 'Insufficient data available for this market.', 0, 'L');
            return;
        }

        // Key metrics table
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 8, 'Key Market Metrics (' . $this->data['months'] . ' Month Period)', 0, 1);

        $metrics = array(
            array('Total Sales', number_format($summary['total_sales'])),
            array('Average Sale Price', '$' . number_format($summary['avg_close_price'])),
            array('Price per Sq Ft', '$' . number_format($summary['avg_price_per_sqft'])),
            array('Average Days on Market', number_format($summary['avg_dom']) . ' days'),
            array('Sale-to-List Ratio', number_format($summary['avg_sp_lp_ratio'], 1) . '%'),
            array('Total Sales Volume', '$' . number_format($summary['total_volume'] / 1000000, 1) . 'M'),
        );

        if (!isset($appreciation['error'])) {
            $metrics[] = array('Price Appreciation', ($appreciation['total_change_pct'] > 0 ? '+' : '') . $appreciation['total_change_pct'] . '%');
        }

        $this->render_key_value_table($metrics);

        // Market interpretation
        if (!empty($heat) && isset($heat['interpretation'])) {
            $this->pdf->Ln(5);
            $this->pdf->SetFont('helvetica', 'B', 11);
            $this->pdf->Cell(0, 8, 'Market Analysis', 0, 1);
            $this->pdf->SetFont('helvetica', '', 10);
            $this->pdf->MultiCell(0, 5, $heat['interpretation'], 0, 'L');
        }
    }

    /**
     * Add market overview section
     */
    private function add_market_overview_section() {
        $this->pdf->AddPage();
        $this->add_section_header('Market Overview');

        $city_summary = $this->data['city_summary'];
        $supply = $this->data['supply_demand'];

        // Current inventory
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 8, 'Current Inventory', 0, 1);

        $inventory = array(
            array('Active Listings', number_format($city_summary['active_count'] ?? 0)),
            array('Pending Sales', number_format($city_summary['pending_count'] ?? 0)),
            array('New This Week', number_format($city_summary['new_this_week'] ?? 0)),
            array('New This Month', number_format($city_summary['new_this_month'] ?? 0)),
            array('Average List Price', '$' . number_format($city_summary['avg_list_price'] ?? 0)),
        );

        $this->render_key_value_table($inventory);

        // Supply/Demand metrics
        $this->pdf->Ln(5);
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 8, 'Supply & Demand Indicators', 0, 1);

        $supply_metrics = array(
            array('Months of Supply', number_format($supply['months_supply'] ?? 0, 1)),
            array('Absorption Rate', number_format($supply['absorption_rate'] ?? 0, 1) . '%'),
            array('Average Monthly Sales', number_format($supply['avg_monthly_sales'] ?? 0, 1)),
        );

        $this->render_key_value_table($supply_metrics);

        // Market interpretation
        $months_supply = $supply['months_supply'] ?? 6;
        $interpretation = '';
        if ($months_supply < 3) {
            $interpretation = "This is a strong seller's market with very limited inventory. Buyers should expect competition and be prepared to act quickly.";
        } elseif ($months_supply < 6) {
            $interpretation = "This is a balanced to slightly seller-favored market. Well-priced properties are moving quickly.";
        } else {
            $interpretation = "This is a buyer's market with more inventory available. Buyers have more negotiating power and time to make decisions.";
        }

        $this->pdf->Ln(5);
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, $interpretation, 0, 'L');
    }

    /**
     * Add price trends section
     */
    private function add_price_trends_section() {
        $this->pdf->AddPage();
        $this->add_section_header('Price Trends');

        $monthly = $this->data['monthly_trends'];
        $yoy = $this->data['yoy_comparison'];

        if (empty($monthly)) {
            $this->pdf->SetFont('helvetica', '', 11);
            $this->pdf->MultiCell(0, 6, 'Insufficient price trend data available.', 0, 'L');
            return;
        }

        // Monthly trends table
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 8, 'Monthly Price Trends', 0, 1);

        // Table header
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->SetFillColorArray(array(240, 240, 240));
        $this->pdf->Cell(35, 7, 'Month', 1, 0, 'L', true);
        $this->pdf->Cell(25, 7, 'Sales', 1, 0, 'C', true);
        $this->pdf->Cell(35, 7, 'Avg Price', 1, 0, 'R', true);
        $this->pdf->Cell(25, 7, '$/SqFt', 1, 0, 'R', true);
        $this->pdf->Cell(20, 7, 'DOM', 1, 0, 'C', true);
        $this->pdf->Cell(25, 7, 'SP/LP', 1, 0, 'C', true);
        $this->pdf->Cell(20, 7, 'MoM %', 1, 1, 'C', true);

        // Table data (last 6 months)
        $this->pdf->SetFont('helvetica', '', 9);
        $recent_months = array_slice($monthly, -6);
        foreach ($recent_months as $month) {
            $this->pdf->Cell(35, 6, $month['month_name'], 1, 0, 'L');
            $this->pdf->Cell(25, 6, number_format($month['sales_count']), 1, 0, 'C');
            $this->pdf->Cell(35, 6, '$' . number_format($month['avg_close_price']), 1, 0, 'R');
            $this->pdf->Cell(25, 6, '$' . number_format($month['avg_price_per_sqft']), 1, 0, 'R');
            $this->pdf->Cell(20, 6, number_format($month['avg_dom'], 0), 1, 0, 'C');
            $this->pdf->Cell(25, 6, number_format($month['avg_sp_lp_ratio'], 1) . '%', 1, 0, 'C');
            $change = $month['mom_price_change_pct'];
            $this->pdf->Cell(20, 6, ($change > 0 ? '+' : '') . number_format($change, 1) . '%', 1, 1, 'C');
        }

        // Year-over-Year comparison
        if (!isset($yoy['error']) && !empty($yoy)) {
            $this->pdf->Ln(10);
            $this->pdf->SetFont('helvetica', 'B', 11);
            $this->pdf->Cell(0, 8, 'Year-over-Year Comparison', 0, 1);

            foreach ($yoy as $comparison) {
                $this->pdf->SetFont('helvetica', '', 10);
                $this->pdf->Cell(0, 6, $comparison['current_year'] . ' vs ' . $comparison['previous_year'] . ':', 0, 1);
                $this->pdf->Cell(90, 5, '  Price Change: ' . ($comparison['price_change_pct'] > 0 ? '+' : '') . $comparison['price_change_pct'] . '%', 0, 0);
                $this->pdf->Cell(90, 5, '  Sales Volume Change: ' . ($comparison['volume_change_pct'] > 0 ? '+' : '') . $comparison['volume_change_pct'] . '%', 0, 1);
            }
        }
    }

    /**
     * Add DOM analysis section
     */
    private function add_dom_analysis_section() {
        $this->pdf->AddPage();
        $this->add_section_header('Days on Market Analysis');

        $dom = $this->data['dom_analysis'];

        if (empty($dom) || isset($dom['error'])) {
            $this->pdf->SetFont('helvetica', '', 11);
            $this->pdf->MultiCell(0, 6, 'Insufficient DOM data available.', 0, 'L');
            return;
        }

        // DOM summary
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 8, 'DOM Statistics', 0, 1);

        $dom_metrics = array(
            array('Average DOM', number_format($dom['avg_dom']) . ' days'),
            array('Minimum DOM', number_format($dom['min_dom']) . ' days'),
            array('Maximum DOM', number_format($dom['max_dom']) . ' days'),
            array('Market Speed', $dom['market_speed'] ?? 'Normal'),
        );

        $this->render_key_value_table($dom_metrics);

        // DOM distribution
        $this->pdf->Ln(5);
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 8, 'DOM Distribution', 0, 1);

        $distribution = array(
            array('Under 7 Days', $dom['pct_under_7_days'] . '%', $dom['sold_under_7_days'] . ' properties'),
            array('Under 14 Days', $dom['pct_under_14_days'] . '%', $dom['sold_under_14_days'] . ' properties'),
            array('Under 30 Days', $dom['pct_under_30_days'] . '%', $dom['sold_under_30_days'] . ' properties'),
            array('Under 60 Days', $dom['pct_under_60_days'] . '%', $dom['sold_under_60_days'] . ' properties'),
            array('Over 90 Days', $dom['pct_over_90_days'] . '%', $dom['sold_over_90_days'] . ' properties'),
        );

        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->SetFillColorArray(array(240, 240, 240));
        $this->pdf->Cell(60, 7, 'Time Range', 1, 0, 'L', true);
        $this->pdf->Cell(40, 7, 'Percentage', 1, 0, 'C', true);
        $this->pdf->Cell(60, 7, 'Count', 1, 1, 'C', true);

        $this->pdf->SetFont('helvetica', '', 9);
        foreach ($distribution as $row) {
            $this->pdf->Cell(60, 6, $row[0], 1, 0, 'L');
            $this->pdf->Cell(40, 6, $row[1], 1, 0, 'C');
            $this->pdf->Cell(60, 6, $row[2], 1, 1, 'C');
        }
    }

    /**
     * Add supply/demand section
     */
    private function add_supply_demand_section() {
        // This is covered in market overview
    }

    /**
     * Add feature premiums section
     */
    private function add_feature_premiums_section() {
        $premiums = $this->data['feature_premiums'];

        if (empty($premiums)) {
            return;
        }

        $this->pdf->AddPage();
        $this->add_section_header('Feature Premiums');

        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->MultiCell(0, 5, 'Properties with these features command price premiums compared to similar properties without them:', 0, 'L');
        $this->pdf->Ln(5);

        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->SetFillColorArray(array(240, 240, 240));
        $this->pdf->Cell(50, 7, 'Feature', 1, 0, 'L', true);
        $this->pdf->Cell(35, 7, 'Premium', 1, 0, 'C', true);
        $this->pdf->Cell(35, 7, 'Confidence', 1, 0, 'C', true);
        $this->pdf->Cell(50, 7, 'Sample Size', 1, 1, 'C', true);

        $this->pdf->SetFont('helvetica', '', 9);
        foreach ($premiums as $key => $premium) {
            if (isset($premium['error'])) continue;

            $this->pdf->Cell(50, 6, $premium['label'], 1, 0, 'L');
            $this->pdf->Cell(35, 6, ($premium['premium_pct'] > 0 ? '+' : '') . $premium['premium_pct'] . '%', 1, 0, 'C');
            $this->pdf->Cell(35, 6, $premium['confidence'], 1, 0, 'C');
            $this->pdf->Cell(50, 6, ($premium['with_feature_count'] ?? 'N/A') . ' properties', 1, 1, 'C');
        }
    }

    /**
     * Add agent performance section
     */
    private function add_agent_performance_section() {
        $agents = $this->data['top_agents'];

        if (empty($agents)) {
            return;
        }

        $this->pdf->AddPage();
        $this->add_section_header('Top Performing Agents');

        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->SetFillColorArray(array(240, 240, 240));
        $this->pdf->Cell(60, 7, 'Agent Name', 1, 0, 'L', true);
        $this->pdf->Cell(30, 7, 'Transactions', 1, 0, 'C', true);
        $this->pdf->Cell(45, 7, 'Total Volume', 1, 0, 'R', true);
        $this->pdf->Cell(45, 7, 'Avg Price', 1, 1, 'R', true);

        $this->pdf->SetFont('helvetica', '', 9);
        foreach (array_slice($agents, 0, 10) as $agent) {
            $this->pdf->Cell(60, 6, $agent['agent_name'] ?? 'Unknown', 1, 0, 'L');
            $this->pdf->Cell(30, 6, number_format($agent['transaction_count'] ?? 0), 1, 0, 'C');
            $this->pdf->Cell(45, 6, '$' . number_format($agent['total_volume'] ?? 0), 1, 0, 'R');
            $this->pdf->Cell(45, 6, '$' . number_format($agent['avg_price'] ?? 0), 1, 1, 'R');
        }
    }

    /**
     * Add property characteristics section
     */
    private function add_property_characteristics_section() {
        $chars = $this->data['property_characteristics'];
        $beds = $this->data['price_by_bedrooms'];

        if (empty($chars) && empty($beds)) {
            return;
        }

        $this->pdf->AddPage();
        $this->add_section_header('Property Characteristics');

        if (!empty($chars)) {
            $this->pdf->SetFont('helvetica', 'B', 11);
            $this->pdf->Cell(0, 8, 'Typical Property Profile', 0, 1);

            $char_metrics = array(
                array('Average Square Feet', number_format($chars['avg_sqft'] ?? 0)),
                array('Average Bedrooms', number_format($chars['avg_bedrooms'] ?? 0, 1)),
                array('Average Bathrooms', number_format($chars['avg_bathrooms'] ?? 0, 1)),
                array('Average Age', number_format($chars['avg_age'] ?? 0) . ' years'),
                array('Has Garage', ($chars['pct_has_garage'] ?? 0) . '%'),
                array('Has Fireplace', ($chars['pct_has_fireplace'] ?? 0) . '%'),
                array('New Construction', ($chars['pct_new_construction'] ?? 0) . '%'),
            );

            $this->render_key_value_table($char_metrics);
        }

        if (!empty($beds)) {
            $this->pdf->Ln(10);
            $this->pdf->SetFont('helvetica', 'B', 11);
            $this->pdf->Cell(0, 8, 'Price by Bedroom Count', 0, 1);

            $this->pdf->SetFont('helvetica', 'B', 9);
            $this->pdf->SetFillColorArray(array(240, 240, 240));
            $this->pdf->Cell(40, 7, 'Bedrooms', 1, 0, 'L', true);
            $this->pdf->Cell(40, 7, 'Sales', 1, 0, 'C', true);
            $this->pdf->Cell(50, 7, 'Avg Price', 1, 0, 'R', true);
            $this->pdf->Cell(40, 7, 'Avg DOM', 1, 1, 'C', true);

            $this->pdf->SetFont('helvetica', '', 9);
            foreach ($beds as $bed) {
                $this->pdf->Cell(40, 6, $bed['bedrooms'] . ' BR', 1, 0, 'L');
                $this->pdf->Cell(40, 6, number_format($bed['sales_count'] ?? 0), 1, 0, 'C');
                $this->pdf->Cell(50, 6, '$' . number_format($bed['avg_price'] ?? 0), 1, 0, 'R');
                $this->pdf->Cell(40, 6, number_format($bed['avg_dom'] ?? 0) . ' days', 1, 1, 'C');
            }
        }
    }

    /**
     * Add disclaimer
     */
    private function add_disclaimer() {
        $this->pdf->AddPage();
        $this->add_section_header('Disclaimer');

        $disclaimer = "This market analysis report is provided for informational purposes only and should not be " .
            "considered as an appraisal or a guarantee of property values. The data contained in this report is " .
            "derived from MLS and public records and is believed to be accurate but is not guaranteed.\n\n" .
            "Market conditions change rapidly and past performance is not indicative of future results. " .
            "This report should not be used as the sole basis for any real estate, lending, or investment decisions.\n\n" .
            "For specific property valuations or investment advice, please consult with a licensed appraiser, " .
            "real estate professional, or financial advisor.\n\n" .
            "Generated by MLS Listings Display on " . date('F j, Y') . ".";

        $this->pdf->SetFont('helvetica', '', 9);
        $this->pdf->SetTextColorArray(array(100, 100, 100));
        $this->pdf->MultiCell(0, 5, $disclaimer, 0, 'L');
    }

    /**
     * Add section header
     *
     * @param string $title
     */
    private function add_section_header($title) {
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->SetTextColorArray($this->primary_color);
        $this->pdf->Cell(0, 12, $title, 0, 1, 'L');
        $this->pdf->SetDrawColorArray($this->primary_color);
        $this->pdf->Line(15, $this->pdf->GetY(), 195, $this->pdf->GetY());
        $this->pdf->Ln(5);
        $this->pdf->SetTextColorArray($this->text_color);
    }

    /**
     * Render key-value table
     *
     * @param array $data
     */
    private function render_key_value_table($data) {
        $this->pdf->SetFont('helvetica', '', 10);
        foreach ($data as $row) {
            $this->pdf->Cell(80, 6, $row[0] . ':', 0, 0, 'L');
            $this->pdf->SetFont('helvetica', 'B', 10);
            $this->pdf->Cell(100, 6, $row[1], 0, 1, 'L');
            $this->pdf->SetFont('helvetica', '', 10);
        }
    }

    /**
     * Save PDF to file
     *
     * @return array{file: string, url: string, filename: string} File info
     */
    private function save_pdf() {
        $upload_dir = wp_upload_dir();
        $report_dir = $upload_dir['basedir'] . '/mld-reports';

        if (!file_exists($report_dir)) {
            wp_mkdir_p($report_dir);
        }

        $filename = 'market-report-' . sanitize_file_name($this->data['city']) . '-' . date('Y-m-d-His') . '.pdf';
        $filepath = $report_dir . '/' . $filename;

        $this->pdf->Output($filepath, 'F');

        return array(
            'file' => $filepath,
            'url' => $upload_dir['baseurl'] . '/mld-reports/' . $filename,
            'filename' => $filename
        );
    }

    /**
     * Generate HTML report (fallback when TCPDF is not available)
     *
     * @return array HTML content and metadata
     */
    private function generate_html_report() {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html($this->data['options']['report_title']); ?> - <?php echo esc_html($this->data['city']); ?>, <?php echo esc_html($this->data['state']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
                h1 { color: #2c5aa0; border-bottom: 2px solid #2c5aa0; padding-bottom: 10px; }
                h2 { color: #1e4278; margin-top: 30px; }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
                th { background: #f5f5f5; font-weight: bold; }
                .metric-value { font-weight: bold; color: #2c5aa0; }
                .positive { color: #28a745; }
                .negative { color: #dc3545; }
                .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0; }
                .summary-card { background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; }
                .summary-card .label { font-size: 12px; color: #666; }
                .summary-card .value { font-size: 24px; font-weight: bold; color: #2c5aa0; }
                @media print {
                    body { max-width: none; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <h1><?php echo esc_html($this->data['options']['report_title']); ?></h1>
            <h2><?php echo esc_html($this->data['city']); ?>, <?php echo esc_html($this->data['state']); ?></h2>
            <p>Generated: <?php echo esc_html($this->data['options']['report_date']); ?></p>

            <?php if (!isset($this->data['market_summary']['error'])): ?>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">Total Sales</div>
                    <div class="value"><?php echo number_format($this->data['market_summary']['total_sales']); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Avg Sale Price</div>
                    <div class="value">$<?php echo number_format($this->data['market_summary']['avg_close_price']); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Avg DOM</div>
                    <div class="value"><?php echo number_format($this->data['market_summary']['avg_dom']); ?> days</div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($this->data['monthly_trends'])): ?>
            <h2>Monthly Price Trends</h2>
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Sales</th>
                        <th>Avg Price</th>
                        <th>$/SqFt</th>
                        <th>DOM</th>
                        <th>SP/LP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($this->data['monthly_trends'], -6) as $month): ?>
                    <tr>
                        <td><?php echo esc_html($month['month_name']); ?></td>
                        <td><?php echo number_format($month['sales_count']); ?></td>
                        <td>$<?php echo number_format($month['avg_close_price']); ?></td>
                        <td>$<?php echo number_format($month['avg_price_per_sqft']); ?></td>
                        <td><?php echo number_format($month['avg_dom'], 0); ?></td>
                        <td><?php echo number_format($month['avg_sp_lp_ratio'], 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (!empty($this->data['price_by_bedrooms'])): ?>
            <h2>Price by Bedrooms</h2>
            <table>
                <thead>
                    <tr>
                        <th>Bedrooms</th>
                        <th>Sales</th>
                        <th>Avg Price</th>
                        <th>Avg DOM</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->data['price_by_bedrooms'] as $bed): ?>
                    <tr>
                        <td><?php echo esc_html($bed['bedrooms']); ?> BR</td>
                        <td><?php echo number_format($bed['sales_count'] ?? 0); ?></td>
                        <td>$<?php echo number_format($bed['avg_price'] ?? 0); ?></td>
                        <td><?php echo number_format($bed['avg_dom'] ?? 0); ?> days</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h2>Disclaimer</h2>
            <p style="font-size: 11px; color: #666;">
                This market analysis report is provided for informational purposes only and should not be
                considered as an appraisal or a guarantee of property values. Market conditions change rapidly
                and past performance is not indicative of future results.
            </p>

            <div class="no-print" style="margin-top: 30px; text-align: center;">
                <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
                    Print Report
                </button>
            </div>
        </body>
        </html>
        <?php
        $html = ob_get_clean();

        return array(
            'format' => 'html',
            'content' => $html,
            'city' => $this->data['city'],
            'state' => $this->data['state']
        );
    }
}
