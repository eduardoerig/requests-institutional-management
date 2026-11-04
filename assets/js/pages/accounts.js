/**
 * Usuários e setores (pages/accounts.php)
 * - Pessoas: busca, filtro por perfil, Ativos/Desativados; edição em folha (celular) ou painel lateral (PC).
 * - Nova pessoa: assistente de 3 passos; a senha temporária vem do servidor e aparece uma única vez.
 * - Setores: adicionar/remover responsável, criar/excluir setor.
 * Usa as funções globais de script.js: showToast, showConfirm, flashToast, attachCsrfToken, readJson, requestErrorMessage.
 */
(function () {
    'use strict';

    const app = document.getElementById('accApp');
    const dataEl = document.getElementById('accData');
    if (!app || !dataEl) return;

    let DATA;
    try {
        DATA = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }

    const people = new Map(DATA.people.map((p) => [p.id, p]));
    const ORG = DATA.orgName || 'SisReq';
    const APP_URL = DATA.appUrl || location.origin + '/';
    const ME = Number(DATA.me) || 0;
    const LOGIN_RE = /^[A-Za-z0-9._-]{2,100}$/;
    const mqDesktop = window.matchMedia('(min-width: 900px)');
    const isDesktop = () => mqDesktop.matches;
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const KIND_ROLE = { req: 'solicitante', coord: 'adm_sub', admin: 'admin' };

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------
    const fold = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const digits = (s) => String(s || '').replace(/\D/g, '');
    // Número com DDD e sem país (10 ou 11 dígitos) ganha o 55 do Brasil
    const waPhone = (s) => {
        const d = digits(s);
        return d.length === 10 || d.length === 11 ? '55' + d : d;
    };
    const sameSet = (a, b) => a.length === b.length && a.every((x) => b.includes(x));

    function suggestLogin(name) {
        const parts = fold(name).replace(/[^a-z0-9\s]/g, ' ').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '';
        return parts.length > 1 ? parts[0] + '.' + parts[parts.length - 1] : parts[0];
    }

    function credentialsText(login, pass) {
        return `Acesso ao ${ORG}\nEndereço: ${APP_URL}\nUsuário: ${login}\nSenha temporária: ${pass}\nNo primeiro acesso você cria a sua própria senha.`;
    }

    function waLink(phone, name, login, pass) {
        const first = String(name || '').trim().split(/\s+/)[0] || '';
        const msg = `Olá${first ? ', ' + first : ''}! Seu acesso ao ${ORG} está pronto.\n\n`
            + `Endereço: ${APP_URL}\nUsuário: ${login}\nSenha temporária: ${pass}\n\n`
            + 'No primeiro acesso o sistema pede para você criar a sua própria senha.';
        return 'https://wa.me/' + waPhone(phone) + '?text=' + encodeURIComponent(msg);
    }

    async function copyText(text) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (e) { /* cai no plano B */ }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        return ok;
    }

    // POST com CSRF; aceita chaves repetidas (ex.: subdivisions[])
    async function send(url, pairs) {
        const body = new URLSearchParams();
        pairs.forEach(([k, v]) => body.append(k, v ?? ''));
        try {
            const data = await postWithSession(url, body);
            if (!data.success && data.redirect) {
                location.href = data.redirect;
                return null;
            }
            return data;
        } catch (err) {
            showToast(requestErrorMessage(err), 'error');
            return null;
        }
    }

    let leaving = false;
    function reloadTo(hash, message) {
        leaving = true;
        if (message && typeof flashToast === 'function') flashToast(message);
        try { history.replaceState(null, '', location.pathname + location.search + '#' + hash); } catch (e) { /* sem histórico */ } // caminho completo: com <base href>, '#x' apontaria para a raiz
        location.reload();
    }

    function setFieldError(input, errEl, message) {
        if (!errEl) return;
        errEl.textContent = message || '';
        errEl.hidden = !message;
        if (input) {
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
            const base = input.dataset.describedby ?? (input.dataset.describedby = input.getAttribute('aria-describedby') || '');
            const ids = [base, message ? errEl.id : ''].filter(Boolean).join(' ');
            if (ids) input.setAttribute('aria-describedby', ids); else input.removeAttribute('aria-describedby');
        }
    }

    function setBusy(btn, busy) {
        if (!btn) return;
        btn.disabled = busy;
        btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function globalModalOpen() {
        const m = document.getElementById('globalModal');
        return !!(m && m.classList.contains('active'));
    }

    // ------------------------------------------------------------------
    // Camadas (folha de edição no celular, assistente): botão "voltar" do aparelho fecha
    // ------------------------------------------------------------------
    let layerPushed = false;
    let ignorePop = false;

    function pushLayer(name) {
        if (layerPushed) return;
        try {
            history.pushState({ accLayer: name }, '');
            layerPushed = true;
        } catch (e) { /* sem histórico */ }
    }

    function openLayerName() {
        if (!wzEls.root.hidden) return 'wizard';
        if (!edEls.root.hidden) return 'editor';
        return null;
    }

    async function requestClose(name) {
        if (!(await canLeave(name))) return false;
        closeLayer(name);
        return true;
    }

    function closeLayer(name) {
        if (name === 'wizard' && wz.done) {
            reloadTo('pessoas', 'Conta criada.');
            return;
        }
        if (name === 'wizard') closeWizard(); else closeEditor();
        if (layerPushed && !openLayerName()) {
            layerPushed = false;
            ignorePop = true;
            history.back();
        }
    }

    window.addEventListener('popstate', async () => {
        if (ignorePop) { ignorePop = false; return; }
        if (!layerPushed) return;
        layerPushed = false;
        const name = openLayerName();
        if (!name) return;
        if (await canLeave(name)) {
            if (name === 'wizard' && wz.done) { reloadTo('pessoas', 'Conta criada.'); return; }
            if (name === 'wizard') closeWizard(); else closeEditor();
        } else {
            pushLayer(name);
        }
    });

    async function canLeave(name) {
        if (name === 'editor') {
            if (ed.secret && !ed.secretCopied) {
                const ok = await showConfirm({ title: 'Já passou a senha temporária?', message: 'Ela não aparece de novo. Se fechar sem anotar, gere outra depois.', type: 'warning', confirmLabel: 'Fechar mesmo assim', cancelLabel: 'Voltar' });
                if (!ok) return false;
            }
            if (changes().length > 0) {
                return !!(await showConfirm({ title: 'Descartar alterações?', message: 'As alterações nesta pessoa ainda não foram salvas.', type: 'warning', confirmLabel: 'Descartar', cancelLabel: 'Continuar editando' }));
            }
            return true;
        }
        if (wz.done) {
            if (wz.copied) return true;
            return !!(await showConfirm({ title: 'Já passou o acesso?', message: 'A senha temporária não aparece de novo. Copie ou envie pelo WhatsApp antes de fechar.', type: 'warning', confirmLabel: 'Fechar mesmo assim', cancelLabel: 'Voltar' }));
        }
        if (wizardHasData()) {
            return !!(await showConfirm({ title: 'Descartar o cadastro?', message: 'O que você preencheu será perdido.', type: 'warning', confirmLabel: 'Descartar', cancelLabel: 'Continuar' }));
        }
        return true;
    }

    // Foco preso dentro do diálogo aberto (assistente sempre; edição só no celular)
    document.addEventListener('keydown', (e) => {
        if (globalModalOpen()) return;
        const name = openLayerName();
        if (!name) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            requestClose(name);
            return;
        }
        if (e.key !== 'Tab') return;
        const box = name === 'wizard' ? wzEls.card : (isDesktop() ? null : edEls.root);
        if (!box) return;
        const items = $$('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])', box)
            .filter((el) => !el.disabled && !el.closest('[hidden]') && el.offsetParent !== null);
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (e.shiftKey && (document.activeElement === first || !box.contains(document.activeElement))) {
            e.preventDefault(); last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault(); first.focus();
        }
    });

    window.addEventListener('beforeunload', (e) => {
        if (leaving) return;
        if ((!edEls.root.hidden && changes().length > 0) || (!wzEls.root.hidden && wizardHasData() && !wz.done)) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // ------------------------------------------------------------------
    // Abas Pessoas / Setores
    // ------------------------------------------------------------------
    const tabs = $$('[data-acc-tab]', app);
    function selectTab(name, focus) {
        tabs.forEach((t) => {
            const on = t.dataset.accTab === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            const panel = document.getElementById(t.getAttribute('aria-controls'));
            if (panel) panel.hidden = !on;
            if (on && focus) t.focus();
        });
        try { history.replaceState(history.state, '', location.pathname + location.search + '#' + name); } catch (e) { /* ignora */ }
    }
    tabs.forEach((t, i) => {
        t.addEventListener('click', async () => {
            if (t.dataset.accTab !== 'pessoas' && !edEls.root.hidden) {
                if (!(await requestClose('editor'))) return;
            }
            selectTab(t.dataset.accTab);
        });
        t.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            const next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            next.click();
            next.focus();
        });
    });
    if (location.hash === '#setores') selectTab('setores');

    // ------------------------------------------------------------------
    // Lista: busca, perfil, situação
    // ------------------------------------------------------------------
    const rows = $$('.acc-row', app);
    const searchInput = $('#accSearch');
    const emptyMsg = $('#accEmpty');
    const filt = { q: '', kind: 'all', state: 'active' };

    function applyFilters() {
        const q = fold(filt.q.trim());
        const counts = { active: 0, inactive: 0 };
        let shown = 0;
        rows.forEach((li) => {
            const p = people.get(Number(li.dataset.id));
            if (!p) return;
            const match = (filt.kind === 'all' || p.kind === filt.kind) && (!q || fold(p.name + ' ' + p.login).includes(q));
            if (match) counts[p.active ? 'active' : 'inactive']++;
            const show = match && p.active === (filt.state === 'active');
            li.hidden = !show;
            if (show) shown++;
        });
        $$('[data-acc-count]', app).forEach((el) => { el.textContent = counts[el.dataset.accCount]; });
        emptyMsg.hidden = shown > 0;
    }

    let searchTimer = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { filt.q = searchInput.value; applyFilters(); }, 120);
    });
    $$('[data-acc-kind]', app).forEach((btn) => btn.addEventListener('click', () => {
        filt.kind = btn.dataset.accKind;
        $$('[data-acc-kind]', app).forEach((b) => {
            const on = b === btn;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        applyFilters();
    }));
    $$('[data-acc-state]', app).forEach((btn) => btn.addEventListener('click', () => {
        filt.state = btn.dataset.accState;
        $$('[data-acc-state]', app).forEach((b) => {
            const on = b === btn;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        applyFilters();
    }));
    applyFilters();

    // ------------------------------------------------------------------
    // Edição de pessoa
    // ------------------------------------------------------------------
    const layout = $('#accPeopleLayout');
    const edEls = {
        root: $('#accEditor'),
        avatar: $('#accEdAvatar'),
        title: $('#accEdName'),
        role: $('#accEdRole'),
        state: $('#accEdState'),
        selfNote: $('#accEdSelfNote'),
        name: $('#accEdNameInput'), nameErr: $('#accEdNameErr'),
        login: $('#accEdLogin'), loginErr: $('#accEdLoginErr'),
        phone: $('#accEdPhone'), phoneErr: $('#accEdPhoneErr'),
        kind: $('#accEdKind'),
        subs: $('#accEdSubs'), subsLabel: $('#accEdSubsLabel'),
        sector: $('#accEdSector'),
        accessErr: $('#accEdAccessErr'),
        secret: $('#accEdSecret'), secretValue: $('#accEdSecretValue'), whats: $('#accEdWhats'),
        resetBtn: $('#accEdResetBtn'),
        active: $('#accEdActive'),
        del: $('#accEdDelete'),
        save: $('#accEdSave'),
    };
    const ed = { id: null, init: null, cur: null, busy: false, secret: null, secretCopied: false, trigger: null };

    function personAccess(p) {
        return {
            kind: p.kind,
            sector: p.kind === 'sector' ? (p.sector || 'ti') : null,
            subs: p.subs.map(Number).sort((a, b) => a - b),
        };
    }

    function changes() {
        if (!ed.init || !ed.cur) return [];
        const i = ed.init;
        const c = ed.cur;
        const list = [];
        if (c.name.trim() !== i.name) list.push('name');
        if (c.login.trim() !== i.login) list.push('login');
        if (digits(c.phone) !== digits(i.phone)) list.push('phone');
        const roleChanged = c.kind !== i.kind || (c.kind === 'sector' && c.sector !== i.sector);
        if (roleChanged) list.push('role');
        if ((c.kind === 'req' || c.kind === 'coord') && !sameSet(c.subs, i.subs)) list.push('subs');
        if (c.active !== i.active) list.push('status');
        return list;
    }

    function renderStateChip(active) {
        edEls.state.className = 'acc-state ' + (active ? 'acc-state--on' : 'acc-state--off');
        edEls.state.innerHTML = '';
        const icon = document.createElement('i');
        icon.className = 'fa-solid ' + (active ? 'fa-user-check' : 'fa-user-slash');
        icon.setAttribute('aria-hidden', 'true');
        edEls.state.append(icon, document.createTextNode(' ' + (active ? 'Ativa' : 'Desativada')));
    }

    function renderAccess() {
        const c = ed.cur;
        const isSelf = ed.id === ME;
        $$('[data-acc-ed-group]', edEls.root).forEach((g) => {
            const grp = g.dataset.accEdGroup;
            g.hidden = !((grp === 'subs' && (c.kind === 'req' || c.kind === 'coord'))
                || (grp === 'sector' && c.kind === 'sector')
                || (grp === 'admin' && c.kind === 'admin'));
        });
        edEls.subsLabel.textContent = c.kind === 'coord' ? 'Aprova os pedidos de (uma ou mais áreas)' : 'Área (os pedidos vão para a coordenação dela)';
        $$('.acc-choice', edEls.subs).forEach((b) => {
            b.setAttribute('aria-pressed', c.subs.includes(Number(b.dataset.value)) ? 'true' : 'false');
            b.disabled = isSelf;
        });
        $$('.acc-choice', edEls.sector).forEach((b) => {
            b.setAttribute('aria-pressed', b.dataset.value === c.sector ? 'true' : 'false');
            b.disabled = isSelf;
        });
    }

    function renderSave() {
        const n = changes().length;
        const label = edEls.save.querySelector('span');
        label.textContent = n ? `Salvar · ${n} ${n === 1 ? 'alteração' : 'alterações'}` : 'Nada para salvar';
        edEls.save.disabled = n === 0 || ed.busy;
    }

    function renderSecret() {
        const p = people.get(ed.id);
        edEls.secret.hidden = !ed.secret;
        edEls.resetBtn.querySelector('span').textContent = ed.secret ? 'Gerar outra' : 'Gerar senha temporária';
        if (ed.secret && p) {
            edEls.secretValue.textContent = ed.secret;
            edEls.whats.href = waLink(p.phone, p.name, p.login, ed.secret);
        }
    }

    function clearEditorErrors() {
        setFieldError(edEls.name, edEls.nameErr, '');
        setFieldError(edEls.login, edEls.loginErr, '');
        setFieldError(edEls.phone, edEls.phoneErr, '');
        setFieldError(null, edEls.accessErr, '');
    }

    function fillEditor(p) {
        const access = personAccess(p);
        ed.id = p.id;
        ed.init = { name: p.name, login: p.login, phone: p.phone || '', active: !!p.active, ...access };
        ed.cur = { ...ed.init, subs: access.subs.slice() };
        ed.secret = null;
        ed.secretCopied = false;
        ed.busy = false;
        const isSelf = p.id === ME;

        edEls.avatar.className = 'acc-avatar acc-avatar--lg acc-avatar--' + p.kind;
        edEls.avatar.textContent = p.initials;
        edEls.title.textContent = p.name;
        edEls.role.className = 'acc-role acc-role--' + p.kind;
        edEls.role.textContent = p.roleLabel;
        renderStateChip(!!p.active);
        edEls.selfNote.hidden = !isSelf;

        edEls.name.value = p.name;
        edEls.login.value = p.login;
        edEls.phone.value = p.phone || '';
        edEls.kind.value = p.kind;
        edEls.kind.disabled = isSelf;
        edEls.active.checked = !!p.active;
        edEls.active.disabled = isSelf;
        edEls.del.disabled = isSelf;
        edEls.resetBtn.disabled = isSelf;
        clearEditorErrors();
        renderAccess();
        renderSecret();
        renderSave();
    }

    async function openEditor(id, trigger) {
        const p = people.get(id);
        if (!p) return;
        if (!edEls.root.hidden) {
            if (ed.id === id) return;
            if (!(await canLeave('editor'))) return;
        }
        fillEditor(p);
        ed.trigger = trigger || null;
        rows.forEach((li) => li.classList.toggle('is-selected', Number(li.dataset.id) === id));
        edEls.root.hidden = false;
        layout.classList.add('has-editor');
        if (isDesktop()) {
            edEls.root.setAttribute('role', 'region');
            edEls.root.removeAttribute('aria-modal');
        } else {
            edEls.root.setAttribute('role', 'dialog');
            edEls.root.setAttribute('aria-modal', 'true');
            document.documentElement.classList.add('acc-lock');
            pushLayer('editor');
        }
        edEls.root.querySelector('.acc-editor__body').scrollTop = 0;
        edEls.title.focus({ preventScroll: isDesktop() });
    }

    function closeEditor() {
        edEls.root.hidden = true;
        layout.classList.remove('has-editor');
        rows.forEach((li) => li.classList.remove('is-selected'));
        document.documentElement.classList.remove('acc-lock');
        ed.id = null;
        ed.init = null;
        ed.cur = null;
        ed.secret = null;
        edEls.secretValue.textContent = '';
        const t = ed.trigger;
        ed.trigger = null;
        if (t && document.contains(t)) t.focus();
    }

    // Campos da edição
    edEls.name.addEventListener('input', () => { ed.cur.name = edEls.name.value; setFieldError(edEls.name, edEls.nameErr, ''); renderSave(); });
    edEls.login.addEventListener('input', () => { ed.cur.login = edEls.login.value; setFieldError(edEls.login, edEls.loginErr, ''); renderSave(); });
    edEls.phone.addEventListener('input', () => { ed.cur.phone = edEls.phone.value; setFieldError(edEls.phone, edEls.phoneErr, ''); renderSave(); });
    edEls.active.addEventListener('change', () => { ed.cur.active = edEls.active.checked; renderStateChip(ed.cur.active); renderSave(); });
    edEls.kind.addEventListener('change', () => {
        const c = ed.cur;
        c.kind = edEls.kind.value;
        if (c.kind === 'req' && c.subs.length > 1) c.subs = c.subs.slice(0, 1);
        if (c.kind === 'sector' && !c.sector) c.sector = ed.init.sector;
        setFieldError(null, edEls.accessErr, '');
        renderAccess();
        renderSave();
    });
    edEls.subs.addEventListener('click', (e) => {
        const b = e.target.closest('.acc-choice');
        if (!b || b.disabled) return;
        const id = Number(b.dataset.value);
        const c = ed.cur;
        if (c.kind === 'coord') {
            c.subs = c.subs.includes(id) ? c.subs.filter((x) => x !== id) : c.subs.concat(id).sort((a, x) => a - x);
        } else {
            c.subs = [id];
        }
        setFieldError(null, edEls.accessErr, '');
        renderAccess();
        renderSave();
    });
    edEls.sector.addEventListener('click', (e) => {
        const b = e.target.closest('.acc-choice');
        if (!b || b.disabled) return;
        ed.cur.sector = b.dataset.value;
        setFieldError(null, edEls.accessErr, '');
        renderAccess();
        renderSave();
    });

    function validateEditor() {
        const c = ed.cur;
        const list = changes();
        let firstBad = null;
        clearEditorErrors();
        const name = c.name.trim();
        if (list.includes('name') && (name.length < 1 || name.length > 150)) {
            setFieldError(edEls.name, edEls.nameErr, 'Informe o nome (até 150 caracteres).');
            firstBad = firstBad || edEls.name;
        }
        if (list.includes('login') && !LOGIN_RE.test(c.login.trim())) {
            setFieldError(edEls.login, edEls.loginErr, 'Use letras, números, ponto, hífen ou sublinhado, sem espaços nem acentos.');
            firstBad = firstBad || edEls.login;
        }
        const ph = digits(c.phone);
        if (list.includes('phone') && ph && (ph.length < 10 || ph.length > 15)) {
            setFieldError(edEls.phone, edEls.phoneErr, 'Informe o número com DDD, só números. Ex.: 11999998888.');
            firstBad = firstBad || edEls.phone;
        }
        if (list.includes('role') || list.includes('subs')) {
            let msg = '';
            if (c.kind === 'req' && c.subs.length !== 1) msg = 'Escolha a área desta pessoa.';
            if (c.kind === 'coord' && c.subs.length < 1) msg = 'Escolha ao menos uma área para a coordenação.';
            if (c.kind === 'sector' && !c.sector) msg = 'Escolha o setor que a pessoa atende.';
            if (msg) {
                setFieldError(null, edEls.accessErr, msg);
                firstBad = firstBad || edEls.kind;
            }
        }
        if (firstBad) firstBad.focus();
        return !firstBad;
    }

    async function saveEditor() {
        const list = changes();
        if (!list.length || ed.busy || !validateEditor()) return;
        const p = people.get(ed.id);
        const c = ed.cur;
        const pairs = [['action', 'update'], ['id', ed.id]];
        if (list.includes('name')) pairs.push(['name', c.name.trim()]);
        if (list.includes('login')) pairs.push(['login', c.login.trim()]);
        if (list.includes('phone')) pairs.push(['phone', waPhone(c.phone)]);
        const roleChanged = list.includes('role');
        if (roleChanged) {
            let role = c.kind === 'sector' ? c.sector : KIND_ROLE[c.kind];
            if (c.kind === 'admin' && ed.init.kind === 'admin') role = p.role; // mantém 'adm'/'coord' legados
            pairs.push(['role', role]);
        }
        if ((c.kind === 'req' || c.kind === 'coord') && (roleChanged || list.includes('subs'))) {
            c.subs.forEach((id) => pairs.push(['subdivisions[]', id]));
        }
        if (list.includes('status')) pairs.push(['status', c.active ? 1 : 0]);

        ed.busy = true;
        renderSave();
        edEls.save.setAttribute('aria-busy', 'true');
        const data = await send('api/update_user_admin.php', pairs);
        ed.busy = false;
        edEls.save.setAttribute('aria-busy', 'false');
        renderSave();
        if (!data) return;
        if (!data.success) {
            const map = { name: [edEls.name, edEls.nameErr], login: [edEls.login, edEls.loginErr], phone: [edEls.phone, edEls.phoneErr] };
            if (data.field && map[data.field]) {
                setFieldError(map[data.field][0], map[data.field][1], data.message);
                map[data.field][0].focus();
            } else if (/telefone/i.test(data.message || '')) {
                setFieldError(edEls.phone, edEls.phoneErr, data.message);
                edEls.phone.focus();
            } else {
                showToast(data.message || 'Não foi possível salvar.', 'error');
            }
            return;
        }
        reloadTo('pessoas', 'Alterações salvas.');
    }

    async function resetPassword() {
        const p = people.get(ed.id);
        if (!p || ed.id === ME) return;
        const ok = await showConfirm({
            title: 'Gerar senha temporária?',
            message: `A senha atual de ${p.name} deixa de funcionar. A pessoa cria uma nova no próximo acesso.`,
            type: 'warning',
            confirmLabel: 'Gerar senha',
        });
        if (!ok) return;
        setBusy(edEls.resetBtn, true);
        const data = await send('api/update_user_admin.php', [['action', 'reset_password'], ['id', p.id]]);
        setBusy(edEls.resetBtn, false);
        if (!data) return;
        if (!data.success || !data.temp_password) {
            showToast(data.message || 'Não foi possível gerar a senha.', 'error');
            return;
        }
        ed.secret = String(data.temp_password);
        ed.secretCopied = false;
        renderSecret();
        showToast('Senha temporária gerada. Passe para a pessoa agora.', 'success');
        edEls.secret.scrollIntoView({ block: 'nearest' });
    }

    async function deleteUser() {
        const p = people.get(ed.id);
        if (!p || ed.id === ME) return;
        const ok = await showConfirm({
            title: `Excluir a conta de ${p.name}?`,
            message: 'A conta some de vez. Se a pessoa já fez pedidos, o sistema não deixa excluir: desative a conta para manter o histórico.',
            type: 'error',
            confirmLabel: 'Excluir conta',
            isDanger: true,
        });
        if (!ok) return;
        setBusy(edEls.del, true);
        const data = await send('api/update_user_admin.php', [['action', 'delete'], ['id', p.id]]);
        setBusy(edEls.del, false);
        if (!data) return;
        if (!data.success) {
            showToast(data.message || 'Não foi possível excluir.', 'error');
            return;
        }
        ed.init = null; // nada mais a perder
        reloadTo('pessoas', 'Conta excluída.');
    }

    // ------------------------------------------------------------------
    // Nova pessoa (assistente)
    // ------------------------------------------------------------------
    const wzEls = {
        root: $('#accWizard'),
        card: $('#accWizard .acc-wizard__card'),
        stepLabel: $('#accWzStep'),
        title: $('#accWzTitle'),
        bars: $$('#accWzBars span'),
        steps: $$('[data-acc-step]'),
        kinds: $$('[data-acc-wz-kind]'),
        kindErr: $('#accWzKindErr'),
        name: $('#accWzName'), nameErr: $('#accWzNameErr'),
        login: $('#accWzLogin'), loginErr: $('#accWzLoginErr'),
        phone: $('#accWzPhone'), phoneErr: $('#accWzPhoneErr'),
        whereHint: $('#accWzWhereHint'),
        subs: $('#accWzSubs'),
        sector: $('#accWzSector'),
        whereErr: $('#accWzWhereErr'),
        doneName: $('#accWzDoneName'), doneLogin: $('#accWzDoneLogin'), donePass: $('#accWzDonePass'),
        whats: $('#accWzWhats'),
        back: $('#accWzBack'), next: $('#accWzNext'), finish: $('#accWzFinish'),
    };
    const wz = { step: 1, kind: null, subs: [], sector: null, loginTouched: false, done: null, copied: false, busy: false, trigger: null };

    function wizardHasData() {
        return !!(wz.kind || wzEls.name.value.trim() || wzEls.login.value.trim() || wzEls.phone.value.trim());
    }

    function renderWizard() {
        const s = wz.step;
        const titles = {
            1: 'O que a pessoa faz?',
            2: 'Quem é',
            3: wz.kind === 'sector' ? 'Qual setor?' : (wz.kind === 'admin' ? 'Pronto para criar' : (wz.kind === 'coord' ? 'Quais áreas?' : 'De qual área?')),
            4: 'Conta criada',
        };
        wzEls.stepLabel.textContent = s <= 3 ? `Passo ${s} de 3` : 'Nova pessoa';
        wzEls.title.textContent = titles[s];
        wzEls.bars.forEach((b, i) => b.classList.toggle('is-on', i < Math.min(s, 3)));
        wzEls.bars[0].parentElement.hidden = s === 4;
        wzEls.steps.forEach((el) => { el.hidden = Number(el.dataset.accStep) !== s; });
        wzEls.back.hidden = !(s === 2 || s === 3);
        wzEls.next.hidden = s === 4;
        wzEls.finish.hidden = s !== 4;
        wzEls.next.innerHTML = '';
        if (s === 3) {
            const i = document.createElement('i');
            i.className = 'fa-solid fa-user-plus';
            i.setAttribute('aria-hidden', 'true');
            wzEls.next.append(i, document.createTextNode(' Criar conta'));
        } else {
            wzEls.next.textContent = 'Continuar';
        }
        setBusy(wzEls.next, wz.busy);

        wzEls.kinds.forEach((b) => b.setAttribute('aria-pressed', b.dataset.accWzKind === wz.kind ? 'true' : 'false'));

        $$('[data-acc-wz-group]', wzEls.root).forEach((g) => {
            const grp = g.dataset.accWzGroup;
            g.hidden = !((grp === 'subs' && (wz.kind === 'req' || wz.kind === 'coord'))
                || (grp === 'sector' && wz.kind === 'sector')
                || (grp === 'admin' && wz.kind === 'admin'));
        });
        const hints = {
            req: 'Os pedidos desta pessoa vão para a coordenação desta área.',
            coord: 'Pode escolher mais de uma: a coordenação aprova os pedidos dessas áreas.',
            sector: 'A pessoa vai receber os pedidos deste setor na Caixa.',
            admin: '',
        };
        wzEls.whereHint.textContent = hints[wz.kind] || '';
        wzEls.whereHint.hidden = !hints[wz.kind];
        $$('.acc-choice', wzEls.subs).forEach((b) => b.setAttribute('aria-pressed', wz.subs.includes(Number(b.dataset.value)) ? 'true' : 'false'));
        $$('.acc-choice', wzEls.sector).forEach((b) => b.setAttribute('aria-pressed', b.dataset.value === wz.sector ? 'true' : 'false'));
    }

    function resetWizard() {
        Object.assign(wz, { step: 1, kind: null, subs: [], sector: null, loginTouched: false, done: null, copied: false, busy: false });
        wzEls.name.value = '';
        wzEls.login.value = '';
        wzEls.phone.value = '';
        wzEls.doneName.textContent = '';
        wzEls.doneLogin.textContent = '';
        wzEls.donePass.textContent = '';
        setFieldError(null, wzEls.kindErr, '');
        setFieldError(wzEls.name, wzEls.nameErr, '');
        setFieldError(wzEls.login, wzEls.loginErr, '');
        setFieldError(wzEls.phone, wzEls.phoneErr, '');
        setFieldError(null, wzEls.whereErr, '');
        const copyLabel = $('[data-acc-action="copy-wizard"] span', wzEls.root);
        if (copyLabel) copyLabel.textContent = 'Copiar usuário e senha';
    }

    async function openWizard(trigger) {
        if (!edEls.root.hidden && !(await requestClose('editor'))) return;
        resetWizard();
        wz.trigger = trigger || null;
        wzEls.root.hidden = false;
        document.documentElement.classList.add('acc-lock', 'acc-lock--wizard');
        pushLayer('wizard');
        renderWizard();
        wzEls.title.focus();
    }

    function closeWizard() {
        wzEls.root.hidden = true;
        document.documentElement.classList.remove('acc-lock', 'acc-lock--wizard');
        wzEls.donePass.textContent = '';
        const t = wz.trigger;
        wz.trigger = null;
        if (t && document.contains(t)) t.focus();
    }

    function goStep(n) {
        wz.step = n;
        renderWizard();
        wzEls.card.querySelector('.acc-wizard__body').scrollTop = 0;
        if (n === 2) wzEls.name.focus(); else wzEls.title.focus();
    }

    function validateStep2() {
        let firstBad = null;
        const name = wzEls.name.value.trim();
        const login = wzEls.login.value.trim();
        const ph = digits(wzEls.phone.value);
        setFieldError(wzEls.name, wzEls.nameErr, '');
        setFieldError(wzEls.login, wzEls.loginErr, '');
        setFieldError(wzEls.phone, wzEls.phoneErr, '');
        if (!name || name.length > 150) {
            setFieldError(wzEls.name, wzEls.nameErr, 'Informe o nome completo.');
            firstBad = firstBad || wzEls.name;
        }
        if (!LOGIN_RE.test(login)) {
            setFieldError(wzEls.login, wzEls.loginErr, login ? 'Use letras, números, ponto, hífen ou sublinhado, sem espaços nem acentos.' : 'Informe o usuário para entrar.');
            firstBad = firstBad || wzEls.login;
        }
        if (ph && (ph.length < 10 || ph.length > 15)) {
            setFieldError(wzEls.phone, wzEls.phoneErr, 'Informe o número com DDD. Ex.: (11) 99999-8888.');
            firstBad = firstBad || wzEls.phone;
        }
        if (firstBad) firstBad.focus();
        return !firstBad;
    }

    function validateStep3() {
        let msg = '';
        if (wz.kind === 'req' && wz.subs.length !== 1) msg = 'Escolha a área desta pessoa.';
        if (wz.kind === 'coord' && wz.subs.length < 1) msg = 'Escolha ao menos uma área.';
        if (wz.kind === 'sector' && !wz.sector) msg = 'Escolha o setor que a pessoa atende.';
        setFieldError(null, wzEls.whereErr, msg);
        return !msg;
    }

    async function createPerson() {
        if (wz.busy) return;
        const name = wzEls.name.value.trim();
        const login = wzEls.login.value.trim();
        const phone = wzEls.phone.value.trim() ? waPhone(wzEls.phone.value) : '';
        const role = wz.kind === 'sector' ? wz.sector : KIND_ROLE[wz.kind];
        const pairs = [['new_name', name], ['new_login', login], ['new_phone', phone], ['new_role', role]];
        if (wz.kind === 'req' || wz.kind === 'coord') wz.subs.forEach((id) => pairs.push(['new_subdivisions[]', id]));
        // Sem new_password: o servidor gera a senha temporária e devolve uma única vez

        wz.busy = true;
        renderWizard();
        const data = await send('api/create_user.php', pairs);
        wz.busy = false;
        renderWizard();
        if (!data) return;
        if (!data.success) {
            const map = { name: [wzEls.name, wzEls.nameErr], login: [wzEls.login, wzEls.loginErr], phone: [wzEls.phone, wzEls.phoneErr] };
            if (data.field && map[data.field]) {
                goStep(2);
                setFieldError(map[data.field][0], map[data.field][1], data.message);
                map[data.field][0].focus();
            } else {
                setFieldError(null, wzEls.whereErr, data.message || 'Não foi possível criar a conta.');
            }
            return;
        }
        const pass = String(data.temp_password || '');
        wz.done = { name, login: data.login || login, pass, phone };
        wzEls.doneName.textContent = name;
        wzEls.doneLogin.textContent = wz.done.login;
        wzEls.donePass.textContent = pass || '—';
        wzEls.whats.href = waLink(phone, name, wz.done.login, pass);
        goStep(4);
    }

    async function wizardNext() {
        if (wz.step === 1) {
            if (!wz.kind) {
                setFieldError(null, wzEls.kindErr, 'Escolha o que a pessoa faz.');
                return;
            }
            goStep(2);
        } else if (wz.step === 2) {
            if (validateStep2()) goStep(3);
        } else if (wz.step === 3) {
            if (validateStep3()) await createPerson();
        }
    }

    wzEls.kinds.forEach((b) => b.addEventListener('click', () => {
        const k = b.dataset.accWzKind;
        if (wz.kind !== k) {
            wz.kind = k;
            wz.subs = [];
            wz.sector = null;
        }
        setFieldError(null, wzEls.kindErr, '');
        setFieldError(null, wzEls.whereErr, '');
        renderWizard();
    }));
    wzEls.name.addEventListener('input', () => {
        setFieldError(wzEls.name, wzEls.nameErr, '');
        if (!wz.loginTouched) {
            wzEls.login.value = suggestLogin(wzEls.name.value);
            setFieldError(wzEls.login, wzEls.loginErr, '');
        }
    });
    wzEls.login.addEventListener('input', () => {
        wz.loginTouched = wzEls.login.value.trim() !== '';
        setFieldError(wzEls.login, wzEls.loginErr, '');
    });
    wzEls.phone.addEventListener('input', () => setFieldError(wzEls.phone, wzEls.phoneErr, ''));
    [wzEls.name, wzEls.login, wzEls.phone].forEach((el) => el.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); wizardNext(); }
    }));
    wzEls.subs.addEventListener('click', (e) => {
        const b = e.target.closest('.acc-choice');
        if (!b) return;
        const id = Number(b.dataset.value);
        if (wz.kind === 'coord') {
            wz.subs = wz.subs.includes(id) ? wz.subs.filter((x) => x !== id) : wz.subs.concat(id);
        } else {
            wz.subs = [id];
        }
        setFieldError(null, wzEls.whereErr, '');
        renderWizard();
    });
    wzEls.sector.addEventListener('click', (e) => {
        const b = e.target.closest('.acc-choice');
        if (!b) return;
        wz.sector = b.dataset.value;
        setFieldError(null, wzEls.whereErr, '');
        renderWizard();
    });
    wzEls.whats.addEventListener('click', () => { wz.copied = true; });
    edEls.whats.addEventListener('click', () => { ed.secretCopied = true; });

    // ------------------------------------------------------------------
    // Setores
    // ------------------------------------------------------------------
    async function addResponsible(form) {
        const card = form.closest('.acc-sector');
        const select = form.querySelector('select[name="user_id"]');
        if (!select || !select.value) {
            showToast('Escolha a pessoa.', 'error');
            if (select) (select.tomselect || select).focus();
            return;
        }
        const btn = form.querySelector('button[type="submit"]');
        setBusy(btn, true);
        const data = await send('api/assign_sector.php', [['action', 'add'], ['user_id', select.value], ['sector_id', card.dataset.areaId]]);
        setBusy(btn, false);
        if (!data) return;
        if (!data.success) { showToast(data.message || 'Não foi possível adicionar.', 'error'); return; }
        reloadTo('setores', 'Responsável adicionado.');
    }

    async function removeResponsible(btn) {
        const card = btn.closest('.acc-sector');
        const ok = await showConfirm({
            title: `Tirar ${btn.dataset.userName} do setor ${card.dataset.areaLabel}?`,
            message: 'A pessoa deixa de receber os pedidos deste setor. A conta continua ativa.',
            type: 'warning',
            confirmLabel: 'Tirar do setor',
        });
        if (!ok) return;
        setBusy(btn, true);
        const data = await send('api/assign_sector.php', [['action', 'remove'], ['user_id', btn.dataset.userId], ['sector_id', card.dataset.areaId]]);
        setBusy(btn, false);
        if (!data) return;
        if (!data.success) { showToast(data.message || 'Não foi possível remover.', 'error'); return; }
        reloadTo('setores', 'Responsável removido.');
    }

    async function deleteSector(btn) {
        const card = btn.closest('.acc-sector');
        const ok = await showConfirm({
            title: `Excluir o setor ${card.dataset.areaLabel}?`,
            message: 'Só é possível excluir um setor sem responsáveis e sem histórico de repasses.',
            type: 'error',
            confirmLabel: 'Excluir setor',
            isDanger: true,
        });
        if (!ok) return;
        setBusy(btn, true);
        const data = await send('api/delete_sector.php', [['id', card.dataset.areaId]]);
        setBusy(btn, false);
        if (!data) return;
        if (!data.success) { showToast(data.message || 'Não foi possível excluir.', 'error'); return; }
        reloadTo('setores', 'Setor excluído.');
    }

    // Descrição do setor: frase curta que aparece no passo "Para qual setor?" do Encaminhar
    function toggleDescForm(btn) {
        const card = btn.closest('.acc-sector');
        const form = card.querySelector('[data-acc-form="sector-desc"]');
        const opener = card.querySelector('.acc-sector__desc [data-acc-action="toggle-desc"]');
        const open = form.hidden;
        form.hidden = !open;
        opener.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) form.querySelector('input').focus();
        else opener.focus();
    }

    async function saveSectorDesc(form) {
        const card = form.closest('.acc-sector');
        const btn = form.querySelector('button[type="submit"]');
        setBusy(btn, true);
        const data = await send('api/update_sector.php', [['id', card.dataset.areaId], ['description', form.description.value.trim()]]);
        setBusy(btn, false);
        if (!data) return;
        if (!data.success) { showToast(data.message || 'Não foi possível salvar.', 'error'); return; }
        reloadTo('setores', 'Descrição salva.');
    }

    function toggleAddForm(btn) {
        const card = btn.closest('.acc-sector');
        const form = card.querySelector('[data-acc-form="add-resp"]');
        const opener = card.querySelector('.acc-add');
        const open = form.hidden;
        form.hidden = !open;
        opener.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            const sel = form.querySelector('select');
            ((sel && sel.tomselect) || sel || form).focus?.();
        } else {
            opener.focus();
        }
    }

    // ------------------------------------------------------------------
    // Ações (delegação)
    // ------------------------------------------------------------------
    document.addEventListener('click', async (e) => {
        const el = e.target.closest('[data-acc-action]');
        if (!el) return;
        const action = el.dataset.accAction;
        switch (action) {
            case 'open-person': {
                const li = el.closest('.acc-row');
                if (li) openEditor(Number(li.dataset.id), el);
                break;
            }
            case 'close-editor': requestClose('editor'); break;
            case 'save-user': saveEditor(); break;
            case 'reset-password': resetPassword(); break;
            case 'delete-user': deleteUser(); break;
            case 'copy-edit': {
                const p = people.get(ed.id);
                if (!p || !ed.secret) break;
                if (await copyText(credentialsText(p.login, ed.secret))) {
                    ed.secretCopied = true;
                    showToast('Usuário e senha copiados.', 'success');
                } else {
                    showToast('Não deu para copiar. Selecione a senha e copie.', 'error');
                }
                break;
            }
            case 'new-person': openWizard(el); break;
            case 'close-wizard': requestClose('wizard'); break;
            case 'wizard-back': if (wz.step > 1 && wz.step < 4) goStep(wz.step - 1); break;
            case 'wizard-next': wizardNext(); break;
            case 'wizard-finish': requestClose('wizard'); break;
            case 'copy-wizard': {
                if (!wz.done) break;
                if (await copyText(credentialsText(wz.done.login, wz.done.pass))) {
                    wz.copied = true;
                    const span = el.querySelector('span');
                    if (span) span.textContent = 'Copiado';
                    showToast('Usuário e senha copiados.', 'success');
                } else {
                    showToast('Não deu para copiar. Selecione a senha e copie.', 'error');
                }
                break;
            }
            case 'toggle-add-resp': toggleAddForm(el); break;
            case 'toggle-desc': toggleDescForm(el); break;
            case 'remove-resp': removeResponsible(el); break;
            case 'delete-sector': deleteSector(el); break;
            default: break;
        }
    });

    document.addEventListener('submit', (e) => {
        const form = e.target.closest('[data-acc-form]');
        if (!form) return;
        e.preventDefault();
        if (form.dataset.accForm === 'add-resp') addResponsible(form);
        if (form.dataset.accForm === 'sector-desc') saveSectorDesc(form);
    });

    // Passou de celular para PC (ou o contrário) com a edição aberta: ajusta o papel do painel
    mqDesktop.addEventListener?.('change', () => {
        if (edEls.root.hidden) return;
        if (isDesktop()) {
            edEls.root.setAttribute('role', 'region');
            edEls.root.removeAttribute('aria-modal');
            if (wzEls.root.hidden) document.documentElement.classList.remove('acc-lock');
        } else {
            edEls.root.setAttribute('role', 'dialog');
            edEls.root.setAttribute('aria-modal', 'true');
            document.documentElement.classList.add('acc-lock');
        }
    });
})();
