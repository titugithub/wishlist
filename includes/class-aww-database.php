<?php
/**
 * Database Class for Advanced WooCommerce Wishlist
 *
 * @package Advanced_WC_Wishlist
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AWW_Database Class
 *
 * Handles all database operations for the wishlist functionality
 *
 * @since 1.0.0
 */
class AWW_Database {

    /**
     * Wishlist table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Lists table name
     *
     * @var string
     */
    private $lists_table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'aww_wishlists';
        $this->lists_table_name = $wpdb->prefix . 'aww_wishlist_lists';
    }

    /**
     * Create database tables (multiple wishlist support)
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // New table for wishlist lists
        $sql_lists = "CREATE TABLE {$this->lists_table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            session_id varchar(255) DEFAULT NULL,
            name varchar(255) NOT NULL,
            date_created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id)
        ) $charset_collate;";

        // Updated wishlist items table
        $sql_items = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wishlist_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            price_at_add decimal(20,6) DEFAULT NULL,
            date_added datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY wishlist_id (wishlist_id),
            KEY product_id (product_id),
            KEY date_added (date_added),
            UNIQUE KEY unique_wishlist_item (wishlist_id, product_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_lists );
        dbDelta( $sql_items );

        // Migration: Move old wishlist items to default list if upgrading
        $this->migrate_old_wishlist_schema();

        update_option( 'aww_db_version', AWW_VERSION );
    }

    /**
     * Create database table (alias for create_tables for backward compatibility)
     */
    public function create_table() {
        return $this->create_tables();
    }

    /**
     * Migrate old wishlist schema to new multiple wishlist schema
     */
    private function migrate_old_wishlist_schema() {
        global $wpdb;
        // Check if old columns exist
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$this->table_name}" );
        $has_user_id = false;
        $has_session_id = false;
        $has_wishlist_id = false;
        foreach ( $columns as $col ) {
            if ( $col->Field === 'user_id' ) $has_user_id = true;
            if ( $col->Field === 'session_id' ) $has_session_id = true;
            if ( $col->Field === 'wishlist_id' ) $has_wishlist_id = true;
        }
        if ( $has_user_id || $has_session_id ) {
            // For each unique user/session, create a default wishlist and move items
            $users = $wpdb->get_results( "SELECT DISTINCT user_id, session_id FROM {$this->table_name}" );
            foreach ( $users as $u ) {
                $user_id = $u->user_id;
                $session_id = $u->session_id;
                $name = __( 'My Wishlist', 'advanced-wc-wishlist' );
                $wpdb->insert( $this->lists_table_name, [
                    'user_id' => $user_id,
                    'session_id' => $session_id,
                    'name' => $name,
                    'date_created' => current_time( 'mysql' ),
                    'date_updated' => current_time( 'mysql' ),
                ] );
                $wishlist_id = $wpdb->insert_id;
                // Update items
                $where = [];
                $where_values = [];
                if ( $user_id ) {
                    $where[] = 'user_id = %d';
                    $where_values[] = $user_id;
                }
                if ( $session_id ) {
                    $where[] = 'session_id = %s';
                    $where_values[] = $session_id;
                }
                if ( ! empty( $where ) ) {
                    $where_clause = implode( ' AND ', $where );
                    $sql = "UPDATE {$this->table_name} SET wishlist_id = %d WHERE {$where_clause}";
                    $values = array_merge( array( $wishlist_id ), $where_values );
                    $wpdb->query( $wpdb->prepare( $sql, $values ) );
                }
            }
            // Remove old columns
            $wpdb->query( "ALTER TABLE {$this->table_name} DROP COLUMN user_id" );
            $wpdb->query( "ALTER TABLE {$this->table_name} DROP COLUMN session_id" );
        }
    }

    /**
     * Create a new wishlist
     */
    public function create_wishlist($name = '', $user_id = null, $session_id = null) {
        global $wpdb;
        if (!$user_id && !$session_id) {
            $user_info = $this->get_user_info();
            $user_id = $user_info['user_id'];
            $session_id = $user_info['session_id'];
        }
        if (!$name) {
            $name = __('My Wishlist', 'advanced-wc-wishlist');
        }
        $wpdb->insert($this->lists_table_name, [
            'user_id' => $user_id,
            'session_id' => $session_id,
            'name' => $name,
            'date_created' => current_time('mysql'),
            'date_updated' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    /**
     * Get all wishlists for current user/session
     */
    public function get_wishlists($user_id = null, $session_id = null) {
        global $wpdb;
        if (!$user_id && !$session_id) {
            $user_info = $this->get_user_info();
            $user_id = $user_info['user_id'];
            $session_id = $user_info['session_id'];
        }
        $where = [];
        if ($user_id) $where[] = $wpdb->prepare('user_id = %d', $user_id);
        if ($session_id) $where[] = $wpdb->prepare('session_id = %s', $session_id);
        $where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return $wpdb->get_results("SELECT * FROM {$this->lists_table_name} $where_clause ORDER BY date_created ASC");
    }

    /**
     * Get a single wishlist by ID
     */
    public function get_wishlist($wishlist_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->lists_table_name} WHERE id = %d", $wishlist_id));
    }

    /**
     * Update wishlist name
     */
    public function update_wishlist($wishlist_id, $name) {
        global $wpdb;
        return $wpdb->update($this->lists_table_name, [
            'name' => $name,
            'date_updated' => current_time('mysql'),
        ], [ 'id' => $wishlist_id ]);
    }

    /**
     * Delete a wishlist and its items
     */
    public function delete_wishlist($wishlist_id) {
        global $wpdb;
        $wpdb->delete($this->table_name, [ 'wishlist_id' => $wishlist_id ]);
        return $wpdb->delete($this->lists_table_name, [ 'id' => $wishlist_id ]);
    }

    /**
     * Get default wishlist for user/session (create if not exists)
     */
    public function get_default_wishlist_id($user_id = null, $session_id = null) {
        $wishlists = $this->get_wishlists($user_id, $session_id);
        if (!empty($wishlists)) {
            return $wishlists[0]->id;
        }
        return $this->create_wishlist('', $user_id, $session_id);
    }

    /**
     * Add product to wishlist (now requires wishlist_id)
     */
    public function add_to_wishlist($product_id, $wishlist_id = null) {
        global $wpdb;
        if (!$wishlist_id) {
            $wishlist_id = $this->get_default_wishlist_id();
        }
        if (!$this->is_valid_product($product_id)) {
            return false;
        }
        if ($this->is_product_in_wishlist($product_id, $wishlist_id)) {
            return false;
        }
        $product = wc_get_product($product_id);
        $price = $product ? $product->get_price() : null;
        $result = $wpdb->insert(
            $this->table_name,
            [
                'wishlist_id' => $wishlist_id,
                'product_id' => $product_id,
                'price_at_add' => $price,
                'date_added' => current_time('mysql'),
            ],
            [ '%d', '%d', '%f', '%s' ]
        );
        if ($result) {
            do_action('aww_product_added_to_wishlist', $product_id, $wishlist_id);
            return $wpdb->insert_id;
        }
        return false;
    }

    /**
     * Remove product from wishlist (now requires wishlist_id)
     */
    public function remove_from_wishlist($product_id, $wishlist_id = null) {
        global $wpdb;
        if (!$wishlist_id) {
            $wishlist_id = $this->get_default_wishlist_id();
        }
        $result = $wpdb->delete($this->table_name, [
            'wishlist_id' => $wishlist_id,
            'product_id' => $product_id,
        ]);
        if ($result) {
            do_action('aww_product_removed_from_wishlist', $product_id, $wishlist_id);
            return true;
        }
        return false;
    }

    /**
     * Get wishlist items (now requires wishlist_id)
     */
    public function get_wishlist_items($wishlist_id = null, $limit = 0, $offset = 0) {
        global $wpdb;
        if (!$wishlist_id) {
            $wishlist_id = $this->get_default_wishlist_id();
        }
        $sql = "SELECT w.*, p.post_title as product_name, p.post_status 
                FROM {$this->table_name} w 
                LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID 
                WHERE w.wishlist_id = %d AND p.post_status = 'publish' 
                ORDER BY w.date_added DESC";
        if ($limit > 0) {
            $sql .= $wpdb->prepare(' LIMIT %d', $limit);
            if ($offset > 0) {
                $sql .= $wpdb->prepare(' OFFSET %d', $offset);
            }
        }
        return $wpdb->get_results($wpdb->prepare($sql, $wishlist_id));
    }

    /**
     * Get wishlist count (now requires wishlist_id)
     */
    public function get_wishlist_count($wishlist_id = null) {
        global $wpdb;
        if (!$wishlist_id) {
            $wishlist_id = $this->get_default_wishlist_id();
        }
        $sql = "SELECT COUNT(*) 
                FROM {$this->table_name} w 
                LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID 
                WHERE w.wishlist_id = %d AND p.post_status = 'publish'";
        return (int) $wpdb->get_var($wpdb->prepare($sql, $wishlist_id));
    }

    /**
     * Check if product is in wishlist (now requires wishlist_id)
     */
    public function is_product_in_wishlist($product_id, $wishlist_id = null) {
        global $wpdb;
        if (!$wishlist_id) {
            $wishlist_id = $this->get_default_wishlist_id();
        }
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE product_id = %d AND wishlist_id = %d",
            $product_id, $wishlist_id
        ));
        return (bool) $result;
    }

    /**
     * Safely start a session if possible
     *
     * @return bool Whether session was successfully started
     */
    private function safe_session_start() {
        // Check if we can start a session safely
        if ( ! headers_sent() && ! session_id() ) {
            try {
                session_start();
                return true;
            } catch ( Exception $e ) {
                // Session start failed, log the error only in debug mode
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'Advanced WC Wishlist: Could not start session: ' . $e->getMessage() );
                }
                return false;
            }
        }
        return session_id() ? true : false;
    }

    /**
     * Get user info for wishlist operations
     *
     * @return array
     */
    public function get_user_info() {
        $user_id = get_current_user_id();
        $session_id = null;

        if ( ! $user_id ) {
            // Try to start session safely
            $this->safe_session_start();
            
            // Only use session_id if session was successfully started
            if ( session_id() ) {
                $session_id = session_id();
            }
        }

        return array(
            'user_id' => $user_id,
            'session_id' => $session_id,
        );
    }

    /**
     * Validate product
     *
     * @param int $product_id Product ID
     * @return bool
     */
    private function is_valid_product( $product_id ) {
        $product = wc_get_product( $product_id );
        return $product && $product->is_visible();
    }

    /**
     * Transfer guest wishlist to user
     *
     * @param string $session_id Session ID
     * @param int    $user_id User ID
     * @return bool
     */
    public function transfer_guest_wishlist( $session_id, $user_id ) {
        global $wpdb;

        if ( ! $session_id || ! $user_id ) {
            return false;
        }

        // Get guest wishlists
        $guest_wishlists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->lists_table_name} WHERE session_id = %s",
                $session_id
            )
        );

        if ( empty( $guest_wishlists ) ) {
            return true;
        }

        // Transfer each wishlist
        foreach ( $guest_wishlists as $guest_wishlist ) {
            // Create new wishlist for user
            $new_wishlist_id = $this->create_wishlist( $guest_wishlist->name, $user_id );
            
            if ( $new_wishlist_id ) {
                // Get items from guest wishlist
                $guest_items = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT product_id FROM {$this->table_name} WHERE wishlist_id = %d",
                        $guest_wishlist->id
                    )
                );

                // Transfer each item
                foreach ( $guest_items as $item ) {
                    $this->add_to_wishlist( $item->product_id, $new_wishlist_id );
                }

                // Delete guest wishlist
                $this->delete_wishlist( $guest_wishlist->id );
            }
        }

        return true;
    }

    /**
     * Clean expired wishlist items
     *
     * @param int $days Number of days to keep items
     * @return int Number of deleted items
     */
    public function clean_expired_items( $days = 30 ) {
        global $wpdb;

        $expiry_date = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // Get guest wishlists older than expiry date
        $expired_wishlists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$this->lists_table_name} WHERE session_id IS NOT NULL AND date_created < %s",
                $expiry_date
            )
        );

        $deleted_count = 0;

        foreach ( $expired_wishlists as $wishlist ) {
            // Delete wishlist items
            $wpdb->delete( $this->table_name, array( 'wishlist_id' => $wishlist->id ) );
            // Delete wishlist
            $wpdb->delete( $this->lists_table_name, array( 'id' => $wishlist->id ) );
            $deleted_count++;
        }

        return $deleted_count;
    }





    /**
     * Get wishlist count by product
     *
     * @param int $product_id Product ID
     * @return int
     */
    public function get_wishlist_count_by_product( $product_id ) {
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE product_id = %d",
                $product_id
            )
        );

        return (int) $result;
    }

    /**
     * Export wishlist data
     *
     * @param string $format Export format (csv, json)
     * @return string|array
     */
    public function export_wishlist_data( $format = 'csv' ) {
        global $wpdb;

        $sql = "SELECT w.*, p.post_title as product_name, u.user_login, u.user_email, l.name as wishlist_name 
                FROM {$this->table_name} w 
                LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID 
                LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID 
                LEFT JOIN {$this->lists_table_name} l ON w.wishlist_id = l.id 
                ORDER BY w.date_added DESC";

        $data = $wpdb->get_results( $sql, ARRAY_A );

        if ( $format === 'json' ) {
            return $data;
        }

        // CSV format
        if ( empty( $data ) ) {
            return '';
        }

        $output = fopen( 'php://temp', 'r+' );
        fputcsv( $output, array_keys( $data[0] ) ); // Headers

        foreach ( $data as $row ) {
            fputcsv( $output, $row );
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }

    /**
     * Get wishlist items by session ID (for guest users)
     */
    public function get_wishlist_items_by_session( $session_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aww_wishlists';
        
        $sql = $wpdb->prepare(
            "SELECT w.* FROM {$table_name} w 
             INNER JOIN {$wpdb->prefix}aww_wishlist_lists l ON w.wishlist_id = l.id 
             WHERE l.session_id = %s 
             ORDER BY w.date_added DESC",
            $session_id
        );
        
        return $wpdb->get_results( $sql );
    }

    /**
     * Delete wishlist items by session ID (for guest users)
     */
    public function delete_wishlist_items_by_session( $session_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aww_wishlists';
        
        $sql = $wpdb->prepare(
            "DELETE w FROM {$table_name} w 
             INNER JOIN {$wpdb->prefix}aww_wishlist_lists l ON w.wishlist_id = l.id 
             WHERE l.session_id = %s",
            $session_id
        );
        
        return $wpdb->query( $sql );
    }

    /**
     * Get popular wishlisted products
     *
     * @param int $limit Number of products to return
     * @return array
     */
    public function get_popular_wishlisted_products( $limit = 10 ) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT product_id, COUNT(*) as wishlist_count 
             FROM {$this->table_name} 
             GROUP BY product_id 
             ORDER BY wishlist_count DESC 
             LIMIT %d",
            $limit
        );

        return $wpdb->get_results( $sql );
    }
} 