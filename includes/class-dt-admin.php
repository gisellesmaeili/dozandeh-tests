<?php
defined( 'ABSPATH' ) || exit;

final class DT_Admin {

    const CAP = 'manage_options';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'admin_post_dt_export_csv', [ __CLASS__, 'export_csv' ] );
        add_action( 'admin_post_dt_delete_submission', [ __CLASS__, 'delete_submission' ] );
        add_action( 'admin_post_dt_delete_quiz', [ __CLASS__, 'delete_quiz' ] );
        add_action( 'wp_ajax_dt_save_quiz', [ __CLASS__, 'ajax_save_quiz' ] );
        add_action( 'wp_ajax_dt_toggle_quiz', [ __CLASS__, 'ajax_toggle_quiz' ] );
        add_action('admin_post_dt_save_sms', [__CLASS__, 'save_sms']);
        add_action('admin_post_dt_test_sms', [__CLASS__, 'test_sms']);
    }

    public static function menu() {
        add_menu_page( 'آزمون‌های روانشناسی', 'آزمون‌ها', self::CAP, 'dt-users', [ __CLASS__, 'page_users' ], 'dashicons-clipboard', 58 );
        add_submenu_page( 'dt-users', 'کاربران آزمون‌ها', 'کاربران', self::CAP, 'dt-users', [ __CLASS__, 'page_users' ] );
        add_submenu_page( 'dt-users', 'مدیریت آزمون‌ها', 'مدیریت آزمون‌ها', self::CAP, 'dt-quizzes', [ __CLASS__, 'page_quizzes' ] );
        add_submenu_page('dt-users', 'تنظیمات پیامک', 'تنظیمات پیامک', self::CAP, 'dt-sms', [__CLASS__, 'page_sms']);
    }

    public static function assets() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
        if (!in_array($page, ['dt-users', 'dt-quizzes', 'dt-sms'], true)) {
            return;
        }
        wp_enqueue_style( 'dt-admin', DT_URL . 'assets/css/admin.css', [], DT_VERSION );
        wp_enqueue_script( 'dt-admin', DT_URL . 'assets/js/admin.js', [], DT_VERSION, true );
        wp_localize_script(
            'dt-admin',
            'DTAdmin',
            [
                'ajax'  => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'dt_save_quiz' ),
            ]
        );
    }

    /* ---------------------------------------------------------------- Helpers */

    private static function guard( $action ) {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'شما دسترسی لازم را ندارید.', '', [ 'response' => 403 ] );
        }
        check_admin_referer( $action );
    }

    private static function search_term() {
        return isset( $_GET['s'] ) ? dt_to_en_digits( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore
    }

    private static function result_text( $json ) {
        $res   = DT_Repo::parse_result( $json );
        $parts = [];

        if ( ! empty( $res['overall']['label'] ) ) {
            $parts[] = 'نتیجه کلی: ' . $res['overall']['label'];
        }
        foreach ( $res['dimensions'] as $d ) {
            $parts[] = $d['name'] . ': ' . ( $d['label'] ?? '' ) . ' (' . $d['percent'] . '%)';
        }
        if ( $res['flags'] ) {
            $parts[] = 'نیازمند توجه';
        }
        return implode( ' | ', $parts );
    }

    private static function csv_cell( $value ) {
        $value = (string) $value;
        return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value; // Stop CSV formula injection.
    }

    /* ------------------------------------------------------------ Users page */

    public static function page_users() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'شما دسترسی لازم را ندارید.' );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore
        if ( 'view' === $action ) {
            self::render_detail( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore
            return;
        }

        $search = self::search_term();
        $per    = 20;
        $paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
        $total  = DT_Repo::count_submissions( $search );
        $rows   = DT_Repo::get_submissions( $search, $per, ( $paged - 1 ) * $per );
        $export = wp_nonce_url( add_query_arg( [ 'action' => 'dt_export_csv', 's' => $search ], admin_url( 'admin-post.php' ) ), 'dt_export' );
        $pages  = (int) ceil( $total / $per );
        ?>
        <div class="dt-admin" dir="rtl">
            <div class="dt-a-head">
                <div>
                    <h1 class="dt-a-title">کاربران آزمون‌ها</h1>
                    <p class="dt-a-sub"><?php echo esc_html( dt_to_fa_digits( $total ) ); ?> نتیجه ثبت شده است.</p>
                </div>
                <a class="dt-a-btn dt-a-btn--primary" href="<?php echo esc_url( $export ); ?>">دریافت خروجی CSV</a>
            </div>

            <form method="get" class="dt-a-toolbar">
                <input type="hidden" name="page" value="dt-users">
                <input type="search" name="s" class="dt-a-input" value="<?php echo esc_attr( $search ); ?>" placeholder="جستجو بر اساس نام، موبایل یا آزمون…">
                <button type="submit" class="dt-a-btn dt-a-btn--outline">جستجو</button>
                <?php if ( '' !== $search ) : ?>
                    <a class="dt-a-btn dt-a-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=dt-users' ) ); ?>">پاک کردن</a>
                <?php endif; ?>
            </form>

            <div class="dt-a-card">
                <div class="dt-a-list">
                    <div class="dt-a-row dt-a-row--head">
                        <span class="dt-a-cell dt-a-cell--grow">نام</span>
                        <span class="dt-a-cell">موبایل</span>
                        <span class="dt-a-cell dt-a-cell--narrow">سن</span>
                        <span class="dt-a-cell dt-a-cell--grow">آزمون</span>
                        <span class="dt-a-cell dt-a-cell--grow">نتیجه</span>
                        <span class="dt-a-cell">تاریخ</span>
                        <span class="dt-a-cell dt-a-cell--detail">جزئیات</span>
                        <span class="dt-a-cell dt-a-cell--act"></span>
                    </div>

                    <?php if ( ! $rows ) : ?>
                        <div class="dt-a-empty">موردی پیدا نشد.</div>
                    <?php endif; ?>

                    <?php foreach ( $rows as $r ) : ?>
                        <?php
                        $res     = DT_Repo::parse_result( $r['result'] );
                        $overall = $res['overall'];
                        $view    = admin_url( 'admin.php?page=dt-users&action=view&id=' . (int) $r['id'] );
                        $del     = wp_nonce_url( admin_url( 'admin-post.php?action=dt_delete_submission&id=' . (int) $r['id'] ), 'dt_del_sub_' . (int) $r['id'] );
                        ?>
                        <div class="dt-a-row">
                            <div class="dt-a-cell dt-a-cell--grow" data-label="نام"><strong><?php echo esc_html( $r['full_name'] ); ?></strong></div>
                            <div class="dt-a-cell" data-label="موبایل"><a href="tel:<?php echo esc_attr( $r['phone'] ); ?>" dir="ltr"><?php echo esc_html( $r['phone'] ); ?></a></div>
                            <div class="dt-a-cell dt-a-cell--narrow" data-label="سن"><?php echo $r['age'] ? esc_html( dt_to_fa_digits( $r['age'] ) ) : '—'; ?></div>
                            <div class="dt-a-cell dt-a-cell--grow" data-label="آزمون"><?php echo esc_html( $r['quiz_title'] ); ?></div>
                            <div class="dt-a-cell dt-a-cell--grow dt-a-badges" data-label="نتیجه">
                                <?php if ( $overall && ! empty( $overall['label'] ) ) : ?>
                                    <span class="dt-a-badge is-ok"><?php echo esc_html( $overall['label'] ); ?></span>
                                <?php else : ?>
                                    <span>—</span>
                                <?php endif; ?>
                                <?php if ( $res['flags'] ) : ?>
                                    <span class="dt-a-badge is-flag">نیازمند توجه</span>
                                <?php endif; ?>
                            </div>
                            <div class="dt-a-cell" data-label="تاریخ"><?php echo esc_html( mysql2date( 'Y/m/d H:i', $r['created_at'] ) ); ?></div>
                            <div class="dt-a-cell dt-a-cell--detail">
                                <a class="dt-a-btn dt-a-btn--outline dt-a-btn--sm" href="<?php echo esc_url( $view ); ?>">مشاهده جزئیات</a>
                            </div>
                            <div class="dt-a-cell dt-a-cell--act">
                                <a class="dt-a-btn dt-a-btn--ghost dt-a-btn--sm dt-a-danger" href="<?php echo esc_url( $del ); ?>" data-confirm="این مورد حذف شود؟">حذف</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php
            if ( $pages > 1 ) {
                $links = paginate_links(
                        [
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'current'   => $paged,
                                'total'     => $pages,
                                'type'      => 'array',
                                'prev_text' => 'قبلی',
                                'next_text' => 'بعدی',
                        ]
                );
                echo '<nav class="dt-a-pager">' . wp_kses_post( implode( '', (array) $links ) ) . '</nav>';
            }
            ?>
        </div>
        <?php
    }

    /** The detail page of one submission. */
    private static function render_detail( $id ) {
        $r = DT_Repo::get_submission( $id );
        if ( ! $r ) {
            wp_die( 'این مورد پیدا نشد.' );
        }

        $res     = DT_Repo::parse_result( $r['result'] );
        $overall = $res['overall'];
        $answers = DT_Repo::decode_list( $r['answers'] );
        $back    = admin_url( 'admin.php?page=dt-users' );
        ?>
        <div class="dt-admin" dir="rtl">
            <div class="dt-a-head">
                <div>
                    <h1 class="dt-a-title">جزئیات نتیجه</h1>
                    <p class="dt-a-sub"><?php echo esc_html( $r['quiz_title'] ); ?></p>
                </div>
                <a class="dt-a-btn dt-a-btn--outline" href="<?php echo esc_url( $back ); ?>">بازگشت به کاربران</a>
            </div>

            <section class="dt-a-card dt-a-pad">
                <h2 class="dt-a-h2">اطلاعات کاربر</h2>
                <div class="dt-a-info">
                    <div><small>نام</small><strong><?php echo esc_html( $r['full_name'] ); ?></strong></div>
                    <div><small>موبایل</small><strong dir="ltr"><?php echo esc_html( $r['phone'] ); ?></strong></div>
                    <div><small>سن</small><strong><?php echo $r['age'] ? esc_html( dt_to_fa_digits( $r['age'] ) ) : '—'; ?></strong></div>
                    <div><small>تاریخ</small><strong><?php echo esc_html( mysql2date( 'Y/m/d H:i', $r['created_at'] ) ); ?></strong></div>
                    <div>
                        <small>نتیجه کلی</small>
                        <strong>
                            <?php
                            echo $overall && ! empty( $overall['label'] )
                                    ? esc_html( $overall['label'] . ' (' . dt_to_fa_digits( $overall['earned'] ) . ' از ' . dt_to_fa_digits( $overall['max'] ) . ')' )
                                    : '—';
                            ?>
                        </strong>
                    </div>
                </div>
            </section>

            <?php if ( $res['flags'] ) : ?>
                <div class="dt-a-alert">
                    <strong>این کاربر نیازمند توجه است:</strong>
                    <?php foreach ( $res['flags'] as $f ) : ?>
                        <p><b><?php echo esc_html( $f['name'] ); ?>:</b> <?php echo esc_html( $f['text'] ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="dt-a-card dt-a-pad">
                <h2 class="dt-a-h2">نتیجه مؤلفه‌ها</h2>
                <div class="dt-a-list">
                    <div class="dt-a-row dt-a-row--head">
                        <span class="dt-a-cell dt-a-cell--grow">مؤلفه</span>
                        <span class="dt-a-cell">امتیاز</span>
                        <span class="dt-a-cell">درصد</span>
                        <span class="dt-a-cell">سطح</span>
                    </div>
                    <?php foreach ( $res['dimensions'] as $d ) : ?>
                        <div class="dt-a-row">
                            <div class="dt-a-cell dt-a-cell--grow" data-label="مؤلفه"><strong><?php echo esc_html( $d['name'] ); ?></strong></div>
                            <div class="dt-a-cell" data-label="امتیاز"><?php echo esc_html( dt_to_fa_digits( (int) $d['earned'] ) . ' از ' . dt_to_fa_digits( (int) $d['max'] ) ); ?></div>
                            <div class="dt-a-cell" data-label="درصد"><?php echo esc_html( dt_to_fa_digits( (int) $d['percent'] ) ); ?>٪</div>
                            <div class="dt-a-cell" data-label="سطح"><?php echo ! empty( $d['label'] ) ? esc_html( $d['label'] ) : '—'; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dt-a-card dt-a-pad">
                <h2 class="dt-a-h2">پاسخ‌های کاربر</h2>
                <?php if ( ! $answers ) : ?>
                    <p class="dt-a-hint">پاسخ‌های این مورد ذخیره نشده است. این مورد قبل از به‌روزرسانی افزونه ثبت شده است.</p>
                <?php else : ?>
                    <div class="dt-a-list">
                        <div class="dt-a-row dt-a-row--head">
                            <span class="dt-a-cell dt-a-cell--num">#</span>
                            <span class="dt-a-cell dt-a-cell--q">سؤال</span>
                            <span class="dt-a-cell dt-a-cell--grow">پاسخ</span>
                            <span class="dt-a-cell">مؤلفه</span>
                            <span class="dt-a-cell dt-a-cell--narrow">امتیاز</span>
                        </div>
                        <?php foreach ( $answers as $i => $a ) : ?>
                            <div class="dt-a-row">
                                <div class="dt-a-cell dt-a-cell--num"><?php echo esc_html( dt_to_fa_digits( $i + 1 ) ); ?></div>
                                <div class="dt-a-cell dt-a-cell--q" data-label="سؤال"><?php echo esc_html( $a['q'] ?? '' ); ?></div>
                                <div class="dt-a-cell dt-a-cell--grow" data-label="پاسخ"><strong><?php echo esc_html( $a['a'] ?? '' ); ?></strong></div>
                                <div class="dt-a-cell" data-label="مؤلفه"><?php echo esc_html( $a['dim'] ?? '' ); ?></div>
                                <div class="dt-a-cell dt-a-cell--narrow" data-label="امتیاز"><?php echo esc_html( dt_to_fa_digits( (int) ( $a['points'] ?? 0 ) ) . '/' . dt_to_fa_digits( (int) ( $a['max'] ?? 0 ) ) ); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public static function delete_submission() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
        self::guard( 'dt_del_sub_' . $id );
        DT_Repo::delete_submission( $id );
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=dt-users' ) );
        exit;
    }

    public static function export_csv() {
        self::guard( 'dt_export' );
        $search = self::search_term();

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="dozandeh-users-' . gmdate( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM. Excel then reads Farsi text correctly.
        fputcsv( $out, [ 'نام و نام خانوادگی', 'موبایل', 'سن', 'آزمون', 'نتیجه', 'تاریخ' ], ',', '"', '' );

        $offset = 0;
        do {
            $rows = DT_Repo::get_submissions( $search, 500, $offset );
            foreach ( $rows as $r ) {
                fputcsv(
                    $out,
                    [
                        self::csv_cell( $r['full_name'] ),
                        "\t" . $r['phone'], // The tab keeps the leading zero in Excel.
                        $r['age'] ? $r['age'] : '',
                        self::csv_cell( $r['quiz_title'] ),
                        self::csv_cell( self::result_text( $r['result'] ) ),
                        $r['created_at'],
                    ],
                    ',',
                    '"',
                    ''
                );
            }
            $offset += 500;
        } while ( 500 === count( $rows ) );

        fclose( $out );
        exit;
    }

    	/* -------------------------------------------------------------- SMS page */

	public static function page_sms() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'شما دسترسی لازم را ندارید.' );
		}

		$s       = DT_Sms::settings();
		$last    = get_option( DT_Sms::LAST );
		$code    = isset( $_GET['dt_msg'] ) ? sanitize_key( wp_unslash( $_GET['dt_msg'] ) ) : ''; // phpcs:ignore
		$notices = [
			'saved'     => [ 'success', 'تنظیمات ذخیره شد.' ],
			'missing'   => [ 'error', 'تنظیمات ذخیره شد، اما ارسال پیامک فعال نشد. کلید API، شماره خط و حداقل یک شماره موبایل معتبر لازم است.' ],
			'test_ok'   => [ 'success', 'پیامک آزمایشی ارسال شد.' ],
			'test_fail' => [ 'error', 'ارسال پیامک آزمایشی ناموفق بود. توضیح پنل در بخش «آخرین ارسال» آمده است.' ],
		];
		$has_key = '' !== $s['api_key'];
		$ready   = $has_key && '' !== $s['sender'] && DT_Sms::parse_numbers( $s['numbers'] );
		?>
		<div class="dt-admin" dir="rtl">
			<div class="dt-a-head">
				<div>
					<h1 class="dt-a-title">تنظیمات پیامک</h1>
					<p class="dt-a-sub">اطلاعات هر شرکت‌کننده پس از ثبت فرم، با پیامک برای مدیر ارسال می‌شود.</p>
				</div>
			</div>

			<?php if ( isset( $notices[ $code ] ) ) : ?>
				<div class="dt-a-msg is-<?php echo esc_attr( $notices[ $code ][0] ); ?>"><?php echo esc_html( $notices[ $code ][1] ); ?></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dt_save_sms">
				<?php wp_nonce_field( 'dt_save_sms' ); ?>

				<section class="dt-a-card dt-a-pad">
					<h2 class="dt-a-h2">دریافت پیامک</h2>
					<p class="dt-a-hint">با روشن کردن این گزینه، فیلدهای اتصال به پنل پیامک نمایش داده می‌شوند.</p>

					<label class="dt-a-switch dt-a-flagrow">
						<input type="checkbox" role="switch" name="enabled" value="1" data-sms-toggle <?php checked( 1, (int) $s['enabled'] ); ?>>
						<span class="dt-a-switch__track"></span>
						<span class="dt-a-switch__text">دریافت اطلاعات شرکت کنندگان از طریق اس ام اس</span>
					</label>

					<div id="dt-sms-fields" <?php echo $s['enabled'] ? '' : 'hidden'; ?>>
                        <div class="dt-a-field">
							<label for="dt-sms-key">کلید دسترسی (API Key) پنل لیمو اس‌ام‌اس</label>
							<input type="text" id="dt-sms-key" name="api_key" class="dt-a-input dt-a-ltr" autocomplete="off" spellcheck="false" value="<?php echo esc_attr(DT_Sms::mask($s['api_key'])); ?>">
                            <?php if ($has_key): ?>
                                <p class="dt-a-hint">کلید ذخیره شده است و برای امنیت به‌صورت ناقص نمایش داده می‌شود. برای تغییر، کلید جدید را کامل
                                    وارد کنید. اگر این مقدار را تغییر ندهید، کلید قبلی حفظ می‌شود.</p>
                            <?php endif; ?>
                        </div>

                        <div class="dt-a-field">
							<label for="dt-sms-sender">شماره خط ارسال‌کننده</label>
							<input type="text" id="dt-sms-sender" name="sender" class="dt-a-input dt-a-ltr" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $s['sender'] ); ?>" placeholder="مثال: vip">
							<p class="dt-a-hint">شماره خط یا نام خط را دقیقاً مثل پنل لیمو اس‌ام‌اس وارد کنید (مثلاً <code>vip</code>). حروف انگلیسی، عدد و علامت‌های + _ . - مجاز هستند.</p>
						</div>

						<div class="dt-a-field">
							<label for="dt-sms-numbers">شماره موبایل‌های دریافت‌کننده</label>
							<textarea id="dt-sms-numbers" name="numbers" rows="4" class="dt-a-input dt-a-ltr" placeholder="09123456789"><?php echo esc_textarea( $s['numbers'] ); ?></textarea>
							<p class="dt-a-hint">هر شماره را در یک خط بنویسید (یا با ویرگول جدا کنید). فقط شماره‌های معتبر موبایل ذخیره می‌شوند.</p>
						</div>
					</div>
				</section>

				<div class="dt-a-savebar">
					<span></span>
					<button type="submit" class="dt-a-btn dt-a-btn--primary">ذخیره تنظیمات</button>
				</div>
			</form>

			<?php if ( $ready ) : ?>
				<section class="dt-a-card dt-a-pad" style="margin-top:16px">
					<h2 class="dt-a-h2">ارسال پیامک آزمایشی</h2>
					<p class="dt-a-hint">یک پیامک کوتاه به شماره‌های بالا می‌فرستد. با آن، کلید API و شماره خط را بررسی کنید.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dt_test_sms">
						<?php wp_nonce_field( 'dt_test_sms' ); ?>
						<button type="submit" class="dt-a-btn dt-a-btn--outline">ارسال پیامک آزمایشی</button>
					</form>
				</section>
			<?php endif; ?>

			<?php if ( is_array( $last ) ) : ?>
				<section class="dt-a-card dt-a-pad" style="margin-top:16px">
					<h2 class="dt-a-h2">آخرین ارسال</h2>
					<p class="dt-a-hint">
						<span class="dt-a-badge <?php echo ! empty( $last['ok'] ) ? 'is-ok' : 'is-flag'; ?>"><?php echo ! empty( $last['ok'] ) ? 'موفق' : 'ناموفق'; ?></span>
						<?php echo esc_html( mysql2date( 'Y/m/d H:i', $last['time'] ) ); ?> — <?php echo esc_html( $last['message'] ); ?>
					</p>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function save_sms() {
		self::guard( 'dt_save_sms' );

		$old     = DT_Sms::settings();
		$key     = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : ''; // phpcs:ignore
        // Allowed: letters, digits, plus, underscore, dot, and dash. Example: "vip" or "30001234567890".
        $sender = isset($_POST['sender']) ? preg_replace('/[^A-Za-z0-9+_.-]/', '', dt_to_en_digits(sanitize_text_field(wp_unslash($_POST['sender'])))) : ''; // phpcs:ignore
		$numbers = isset( $_POST['numbers'] ) ? DT_Sms::parse_numbers( sanitize_textarea_field( wp_unslash( $_POST['numbers'] ) ) ) : []; // phpcs:ignore

		$new = [
			'enabled' => ! empty( $_POST['enabled'] ) ? 1 : 0, // phpcs:ignore
            // An empty field, or the masked value, keeps the old key.
            'api_key' => ('' === $key || false !== strpos($key, '•')) ? $old['api_key'] : $key,
			'sender'  => $sender,
			'numbers' => implode( "\n", $numbers ),
		];

		// Do not turn on SMS with missing data.
		$missing = $new['enabled'] && ( '' === $new['api_key'] || '' === $new['sender'] || ! $numbers );
		if ( $missing ) {
			$new['enabled'] = 0;
		}

		update_option( DT_Sms::OPTION, $new, false );

		wp_safe_redirect( add_query_arg( 'dt_msg', $missing ? 'missing' : 'saved', admin_url( 'admin.php?page=dt-sms' ) ) );
		exit;
	}

	public static function test_sms() {
		self::guard( 'dt_test_sms' );

		$ok = DT_Sms::send( 'پیامک آزمایشی افزونه آزمون‌های روانشناسی دکتر دوزنده' );

		wp_safe_redirect( add_query_arg( 'dt_msg', $ok ? 'test_ok' : 'test_fail', admin_url( 'admin.php?page=dt-sms' ) ) );
		exit;
	}

    /* ----------------------------------------------------------- Quizzes page */

    public static function page_quizzes() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'شما دسترسی لازم را ندارید.' );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore
        if ( in_array( $action, [ 'new', 'edit' ], true ) ) {
            self::render_builder();
            return;
        }
        self::render_quiz_list();
    }

    private static function render_quiz_list() {
        $quizzes = DT_Repo::get_all_quizzes();
        $new_url = admin_url( 'admin.php?page=dt-quizzes&action=new' );
        $deleted = isset( $_GET['dt_msg'] ) && 'deleted' === $_GET['dt_msg']; // phpcs:ignore
        ?>
        <div class="dt-admin" dir="rtl">
            <div class="dt-a-head">
                <div>
                    <h1 class="dt-a-title">مدیریت آزمون‌ها</h1>
                    <p class="dt-a-sub">برای نمایش آزمون‌ها، این شورت‌کد را در یک برگه قرار دهید: <code class="dt-a-code">[dozandeh_tests]</code></p>
                </div>
                <a class="dt-a-btn dt-a-btn--primary" href="<?php echo esc_url( $new_url ); ?>">افزودن آزمون</a>
            </div>

            <?php if ( $deleted ) : ?>
                <div class="dt-a-msg is-success">آزمون حذف شد.</div>
            <?php endif; ?>

            <div class="dt-a-card">
                <div class="dt-a-list">
                    <div class="dt-a-row dt-a-row--head">
                        <span class="dt-a-cell dt-a-cell--wide">نام آزمون</span>
                        <span class="dt-a-cell">تعداد سؤال</span>
                        <span class="dt-a-cell">وضعیت</span>
                        <span class="dt-a-cell dt-a-cell--act dt-a-cell--acts"></span>
                    </div>

                    <?php if ( ! $quizzes ) : ?>
                        <div class="dt-a-empty">هنوز آزمونی نساخته‌اید.</div>
                    <?php endif; ?>

                    <?php foreach ( $quizzes as $q ) : ?>
                        <?php
                        $id   = (int) $q['id'];
                        $edit = admin_url( 'admin.php?page=dt-quizzes&action=edit&quiz_id=' . $id );
                        $del  = wp_nonce_url( admin_url( 'admin-post.php?action=dt_delete_quiz&quiz_id=' . $id ), 'dt_delete_quiz_' . $id );
                        ?>
                        <div class="dt-a-row">
                            <div class="dt-a-cell dt-a-cell--wide" data-label="نام آزمون"><strong><?php echo esc_html( $q['title'] ); ?></strong></div>
                            <div class="dt-a-cell" data-label="تعداد سؤال"><?php echo esc_html( dt_to_fa_digits( (int) $q['question_count'] ) ); ?></div>
                            <div class="dt-a-cell" data-label="وضعیت">
                                <label class="dt-a-switch">
                                    <input type="checkbox" role="switch" data-toggle-quiz="<?php echo (int) $id; ?>" <?php checked( 'publish', $q['status'] ); ?>>
                                    <span class="dt-a-switch__track"></span>
                                    <span class="dt-a-switch__text"><?php echo 'publish' === $q['status'] ? 'فعال' : 'غیرفعال'; ?></span>
                                </label>
                            </div>
                            <div class="dt-a-cell dt-a-cell--act dt-a-cell--acts">
                                <a class="dt-a-btn dt-a-btn--outline dt-a-btn--sm" href="<?php echo esc_url( $edit ); ?>">ویرایش</a>
                                <a class="dt-a-btn dt-a-btn--ghost dt-a-btn--sm dt-a-danger" href="<?php echo esc_url( $del ); ?>" data-confirm="آزمون و همه سؤال‌های آن حذف شود؟">حذف</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_builder() {
        $id   = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0; // phpcs:ignore
        $data = $id ? DT_Repo::get_quiz_for_edit( $id ) : null;

        if ( $id && ! $data ) {
            wp_die( 'آزمون پیدا نشد.' );
        }
        if ( ! $data ) {
            $data = [
                'id'           => 0,
                'title'        => '',
                'description'  => '',
                'scoring_mode' => 'score',
                'status'       => 'publish',
                'result_note'  => '',
                'dimensions'   => [],
                'questions'    => [],
            ];
        }
        ?>
        <div class="dt-admin" dir="rtl">
            <div class="dt-a-head">
                <div>
                    <h1 class="dt-a-title"><?php echo $id ? 'ویرایش آزمون' : 'آزمون جدید'; ?></h1>
                    <p class="dt-a-sub">اطلاعات آزمون، مؤلفه‌ها، سؤال‌ها و گزینه‌ها را اینجا مدیریت کنید.</p>
                </div>
                <a class="dt-a-btn dt-a-btn--outline" href="<?php echo esc_url( admin_url( 'admin.php?page=dt-quizzes' ) ); ?>">بازگشت به فهرست</a>
            </div>

            <div id="dt-builder" class="dt-builder" data-quiz="<?php echo esc_attr( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ); ?>">
                <div id="dt-builder-body"></div>
                <div class="dt-a-savebar">
                    <span id="dt-msg" class="dt-a-msg" role="status"></span>
                    <button type="button" class="dt-a-btn dt-a-btn--primary" data-act="save">ذخیره آزمون</button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajax_toggle_quiz() {
        check_ajax_referer( 'dt_save_quiz', '_wpnonce' );
        if ( ! current_user_can( self::CAP ) ) {
            wp_send_json_error( [ 'message' => 'شما دسترسی لازم را ندارید.' ], 403 );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $on = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];

        if ( ! $id || ! DT_Repo::get_quiz( $id ) ) {
            wp_send_json_error( [ 'message' => 'آزمون پیدا نشد.' ], 404 );
        }

        DT_Repo::set_status( $id, $on ? 'publish' : 'draft' );
        wp_send_json_success( [ 'enabled' => $on ] );
    }
    public static function ajax_save_quiz() {
        check_ajax_referer( 'dt_save_quiz', '_wpnonce' );
        if ( ! current_user_can( self::CAP ) ) {
            wp_send_json_error( [ 'message' => 'شما دسترسی لازم را ندارید.' ], 403 );
        }

        $raw   = isset( $_POST['quiz'] ) ? json_decode( wp_unslash( $_POST['quiz'] ), true ) : null; // phpcs:ignore
        $clean = DT_Validator::quiz( $raw );
        if ( is_wp_error( $clean ) ) {
            wp_send_json_error( [ 'message' => $clean->get_error_message() ], 422 );
        }

        $id = DT_Repo::save_quiz( $clean );
        if ( is_wp_error( $id ) ) {
            wp_send_json_error( [ 'message' => $id->get_error_message() ], 500 );
        }

        wp_send_json_success(
            [
                'id'      => $id,
                'message' => 'آزمون ذخیره شد.',
                'url'     => admin_url( 'admin.php?page=dt-quizzes&action=edit&quiz_id=' . $id ),
            ]
        );
    }

    public static function delete_quiz() {
        $id = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0; // phpcs:ignore
        self::guard( 'dt_delete_quiz_' . $id );
        DT_Repo::delete_quiz( $id );
        wp_safe_redirect( add_query_arg( 'dt_msg', 'deleted', admin_url( 'admin.php?page=dt-quizzes' ) ) );
        exit;
    }
}