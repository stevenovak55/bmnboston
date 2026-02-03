<?php
/**
 * MLD Email Validator
 *
 * Validates email addresses for bot registration prevention.
 * Detects disposable email domains and gibberish patterns.
 *
 * @package MLS_Listings_Display
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Email_Validator {

    /**
     * Initialize WordPress hooks for registration protection
     */
    public static function init() {
        // Hook into WordPress default registration
        add_filter('registration_errors', array(__CLASS__, 'validate_wp_registration'), 10, 3);

        // Hook into wp_pre_insert_user_data for comprehensive coverage (catches all paths including wp_create_user)
        add_filter('wp_pre_insert_user_data', array(__CLASS__, 'validate_user_data_on_insert'), 10, 4);
    }

    /**
     * Validate email during WordPress default registration
     *
     * @param WP_Error $errors Registration errors
     * @param string $sanitized_user_login User login
     * @param string $user_email User email
     * @return WP_Error
     */
    public static function validate_wp_registration($errors, $sanitized_user_login, $user_email) {
        $validation = self::validate($user_email);

        if (!$validation['valid']) {
            self::log_blocked_attempt($user_email, $validation['reason'], $validation['code'], array(
                'ip' => self::get_client_ip(),
                'source' => 'wp_registration'
            ));
            $errors->add($validation['code'], $validation['reason']);
        }

        return $errors;
    }

    /**
     * Validate user data before insertion (catches wp_insert_user, wp_create_user)
     *
     * @param array $data User data
     * @param bool $update Whether this is an update
     * @param int|null $user_id User ID if update
     * @param array $userdata Raw user data
     * @return array
     */
    public static function validate_user_data_on_insert($data, $update, $user_id, $userdata) {
        // Only validate on new user creation, not updates
        if ($update) {
            return $data;
        }

        $email = isset($data['user_email']) ? $data['user_email'] : '';
        if (empty($email)) {
            return $data;
        }

        $validation = self::validate($email);

        if (!$validation['valid']) {
            self::log_blocked_attempt($email, $validation['reason'], $validation['code'], array(
                'ip' => self::get_client_ip(),
                'source' => 'wp_insert_user'
            ));

            // Throw an error that wp_insert_user will catch
            // We can't return WP_Error from this filter, so we use a workaround
            // by setting invalid email that will fail WordPress validation
            $data['user_email'] = '';

            // Store the error message for display
            set_transient('mld_registration_error_' . self::get_client_ip(), $validation['reason'], 60);
        }

        return $data;
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip() {
        $headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return sanitize_text_field($ip);
                }
            }
        }
        return 'unknown';
    }

    /**
     * Common disposable email domains (100+ known temporary email services)
     * These are services that provide throwaway email addresses commonly used by bots.
     *
     * @var array
     */
    private static $disposable_domains = array(
        // Most common
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', 'guerrillamail.net',
        'guerrillamail.org', 'guerrillamail.biz', 'guerrillamail.de', 'sharklasers.com',
        'grr.la', 'guerrillamailblock.com', 'pokemail.net', 'spam4.me',
        'tempmail.com', 'tempmail.net', '10minutemail.com', '10minutemail.net',
        'temp-mail.org', 'temp-mail.io', 'tempmailo.com', 'tempinbox.com',
        'fakeinbox.com', 'fakemailgenerator.com', 'throwawaymail.com',
        'getnada.com', 'nada.email', 'throwaway.email', 'trashmail.com',
        'trashmail.net', 'trashmail.org', 'dispostable.com', 'mailnesia.com',
        'maildrop.cc', 'mailcatch.com', 'mailsac.com', 'mintemail.com',
        'mytrashmail.com', 'getairmail.com', 'yopmail.com', 'yopmail.fr',
        'yopmail.net', 'cool.fr.nf', 'jetable.fr.nf', 'nospam.ze.tc',
        'nomail.xl.cx', 'mega.zik.dj', 'speed.1s.fr', 'courriel.fr.nf',
        'moncourrier.fr.nf', 'monemail.fr.nf', 'monmail.fr.nf',

        // Additional common ones
        'mailnator.com', 'mailinator2.com', 'mailinater.com', 'mailinator.net',
        'mailinator.org', 'mailinator.us', 'mailin8r.com', 'mailinator.info',
        'sogetthis.com', 'mailinater.com', 'spamgourmet.com', 'spamgourmet.net',
        'spamgourmet.org', 'devnullmail.com', 'letthemeatspam.com',
        'thisisnotmyrealemail.com', 'trash-mail.at', 'trash-mail.com',
        'trash-mail.de', 'trash-mail.me', 'wegwerfmail.de', 'wegwerfmail.net',
        'wegwerfmail.org', 'e4ward.com', 'spambox.us', 'spambog.com',
        'spambog.de', 'spambog.ru', 'kasmail.com', 'spamcero.com',
        'spaml.com', 'spaml.de', 'uggsrock.com', 'spam.la',
        'spamspot.com', 'spamthis.co.uk', 'tempail.com', 'tempemail.co.za',
        'tempemail.com', 'tempemail.net', 'tempmail.it', 'tempmailer.com',
        'tempomail.fr', 'temporaryemail.net', 'temporaryinbox.com',
        'thankyou2010.com', 'thecloudindex.com', 'tmail.ws', 'tmailinator.com',
        'emailondeck.com', 'anonymbox.com', 'fakebox.net', 'mailexpire.com',
        'tempsky.com', 'emailfake.com', 'crazymailing.com', 'emkei.cz',

        // More recent services
        'disposableemailaddresses.com', 'emailmiser.com', 'emailsensei.com',
        'emailto.de', 'emailwarden.com', 'emailx.at.hm', 'emailxfer.com',
        'emz.net', 'enterto.com', 'ephemail.net', 'ero-tube.org',
        'etranquil.com', 'etranquil.net', 'etranquil.org', 'evopo.com',
        'explodemail.com', 'eyepaste.com', 'mailforspam.com', 'mailfreeonline.com',
        'mailfree.ga', 'mailfree.gq', 'mailfree.ml', 'mailfs.com',
        'mailguard.me', 'mailhazard.com', 'mailhazard.us', 'mailhub.top',
        'mailhz.me', 'mailimate.com', 'mailinbox.co', 'mailinbox.me',

        // SEO spam bot domains (GSA Search Engine Ranker, link building bots)
        'gsasearchengineranker.com', 'seoautomationpro.com', 'verifiedlinklist.com',
        'welcometotijuana.com', 'budgetthailandtravel.com', 'travel-e-store.com',
        'domain-grow.xyz', 'blogwebsite.top', 'webtoteam.shop', 'dnsabr.com',

        // Russian spam domains
        'poochta.ru', 'belettersmail.com', 'n8ncreator.ru',

        // Spam subdomains and unusual TLDs commonly used by bots
        'mailr.click', 'fckmail.online', 'scarbour.com', 'veauly.com'
    );

    /**
     * Suspicious TLDs commonly used by spam bots
     * These are checked if the domain isn't in the explicit blocklist
     *
     * @var array
     */
    private static $suspicious_tlds = array(
        '.cfd', '.top', '.shop', '.click', '.beer', '.pro', '.today',
        '.xyz', '.online', '.gy', '.ru'
    );

    /**
     * Keyboard walk patterns that indicate gibberish
     *
     * @var array
     */
    private static $keyboard_walks = array(
        'qwerty', 'qwertz', 'asdfgh', 'zxcvbn', 'qazwsx', 'wsxedc',
        'edcrfv', 'rfvtgb', 'tgbyhn', 'yhnujm', '123456', '654321',
        'abcdef', 'fedcba', 'asdf', 'qwer', 'zxcv', 'wasd',
        '1qaz', '2wsx', '3edc', '4rfv', '5tgb', '6yhn',
        'qweasd', 'asdqwe', 'zxcasd'
    );

    /**
     * Test/placeholder patterns commonly used by bots
     *
     * @var array
     */
    private static $test_patterns = array(
        '/^test[0-9]*@/i',
        '/^user[0-9]*@/i',
        '/^admin[0-9]*@/i',
        '/^demo[0-9]*@/i',
        '/^sample[0-9]*@/i',
        '/^example[0-9]*@/i',
        '/^fake[0-9]*@/i',
        '/^spam[0-9]*@/i',
        '/^temp[0-9]*@/i',
        '/^noreply[0-9]*@/i',
        '/^nobody[0-9]*@/i',
        '/^null[0-9]*@/i',
        '/^void[0-9]*@/i',
        '/^[a-z]{1,2}[0-9]+@/i',  // a1@, ab123@
        '/^[0-9]+@/i',            // 1234@, 99999@ - numeric only
        '/^aaa+@/i',              // aaa@, aaaa@
        '/^xxx+@/i',              // xxx@, xxxx@
        '/^zzz+@/i',              // zzz@, zzzz@
    );

    /**
     * Validate an email address for bot-like patterns
     *
     * @param string $email Email to validate
     * @return array {
     *     @type bool   $valid   Whether the email passes validation
     *     @type string $reason  If invalid, the reason why
     *     @type string $code    Machine-readable error code
     * }
     */
    public static function validate($email) {
        $email = strtolower(trim($email));

        // Basic format check
        if (!is_email($email)) {
            return array(
                'valid' => false,
                'reason' => 'Invalid email format.',
                'code' => 'invalid_format'
            );
        }

        // Check for disposable email domain
        $disposable_check = self::is_disposable_email($email);
        if ($disposable_check) {
            return array(
                'valid' => false,
                'reason' => 'Please use a permanent email address, not a temporary one.',
                'code' => 'disposable_email'
            );
        }

        // Check for gibberish patterns
        $gibberish_check = self::is_gibberish_email($email);
        if ($gibberish_check !== false) {
            return array(
                'valid' => false,
                'reason' => 'Please use a valid email address.',
                'code' => 'gibberish_email',
                'pattern' => $gibberish_check
            );
        }

        return array(
            'valid' => true,
            'reason' => '',
            'code' => ''
        );
    }

    /**
     * Check if email uses a known disposable email domain
     *
     * @param string $email Email to check
     * @return bool True if disposable
     */
    public static function is_disposable_email($email) {
        $domain = self::get_domain($email);
        if (!$domain) {
            return false;
        }

        // Direct match
        if (in_array($domain, self::$disposable_domains)) {
            return true;
        }

        // Check for subdomain patterns (e.g., anything.mailinator.com)
        foreach (self::$disposable_domains as $disposable_domain) {
            if (substr($domain, -strlen($disposable_domain) - 1) === '.' . $disposable_domain) {
                return true;
            }
        }

        // Check for suspicious TLDs (commonly used by spam bots)
        foreach (self::$suspicious_tlds as $tld) {
            if (substr($domain, -strlen($tld)) === $tld) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if email appears to be gibberish
     *
     * @param string $email Email to check
     * @return string|false Pattern that matched, or false if not gibberish
     */
    public static function is_gibberish_email($email) {
        $local_part = self::get_local_part($email);
        $domain = self::get_domain($email);

        if (!$local_part || !$domain) {
            return false;
        }

        // Strip numbers for pattern checking
        $local_letters = preg_replace('/[0-9]/', '', $local_part);
        $domain_name = explode('.', $domain)[0];
        $domain_letters = preg_replace('/[0-9]/', '', $domain_name);

        // Check for keyboard walks in local part
        foreach (self::$keyboard_walks as $walk) {
            if (stripos($local_letters, $walk) !== false) {
                return 'keyboard_walk:' . $walk;
            }
        }

        // Check for keyboard walks in domain name
        foreach (self::$keyboard_walks as $walk) {
            if (stripos($domain_letters, $walk) !== false) {
                return 'keyboard_walk_domain:' . $walk;
            }
        }

        // Check for test patterns
        foreach (self::$test_patterns as $pattern) {
            if (preg_match($pattern, $email)) {
                return 'test_pattern:' . $pattern;
            }
        }

        // Check for excessive consonants (no vowels in long string)
        if (strlen($local_letters) >= 6) {
            // Get chunks of 5+ consecutive consonants
            if (preg_match('/[^aeiou]{6,}/i', $local_letters, $matches)) {
                return 'excessive_consonants:' . $matches[0];
            }
        }

        // Check for repeated characters (4+ of the same letter)
        if (preg_match('/(.)\1{3,}/', $local_letters, $matches)) {
            return 'repeated_chars:' . $matches[0];
        }

        // Check for random-looking patterns (high entropy)
        // e.g., "xkjfhw@" - looks like random letters
        if (strlen($local_letters) >= 6 && self::looks_random($local_letters)) {
            return 'random_looking';
        }

        return false;
    }

    /**
     * Check if a string looks randomly generated
     * Uses bigram frequency analysis - random strings have unusual letter combinations
     *
     * @param string $str String to check
     * @return bool True if appears random
     */
    private static function looks_random($str) {
        // Common English bigrams
        $common_bigrams = array(
            'th', 'he', 'in', 'er', 'an', 're', 'on', 'at', 'en', 'nd',
            'ti', 'es', 'or', 'te', 'of', 'ed', 'is', 'it', 'al', 'ar',
            'st', 'to', 'nt', 'ng', 'se', 'ha', 'as', 'ou', 'io', 'le',
            'me', 'ma', 'el', 'ea', 've', 'de', 'co', 'ne', 'ri', 'li'
        );

        $str = strtolower($str);
        $length = strlen($str);

        if ($length < 6) {
            return false;
        }

        // Count common bigrams
        $common_count = 0;
        for ($i = 0; $i < $length - 1; $i++) {
            $bigram = substr($str, $i, 2);
            if (in_array($bigram, $common_bigrams)) {
                $common_count++;
            }
        }

        // In normal English-ish text, ~30% of bigrams should be common
        // Random strings have much fewer common bigrams
        $bigram_ratio = $common_count / ($length - 1);

        // If less than 10% common bigrams, likely random
        return $bigram_ratio < 0.10;
    }

    /**
     * Extract domain from email
     *
     * @param string $email Email address
     * @return string|false Domain or false
     */
    private static function get_domain($email) {
        $parts = explode('@', $email);
        return isset($parts[1]) ? strtolower($parts[1]) : false;
    }

    /**
     * Extract local part (before @) from email
     *
     * @param string $email Email address
     * @return string|false Local part or false
     */
    private static function get_local_part($email) {
        $parts = explode('@', $email);
        return isset($parts[0]) ? strtolower($parts[0]) : false;
    }

    /**
     * Log a blocked registration attempt for monitoring
     *
     * @param string $email     Email that was blocked
     * @param string $reason    Reason for blocking
     * @param string $code      Error code
     * @param array  $context   Additional context (IP, timestamp, etc.)
     */
    public static function log_blocked_attempt($email, $reason, $code, $context = array()) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_data = array(
            'time' => current_time('mysql'),
            'email' => $email,
            'reason' => $reason,
            'code' => $code,
            'ip' => isset($context['ip']) ? $context['ip'] : 'unknown',
            'source' => isset($context['source']) ? $context['source'] : 'unknown',
        );

        error_log('[MLD Bot Prevention] Blocked registration: ' . json_encode($log_data));
    }
}
