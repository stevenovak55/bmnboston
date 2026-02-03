<?php
/**
 * Conversation Context Manager
 *
 * Central manager for all conversation context including:
 * - Collected user info (name, phone, email)
 * - Active search criteria (city, price range, bedrooms, property type)
 * - Recently shown properties (for reference resolution)
 * - Active property data (for detailed Q&A)
 * - Returning visitor recognition
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Conversation_Context {

    /**
     * Conversation ID
     *
     * @var int
     */
    private $conversation_id;

    /**
     * Collected user info
     *
     * @var array
     */
    private $collected_info = array();

    /**
     * Active search criteria
     *
     * @var array
     */
    private $search_criteria = array();

    /**
     * Recently shown properties
     *
     * @var array
     */
    private $shown_properties = array();

    /**
     * Active property data for detailed Q&A
     *
     * @var array|null
     */
    private $active_property = null;

    /**
     * Active property ID
     *
     * @var string|null
     */
    private $active_property_id = null;

    /**
     * Whether context has been modified
     *
     * @var bool
     */
    private $is_dirty = false;

    /**
     * Constructor
     *
     * @param int|null $conversation_id Conversation ID
     */
    public function __construct($conversation_id = null) {
        $this->conversation_id = $conversation_id;
        if ($conversation_id) {
            $this->load($conversation_id);
        }
    }

    /**
     * Load context from database
     *
     * @param int $conversation_id Conversation ID
     * @return bool Success
     */
    public function load($conversation_id) {
        global $wpdb;

        $this->conversation_id = $conversation_id;
        $table = $wpdb->prefix . 'mld_chat_conversations';

        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT collected_info, search_context, shown_properties, active_property_id,
                    user_name, user_email, user_phone
             FROM {$table}
             WHERE id = %d",
            $conversation_id
        ), ARRAY_A);

        if (!$conversation) {
            return false;
        }

        // Load collected info
        $this->collected_info = !empty($conversation['collected_info'])
            ? json_decode($conversation['collected_info'], true) ?: array()
            : array();

        // Also populate from direct columns if not in JSON
        if (empty($this->collected_info['name']) && !empty($conversation['user_name'])) {
            $this->collected_info['name'] = $conversation['user_name'];
        }
        if (empty($this->collected_info['email']) && !empty($conversation['user_email'])) {
            $this->collected_info['email'] = $conversation['user_email'];
        }
        if (empty($this->collected_info['phone']) && !empty($conversation['user_phone'])) {
            $this->collected_info['phone'] = $conversation['user_phone'];
        }

        // Load search context
        $this->search_criteria = !empty($conversation['search_context'])
            ? json_decode($conversation['search_context'], true) ?: array()
            : array();

        // Load shown properties
        $this->shown_properties = !empty($conversation['shown_properties'])
            ? json_decode($conversation['shown_properties'], true) ?: array()
            : array();

        // Load active property ID (data is loaded on demand)
        $this->active_property_id = $conversation['active_property_id'];

        $this->is_dirty = false;
        return true;
    }

    /**
     * Save context to database
     *
     * @return bool Success
     */
    public function save() {
        if (!$this->conversation_id || !$this->is_dirty) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mld_chat_conversations';

        $data = array(
            'collected_info' => json_encode($this->collected_info),
            'search_context' => json_encode($this->search_criteria),
            'shown_properties' => json_encode($this->shown_properties),
            'active_property_id' => $this->active_property_id,
            'updated_at' => current_time('mysql'),
        );

        // Also update direct columns for user info
        if (!empty($this->collected_info['name'])) {
            $data['user_name'] = $this->collected_info['name'];
        }
        if (!empty($this->collected_info['email'])) {
            $data['user_email'] = $this->collected_info['email'];
        }
        if (!empty($this->collected_info['phone'])) {
            $data['user_phone'] = $this->collected_info['phone'];
        }

        $result = $wpdb->update(
            $table,
            $data,
            array('id' => $this->conversation_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            $this->is_dirty = false;
        }

        return $result !== false;
    }

    // ============================================
    // COLLECTED USER INFO
    // ============================================

    /**
     * Get collected user info
     *
     * @return array Collected info (name, phone, email, etc.)
     */
    public function get_collected_info() {
        return $this->collected_info;
    }

    /**
     * Set collected user info
     *
     * @param array $info User info array
     */
    public function set_collected_info($info) {
        $this->collected_info = array_merge($this->collected_info, $info);
        $this->is_dirty = true;
    }

    /**
     * Get specific collected field
     *
     * @param string $field Field name
     * @return mixed|null Field value or null
     */
    public function get_collected_field($field) {
        return isset($this->collected_info[$field]) ? $this->collected_info[$field] : null;
    }

    /**
     * Set specific collected field
     *
     * @param string $field Field name
     * @param mixed $value Field value
     */
    public function set_collected_field($field, $value) {
        $this->collected_info[$field] = $value;
        $this->is_dirty = true;
    }

    /**
     * Check if user info is complete
     *
     * @return bool Has minimum info (name + phone or email)
     */
    public function has_complete_contact_info() {
        return !empty($this->collected_info['name'])
            && (!empty($this->collected_info['phone']) || !empty($this->collected_info['email']));
    }

    // ============================================
    // SEARCH CRITERIA
    // ============================================

    /**
     * Get active search criteria
     *
     * @return array Search criteria
     */
    public function get_search_criteria() {
        return $this->search_criteria;
    }

    /**
     * Update search criteria (merges with existing)
     *
     * @param array $new_criteria New criteria to merge
     */
    public function update_search_criteria($new_criteria) {
        // Filter out empty values from new criteria
        $new_criteria = array_filter($new_criteria, function($v) {
            return $v !== null && $v !== '';
        });

        // Merge with existing (new values override)
        $this->search_criteria = array_merge($this->search_criteria, $new_criteria);
        $this->is_dirty = true;
    }

    /**
     * Clear search criteria
     */
    public function clear_search_criteria() {
        $this->search_criteria = array();
        $this->is_dirty = true;
    }

    /**
     * Get specific search criterion
     *
     * @param string $key Criterion key
     * @return mixed|null Value or null
     */
    public function get_search_criterion($key) {
        return isset($this->search_criteria[$key]) ? $this->search_criteria[$key] : null;
    }

    // ============================================
    // SHOWN PROPERTIES
    // ============================================

    /**
     * Record shown properties
     *
     * @param array $properties Properties that were shown to user
     */
    public function record_shown_properties($properties) {
        $this->shown_properties = array();
        $index = 1;

        foreach ($properties as $property) {
            $this->shown_properties[] = array(
                'index' => $index,
                'listing_id' => $property['listing_id'] ?? null,
                'address' => $property['address'] ?? null,
                'price' => $property['price'] ?? null,
                'street' => $property['street_address'] ?? null,
            );
            $index++;
        }

        $this->is_dirty = true;
    }

    /**
     * Get shown properties
     *
     * @return array Shown properties
     */
    public function get_shown_properties() {
        return $this->shown_properties;
    }

    /**
     * Resolve reference to listing ID
     *
     * @param string $reference User reference (e.g., "5", "first", "70 Phillips")
     * @return string|null Listing ID or null
     */
    public function resolve_reference($reference) {
        if (empty($this->shown_properties)) {
            return null;
        }

        $reference = strtolower(trim($reference));

        // Numeric reference: "5", "number 5", "#5"
        $reference_clean = preg_replace('/#|number\s*/i', '', $reference);
        if (is_numeric($reference_clean)) {
            $index = intval($reference_clean);
            foreach ($this->shown_properties as $prop) {
                if ($prop['index'] === $index) {
                    return $prop['listing_id'];
                }
            }
        }

        // Ordinal reference: "first", "second", "third", etc.
        $ordinals = array(
            'first' => 1, '1st' => 1,
            'second' => 2, '2nd' => 2,
            'third' => 3, '3rd' => 3,
            'fourth' => 4, '4th' => 4,
            'fifth' => 5, '5th' => 5,
            'last' => count($this->shown_properties),
        );

        if (isset($ordinals[$reference])) {
            $index = $ordinals[$reference];
            foreach ($this->shown_properties as $prop) {
                if ($prop['index'] === $index) {
                    return $prop['listing_id'];
                }
            }
        }

        // Address match: "70 Phillips", "123 Main St"
        foreach ($this->shown_properties as $prop) {
            $address = strtolower($prop['address'] ?? '');
            $street = strtolower($prop['street'] ?? '');

            if (!empty($address) && strpos($address, $reference) !== false) {
                return $prop['listing_id'];
            }
            if (!empty($street) && strpos($street, $reference) !== false) {
                return $prop['listing_id'];
            }
        }

        // Price-based: "cheapest", "most expensive"
        if ($reference === 'cheapest' || $reference === 'lowest price') {
            $cheapest = null;
            $min_price = PHP_FLOAT_MAX;
            foreach ($this->shown_properties as $prop) {
                $price = floatval(str_replace(array('$', ','), '', $prop['price'] ?? 0));
                if ($price > 0 && $price < $min_price) {
                    $min_price = $price;
                    $cheapest = $prop['listing_id'];
                }
            }
            return $cheapest;
        }

        if ($reference === 'most expensive' || $reference === 'highest price') {
            $expensive = null;
            $max_price = 0;
            foreach ($this->shown_properties as $prop) {
                $price = floatval(str_replace(array('$', ','), '', $prop['price'] ?? 0));
                if ($price > $max_price) {
                    $max_price = $price;
                    $expensive = $prop['listing_id'];
                }
            }
            return $expensive;
        }

        return null;
    }

    // ============================================
    // ACTIVE PROPERTY
    // ============================================

    /**
     * Set active property for detailed Q&A
     *
     * @param string $listing_id Listing ID
     * @param array $data Full property data
     */
    public function set_active_property($listing_id, $data) {
        $this->active_property_id = $listing_id;
        $this->active_property = $data;
        $this->is_dirty = true;
    }

    /**
     * Get active property data
     *
     * @return array|null Property data or null
     */
    public function get_active_property() {
        return $this->active_property;
    }

    /**
     * Get active property ID
     *
     * @return string|null Listing ID or null
     */
    public function get_active_property_id() {
        return $this->active_property_id;
    }

    /**
     * Clear active property
     */
    public function clear_active_property() {
        $this->active_property_id = null;
        $this->active_property = null;
        $this->is_dirty = true;
    }

    // ============================================
    // RETURNING VISITOR
    // ============================================

    /**
     * Check if this is a returning visitor
     *
     * @param string|null $email User email
     * @param string|null $phone User phone
     * @return string|null User name if returning, null otherwise
     */
    public function check_returning_visitor($email = null, $phone = null) {
        if (empty($email) && empty($phone)) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mld_chat_conversations';

        // Look for previous conversations with same email or phone
        $where_parts = array();
        $params = array();

        if (!empty($email)) {
            $where_parts[] = "user_email = %s";
            $params[] = $email;
        }
        if (!empty($phone)) {
            $where_parts[] = "user_phone = %s";
            $params[] = $phone;
        }

        $where = implode(' OR ', $where_parts);

        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT user_name, user_email, user_phone, collected_info
             FROM {$table}
             WHERE ({$where})
             AND user_name IS NOT NULL
             AND user_name != ''
             AND id != %d
             ORDER BY last_message_at DESC
             LIMIT 1",
            array_merge($params, array($this->conversation_id ?: 0))
        ), ARRAY_A);

        if ($previous && !empty($previous['user_name'])) {
            // Pre-populate collected info from previous conversation
            $this->collected_info['name'] = $previous['user_name'];
            $this->collected_info['email'] = $previous['user_email'];
            $this->collected_info['phone'] = $previous['user_phone'];

            // Also load any additional collected info
            if (!empty($previous['collected_info'])) {
                $prev_info = json_decode($previous['collected_info'], true);
                if (is_array($prev_info)) {
                    $this->collected_info = array_merge($prev_info, $this->collected_info);
                }
            }

            $this->is_dirty = true;
            return $previous['user_name'];
        }

        return null;
    }

    // ============================================
    // AI CONTEXT BUILDING
    // ============================================

    /**
     * Build context string for AI system prompt
     *
     * @return string Formatted context for AI
     */
    public function build_ai_context_string() {
        $parts = array();

        // User info context
        if (!empty($this->collected_info)) {
            $info = $this->collected_info;
            $user_parts = array();

            if (!empty($info['name'])) {
                $user_parts[] = "Name: " . $info['name'];
            }
            if (!empty($info['phone'])) {
                $user_parts[] = "Phone: " . $info['phone'];
            }
            if (!empty($info['email'])) {
                $user_parts[] = "Email: " . $info['email'];
            }

            if (!empty($user_parts)) {
                $parts[] = "## User Contact Info (ALREADY COLLECTED - DO NOT ASK AGAIN)\n" .
                    implode("\n", $user_parts);
            }
        }

        // Search criteria context
        if (!empty($this->search_criteria)) {
            $criteria = $this->search_criteria;
            $search_parts = array();

            if (!empty($criteria['city'])) {
                $search_parts[] = "Location: " . $criteria['city'];
            }
            if (!empty($criteria['neighborhood'])) {
                $search_parts[] = "Neighborhood: " . $criteria['neighborhood'];
            }
            if (!empty($criteria['min_price']) || !empty($criteria['max_price'])) {
                $price = '$' . number_format($criteria['min_price'] ?? 0) .
                    ' - $' . number_format($criteria['max_price'] ?? 999999999);
                $search_parts[] = "Price Range: " . $price;
            }
            if (!empty($criteria['min_bedrooms'])) {
                $search_parts[] = "Bedrooms: " . $criteria['min_bedrooms'] . "+";
            }
            if (!empty($criteria['property_type'])) {
                $search_parts[] = "Type: " . $criteria['property_type'];
            }

            if (!empty($search_parts)) {
                $parts[] = "## Active Search Criteria (KEEP these when user refines search)\n" .
                    implode("\n", $search_parts);
            }
        }

        // Shown properties context
        if (!empty($this->shown_properties)) {
            $props = array();
            foreach ($this->shown_properties as $prop) {
                $props[] = "#{$prop['index']}: {$prop['address']} - {$prop['price']} (ID: {$prop['listing_id']})";
            }
            $parts[] = "## Recently Shown Properties (use to resolve references like \"number 3\")\n" .
                implode("\n", $props);
        }

        // Active property context
        if (!empty($this->active_property_id)) {
            $parts[] = "## Active Property Being Discussed\nListing ID: {$this->active_property_id}\n" .
                "Full property data is available. You can answer detailed questions about this property.";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Get conversation ID
     *
     * @return int|null
     */
    public function get_conversation_id() {
        return $this->conversation_id;
    }

    /**
     * Set conversation ID
     *
     * @param int $id
     */
    public function set_conversation_id($id) {
        $this->conversation_id = $id;
    }
}

/**
 * Get a conversation context instance
 *
 * @param int|null $conversation_id Conversation ID
 * @return MLD_Conversation_Context
 */
function mld_get_conversation_context($conversation_id = null) {
    return new MLD_Conversation_Context($conversation_id);
}
