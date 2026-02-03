<?php
/**
 * MLD Market Narrative Generator
 *
 * Generates intelligent, human-readable market commentary
 * from forecast data and market trends
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Market_Narrative {

    /**
     * Generate comprehensive market narrative
     *
     * @param array $forecast_data Forecast data from MLD_Market_Forecasting
     * @param array $market_context Market context data
     * @param string $city City name
     * @param string $state State abbreviation
     * @return array Narrative sections
     */
    public function generate_narrative($forecast_data, $market_context = array(), $city = '', $state = '') {
        $narrative = array();

        // Market overview section
        $narrative['overview'] = $this->generate_overview($forecast_data, $market_context, $city, $state);

        // Price trend analysis
        $narrative['price_trends'] = $this->generate_price_trend_narrative($forecast_data);

        // Forecast summary
        $narrative['forecast'] = $this->generate_forecast_narrative($forecast_data);

        // Investment outlook
        $narrative['investment'] = $this->generate_investment_narrative($forecast_data);

        // Risk factors
        $narrative['risks'] = $this->generate_risk_narrative($forecast_data);

        // Recommendations
        $narrative['recommendations'] = $this->generate_recommendations($forecast_data, $market_context);

        return $narrative;
    }

    /**
     * Generate market overview narrative
     *
     * @param array $forecast_data Forecast data
     * @param array $market_context Market context
     * @param string $city City name
     * @param string $state State abbreviation
     * @return string Overview narrative
     */
    private function generate_overview($forecast_data, $market_context, $city, $state) {
        $location = !empty($city) ? $city . ($state ? ', ' . $state : '') : 'this market';

        if (!$forecast_data['price_forecast']['success']) {
            return "Limited historical data is available for {$location}, which may affect forecast accuracy.";
        }

        $trend = $forecast_data['price_forecast']['trend'];
        $momentum = $forecast_data['price_forecast']['momentum'];
        $confidence = $forecast_data['price_forecast']['confidence'];

        $parts = array();

        // Location intro
        $parts[] = "The real estate market in {$location}";

        // Trend description
        if ($trend['direction'] === 'up') {
            $parts[] = "is currently experiencing price appreciation";
        } else if ($trend['direction'] === 'down') {
            $parts[] = "is showing declining prices";
        } else {
            $parts[] = "has remained relatively stable";
        }

        // Annual change rate
        if (abs($trend['annual_change_pct']) > 1) {
            $parts[] = "at approximately " . $this->format_percentage($trend['annual_change_pct']) . " annually";
        }

        $overview = implode(' ', $parts) . '.';

        // Add momentum insight
        if ($momentum['status'] === 'accelerating') {
            $overview .= " Recent data suggests this appreciation is accelerating.";
        } else if ($momentum['status'] === 'declining') {
            $overview .= " However, recent trends indicate this pace is slowing.";
        } else if ($momentum['status'] === 'strengthening') {
            $overview .= " Market momentum is strengthening.";
        } else if ($momentum['status'] === 'weakening') {
            $overview .= " Market momentum appears to be weakening.";
        }

        // Add confidence qualifier
        if ($confidence['level'] === 'low') {
            $overview .= " Note that limited historical data reduces the reliability of these projections.";
        }

        return $overview;
    }

    /**
     * Generate price trend narrative
     *
     * @param array $forecast_data Forecast data
     * @return string Price trend narrative
     */
    private function generate_price_trend_narrative($forecast_data) {
        if (!$forecast_data['price_forecast']['success']) {
            return "Insufficient data to analyze price trends.";
        }

        $appreciation = $forecast_data['price_forecast']['appreciation'];
        $current_price = $forecast_data['price_forecast']['current_price'];

        $narrative = array();

        // 12-month appreciation
        if (isset($appreciation['12_month']) && abs($appreciation['12_month']) > 0.5) {
            $change = $appreciation['12_month'];
            if ($change > 0) {
                $narrative[] = "Over the past year, average home prices have increased by " .
                    $this->format_percentage($change) . ", indicating strong market demand.";
            } else {
                $narrative[] = "Over the past year, average home prices have decreased by " .
                    $this->format_percentage(abs($change)) . ", suggesting a buyers' market.";
            }
        } else {
            $narrative[] = "Over the past year, home prices have remained relatively stable.";
        }

        // 6-month trend
        if (isset($appreciation['6_month']) && isset($appreciation['12_month'])) {
            $short_term = $appreciation['6_month'];
            $long_term = $appreciation['12_month'];

            if (abs($short_term) > abs($long_term) * 0.6) { // Accelerating
                if ($short_term > 0) {
                    $narrative[] = "The 6-month trend shows accelerating appreciation at " .
                        $this->format_percentage($short_term * 2) . " annualized.";
                } else {
                    $narrative[] = "The 6-month trend shows accelerating price declines at " .
                        $this->format_percentage(abs($short_term * 2)) . " annualized.";
                }
            }
        }

        // Current price context
        if ($current_price > 0) {
            $narrative[] = "The current average sale price is " . $this->format_currency($current_price) . ".";
        }

        return implode(' ', $narrative);
    }

    /**
     * Generate forecast narrative
     *
     * @param array $forecast_data Forecast data
     * @return string Forecast narrative
     */
    private function generate_forecast_narrative($forecast_data) {
        if (!$forecast_data['price_forecast']['success']) {
            return "Price forecasting is not available due to insufficient historical data.";
        }

        $forecast = $forecast_data['price_forecast']['forecast'];
        $confidence = $forecast_data['price_forecast']['confidence'];

        if (empty($forecast)) {
            return "No forecast data available.";
        }

        $narrative = array();

        // Opening with confidence qualifier
        if ($confidence['level'] === 'high') {
            $narrative[] = "Based on strong historical data consistency,";
        } else if ($confidence['level'] === 'medium') {
            $narrative[] = "Based on available market data,";
        } else {
            $narrative[] = "With limited data confidence,";
        }

        // Find 12-month forecast (or longest available)
        $year_forecast = null;
        foreach ($forecast as $f) {
            if ($f['months_ahead'] == 12) {
                $year_forecast = $f;
                break;
            }
        }

        if (!$year_forecast && !empty($forecast)) {
            $year_forecast = end($forecast);
        }

        if ($year_forecast) {
            if ($year_forecast['change_pct'] > 2) {
                $narrative[] = "prices are projected to increase by approximately " .
                    $this->format_percentage($year_forecast['change_pct']) .
                    " over the next " . $year_forecast['months_ahead'] . " months,";
                $narrative[] = "reaching an estimated " . $this->format_currency($year_forecast['predicted_price']) . ".";
            } else if ($year_forecast['change_pct'] < -2) {
                $narrative[] = "prices are projected to decrease by approximately " .
                    $this->format_percentage(abs($year_forecast['change_pct'])) .
                    " over the next " . $year_forecast['months_ahead'] . " months,";
                $narrative[] = "reaching an estimated " . $this->format_currency($year_forecast['predicted_price']) . ".";
            } else {
                $narrative[] = "prices are expected to remain relatively stable over the next " .
                    $year_forecast['months_ahead'] . " months,";
                $narrative[] = "with minimal change anticipated.";
            }
        }

        // Add confidence interval
        if ($year_forecast && isset($year_forecast['low_estimate']) && isset($year_forecast['high_estimate'])) {
            $range_low = $this->format_currency($year_forecast['low_estimate']);
            $range_high = $this->format_currency($year_forecast['high_estimate']);
            $narrative[] = "The 95% confidence range is between {$range_low} and {$range_high}.";
        }

        return implode(' ', $narrative);
    }

    /**
     * Generate investment narrative
     *
     * @param array $forecast_data Forecast data
     * @return string Investment narrative
     */
    private function generate_investment_narrative($forecast_data) {
        if (empty($forecast_data['investment_analysis']) || !$forecast_data['investment_analysis']['success']) {
            return "Investment analysis requires a subject property value.";
        }

        $investment = $forecast_data['investment_analysis'];
        $projected = $investment['projected_values'];
        $annual_rate = $investment['annual_appreciation_rate'];

        $narrative = array();

        // Opening statement
        if ($annual_rate > 5) {
            $narrative[] = "This market presents strong investment potential with";
        } else if ($annual_rate > 2) {
            $narrative[] = "This market offers moderate investment opportunity with";
        } else if ($annual_rate > 0) {
            $narrative[] = "This market provides modest appreciation potential with";
        } else if ($annual_rate > -2) {
            $narrative[] = "This market shows minimal price movement with";
        } else {
            $narrative[] = "This market presents investment challenges with";
        }

        $narrative[] = "an estimated annual appreciation rate of " .
            $this->format_percentage($annual_rate) . ".";

        // 5-year projection
        if (isset($projected['5_year'])) {
            $five_year = $projected['5_year'];
            $current = $investment['current_value'];

            $narrative[] = "Over a 5-year period, a property valued at " .
                $this->format_currency($current) . " today is projected to reach " .
                $this->format_currency($five_year['value']) . ",";
            $narrative[] = "representing a potential gain of " .
                $this->format_currency($five_year['appreciation']) .
                " (" . $this->format_percentage($five_year['appreciation_pct']) . ").";
        }

        // Risk context
        $risk = $investment['risk_assessment'];
        if ($risk['level'] === 'low') {
            $narrative[] = "Market conditions suggest relatively low investment risk.";
        } else if ($risk['level'] === 'medium') {
            $narrative[] = "Market volatility is within normal ranges for real estate.";
        } else if ($risk['level'] === 'elevated') {
            $narrative[] = "Current market conditions suggest elevated investment risk.";
        } else {
            $narrative[] = "Significant market volatility indicates higher investment risk.";
        }

        return implode(' ', $narrative);
    }

    /**
     * Generate risk narrative
     *
     * @param array $forecast_data Forecast data
     * @return string Risk narrative
     */
    private function generate_risk_narrative($forecast_data) {
        if (empty($forecast_data['investment_analysis']) || !$forecast_data['investment_analysis']['success']) {
            return "Risk assessment requires complete forecast data.";
        }

        $risk = $forecast_data['investment_analysis']['risk_assessment'];
        $confidence = $forecast_data['price_forecast']['confidence'];
        $momentum = $forecast_data['price_forecast']['momentum'];

        $narrative = array();

        // Primary risk level
        $narrative[] = "The overall investment risk level for this market is rated as <strong>" . ucfirst($risk['level']) . "</strong>.";

        // Risk factors
        $factors = array();

        if ($risk['factors']['volatility'] > 0.15) {
            $factors[] = "higher than average price volatility";
        }

        if ($risk['factors']['trend'] === 'down') {
            $factors[] = "declining price trends";
        }

        if ($momentum['status'] === 'declining') {
            $factors[] = "weakening market momentum";
        }

        if ($confidence['level'] === 'low') {
            $factors[] = "limited historical data reducing forecast confidence";
        }

        if (!empty($factors)) {
            $narrative[] = "Key risk factors include " . $this->format_list($factors) . ".";
        } else {
            $narrative[] = "The market shows stable conditions with no significant risk factors identified.";
        }

        // Risk description
        if (!empty($risk['description'])) {
            $narrative[] = $risk['description'];
        }

        return implode(' ', $narrative);
    }

    /**
     * Generate recommendations
     *
     * @param array $forecast_data Forecast data
     * @param array $market_context Market context
     * @return string Recommendations narrative
     */
    private function generate_recommendations($forecast_data, $market_context) {
        if (!$forecast_data['price_forecast']['success']) {
            return "Recommendations require sufficient market data.";
        }

        $trend = $forecast_data['price_forecast']['trend'];
        $momentum = $forecast_data['price_forecast']['momentum'];

        $recommendations = array();

        // For buyers
        if ($trend['direction'] === 'up' && $momentum['status'] === 'accelerating') {
            $recommendations[] = "<strong>For Buyers:</strong> Consider acting soon as prices are rising and acceleration suggests increased competition ahead.";
        } else if ($trend['direction'] === 'down' || $momentum['status'] === 'weakening') {
            $recommendations[] = "<strong>For Buyers:</strong> Current conditions may favor negotiation and patient property selection.";
        } else {
            $recommendations[] = "<strong>For Buyers:</strong> Stable market conditions allow for measured decision-making without time pressure.";
        }

        // For sellers
        if ($trend['direction'] === 'up' && $momentum['status'] !== 'weakening') {
            $recommendations[] = "<strong>For Sellers:</strong> Strong market conditions support achieving optimal pricing.";
        } else if ($trend['direction'] === 'down' || $momentum['status'] === 'declining') {
            $recommendations[] = "<strong>For Sellers:</strong> Competitive pricing and professional presentation are essential in current conditions.";
        } else {
            $recommendations[] = "<strong>For Sellers:</strong> Balanced market requires strategic pricing aligned with recent comparable sales.";
        }

        // General advice
        $recommendations[] = "<strong>General Advice:</strong> All real estate decisions should consider personal circumstances, financing options, and long-term goals in addition to market conditions.";

        return implode(' ', $recommendations);
    }

    /**
     * Format currency value
     *
     * @param float $value Value to format
     * @return string Formatted currency
     */
    private function format_currency($value) {
        if ($value >= 1000000) {
            return '$' . number_format($value / 1000000, 2) . 'M';
        } else if ($value >= 1000) {
            return '$' . number_format($value / 1000, 0) . 'k';
        } else {
            return '$' . number_format($value, 0);
        }
    }

    /**
     * Format percentage value
     *
     * @param float $value Percentage value
     * @return string Formatted percentage
     */
    private function format_percentage($value) {
        $formatted = number_format(abs($value), 1) . '%';
        return $value >= 0 ? '+' . $formatted : '-' . $formatted;
    }

    /**
     * Format list with commas and 'and'
     *
     * @param array $items List items
     * @return string Formatted list
     */
    private function format_list($items) {
        if (count($items) === 0) {
            return '';
        } else if (count($items) === 1) {
            return $items[0];
        } else if (count($items) === 2) {
            return $items[0] . ' and ' . $items[1];
        } else {
            $last = array_pop($items);
            return implode(', ', $items) . ', and ' . $last;
        }
    }

    /**
     * Generate executive summary
     *
     * @param array $forecast_data Forecast data
     * @param array $market_context Market context
     * @param string $city City name
     * @param string $state State abbreviation
     * @return string Executive summary
     */
    public function generate_executive_summary($forecast_data, $market_context = array(), $city = '', $state = '') {
        $narrative = $this->generate_narrative($forecast_data, $market_context, $city, $state);

        $summary = array();
        $summary[] = $narrative['overview'];
        $summary[] = $narrative['forecast'];

        if (!empty($forecast_data['investment_analysis']['success'])) {
            $investment = $forecast_data['investment_analysis'];
            $summary[] = "Investment outlook: " . $this->format_percentage($investment['annual_appreciation_rate']) .
                " annual appreciation with " . strtolower($investment['risk_assessment']['level']) . " risk.";
        }

        return implode(' ', $summary);
    }
}
