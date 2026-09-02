<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CF7SG_Rate_Limiter {
    public static function key( $ip, $form_id ) {
        return 'cf7sg_rl_' . md5( $ip . '|' . $form_id );
    }

    public static function hit( $ip, $form_id, $max, $minutes ) {
        if ( '' === $ip || $max < 1 || $minutes < 1 ) { return false; }
        $key = self::key( $ip, $form_id );
        $count = (int) get_transient( $key );
        if ( $count >= $max ) { return true; }
        set_transient( $key, $count + 1, $minutes * MINUTE_IN_SECONDS );
        return false;
    }
}
