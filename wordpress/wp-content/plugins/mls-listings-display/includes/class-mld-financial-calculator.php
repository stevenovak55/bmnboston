<?php
/**
 * Financial Calculator Utility Class
 * 
 * Provides precise financial calculations using BCMath when available
 * Falls back to standard PHP math functions if BCMath is not installed
 *
 * @package MLS_Listings_Display
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Financial Calculator class
 */
class MLD_Financial_Calculator {
    
    /**
     * Decimal precision for calculations
     */
    const PRECISION = 2;
    
    /**
     * Check if BCMath is available
     * 
     * @return bool
     */
    public static function is_bcmath_available() {
        return function_exists('bcadd') && function_exists('bcmul') && function_exists('bcdiv') && function_exists('bcsub');
    }
    
    /**
     * Add two financial amounts
     * 
     * @param string|float $a First amount
     * @param string|float $b Second amount
     * @param int $precision Decimal precision
     * @return string Result
     */
    public static function add($a, $b, $precision = self::PRECISION) {
        if (self::is_bcmath_available()) {
            return bcadd((string)$a, (string)$b, $precision);
        }
        return number_format((float)$a + (float)$b, $precision, '.', '');
    }
    
    /**
     * Subtract two financial amounts
     * 
     * @param string|float $a First amount
     * @param string|float $b Second amount
     * @param int $precision Decimal precision
     * @return string Result
     */
    public static function subtract($a, $b, $precision = self::PRECISION) {
        if (self::is_bcmath_available()) {
            return bcsub((string)$a, (string)$b, $precision);
        }
        return number_format((float)$a - (float)$b, $precision, '.', '');
    }
    
    /**
     * Multiply two financial amounts
     * 
     * @param string|float $a First amount
     * @param string|float $b Second amount
     * @param int $precision Decimal precision
     * @return string Result
     */
    public static function multiply($a, $b, $precision = self::PRECISION) {
        if (self::is_bcmath_available()) {
            return bcmul((string)$a, (string)$b, $precision);
        }
        return number_format((float)$a * (float)$b, $precision, '.', '');
    }
    
    /**
     * Divide two financial amounts
     * 
     * @param string|float $a Dividend
     * @param string|float $b Divisor
     * @param int $precision Decimal precision
     * @return string Result
     */
    public static function divide($a, $b, $precision = self::PRECISION) {
        if ((float)$b == 0) {
            return '0';
        }
        
        if (self::is_bcmath_available()) {
            return bcdiv((string)$a, (string)$b, $precision);
        }
        return number_format((float)$a / (float)$b, $precision, '.', '');
    }
    
    /**
     * Calculate percentage
     * 
     * @param string|float $value Base value
     * @param string|float $percentage Percentage (e.g., 15 for 15%)
     * @param int $precision Decimal precision
     * @return string Result
     */
    public static function percentage($value, $percentage, $precision = self::PRECISION) {
        $multiplier = self::divide($percentage, '100', 4);
        return self::multiply($value, $multiplier, $precision);
    }
    
    /**
     * Calculate price range for similar properties
     * 
     * @param string|float $price Base price
     * @param float $percentage Percentage range (e.g., 15 for ±15%)
     * @return array Array with 'min' and 'max' keys
     */
    public static function calculate_price_range($price, $percentage = 15) {
        $price_str = (string)$price;
        $percentage_str = (string)$percentage;
        
        // Calculate the percentage amount
        $variance = self::percentage($price_str, $percentage_str);
        
        // Calculate min and max
        $min = self::subtract($price_str, $variance);
        $max = self::add($price_str, $variance);
        
        // Ensure min is not negative
        if ((float)$min < 0) {
            $min = '0.00';
        }
        
        return [
            'min' => $min,
            'max' => $max
        ];
    }
    
    /**
     * Calculate monthly mortgage payment
     * 
     * @param string|float $principal Loan amount
     * @param float $annual_rate Annual interest rate (e.g., 5.5 for 5.5%)
     * @param int $years Loan term in years
     * @param string|float $down_payment Down payment amount
     * @return string Monthly payment
     */
    public static function calculate_mortgage_payment($principal, $annual_rate, $years = 30, $down_payment = 0) {
        // Calculate loan amount
        $loan_amount = self::subtract($principal, $down_payment);
        
        if ((float)$loan_amount <= 0) {
            return '0.00';
        }
        
        // Convert annual rate to monthly
        $monthly_rate = self::divide($annual_rate, '1200', 6); // Divide by 12 months * 100 to convert percentage
        
        if ((float)$monthly_rate == 0) {
            // No interest, simple division
            $months = $years * 12;
            return self::divide($loan_amount, (string)$months);
        }
        
        // Calculate number of payments
        $num_payments = $years * 12;
        
        if (self::is_bcmath_available()) {
            // Use BCMath for precise calculation
            // Formula: P * (r * (1 + r)^n) / ((1 + r)^n - 1)
            
            // Calculate (1 + r)
            $one_plus_r = self::add('1', $monthly_rate, 6);
            
            // Calculate (1 + r)^n using loop since bcpow doesn't handle decimals well
            $power = '1';
            for ($i = 0; $i < $num_payments; $i++) {
                $power = self::multiply($power, $one_plus_r, 6);
            }
            
            // Calculate numerator: r * (1 + r)^n
            $numerator = self::multiply($monthly_rate, $power, 6);
            
            // Calculate denominator: (1 + r)^n - 1
            $denominator = self::subtract($power, '1', 6);
            
            // Calculate payment
            if ((float)$denominator > 0) {
                $payment = self::multiply($loan_amount, self::divide($numerator, $denominator, 6));
            } else {
                $payment = '0.00';
            }
        } else {
            // Fallback to float calculation
            $r = (float)$monthly_rate;
            $n = $num_payments;
            $p = (float)$loan_amount;
            
            if ($r > 0) {
                $payment = $p * ($r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1);
            } else {
                $payment = $p / $n;
            }
            
            $payment = number_format($payment, 2, '.', '');
        }
        
        return $payment;
    }
    
    /**
     * Format currency for display
     * 
     * @param string|float $amount Amount to format
     * @param string $symbol Currency symbol
     * @param bool $show_cents Whether to show cents
     * @return string Formatted currency
     */
    public static function format_currency($amount, $symbol = '$', $show_cents = true) {
        $amount = (float)$amount;
        
        if ($show_cents) {
            return $symbol . number_format($amount, 2, '.', ',');
        } else {
            return $symbol . number_format($amount, 0, '.', ',');
        }
    }
    
    /**
     * Calculate property tax estimate
     * 
     * @param string|float $assessed_value Property assessed value
     * @param float $tax_rate Tax rate (e.g., 1.2 for 1.2%)
     * @return string Annual tax amount
     */
    public static function calculate_property_tax($assessed_value, $tax_rate) {
        return self::percentage($assessed_value, $tax_rate);
    }
    
    /**
     * Calculate cap rate for investment property
     * 
     * @param string|float $noi Net Operating Income
     * @param string|float $property_value Property value
     * @return string Cap rate as percentage
     */
    public static function calculate_cap_rate($noi, $property_value) {
        if ((float)$property_value <= 0) {
            return '0.00';
        }
        
        $rate = self::divide($noi, $property_value, 4);
        return self::multiply($rate, '100'); // Convert to percentage
    }
}

/**
 * Helper function to get financial calculator instance
 * 
 * @return MLD_Financial_Calculator
 */
function mld_financial() {
    static $instance = null;
    if (null === $instance) {
        $instance = new MLD_Financial_Calculator();
    }
    return $instance;
}