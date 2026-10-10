<?php
defined( 'ABSPATH' ) || exit;

final class DT_Repo {

    const CACHE_KEY = 'dt_cards';

    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'dt_' . $name;
    }

    /** Decode a JSON list. Return an empty array if the data is not valid. */
    public static function decode_list( $json ) {
        $data = json_decode( (string) $json, true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Read the saved result of a submission.
     * It supports the old format (a list of components) and the new format.
     */
    public static function parse_result( $json ) {
        $data = json_decode( (string) $json, true );
        $out  = [ 'overall' => null, 'dimensions' => [], 'flags' => [] ];

        if ( ! is_array( $data ) ) {
            return $out;
        }
        if ( isset( $data['dimensions'] ) ) {
            $out['overall']    = isset( $data['overall'] ) && is_array( $data['overall'] ) ? $data['overall'] : null;
            $out['dimensions'] = (array) $data['dimensions'];
            $out['flags']      = (array) ( $data['flags'] ?? [] );
        } else {
            $out['dimensions'] = $data; // Old format.
        }
        return $out;
    }

    /** Turn a test on ("publish") or off ("draft"). */
    public static function set_status( $id, $status ) {
        global $wpdb;
        $wpdb->update(
            self::table( 'quizzes' ),
            [ 'status' => 'publish' === $status ? 'publish' : 'draft' ],
            [ 'id' => (int) $id ],
            [ '%s' ],
            [ '%d' ]
        );
        self::flush_cache();
    }

    public static function get_submission( $id ) {
        global $wpdb;
        $table = self::table( 'submissions' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
    }
    public static function flush_cache() {
        delete_transient( self::CACHE_KEY );
    }

    /* ---------------------------------------------------------------- Quizzes */

    /** Published quizzes for the front-end cards. Cached for one day. */
    public static function get_published_quizzes() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $q    = self::table( 'quizzes' );
        $qs   = self::table( 'questions' );
        $rows = $wpdb->get_results(
            "SELECT q.id, q.title, q.description,
				(SELECT COUNT(*) FROM {$qs} x WHERE x.quiz_id = q.id) AS question_count
			FROM {$q} q WHERE q.status = 'publish' ORDER BY q.id ASC",
            ARRAY_A
        );
        $rows = $rows ? $rows : [];

        set_transient( self::CACHE_KEY, $rows, DAY_IN_SECONDS );
        return $rows;
    }

    /** All quizzes for the admin list. */
    public static function get_all_quizzes() {
        global $wpdb;
        $q  = self::table( 'quizzes' );
        $qs = self::table( 'questions' );
        $rows = $wpdb->get_results(
            "SELECT q.id, q.title, q.status,
				(SELECT COUNT(*) FROM {$qs} x WHERE x.quiz_id = q.id) AS question_count
			FROM {$q} q ORDER BY q.id DESC",
            ARRAY_A
        );
        return $rows ? $rows : [];
    }

    public static function get_quiz( $id ) {
        global $wpdb;
        $table = self::table( 'quizzes' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
    }

    /** Questions with their options. Two queries only. */
    public static function get_questions( $quiz_id ) {
        global $wpdb;
        $qt = self::table( 'questions' );
        $ot = self::table( 'options' );

        $questions = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, body, dimension_key FROM {$qt} WHERE quiz_id = %d ORDER BY sort_order ASC, id ASC", $quiz_id ),
            ARRAY_A
        );
        if ( ! $questions ) {
            return [];
        }

        $ids     = implode( ',', array_map( 'intval', wp_list_pluck( $questions, 'id' ) ) );
        $options = $wpdb->get_results(
            "SELECT id, question_id, option_text, score, is_correct FROM {$ot} WHERE question_id IN ({$ids}) ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );

        $grouped = [];
        foreach ( $options as $option ) {
            $grouped[ (int) $option['question_id'] ][] = $option;
        }
        foreach ( $questions as &$question ) {
            $question['options'] = $grouped[ (int) $question['id'] ] ?? [];
        }
        unset( $question );

        return $questions;
    }

    /** Quiz data in the shape that the admin builder needs. */
    public static function get_quiz_for_edit( $id ) {
        $quiz = self::get_quiz( $id );
        if ( ! $quiz ) {
            return null;
        }

        $questions = [];
        foreach ( self::get_questions( $id ) as $q ) {
            $options = [];
            foreach ( $q['options'] as $o ) {
                $options[] = [
                    'text'    => $o['option_text'],
                    'score'   => (int) $o['score'],
                    'correct' => (bool) $o['is_correct'],
                ];
            }
            $questions[] = [
                'body'          => $q['body'],
                'dimension_key' => $q['dimension_key'],
                'options'       => $options,
            ];
        }

        return [
            'id'             => (int) $quiz['id'],
            'title'          => $quiz['title'],
            'description'    => $quiz['description'],
            'scoring_mode'   => $quiz['scoring_mode'],
            'status'         => $quiz['status'],
            'result_note'    => $quiz['result_note'],
            'overall_title'  => $quiz['overall_title'],
            'overall_levels' => self::decode_list( $quiz['overall_levels'] ),
            'dimensions'     => self::decode_list( $quiz['dimensions'] ),
            'questions'      => $questions,
        ];
    }

    /** Save a clean quiz (new or existing). Returns quiz ID or WP_Error. */
    public static function save_quiz( array $d ) {
        global $wpdb;
        $quizzes   = self::table( 'quizzes' );
        $questions = self::table( 'questions' );
        $options   = self::table( 'options' );

        if ( $d['id'] && ! self::get_quiz( $d['id'] ) ) {
            return new WP_Error( 'dt_missing', 'این آزمون دیگر وجود ندارد.' );
        }

        $row = [
            'title'        => $d['title'],
            'description'  => $d['description'],
            'scoring_mode' => $d['scoring_mode'],
            'dimensions'     => wp_json_encode( $d['dimensions'], JSON_UNESCAPED_UNICODE ),
            'overall_title'  => $d['overall_title'],
            'overall_levels' => wp_json_encode( $d['overall_levels'], JSON_UNESCAPED_UNICODE ),
            'result_note'    => $d['result_note'],
            'status'       => $d['status'],
        ];

        $wpdb->query( 'START TRANSACTION' );

        if ( $d['id'] ) {
            $quiz_id = (int) $d['id'];
            $ok      = false !== $wpdb->update( $quizzes, $row, [ 'id' => $quiz_id ] );
            if ( $ok ) {
                $wpdb->query( $wpdb->prepare( "DELETE o FROM {$options} o INNER JOIN {$questions} q ON q.id = o.question_id WHERE q.quiz_id = %d", $quiz_id ) );
                $wpdb->delete( $questions, [ 'quiz_id' => $quiz_id ], [ '%d' ] );
            }
        } else {
            $row['created_at'] = current_time( 'mysql' );
            $ok                = false !== $wpdb->insert( $quizzes, $row );
            $quiz_id           = (int) $wpdb->insert_id;
        }

        if ( $ok ) {
            foreach ( $d['questions'] as $i => $q ) {
                $ok = false !== $wpdb->insert(
                        $questions,
                        [
                            'quiz_id'       => $quiz_id,
                            'body'          => $q['body'],
                            'dimension_key' => $q['dimension_key'],
                            'sort_order'    => $i,
                        ]
                    );
                if ( ! $ok ) {
                    break;
                }
                $question_id = (int) $wpdb->insert_id;

                foreach ( $q['options'] as $j => $o ) {
                    $ok = false !== $wpdb->insert(
                            $options,
                            [
                                'question_id' => $question_id,
                                'option_text' => $o['text'],
                                'score'       => $o['score'],
                                'is_correct'  => $o['correct'] ? 1 : 0,
                                'sort_order'  => $j,
                            ]
                        );
                    if ( ! $ok ) {
                        break 2;
                    }
                }
            }
        }

        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'dt_db', 'ذخیره‌سازی ناموفق بود. دوباره تلاش کنید.' );
        }

        $wpdb->query( 'COMMIT' );
        self::flush_cache();
        return $quiz_id;
    }

    public static function delete_quiz( $id ) {
        global $wpdb;
        $questions = self::table( 'questions' );
        $options   = self::table( 'options' );

        $wpdb->query( $wpdb->prepare( "DELETE o FROM {$options} o INNER JOIN {$questions} q ON q.id = o.question_id WHERE q.quiz_id = %d", $id ) );
        $wpdb->delete( $questions, [ 'quiz_id' => $id ], [ '%d' ] );
        $wpdb->delete( self::table( 'quizzes' ), [ 'id' => $id ], [ '%d' ] );
        self::flush_cache();
    }

    /* ------------------------------------------------------------ Submissions */

    public static function insert_submission( array $data ) {
        global $wpdb;
        $ok = $wpdb->insert(
            self::table( 'submissions' ),
            [
                'quiz_id'    => $data['quiz_id'],
                'quiz_title' => $data['quiz_title'],
                'full_name'  => $data['full_name'],
                'phone'      => $data['phone'],
                'age'        => $data['age'],
                'result'     => $data['result'],
                'answers'    => $data['answers'],
                'created_at' => current_time( 'mysql' ),
            ]
        );
        return false !== $ok;
    }

    private static function search_clause( $search ) {
        global $wpdb;
        if ( '' === $search ) {
            return [ '1=1', [] ];
        }
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        return [ '(full_name LIKE %s OR phone LIKE %s OR quiz_title LIKE %s)', [ $like, $like, $like ] ];
    }

    public static function get_submissions( $search = '', $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table                = self::table( 'submissions' );
        list( $where, $args ) = self::search_clause( $search );
        $args[]               = (int) $limit;
        $args[]               = (int) $offset;

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ),
            ARRAY_A
        );
        return $rows ? $rows : [];
    }

    public static function count_submissions( $search = '' ) {
        global $wpdb;
        $table                = self::table( 'submissions' );
        list( $where, $args ) = self::search_clause( $search );
        $sql                  = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

        return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
    }

    public static function delete_submission( $id ) {
        global $wpdb;
        $wpdb->delete( self::table( 'submissions' ), [ 'id' => $id ], [ '%d' ] );
    }
}