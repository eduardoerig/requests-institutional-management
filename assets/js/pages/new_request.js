/**
 * Nova requisição (UI 2.0)
 * - Escolha do setor carrega o formulário (api/get_form.php) e liga o envio via bindFormRequests() (script.js).
 * - Celular: passo a passo só de apresentação (mostra/oculta partes do mesmo <form>).
 * - Computador (≥900px): tudo visível numa página; a navegação de passos fica escondida pelo CSS.
 * Nada aqui muda nomes de campos nem o envio: quem envia é o listener de submit do script.js.
 * Celular: cada passo entra no histórico, então o "voltar" do aparelho volta um passo em vez de sair.
 * Com algo digitado e ainda não enviado, sair da página (F5, fechar, link) pede confirmação.
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
    // Quem tem mais de uma área escolhe a área antes do setor (o campo fica fora do <form> do setor)
    const areaBox = root.querySelector('[data-area-choice]');
    const areaError = root.querySelector('[data-area-error]');

    const titles = [areaBox ? 'Área e setor' : 'Para qual setor?', 'O que você precisa?', 'Urgência e revisão'];
    const kept = ['title', 'details', 'end', 'obs', 'file', 'qtd', 'urgent_reason'];
    const keptRadios = ['obs_choice']; // respostas que valem para qualquer setor
    const cache = {};
    let step = 1;
    let chosen = null;
    let loading = null;
    let sendObserver = null;
    let pushed = 0;        // passos empilhados no histórico (celular)
    let ignorePop = 0;     // voltas no histórico feitas por nós mesmos (botão Voltar)
    let dirty = false;     // a pessoa já escreveu algo
    let submitting = false; // enviando: sair da página não pergunta

    const currentForm = () => holder.querySelector('.form_request');
    const stepPart = (n) => currentForm()?.querySelector(`[data-form-step="${n}"]`);

    stepTitle.setAttribute('tabindex', '-1');

    // ---------------------------------------------------------------
    // Passos (celular)
    // ---------------------------------------------------------------
    function setStep(n, opts = {}) {
        // Celular: avançar empilha o passo; voltar pelo botão desempilha (o "voltar" do aparelho faz o mesmo)
        if (!opts.fromPop && !desk.matches) {
            if (n > step) {
                try { history.pushState({ nrStep: n }, ''); pushed++; } catch (e) { /* sem histórico */ }
            } else if (n < step && pushed > 0) {
                const back = Math.min(pushed, step - n);
                pushed -= back;
                ignorePop++;
                history.go(-back);
            }
        }
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

    // ---------------------------------------------------------------
    // Área do pedido (só para quem tem mais de uma)
    // ---------------------------------------------------------------
    const areaRadio = () => areaBox?.querySelector('input[name="nr_area"]:checked') || null;

    // Leva a área escolhida para o campo escondido do formulário do setor
    function syncArea() {
        const hidden = holder.querySelector('[data-area-hidden]');
        if (hidden) hidden.value = areaRadio()?.value || '';
    }

    // Sem área escolhida: mostra o aviso no passo 1 e não deixa seguir
    function areaOk() {
        if (!areaBox || areaRadio()) return true;
        if (step !== 1) setStep(1, { focus: false });
        areaError.hidden = false;
        areaBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        areaBox.querySelector('input[name="nr_area"]')?.focus({ preventScroll: true });
        return false;
    }

    if (areaBox) {
        areaBox.addEventListener('change', () => {
            areaError.hidden = true;
            dirty = true;
            syncArea();
            if (step === 3) renderReview();
        });
        // Computador: o botão Enviar do formulário não passa pelo passo 1; confere a área antes do envio do script.js
        holder.addEventListener('submit', (e) => {
            if (areaOk()) return;
            e.preventDefault();
            e.stopPropagation();
        }, true);
    }

    async function goNext() {
        if (step === 1) {
            if (!areaOk()) return;
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

    window.addEventListener('popstate', (e) => {
        if (ignorePop) { ignorePop--; return; }
        const target = (e.state && e.state.nrStep) || 1;
        if (target < step) {
            pushed = Math.max(0, pushed - (step - target));
            setStep(target, { fromPop: true });
        } else if (target > step) {
            // "Avançar" do navegador pularia a validação: desfaz
            ignorePop++;
            history.back();
        }
    });

    holder.addEventListener('input', () => { dirty = true; });
    window.addEventListener('beforeunload', (e) => {
        if (!dirty || submitting) return;
        e.preventDefault();
        e.returnValue = ''; // o navegador mostra o próprio aviso de "sair da página?"
    });

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
        const areaName = (areaRadio()?.parentElement?.textContent || '').trim();
        const partnerName = ['partner_area_id', 'partner2_area_id']
            .map(name => form.querySelector(`[name="${name}"]`))
            .filter(select => select?.value && !select.disabled)
            .map(select => select.selectedOptions[0]?.textContent || '')
            .join(' e ');
        box.innerHTML =
            '<span class="nr-review__label">Revisão</span>' +
            `<strong class="nr-review__title">Pedido para ${escapeHtml(chosen.dataset.label || sectorName)}</strong>` +
            (areaName ? `<span class="nr-review__line">Área: ${escapeHtml(areaName)}</span>` : '') +
            (title ? `<span class="nr-review__line">${escapeHtml(title)}</span>` : '') +
            (due ? `<span class="nr-review__line">Para ${escapeHtml(due)}${urgent ? ' · <b>urgente</b>' : ''}</span>` : '') +
            (partnerName ? `<span class="nr-review__line">Sugestão de parceiro: ${escapeHtml(partnerName)}</span>` : '') +
            `<span class="nr-review__text">A coordenação aprova, depois entra na Caixa de ${escapeHtml(sectorName)}. Você recebe um aviso a cada etapa.</span>`;
    }

    // ---------------------------------------------------------------
    // Prazo: só o calendário; embaixo, a data por extenso ("Prazo: quinta-feira, 20/11")
    // ---------------------------------------------------------------
    function enhanceDate(form) {
        const input = form.querySelector('input[name="end"]');
        if (!input) return;
        const chosenText = input.closest('.form-group').querySelector('[data-date-chosen]');
        function sync() {
            if (chosenText) chosenText.textContent = input.value ? `Prazo: ${formatDate(input.value)}` : '';
        }
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
            // Botão desabilitado = enviando (script.js): sair da página não pergunta; se falhar, ele volta
            submitting = inner.disabled;
            btnSend.disabled = inner.disabled;
            btnSend.innerHTML = inner.disabled ? inner.innerHTML : original;
        });
        sendObserver.observe(inner, { attributes: true, attributeFilter: ['disabled'], childList: true, subtree: true });
    }

    // ---------------------------------------------------------------
    // Escolha do setor
    // ---------------------------------------------------------------
    // Uma busca por setor, guardada como promessa (pré-carga e clique reaproveitam a mesma); falhou, tenta de novo no próximo clique
    function fetchForm(id) {
        if (!cache[id]) {
            const formData = new FormData();
            formData.append('action', 'get');
            formData.append('id', id);
            cache[id] = fetchAjax('api/get_form.php', formData, { quiet: true }).then((data) => {
                if (data && data.success) return data.form;
                delete cache[id];
                return null;
            });
        }
        return cache[id];
    }

    // Sem véu na tela: o formulário atual fica (esmaecido se demorar) e só aparece "Abrindo..." se ainda não havia nenhum
    async function loadForm(card) {
        const job = fetchForm(card.dataset.id);
        holder.setAttribute('aria-busy', 'true');
        const slow = setTimeout(() => {
            if (chosen === card && !currentForm()) {
                holder.innerHTML = '<div class="empty-state"><div class="spinner"></div><p>Abrindo formulário...</p></div>';
            }
        }, 250);
        const html = await job;
        clearTimeout(slow);
        if (chosen === card) holder.removeAttribute('aria-busy');
        return html;
    }

    async function choose(card) {
        if (card.getAttribute('aria-pressed') === 'true') return;

        // O que já foi escrito continua lá ao trocar de setor
        const typed = {};
        kept.forEach(name => {
            const field = holder.querySelector(`[name="${name}"]`);
            if (field && field.value.trim() !== '') typed[name] = field.value;
        });
        const keptChoices = {};
        keptRadios.forEach(name => {
            const radio = holder.querySelector(`[name="${name}"]:checked`);
            if (radio) keptChoices[name] = radio.value;
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
        Object.entries(keptChoices).forEach(([name, value]) => {
            const radio = holder.querySelector(`[name="${name}"][value="${CSS.escape(value)}"]`);
            if (radio) radio.checked = true;
        });
        syncArea();

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

    // Pré-carrega os formulários com a página parada: a troca de setor fica instantânea
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 300));
    idle(() => cards.forEach(card => fetchForm(card.dataset.id)));

    // Ao passar para o computador no meio do caminho, nada fica escondido (o CSS mostra tudo)
    setStep(1, { focus: false });
})();
