(() => {
    'use strict';

    const app = document.getElementById('dt-app');
    if (!app || !window.DT) return;

    // Move the floating CTA to <body>. A transformed parent can break "position: fixed".
    const cta = app.querySelector('.dt-cta');
    if (cta) document.body.appendChild(cta);

    const views = {};
    app.querySelectorAll('[data-view]').forEach((el) => { views[el.dataset.view] = el; });

    let state = { quiz: null, index: 0, answers: {}, timer: null };

    const toFa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const show = (name) => {
        Object.keys(views).forEach((key) => { views[key].hidden = key !== name; });
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        app.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    };

    const setError = (name, text) => {
        const box = app.querySelector(`[data-error="${name}"]`);
        if (!box) return;
        box.textContent = text || '';
        box.hidden = !text;
    };

    const api = async (path, options = {}) => {
        try {
            const res = await fetch(DT.rest + path, { headers: { 'Content-Type': 'application/json' }, ...options });
            return { ok: res.ok, data: await res.json() };
        } catch (err) {
            return { ok: false, data: { message: 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.' } };
        }
    };

    /* ---------------------------------------------------------------- Quiz */
    const startQuiz = async (btn) => {
        setError('cards', '');
        const label = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'در حال بارگذاری…';

        const { ok, data } = await api(`quizzes/${btn.dataset.quiz}`);

        btn.disabled = false;
        btn.textContent = label;
        if (!ok) return setError('cards', data.message || 'خطا در بارگذاری آزمون.');

        state = { quiz: data, index: 0, answers: {}, timer: null };
        renderQuestion();
        show('quiz');
    };

    const renderQuestion = () => {
        const { quiz, index, answers } = state;
        const total = quiz.questions.length;
        const q = quiz.questions[index];
        const chosen = answers[q.id];
        const isLast = index === total - 1;

        views.quiz.innerHTML = `
			<div class="dt-panel">
				<div class="dt-quiz-inner">
					<div class="dt-quiz__top">
						<h2 class="dt-title">${esc(quiz.title)}</h2>
						<span class="dt-badge">${toFa(index + 1)} از ${toFa(total)}</span>
					</div>
					<div class="dt-progress" role="progressbar" aria-valuemin="0" aria-valuemax="${total}" aria-valuenow="${index + 1}">
						<span style="width:${((index + 1) / total) * 100}%"></span>
					</div>
					<h3 class="dt-question" tabindex="-1">${esc(q.body)}</h3>
					<div class="dt-options" role="radiogroup">
						${q.options.map((o) => `
							<button type="button" role="radio" aria-checked="${chosen === o.id}" class="dt-option${chosen === o.id ? ' is-selected' : ''}" data-option="${o.id}">${esc(o.text)}</button>
						`).join('')}
					</div>
					<div class="dt-actions">
						<button type="button" class="dt-btn dt-btn--ghost" data-act="prev">${index === 0 ? 'بازگشت به آزمون‌ها' : 'قبلی'}</button>
						<button type="button" class="dt-btn dt-btn--primary" data-act="next" ${chosen ? '' : 'disabled'}>${isLast ? 'ادامه' : 'بعدی'}</button>
					</div>
				</div>
			</div>`;

        views.quiz.querySelector('.dt-question').focus({ preventScroll: true });
    };

    const go = (step) => {
        clearTimeout(state.timer);
        const next = state.index + step;
        if (next < 0) return show('cards');
        if (next >= state.quiz.questions.length) return show('info');
        state.index = next;
        renderQuestion();
    };

    const pick = (btn) => {
        const q = state.quiz.questions[state.index];
        state.answers[q.id] = Number(btn.dataset.option);
        renderQuestion();
        if (state.index < state.quiz.questions.length - 1) {
            clearTimeout(state.timer);
            state.timer = setTimeout(() => go(1), 220);
        }
    };

    /* -------------------------------------------------------------- Result */
    const personIcon = '<svg viewBox="0 0 48 48" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="14" y="5" width="20" height="20" rx="5"/><path d="M6 44v-8a8 8 0 0 1 8-8h20a8 8 0 0 1 8 8v8"/></svg>';

    // Top card: the overall level of the user.
    const overallCard = (res) => {
        const o = res.overall;
        if (!o) return '';
        return `
			<section class="dt-overall">
				<h3 class="dt-overall__title">میزان ${esc(res.overall_title)} شما</h3>
				<div class="dt-levels">
					${o.zones.map((z, i) => `
						<div class="dt-level${i === o.index ? ' is-active' : ''}"${i === o.index ? ' aria-current="true"' : ''}>
							${personIcon}<span>${esc(z.label)}</span>
						</div>`).join('')}
				</div>
				${o.description ? `<p class="dt-overall__desc">${esc(o.description)}</p>` : ''}
			</section>`;
    };

    // One bar for one component.
    const bar = (d) => {
        const total = Math.max(1, d.zones[d.zones.length - 1].to);
        const width = Math.min(100, (d.earned / total) * 100);
        const flex = (z) => `flex:${Math.max(1, z.to - z.from)} 1 0`;
        return `
			<article class="dt-dim">
				<header class="dt-dim__head">
					<h4 class="dt-dim__name">بعد ${esc(d.name)}</h4>
					<span class="dt-dim__pct">${toFa(d.percent)}٪</span>
				</header>
				<div class="dt-meter" role="img" aria-label="${esc(d.name)}: ${toFa(d.percent)} درصد">
					<div class="dt-meter__track">
						<span class="dt-meter__fill" data-width="${width}"></span>
						${d.zones.map((z) => `<span class="dt-meter__zone" style="${flex(z)}"></span>`).join('')}
					</div>
					<div class="dt-meter__labels">
						${d.zones.map((z) => `<span style="${flex(z)}">${esc(z.label)}</span>`).join('')}
					</div>
				</div>
				${d.label ? `<p class="dt-dim__level">سطح شما: <b>${esc(d.label)}</b></p>` : ''}
				${d.description ? `<p class="dt-dim__desc">${esc(d.description)}</p>` : ''}
			</article>`;
    };

    // Warning box for flagged components.
    const flagsBox = (flags) => (flags.length
        ? `<div class="dt-alert" role="alert">${flags.map((f) => `<p>${esc(f.text)}</p>`).join('')}</div>`
        : '');

    const renderResult = (res, name) => {
        views.result.innerHTML = `
			<div class="dt-panel">
				<header class="dt-result__head">
					<span class="dt-badge">نتیجه آزمون</span>
					<h2 class="dt-title dt-title--sm" tabindex="-1">${esc(res.title)}</h2>
					<p class="dt-lead">${esc(name)} عزیز، نتیجه شما در ادامه آمده است.</p>
				</header>

				${overallCard(res)}
				${flagsBox(res.flags)}

				<h3 class="dt-section-title">نمودار ابعاد اصلی ${esc(res.overall_title)}</h3>
				<div class="dt-dims">${res.dimensions.map(bar).join('')}</div>

				${res.note ? `<div class="dt-note"><h3>توضیح نتیجه</h3><p>${esc(res.note)}</p></div>` : ''}
				<p class="dt-disclaimer">این آزمون ابزار خودآگاهی است و جایگزین تشخیص بالینی نیست. برای تفسیر دقیق نتیجه با دکتر دوزنده مشورت کنید.</p>
				<div class="dt-actions">
					<a class="dt-btn dt-btn--primary" href="${esc(DT.tel)}">تماس برای مشاوره</a>
					<button type="button" class="dt-btn dt-btn--ghost" data-act="restart">انجام آزمون دیگر</button>
				</div>
			</div>`;

        requestAnimationFrame(() => requestAnimationFrame(() => {
            views.result.querySelectorAll('.dt-meter__fill').forEach((fill) => {
                fill.style.width = fill.dataset.width + '%';
            });
        }));
    };

    /* -------------------------------------------------------------- Events */
    app.addEventListener('click', (e) => {
        const start = e.target.closest('[data-quiz]');
        if (start) return startQuiz(start);

        const option = e.target.closest('[data-option]');
        if (option) return pick(option);

        const act = e.target.closest('[data-act]');
        if (!act) return;
        if (act.dataset.act === 'prev') go(-1);
        if (act.dataset.act === 'next') go(1);
        if (act.dataset.act === 'restart') show('cards');
    });

    const form = views.info.querySelector('form');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        setError('info', '');

        const fd = new FormData(form);
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'در حال محاسبه…';

        const { ok, data } = await api(`quizzes/${state.quiz.id}/submit`, {
            method: 'POST',
            body: JSON.stringify({
                name: fd.get('name'),
                phone: fd.get('phone'),
                age: fd.get('age'),
                website: fd.get('website'),
                consent: form.elements.consent.checked,
                answers: state.answers,
            }),
        });

        btn.disabled = false;
        btn.textContent = 'مشاهده نتیجه';
        if (!ok) return setError('info', data.message || 'خطایی رخ داد. دوباره تلاش کنید.');

        show('result');
        renderResult(data.result, fd.get('name'));
    });
})();