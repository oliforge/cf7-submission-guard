<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CF7SG_Validator {
    private $settings;
    private $events = array();
    private $request_checked = false;

    public function __construct() {
        $this->settings = CF7SG_Settings::get();
        add_filter( 'wpcf7_form_elements', array( $this, 'inject_timing_token' ) );
        add_filter( 'wpcf7_validate', array( $this, 'validate' ), 50, 2 );
        add_action( 'wpcf7_mail_sent', array( $this, 'log_success' ) );
    }

    public function inject_timing_token( $html ) {
        if ( empty( $this->settings['enabled'] ) || empty( $this->settings['min_time_enabled'] ) ) { return $html; }
        $ts = time();
        $sig = hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) );
        return $html . sprintf(
            '<input type="hidden" name="_cf7sg_ts" value="%1$d"><input type="hidden" name="_cf7sg_sig" value="%2$s">',
            $ts,
            esc_attr( $sig )
        );
    }

    private function raw( $name ) {
        if ( ! $name || ! isset( $_POST[ $name ] ) ) { return ''; }
        $value = wp_unslash( $_POST[ $name ] );
        if ( is_array( $value ) ) {
            $value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
        }
        return trim( (string) $value );
    }

    private function lines( $value ) {
        return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $value ) ) ) );
    }

    private function ip() {
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }

    private function form_id() {
        $form = function_exists( 'wpcf7_get_current_contact_form' ) ? wpcf7_get_current_contact_form() : null;
        if ( ! $form ) { return ''; }
        if ( method_exists( $form, 'hash' ) ) {
            $hash = $form->hash();
            if ( $hash ) { return $hash; }
        }
        return method_exists( $form, 'id' ) ? (string) $form->id() : '';
    }

    private function add_event( $rule, $field, $message ) {
        $this->events[] = array( 'rule' => $rule, 'field' => $field, 'message' => $message );
    }

    private function tag_by_name( $name ) {
        $form = function_exists( 'wpcf7_get_current_contact_form' ) ? wpcf7_get_current_contact_form() : null;
        if ( ! $form || ! method_exists( $form, 'scan_form_tags' ) ) { return null; }
        foreach ( (array) $form->scan_form_tags() as $tag ) {
            if ( isset( $tag->name ) && $name === $tag->name ) { return $tag; }
        }
        return null;
    }

    private function invalidate( $result, $field, $message ) {
        if ( 'monitor' === $this->settings['mode'] ) { return; }
        $tag = $this->tag_by_name( $field );
        if ( ! $tag ) { $tag = $this->tag_by_name( $this->settings['error_field'] ); }
        // CF7 core silently ignores invalidate() unless $tag resolves to a real tag
        // name on the form. If the configured field and error_field both mismatch
        // the actual form tags, fall back to the first eligible tag so a matched
        // rule always blocks the submission instead of being logged as blocked
        // while the mail still sends.
        if ( ! $tag ) { $tag = $this->first_field_tag(); }
        if ( $tag ) { $result->invalidate( $tag, $message ); }
    }

    private function first_field_tag() {
        $form = function_exists( 'wpcf7_get_current_contact_form' ) ? wpcf7_get_current_contact_form() : null;
        if ( ! $form || ! method_exists( $form, 'scan_form_tags' ) ) { return null; }
        foreach ( (array) $form->scan_form_tags() as $tag ) {
            if ( ! empty( $tag->name ) && ! in_array( $tag->basetype, array( 'submit', 'hidden' ), true ) ) {
                return $tag;
            }
        }
        return null;
    }

    private function detect_url( $text ) {
        if ( preg_match( '~(?:https?\s*:\s*//|www\s*\.)~iu', $text ) ) { return true; }
        return (bool) preg_match( '~\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|net|org|io|co|biz|info|me|xyz|online|site|store|ru|ua|de|fr|it|es|pl|nl|uk|us|ca|app|dev)\b~iu', $text );
    }

    private function detect_email( $text ) {
        return (bool) preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text );
    }

    private function percent_digits( $text ) {
        $len = max( 1, mb_strlen( preg_replace( '/\s+/u', '', $text ) ) );
        preg_match_all( '/\p{N}/u', $text, $m );
        return ( count( $m[0] ) / $len ) * 100;
    }

    private function percent_uppercase( $text ) {
        preg_match_all( '/\p{L}/u', $text, $letters );
        $total = count( $letters[0] );
        if ( 0 === $total ) { return 0; }
        preg_match_all( '/\p{Lu}/u', $text, $upper );
        return ( count( $upper[0] ) / $total ) * 100;
    }

    private function validate_country( $field ) {
        $value = $this->raw( $field );
        if ( '' === $value ) { return false; }
        $allowed = $this->lines( $this->settings['allowed_countries'] );
        if ( ! $allowed ) {
            $tag = $this->tag_by_name( $field );
            if ( $tag && isset( $tag->values ) ) { $allowed = array_map( 'strval', (array) $tag->values ); }
        }
        if ( ! $allowed ) { return false; }
        return ! in_array( $value, $allowed, true );
    }

    private function email_domain( $email ) {
        $at = strrpos( $email, '@' );
        if ( false === $at ) { return ''; }
        return strtolower( trim( substr( $email, $at + 1 ), ". \t\n\r\0\x0B" ) );
    }

    private function blocked_domain( $email ) {
        $domain = $this->email_domain( $email );
        if ( '' === $domain ) { return false; }
        foreach ( $this->lines( strtolower( $this->settings['blocked_domains'] ) ) as $blocked ) {
            $blocked = ltrim( $blocked, '.@ ' );
            if ( $domain === $blocked || ( strlen( $domain ) > strlen( $blocked ) && substr( $domain, -( strlen( $blocked ) + 1 ) ) === '.' . $blocked ) ) {
                return true;
            }
        }
        return false;
    }

    private function timing_invalid() {
        if ( empty( $this->settings['min_time_enabled'] ) ) { return false; }
        $ts = isset( $_POST['_cf7sg_ts'] ) ? absint( $_POST['_cf7sg_ts'] ) : 0;
        $sig = isset( $_POST['_cf7sg_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['_cf7sg_sig'] ) ) : '';
        if ( ! $ts || ! $sig ) { return true; }
        $expected = hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) );
        if ( ! hash_equals( $expected, $sig ) ) { return true; }
        $elapsed = time() - $ts;
        return $elapsed < absint( $this->settings['min_time_seconds'] ) || $elapsed > DAY_IN_SECONDS;
    }

    private function duplicate( $ip, $form_id, $message ) {
        if ( empty( $this->settings['duplicate_enabled'] ) || '' === $message ) { return false; }
        $key = 'cf7sg_dup_' . md5( $ip . '|' . $form_id . '|' . hash( 'sha256', $message ) );
        if ( get_transient( $key ) ) { return true; }
        set_transient( $key, 1, max( 1, absint( $this->settings['duplicate_minutes'] ) ) * MINUTE_IN_SECONDS );
        return false;
    }

    public function validate( $result, $tags ) {
        if ( $this->request_checked || empty( $this->settings['enabled'] ) ) { return $result; }
        $this->request_checked = true;
        $s = $this->settings;

        $name = $this->raw( $s['name_field'] );
        if ( $s['name_max'] > 0 && mb_strlen( $name ) > $s['name_max'] ) {
            $this->add_event( 'name_max', $s['name_field'], $s['msg_name_max'] );
        }

        $message = $this->raw( $s['message_field'] );
        if ( $s['message_min'] > 0 && mb_strlen( $message ) < $s['message_min'] ) {
            $this->add_event( 'message_min', $s['message_field'], $s['msg_message_min'] );
        }
        if ( $s['message_max'] > 0 && mb_strlen( $message ) > $s['message_max'] ) {
            $this->add_event( 'message_max', $s['message_field'], $s['msg_message_max'] );
        }

        foreach ( $this->lines( $s['content_fields'] ) as $field ) {
            $text = $this->raw( sanitize_key( $field ) );
            if ( '' === $text ) { continue; }
            if ( ! empty( $s['block_urls'] ) && $this->detect_url( $text ) ) { $this->add_event( 'url_detected', $field, $s['msg_url'] ); }
            if ( ! empty( $s['block_at'] ) && false !== strpos( $text, '@' ) ) { $this->add_event( 'at_detected', $field, $s['msg_at'] ); }
            if ( ! empty( $s['block_email_patterns'] ) && $this->detect_email( $text ) ) { $this->add_event( 'email_pattern', $field, $s['msg_email_pattern'] ); }
            if ( ! empty( $s['block_html'] ) && preg_match( '/<\/?[a-z][^>]*>/iu', $text ) ) { $this->add_event( 'html_detected', $field, $s['msg_html'] ); }
            if ( ! empty( $s['block_bbcode'] ) && preg_match( '/\[(?:url|link|img|code|quote)(?:=[^\]]+)?\]/iu', $text ) ) { $this->add_event( 'bbcode_detected', $field, $s['msg_bbcode'] ); }
            if ( $s['max_digits_percent'] > 0 && $this->percent_digits( $text ) > $s['max_digits_percent'] ) { $this->add_event( 'digits_ratio', $field, $s['msg_forbidden'] ); }
            if ( $s['max_uppercase_percent'] > 0 && $this->percent_uppercase( $text ) > $s['max_uppercase_percent'] ) { $this->add_event( 'uppercase_ratio', $field, $s['msg_forbidden'] ); }
            foreach ( $this->lines( $s['forbidden_words'] ) as $needle ) {
                if ( '' !== $needle && false !== mb_stripos( $text, $needle ) ) { $this->add_event( 'forbidden_content', $field, $s['msg_forbidden'] ); break; }
            }
        }

        $email = $this->raw( $s['email_field'] );
        if ( $email && $this->blocked_domain( $email ) ) { $this->add_event( 'blocked_domain', $s['email_field'], $s['msg_domain'] ); }

        if ( $s['country_field'] && $this->validate_country( $s['country_field'] ) ) { $this->add_event( 'invalid_country', $s['country_field'], $s['msg_country'] ); }

        if ( ! empty( $s['required_consent'] ) && $s['consent_field'] ) {
            $consent = $this->raw( $s['consent_field'] );
            if ( '' === $consent ) { $this->add_event( 'consent_missing', $s['consent_field'], $s['msg_consent'] ); }
        }

        if ( $this->timing_invalid() ) { $this->add_event( 'too_fast', $s['error_field'], $s['msg_too_fast'] ); }

        $ip = $this->ip();
        $form_id = $this->form_id();
        if ( ! empty( $s['rate_limit_enabled'] ) && CF7SG_Rate_Limiter::hit( $ip, $form_id, absint( $s['rate_limit_count'] ), absint( $s['rate_limit_minutes'] ) ) ) {
            $this->add_event( 'rate_limit', $s['error_field'], $s['msg_rate_limit'] );
        }
        if ( $this->duplicate( $ip, $form_id, $message ) ) { $this->add_event( 'duplicate', $s['message_field'], $s['msg_duplicate'] ); }

        $domain = $this->email_domain( $email );
        foreach ( $this->events as $event ) {
            $this->invalidate( $result, sanitize_key( $event['field'] ), $event['message'] );
            CF7SG_Logger::add( $form_id, 'blocked', $event['rule'], $event['field'], $ip, $s, $domain );
        }

        return $result;
    }

    public function log_success( $contact_form ) {
        $s = $this->settings;
        if ( empty( $s['logging_enabled'] ) || empty( $s['log_success'] ) || ! empty( $this->events ) ) { return; }
        $form_id = method_exists( $contact_form, 'hash' ) && $contact_form->hash() ? $contact_form->hash() : ( method_exists( $contact_form, 'id' ) ? $contact_form->id() : '' );
        $domain = $this->email_domain( $this->raw( $s['email_field'] ) );
        CF7SG_Logger::add( $form_id, 'allowed', 'passed', '', $this->ip(), $s, $domain );
    }
}
