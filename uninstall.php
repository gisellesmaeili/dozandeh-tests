<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// To delete all data on uninstall, add this line to wp-config.php:
// define( 'DT_REMOVE_DATA_ON_UNINSTALL', true );
if ( ! defined( 'DT_REMOVE_DATA_ON_UNINSTALL' ) || true !== DT_REMOVE_DATA_ON_UNINSTALL ) {
    return;
}

global $wpdb;

foreach ( [ 'options', 'questions', 'quizzes', 'submissions' ] as $name ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dt_{$name}" ); // phpcs:ignore
}

delete_option( 'dt_db_version' );
delete_option('dt_sms');
delete_option('dt_sms_last');
delete_transient( 'dt_cards' );