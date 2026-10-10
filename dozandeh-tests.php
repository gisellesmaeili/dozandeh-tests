<?php
/**
 * Plugin Name:       آزمون‌های روانشناسی دکتر دوزنده
 * Description:       افزونه سبک آزمون‌های روانشناسی
 * Version:           1.1.3
 * Author:            Giselle Esmaeili
 * Author URI:        https://www.linkedin.com/in/qazal-esmaeili-developer
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Text Domain:       dozandeh-tests
 */

defined( 'ABSPATH' ) || exit;

define( 'DT_VERSION', '1.1.3' );
define( 'DT_DB_VERSION', '2' );
define( 'DT_FILE', __FILE__ );
define( 'DT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DT_URL', plugin_dir_url( __FILE__ ) );

/** Phone number of the doctor. Use the "dt_phone" filter to change it. */
function dt_phone() {
    return apply_filters( 'dt_phone', '09030429138' );
}

/** Convert Persian and Arabic digits to English digits. */
function dt_to_en_digits( $value ) {
    return strtr(
        (string) $value,
        [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]
    );
}

/** Convert English digits to Persian digits. */
function dt_to_fa_digits( $value ) {
    return strtr( (string) $value, [ '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ] );
}

require_once DT_PATH . 'includes/class-dt-install.php';
require_once DT_PATH . 'includes/class-dt-repo.php';
require_once DT_PATH . 'includes/class-dt-validator.php';
require_once DT_PATH . 'includes/class-dt-sms.php';
require_once DT_PATH . 'includes/class-dt-rest.php';
require_once DT_PATH . 'includes/class-dt-shortcode.php';

register_activation_hook( DT_FILE, [ 'DT_Install', 'activate' ] );

add_action(
    'plugins_loaded',
    static function () {
        DT_Install::maybe_upgrade();
        DT_Rest::init();
        DT_Sms::init();
        DT_Shortcode::init();

        if ( is_admin() ) {
            require_once DT_PATH . 'includes/class-dt-admin.php';
            DT_Admin::init();
        }
    }
);