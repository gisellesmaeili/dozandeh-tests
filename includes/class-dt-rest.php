<?php
defined( 'ABSPATH' ) || exit;

final class DT_Rest {

    const NS = 'dozandeh-tests/v1';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route(
            self::NS,
            '/quizzes/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_quiz' ],
                'permission_callback' => '__return_true',
            ]
        );
        register_rest_route(
            self::NS,
            '/quizzes/(?P<id>\d+)/submit',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'submit' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /** Public data of one quiz. No scores. No correct answers. */
    public static function get_quiz( WP_REST_Request $request ) {
        $quiz = DT_Repo::get_quiz( (int) $request['id'] );
        if ( ! $quiz || 'publish' !== $quiz['status'] ) {
            return new WP_Error( 'dt_not_found', 'آزمون پیدا نشد.', [ 'status' => 404 ] );
        }

        $questions = [];
        foreach ( DT_Repo::get_questions( (int) $quiz['id'] ) as $q ) {
            $options = [];
            foreach ( $q['options'] as $o ) {
                $options[] = [ 'id' => (int) $o['id'], 'text' => $o['option_text'] ];
            }
            $questions[] = [ 'id' => (int) $q['id'], 'body' => $q['body'], 'options' => $options ];
        }

        return rest_ensure_response(
            [
                'id'        => (int) $quiz['id'],
                'title'     => $quiz['title'],
                'questions' => $questions,
            ]
        );
    }

    public static function submit( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $params = is_array( $params ) ? $params : [];

        // Honeypot: a real user never fills this field.
        if ( ! empty( $params['website'] ) ) {
            return new WP_Error( 'dt_spam', 'درخواست نامعتبر است.', [ 'status' => 400 ] );
        }

        // Rate limit: 10 successful submissions per hour for each IP address.
        $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $key  = 'dt_rl_' . md5( $ip );
        $hits = (int) get_transient( $key );
        if ( $hits >= 10 ) {
            return new WP_Error( 'dt_rate', 'تعداد درخواست‌ها زیاد است. بعداً دوباره تلاش کنید.', [ 'status' => 429 ] );
        }

        $name  = sanitize_text_field( (string) ( $params['name'] ?? '' ) );
        $phone = dt_to_en_digits( sanitize_text_field( (string) ( $params['phone'] ?? '' ) ) );
        $age   = absint( dt_to_en_digits( (string) ( $params['age'] ?? '' ) ) );

        if ( mb_strlen( $name ) < 2 ) {
            return new WP_Error( 'dt_name', 'نام و نام خانوادگی را وارد کنید.', [ 'status' => 422 ] );
        }
        if ( ! preg_match( '/^09\d{9}$/', $phone ) ) {
            return new WP_Error( 'dt_phone', 'شماره موبایل معتبر نیست. مثال: 09123456789', [ 'status' => 422 ] );
        }
        if ( $age && ( $age < 5 || $age > 100 ) ) {
            return new WP_Error( 'dt_age', 'سن واردشده معتبر نیست.', [ 'status' => 422 ] );
        }
        if ( empty( $params['consent'] ) ) {
            return new WP_Error( 'dt_consent', 'برای مشاهده نتیجه، تأیید ذخیره اطلاعات لازم است.', [ 'status' => 422 ] );
        }

        $quiz = DT_Repo::get_quiz( (int) $request['id'] );
        if ( ! $quiz || 'publish' !== $quiz['status'] ) {
            return new WP_Error( 'dt_not_found', 'آزمون پیدا نشد.', [ 'status' => 404 ] );
        }

        $answers = isset( $params['answers'] ) && is_array( $params['answers'] ) ? $params['answers'] : [];
        $result  = self::calculate( $quiz, DT_Repo::get_questions( (int) $quiz['id'] ), $answers );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // "details" is a copy of each question and answer. Only the admin sees it.
        $details = $result['details'];
        unset( $result['details'] );

        $stored = [
            'overall'    => $result['overall'] ? array_diff_key( $result['overall'], [ 'zones' => 1 ] ) : null,
            'dimensions' => array_map(
                static function ( $d ) {
                    unset( $d['zones'] );
                    return $d;
                },
                $result['dimensions']
            ),
            'flags'      => $result['flags'],
        ];

        $saved = DT_Repo::insert_submission(
            [
                'quiz_id'    => (int) $quiz['id'],
                'quiz_title' => $quiz['title'],
                'full_name'  => mb_substr( $name, 0, 120 ),
                'phone'      => $phone,
                'age'        => min( 100, $age ),
                'result'     => wp_json_encode( $stored, JSON_UNESCAPED_UNICODE ),
                'answers'    => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ),
            ]
        );
        if ( ! $saved ) {
            return new WP_Error( 'dt_db', 'ثبت اطلاعات ناموفق بود. دوباره تلاش کنید.', [ 'status' => 500 ] );
        }

        // Send the SMS to the admin. This call only puts the SMS in a queue.
        DT_Sms::notify(
            [
                'quiz_title' => $quiz['title'],
                'name' => mb_substr($name, 0, 120),
                'phone' => $phone,
                'age' => min(100, $age),
                'result' => $stored,
            ]
        );

        set_transient( $key, $hits + 1, HOUR_IN_SECONDS );

        return rest_ensure_response( [ 'result' => $result ] );
    }

    /** Calculate scores, levels, and flags. */
    private static function calculate( array $quiz, array $questions, array $answers ) {
        $totals = [];
        foreach ( (array) json_decode( $quiz['dimensions'], true ) as $d ) {
            $totals[ $d['key'] ] = [
                'name'      => $d['name'],
                'levels'    => $d['levels'] ?? [],
                'flagged'   => ! empty( $d['flagged'] ),
                'flag_text' => $d['flag_text'] ?? '',
                'earned'    => 0,
                'max'       => 0,
            ];
        }

        $details = [];
        foreach ( $questions as $q ) {
            $options = [];
            foreach ( $q['options'] as $o ) {
                $options[ (int) $o['id'] ] = $o;
            }

            $picked = isset( $answers[ $q['id'] ] ) ? (int) $answers[ $q['id'] ] : 0;
            if ( ! isset( $options[ $picked ] ) ) {
                return new WP_Error( 'dt_answers', 'لطفاً به همه سؤال‌ها پاسخ دهید.', [ 'status' => 422 ] );
            }
            if ( ! isset( $totals[ $q['dimension_key'] ] ) ) {
                continue;
            }

            if ( 'correct' === $quiz['scoring_mode'] ) {
                $max    = 1;
                $earned = (int) $options[ $picked ]['is_correct'];
            } else {
                $max    = max( 0, ...array_map( 'intval', wp_list_pluck( $q['options'], 'score' ) ) );
                $earned = (int) $options[ $picked ]['score'];
            }

            $totals[ $q['dimension_key'] ]['max']    += $max;
            $totals[ $q['dimension_key'] ]['earned'] += $earned;

            $details[] = [
                'q'      => $q['body'],
                'a'      => $options[ $picked ]['option_text'],
                'dim'    => $totals[ $q['dimension_key'] ]['name'],
                'points' => $earned,
                'max'    => $max,
            ];
        }

        $out          = [];
        $flags        = [];
        $total_earned = 0;
        $total_max    = 0;

        foreach ( $totals as $t ) {
            if ( $t['max'] < 1 ) {
                continue;
            }

            $levels = $t['levels'] ? $t['levels'] : self::default_levels( $t['max'] );
            $info   = self::resolve_level( $levels, $t['earned'] );

            $out[] = [
                'name'        => $t['name'],
                'earned'      => $t['earned'],
                'max'         => $t['max'],
                'percent'     => (int) round( $t['earned'] / $t['max'] * 100 ),
                'label'       => $info['label'],
                'description' => $info['description'],
                'index'       => $info['index'],
                'zones'       => self::zones( $levels ),
            ];

            // Show the flag text only when the score is in the highest level.
            if ( $t['flagged'] && $t['levels'] && $info['index'] === count( $levels ) - 1 ) {
                $flags[] = [ 'name' => $t['name'], 'text' => $t['flag_text'] ];
            }

            $total_earned += $t['earned'];
            $total_max    += $t['max'];
        }

        $overall        = null;
        $overall_levels = DT_Repo::decode_list( $quiz['overall_levels'] );
        if ( $overall_levels ) {
            $info    = self::resolve_level( $overall_levels, $total_earned );
            $overall = [
                'earned'      => $total_earned,
                'max'         => $total_max,
                'label'       => $info['label'],
                'description' => $info['description'],
                'index'       => $info['index'],
                'zones'       => self::zones( $overall_levels ),
            ];
        }

        return [
            'title'         => $quiz['title'],
            'overall_title' => $quiz['overall_title'] ? $quiz['overall_title'] : $quiz['title'],
            'note'          => $quiz['result_note'],
            'overall'       => $overall,
            'dimensions'    => $out,
            'flags'         => $flags,
            'details'       => $details,
        ];
    }

    /** Find the level of a score. The first matching level wins. */
    private static function resolve_level( array $levels, $score ) {
        foreach ( $levels as $i => $l ) {
            if ( $score >= $l['min'] && $score <= $l['max'] ) {
                return [ 'label' => $l['label'], 'description' => $l['description'], 'index' => $i ];
            }
        }
        // The score is outside all ranges. Use the nearest end.
        $last = count( $levels ) - 1;
        $i    = $score > $levels[ $last ]['max'] ? $last : 0;
        return [ 'label' => $levels[ $i ]['label'], 'description' => $levels[ $i ]['description'], 'index' => $i ];
    }

    /** Zones draw the colored parts of the bar on the front end. */
    private static function zones( array $levels ) {
        $zones = [];
        $count = count( $levels );

        foreach ( $levels as $i => $l ) {
            $zones[] = [
                'label' => $l['label'],
                'from'  => 0 === $i ? 0 : (int) $l['min'],
                'to'    => $i + 1 < $count ? (int) $levels[ $i + 1 ]['min'] : (int) $l['max'],
            ];
        }
        return $zones;
    }

    /** Five equal levels. The plugin uses them when the admin defines no levels. */
    private static function default_levels( $max ) {
        $labels = [ 'خیلی کم', 'کم', 'متوسط', 'زیاد', 'خیلی زیاد' ];
        $levels = [];

        foreach ( $labels as $i => $label ) {
            $levels[] = [
                'min'         => (int) round( $max * $i / 5 ),
                'max'         => (int) round( $max * ( $i + 1 ) / 5 ),
                'label'       => $label,
                'description' => '',
            ];
        }
        return $levels;
    }
}