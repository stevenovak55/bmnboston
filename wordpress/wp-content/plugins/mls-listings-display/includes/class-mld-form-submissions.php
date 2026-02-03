<?php
/**
 * MLS Listings Display Form Submissions Handler
 * 
 * Handles database operations for contact form submissions
 * 
 * @package MLS_Listings_Display
 * @since 3.1
 */

class MLD_Form_Submissions {
    
    /**
     * Table name for form submissions
     */
    private static $table_name = 'mld_form_submissions';
    
    /**
     * Get full table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }
    
    /**
     * Create the form submissions table
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_type varchar(50) NOT NULL,
            property_mls varchar(50) DEFAULT NULL,
            property_address text DEFAULT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) DEFAULT NULL,
            message text DEFAULT NULL,
            tour_type varchar(50) DEFAULT NULL,
            preferred_date date DEFAULT NULL,
            preferred_time varchar(50) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'new',
            PRIMARY KEY (id),
            KEY form_type (form_type),
            KEY property_mls (property_mls),
            KEY created_at (created_at),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Drop the table on uninstall
     */
    public static function drop_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }
    
    /**
     * Insert a new form submission
     */
    public static function insert_submission($data) {
        global $wpdb;
        
        $defaults = [
            'form_type' => 'contact',
            'property_mls' => null,
            'property_address' => null,
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => null,
            'message' => null,
            'tour_type' => null,
            'preferred_date' => null,
            'preferred_time' => null,
            'ip_address' => self::get_user_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'status' => 'new'
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        $result = $wpdb->insert(
            self::get_table_name(),
            $data,
            [
                '%s', // form_type
                '%s', // property_mls
                '%s', // property_address
                '%s', // first_name
                '%s', // last_name
                '%s', // email
                '%s', // phone
                '%s', // message
                '%s', // tour_type
                '%s', // preferred_date
                '%s', // preferred_time
                '%s', // ip_address
                '%s', // user_agent
                '%s'  // status
            ]
        );
        
        if ($result === false) {
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get form submissions with pagination and filtering
     */
    public static function get_submissions($args = []) {
        global $wpdb;
        
        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'form_type' => '',
            'status' => '',
            'search' => '',
            'date_from' => '',
            'date_to' => ''
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $table_name = self::get_table_name();
        $where_clauses = [];
        $where_values = [];
        
        // Build WHERE clauses
        if (!empty($args['form_type'])) {
            $where_clauses[] = 'form_type = %s';
            $where_values[] = $args['form_type'];
        }
        
        if (!empty($args['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[] = $args['status'];
        }
        
        if (!empty($args['search'])) {
            $where_clauses[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR property_address LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }
        
        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }
        
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM $table_name $where_sql";
        if (!empty($where_values)) {
            $count_sql = $wpdb->prepare($count_sql, $where_values);
        }
        $total_items = $wpdb->get_var($count_sql);
        
        // Calculate pagination
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // Get items
        $orderby = in_array($args['orderby'], ['created_at', 'form_type', 'status']) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        $sql = "SELECT * FROM $table_name $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, [$args['per_page'], $offset]);
        
        $items = $wpdb->get_results($wpdb->prepare($sql, $query_values), ARRAY_A);
        
        return [
            'items' => $items,
            'total' => $total_items,
            'pages' => ceil($total_items / $args['per_page']),
            'page' => $args['page'],
            'per_page' => $args['per_page']
        ];
    }
    
    /**
     * Get a single submission by ID
     */
    public static function get_submission($id) {
        global $wpdb;
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
    }
    
    /**
     * Update submission status
     */
    public static function update_status($id, $status) {
        global $wpdb;
        
        return $wpdb->update(
            self::get_table_name(),
            ['status' => $status],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }
    
    /**
     * Delete a submission
     */
    public static function delete_submission($id) {
        global $wpdb;
        
        return $wpdb->delete(
            self::get_table_name(),
            ['id' => $id],
            ['%d']
        );
    }
    
    /**
     * Delete multiple submissions
     */
    public static function delete_submissions($ids) {
        global $wpdb;
        
        if (empty($ids) || !is_array($ids)) {
            return false;
        }
        
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . self::get_table_name() . " WHERE id IN ($placeholders)",
                $ids
            )
        );
    }
    
    /**
     * Get user IP address
     */
    private static function get_user_ip() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP, 
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get submission statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table_name = self::get_table_name();
        
        $stats = [
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
            'new' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'new'"),
            'contacted' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'contacted'"),
            'converted' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'converted'"),
            'today' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE DATE(created_at) = %s",
                current_time('Y-m-d')
            )),
            'this_week' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE created_at >= %s",
                date('Y-m-d', strtotime('-7 days'))
            ))
        ];
        
        return $stats;
    }
}