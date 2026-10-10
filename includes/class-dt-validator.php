<?php
defined( 'ABSPATH' ) || exit;

final class DT_Validator {

    private static function key( $value ) {
        return substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $value ) ), 0, 20 );
    }

    /** Clean a list of score ranges. Return a WP_Error if the data is not correct. */
    private static function levels( $raw, $where ) {
        $levels = [];

        foreach ( (array) $raw as $lvl ) {
            if ( ! is_array( $lvl ) ) {
                continue;
            }
            $label = sanitize_text_field( $lvl['label'] ?? '' );
            if ( '' === $label ) {
                return new WP_Error( 'dt_level', "نام همه سطح‌های {$where} را وارد کنید." );
            }

            $min = max( 0, (int) ( $lvl['min'] ?? 0 ) );
            $max = max( 0, (int) ( $lvl['max'] ?? 0 ) );
            if ( $min > $max ) {
                return new WP_Error( 'dt_level', "در {$where}، حداقل امتیاز سطح «{$label}» از حداکثر آن بزرگ‌تر است." );
            }

            $levels[] = [
                'min'         => $min,
                'max'         => $max,
                'label'       => mb_substr( $label, 0, 60 ),
                'description' => sanitize_textarea_field( $lvl['description'] ?? '' ),
            ];
        }

        usort(
            $levels,
            static function ( $a, $b ) {
                return $a['min'] <=> $b['min'];
            }
        );

        return $levels;
    }

    /** @return array|WP_Error */
    public static function quiz( $input ) {
        if ( ! is_array( $input ) ) {
            return new WP_Error( 'dt_invalid', 'داده‌های ارسالی نامعتبر است.' );
        }

        $title = sanitize_text_field( $input['title'] ?? '' );
        if ( '' === $title ) {
            return new WP_Error( 'dt_title', 'نام آزمون را وارد کنید.' );
        }

        $mode   = ( $input['scoring_mode'] ?? '' ) === 'correct' ? 'correct' : 'score';
        $status = ( $input['status'] ?? '' ) === 'draft' ? 'draft' : 'publish';

        // Dimensions (the components that the test measures).
        $dimensions = [];
        $keys       = [];
        foreach ( (array) ( $input['dimensions'] ?? [] ) as $dim ) {
            $key  = is_array( $dim ) ? self::key( $dim['key'] ?? '' ) : '';
            $name = is_array( $dim ) ? sanitize_text_field( $dim['name'] ?? '' ) : '';
            if ( '' === $key || '' === $name || isset( $keys[ $key ] ) ) {
                return new WP_Error( 'dt_dim', 'نام همه مؤلفه‌ها را وارد کنید.' );
            }

            $levels = self::levels( $dim['levels'] ?? [], "مؤلفه «{$name}»" );
            if ( is_wp_error( $levels ) ) {
                return $levels;
            }

            $flagged   = ! empty( $dim['flagged'] );
            $flag_text = sanitize_textarea_field( $dim['flag_text'] ?? '' );
            if ( $flagged && ( '' === $flag_text || ! $levels ) ) {
                return new WP_Error( 'dt_flag', "برای مؤلفه «{$name}» که علامت‌گذاری شده، سطح‌ها و متن هشدار را وارد کنید." );
            }

            $keys[ $key ] = true;
            $dimensions[] = [
                'key'       => $key,
                'name'      => $name,
                'levels'    => $levels,
                'flagged'   => $flagged,
                'flag_text' => $flag_text,
            ];
        }
        if ( ! $dimensions ) {
            return new WP_Error( 'dt_dim', 'حداقل یک مؤلفه لازم است.' );
        }

        $overall_levels = self::levels( $input['overall_levels'] ?? [], 'نتیجه کلی' );
        if ( is_wp_error( $overall_levels ) ) {
            return $overall_levels;
        }

        // Questions and options.
        $questions = [];
        foreach ( array_values( (array) ( $input['questions'] ?? [] ) ) as $i => $q ) {
            $n    = $i + 1;
            $body = is_array( $q ) ? sanitize_textarea_field( $q['body'] ?? '' ) : '';
            $key  = is_array( $q ) ? self::key( $q['dimension_key'] ?? '' ) : '';

            if ( '' === $body ) {
                return new WP_Error( 'dt_q', "متن سؤال {$n} را وارد کنید." );
            }
            if ( ! isset( $keys[ $key ] ) ) {
                return new WP_Error( 'dt_q', "برای سؤال {$n} یک مؤلفه انتخاب کنید." );
            }

            $options = [];
            $correct = 0;
            foreach ( (array) ( $q['options'] ?? [] ) as $o ) {
                $text = is_array( $o ) ? sanitize_text_field( $o['text'] ?? '' ) : '';
                if ( '' === $text ) {
                    return new WP_Error( 'dt_o', "متن همه گزینه‌های سؤال {$n} را وارد کنید." );
                }
                $is_correct = ! empty( $o['correct'] );
                $correct   += $is_correct ? 1 : 0;
                $options[]  = [
                    'text'    => mb_substr( $text, 0, 500 ),
                    'score'   => max( 0, min( 1000, (int) ( $o['score'] ?? 0 ) ) ),
                    'correct' => $is_correct,
                ];
            }

            if ( count( $options ) < 2 ) {
                return new WP_Error( 'dt_o', "سؤال {$n} باید حداقل دو گزینه داشته باشد." );
            }
            if ( 'correct' === $mode && 1 !== $correct ) {
                return new WP_Error( 'dt_o', "برای سؤال {$n} دقیقاً یک گزینه صحیح انتخاب کنید." );
            }

            $questions[] = [ 'body' => $body, 'dimension_key' => $key, 'options' => $options ];
        }
        if ( ! $questions ) {
            return new WP_Error( 'dt_q', 'حداقل یک سؤال لازم است.' );
        }

        return [
            'id'             => absint( $input['id'] ?? 0 ),
            'title'          => mb_substr( $title, 0, 190 ),
            'description'    => sanitize_textarea_field( $input['description'] ?? '' ),
            'scoring_mode'   => $mode,
            'status'         => $status,
            'result_note'    => sanitize_textarea_field( $input['result_note'] ?? '' ),
            'overall_title'  => mb_substr( sanitize_text_field( $input['overall_title'] ?? '' ), 0, 190 ),
            'overall_levels' => $overall_levels,
            'dimensions'     => $dimensions,
            'questions'      => $questions,
        ];
    }
}