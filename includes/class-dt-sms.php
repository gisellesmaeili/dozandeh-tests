<?php
defined('ABSPATH') || exit;

/**
 * SMS notification for the admin (LimoSMS).
 */
final class DT_Sms
{

    const OPTION = 'dt_sms';
    const LAST = 'dt_sms_last';
    const HOOK = 'dt_send_sms';
    const ENDPOINT = 'https://api.limosms.com/api/sendsms';

    public static function init()
    {
        // The background job (WP-Cron) calls this hook.
        add_action(self::HOOK, [__CLASS__, 'send']);
    }

    /** Saved settings with default values. */
    public static function settings()
    {
        $saved = get_option(self::OPTION, []);

        return wp_parse_args(
            is_array($saved) ? $saved : [],
            [
                'enabled' => 0,
                'api_key' => '',
                'sender' => '',
                'numbers' => '',
            ]
        );
    }

    /** Make a clean list of mobile numbers. Remove wrong and double numbers. */
    public static function parse_numbers($raw)
    {
        $parts = preg_split('/[\s,،;]+/u', dt_to_en_digits((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];

        foreach ($parts as $part) {
            // Change +98912..., 0098912..., and 98912... to 0912...
            if (preg_match('/^(?:\+98|0098|98)(9\d{9})$/', $part, $m)) {
                $part = '0' . $m[1];
            }
            if (preg_match('/^09\d{9}$/', $part)) {
                $out[$part] = $part;
            }
        }

        return array_values($out);
    }

    /** A safe display value for the saved key: 8 dots and the last 4 characters. */
    public static function mask($key)
    {
        $key = (string) $key;
        return '' === $key ? '' : str_repeat('•', 8) . substr($key, -4);
    }

    /** True when the toggle is on and all fields have a value. */
    public static function is_ready()
    {
        $s = self::settings();

        return (bool) $s['enabled']
            && '' !== $s['api_key']
            && '' !== $s['sender']
            && (bool) self::parse_numbers($s['numbers']);
    }

    /** Make the SMS text. Persian SMS: 70 characters for each part. A long text costs more. */
    public static function build_message(array $d)
    {
        $res = $d['result'];
        $lines = [
            'نتیجه آزمون جدید',
            'آزمون: ' . $d['quiz_title'],
            'نام: ' . $d['name'],
            'موبایل: ' . $d['phone'],
        ];

        if ($d['age']) {
            $lines[] = 'سن: ' . $d['age'];
        }
        if (!empty($res['overall']['label'])) {
            $lines[] = 'نتیجه کلی: ' . $res['overall']['label'];
        }
        foreach ($res['dimensions'] as $dim) {
            $level = !empty($dim['label']) ? $dim['label'] . ' ' : '';
            $lines[] = $dim['name'] . ': ' . $level . '(' . $dim['percent'] . '%)';
        }
        if (!empty($res['flags'])) {
            $lines[] = 'نیازمند توجه: ' . implode('، ', wp_list_pluck($res['flags'], 'name'));
        }

        $text = apply_filters('dt_sms_message', implode("\n", $lines), $d);

        return mb_substr($text, 0, 500);
    }

    /** Queue the SMS. This function is fast. The user does not wait for the API. */
    public static function notify(array $data)
    {
        if (!self::is_ready()) {
            return;
        }

        $message = self::build_message($data);

        // No WP-Cron on this site: send now.
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            self::send($message);
            return;
        }

        // The second argument makes each event unique. WordPress drops equal events.
        wp_schedule_single_event(time(), self::HOOK, [$message, uniqid('', true)]);
        spawn_cron();
    }

    /** Send one SMS to all admin numbers. Return true on success. */
    public static function send($message)
    {
        $s = self::settings();
        $numbers = self::parse_numbers($s['numbers']);

        if ('' === $s['api_key'] || '' === $s['sender'] || !$numbers) {
            return self::log(false, 'تنظیمات پیامک کامل نیست.');
        }

        $response = wp_remote_post(
            self::ENDPOINT,
            [
                'timeout' => 15,
                'redirection' => 0, // A redirect can change POST to GET. Show it as an error instead.
                'headers' => [
                    'Content-Type' => 'application/json',
                    'ApiKey' => $s['api_key'],
                ],
                'body' => wp_json_encode(
                    [
                        'Message' => (string) $message,
                        'SenderNumber' => $s['sender'],
                        'MobileNumber' => $numbers,
                    ],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        if (is_wp_error($response)) {
            return self::log(false, $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) wp_remote_retrieve_body($response))); // Remove a BOM.

        // Read the keys in any letter case: "Success", "success", "SUCCESS".
        $data = json_decode($body, true);
        $data = is_array($data) ? array_change_key_case($data, CASE_LOWER) : [];

        $ok = isset($data['success']) && filter_var($data['success'], FILTER_VALIDATE_BOOLEAN);
        $text = isset($data['message']) && is_scalar($data['message']) ? trim((string) $data['message']) : '';

        if ('' === $text) {
            // No message in the answer. Save the HTTP code and the start of the raw answer.
            $text = 'HTTP ' . $code;
            if ($code >= 300 && $code < 400) {
                $text .= ' — redirect to: ' . wp_remote_retrieve_header($response, 'location');
            } else {
                $text .= '' !== $body ? ' — ' . mb_substr($body, 0, 200) : ' — پاسخ خالی';
            }
        }

        return self::log($ok, $text);
    }
    
    /** Save the result of the last SMS. The API key is never saved here. */
    private static function log($ok, $text)
    {
        update_option(
            self::LAST,
            [
                'time' => current_time('mysql'),
                'ok' => (bool) $ok,
                'message' => mb_substr(wp_strip_all_tags($text), 0, 300),
            ],
            false
        );

        return (bool) $ok;
    }
}