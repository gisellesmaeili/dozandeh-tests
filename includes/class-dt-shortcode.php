<?php
defined( 'ABSPATH' ) || exit;

final class DT_Shortcode {

    public static function init() {
        add_shortcode( 'dozandeh_tests', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
    }

    public static function register_assets() {
        wp_register_style( 'dt-front', DT_URL . 'assets/css/frontend.css', [], DT_VERSION );
        wp_register_script(
            'dt-front',
            DT_URL . 'assets/js/frontend.js',
            [],
            DT_VERSION,
            [ 'in_footer' => true, 'strategy' => 'defer' ]
        );
    }

    public static function render() {
        static $rendered = false;
        if ( $rendered ) {
            return ''; // One instance for each page.
        }
        $rendered = true;

        wp_enqueue_style( 'dt-front' );
        wp_enqueue_script( 'dt-front' );
        wp_localize_script(
            'dt-front',
            'DT',
            [
                'rest' => esc_url_raw( rest_url( DT_Rest::NS . '/' ) ),
                'tel'  => 'tel:' . preg_replace( '/\D/', '', dt_phone() ),
            ]
        );

        $quizzes = DT_Repo::get_published_quizzes();

        ob_start();
        include DT_PATH . 'templates/tests.php';
        return ob_get_clean();
    }
}