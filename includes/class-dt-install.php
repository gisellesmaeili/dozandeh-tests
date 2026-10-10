<?php
defined( 'ABSPATH' ) || exit;

final class DT_Install {

    public static function activate() {
        self::create_tables();
        update_option( 'dt_db_version', DT_DB_VERSION );
    }

    public static function maybe_upgrade() {
        if ( get_option( 'dt_db_version' ) !== DT_DB_VERSION ) {
            self::create_tables();
            update_option( 'dt_db_version', DT_DB_VERSION );
        }
    }

    private static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix . 'dt_';

        dbDelta( "CREATE TABLE {$p}quizzes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(190) NOT NULL,
			description text NOT NULL,
			scoring_mode varchar(10) NOT NULL DEFAULT 'score',
            overall_title varchar(190) NOT NULL DEFAULT '',
			overall_levels longtext NOT NULL,
			dimensions longtext NOT NULL,
			result_note text NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'publish',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};" );

        dbDelta( "CREATE TABLE {$p}questions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) unsigned NOT NULL,
			body text NOT NULL,
			dimension_key varchar(20) NOT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY quiz_id (quiz_id)
		) {$charset};" );

        dbDelta( "CREATE TABLE {$p}options (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question_id bigint(20) unsigned NOT NULL,
			option_text varchar(500) NOT NULL,
			score int(11) NOT NULL DEFAULT 0,
			is_correct tinyint(1) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY question_id (question_id)
		) {$charset};" );

        dbDelta( "CREATE TABLE {$p}submissions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) unsigned NOT NULL,
			quiz_title varchar(190) NOT NULL,
			full_name varchar(120) NOT NULL,
			phone varchar(20) NOT NULL,
			age tinyint(3) unsigned NOT NULL DEFAULT 0,
			result longtext NOT NULL,
            answers longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY quiz_id (quiz_id),
			KEY created_at (created_at)
		) {$charset};" );
    }
}