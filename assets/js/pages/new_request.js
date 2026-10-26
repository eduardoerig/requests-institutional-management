/**
 * Nova requisição (UI 2.0)
 * - Escolha do setor carrega o formulário (api/get_form.php) e liga o envio via bindFormRequests() (script.js).
 * - Celular: passo a passo só de apresentação (mostra/oculta partes do mesmo <form>).
 * - Computador (≥900px): tudo visível numa página; a navegação de passos fica escondida pelo CSS.
 * Nada aqui muda nomes de campos nem o envio: quem envia é o listener de submit do script.js.
 */
(function () {
    const root = document.querySelector('[data-new-request]');
    if (!root) return;

    const cards = Array.from(root.querySelectorAll('.nr-sector'));
    const panel = document.getElementById('formContainer');
    const holder = document.getElementById('dynamicForm');
    const sectorError = root.querySelector('[data-sector-error]');
    const stepCount = root.querySelector('[data-step-count]');
    const stepTitle = root.querySelector('[data-step-title]');
    const bars = Array.from(root.querySelectorAll('.nr-progress li'));
    const btnBack = root.querySelector('[data-step-back]');
    const btnNext = root.querySelector('[data-step-next]');
    const btnSend = root.querySelector('[data-step-send]');
    const sectorOut = root.querySelector('[data-sector-name-out]');
    const desk = window.matchMedia('(min-width: 900px)');

    const titles = ['Para qual setor?', 'O que você precisa?', 'Urgência e revisão'];
    const kept = ['title', 'details', 'end', 'obs', 'file', 'qtd', 'urgent_reason'];
    const cache = {};
    let step = 1;
    let chosen = null;
    let loading = null;
    let sendObserver = null;

    const currentForm = () => holder.querySelector('.form_request');
    const stepPart = (n) => currentForm()?.querySelector(`[data-form-step="${n}"]`);

    stepTitle.setAttribute('tabindex', '-1');

    // ---------------------------------------------------------------
    // Passos (celular)
    // ---------------------------------------------------------------
    function setStep(n, opts = {}) {
        step = n;
        root.dataset.step = String(n);
        stepCount.textContent = `Passo ${n} de 3`;
        stepTitle.textContent = titles[n - 1];
        bars.forEach((li, i) => li.classList.toggle('is-on', i < n));
        btnBack.hidden = n === 1;
        btnNext.hidden = n === 3;
        btnSend.hidden = n !== 3;
        if (n === 3) renderReview();
        if (!desk.matches && opts.focus !== false) {
            window.scrollTo({ top: 0, behavior: 'auto' });
            stepTitle.focus({ preventScroll: true });
        }
    }

    function validatePart(n) {
        const part = stepPart(n);
        return part ? validateRequestForm(part) : true;
    }

    async function goNext() {
        if (step === 1) {
            if (!chosen) {
                sectorError.hidden = false;
                cards[0]?.focus();
                return;
            }
            if (loading) await loading;
            if (!currentForm()) return; // não abriu; a mensagem de erro já está na tela
            setStep(2);
            return;
        }
        if (step === 2 && validatePart(2)) setStep(3);
    }

    btnNext.addEventListener('click', goNext);
    btnBack.addEventListener('click', () => { if (step > 1) setStep(step - 1); });

    btnSend.addEventListener('click', () => {
        const form = currentForm();
        if (!form) return;
        if (!validatePart(2)) {
            setStep(2, { focus: false });
            validatePart(2); // mostra os erros agora que a parte está visível
            return;
        }
        if (!validatePart(3)) return;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.querySelector('button[type="submit"]')?.click();
    });

    // ---------------------------------------------------------------
    // Revisão (passo 3)
    // ---------------------------------------------------------------
    function formatDate(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!m) return '';
        const d = new Date(+m[1], +m[2] - 1, +m[3]);
        return d.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: '2-digit' });
    }

    function renderReview() {
        const form = currentForm();
        const box = form?.querySelector('[data-review]');
        if (!box || !chosen) return;
        const title = (form.querySelector('[name="title"]')?.value || '').trim();
        const due = formatDate(form.querySelector('[name="end"]')?.value);
        const urgent = !!form.querySelector('[name="urgent"]')?.checked;
        const sectorName = chosen.dataset.sectorName || chosen.dataset.label || '';
        box.innerHTML =
            '<span class="nr-review__label">Revisão</span>' +
            `<strong class="nr-review__title">Pedido para ${escapeHtml(chosen.dataset.label || sectorName)}</strong>` +
            (title ? `<span class="nr-review__line">${escapeHtml(title)}</span>` : '') +
            (due ? `<span class="nr-review__line">Para ${escapeHtml(due)}${urgent ? ' · <b>urgente</b>' : ''}</span>` : '') +
            `<span class="nr-review__text">A coordenação aprova, depois entra na Caixa de ${escapeHtml(sectorName)}. Você recebe um aviso a cada etapa.</span>`;
    }

    // ---------------------------------------------------------------
    // Prazo: atalhos com estado + "Escolher data"
    // ---------------------------------------------------------------
    function enhanceDate(form) {
        const box = form.querySelector('[data-date-quick]');
        const input = form.querySelector('input[name="end"]');
        if (!box || !input) return;
        const group = input.closest('.form-group');
        const chosenText = group.querySelector('[data-date-chosen]');
        const quick = Array.from(box.querySelectorAll('.chip-btn')); // criados pelo bindFormRequests
        const days = [1, 7, 14];

        const pick = document.createElement('button');
        pick.type = 'button';
        pick.className = 'chip-btn';
        pick.textContent = 'Escolher data';
        box.appendChild(pick);
        const all = quick.concat(pick);
        all.forEach(b => b.setAttribute('aria-pressed', 'false'));

        function sync() {
            const value = input.value;
            const idx = days.findIndex(n => isoInDays(n) === value);
            const custom = value !== '' && idx === -1;
            all.forEach((b, i) => b.setAttribute('aria-pressed', String(i === idx || (custom && b === pick))));
            if (custom) group.classList.add('is-picking');
            if (chosenText) chosenText.textContent = value ? `Prazo: ${formatDate(value)}` : '';
        }

        quick.forEach(b => b.addEventListener('click', () => group.classList.remove('is-picking')));
        pick.addEventListener('click', () => {
            group.classList.add('is-picking');
            pick.setAttribute('aria-pressed', 'true');
            quick.forEach(b => b.setAttribute('aria-pressed', 'false'));
            input.focus();
            try { if (typeof input.showPicker === 'function') input.showPicker(); } catch (e) { /* alguns navegadores só abrem com toque direto */ }
        });
        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
        sync();
    }

    // Botão "Enviar" fixo do celular acompanha o botão do formulário (desabilitado / "Enviando...")
    function mirrorSend(form) {
        if (sendObserver) sendObserver.disconnect();
        const inner = form.querySelector('button[type="submit"]');
        if (!inner) return;
        const original = btnSend.innerHTML;
        sendObserver = new MutationObserver(() => {
            btnSend.disabled = inner.disabled;
            btnSend.innerHTML = inner.disabled ? inner.innerHTML : original;
        });
        sendObserver.observe(inner, { attributes: true, attributeFilter: ['disabled'], childList: true, subtree: true });
    }

    // ---------------------------------------------------------------
    // Escolha do setor
    // ---------------------------------------------------------------
    async function loadForm(card) {
        let html = cache[card.dataset.id];
        if (html) return html;
        holder.innerHTML = '<div class="empty-state"><div class="spinner"></div><p>Abrindo formulário...</p></div>';
        const formData = new FormData();
        formData.append('action', 'get');
        formData.append('id', card.dataset.id);
        const data = await fetchAjax('api/get_form.php', formData);
        if (!data || !data.success) return null;
        return (cache[card.dataset.id] = data.form);
    }

    async function choose(card) {
        if (card.getAttribute('aria-pressed') === 'true') return;

        // O que já foi escrito continua lá ao trocar de setor
        const typed = {};
        kept.forEach(name => {
            const field = holder.querySelector(`[name="${name}"]`);
            if (field && field.value.trim() !== '') typed[name] = field.value;
        });
        const wasUrgent = !!holder.querySelector('[name="urgent"]')?.checked;

        chosen = card;
        cards.forEach(c => c.setAttribute('aria-pressed', c === card ? 'true' : 'false'));
        sectorError.hidden = true;
        root.classList.add('has-choice');
        panel.hidden = false;
        panel.dataset.sector = card.dataset.sector;
        if (sectorOut) sectorOut.textContent = card.dataset.sectorName || card.dataset.label;

        const job = loadForm(card);
        loading = job;
        const html = await job;
        if (loading === job) loading = null;
        if (chosen !== card) return; // outro setor foi escolhido enquanto carregava

        if (!html) {
            holder.innerHTML = '<div class="empty-state"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><p>Não foi possível abrir o formulário. Tente de novo.</p></div>';
            card.setAttribute('aria-pressed', 'false');
            chosen = null;
            return;
        }

        holder.innerHTML = html;
        Object.entries(typed).forEach(([name, value]) => {
            const field = holder.querySelector(`[name="${name}"]`);
            if (field) field.value = value;
        });
        if (typed.obs) holder.querySelector('.form-more')?.setAttribute('open', '');

        bindFormRequests(); // script.js: data mínima, atalhos de data, urgência, "Outro", validação e envio

        const form = currentForm();
        if (form) {
            const urgent = form.querySelector('[data-urgent]');
            if (urgent && wasUrgent) {
                urgent.checked = true;
                const reason = form.querySelector('[data-urgent-reason]');
                if (reason) {
                    reason.hidden = false;
                    const input = reason.querySelector('input');
                    if (input) input.required = true;
                }
            }
            enhanceDate(form);
            mirrorSend(form);
            // Celular: Enter num campo avança o passo em vez de enviar sem passar pela revisão
            form.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' || desk.matches || step === 3) return;
                if (e.target.tagName === 'TEXTAREA' || e.target.type === 'submit' || e.target.type === 'button') return;
                e.preventDefault();
                goNext();
            });
            form.addEventListener('input', () => { if (step === 3) renderReview(); });
            form.addEventListener('change', () => { if (step === 3) renderReview(); });
        }

        if (desk.matches) {
            holder.querySelector('#title')?.focus({ preventScroll: true });
        }
    }

    cards.forEach(card => card.addEventListener('click', () => choose(card)));

    // Ao passar para o computador no meio do caminho, nada fica escondido (o CSS mostra tudo)
    setStep(1, { focus: false });
})();
