<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CF7SG_Plugin {
    private static $instance;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
        add_action( 'cf7sg_daily_cleanup', array( 'CF7SG_Logger', 'cleanup_expired' ) );
    }

    public function init() {
        load_plugin_textdomain( 'cf7-submission-guard', false, dirname( plugin_basename( CF7SG_FILE ) ) . '/languages' );

        if ( is_admin() ) {
            new CF7SG_Settings();
        }

        if ( defined( 'WPCF7_VERSION' ) ) {
            new CF7SG_Validator();
        } elseif ( is_admin() ) {
            add_action( 'admin_notices', array( $this, 'cf7_missing_notice' ) );
        }
    }

    public function cf7_missing_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'CF7 Submission Guard requires Contact Form 7 to be installed and active.', 'cf7-submission-guard' ) . '</p></div>';
    }

    public static function activate() {
        CF7SG_Logger::install_table();
        if ( ! wp_next_scheduled( 'cf7sg_daily_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'cf7sg_daily_cleanup' );
        }
        if ( false === get_option( 'cf7sg_settings', false ) ) {
            add_option( 'cf7sg_settings', CF7SG_Settings::defaults(), '', false );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'cf7sg_daily_cleanup' );
    }
}
