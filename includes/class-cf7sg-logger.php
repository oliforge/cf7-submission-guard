<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CF7SG_Logger {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cf7sg_logs';
    }

    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            form_id varchar(64) NOT NULL DEFAULT '',
            result varchar(20) NOT NULL DEFAULT '',
            rule varchar(64) NOT NULL DEFAULT '',
            field_name varchar(190) NOT NULL DEFAULT '',
            ip_value varchar(128) NOT NULL DEFAULT '',
            email_domain varchar(190) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY form_rule (form_id, rule)
        ) {$charset};";
        dbDelta( $sql );
    }

    public static function ip_for_log( $ip, $mode ) {
        if ( 'none' === $mode || '' === $ip ) { return ''; }
        if ( 'full' === $mode ) { return substr( $ip, 0, 128 ); }
        return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 24 );
    }

    public static function add( $form_id, $result, $rule, $field_name, $ip, $settings, $email_domain = '' ) {
        if ( empty( $settings['logging_enabled'] ) ) { return; }
        global $wpdb;
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $wpdb->insert(
            self::table(),
            array(
                'created_at' => current_time( 'mysql', true ),
                'form_id' => sanitize_text_field( (string) $form_id ),
                'result' => sanitize_key( $result ),
                'rule' => sanitize_key( $rule ),
                'field_name' => sanitize_key( $field_name ),
                'ip_value' => self::ip_for_log( $ip, $settings['ip_storage'] ),
                'email_domain' => substr( sanitize_text_field( (string) $email_domain ), 0, 190 ),
                'user_agent' => substr( $ua, 0, 255 ),
            ),
            array( '%s','%s','%s','%s','%s','%s','%s','%s' )
        );
    }

    public static function recent( $limit = 200 ) {
        global $wpdb;
        $limit = max( 1, min( 500, absint( $limit ) ) );
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Dashboard summary: blocked/allowed totals for the window, and the
     * most frequently triggered rules within it.
     */
    public static function stats( $days = 30 ) {
        global $wpdb;
        $table = self::table();
        $since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );

        $totals = array( 'blocked' => 0, 'allowed' => 0 );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT result, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY result", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ( (array) $rows as $row ) {
            if ( isset( $totals[ $row->result ] ) ) { $totals[ $row->result ] = (int) $row->total; }
        }

        $by_rule = $wpdb->get_results( $wpdb->prepare( "SELECT rule, COUNT(*) AS total FROM {$table} WHERE created_at >= %s AND result = %s GROUP BY rule ORDER BY total DESC LIMIT 8", $since, 'blocked' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array(
            'days'      => absint( $days ),
            'blocked'   => $totals['blocked'],
            'allowed'   => $totals['allowed'],
            'by_rule'   => $by_rule,
        );
    }

    public static function cleanup_expired() {
        global $wpdb;
        $settings = CF7SG_Settings::get();
        $days = max( 1, absint( $settings['retention_days'] ) );
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $table = self::table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $threshold ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function clear() {
        global $wpdb;
        $table = self::table();
        $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
