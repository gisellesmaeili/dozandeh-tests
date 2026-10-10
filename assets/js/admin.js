(() => {
    'use strict';

    // Confirm dialog for delete links.
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) e.preventDefault();
    });

    // On/off switch in the test list.
    document.addEventListener('change', async (e) => {
        const box = e.target.closest('[data-toggle-quiz]');
        if (!box) return;

        const text = box.closest('.dt-a-switch').querySelector('.dt-a-switch__text');
        const form = new URLSearchParams({
            action: 'dt_toggle_quiz',
            _wpnonce: DTAdmin.nonce,
            id: box.dataset.toggleQuiz,
            enabled: box.checked ? '1' : '0',
        });

        box.disabled = true;
        try {
            const res = await fetch(DTAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: form });
            const json = await res.json();
            if (!json.success) throw new Error('failed');
            text.textContent = box.checked ? 'فعال' : 'غیرفعال';
        } catch (err) {
            box.checked = !box.checked;
            window.alert('تغییر وضعیت انجام نشد. دوباره تلاش کنید.');
        }
        box.disabled = false;
    });

    // Show or hide the SMS fields when the toggle changes.
    document.addEventListener('change', (e) => {
        const box = e.target.closest('[data-sms-toggle]');
        if (!box) return;
        const fields = document.getElementById('dt-sms-fields');
        if (fields) fields.hidden = !box.checked;
    });

    const root = document.getElementById('dt-builder');
    if (!root) return;

    const body = root.querySelector('#dt-builder-body');
    const msg = root.querySelector('#dt-msg');
    const saveBtn = root.querySelector('[data-act="save"]');
    const quiz = JSON.parse(root.dataset.quiz);

    const uid = () => 'd' + Math.random().toString(36).slice(2, 8);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const sel = (a, b) => (a === b ? 'selected' : '');

    const blankOption = () => ({ text: '', score: 0, correct: false });
    const blankDimension = () => ({ key: uid(), name: '', levels: [], flagged: false, flag_text: '' });
    const blankLevel = (list) => {
        const last = list[list.length - 1];
        const start = last ? last.max + 1 : 0;
        return { min: start, max: start, label: '', description: '' };
    };
    const blankQuestion = () => ({
        body: '',
        dimension_key: quiz.dimensions[0] ? quiz.dimensions[0].key : '',
        options: [blankOption(), blankOption()],
    });

    // Fill missing fields (old tests do not have them).
    quiz.overall_title = quiz.overall_title || '';
    quiz.overall_levels = quiz.overall_levels || [];
    quiz.dimensions.forEach((d) => {
        d.levels = d.levels || [];
        d.flagged = !!d.flagged;
        d.flag_text = d.flag_text || '';
    });
    if (!quiz.dimensions.length) quiz.dimensions.push(blankDimension());
    if (!quiz.questions.length) quiz.questions.push(blankQuestion());

    const notify = (text, type) => {
        msg.textContent = text;
        msg.className = 'dt-a-msg is-' + type;
    };

    // Return the level list for a scope: "o" is overall, "d" is one component.
    const levelList = (scope, di) => (scope === 'o' ? quiz.overall_levels : quiz.dimensions[di].levels);

    /* ------------------------------------------------------------ Templates */
    const dimOptions = (current) => quiz.dimensions
        .map((d) => `<option value="${esc(d.key)}" ${sel(d.key, current)}>${esc(d.name || 'مؤلفه بدون نام')}</option>`)
        .join('');

    const levelRow = (lv, scope, di, li) => `
		<div class="dt-a-lvl">
			<div class="dt-a-lvl__top">
				<input type="number" min="0" class="dt-a-input dt-a-lvl__n" placeholder="از" value="${lv.min}" data-f="lvl" data-k="min" data-s="${scope}" data-d="${di}" data-l="${li}">
				<span>تا</span>
				<input type="number" min="0" class="dt-a-input dt-a-lvl__n" placeholder="تا" value="${lv.max}" data-f="lvl" data-k="max" data-s="${scope}" data-d="${di}" data-l="${li}">
				<input type="text" class="dt-a-input" placeholder="نام سطح (مثلاً: کم)" value="${esc(lv.label)}" data-f="lvl" data-k="label" data-s="${scope}" data-d="${di}" data-l="${li}">
				<button type="button" class="dt-a-btn dt-a-btn--ghost dt-a-btn--icon dt-a-danger" data-act="del-lvl" data-s="${scope}" data-d="${di}" data-l="${li}" aria-label="حذف سطح">×</button>
			</div>
			<textarea class="dt-a-input" rows="2" placeholder="توضیح نتیجه برای این سطح" data-f="lvl" data-k="description" data-s="${scope}" data-d="${di}" data-l="${li}">${esc(lv.description)}</textarea>
		</div>`;

    const levelsEditor = (list, scope, di) => `
		<div class="dt-a-levels">
			${list.map((lv, li) => levelRow(lv, scope, di, li)).join('')}
			<button type="button" class="dt-a-btn dt-a-btn--outline dt-a-btn--sm" data-act="add-lvl" data-s="${scope}" data-d="${di}">+ افزودن سطح</button>
		</div>`;

    const dimCard = (d, di) => `
		<div class="dt-a-card dt-a-pad dt-a-dimcard">
			<div class="dt-a-dim">
				<input type="text" class="dt-a-input" placeholder="نام مؤلفه" value="${esc(d.name)}" data-f="dim" data-d="${di}">
				<button type="button" class="dt-a-btn dt-a-btn--ghost dt-a-btn--icon dt-a-danger" data-act="del-dim" data-d="${di}" aria-label="حذف مؤلفه">×</button>
			</div>
			<p class="dt-a-hint">سطح‌های این مؤلفه (بر اساس مجموع امتیاز همین مؤلفه):</p>
			${levelsEditor(d.levels, 'd', di)}
			<label class="dt-a-switch dt-a-flagrow">
				<input type="checkbox" role="switch" data-f="dflag" data-d="${di}" ${d.flagged ? 'checked' : ''}>
				<span class="dt-a-switch__track"></span>
				<span class="dt-a-switch__text">علامت‌گذاری مؤلفه (نمایش هشدار برای بالاترین سطح)</span>
			</label>
			<div class="dt-a-field dt-a-flagtext" ${d.flagged ? '' : 'hidden'}>
				<label>متن هشدار</label>
				<textarea class="dt-a-input" rows="3" data-f="dflagtext" data-d="${di}">${esc(d.flag_text)}</textarea>
			</div>
		</div>`;

    const optionRow = (o, qi, oi) => `
		<div class="dt-a-opt">
			<label class="dt-a-correct"><input type="radio" name="correct-${qi}" data-f="ocorrect" data-q="${qi}" data-o="${oi}" ${o.correct ? 'checked' : ''}> صحیح</label>
			<input type="text" class="dt-a-input" placeholder="متن گزینه" value="${esc(o.text)}" data-f="otext" data-q="${qi}" data-o="${oi}">
			<input type="number" min="0" class="dt-a-input dt-a-score" placeholder="امتیاز" title="امتیاز گزینه" value="${o.score}" data-f="oscore" data-q="${qi}" data-o="${oi}">
			<button type="button" class="dt-a-btn dt-a-btn--ghost dt-a-btn--icon dt-a-danger" data-act="del-o" data-q="${qi}" data-o="${oi}" aria-label="حذف گزینه">×</button>
		</div>`;

    const questionCard = (q, qi) => `
		<article class="dt-a-card dt-a-pad">
			<div class="dt-a-q__head">
				<span class="dt-a-num">سؤال ${qi + 1}</span>
				<div class="dt-a-tools">
					<button type="button" class="dt-a-btn dt-a-btn--outline dt-a-btn--sm" data-act="up-q" data-q="${qi}" aria-label="انتقال به بالا">↑</button>
					<button type="button" class="dt-a-btn dt-a-btn--outline dt-a-btn--sm" data-act="down-q" data-q="${qi}" aria-label="انتقال به پایین">↓</button>
					<button type="button" class="dt-a-btn dt-a-btn--ghost dt-a-btn--sm dt-a-danger" data-act="del-q" data-q="${qi}">حذف سؤال</button>
				</div>
			</div>
			<div class="dt-a-field">
				<label>متن سؤال</label>
				<textarea class="dt-a-input" rows="2" data-f="qbody" data-q="${qi}">${esc(q.body)}</textarea>
			</div>
			<div class="dt-a-field">
				<label>مؤلفه‌ای که این سؤال می‌سنجد</label>
				<select class="dt-a-input" data-f="qdim" data-q="${qi}">${dimOptions(q.dimension_key)}</select>
			</div>
			<label class="dt-a-hint" style="display:block;font-weight:600">گزینه‌ها</label>
			${q.options.map((o, oi) => optionRow(o, qi, oi)).join('')}
			<button type="button" class="dt-a-btn dt-a-btn--outline dt-a-btn--sm" data-act="add-o" data-q="${qi}">+ افزودن گزینه</button>
		</article>`;

    const render = () => {
        root.classList.toggle('is-mode-score', quiz.scoring_mode !== 'correct');
        root.classList.toggle('is-mode-correct', quiz.scoring_mode === 'correct');

        body.innerHTML = `
			<section class="dt-a-card dt-a-pad">
				<h2 class="dt-a-h2">اطلاعات آزمون</h2>
				<p class="dt-a-hint">نام و توضیح کوتاه روی کارت آزمون در سایت نمایش داده می‌شود.</p>
				<label class="dt-a-switch dt-a-flagrow">
					<input type="checkbox" role="switch" data-f="status" ${quiz.status === 'publish' ? 'checked' : ''}>
					<span class="dt-a-switch__track"></span>
					<span class="dt-a-switch__text">آزمون فعال است (نمایش در سایت)</span>
				</label>
				<div class="dt-a-field">
					<label>نام آزمون</label>
					<input type="text" class="dt-a-input" data-f="title" value="${esc(quiz.title)}">
				</div>
				<div class="dt-a-field">
					<label>توضیح کوتاه</label>
					<textarea class="dt-a-input" rows="3" data-f="description">${esc(quiz.description)}</textarea>
				</div>
				<div class="dt-a-field">
					<label>روش امتیازدهی</label>
					<select class="dt-a-input" data-f="scoring_mode">
						<option value="score" ${sel('score', quiz.scoring_mode)}>امتیاز برای هر گزینه</option>
						<option value="correct" ${sel('correct', quiz.scoring_mode)}>گزینه صحیح</option>
					</select>
				</div>
				<p class="dt-a-hint dt-a-help--score">برای هر گزینه یک امتیاز بنویسید. امتیاز مؤلفه برابر مجموع امتیاز گزینه‌های انتخاب‌شده است.</p>
				<p class="dt-a-hint dt-a-help--correct">برای هر سؤال یک گزینه صحیح انتخاب کنید. امتیاز مؤلفه برابر تعداد پاسخ‌های صحیح است.</p>
				<div class="dt-a-field">
					<label>توضیح عمومی نتیجه (اختیاری)</label>
					<textarea class="dt-a-input" rows="3" data-f="result_note">${esc(quiz.result_note)}</textarea>
				</div>
			</section>

			<section class="dt-a-card dt-a-pad">
				<h2 class="dt-a-h2">نتیجه کلی</h2>
				<p class="dt-a-hint">مجموع امتیاز همه مؤلفه‌ها با این سطح‌ها سنجیده می‌شود. اعداد را بدون هم‌پوشانی بنویسید. مثال: ۰ تا ۱۳ کم، ۱۴ تا ۲۰ متوسط، ۲۱ تا ۴۰ زیاد.</p>
				<div class="dt-a-field">
					<label>موضوع آزمون (مثلاً: افسردگی). عنوان نتیجه «میزان افسردگی شما» می‌شود.</label>
					<input type="text" class="dt-a-input" data-f="overall_title" value="${esc(quiz.overall_title)}">
				</div>
				${levelsEditor(quiz.overall_levels, 'o', 0)}
			</section>

			<h2 class="dt-a-h2" style="margin:24px 0 4px">مؤلفه‌ها</h2>
			<p class="dt-a-hint">هر مؤلفه در صفحه نتیجه یک نمودار نواری با توضیح دارد.</p>
			${quiz.dimensions.map(dimCard).join('')}
			<p><button type="button" class="dt-a-btn dt-a-btn--outline dt-a-btn--sm" data-act="add-dim">+ افزودن مؤلفه</button></p>

			<h2 class="dt-a-h2" style="margin:24px 0 12px">سؤال‌ها</h2>
			${quiz.questions.map(questionCard).join('')}
			<p><button type="button" class="dt-a-btn dt-a-btn--outline" data-act="add-q">+ افزودن سؤال</button></p>`;
    };

    /* --------------------------------------------------------------- Fields */
    const syncDimOptions = () => {
        body.querySelectorAll('select[data-f="qdim"]').forEach((select) => {
            Array.from(select.options).forEach((opt) => {
                const dim = quiz.dimensions.find((d) => d.key === opt.value);
                if (dim) opt.textContent = dim.name || 'مؤلفه بدون نام';
            });
        });
    };

    const onField = (e) => {
        const t = e.target;
        const f = t.getAttribute('data-f');
        if (!f) return;

        const q = quiz.questions[+t.getAttribute('data-q')];
        const o = q ? q.options[+t.getAttribute('data-o')] : null;
        const d = quiz.dimensions[+t.getAttribute('data-d')];

        switch (f) {
            case 'title': case 'description': case 'result_note': case 'overall_title':
                quiz[f] = t.value; break;
            case 'status':
                quiz.status = t.checked ? 'publish' : 'draft'; break;
            case 'scoring_mode':
                quiz.scoring_mode = t.value;
                root.classList.toggle('is-mode-score', t.value !== 'correct');
                root.classList.toggle('is-mode-correct', t.value === 'correct');
                break;
            case 'dim':
                d.name = t.value;
                syncDimOptions();
                break;
            case 'dflag':
                d.flagged = t.checked;
                t.closest('.dt-a-dimcard').querySelector('.dt-a-flagtext').hidden = !t.checked;
                break;
            case 'dflagtext': d.flag_text = t.value; break;
            case 'lvl': {
                const lv = levelList(t.dataset.s, +t.dataset.d)[+t.dataset.l];
                const k = t.dataset.k;
                lv[k] = (k === 'min' || k === 'max') ? (parseInt(t.value, 10) || 0) : t.value;
                break;
            }
            case 'qbody': q.body = t.value; break;
            case 'qdim': q.dimension_key = t.value; break;
            case 'otext': o.text = t.value; break;
            case 'oscore': o.score = parseInt(t.value, 10) || 0; break;
            case 'ocorrect':
                q.options.forEach((x) => { x.correct = false; });
                o.correct = t.checked;
                break;
        }
    };
    root.addEventListener('input', onField);
    root.addEventListener('change', onField);

    /* -------------------------------------------------------------- Actions */
    const move = (list, from, to) => {
        if (to < 0 || to >= list.length) return;
        list.splice(to, 0, list.splice(from, 1)[0]);
    };

    const save = async () => {
        saveBtn.disabled = true;
        saveBtn.textContent = 'در حال ذخیره…';
        notify('', 'success');

        const form = new URLSearchParams();
        form.append('action', 'dt_save_quiz');
        form.append('_wpnonce', DTAdmin.nonce);
        form.append('quiz', JSON.stringify(quiz));

        try {
            const res = await fetch(DTAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: form });
            const json = await res.json();
            if (json.success) {
                quiz.id = json.data.id;
                notify(json.data.message, 'success');
                window.history.replaceState(null, '', json.data.url);
            } else {
                notify((json.data && json.data.message) || 'خطایی رخ داد.', 'error');
            }
        } catch (err) {
            notify('ارتباط با سرور برقرار نشد.', 'error');
        }

        saveBtn.disabled = false;
        saveBtn.textContent = 'ذخیره آزمون';
    };

    root.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (!b) return;

        const act = b.dataset.act;
        const qi = +b.dataset.q;
        const oi = +b.dataset.o;
        const di = +b.dataset.d;
        const li = +b.dataset.l;
        const scope = b.dataset.s;

        switch (act) {
            case 'save': save(); return;
            case 'add-lvl': {
                const list = levelList(scope, di);
                list.push(blankLevel(list));
                break;
            }
            case 'del-lvl': levelList(scope, di).splice(li, 1); break;
            case 'add-dim': quiz.dimensions.push(blankDimension()); break;
            case 'del-dim': {
                if (quiz.dimensions.length < 2) return notify('حداقل یک مؤلفه لازم است.', 'error');
                const key = quiz.dimensions[di].key;
                quiz.dimensions.splice(di, 1);
                quiz.questions.forEach((q) => { if (q.dimension_key === key) q.dimension_key = quiz.dimensions[0].key; });
                break;
            }
            case 'add-q': quiz.questions.push(blankQuestion()); break;
            case 'del-q':
                if (!window.confirm('این سؤال حذف شود؟')) return;
                quiz.questions.splice(qi, 1); break;
            case 'up-q': move(quiz.questions, qi, qi - 1); break;
            case 'down-q': move(quiz.questions, qi, qi + 1); break;
            case 'add-o': quiz.questions[qi].options.push(blankOption()); break;
            case 'del-o':
                if (quiz.questions[qi].options.length < 3) return notify('هر سؤال حداقل دو گزینه نیاز دارد.', 'error');
                quiz.questions[qi].options.splice(oi, 1); break;
            default: return;
        }
        render();
    });

    render();
})();