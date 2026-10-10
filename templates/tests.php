<?php
defined( 'ABSPATH' ) || exit;

$phone     = dt_phone();
$tel       = 'tel:' . preg_replace( '/\D/', '', $phone );
$phone_fmt = dt_to_fa_digits( substr( $phone, 0, 4 ) . ' ' . substr( $phone, 4, 3 ) . ' ' . substr( $phone, 7 ) );
?>
    <div class="dt-app" id="dt-app" dir="rtl" lang="fa">

        <section class="dt-view" data-view="cards" aria-labelledby="dt-title">
            <header class="dt-head">
                <p class="dt-lead">یکی از آزمون‌ها را انتخاب کنید و به سؤال‌ها صادقانه پاسخ دهید. نتیجه، میزان هر مؤلفه را با نمودار نشان می‌دهد.</p>
            </header>

            <p class="dt-error" data-error="cards" role="alert" hidden></p>

            <?php if ( $quizzes ) : ?>
                <div class="dt-grid">
                    <?php foreach ( $quizzes as $quiz ) : ?>
                        <article class="dt-card">
                            <span class="dt-badge"><?php echo esc_html( dt_to_fa_digits( (int) $quiz['question_count'] ) ); ?> سؤال</span>
                            <h3 class="dt-card__title"><?php echo esc_html( $quiz['title'] ); ?></h3>
                            <p class="dt-card__desc"><?php echo esc_html( $quiz['description'] ); ?></p>
                            <button type="button" class="dt-btn dt-btn--primary" data-quiz="<?php echo (int) $quiz['id']; ?>">شروع آزمون</button>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="dt-lead">هنوز آزمونی منتشر نشده است.</p>
            <?php endif; ?>

            <noscript><p class="dt-error">برای انجام آزمون، جاوااسکریپت مرورگر را فعال کنید.</p></noscript>
        </section>

        <section class="dt-view" data-view="quiz" hidden></section>

        <section class="dt-view" data-view="info" hidden>
            <div class="dt-panel dt-panel--narrow">
                <h2 class="dt-title dt-title--sm">یک قدم تا مشاهده نتیجه</h2>
                <p class="dt-lead">برای نمایش نتیجه، اطلاعات تماس خود را وارد کنید.</p>

                <form class="dt-form" novalidate>
                    <div class="dt-field">
                        <label for="dt-name">نام و نام خانوادگی</label>
                        <input id="dt-name" name="name" type="text" autocomplete="name" maxlength="100" required>
                    </div>
                    <div class="dt-field">
                        <label for="dt-phone">شماره موبایل</label>
                        <input id="dt-phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" dir="ltr" maxlength="11" placeholder="09123456789" required>
                    </div>
                    <div class="dt-field">
                        <label for="dt-age">سن (اختیاری)</label>
                        <input id="dt-age" name="age" type="text" inputmode="numeric" maxlength="3" dir="ltr">
                    </div>
                    <div class="dt-hp" aria-hidden="true">
                        <input type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <label class="dt-check">
                        <input type="checkbox" name="consent" required>
                        <span>با ذخیره اطلاعات تماس خود برای ارتباط مشاور موافقم.</span>
                    </label>
                    <p class="dt-error" data-error="info" role="alert" hidden></p>
                    <button type="submit" class="dt-btn dt-btn--primary">مشاهده نتیجه</button>
                </form>
            </div>
        </section>

        <section class="dt-view" data-view="result" hidden></section>

        <a class="dt-cta" href="<?php echo esc_url( $tel ); ?>" aria-label="تماس با دکتر راجیه دوزنده">
		<span class="dt-cta__icon" aria-hidden="true">
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
		</span>
            <span class="dt-cta__text">
			<span class="dt-cta__label">مشاوره با دکتر دوزنده</span>
			<span class="dt-cta__num" dir="ltr"><?php echo esc_html( $phone_fmt ); ?></span>
		</span>
        </a>
    </div>

<?php if ( $quizzes ) : ?>
    <?php
    $items = [];
    foreach ( $quizzes as $i => $quiz ) {
        $items[] = [
            '@type'       => 'ListItem',
            'position'    => $i + 1,
            'name'        => $quiz['title'],
            'description' => $quiz['description'],
        ];
    }
    ?>
    <script type="application/ld+json"><?php
        echo wp_json_encode(
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'آزمون‌های روانشناسی دکتر راجیه دوزنده',
                'itemListElement' => $items,
            ],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
        ?></script>
<?php endif; ?>