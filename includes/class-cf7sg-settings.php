<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CF7SG_Settings {
    const OPTION = 'cf7sg_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_cf7sg_clear_logs', array( $this, 'clear_logs' ) );
    }

    public static function defaults() {
        return array(
            'enabled'                 => 1,
            'mode'                    => 'enforce',
            'name_field'              => 'name',
            'name_max'                => 20,
            'message_field'           => 'message',
            'message_min'             => 30,
            'message_max'             => 0,
            'email_field'             => 'email',
            'country_field'           => 'select_country',
            'consent_field'           => '',
            'required_consent'        => 0,
            'content_fields'          => "name\nmessage",
            'block_urls'              => 1,
            'block_at'                => 1,
            'block_email_patterns'    => 1,
            'block_html'              => 1,
            'block_bbcode'            => 0,
            'max_digits_percent'      => 0,
            'max_uppercase_percent'   => 0,
            'forbidden_words'         => '',
            'blocked_domains'         => '',
            'allowed_countries'       => '',
            'rate_limit_enabled'      => 1,
            'rate_limit_count'        => 3,
            'rate_limit_minutes'      => 60,
            'min_time_enabled'        => 1,
            'min_time_seconds'        => 15,
            'duplicate_enabled'       => 0,
            'duplicate_minutes'       => 60,
            'logging_enabled'         => 1,
            'log_success'             => 0,
            'ip_storage'              => 'anonymized',
            'retention_days'          => 30,
            'error_field'             => 'name',
            'msg_name_max'            => 'Name is too long.',
            'msg_message_min'         => 'Message is too short.',
            'msg_message_max'         => 'Message is too long.',
            'msg_url'                 => 'Links are not allowed in this field.',
            'msg_at'                  => 'The @ character is not allowed in this field.',
            'msg_email_pattern'       => 'Email addresses are not allowed in this field.',
            'msg_html'                => 'HTML markup is not allowed in this field.',
            'msg_bbcode'              => 'BBCode markup is not allowed in this field.',
            'msg_forbidden'           => 'This field contains prohibited content.',
            'msg_country'             => 'Please select a valid country.',
            'msg_domain'              => 'This email domain is not allowed.',
            'msg_rate_limit'          => 'Too many submissions. Please try again later.',
            'msg_too_fast'            => 'The form was submitted too quickly. Please try again.',
            'msg_duplicate'           => 'This message was already submitted recently.',
            'msg_consent'             => 'Please accept the required consent.',
        );
    }

    public static function get() {
        return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
    }

    public function menu() {
        add_menu_page(
            __( 'CF7 Submission Guard', 'cf7-submission-guard' ),
            __( 'Submission Guard', 'cf7-submission-guard' ),
            'manage_options',
            'cf7-submission-guard',
            array( $this, 'render_dashboard' ),
            'dashicons-shield',
            80
        );
        add_submenu_page(
            'cf7-submission-guard',
            __( 'CF7 Submission Guard Dashboard', 'cf7-submission-guard' ),
            __( 'Dashboard', 'cf7-submission-guard' ),
            'manage_options',
            'cf7-submission-guard',
            array( $this, 'render_dashboard' )
        );
        add_submenu_page(
            'cf7-submission-guard',
            __( 'CF7 Submission Guard Logs', 'cf7-submission-guard' ),
            __( 'Logs', 'cf7-submission-guard' ),
            'manage_options',
            'cf7-submission-guard-logs',
            array( $this, 'render_logs' )
        );
        add_submenu_page(
            'cf7-submission-guard',
            __( 'CF7 Submission Guard Settings', 'cf7-submission-guard' ),
            __( 'Settings', 'cf7-submission-guard' ),
            'manage_options',
            'cf7-submission-guard-settings',
            array( $this, 'render_settings' )
        );
    }

    public function register() {
        register_setting( 'cf7sg_group', self::OPTION, array( $this, 'sanitize' ) );
    }

    public function assets( $hook ) {
        if ( false === strpos( $hook, 'cf7-submission-guard' ) ) {
            return;
        }
        wp_enqueue_style( 'cf7sg-admin', CF7SG_URL . 'assets/admin.css', array(), CF7SG_VERSION );
    }

    private function clean_multiline( $value ) {
        $lines = preg_split( '/\R/', (string) $value );
        $lines = array_filter( array_map( 'trim', $lines ) );
        return implode( "\n", array_values( array_unique( $lines ) ) );
    }

    public function sanitize( $input ) {
        $defaults = self::defaults();
        $out = $defaults;
        $checkboxes = array( 'enabled','required_consent','block_urls','block_at','block_email_patterns','block_html','block_bbcode','rate_limit_enabled','min_time_enabled','duplicate_enabled','logging_enabled','log_success' );
        foreach ( $checkboxes as $key ) {
            $out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
        }

        $out['mode'] = isset( $input['mode'] ) && in_array( $input['mode'], array( 'enforce', 'monitor' ), true ) ? $input['mode'] : 'enforce';
        $out['ip_storage'] = isset( $input['ip_storage'] ) && in_array( $input['ip_storage'], array( 'anonymized', 'full', 'none' ), true ) ? $input['ip_storage'] : 'anonymized';

        foreach ( array( 'name_field','message_field','email_field','country_field','consent_field','error_field' ) as $key ) {
            $out[ $key ] = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
        }
        foreach ( array( 'name_max','message_min','message_max','rate_limit_count','rate_limit_minutes','min_time_seconds','duplicate_minutes','retention_days','max_digits_percent','max_uppercase_percent' ) as $key ) {
            $out[ $key ] = isset( $input[ $key ] ) ? max( 0, absint( $input[ $key ] ) ) : 0;
        }
        foreach ( array( 'content_fields','forbidden_words','blocked_domains','allowed_countries' ) as $key ) {
            $out[ $key ] = isset( $input[ $key ] ) ? $this->clean_multiline( sanitize_textarea_field( wp_unslash( $input[ $key ] ) ) ) : '';
        }
        foreach ( array_keys( $defaults ) as $key ) {
            if ( 0 === strpos( $key, 'msg_' ) ) {
                $out[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : $defaults[ $key ];
            }
        }
        return $out;
    }

    private function checkbox( $name, $label, $s ) {
        printf( '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>', esc_attr( self::OPTION ), esc_attr( $name ), checked( ! empty( $s[ $name ] ), true, false ), esc_html( $label ) );
    }

    private function text( $name, $s, $type = 'text', $min = null ) {
        $min_attr = null !== $min ? ' min="' . esc_attr( $min ) . '"' : '';
        printf( '<input class="regular-text" type="%1$s"%2$s name="%3$s[%4$s]" value="%5$s">', esc_attr( $type ), $min_attr, esc_attr( self::OPTION ), esc_attr( $name ), esc_attr( $s[ $name ] ) );
    }

    private function textarea( $name, $s, $rows = 5 ) {
        printf( '<textarea class="large-text code" rows="%1$d" name="%2$s[%3$s]">%4$s</textarea>', absint( $rows ), esc_attr( self::OPTION ), esc_attr( $name ), esc_textarea( $s[ $name ] ) );
    }

    public function render_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $s = self::get();
        $stats = CF7SG_Logger::stats( 30 );
        ?>
        <div class="wrap cf7sg-wrap">
            <h1><?php esc_html_e( 'CF7 Submission Guard', 'cf7-submission-guard' ); ?> <span class="cf7sg-version">v<?php echo esc_html( CF7SG_VERSION ); ?></span></h1>
            <p>
                <?php
                if ( empty( $s['enabled'] ) ) {
                    esc_html_e( 'Protection is currently disabled.', 'cf7-submission-guard' );
                } elseif ( 'monitor' === $s['mode'] ) {
                    esc_html_e( 'Protection is enabled in monitor mode: rule matches are logged but submissions are not blocked.', 'cf7-submission-guard' );
                } else {
                    esc_html_e( 'Protection is enabled and enforcing rules on Contact Form 7 submissions.', 'cf7-submission-guard' );
                }
                ?>
            </p>

            <div class="cf7sg-metrics">
                <div class="cf7sg-metric"><strong><?php echo esc_html( number_format_i18n( $stats['blocked'] ) ); ?></strong><span><?php esc_html_e( 'Blocked, last 30 days', 'cf7-submission-guard' ); ?></span></div>
                <div class="cf7sg-metric"><strong><?php echo esc_html( number_format_i18n( $stats['allowed'] ) ); ?></strong><span><?php esc_html_e( 'Allowed (logged), last 30 days', 'cf7-submission-guard' ); ?></span></div>
                <div class="cf7sg-metric"><strong><?php echo empty( $s['rate_limit_enabled'] ) ? esc_html__( 'Off', 'cf7-submission-guard' ) : esc_html( $s['rate_limit_count'] . '/' . $s['rate_limit_minutes'] . 'm' ); ?></strong><span><?php esc_html_e( 'Rate limit', 'cf7-submission-guard' ); ?></span></div>
            </div>

            <div class="cf7sg-grid">
                <section class="cf7sg-card">
                    <h2><?php esc_html_e( 'Most triggered rules, last 30 days', 'cf7-submission-guard' ); ?></h2>
                    <?php if ( empty( $stats['by_rule'] ) ) : ?>
                        <p class="description"><?php esc_html_e( 'No blocked submissions in this period.', 'cf7-submission-guard' ); ?></p>
                    <?php else : ?>
                        <table class="widefat striped cf7sg-log-table">
                            <thead><tr><th><?php esc_html_e( 'Rule', 'cf7-submission-guard' ); ?></th><th><?php esc_html_e( 'Count', 'cf7-submission-guard' ); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ( $stats['by_rule'] as $row ) : ?>
                                <tr><td><code><?php echo esc_html( $row->rule ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="cf7sg-card">
                    <h2><?php esc_html_e( 'Recent activity', 'cf7-submission-guard' ); ?></h2>
                    <?php $recent = CF7SG_Logger::recent( 10 ); ?>
                    <?php if ( empty( $recent ) ) : ?>
                        <p class="description"><?php esc_html_e( 'No log entries yet.', 'cf7-submission-guard' ); ?></p>
                    <?php else : ?>
                        <table class="widefat striped cf7sg-log-table">
                            <thead><tr><th><?php esc_html_e( 'Date', 'cf7-submission-guard' ); ?></th><th><?php esc_html_e( 'Result', 'cf7-submission-guard' ); ?></th><th><?php esc_html_e( 'Rule', 'cf7-submission-guard' ); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ( $recent as $row ) : ?>
                                <tr><td><?php echo esc_html( $row->created_at ); ?></td><td><?php echo esc_html( $row->result ); ?></td><td><code><?php echo esc_html( $row->rule ); ?></code></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cf7-submission-guard-logs' ) ); ?>"><?php esc_html_e( 'View full log', 'cf7-submission-guard' ); ?> &rarr;</a></p>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $s = self::get();
        ?>
        <div class="wrap cf7sg-wrap">
            <h1><?php esc_html_e( 'CF7 Submission Guard', 'cf7-submission-guard' ); ?> <span class="cf7sg-version">v<?php echo esc_html( CF7SG_VERSION ); ?></span></h1>
            <p><?php esc_html_e( 'Server-side submission rules for Contact Form 7. Field names must match the CF7 form-tag names.', 'cf7-submission-guard' ); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields( 'cf7sg_group' ); ?>
                <div class="cf7sg-grid">
                    <section class="cf7sg-card"><h2>General</h2>
                        <p><?php $this->checkbox( 'enabled', 'Enable protection', $s ); ?></p>
                        <p><label>Mode<br><select name="<?php echo esc_attr( self::OPTION ); ?>[mode]"><option value="enforce" <?php selected( $s['mode'], 'enforce' ); ?>>Enforce</option><option value="monitor" <?php selected( $s['mode'], 'monitor' ); ?>>Monitor only</option></select></label></p>
                        <p><label>Generic error field<br><?php $this->text( 'error_field', $s ); ?></label></p>
                    </section>

                    <section class="cf7sg-card"><h2>Core fields</h2>
                        <p><label>Name field<br><?php $this->text( 'name_field', $s ); ?></label></p>
                        <p><label>Maximum name length<br><?php $this->text( 'name_max', $s, 'number', 0 ); ?></label></p>
                        <p><label>Message field<br><?php $this->text( 'message_field', $s ); ?></label></p>
                        <p><label>Minimum message length<br><?php $this->text( 'message_min', $s, 'number', 0 ); ?></label></p>
                        <p><label>Maximum message length (0 = disabled)<br><?php $this->text( 'message_max', $s, 'number', 0 ); ?></label></p>
                        <p><label>Email field<br><?php $this->text( 'email_field', $s ); ?></label></p>
                        <p><label>Country field<br><?php $this->text( 'country_field', $s ); ?></label></p>
                    </section>

                    <section class="cf7sg-card"><h2>Content rules</h2>
                        <p><label>Fields, one per line<br><?php $this->textarea( 'content_fields', $s, 4 ); ?></label></p>
                        <p><?php $this->checkbox( 'block_urls', 'Block URLs/domains', $s ); ?></p>
                        <p><?php $this->checkbox( 'block_at', 'Block @ character', $s ); ?></p>
                        <p><?php $this->checkbox( 'block_email_patterns', 'Block email patterns', $s ); ?></p>
                        <p><?php $this->checkbox( 'block_html', 'Block HTML tags', $s ); ?></p>
                        <p><?php $this->checkbox( 'block_bbcode', 'Block BBCode-like markup', $s ); ?></p>
                        <p><label>Max digits % (0 = disabled)<br><?php $this->text( 'max_digits_percent', $s, 'number', 0 ); ?></label></p>
                        <p><label>Max uppercase % (0 = disabled)<br><?php $this->text( 'max_uppercase_percent', $s, 'number', 0 ); ?></label></p>
                        <p><label>Forbidden words/phrases, one per line<br><?php $this->textarea( 'forbidden_words', $s, 5 ); ?></label></p>
                    </section>

                    <section class="cf7sg-card"><h2>Email domains</h2>
                        <p>Blocked domains, one per line. Subdomains are blocked too.</p>
                        <?php $this->textarea( 'blocked_domains', $s, 10 ); ?>
                    </section>

                    <section class="cf7sg-card"><h2>Country</h2>
                        <p>Allowed submitted values, one per line. If empty, the plugin validates against the CF7 select tag values when available.</p>
                        <?php $this->textarea( 'allowed_countries', $s, 10 ); ?>
                    </section>

                    <section class="cf7sg-card"><h2>Rate limit & timing</h2>
                        <p><?php $this->checkbox( 'rate_limit_enabled', 'Enable IP rate limiting', $s ); ?></p>
                        <p><label>Maximum attempts<br><?php $this->text( 'rate_limit_count', $s, 'number', 1 ); ?></label></p>
                        <p><label>Period, minutes<br><?php $this->text( 'rate_limit_minutes', $s, 'number', 1 ); ?></label></p>
                        <hr>
                        <p><?php $this->checkbox( 'min_time_enabled', 'Enable minimum completion time', $s ); ?></p>
                        <p><label>Minimum seconds<br><?php $this->text( 'min_time_seconds', $s, 'number', 0 ); ?></label></p>
                        <hr>
                        <p><?php $this->checkbox( 'duplicate_enabled', 'Block duplicate messages from same IP', $s ); ?></p>
                        <p><label>Duplicate window, minutes<br><?php $this->text( 'duplicate_minutes', $s, 'number', 1 ); ?></label></p>
                    </section>

                    <section class="cf7sg-card"><h2>Consent validation</h2>
                        <p><?php $this->checkbox( 'required_consent', 'Require configured consent field', $s ); ?></p>
                        <p><label>Consent field<br><?php $this->text( 'consent_field', $s ); ?></label></p>
                        <p class="description">This does not create consent text; it only validates the submitted CF7 field.</p>
                    </section>

                    <section class="cf7sg-card"><h2>Logging</h2>
                        <p><?php $this->checkbox( 'logging_enabled', 'Enable security logging', $s ); ?></p>
                        <p><?php $this->checkbox( 'log_success', 'Log successful submissions', $s ); ?></p>
                        <p><label>IP storage<br><select name="<?php echo esc_attr( self::OPTION ); ?>[ip_storage]"><option value="anonymized" <?php selected( $s['ip_storage'], 'anonymized' ); ?>>Anonymized</option><option value="full" <?php selected( $s['ip_storage'], 'full' ); ?>>Full IP</option><option value="none" <?php selected( $s['ip_storage'], 'none' ); ?>>Do not store</option></select></label></p>
                        <p><label>Retention, days<br><?php $this->text( 'retention_days', $s, 'number', 1 ); ?></label></p>
                        <p class="description">Name and message contents are never written to the plugin log. The submitted email address itself is never stored either — only its domain (e.g. "example.com"), to help spot patterns.</p>
                    </section>

                    <section class="cf7sg-card cf7sg-card-wide"><h2>Error messages</h2>
                        <div class="cf7sg-message-grid">
                        <?php
                        $labels = array(
                            'msg_name_max'=>'Name too long','msg_message_min'=>'Message too short','msg_message_max'=>'Message too long','msg_url'=>'URL detected','msg_at'=>'@ detected','msg_email_pattern'=>'Email pattern detected','msg_html'=>'HTML detected','msg_bbcode'=>'BBCode detected','msg_forbidden'=>'Forbidden content','msg_country'=>'Invalid country','msg_domain'=>'Blocked email domain','msg_rate_limit'=>'Rate limit','msg_too_fast'=>'Too fast','msg_duplicate'=>'Duplicate','msg_consent'=>'Consent missing'
                        );
                        foreach ( $labels as $key => $label ) {
                            echo '<label>' . esc_html( $label );
                            $this->text( $key, $s );
                            echo '</label>';
                        }
                        ?>
                        </div>
                    </section>
                </div>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_logs() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $rows = CF7SG_Logger::recent( 200 );
        ?>
        <div class="wrap cf7sg-wrap"><h1>CF7 Submission Guard Logs</h1>
            <p>Latest 200 events. Name and message contents are never stored; the email address itself is never stored either, only its domain.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Clear all Submission Guard logs?');">
                <input type="hidden" name="action" value="cf7sg_clear_logs">
                <?php wp_nonce_field( 'cf7sg_clear_logs' ); ?>
                <?php submit_button( 'Clear all logs', 'delete', 'submit', false ); ?>
            </form>
            <table class="widefat striped cf7sg-log-table"><thead><tr><th>Date</th><th>Form</th><th>Result</th><th>Rule</th><th>Field</th><th>Email domain</th><th>IP</th><th class="cf7sg-col-ua">User agent</th></tr></thead><tbody>
            <?php if ( ! $rows ) : ?><tr><td colspan="8">No log entries.</td></tr><?php else : foreach ( $rows as $row ) : ?>
                <tr><td><?php echo esc_html( $row->created_at ); ?></td><td><?php echo esc_html( $row->form_id ); ?></td><td><?php echo esc_html( $row->result ); ?></td><td><code><?php echo esc_html( $row->rule ); ?></code></td><td><?php echo esc_html( $row->field_name ); ?></td><td><?php echo esc_html( $row->email_domain ); ?></td><td><?php echo esc_html( $row->ip_value ); ?></td><td class="cf7sg-col-ua"><span class="cf7sg-ua-text"><?php echo esc_html( $row->user_agent ); ?></span><?php if ( '' !== $row->user_agent ) : ?><span class="cf7sg-ua-info" title="<?php echo esc_attr( $row->user_agent ); ?>">&#9432;</span><?php endif; ?></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    public function clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Unauthorized.', 'cf7-submission-guard' ) ); }
        check_admin_referer( 'cf7sg_clear_logs' );
        CF7SG_Logger::clear();
        wp_safe_redirect( add_query_arg( array( 'page' => 'cf7-submission-guard-logs' ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
